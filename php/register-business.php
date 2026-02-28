<?php
header('Content-Type: application/json');

include 'db-connection.php';
include 'tenant-context.php';

function respond($success, $message = '', $extra = [], $statusCode = 200) {
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function validate_password_strength($password) {
    if (!is_string($password) || strlen($password) < 10) {
        return false;
    }
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) return false;
    return true;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(false, 'Method not allowed', [], 405);
    }

    ensure_multitenant_schema($conn);

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $businessName = trim((string)($body['business_name'] ?? ''));
    $businessEmail = trim((string)($body['business_email'] ?? ''));
    $contactNumber = trim((string)($body['contact_number'] ?? ''));
    $requestedCode = trim((string)($body['business_code'] ?? ''));
    $ownerUsername = trim((string)($body['owner_username'] ?? ''));
    $ownerEmail = trim((string)($body['owner_email'] ?? $businessEmail));
    $ownerPassword = (string)($body['owner_password'] ?? '');
    $plan = strtolower(trim((string)($body['subscription_plan'] ?? MULTI_TENANT_DEFAULT_PLAN)));

    if ($businessName === '' || $businessEmail === '' || $ownerUsername === '' || $ownerEmail === '' || $ownerPassword === '') {
        respond(false, 'Business name, business email, owner username, owner email, and owner password are required', [], 400);
    }
    if (strlen($businessName) > 180) {
        respond(false, 'Business name is too long', [], 400);
    }
    if (!filter_var($businessEmail, FILTER_VALIDATE_EMAIL) || strlen($businessEmail) > 160) {
        respond(false, 'Invalid business email address', [], 400);
    }
    if ($contactNumber !== '' && strlen($contactNumber) > 40) {
        respond(false, 'Contact number is too long', [], 400);
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $ownerUsername)) {
        respond(false, 'Owner username must be 3-60 chars and use letters, numbers, dot, underscore, or dash', [], 400);
    }
    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || strlen($ownerEmail) > 160) {
        respond(false, 'Invalid owner email address', [], 400);
    }
    if (!validate_password_strength($ownerPassword)) {
        respond(false, 'Owner password must be at least 10 chars and include uppercase, lowercase, number, and symbol', [], 400);
    }

    $allowedPlans = ['starter', 'growth', 'pro', 'enterprise'];
    if (!in_array($plan, $allowedPlans, true)) {
        $plan = MULTI_TENANT_DEFAULT_PLAN;
    }

    $businessCode = $requestedCode !== '' ? tenant_slugify_code($requestedCode) : tenant_generate_unique_code($conn, $businessName);
    if ($businessCode === '') {
        respond(false, 'Business code is invalid', [], 400);
    }
    if ($requestedCode !== '' && strlen($businessCode) < 2) {
        respond(false, 'Business code must include at least 2 letters or numbers', [], 400);
    }

    $existingBiz = tenant_fetch_business_by_code($conn, $businessCode);
    if ($existingBiz) {
        respond(false, 'Business code is already in use. Pick another code.', [], 409);
    }

    $conn->begin_transaction();
    try {
        $status = 'active';
        $bizStmt = $conn->prepare(
            "INSERT INTO businesses (business_code, business_name, business_email, contact_number, status, subscription_plan)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $bizStmt->bind_param('ssssss', $businessCode, $businessName, $businessEmail, $contactNumber, $status, $plan);
        $bizStmt->execute();
        $businessId = intval($conn->insert_id);
        $bizStmt->close();

        if ($businessId <= 0) {
            throw new Exception('Could not create business account');
        }

        $ownerRole = 'owner';
        $passwordHash = password_hash($ownerPassword, PASSWORD_DEFAULT);
        $ownerStmt = $conn->prepare(
            "INSERT INTO users (business_id, username, email, password, role)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ownerStmt->bind_param('issss', $businessId, $ownerUsername, $ownerEmail, $passwordHash, $ownerRole);
        $ownerStmt->execute();
        $ownerUserId = intval($conn->insert_id);
        $ownerStmt->close();

        tenant_ensure_business_settings_row($conn, $businessId, $businessName, $businessEmail, $contactNumber);
        tenant_ensure_payment_gateway_row($conn, $businessId);

        $conn->commit();

        respond(true, 'Business account created successfully', [
            'business_id' => $businessId,
            'business_code' => $businessCode,
            'owner_user_id' => $ownerUserId,
            'storefront_url' => '../b/' . rawurlencode($businessCode) . '/',
            'login_url' => '../b/' . rawurlencode($businessCode) . '/pages/login.html'
        ], 201);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
} catch (mysqli_sql_exception $e) {
    error_log('register-business.php sql: ' . $e->getMessage());
    $code = intval($e->getCode());
    if ($code === 1062) {
        respond(false, 'A record with the same unique value already exists (business code, username, or email).', [], 409);
    }
    respond(false, 'Unable to create business account right now.', [], 500);
} catch (Exception $e) {
    error_log('register-business.php: ' . $e->getMessage());
    respond(false, 'Unable to create business account right now.', [], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
