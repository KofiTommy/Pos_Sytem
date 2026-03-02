<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

include 'db-connection.php';
include 'tenant-context.php';

const RESET_REQUEST_WINDOW_SECONDS = 900; // 15 minutes
const RESET_REQUEST_MAX_PER_KEY = 5;
const RESET_REQUEST_MAX_PER_IP = 20;
const RESET_TOKEN_TTL_MINUTES = 30;
const RESET_GENERIC_MESSAGE = 'If the account details are valid, a password reset link has been sent.';

function respond($success, $message = '', $extra = [], $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function detect_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    foreach ($candidates as $raw) {
        $parts = explode(',', (string)$raw);
        foreach ($parts as $part) {
            $ip = trim($part);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

function normalize_identifier($value): string {
    return strtolower(trim((string)$value));
}

function reset_attempt_key(string $businessCode, string $identifier): string {
    return hash('sha256', strtolower(trim($businessCode)) . '|' . normalize_identifier($identifier));
}

function ensure_reset_tables(mysqli $conn): void {
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

    $conn->query(
        "CREATE TABLE IF NOT EXISTS password_reset_attempts (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            attempt_key VARCHAR(128) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_reset_attempt_key_time (attempt_key, created_at),
            INDEX idx_password_reset_attempt_ip_time (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function prune_reset_attempts(mysqli $conn): void {
    $stmt = $conn->prepare(
        "DELETE FROM password_reset_attempts
         WHERE created_at < (NOW() - INTERVAL ? SECOND)"
    );
    $window = RESET_REQUEST_WINDOW_SECONDS;
    $stmt->bind_param('i', $window);
    $stmt->execute();
    $stmt->close();
}

function reset_attempt_count_for_key(mysqli $conn, string $attemptKey): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM password_reset_attempts
         WHERE attempt_key = ?
           AND created_at >= (NOW() - INTERVAL ? SECOND)"
    );
    $window = RESET_REQUEST_WINDOW_SECONDS;
    $stmt->bind_param('si', $attemptKey, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0);
}

function reset_attempt_count_for_ip(mysqli $conn, string $ipAddress): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM password_reset_attempts
         WHERE ip_address = ?
           AND created_at >= (NOW() - INTERVAL ? SECOND)"
    );
    $window = RESET_REQUEST_WINDOW_SECONDS;
    $stmt->bind_param('si', $ipAddress, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0);
}

function enforce_reset_rate_limit(mysqli $conn, string $attemptKey, string $ipAddress): void {
    prune_reset_attempts($conn);

    $perKey = reset_attempt_count_for_key($conn, $attemptKey);
    $perIp = reset_attempt_count_for_ip($conn, $ipAddress);

    if ($perKey >= RESET_REQUEST_MAX_PER_KEY || $perIp >= RESET_REQUEST_MAX_PER_IP) {
        throw new Exception('Too many reset attempts. Please wait 15 minutes and try again.', 429);
    }
}

function record_reset_attempt(mysqli $conn, string $attemptKey, string $ipAddress): void {
    $stmt = $conn->prepare(
        "INSERT INTO password_reset_attempts (attempt_key, ip_address)
         VALUES (?, ?)"
    );
    $stmt->bind_param('ss', $attemptKey, $ipAddress);
    $stmt->execute();
    $stmt->close();
}

function build_reset_link(string $businessCode, string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('#/php/[^/]+$#', '', $scriptName);
    $path = rtrim((string)$basePath, '/') . '/pages/forgot-password.html';
    $query = 'tenant=' . rawurlencode($businessCode) . '&token=' . rawurlencode($token);

    if ($host !== '') {
        return $scheme . '://' . $host . $path . '?' . $query;
    }

    return '../pages/forgot-password.html?' . $query;
}

function send_owner_reset_email(string $toEmail, string $username, string $businessName, string $resetLink): bool {
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'Owner Password Reset Request';
    $safeBusinessName = trim($businessName) !== '' ? $businessName : 'your business';
    $safeUsername = trim($username) !== '' ? $username : 'Owner';

    $messageLines = [
        'Hello ' . $safeUsername . ',',
        '',
        'A request was made to reset the owner password for ' . $safeBusinessName . '.',
        'If you made this request, open this link within ' . RESET_TOKEN_TTL_MINUTES . ' minutes:',
        $resetLink,
        '',
        'If you did not request this, you can safely ignore this email.'
    ];
    $message = implode("\r\n", $messageLines);

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: no-reply@localhost\r\n";

    return @mail($toEmail, $subject, $message, $headers);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(false, 'Method not allowed', [], 405);
    }

    ensure_multitenant_schema($conn);
    ensure_reset_tables($conn);

    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $businessCode = tenant_slugify_code(trim((string)($body['business_code'] ?? ($body['tenant'] ?? ''))));
    $identifier = trim((string)($body['identifier'] ?? ($body['email'] ?? '')));
    if ($businessCode === '' || $identifier === '') {
        respond(false, 'Business code and owner email/username are required.', [], 422);
    }

    $clientIp = detect_client_ip();
    $attemptKey = reset_attempt_key($businessCode, $identifier);
    enforce_reset_rate_limit($conn, $attemptKey, $clientIp);
    record_reset_attempt($conn, $attemptKey, $clientIp);

    $business = tenant_fetch_business_by_code($conn, $businessCode);
    if (!$business) {
        respond(true, RESET_GENERIC_MESSAGE, ['sent' => true]);
    }

    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        respond(true, RESET_GENERIC_MESSAGE, ['sent' => true]);
    }

    $normalized = normalize_identifier($identifier);
    $userStmt = $conn->prepare(
        "SELECT id, username, email, role
         FROM users
         WHERE business_id = ?
           AND (LOWER(email) = ? OR LOWER(username) = ?)
         ORDER BY id ASC
         LIMIT 1"
    );
    $userStmt->bind_param('iss', $businessId, $normalized, $normalized);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    $role = strtolower(trim((string)($user['role'] ?? '')));
    if ($role === 'admin') {
        $role = 'owner';
    }
    if (!$user || $role !== 'owner') {
        respond(true, RESET_GENERIC_MESSAGE, ['sent' => true]);
    }

    $userId = intval($user['id'] ?? 0);
    if ($userId <= 0) {
        respond(true, RESET_GENERIC_MESSAGE, ['sent' => true]);
    }

    $invalidateStmt = $conn->prepare(
        "UPDATE owner_password_reset_tokens
         SET used_at = NOW()
         WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()"
    );
    $invalidateStmt->bind_param('i', $userId);
    $invalidateStmt->execute();
    $invalidateStmt->close();

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTime('+' . RESET_TOKEN_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (strlen($userAgent) > 255) {
        $userAgent = substr($userAgent, 0, 255);
    }

    $insertStmt = $conn->prepare(
        "INSERT INTO owner_password_reset_tokens
            (business_id, user_id, token_hash, expires_at, request_ip, request_user_agent)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $insertStmt->bind_param('iissss', $businessId, $userId, $tokenHash, $expiresAt, $clientIp, $userAgent);
    $insertStmt->execute();
    $insertStmt->close();

    $resetLink = build_reset_link((string)($business['business_code'] ?? $businessCode), $token);
    $emailSent = send_owner_reset_email(
        (string)($user['email'] ?? ''),
        (string)($user['username'] ?? 'Owner'),
        (string)($business['business_name'] ?? ''),
        $resetLink
    );

    $extra = ['sent' => true];
    $appEnv = strtolower(trim((string)getenv('APP_ENV')));
    if ($appEnv !== 'production') {
        $extra['email_sent'] = $emailSent;
        $extra['reset_link'] = $resetLink;
    }

    respond(true, RESET_GENERIC_MESSAGE, $extra);
} catch (Exception $e) {
    $status = intval($e->getCode());
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status === 429) {
        respond(false, $e->getMessage(), [], 429);
    }

    error_log('owner-password-reset-request.php: ' . $e->getMessage());
    respond(false, 'Unable to process reset request right now.', [], $status);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
