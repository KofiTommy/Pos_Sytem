<?php
header('Content-Type: application/json');
include_once __DIR__ . '/hq-auth.php';
hq_require_api();
include __DIR__ . '/db-connection.php';
include __DIR__ . '/tenant-context.php';

const HQ_OWNER_RESET_TTL_MINUTES = 30;

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

function hq_action_client_ip(): string {
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }
    return '0.0.0.0';
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

function hq_fetch_business_for_update(mysqli $conn, int $businessId): ?array {
    $stmt = $conn->prepare(
        "SELECT id, business_code, business_name, status, subscription_plan
         FROM businesses
         WHERE id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

function hq_ensure_owner_reset_tokens_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS owner_password_reset_tokens (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            request_ip VARCHAR(45) NOT NULL DEFAULT '',
            request_user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_owner_reset_token_hash (token_hash),
            INDEX idx_owner_reset_user (user_id, created_at),
            INDEX idx_owner_reset_business (business_id, created_at),
            INDEX idx_owner_reset_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function hq_build_owner_reset_link(string $businessCode, string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('#/php/[^/]+$#', '', $scriptName);
    if (!is_string($basePath) || $basePath === '' || strpos($basePath, '/') !== 0) {
        $basePath = '/possystem';
    }
    $path = rtrim((string)$basePath, '/') . '/pages/forgot-password.html';
    $query = 'tenant=' . rawurlencode($businessCode) . '&token=' . rawurlencode($token);

    if ($host !== '') {
        return $scheme . '://' . $host . $path . '?' . $query;
    }
    return '../pages/forgot-password.html?' . $query;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(false, 'Method not allowed.', [], 405);
    }

    if (!hq_actions_enabled()) {
        respond(false, 'HQ actions are disabled. Set HQ_ACTIONS_ENABLED=true to enable controlled actions.', [], 403);
    }

    $requiredTables = ['businesses', 'users'];
    foreach ($requiredTables as $requiredTable) {
        if (!tenant_table_exists($conn, $requiredTable)) {
            respond(false, 'Required table is missing: ' . $requiredTable, [], 503);
        }
    }

    hq_ensure_action_audit_table($conn);

    $rawBody = file_get_contents('php://input');
    $body = json_decode((string)$rawBody, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $action = strtolower(trim((string)($body['action'] ?? '')));
    $businessId = intval($body['business_id'] ?? 0);
    $performedBy = hq_current_username();

    if ($businessId <= 0) {
        respond(false, 'Valid business_id is required.', [], 422);
    }

    if ($action === 'set_business_status') {
        $targetStatus = strtolower(trim((string)($body['status'] ?? '')));
        if (!in_array($targetStatus, ['active', 'suspended'], true)) {
            respond(false, 'Status must be active or suspended.', [], 422);
        }

        $conn->begin_transaction();
        try {
            $business = hq_fetch_business_for_update($conn, $businessId);
            if (!$business) {
                throw new Exception('Business not found.', 404);
            }

            $currentStatus = strtolower(trim((string)($business['status'] ?? 'active')));
            if ($currentStatus !== $targetStatus) {
                $updateStmt = $conn->prepare("UPDATE businesses SET status = ? WHERE id = ?");
                $updateStmt->bind_param('si', $targetStatus, $businessId);
                $updateStmt->execute();
                $updateStmt->close();
            }

            hq_action_log(
                $conn,
                intval($business['id'] ?? 0),
                (string)($business['business_code'] ?? ''),
                'set_business_status',
                $performedBy,
                [
                    'previous_status' => $currentStatus,
                    'new_status' => $targetStatus
                ]
            );

            $conn->commit();

            $updated = tenant_fetch_business_by_id($conn, $businessId) ?: $business;
            respond(true, 'Business status updated.', [
                'action' => $action,
                'business' => [
                    'id' => intval($updated['id'] ?? 0),
                    'business_code' => (string)($updated['business_code'] ?? ''),
                    'business_name' => (string)($updated['business_name'] ?? ''),
                    'status' => (string)($updated['status'] ?? '')
                ]
            ]);
        } catch (Exception $inner) {
            $conn->rollback();
            throw $inner;
        }
    }

    if ($action === 'issue_owner_reset_link') {
        hq_ensure_owner_reset_tokens_table($conn);

        $conn->begin_transaction();
        try {
            $business = hq_fetch_business_for_update($conn, $businessId);
            if (!$business) {
                throw new Exception('Business not found.', 404);
            }

            $ownerStmt = $conn->prepare(
                "SELECT id, username, email
                 FROM users
                 WHERE business_id = ?
                   AND LOWER(CASE WHEN role = 'admin' THEN 'owner' ELSE role END) = 'owner'
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $ownerStmt->bind_param('i', $businessId);
            $ownerStmt->execute();
            $owner = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();

            if (!$owner) {
                throw new Exception('Owner account not found for this business.', 404);
            }

            $ownerId = intval($owner['id'] ?? 0);
            if ($ownerId <= 0) {
                throw new Exception('Owner account is invalid.', 500);
            }

            $invalidateStmt = $conn->prepare(
                "UPDATE owner_password_reset_tokens
                 SET used_at = NOW()
                 WHERE user_id = ? AND business_id = ? AND used_at IS NULL AND expires_at > NOW()"
            );
            $invalidateStmt->bind_param('ii', $ownerId, $businessId);
            $invalidateStmt->execute();
            $invalidateStmt->close();

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = (new DateTime('+' . HQ_OWNER_RESET_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
            $requestIp = hq_action_client_ip();
            $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            if (strlen($userAgent) > 255) {
                $userAgent = substr($userAgent, 0, 255);
            }

            $insertStmt = $conn->prepare(
                "INSERT INTO owner_password_reset_tokens
                    (business_id, user_id, token_hash, expires_at, request_ip, request_user_agent)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $insertStmt->bind_param('iissss', $businessId, $ownerId, $tokenHash, $expiresAt, $requestIp, $userAgent);
            $insertStmt->execute();
            $insertStmt->close();

            $businessCode = (string)($business['business_code'] ?? '');
            $resetLink = hq_build_owner_reset_link($businessCode, $token);

            hq_action_log(
                $conn,
                intval($business['id'] ?? 0),
                $businessCode,
                'issue_owner_reset_link',
                $performedBy,
                [
                    'owner_user_id' => $ownerId,
                    'owner_username' => (string)($owner['username'] ?? ''),
                    'expires_at' => $expiresAt
                ]
            );

            $conn->commit();

            respond(true, 'Owner reset link issued.', [
                'action' => $action,
                'business' => [
                    'id' => intval($business['id'] ?? 0),
                    'business_code' => $businessCode,
                    'business_name' => (string)($business['business_name'] ?? '')
                ],
                'owner' => [
                    'id' => $ownerId,
                    'username' => (string)($owner['username'] ?? ''),
                    'email' => (string)($owner['email'] ?? '')
                ],
                'reset_link' => $resetLink,
                'expires_at' => $expiresAt
            ]);
        } catch (Exception $inner) {
            $conn->rollback();
            throw $inner;
        }
    }

    respond(false, 'Invalid action.', [], 422);
} catch (Exception $e) {
    $status = intval($e->getCode());
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    if ($status >= 500) {
        error_log('hq-actions.php: ' . $e->getMessage());
        respond(false, 'Unable to process HQ action right now.', [], $status);
    }
    respond(false, $e->getMessage(), [], $status);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
