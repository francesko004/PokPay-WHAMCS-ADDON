<?php
/**
 * End-to-end tests for the PokPay WHMCS gateway.
 *
 *   cd tests && composer install && php run-tests.php
 *
 * Starts two PHP built-in servers: a fake WHMCS install (with the module copied in)
 * and a mock PokPay API, then drives the real pay.php / callback / refund code.
 */

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/pokpay-whmcs-test-' . getmypid();
$whmcsPort = 18000 + (getmypid() % 1000);
$apiPort = $whmcsPort + 1000;
$whmcsUrl = "http://127.0.0.1:$whmcsPort/";
$apiUrl = "http://127.0.0.1:$apiPort";

// ---------------------------------------------------------------- setup
exec('rm -rf ' . escapeshellarg($work));
mkdir("$work/whmcs", 0777, true);
exec('cp -R ' . escapeshellarg(__DIR__ . '/whmcs-stub') . '/. ' . escapeshellarg("$work/whmcs"));
exec('cp -R ' . escapeshellarg("$root/modules") . ' ' . escapeshellarg("$work/whmcs/modules"));

$env = [
    'POKPAY_TEST_DB' => "$work/whmcs.sqlite",
    'POKPAY_TEST_SYSTEMURL' => $whmcsUrl,
    'POKPAY_TEST_VENDOR' => getenv('POKPAY_TEST_VENDOR') ?: __DIR__ . '/vendor',
    'MOCK_STATE' => "$work/mock-state.json",
];
foreach ($env as $k => $v) {
    putenv("$k=$v");
}

require "$work/whmcs/init.php";

use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$schema->create('tblpaymentgateways', function ($t) { $t->increments('id'); $t->string('gateway'); $t->string('setting'); $t->text('value'); });
$schema->create('tblcurrencies', function ($t) { $t->increments('id'); $t->string('code'); $t->decimal('rate', 10, 5); $t->integer('default')->default(0); });
$schema->create('tblclients', function ($t) { $t->increments('id'); $t->integer('currency'); $t->string('firstname')->default(''); $t->string('lastname')->default(''); $t->string('email')->default(''); $t->string('phonenumber')->default(''); $t->string('country')->default(''); });
$schema->create('tblinvoices', function ($t) { $t->increments('id'); $t->integer('userid'); $t->string('invoicenum')->default(''); $t->decimal('total', 10, 2); $t->string('status'); });
$schema->create('tblaccounts', function ($t) { $t->increments('id'); $t->integer('invoiceid'); $t->string('transid'); $t->decimal('amountin', 10, 2); $t->decimal('amountout', 10, 2)->default(0); $t->decimal('fees', 10, 2)->default(0); $t->string('gateway'); });

$gatewaySettings = [
    'type' => 'Invoices', 'name' => 'POK - Pay with card', 'merchantId' => 'merchant-123',
    'keyId' => 'test-key-id', 'keySecret' => 'test-key-secret', 'sandbox' => 'on',
    'expiresAfterMinutes' => '60', 'orderDescription' => '{company} - Invoice #{invoicenum}',
    'buttonText' => 'Paguaj me kartë (POK)', 'apiBaseUrl' => $apiUrl, 'convertto' => '',
];
foreach ($gatewaySettings as $k => $v) {
    DB::table('tblpaymentgateways')->insert(['gateway' => 'pokpay', 'setting' => $k, 'value' => $v]);
}
DB::table('tblcurrencies')->insert([
    ['id' => 1, 'code' => 'ALL', 'rate' => 1, 'default' => 1],
    ['id' => 2, 'code' => 'EUR', 'rate' => 0.01, 'default' => 0],
    ['id' => 3, 'code' => 'USD', 'rate' => 0.011, 'default' => 0],
]);
DB::table('tblclients')->insert(['id' => 1, 'currency' => 1, 'firstname' => 'Arta', 'lastname' => 'Hoxha', 'email' => 'arta@example.al', 'phonenumber' => '+355.69 123 4567', 'country' => 'al']);
DB::table('tblclients')->insert([['id' => 2, 'currency' => 3], ['id' => 3, 'currency' => 2]]);
$inv = function ($id, $user, $total) { DB::table('tblinvoices')->insert(['id' => $id, 'userid' => $user, 'invoicenum' => "INV-$id", 'total' => $total, 'status' => 'Unpaid']); };
$inv(1, 1, 2500.00);  // ALL
$inv(2, 1, 1200.00);  // ALL, tampered amount
$inv(3, 1, 999.00);   // ALL, forged webhook
$inv(4, 2, 11.00);    // USD -> converted to EUR
$inv(5, 1, 800.00);   // ALL, cancelled / fail
$inv(6, 3, 20.00);    // EUR, partial refund rejected

// ---------------------------------------------------------------- servers
$procs = [];
$start = function ($port, $docroot, $router = null) use (&$procs, $env, $work) {
    $cmd = 'exec php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docroot) . ($router ? ' ' . escapeshellarg($router) : '');
    $procs[] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', "$work/server-$port.log", 'a'], 2 => ['file', "$work/server-$port.log", 'a']], $pipes, null, array_merge(getenv(), $env));
};
$start($whmcsPort, "$work/whmcs");
$start($apiPort, __DIR__ . '/mock-pokpay', __DIR__ . '/mock-pokpay/router.php');
register_shutdown_function(function () use (&$procs, $work) {
    foreach ($procs as $p) { proc_terminate($p); }
    if (!getenv('KEEP')) { exec('rm -rf ' . escapeshellarg($work)); } else { echo "Kept $work\n"; }
});
for ($i = 0; $i < 50; $i++) {
    $a = @fsockopen('127.0.0.1', $whmcsPort); $b = @fsockopen('127.0.0.1', $apiPort);
    if ($a && $b) break;
    usleep(100000);
}

// ---------------------------------------------------------------- helpers
function http($method, $url, $body = null, $json = false)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json ? json_encode($body) : http_build_query($body));
        if ($json) curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hs);
    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);
    return ['status' => $status, 'location' => isset($m[1]) ? trim($m[1]) : '', 'body' => substr($raw, $hs)];
}

$failures = 0; $passes = 0;
function check($cond, $label) {
    global $failures, $passes;
    if ($cond) { $passes++; echo "  \033[32m✓\033[0m $label\n"; } else { $failures++; echo "  \033[31m✗ $label\033[0m\n"; }
}
function state() { global $apiUrl; return json_decode(http('GET', "$apiUrl/__test/state")['body'], true); }
function token($invoiceId) { global $gatewaySettings; return \PokPayWhmcs\Gateway::invoiceToken($gatewaySettings, $invoiceId); }
function pay($invoiceId) { global $whmcsUrl; return http('GET', $whmcsUrl . 'modules/gateways/pokpay/pay.php?' . http_build_query(['invoiceid' => $invoiceId, 'token' => token($invoiceId)])); }
function cb($invoiceId, $action, $body = null) { global $whmcsUrl; return http($body === null ? 'GET' : 'POST', $whmcsUrl . "modules/gateways/callback/pokpay.php?invoiceid=$invoiceId&action=$action", $body, true); }
function orderIdFrom($location) { return basename(parse_url($location, PHP_URL_PATH)); }
function invStatus($id) { return DB::table('tblinvoices')->where('id', $id)->value('status'); }

require_once "$work/whmcs/modules/gateways/pokpay/lib/Gateway.php";
require_once "$work/whmcs/modules/gateways/pokpay.php";

// ---------------------------------------------------------------- tests
echo "\n1. Payment button\n";
$html = pokpay_link(array_merge($gatewaySettings, ['invoiceid' => 1, 'currency' => 'ALL', 'systemurl' => $whmcsUrl]));
check(strpos($html, 'method="get"') !== false && strpos($html, 'modules/gateways/pokpay/pay.php') !== false && strpos($html, token(1)) !== false, 'renders GET form to pay.php with signed token');
$html = pokpay_link(array_merge($gatewaySettings, ['invoiceid' => 4, 'currency' => 'USD', 'systemurl' => $whmcsUrl]));
check(strpos($html, 'ALL or EUR') !== false, 'warns when invoice currency is not ALL/EUR');

echo "\n2. Starting a payment\n";
$r = http('POST', $whmcsUrl . 'modules/gateways/pokpay/pay.php', ['invoiceid' => 1, 'token' => 'forged']);
check($r['status'] === 400, 'rejects a forged token');
$r = pay(1);
check($r['status'] === 302 && strpos($r['location'], "$apiUrl/pay/") === 0, 'redirects to POK hosted payment page');
check(strpos($r['location'], '?firstName=Arta&lastName=Hoxha&email=arta%40example.al&phone=%2B355691234567&country=AL') !== false, 'payment page prefilled with customer details');
$order1 = orderIdFrom($r['location']);
$s = state();
$create = null;
foreach ($s['requests'] as $req) { if ($req['method'] === 'POST' && substr($req['path'], -11) === '/sdk-orders') { $create = $req; } }
check($create && $create['auth'], 'order created with Bearer auth after login');
check($create['body']['amount'] == 2500 && $create['body']['currencyCode'] === 'ALL', 'order amount 2500 ALL');
check($create['body']['merchantCustomReference'] === '1' && $create['body']['autoCapture'] === true, 'reference = invoice id, autoCapture on');
check(strpos($create['body']['webhookUrl'], 'callback/pokpay.php?invoiceid=1&action=webhook') !== false, 'webhook URL points to callback');
check($create['body']['description'] === 'Test Hosting shpk - Invoice #INV-1', 'description uses placeholders');
$r2 = pay(1);
check($r2['location'] === $r['location'] && count(state()['orders']) === 1, 'reuses pending checkout instead of creating a duplicate');
$r3 = http('POST', $whmcsUrl . 'modules/gateways/pokpay/pay.php', ['invoiceid' => 1, 'token' => token(1)]);
check($r3['location'] === $r['location'], 'POST requests still accepted');

echo "\n3. Return before payment completes\n";
$r = cb(1, 'return');
check($r['status'] === 302 && strpos($r['location'], 'paymentsuccess') === false, 'not marked successful while pending');
check(invStatus(1) === 'Unpaid', 'invoice still unpaid');

echo "\n4. Webhook after payment\n";
http('POST', "$apiUrl/__test/complete/$order1", []);
$r = cb(1, 'webhook', ['data' => ['sdkOrder' => ['id' => $order1, 'isCompleted' => true]]]);
check($r['status'] === 200 && strpos($r['body'], '"paid"') !== false, 'webhook returns 200 / paid');
check(invStatus(1) === 'Paid', 'invoice marked Paid');
$acc = DB::table('tblaccounts')->where('invoiceid', 1)->get();
check(count($acc) === 1 && $acc[0]->transid === $order1 && (float) $acc[0]->amountin === 2500.0, 'one payment of 2500 with PokPay order id as transaction id');

echo "\n5. Customer return + webhook retry after payment (idempotency)\n";
$r = cb(1, 'return');
check(strpos($r['location'], 'viewinvoice.php?id=1&paymentsuccess=true') !== false, 'return redirects with paymentsuccess=true');
cb(1, 'webhook', ['data' => ['sdkOrder' => ['id' => $order1]]]);
check(DB::table('tblaccounts')->where('invoiceid', 1)->count() === 1, 'no duplicate payment recorded');

echo "\n6. Amount tampering\n";
$order2 = orderIdFrom(pay(2)['location']);
http('POST', "$apiUrl/__test/complete/$order2", ['amount' => 1.00], true);
cb(2, 'webhook', ['data' => ['sdkOrder' => ['id' => $order2]]]);
check(invStatus(2) === 'Unpaid' && DB::table('tblaccounts')->where('invoiceid', 2)->count() === 0, 'completed order with wrong amount is NOT applied');

echo "\n7. Forged webhook\n";
$r = cb(3, 'webhook', ['data' => ['sdkOrder' => ['id' => 'attacker-order', 'isCompleted' => true, 'amount' => 999]]]);
check($r['status'] === 200 && invStatus(3) === 'Unpaid', 'payload claims are ignored; nothing paid');
$order3 = orderIdFrom(pay(3)['location']);
cb(3, 'webhook', ['data' => ['sdkOrder' => ['id' => $order3, 'isCompleted' => true]]]);
check(invStatus(3) === 'Unpaid', 'webhook for our order is ignored until PokPay itself reports it completed');

echo "\n8. Currency conversion (USD invoice -> EUR at POK)\n";
DB::table('tblpaymentgateways')->where('gateway', 'pokpay')->where('setting', 'convertto')->update(['value' => '2']);
$r = pay(4);
$order4 = orderIdFrom($r['location']);
$o4 = state()['orders'][$order4];
check($o4['currencyCode'] === 'EUR' && abs($o4['amount'] - 10.00) < 0.001, 'charged 10.00 EUR for 11.00 USD');
http('POST', "$apiUrl/__test/complete/$order4", []);
check(strpos(cb(4, 'return')['location'], 'paymentsuccess=true') !== false, 'return confirms payment');
check(invStatus(4) === 'Paid' && (float) DB::table('tblaccounts')->where('invoiceid', 4)->value('amountin') === 11.0, 'payment recorded as 11.00 in invoice currency');
DB::table('tblpaymentgateways')->where('gateway', 'pokpay')->where('setting', 'convertto')->update(['value' => '']);

echo "\n9. Failed / cancelled payment\n";
$order5 = orderIdFrom(pay(5)['location']);
http('POST', "$apiUrl/__test/cancel/$order5", []);
$r = cb(5, 'fail');
check(strpos($r['location'], 'paymentfailed=true') !== false && invStatus(5) === 'Unpaid', 'fail redirect shows paymentfailed, invoice unpaid');
$r = pay(5);
check(orderIdFrom($r['location']) !== $order5, 'retry after cancellation creates a fresh order');

echo "\n10. Refunds\n";
$params = array_merge($gatewaySettings, ['transid' => $order1, 'amount' => 2500.00, 'currency' => 'ALL', 'invoiceid' => 1]);
$res = pokpay_refund($params);
$refund = end(state()['refunds']);
check($res['status'] === 'success' && !isset($refund['body']['refundAmount']) && $refund['body']['refundReason'] !== '', 'full refund sends no refundAmount (full) with a reason');
$res = pokpay_refund(array_merge($params, ['amount' => 500.00]));
$refund = end(state()['refunds']);
check($res['status'] === 'success' && $refund['body']['refundAmount'] == 500, 'partial ALL refund sends refundAmount 500');
$order6 = orderIdFrom(pay(6)['location']);
http('POST', "$apiUrl/__test/complete/$order6", []);
cb(6, 'webhook', ['data' => ['sdkOrder' => ['id' => $order6]]]);
$res = pokpay_refund(array_merge($gatewaySettings, ['transid' => $order6, 'amount' => 5.00, 'currency' => 'EUR', 'invoiceid' => 6]));
check($res['status'] === 'error', 'partial EUR refund is refused (PokPay refunds partials in ALL)');
$res = pokpay_refund(array_merge($gatewaySettings, ['transid' => $order6, 'amount' => 20.00, 'currency' => 'EUR', 'invoiceid' => 6]));
check($res['status'] === 'success', 'full EUR refund works');

echo "\n11. Bad credentials\n";
DB::table('tblpaymentgateways')->where('gateway', 'pokpay')->where('setting', 'keySecret')->update(['value' => 'wrong']);
DB::table('tblinvoices')->insert(['id' => 7, 'userid' => 1, 'invoicenum' => '', 'total' => 100, 'status' => 'Unpaid']);
$r = http('POST', $whmcsUrl . 'modules/gateways/pokpay/pay.php', ['invoiceid' => 7, 'token' => \PokPayWhmcs\Gateway::invoiceToken(array_merge($gatewaySettings, ['keySecret' => 'wrong']), 7)]);
check($r['status'] === 400 && strpos($r['body'], 'could not connect to POK') !== false, 'shows a friendly error page');

echo "\n12. Logging\n";
$log = file_get_contents("$work/whmcs-log.jsonl");
check(strpos($log, 'test-key-secret') === false && strpos($log, 'mock-jwt-token') === false, 'key secret and access token are masked in module logs');

echo "\n$passes passed, $failures failed\n";
exit($failures ? 1 : 0);
