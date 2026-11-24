<?php
/**
 * PokPay callback handler.
 *
 *   ?action=return   - client redirected back after paying (redirectUrl)
 *   ?action=fail     - client redirected back after a failed/cancelled payment (failRedirectUrl)
 *   ?action=webhook  - server-to-server notification from PokPay (webhookUrl)
 *
 * PokPay webhooks are not signed, so the payload is never trusted: in every case the
 * module re-fetches the orders it created for the invoice from the PokPay API and only
 * applies a payment that PokPay reports as completed for the exact amount and currency.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../pokpay/lib/Gateway.php';

use PokPayWhmcs\Gateway;
use PokPayWhmcs\PokPayClient;

$params = getGatewayVariables(Gateway::MODULE);
if (empty($params['type'])) {
    http_response_code(503);
    die('Module Not Activated');
}

$systemUrl = Gateway::systemUrl($params);
$invoiceId = isset($_GET['invoiceid']) ? (int) $_GET['invoiceid'] : 0;
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = 'webhook';
}

if ($action === 'webhook') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $order = PokPayClient::extractOrder($payload);
    $orderId = isset($order['id']) ? (string) $order['id'] : '';

    // Fall back to the order's reference if the invoice id wasn't in the URL.
    if ($invoiceId <= 0 && $orderId !== '') {
        $row = Gateway::findOrder($orderId);
        $invoiceId = $row ? (int) $row['invoice_id'] : 0;
    }

    $result = $invoiceId > 0 ? Gateway::reconcileInvoice($params, $invoiceId, $orderId) : 'ignored';
    Gateway::logTransaction($params, ['action' => 'webhook', 'invoice_id' => $invoiceId, 'result' => $result, 'payload' => $payload], 'Webhook: ' . $result);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['received' => true, 'result' => $result]);
    exit;
}

if ($invoiceId <= 0) {
    header('Location: ' . $systemUrl . 'clientarea.php');
    exit;
}

$invoiceUrl = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;

if ($action === 'return') {
    // The webhook and the redirect can race; give PokPay a moment to settle.
    $result = 'pending';
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $result = Gateway::reconcileInvoice($params, $invoiceId);
        if ($result !== 'pending') {
            break;
        }
        sleep(2);
    }

    if ($result === 'paid' || $result === 'already_paid') {
        header('Location: ' . $invoiceUrl . '&paymentsuccess=true');
    } elseif ($result === 'failed') {
        header('Location: ' . $invoiceUrl . '&paymentfailed=true');
    } else {
        // Still processing: the webhook will apply the payment when PokPay confirms it.
        header('Location: ' . $invoiceUrl);
    }
    exit;
}

if ($action === 'fail') {
    // Double-check in case the payment did go through.
    $result = Gateway::reconcileInvoice($params, $invoiceId);
    if ($result === 'paid' || $result === 'already_paid') {
        header('Location: ' . $invoiceUrl . '&paymentsuccess=true');
        exit;
    }
    Gateway::logTransaction($params, ['action' => 'fail', 'invoice_id' => $invoiceId, 'query' => $_GET], 'Failed / Cancelled');
    header('Location: ' . $invoiceUrl . '&paymentfailed=true');
    exit;
}

header('Location: ' . $invoiceUrl);
exit;
