<?php
header('Content-Type: application/json');
include_once __DIR__ . '/hq-auth.php';

const HQ_LOGIN_RATE_LIMIT_WINDOW_SECONDS = 900; // 15 minutes
const HQ_LOGIN_RATE_LIMIT_MAX_PER_KEY = 8;
const HQ_LOGIN_RATE_LIMIT_MAX_PER_IP = 40;
const HQ_LOGIN_GENERIC_FAILURE_MESSAGE = 'Invalid HQ credentials.';

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

function hq_login_attempt_key(string $username): string {
    return hash('sha256', strtolower(trim($username)));
}

function hq_ensure_login_attempts_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS hq_login_attempts (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            attempt_key VARCHAR(128) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hq_login_attempt_key_time (attempt_key, created_at),
            INDEX idx_hq_login_attempt_ip_time (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function hq_prune_login_attempts(mysqli $conn): void {
    $stmt = $conn->prepare(
        "DELETE FROM hq_login_attempts
         WHERE created_at < (NOW() - INTERVAL ? SECOND)"
    );
    $window = HQ_LOGIN_RATE_LIMIT_WINDOW_SECONDS;
    $stmt->bind_param('i', $window);
    $stmt->execute();
    $stmt->close();
}

function hq_login_attempt_count_for_key(mysqli $conn, string $attemptKey): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM hq_login_attempts
         WHERE attempt_key = ?
           AND created_at >= (NOW() - INTERVAL ? SECOND)"
    );
    $window = HQ_LOGIN_RATE_LIMIT_WINDOW_SECONDS;
    $stmt->bind_param('si', $attemptKey, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0);
}

function hq_login_attempt_count_for_ip(mysqli $conn, string $ipAddress): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM hq_login_attempts
         WHERE ip_address = ?
           AND created_at >= (NOW() - INTERVAL ? SECOND)"
    );
    $window = HQ_LOGIN_RATE_LIMIT_WINDOW_SECONDS;
    $stmt->bind_param('si', $ipAddress, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0);
}

function hq_enforce_login_rate_limit(mysqli $conn, string $attemptKey, string $ipAddress): void {
    hq_prune_login_attempts($conn);

    $perKey = hq_login_attempt_count_for_key($conn, $attemptKey);
    $perIp = hq_login_attempt_count_for_ip($conn, $ipAddress);

    if ($perKey >= HQ_LOGIN_RATE_LIMIT_MAX_PER_KEY || $perIp >= HQ_LOGIN_RATE_LIMIT_MAX_PER_IP) {
        throw new Exception('Too many HQ login attempts. Please wait 15 minutes and try again.', 429);
    }
}

function hq_record_failed_login_attempt(mysqli $conn, string $attemptKey, string $ipAddress): void {
    $stmt = $conn->prepare(
        "INSERT INTO hq_login_attempts (attempt_key, ip_address)
         VALUES (?, ?)"
    );
    $stmt->bind_param('ss', $attemptKey, $ipAddress);
    $stmt->execute();
    $stmt->close();
}

function hq_clear_login_attempts(mysqli $conn, string $attemptKey, string $ipAddress): void {
    $stmt = $conn->prepare(
        "DELETE FROM hq_login_attempts
         WHERE attempt_key = ? OR ip_address = ?"
    );
    $stmt->bind_param('ss', $attemptKey, $ipAddress);
    $stmt->execute();
    $stmt->close();
}

try {
    $attemptKey = '';
    $clientIp = hq_client_ip();
    $shouldRecordFailure = false;

    if (!hq_is_enabled()) {
        respond(false, 'HQ dashboard is not configured on this environment.', [], 503);
    }

    if (!hq_ip_is_allowed()) {
        respond(false, 'Forbidden', [], 403);
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(false, 'Method not allowed', [], 405);
    }

    if (!hq_is_same_origin_write_request()) {
        respond(false, 'Forbidden', [], 403);
    }

    include __DIR__ . '/db-connection.php';
    hq_ensure_login_attempts_table($conn);

    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($username === '' || $password === '') {
        respond(false, 'Username and password are required.', [], 422);
    }

    $attemptKey = hq_login_attempt_key($username);
    hq_enforce_login_rate_limit($conn, $attemptKey, $clientIp);
    $shouldRecordFailure = true;

    if (!hq_authenticate($username, $password)) {
        usleep(250000); // slow down online password guessing
        throw new Exception(HQ_LOGIN_GENERIC_FAILURE_MESSAGE, 401);
    }

    hq_mark_authenticated(hq_configured_username());
    hq_clear_login_attempts($conn, $attemptKey, $clientIp);
    $shouldRecordFailure = false;

    respond(true, 'HQ login successful.', [
        'username' => hq_current_username(),
        'redirect' => 'dashboard.php'
    ]);
} catch (Exception $e) {
    if (!empty($shouldRecordFailure) && isset($conn) && $conn instanceof mysqli && $attemptKey !== '') {
        try {
            hq_record_failed_login_attempt($conn, $attemptKey, $clientIp);
        } catch (Exception $recordError) {
            error_log('hq-login.php record failure: ' . $recordError->getMessage());
        }
    }

    $status = intval($e->getCode());
    if (!in_array($status, [401, 403, 405, 422, 429, 503], true)) {
        $status = 500;
    }

    if ($status === 500) {
        error_log('hq-login.php: ' . $e->getMessage());
    }

    $message = $e->getMessage();
    if ($status === 401) {
        $message = HQ_LOGIN_GENERIC_FAILURE_MESSAGE;
    } elseif ($status === 500) {
        $message = 'Unable to process HQ login right now.';
    }

    respond(false, $message, [], $status);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
