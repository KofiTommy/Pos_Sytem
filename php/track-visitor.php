<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

include 'db-connection.php';
include 'tenant-context.php';

function respond($success, $message = '', $extra = []): void {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function sanitize_visitor_key($value): string {
    $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$value);
    if (strlen($key) > 120) {
        $key = substr($key, 0, 120);
    }
    return $key;
}

function sanitize_path_value($value, $maxLen = 180): string {
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);
    if (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen);
    }
    return $text;
}

function detect_client_ip(): string {
    // Do not trust client-controlled forwarding headers here.
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }
    return '';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new Exception('Invalid request method', 405);
    }

    $body = [];
    $rawBody = file_get_contents('php://input');
    if ($rawBody !== false && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    $requestedCode = trim((string)($body['business_code'] ?? ($body['tenant'] ?? '')));
    if ($requestedCode === '') {
        respond(true, '', ['tracked' => false]);
    }

    $business = tenant_require_business_context($conn, ['business_code' => $requestedCode], false);
    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        throw new Exception('Business context is invalid', 400);
    }

    $visitorKey = sanitize_visitor_key($body['visitor_key'] ?? '');
    if (strlen($visitorKey) < 16) {
        throw new Exception('Visitor key is required', 400);
    }

    $visitDate = (new DateTime('today'))->format('Y-m-d');
    $landingPage = sanitize_path_value($body['page'] ?? '/');
    if ($landingPage === '') {
        $landingPage = '/';
    }

    $userAgent = sanitize_path_value($_SERVER['HTTP_USER_AGENT'] ?? '', 255);
    $clientIp = detect_client_ip();
    $ipHash = $clientIp !== '' ? hash('sha256', $clientIp) : '';

    $stmt = $conn->prepare(
        "INSERT INTO store_visitors
            (business_id, visitor_key, visit_date, landing_page, user_agent, ip_hash, page_views)
         VALUES (?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            last_seen_at = CURRENT_TIMESTAMP,
            page_views = page_views + 1,
            landing_page = VALUES(landing_page),
            user_agent = VALUES(user_agent)"
    );
    $stmt->bind_param('isssss', $businessId, $visitorKey, $visitDate, $landingPage, $userAgent, $ipHash);
    $stmt->execute();
    $stmt->close();

    respond(true, '', ['tracked' => true]);
} catch (Exception $e) {
    $status = intval($e->getCode());
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    http_response_code($status);
    if ($status >= 500) {
        error_log('track-visitor.php: ' . $e->getMessage());
        respond(false, 'Unable to track visit right now.', ['tracked' => false]);
    }
    respond(false, $e->getMessage(), ['tracked' => false]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
