<?php
include_once __DIR__ . '/session-bootstrap.php';
secure_session_start();
header('Content-Type: application/json');

const LOGIN_RATE_LIMIT_WINDOW_SECONDS = 900; // 15 minutes
const LOGIN_RATE_LIMIT_MAX_PER_KEY = 8;
const LOGIN_RATE_LIMIT_MAX_PER_IP = 40;
const LOGIN_GENERIC_FAILURE_MESSAGE = 'Invalid username or password';

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    error_log('login.php fatal: ' . ($error['message'] ?? 'Unknown error'));

    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Please try again later.'
    ]);
});

function login_client_ip(): string {
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

function login_attempt_key(string $username, string $businessCode): string {
    $normalizedUser = strtolower(trim($username));
    $normalizedBusiness = strtolower(trim($businessCode));
    return hash('sha256', $normalizedBusiness . '|' . $normalizedUser);
}

function ensure_login_attempts_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            attempt_key VARCHAR(128) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempts_key_time (attempt_key, created_at),
            INDEX idx_login_attempts_ip_time (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function prune_login_attempts(mysqli $conn): void {
    $stmt = $conn->prepare(
        "DELETE FROM login_attempts
         WHERE created_at < (NOW() - INTERVAL ? SECOND)"
    );
    $window = LOGIN_RATE_LIMIT_WINDOW_SECONDS;
    $stmt->bind_param('i', $window);
    $stmt->execute();
    $stmt->close();
}

function login_attempt_count_for_key(mysqli $conn, string $attemptKey): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM login_attempts
         WHERE attempt_key = ?
           AND created_at >= (NOW() - INTERVAL ? SECOND)"
    );
    $window = LOGIN_RATE_LIMIT_WINDOW_SECONDS;
    $stmt->bind_param('si', $attemptKey, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0);
}

function login_attempt_count_for_ip(mysqli $conn, string $ipAddress): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM login_attempts
         WHERE ip_address = ?
           AND created_at >= (NOW() - INTERVAL ? SECOND)"
    );
    $window = LOGIN_RATE_LIMIT_WINDOW_SECONDS;
    $stmt->bind_param('si', $ipAddress, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0);
}

function enforce_login_rate_limit(mysqli $conn, string $attemptKey, string $ipAddress): void {
    prune_login_attempts($conn);

    $perKey = login_attempt_count_for_key($conn, $attemptKey);
    $perIp = login_attempt_count_for_ip($conn, $ipAddress);

    if ($perKey >= LOGIN_RATE_LIMIT_MAX_PER_KEY || $perIp >= LOGIN_RATE_LIMIT_MAX_PER_IP) {
        throw new Exception('Too many login attempts. Please wait 15 minutes and try again.', 429);
    }
}

function record_failed_login_attempt(mysqli $conn, string $attemptKey, string $ipAddress): void {
    $stmt = $conn->prepare(
        "INSERT INTO login_attempts (attempt_key, ip_address)
         VALUES (?, ?)"
    );
    $stmt->bind_param('ss', $attemptKey, $ipAddress);
    $stmt->execute();
    $stmt->close();
}

function clear_login_attempts(mysqli $conn, string $attemptKey, string $ipAddress): void {
    $stmt = $conn->prepare(
        "DELETE FROM login_attempts
         WHERE attempt_key = ? OR ip_address = ?"
    );
    $stmt->bind_param('ss', $attemptKey, $ipAddress);
    $stmt->execute();
    $stmt->close();
}

try {
    $attemptKey = '';
    $clientIp = login_client_ip();
    $shouldRecordFailure = false;

    include 'db-connection.php';
    include 'tenant-context.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $businessCode = isset($_POST['business_code']) ? trim((string)$_POST['business_code']) : '';

    if ($username === '' || $password === '') {
        throw new Exception('Username and password are required', 422);
    }

    ensure_login_attempts_table($conn);
    $attemptKey = login_attempt_key($username, $businessCode);
    enforce_login_rate_limit($conn, $attemptKey, $clientIp);
    $shouldRecordFailure = true;

    $business = tenant_require_business_context(
        $conn,
        ['business_code' => $businessCode],
        $businessCode === ''
    );
    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        throw new Exception('Invalid business context');
    }

    $stmt = $conn->prepare(
        "SELECT id, username, password, email, role
         FROM users
         WHERE business_id = ? AND (username = ? OR email = ?)
         LIMIT 1"
    );
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }

    $stmt->bind_param('iss', $businessId, $username, $username);
    $stmt->execute();

    $user = null;
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
        }
    } else {
        $stmt->bind_result($userId, $dbUsername, $dbPassword, $dbEmail, $dbRole);
        if ($stmt->fetch()) {
            $user = [
                'id' => $userId,
                'username' => $dbUsername,
                'password' => $dbPassword,
                'email' => $dbEmail,
                'role' => $dbRole
            ];
        }
    }
    $stmt->close();

    if (!$user) {
        throw new Exception('Invalid username or password');
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    if ($role === 'admin') {
        $role = 'owner';
    }
    if (!in_array($role, ['owner', 'sales'], true)) {
        throw new Exception('Account role is not configured. Contact the owner.');
    }

    if (!password_verify($password, (string)$user['password'])) {
        throw new Exception('Invalid username or password');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $role;
    $_SESSION['is_admin'] = true;
    tenant_set_session_context($business);
    clear_login_attempts($conn, $attemptKey, $clientIp);
    $shouldRecordFailure = false;

    if (isset($_POST['remember'])) {
        setcookie('username', (string)$user['username'], [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'role' => $role,
        'business_code' => (string)($business['business_code'] ?? ''),
        'business_name' => (string)($business['business_name'] ?? ''),
        'redirect' => $role === 'owner' ? '../pages/admin/dashboard.php' : '../pages/admin/pos.php'
    ]);
} catch (Exception $e) {
    if (!empty($shouldRecordFailure) && isset($conn) && $conn instanceof mysqli && $attemptKey !== '') {
        try {
            record_failed_login_attempt($conn, $attemptKey, $clientIp);
        } catch (Exception $recordError) {
            error_log('login.php record failure error: ' . $recordError->getMessage());
        }
    }

    $status = 400;
    $message = LOGIN_GENERIC_FAILURE_MESSAGE;
    $rawMessage = $e->getMessage();
    if (intval($e->getCode()) === 429) {
        $status = 429;
        $message = $rawMessage;
    } elseif (intval($e->getCode()) === 422) {
        $status = 422;
        $message = 'Username and password are required';
    } elseif (stripos($rawMessage, 'Invalid request method') !== false) {
        $status = 405;
        $message = 'Method not allowed';
    }

    error_log('login.php: ' . $rawMessage);

    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
