<?php
header('Content-Type: application/json');
include_once __DIR__ . '/hq-auth.php';
hq_require_api();
include __DIR__ . '/db-connection.php';
include __DIR__ . '/tenant-context.php';

const HQ_ALERT_ALLOWED_STATUSES = ['open', 'acknowledged', 'resolved'];

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

function hq_ensure_alert_workflow_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS hq_alert_workflow (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            alert_key VARCHAR(64) NOT NULL,
            business_code VARCHAR(64) NOT NULL DEFAULT '',
            title VARCHAR(190) NOT NULL DEFAULT '',
            detail VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            updated_by VARCHAR(120) NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_hq_alert_key (alert_key),
            INDEX idx_hq_alert_status_time (status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function hq_ensure_action_audit_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS hq_action_audit_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            business_code VARCHAR(64) NOT NULL DEFAULT '',
            action_key VARCHAR(80) NOT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            performed_by VARCHAR(120) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hq_action_business_time (business_id, created_at),
            INDEX idx_hq_action_action_time (action_key, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function hq_action_log(mysqli $conn, int $businessId, string $businessCode, string $actionKey, string $performedBy, array $payload = []): void {
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        $payloadJson = '{}';
    }
    $stmt = $conn->prepare(
        "INSERT INTO hq_action_audit_log
            (business_id, business_code, action_key, payload_json, performed_by)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $businessId, $businessCode, $actionKey, $payloadJson, $performedBy);
    $stmt->execute();
    $stmt->close();
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(false, 'Method not allowed.', [], 405);
    }

    $rawBody = file_get_contents('php://input');
    $body = json_decode((string)$rawBody, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $alertKey = strtolower(trim((string)($body['alert_key'] ?? '')));
    if (!preg_match('/^[a-f0-9]{16,64}$/', $alertKey)) {
        respond(false, 'Valid alert_key is required.', [], 422);
    }

    $status = strtolower(trim((string)($body['status'] ?? '')));
    if (!in_array($status, HQ_ALERT_ALLOWED_STATUSES, true)) {
        respond(false, 'Status must be open, acknowledged, or resolved.', [], 422);
    }

    $businessCode = trim((string)($body['business_code'] ?? ''));
    if (strlen($businessCode) > 64) {
        $businessCode = substr($businessCode, 0, 64);
    }
    $title = trim((string)($body['title'] ?? ''));
    if (strlen($title) > 190) {
        $title = substr($title, 0, 190);
    }
    $detail = trim((string)($body['detail'] ?? ''));
    if (strlen($detail) > 255) {
        $detail = substr($detail, 0, 255);
    }

    $performedBy = hq_current_username();
    hq_ensure_alert_workflow_table($conn);
    hq_ensure_action_audit_table($conn);

    $stmt = $conn->prepare(
        "INSERT INTO hq_alert_workflow
            (alert_key, business_code, title, detail, status, updated_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            business_code = CASE WHEN VALUES(business_code) <> '' THEN VALUES(business_code) ELSE business_code END,
            title = CASE WHEN VALUES(title) <> '' THEN VALUES(title) ELSE title END,
            detail = CASE WHEN VALUES(detail) <> '' THEN VALUES(detail) ELSE detail END,
            status = VALUES(status),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->bind_param('ssssss', $alertKey, $businessCode, $title, $detail, $status, $performedBy);
    $stmt->execute();
    $stmt->close();

    $businessId = 0;
    if ($businessCode !== '') {
        $business = tenant_fetch_business_by_code($conn, $businessCode);
        if ($business) {
            $businessId = intval($business['id'] ?? 0);
        }
    }

    hq_action_log(
        $conn,
        $businessId,
        $businessCode,
        'set_alert_status',
        $performedBy,
        [
            'alert_key' => $alertKey,
            'status' => $status,
            'title' => $title
        ]
    );

    respond(true, 'Alert workflow status updated.', [
        'alert_key' => $alertKey,
        'status' => $status,
        'updated_by' => $performedBy,
        'updated_at' => gmdate('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    error_log('hq-alert-actions.php: ' . $e->getMessage());
    respond(false, 'Unable to update alert workflow right now.', [], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
