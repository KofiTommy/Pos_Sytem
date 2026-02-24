<?php
include 'db-connection.php';
include 'payment-schema.php';
include 'paystack-service.php';

http_response_code(200);
header('Content-Type: text/plain');

try {
    if (!paystack_is_configured($conn)) {
        echo 'ignored';
        exit();
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        throw new Exception('Empty payload');
    }

    $headerSig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    if ($headerSig === '') {
        throw new Exception('Missing signature');
    }

    $expectedSig = hash_hmac('sha512', $rawBody, paystack_secret_key($conn));
    if (!hash_equals($expectedSig, $headerSig)) {
        throw new Exception('Invalid signature');
    }

    $event = json_decode($rawBody, true);
    if (!is_array($event)) {
        throw new Exception('Invalid JSON');
    }

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

    ensure_payment_schema($conn);
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
