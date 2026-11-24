<?php
/**
 * Mock of the PokPay REST API, following the response shapes of the official
 * SDK (data.accessToken, data.sdkOrder, sdkOrder._self.confirmUrl).
 * Run with: php -S 127.0.0.1:PORT router.php   (env MOCK_STATE=/path/state.json)
 *
 * Test-only control endpoints:
 *   POST /__test/complete/{id}   body {"amount":..,"currencyCode":..} optional overrides
 *   POST /__test/cancel/{id}
 *   GET  /__test/state
 */

const KEY_ID = 'test-key-id';
const KEY_SECRET = 'test-key-secret';
const MERCHANT = 'merchant-123';
const TOKEN = 'mock-jwt-token';

$stateFile = getenv('MOCK_STATE');
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : ['orders' => [], 'requests' => [], 'refunds' => []];

function respond($code, $body)
{
    global $state, $stateFile;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

function requireAuth()
{
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if ($h !== 'Bearer ' . TOKEN) {
        respond(401, ['statusCode' => 401, 'message' => 'Unauthorized']);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$state['requests'][] = ['method' => $method, 'path' => $path, 'body' => $body, 'auth' => isset($_SERVER['HTTP_AUTHORIZATION'])];

if ($method === 'POST' && $path === '/auth/sdk/login') {
    if (($body['keyId'] ?? '') !== KEY_ID || ($body['keySecret'] ?? '') !== KEY_SECRET) {
        respond(400, ['statusCode' => 400, 'message' => 'Invalid credentials']);
    }
    respond(200, ['statusCode' => 200, 'message' => 'OK', 'data' => [
        'accessToken' => TOKEN, 'tokenType' => 'Bearer', 'expiresIn' => '3600',
        'expiresAt' => gmdate('c', time() + 3600),
    ]]);
}

if ($method === 'POST' && preg_match('#^/merchants/([^/]+)/sdk-orders$#', $path, $m)) {
    requireAuth();
    if ($m[1] !== MERCHANT) {
        respond(403, ['statusCode' => 403, 'message' => 'Forbidden']);
    }
    if (!isset($body['amount']) || !in_array($body['currencyCode'] ?? '', ['ALL', 'EUR'], true)) {
        respond(400, ['statusCode' => 400, 'message' => 'Invalid order']);
    }
    $id = bin2hex(random_bytes(8));
    $host = 'http://' . $_SERVER['HTTP_HOST'];
    $order = [
        'id' => $id,
        'amount' => (float) $body['amount'],
        'capturedAmount' => 0,
        'currencyCode' => $body['currencyCode'],
        'products' => $body['products'] ?? [],
        'finalAmount' => (float) $body['amount'],
        'createdAt' => gmdate('c'),
        'expiresAt' => gmdate('c', time() + 60 * (int) ($body['expiresAfterMinutes'] ?? 60)),
        'redirectUrl' => $body['redirectUrl'] ?? null,
        'failRedirectUrl' => $body['failRedirectUrl'] ?? null,
        'webhookUrl' => $body['webhookUrl'] ?? null,
        'merchantCustomReference' => $body['merchantCustomReference'] ?? null,
        'isCompleted' => false,
        'isCanceled' => false,
        'isRefunded' => false,
        'hasFailedPaymentFlow' => false,
        'merchant' => ['id' => MERCHANT, 'name' => 'Test Hosting'],
        '_self' => ['confirmUrl' => $host . '/pay/' . $id],
    ];
    $state['orders'][$id] = $order;
    respond(201, ['statusCode' => 201, 'message' => 'Created', 'data' => ['sdkOrder' => $order]]);
}

if ($method === 'GET' && preg_match('#^/merchants/([^/]+)/sdk-orders/([^/]+)$#', $path, $m)) {
    requireAuth();
    if (!isset($state['orders'][$m[2]]) || $m[1] !== MERCHANT) {
        respond(404, ['statusCode' => 404, 'message' => 'Not found']);
    }
    respond(200, ['statusCode' => 200, 'data' => ['sdkOrder' => $state['orders'][$m[2]]]]);
}

if ($method === 'GET' && preg_match('#^/sdk-orders/([^/]+)$#', $path, $m)) {
    if (!isset($state['orders'][$m[1]])) {
        respond(404, ['statusCode' => 404, 'message' => 'Not found']);
    }
    respond(200, ['statusCode' => 200, 'data' => ['sdkOrder' => $state['orders'][$m[1]]]]);
}

if ($method === 'POST' && preg_match('#^/merchants/([^/]+)/sdk-orders/([^/]+)/refund$#', $path, $m)) {
    requireAuth();
    if (!isset($state['orders'][$m[2]])) {
        respond(404, ['statusCode' => 404, 'message' => 'Not found']);
    }
    if (empty($body['refundReason'])) {
        respond(400, ['statusCode' => 400, 'message' => '"refundReason" is required']);
    }
    $state['refunds'][] = ['orderId' => $m[2], 'body' => $body];
    if (!isset($body['refundAmount'])) {
        $state['orders'][$m[2]]['isRefunded'] = true;
    }
    respond(200, ['statusCode' => 200, 'data' => ['sdkOrder' => $state['orders'][$m[2]]]]);
}

if ($method === 'POST' && preg_match('#^/merchants/([^/]+)/subscription-plans$#', $path, $m)) {
    requireAuth();
    foreach (['name', 'amount', 'currencyCode', 'frequency'] as $k) {
        if (!isset($body[$k])) {
            respond(400, ['statusCode' => 400, 'message' => "\"$k\" is required"]);
        }
    }
    $id = 'plan_' . bin2hex(random_bytes(6));
    $plan = array_merge($body, ['id' => $id, 'merchantId' => $m[1], 'isActive' => true, 'webhookSecret' => bin2hex(random_bytes(32))]);
    $state['plans'][$id] = $plan;
    respond(200, ['statusCode' => 200, 'serverStatusCode' => 200, 'data' => ['subscriptionPlan' => $plan]]);
}

if ($method === 'POST' && preg_match('#^/merchants/([^/]+)/subscriptions$#', $path, $m)) {
    requireAuth();
    if (empty($body['planId']) || !isset($state['plans'][$body['planId']])) {
        respond(400, ['statusCode' => 400, 'message' => 'Invalid plan']);
    }
    $unknown = array_diff(array_keys($body), ['planId', 'externalCustomerId', 'expiresAfterMinutes']);
    if ($unknown) {
        respond(400, ['statusCode' => 400, 'message' => 'Unknown fields: ' . implode(',', $unknown)]);
    }
    $plan = $state['plans'][$body['planId']];
    $id = 'sub_' . bin2hex(random_bytes(6));
    $sub = [
        'id' => $id, 'merchantId' => $m[1], 'planId' => $plan['id'], 'planName' => $plan['name'],
        'amount' => $plan['amount'], 'currencyCode' => $plan['currencyCode'], 'status' => 'pending',
        'customer' => ['email' => null, 'externalCustomerId' => $body['externalCustomerId'] ?? null],
        '_self' => ['confirmUrl' => 'http://' . $_SERVER['HTTP_HOST'] . '/subscriptions/' . $id, 'confirmDeeplink' => ''],
    ];
    $state['subscriptions'][$id] = $sub;
    respond(200, ['statusCode' => 200, 'data' => ['subscription' => $sub, 'plan' => ['id' => $plan['id'], 'name' => $plan['name']]]]);
}

// ---- test controls ----
if ($method === 'POST' && preg_match('#^/__test/complete/([^/]+)$#', $path, $m)) {
    $state['orders'][$m[1]]['isCompleted'] = true;
    $state['orders'][$m[1]]['capturedAmount'] = $state['orders'][$m[1]]['amount'];
    foreach (['amount', 'currencyCode'] as $k) {
        if (isset($body[$k])) {
            $state['orders'][$m[1]][$k] = $body[$k];
        }
    }
    respond(200, ['ok' => true]);
}
if ($method === 'POST' && preg_match('#^/__test/cancel/([^/]+)$#', $path, $m)) {
    $state['orders'][$m[1]]['isCanceled'] = true;
    respond(200, ['ok' => true]);
}
if ($method === 'GET' && $path === '/__test/state') {
    array_pop($state['requests']);
    respond(200, $state);
}

respond(404, ['statusCode' => 404, 'message' => 'No route ' . $method . ' ' . $path]);
