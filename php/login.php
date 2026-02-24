<?php
session_start();
header('Content-Type: application/json');

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

    echo json_encode([
        'success' => false,
        'message' => 'Fatal server error during login: ' . ($error['message'] ?? 'Unknown error')
    ]);
});

try {
    include 'db-connection.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

    if ($username === '' || $password === '') {
        throw new Exception('Username and password are required');
    }

    $stmt = $conn->prepare("SELECT id, username, password, email, role FROM users WHERE username = ?");
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }

    $stmt->bind_param('s', $username);
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

    if (isset($_POST['remember'])) {
        setcookie('username', $username, [
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
        'redirect' => $role === 'owner' ? '../pages/admin/dashboard.php' : '../pages/admin/pos.php'
    ]);
} catch (Exception $e) {
    if (!headers_sent()) {
        http_response_code(400);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
