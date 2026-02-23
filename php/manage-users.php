<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner']);
include 'db-connection.php';

function respond($success, $message = '', $extra = []) {
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
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        $body = [];
    }

    if ($method === 'GET') {
        $stmt = $conn->prepare("SELECT id, username, email, role, created_at FROM users ORDER BY created_at DESC");
        $stmt->execute();
        $result = $stmt->get_result();
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();

        respond(true, '', ['users' => $users]);
    }

    if ($method === 'POST') {
        $username = trim($body['username'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = (string)($body['password'] ?? '');
        $role = strtolower(trim($body['role'] ?? 'sales'));

        if ($username === '' || $email === '' || $password === '') {
            respond(false, 'Username, email, and password are required');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            respond(false, 'Username must be 3-60 chars and use letters, numbers, dot, underscore, or dash');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
            respond(false, 'Invalid email address');
        }
        if (!validate_password_strength($password)) {
            respond(false, 'Password must be at least 10 chars and include uppercase, lowercase, number, and symbol');
        }
        if ($role !== 'sales') {
            respond(false, 'Owner can only create sales accounts here');
        }

        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $checkStmt->bind_param('ss', $username, $email);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        if ($exists) {
            respond(false, 'Username or email already exists');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'sales')");
        $stmt->bind_param('sss', $username, $email, $passwordHash);
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();

        respond(true, 'Sales account created successfully', ['user_id' => $newId]);
    }

    if ($method === 'PUT') {
        $action = strtolower(trim($body['action'] ?? ''));
        $ownerId = intval($_SESSION['user_id'] ?? 0);

        if ($action === 'change_own_password') {
            $currentPassword = (string)($body['current_password'] ?? '');
            $newPassword = (string)($body['new_password'] ?? '');

            if ($ownerId <= 0) {
                respond(false, 'Invalid session');
            }
            if ($currentPassword === '' || $newPassword === '') {
                respond(false, 'Current and new password are required');
            }
            if (!validate_password_strength($newPassword)) {
                respond(false, 'New password must be at least 10 chars and include uppercase, lowercase, number, and symbol');
            }

            $ownerStmt = $conn->prepare("SELECT id, password, role FROM users WHERE id = ? LIMIT 1");
            $ownerStmt->bind_param('i', $ownerId);
            $ownerStmt->execute();
            $owner = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();

            $ownerRole = strtolower(trim($owner['role'] ?? ''));
            if ($ownerRole === 'admin') {
                $ownerRole = 'owner';
            }
            if (!$owner || $ownerRole !== 'owner') {
                respond(false, 'Owner account not found');
            }
            if (!password_verify($currentPassword, $owner['password'])) {
                respond(false, 'Current password is incorrect');
            }
            if (password_verify($newPassword, $owner['password'])) {
                respond(false, 'New password must be different from current password');
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->bind_param('si', $newHash, $ownerId);
            $updateStmt->execute();
            $updateStmt->close();

            respond(true, 'Password changed successfully');
        }

        if ($action === 'reset_staff_password') {
            $userId = intval($body['user_id'] ?? 0);
            $newPassword = (string)($body['new_password'] ?? '');

            if ($userId <= 0) {
                respond(false, 'Invalid user ID');
            }
            if (!validate_password_strength($newPassword)) {
                respond(false, 'New password must be at least 10 chars and include uppercase, lowercase, number, and symbol');
            }

            $userStmt = $conn->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $target = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();

            if (!$target) {
                respond(false, 'User not found');
            }
            if (strtolower($target['role'] ?? '') !== 'sales') {
                respond(false, 'Only staff (sales) password can be reset');
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $resetStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $resetStmt->bind_param('si', $newHash, $userId);
            $resetStmt->execute();
            $resetStmt->close();

            respond(true, 'Staff password reset successfully');
        }

        respond(false, 'Invalid action');
    }

    if ($method === 'DELETE') {
        $userId = intval($body['user_id'] ?? 0);
        if ($userId <= 0) {
            respond(false, 'Invalid user ID');
        }

        if (intval($_SESSION['user_id'] ?? 0) === $userId) {
            respond(false, 'You cannot delete your own owner account');
        }

        $roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $roleStmt->bind_param('i', $userId);
        $roleStmt->execute();
        $user = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();

        if (!$user) {
            respond(false, 'User not found');
        }
        if (strtolower($user['role']) !== 'sales') {
            respond(false, 'Only sales accounts can be deleted from this page');
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        if ($deleted <= 0) {
            respond(false, 'Failed to delete account');
        }

        respond(true, 'Sales account deleted successfully');
    }

    http_response_code(405);
    respond(false, 'Method not allowed');
} catch (Exception $e) {
    http_response_code(500);
    respond(false, $e->getMessage());
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
