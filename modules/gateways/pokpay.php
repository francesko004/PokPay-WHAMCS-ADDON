<?php
/**
 * POK (PokPay) payment gateway for WHMCS.
 *
 * Flow (PokPay "hosted payment page"):
 *   1. Client clicks "Pay with POK" on the invoice  -> modules/gateways/pokpay/pay.php
 *   2. pay.php creates a PokPay SDK order and redirects the client to POK's payment page
 *   3. POK redirects back to modules/gateways/callback/pokpay.php and also POSTs a webhook there
 *   4. The callback re-fetches the order from the PokPay API, verifies it and applies the payment
 *
 * @see https://docs.pokpay.io/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/pokpay/lib/Gateway.php';

use PokPayWhmcs\Gateway;

function pokpay_MetaData()
{
    return [
        'DisplayName' => 'POK (PokPay) - Card Payments',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function pokpay_config()
{
    $callbackUrl = '';
    try {
        $callbackUrl = Gateway::systemUrl([]) . 'modules/gateways/callback/pokpay.php';
    } catch (\Exception $e) {
        // ignore
    }

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'POK - Pay with card',
        ],
        'merchantId' => [
            'FriendlyName' => 'Merchant ID',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Your PokPay merchant ID (POK Business dashboard).',
        ],
        'keyId' => [
            'FriendlyName' => 'Key ID',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'API key ID (POK Business dashboard &rarr; E-payments &rarr; API Keys).',
        ],
        'keySecret' => [
            'FriendlyName' => 'Key Secret',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'API key secret.',
        ],
        'sandbox' => [
            'FriendlyName' => 'Sandbox / Staging',
            'Type' => 'yesno',
            'Description' => 'Tick to use the staging environment (api-staging.pokpay.io). Staging keys only work in staging, production keys only in production.',
        ],
        'expiresAfterMinutes' => [
            'FriendlyName' => 'Payment link lifetime (minutes)',
            'Type' => 'text',
            'Size' => '6',
            'Default' => '60',
            'Description' => 'How long a POK checkout stays valid. A new one is created automatically after it expires.',
        ],
        'orderDescription' => [
            'FriendlyName' => 'Order description',
            'Type' => 'text',
            'Size' => '60',
            'Default' => '{company} - Invoice #{invoicenum}',
            'Description' => 'Shown on the POK payment page. Placeholders: {invoiceid}, {invoicenum}, {company}.',
        ],
        'buttonText' => [
            'FriendlyName' => 'Pay button text',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'Paguaj me kartë (POK)',
        ],
        'apiBaseUrl' => [
            'FriendlyName' => 'API base URL override',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Leave empty. Only for testing against a custom endpoint.'
                . ($callbackUrl !== '' ? '<br>Callback / webhook URL used: <code>' . htmlspecialchars($callbackUrl) . '</code>' : ''),
        ],
    ];
}

/**
 * Payment button shown on the invoice.
 */
function pokpay_link($params)
{
    $currency = strtoupper(isset($params['currency']) ? $params['currency'] : '');
    if (!in_array($currency, Gateway::SUPPORTED_CURRENCIES, true)) {
        return '<div class="alert alert-warning">POK accepts payments in ALL or EUR only. '
            . 'Administrator: set "Convert To For Processing" to ALL or EUR in the POK gateway settings.</div>';
    }

    if (empty($params['merchantId']) || empty($params['keyId']) || empty($params['keySecret'])) {
        return '<div class="alert alert-warning">POK payments are not configured yet.</div>';
    }

    $invoiceId = (int) $params['invoiceid'];
    $action = Gateway::systemUrl($params) . 'modules/gateways/pokpay/pay.php';
    $label = !empty($params['buttonText']) ? $params['buttonText'] : (isset($params['langpaynow']) ? $params['langpaynow'] : 'Pay Now');

    $html = '<form method="post" action="' . htmlspecialchars($action) . '">';
    $html .= '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">';
    $html .= '<input type="hidden" name="token" value="' . htmlspecialchars(Gateway::invoiceToken($params, $invoiceId)) . '">';
    $html .= '<button type="submit" class="btn btn-success">' . htmlspecialchars($label) . '</button>';
    $html .= '</form>';

    return $html;
}

/**
 * Refund (full or partial) from the WHMCS admin area.
 */
function pokpay_refund($params)
{
    $orderId = isset($params['transid']) ? (string) $params['transid'] : '';
    $refundAmount = round((float) $params['amount'], 2);

    try {
        $row = Gateway::findOrder($orderId);
        $client = Gateway::client($params);

        $full = true;
        $apiAmount = null;
        if ($row) {
            $invoiceAmount = (float) $row['invoice_amount'];
            $full = abs($refundAmount - $invoiceAmount) < 0.01;
            if (!$full) {
                // PokPay takes refundAmount in the merchant settlement currency (ALL).
                // Only send a partial amount when we know the ALL value for certain.
                if (strtoupper($row['currency']) !== 'ALL' || $invoiceAmount <= 0) {
                    return [
                        'status' => 'error',
                        'rawdata' => 'Partial refunds are only supported for payments charged in ALL. '
                            . 'Refund the full amount here, or issue the partial refund from the POK Business dashboard.',
                    ];
                }
                $apiAmount = round((float) $row['amount'] * $refundAmount / $invoiceAmount, 2);
            }
        } elseif (strtoupper(isset($params['currency']) ? $params['currency'] : '') === 'ALL') {
            // Order not created by this module version: refund the requested ALL amount.
            $full = false;
            $apiAmount = $refundAmount;
        } else {
            return [
                'status' => 'error',
                'rawdata' => 'PokPay order ' . $orderId . ' is unknown to this module; refund it from the POK Business dashboard.',
            ];
        }

        $reason = 'Refund for invoice #' . (isset($params['invoiceid']) ? $params['invoiceid'] : '');
        $result = $client->refundOrder($orderId, $full ? null : $apiAmount, $reason);

        if ($row && $full) {
            Gateway::setStatus($orderId, 'refunded');
        }

        return [
            'status' => 'success',
            'rawdata' => $result,
            'transid' => $orderId . '-R' . time(),
            'fees' => 0,
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'rawdata' => $e->getMessage(),
        ];
    }
}
