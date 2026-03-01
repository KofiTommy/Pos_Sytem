<?php
header('Content-Type: application/json');
include 'db-connection.php';
include 'payment-schema.php';
include 'tenant-context.php';
include 'paystack-service.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

try {
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['POST', 'GET'], true)) {
        throw new Exception('Invalid request method');
    }

    ensure_payment_schema($conn);

    $reference = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $reference = trim((string)($body['reference'] ?? ''));
    }
    if ($reference === '') {
        $reference = trim((string)($_GET['reference'] ?? ''));
    }
    if ($reference === '') {
        throw new Exception('Missing payment reference.');
    }

    $businessCode = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $businessCode = trim((string)($body['business_code'] ?? ''));
    }
    if ($businessCode === '') {
        $businessCode = trim((string)($_GET['business_code'] ?? ($_GET['tenant'] ?? '')));
    }
    if ($businessCode === '') {
        $businessCode = tenant_request_uri_business_code();
    }
    if ($businessCode === '') {
        throw new Exception('Missing business code for payment verification.');
    }
    $business = tenant_require_business_context(
        $conn,
        ['business_code' => $businessCode],
        false
    );
    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        throw new Exception('Business account not found.');
    }

    if (!paystack_is_configured($conn, $businessId)) {
        throw new Exception('Mobile Money is not configured on this server.');
    }

    $intentStmt = $conn->prepare(
        "SELECT id, status, order_id
         FROM payment_intents
         WHERE reference = ? AND business_id = ?"
    );
    $intentStmt->bind_param('si', $reference, $businessId);
    $intentStmt->execute();
    $intent = $intentStmt->get_result()->fetch_assoc();
    $intentStmt->close();
    if (!$intent) {
        throw new Exception('Payment intent not found.');
    }

    $existingOrderId = intval($intent['order_id'] ?? 0);
    if ($existingOrderId > 0 && in_array((string)$intent['status'], ['paid', 'fulfilled'], true)) {
        respond(true, 'Payment already verified', [
            'order_id' => $existingOrderId,
            'reference' => $reference,
            'already_processed' => true
        ]);
    }

    $verifyResponse = paystack_verify_transaction($reference, $conn, $businessId);
    $verifyData = is_array($verifyResponse['data'] ?? null) ? $verifyResponse['data'] : [];
    $gatewayStatus = strtolower((string)($verifyData['status'] ?? ''));
    if ($gatewayStatus !== 'success') {
        $failedStatus = 'failed';
        $respJson = json_encode($verifyResponse);
        $updateStmt = $conn->prepare("UPDATE payment_intents SET status = ?, gateway_response = ? WHERE reference = ? AND business_id = ?");
        $updateStmt->bind_param('sssi', $failedStatus, $respJson, $reference, $businessId);
        $updateStmt->execute();
        $updateStmt->close();
        throw new Exception('Payment has not been completed yet.');
    }

    $result = finalize_paystack_intent($conn, $reference, $verifyData);
    respond(true, 'Payment verified successfully.', [
        'order_id' => intval($result['order_id'] ?? 0),
        'reference' => $reference,
        'already_processed' => !empty($result['already_processed'])
    ]);
} catch (Exception $e) {
    http_response_code(400);
    error_log('paystack-verify.php: ' . $e->getMessage());
    respond(false, 'Unable to verify payment right now.');
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
