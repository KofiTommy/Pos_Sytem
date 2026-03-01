<?php
include 'db-connection.php';
include 'payment-schema.php';
include 'tenant-context.php';
include 'paystack-service.php';

http_response_code(200);
header('Content-Type: text/plain');

try {
    ensure_payment_schema($conn);
    ensure_multitenant_schema($conn);
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        throw new Exception('Empty payload');
    }

    $previewEvent = json_decode($rawBody, true);
    if (!is_array($previewEvent)) {
        throw new Exception('Invalid JSON');
    }
    $previewData = is_array($previewEvent['data'] ?? null) ? $previewEvent['data'] : [];
    $previewMeta = is_array($previewData['metadata'] ?? null) ? $previewData['metadata'] : [];
    $businessId = intval($previewMeta['business_id'] ?? 0);
    if ($businessId <= 0) {
        $previewReference = trim((string)($previewData['reference'] ?? ''));
        if ($previewReference !== '') {
            $bizStmt = $conn->prepare("SELECT business_id FROM payment_intents WHERE reference = ? LIMIT 1");
            $bizStmt->bind_param('s', $previewReference);
            $bizStmt->execute();
            $bizRow = $bizStmt->get_result()->fetch_assoc();
            $bizStmt->close();
            $businessId = intval($bizRow['business_id'] ?? 0);
        }
    }

    if ($businessId <= 0) {
        echo 'ignored';
        exit();
    }

    if (!paystack_is_configured($conn, $businessId)) {
        echo 'ignored';
        exit();
    }

    $headerSig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    if ($headerSig === '') {
        throw new Exception('Missing signature');
    }

    $expectedSig = hash_hmac('sha512', $rawBody, paystack_secret_key($conn, $businessId));
    if (!hash_equals($expectedSig, $headerSig)) {
        throw new Exception('Invalid signature');
    }

    $event = $previewEvent;

    $eventType = (string)($event['event'] ?? '');
    if ($eventType !== 'charge.success') {
        echo 'ok';
        exit();
    }

    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    $reference = trim((string)($data['reference'] ?? ''));
    $status = strtolower((string)($data['status'] ?? ''));
    if ($reference === '' || $status !== 'success') {
        echo 'ok';
        exit();
    }

    finalize_paystack_intent($conn, $reference, $data);
    echo 'ok';
} catch (Exception $e) {
    http_response_code(400);
    echo 'error';
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
