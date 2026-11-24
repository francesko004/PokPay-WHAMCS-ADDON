<?php
/**
 * Minimal WHMCS stand-in for tests. Provides Capsule (real Illuminate on SQLite)
 * and the handful of WHMCS functions the gateway uses.
 *
 * Env: POKPAY_TEST_DB (sqlite path), POKPAY_TEST_SYSTEMURL, POKPAY_TEST_API (mock API URL)
 */

define('WHMCS', true);

// Fail loudly on any notice/warning/deprecation raised by the module (PHP 8.2+ strictness).
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (strpos($file, '/modules/') === false) {
        return false; // only police the gateway's own code
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

require_once getenv('POKPAY_TEST_VENDOR') . '/autoload.php';

use Illuminate\Database\Capsule\Manager;

if (!class_exists('WHMCS\Database\Capsule')) {
    class_alias(Manager::class, 'WHMCS\Database\Capsule');
}

if (!is_file(getenv('POKPAY_TEST_DB'))) {
    touch(getenv('POKPAY_TEST_DB'));
}

$capsule = new Manager();
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => getenv('POKPAY_TEST_DB'),
    'prefix' => '',
]);
$capsule->setAsGlobal();

function pokpay_test_log($kind, $data)
{
    $file = dirname(getenv('POKPAY_TEST_DB')) . '/whmcs-log.jsonl';
    file_put_contents($file, json_encode(['kind' => $kind, 'data' => $data]) . "\n", FILE_APPEND);
}

function getGatewayVariables($gateway, $invoiceId = '')
{
    $settings = Manager::table('tblpaymentgateways')->where('gateway', $gateway)->pluck('value', 'setting')->all();
    if (!$settings) {
        return ['type' => ''];
    }
    $settings['paymentmethod'] = $gateway;
    $settings['systemurl'] = getenv('POKPAY_TEST_SYSTEMURL');
    $settings['companyname'] = 'Test Hosting shpk';
    return $settings;
}

function logTransaction($gateway, $data, $status)
{
    pokpay_test_log('transaction', ['gateway' => $gateway, 'status' => $status, 'data' => $data]);
}

function logModuleCall($module, $action, $request, $response, $processed = '', $replace = [])
{
    $flat = json_encode([$request, $response]);
    foreach ($replace as $secret) {
        if ($secret !== '' && strpos($flat, $secret) !== false) {
            $flat = str_replace($secret, '***', $flat);
        }
    }
    pokpay_test_log('module', ['action' => $action, 'payload' => json_decode($flat, true)]);
}

function checkCbInvoiceID($invoiceId, $gatewayName)
{
    if (!Manager::table('tblinvoices')->where('id', $invoiceId)->exists()) {
        pokpay_test_log('transaction', ['status' => 'Invoice ID Not Found', 'invoice' => $invoiceId]);
        exit;
    }
    return $invoiceId;
}

function addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway)
{
    Manager::table('tblaccounts')->insert([
        'invoiceid' => $invoiceId,
        'transid' => $transId,
        'amountin' => $amount,
        'amountout' => 0,
        'fees' => $fee,
        'gateway' => $gateway,
    ]);
    $invoice = Manager::table('tblinvoices')->where('id', $invoiceId)->first();
    $paid = Manager::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
    if ($paid + 0.001 >= $invoice->total) {
        Manager::table('tblinvoices')->where('id', $invoiceId)->update(['status' => 'Paid']);
    }
    return true;
}

function convertCurrency($amount, $from, $to)
{
    $fromRate = (float) Manager::table('tblcurrencies')->where('id', $from)->value('rate');
    $toRate = (float) Manager::table('tblcurrencies')->where('id', $to)->value('rate');
    return round($amount / $fromRate * $toRate, 2);
}
