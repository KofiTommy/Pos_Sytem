<?php
header('Content-Type: application/json');

include 'admin-auth.php';
include 'db-connection.php';
include 'tenant-context.php';
include 'paystack-service.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function masked_secret(string $secret): string {
    if ($secret === '') {
        return '';
    }
    $len = strlen($secret);
    if ($len <= 6) {
        return str_repeat('*', $len);
    }
    return substr($secret, 0, 4) . str_repeat('*', max($len - 6, 2)) . substr($secret, -2);
}

function test_paystack_key(string $secret): array {
    $ch = curl_init('https://api.paystack.co/integration/payment_session_timeout');
    if ($ch === false) {
        throw new Exception('Failed to initialize gateway test.');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secret,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $rawResp = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    $err = curl_error($ch);
    curl_close($ch);
    if ($rawResp === false || $err !== '') {
        throw new Exception('Gateway test failed: ' . ($err ?: 'Unknown network error'));
    }
    $parsed = json_decode($rawResp, true);
    if (!is_array($parsed)) {
        throw new Exception('Invalid response from Paystack.');
    }
    if ($httpCode >= 400 || empty($parsed['status'])) {
        throw new Exception((string)($parsed['message'] ?? 'Invalid Paystack key.'));
    }
    return $parsed;
}

function load_settings_payload(mysqli $conn, int $businessId): array {
    $settings = load_paystack_settings($conn, $businessId);
    $secret = paystack_secret_key($conn, $businessId);
    $public = paystack_public_key($conn, $businessId);

    return [
        'enabled' => intval($settings['enabled'] ?? 0) === 1,
        'use_sandbox' => intval($settings['use_sandbox'] ?? 1) === 1,
        'public_key' => $public,
        'secret_key_masked' => masked_secret($secret),
        'source' => trim((string)getenv('PAYSTACK_SECRET_KEY')) !== '' ? 'environment' : 'database',
        'crypto_ready' => payment_settings_crypto_key() !== '',
        'updated_at' => $settings['updated_at'] ?? null
    ];
}

try {
    require_roles_api(['owner']);
    ensure_multitenant_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        throw new Exception('Invalid business context. Please sign in again.');
    }
    ensure_payment_gateway_settings_table($conn, $businessId);

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        respond(true, '', ['settings' => load_settings_payload($conn, $businessId)]);
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $action = strtolower(trim((string)($body['action'] ?? 'save')));

        if ($action === 'test') {
            $testSecret = trim((string)($body['secret_key'] ?? ''));
            $effectiveSecret = $testSecret !== '' ? $testSecret : paystack_secret_key($conn, $businessId);
            if ($effectiveSecret === '') {
                throw new Exception('No Paystack secret key configured for test.');
            }
            $response = test_paystack_key($effectiveSecret);

            respond(true, 'Connection to Paystack succeeded.', [
                'gateway_message' => $response['message'] ?? 'ok',
                'settings' => load_settings_payload($conn, $businessId)
            ]);
        }

        $enabled = !empty($body['enabled']) ? 1 : 0;
        $useSandbox = !empty($body['use_sandbox']) ? 1 : 0;
        $publicKey = trim((string)($body['public_key'] ?? ''));
        $secretKey = trim((string)($body['secret_key'] ?? ''));

        if ($publicKey !== '' && strlen($publicKey) > 200) {
            respond(false, 'Public key is too long.');
        }
        if ($secretKey !== '' && strlen($secretKey) > 200) {
            respond(false, 'Secret key is too long.');
        }

        $ciphertext = null;
        $iv = null;
        if ($secretKey !== '') {
            $encrypted = encrypt_payment_secret($secretKey);
            $ciphertext = $encrypted['ciphertext'];
            $iv = $encrypted['iv'];
        }

        if ($secretKey !== '') {
            $stmt = $conn->prepare(
                "UPDATE payment_gateway_settings
                 SET enabled = ?, use_sandbox = ?, public_key = ?, secret_key_ciphertext = ?, secret_key_iv = ?
                 WHERE business_id = ?"
            );
            $stmt->bind_param('iisssi', $enabled, $useSandbox, $publicKey, $ciphertext, $iv, $businessId);
        } else {
            $stmt = $conn->prepare(
                "UPDATE payment_gateway_settings
                 SET enabled = ?, use_sandbox = ?, public_key = ?
                 WHERE business_id = ?"
            );
            $stmt->bind_param('iisi', $enabled, $useSandbox, $publicKey, $businessId);
        }
        $stmt->execute();
        $stmt->close();

        respond(true, 'Payment settings saved.', ['settings' => load_settings_payload($conn, $businessId)]);
    }

    respond(false, 'Method not allowed.');
} catch (Exception $e) {
    http_response_code(500);
    respond(false, $e->getMessage());
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
