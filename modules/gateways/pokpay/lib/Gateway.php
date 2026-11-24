<?php
/**
 * Shared logic for the PokPay WHMCS gateway: order storage, verification and
 * payment recording. Used by the gateway module, pay.php and the callback.
 */

namespace PokPayWhmcs;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/PokPayClient.php';

if (!defined('POKPAY_WHMCS_VERSION')) {
    define('POKPAY_WHMCS_VERSION', '1.0.3');
}

class Gateway
{
    const MODULE = 'pokpay';
    const TABLE = 'mod_pokpay_orders';
    const SUPPORTED_CURRENCIES = ['ALL', 'EUR'];

    /**
     * Build an API client from WHMCS gateway params.
     */
    public static function client(array $params)
    {
        $sandbox = !empty($params['sandbox']) && $params['sandbox'] !== 'off';
        $logger = function ($action, $request, $response, array $secrets) use ($params) {
            if (function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, $action, $request, $response, '', $secrets);
            }
        };

        return new PokPayClient(
            isset($params['keyId']) ? $params['keyId'] : '',
            isset($params['keySecret']) ? $params['keySecret'] : '',
            isset($params['merchantId']) ? $params['merchantId'] : '',
            $sandbox,
            $logger,
            isset($params['apiBaseUrl']) ? $params['apiBaseUrl'] : ''
        );
    }

    /**
     * HMAC used to authorise "start payment" requests for an invoice, so that
     * pay.php can't be used to spawn orders for arbitrary invoice ids.
     */
    public static function invoiceToken(array $params, $invoiceId)
    {
        $secret = (isset($params['keySecret']) ? $params['keySecret'] : '') . '|' . (isset($params['merchantId']) ? $params['merchantId'] : '');
        return hash_hmac('sha256', 'pokpay-invoice|' . (int) $invoiceId, $secret);
    }

    public static function systemUrl(array $params)
    {
        $url = '';
        if (!empty($params['systemurl'])) {
            $url = $params['systemurl'];
        } elseif (class_exists('\WHMCS\Config\Setting')) {
            $url = \WHMCS\Config\Setting::getValue('SystemURL');
        }
        return rtrim((string) $url, '/') . '/';
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    public static function ensureTable()
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->integer('invoice_id')->index();
            $table->string('order_id', 64)->unique();
            $table->decimal('amount', 16, 2);              // amount charged at PokPay
            $table->string('currency', 3);                 // currency charged at PokPay
            $table->decimal('invoice_amount', 16, 2);      // same amount in the invoice currency
            $table->text('confirm_url');
            $table->string('status', 20)->default('pending'); // pending|paid|failed|refunded
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public static function storeOrder($invoiceId, array $order, $amount, $currency, $invoiceAmount, $confirmUrl, $expiresAt)
    {
        self::ensureTable();
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::TABLE)->insert([
            'invoice_id' => (int) $invoiceId,
            'order_id' => (string) $order['id'],
            'amount' => round((float) $amount, 2),
            'currency' => strtoupper($currency),
            'invoice_amount' => round((float) $invoiceAmount, 2),
            'confirm_url' => (string) $confirmUrl,
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function findOrder($orderId)
    {
        self::ensureTable();
        $row = Capsule::table(self::TABLE)->where('order_id', (string) $orderId)->first();
        return $row ? (array) $row : null;
    }

    /**
     * Orders created for an invoice, newest first.
     */
    public static function ordersForInvoice($invoiceId, $status = null)
    {
        self::ensureTable();
        $query = Capsule::table(self::TABLE)->where('invoice_id', (int) $invoiceId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return array_map(function ($row) {
            return (array) $row;
        }, $query->orderBy('id', 'desc')->get()->all());
    }

    /**
     * A still-valid pending order for the same amount that we can send the client back to.
     */
    public static function reusableOrder($invoiceId, $amount, $currency)
    {
        foreach (self::ordersForInvoice($invoiceId, 'pending') as $row) {
            $notExpired = empty($row['expires_at']) || strtotime($row['expires_at']) > time() + 300;
            if ($notExpired
                && strtoupper($row['currency']) === strtoupper($currency)
                && abs((float) $row['amount'] - (float) $amount) < 0.005
                && $row['confirm_url'] !== '') {
                return $row;
            }
        }
        return null;
    }

    public static function setStatus($orderId, $status)
    {
        self::ensureTable();
        Capsule::table(self::TABLE)->where('order_id', (string) $orderId)->update([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ---------------------------------------------------------------------
    // Verification
    // ---------------------------------------------------------------------

    /**
     * Decide whether a PokPay order (as returned by the API) is a valid, completed
     * payment for the stored order row. Returns [bool $paid, string $reason].
     */
    public static function evaluate(array $order, array $row)
    {
        if (empty($order['id']) || (string) $order['id'] !== (string) $row['order_id']) {
            return [false, 'Order id mismatch'];
        }
        if (!empty($order['isCanceled'])) {
            return [false, 'Order was cancelled'];
        }
        if (!empty($order['isRefunded'])) {
            return [false, 'Order was refunded'];
        }

        $status = isset($order['status']) ? strtoupper((string) $order['status']) : '';
        $completed = (isset($order['isCompleted']) && $order['isCompleted'] === true)
            || in_array($status, ['COMPLETED', 'CAPTURED', 'PAID'], true);
        if (!$completed) {
            return [false, 'Order not completed' . ($status !== '' ? ' (status ' . $status . ')' : '')];
        }

        if (!empty($order['currencyCode']) && strtoupper((string) $order['currencyCode']) !== strtoupper($row['currency'])) {
            return [false, 'Currency mismatch: expected ' . $row['currency'] . ', got ' . $order['currencyCode']];
        }

        if (isset($order['amount']) && abs((float) $order['amount'] - (float) $row['amount']) >= 0.01) {
            return [false, 'Amount mismatch: expected ' . $row['amount'] . ', got ' . $order['amount']];
        }

        if (isset($order['merchantCustomReference']) && $order['merchantCustomReference'] !== ''
            && (string) $order['merchantCustomReference'] !== (string) $row['invoice_id']) {
            return [false, 'Reference mismatch: expected invoice ' . $row['invoice_id']];
        }

        return [true, 'Completed'];
    }

    /**
     * Check every pending order of an invoice against PokPay and record the payment
     * for the first completed one. Returns 'paid', 'already_paid', 'pending' or 'failed'.
     */
    public static function reconcileInvoice(array $params, $invoiceId, $preferredOrderId = '')
    {
        $rows = self::ordersForInvoice($invoiceId);
        if (!$rows) {
            return 'failed';
        }

        foreach ($rows as $row) {
            if ($row['status'] === 'paid') {
                return 'already_paid';
            }
        }

        // Check the order mentioned by the webhook (if it's ours) first.
        usort($rows, function ($a, $b) use ($preferredOrderId) {
            return ((string) $b['order_id'] === (string) $preferredOrderId) - ((string) $a['order_id'] === (string) $preferredOrderId);
        });

        $client = self::client($params);
        $result = 'pending';
        foreach ($rows as $row) {
            if ($row['status'] !== 'pending') {
                continue;
            }
            try {
                $order = $client->getOrder($row['order_id']);
            } catch (\Exception $e) {
                self::logTransaction($params, ['invoice_id' => $invoiceId, 'order_id' => $row['order_id'], 'error' => $e->getMessage()], 'Error');
                continue;
            }

            list($paid, $reason) = self::evaluate($order, $row);
            if ($paid) {
                return self::recordPayment($params, $row, $order) ? 'paid' : 'already_paid';
            }

            if (!empty($order['isCanceled'])) {
                self::setStatus($row['order_id'], 'failed');
            } elseif (stripos($reason, 'mismatch') !== false) {
                // Completed but doesn't match what we asked for: never auto-apply.
                self::setStatus($row['order_id'], 'failed');
                self::logTransaction($params, ['invoice_id' => $invoiceId, 'order' => $order, 'reason' => $reason], 'Verification Failed');
                $result = 'failed';
            }
        }

        return $result;
    }

    /**
     * Apply a verified payment to the invoice. Returns false if it was already recorded.
     */
    public static function recordPayment(array $params, array $row, array $order)
    {
        $transId = (string) $row['order_id'];
        $gatewayName = isset($params['name']) ? $params['name'] : self::MODULE;

        $exists = Capsule::table('tblaccounts')->where('gateway', self::MODULE)->where('transid', $transId)->exists();
        if ($exists) {
            self::setStatus($transId, 'paid');
            return false;
        }

        // Atomically claim the order so a simultaneous webhook + customer return
        // can't both add the payment.
        $claimed = Capsule::table(self::TABLE)
            ->where('order_id', $transId)
            ->where('status', 'pending')
            ->update(['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')]);
        if (!$claimed) {
            return false;
        }

        $invoiceId = checkCbInvoiceID((int) $row['invoice_id'], $gatewayName);

        self::logTransaction($params, [
            'invoice_id' => $invoiceId,
            'order_id' => $transId,
            'charged' => $row['amount'] . ' ' . $row['currency'],
            'order' => $order,
        ], 'Success');

        addInvoicePayment($invoiceId, $transId, (float) $row['invoice_amount'], 0, self::MODULE);

        return true;
    }

    public static function logTransaction(array $params, $data, $status)
    {
        if (function_exists('logTransaction')) {
            logTransaction(isset($params['name']) ? $params['name'] : self::MODULE, $data, $status);
        }
    }
}
