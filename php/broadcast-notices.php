<?php
header('Content-Type: application/json');
include_once __DIR__ . '/session-bootstrap.php';
secure_session_start();
include __DIR__ . '/db-connection.php';
include __DIR__ . '/tenant-context.php';
include_once __DIR__ . '/admin-auth.php';

const BROADCAST_PUBLIC_ALLOWED_AUDIENCES = ['customers', 'owners'];

function respond(bool $success, string $message = '', array $extra = [], int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function broadcast_requested_audience(): string {
    $raw = strtolower(trim((string)($_GET['audience'] ?? 'customers')));
    if ($raw === 'customer') {
        $raw = 'customers';
    }
    if ($raw === 'owner') {
        $raw = 'owners';
    }
    if (!in_array($raw, BROADCAST_PUBLIC_ALLOWED_AUDIENCES, true)) {
        return 'customers';
    }
    return $raw;
}

function broadcast_detect_business_id(mysqli $conn, string $audience): int {
    if ($audience === 'owners') {
        $role = current_user_role();
        $businessId = current_business_id();
        if ($role === '' || $businessId <= 0) {
            return 0;
        }
        return $businessId;
    }

    $requestedCode = tenant_request_business_code();
    if ($requestedCode !== '') {
        $business = tenant_fetch_business_by_code($conn, $requestedCode);
        if ($business) {
            return intval($business['id'] ?? 0);
        }
    }

    $sessionBusinessId = intval($_SESSION['business_id'] ?? 0);
    if ($sessionBusinessId > 0) {
        return $sessionBusinessId;
    }

    $fallback = tenant_fetch_default_business($conn);
    if ($fallback) {
        return intval($fallback['id'] ?? 0);
    }

    return 0;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(false, 'Method not allowed.', [], 405);
    }

    if (!tenant_table_exists($conn, 'hq_broadcast_notices')) {
        respond(true, '', [
            'notices' => [],
            'meta' => [
                'count' => 0,
                'audience' => 'customers'
            ]
        ]);
    }

    if (!tenant_table_exists($conn, 'businesses')) {
        respond(true, '', [
            'notices' => [],
            'meta' => [
                'count' => 0,
                'audience' => 'customers'
            ]
        ]);
    }

    $audience = broadcast_requested_audience();
    $businessId = broadcast_detect_business_id($conn, $audience);
    if ($audience === 'owners' && $businessId <= 0) {
        respond(false, 'Unauthorized', [], 401);
    }
    if ($businessId <= 0) {
        respond(true, '', [
            'notices' => [],
            'meta' => [
                'count' => 0,
                'audience' => $audience
            ]
        ]);
    }

    $limit = intval($_GET['limit'] ?? 4);
    if ($limit <= 0) {
        $limit = 4;
    }
    if ($limit > 10) {
        $limit = 10;
    }

    $stmt = $conn->prepare(
        "SELECT
            id,
            business_id,
            audience,
            channel,
            subject,
            message,
            DATE_FORMAT(starts_at, '%Y-%m-%d %H:%i:%s') AS starts_at,
            DATE_FORMAT(expires_at, '%Y-%m-%d %H:%i:%s') AS expires_at,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
         FROM hq_broadcast_notices
         WHERE business_id = ?
           AND is_active = 1
           AND (audience = ? OR audience = 'all')
           AND (starts_at IS NULL OR starts_at <= NOW())
           AND (expires_at IS NULL OR expires_at >= NOW())
         ORDER BY id DESC
         LIMIT ?"
    );
    $stmt->bind_param('isi', $businessId, $audience, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $notices = [];
    while ($row = $result->fetch_assoc()) {
        $notices[] = [
            'id' => intval($row['id'] ?? 0),
            'business_id' => intval($row['business_id'] ?? 0),
            'audience' => (string)($row['audience'] ?? 'customers'),
            'channel' => (string)($row['channel'] ?? 'in_app'),
            'subject' => (string)($row['subject'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'starts_at' => (string)($row['starts_at'] ?? ''),
            'expires_at' => (string)($row['expires_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? '')
        ];
    }
    $stmt->close();

    respond(true, '', [
        'notices' => $notices,
        'meta' => [
            'count' => count($notices),
            'audience' => $audience,
            'business_id' => $businessId
        ]
    ]);
} catch (Exception $e) {
    error_log('broadcast-notices.php: ' . $e->getMessage());
    respond(false, 'Unable to load notices right now.', [], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
