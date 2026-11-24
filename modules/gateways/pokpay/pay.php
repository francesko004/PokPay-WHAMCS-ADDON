<?php
/**
 * Creates (or reuses) a PokPay order for an invoice and redirects the client
 * to POK's hosted payment page.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/Gateway.php';

use PokPayWhmcs\Gateway;
use PokPayWhmcs\PokPayClient;
use WHMCS\Database\Capsule;

/**
 * Append the customer's details so POK's payment page is prefilled.
 */
function pokpay_prefill_url($url, $client)
{
    if (!$client) {
        return $url;
    }
    $fields = [
        'firstName' => isset($client->firstname) ? $client->firstname : '',
        'lastName' => isset($client->lastname) ? $client->lastname : '',
        'email' => isset($client->email) ? $client->email : '',
        'phone' => isset($client->phonenumber) ? preg_replace('/[^0-9+]/', '', (string) $client->phonenumber) : '',
        'country' => isset($client->country) ? strtoupper((string) $client->country) : '',
    ];
    $fields = array_filter($fields, function ($v) {
        return trim((string) $v) !== '';
    });
    if (!$fields) {
        return $url;
    }
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
}

function pokpay_fail_page($message, $invoiceId = 0, $systemUrl = '')
{
    http_response_code(400);
    $back = $invoiceId ? $systemUrl . 'viewinvoice.php?id=' . (int) $invoiceId : $systemUrl . 'clientarea.php';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Payment error</title></head><body style="font-family:sans-serif;max-width:560px;margin:60px auto;padding:0 16px">'
        . '<h2>Payment could not be started</h2><p>' . htmlspecialchars($message) . '</p>'
        . '<p><a href="' . htmlspecialchars($back) . '">&larr; Back to invoice</a></p></body></html>';
    exit;
}

$params = getGatewayVariables(Gateway::MODULE);
if (empty($params['type'])) {
    pokpay_fail_page('The POK payment gateway is not activated.');
}
$systemUrl = Gateway::systemUrl($params);

$invoiceId = isset($_REQUEST['invoiceid']) ? (int) $_REQUEST['invoiceid'] : 0;
$token = isset($_REQUEST['token']) ? (string) $_REQUEST['token'] : '';
if ($invoiceId <= 0 || !hash_equals(Gateway::invoiceToken($params, $invoiceId), $token)) {
    pokpay_fail_page('Invalid payment request.', $invoiceId, $systemUrl);
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    pokpay_fail_page('Invoice not found.', 0, $systemUrl);
}
if ($invoice->status !== 'Unpaid') {
    header('Location: ' . $systemUrl . 'viewinvoice.php?id=' . $invoiceId);
    exit;
}

// Balance due in the invoice (client) currency.
$paid = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin')
    - (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountout');
$balance = round((float) $invoice->total - $paid, 2);
if ($balance <= 0) {
    header('Location: ' . $systemUrl . 'viewinvoice.php?id=' . $invoiceId);
    exit;
}

$client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
$clientCurrencyId = $client ? (int) $client->currency : 0;
$clientCurrency = Capsule::table('tblcurrencies')->where('id', $clientCurrencyId)->first();
if (!$clientCurrency) {
    $clientCurrency = Capsule::table('tblcurrencies')->where('default', 1)->first();
    $clientCurrencyId = $clientCurrency ? (int) $clientCurrency->id : 0;
}

$chargeAmount = $balance;
$chargeCurrency = $clientCurrency ? strtoupper($clientCurrency->code) : '';

// Honour WHMCS "Convert To For Processing".
if (!empty($params['convertto']) && (int) $params['convertto'] !== $clientCurrencyId) {
    $target = Capsule::table('tblcurrencies')->where('id', (int) $params['convertto'])->first();
    if ($target) {
        $chargeAmount = round((float) convertCurrency($balance, $clientCurrencyId, (int) $target->id), 2);
        $chargeCurrency = strtoupper($target->code);
    }
}

if (!in_array($chargeCurrency, Gateway::SUPPORTED_CURRENCIES, true)) {
    pokpay_fail_page('POK only accepts ALL or EUR. Please contact support.', $invoiceId, $systemUrl);
}

// Send the client back to a still-valid checkout instead of creating duplicates.
$existing = Gateway::reusableOrder($invoiceId, $chargeAmount, $chargeCurrency);
if ($existing) {
    header('Location: ' . pokpay_prefill_url($existing['confirm_url'], $client));
    exit;
}

$minutes = isset($params['expiresAfterMinutes']) ? (int) $params['expiresAfterMinutes'] : 60;
if ($minutes < 5) {
    $minutes = 60;
}

$invoiceNum = !empty($invoice->invoicenum) ? $invoice->invoicenum : (string) $invoiceId;
$description = !empty($params['orderDescription']) ? $params['orderDescription'] : '{company} - Invoice #{invoicenum}';
$description = strtr($description, [
    '{invoiceid}' => (string) $invoiceId,
    '{invoicenum}' => (string) $invoiceNum,
    '{company}' => isset($params['companyname']) ? (string) $params['companyname'] : '',
]);
$description = trim(preg_replace('/^\s*-\s*/', '', $description));

$callback = $systemUrl . 'modules/gateways/callback/pokpay.php?invoiceid=' . $invoiceId;

$payload = [
    'amount' => $chargeAmount,
    'currencyCode' => $chargeCurrency,
    'autoCapture' => true,
    'products' => [],
    'description' => function_exists('mb_substr') ? mb_substr($description, 0, 255) : substr($description, 0, 255),
    'merchantCustomReference' => (string) $invoiceId,
    'redirectUrl' => $callback . '&action=return',
    'failRedirectUrl' => $callback . '&action=fail',
    'webhookUrl' => $callback . '&action=webhook',
    'expiresAfterMinutes' => $minutes,
];

try {
    $api = Gateway::client($params);
    $order = $api->createOrder($payload);
    $confirmUrl = PokPayClient::confirmUrl($order);
    if ($confirmUrl === '') {
        throw new \RuntimeException('PokPay did not return a payment page URL.');
    }

    $expiresAt = !empty($order['expiresAt']) && strtotime($order['expiresAt'])
        ? date('Y-m-d H:i:s', strtotime($order['expiresAt']))
        : date('Y-m-d H:i:s', time() + $minutes * 60);

    Gateway::storeOrder($invoiceId, $order, $chargeAmount, $chargeCurrency, $balance, $confirmUrl, $expiresAt);
} catch (\Exception $e) {
    Gateway::logTransaction($params, ['invoice_id' => $invoiceId, 'payload' => $payload, 'error' => $e->getMessage()], 'Order Creation Failed');
    pokpay_fail_page('We could not connect to POK right now. Please try again in a few minutes.', $invoiceId, $systemUrl);
}

header('Location: ' . pokpay_prefill_url($confirmUrl, $client));
exit;
