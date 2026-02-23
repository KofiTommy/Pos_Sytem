<?php
session_start();
header('Content-Type: application/json');
include 'db-connection.php';

try {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    if (empty($username) || empty($password)) {
        throw new Exception('Username and password are required');
    }
    
    // Get user from database
    $sql = "SELECT id, username, password, email, role FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        throw new Exception('Invalid username or password');
    }
    
    $user = $result->fetch_assoc();
    $role = strtolower(trim($user['role'] ?? ''));
    if ($role === 'admin') {
        $role = 'owner';
    }
    if (!in_array($role, ['owner', 'sales'], true)) {
        throw new Exception('Account role is not configured. Contact the owner.');
    }
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        throw new Exception('Invalid username or password');
    }
    
    // Set session
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $role;
    $_SESSION['is_admin'] = true;
    
    // Set cookie if remember me is checked
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
    
    $stmt->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
