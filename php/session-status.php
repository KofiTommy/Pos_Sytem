<?php
include_once __DIR__ . '/session-bootstrap.php';
secure_session_start();
header('Content-Type: application/json');
$role = $_SESSION['role'] ?? null;
if ($role === 'admin') {
    $role = 'owner';
}

echo json_encode([
    'authenticated' => isset($_SESSION['user_id']) && intval($_SESSION['business_id'] ?? 0) > 0 && in_array($role, ['owner', 'sales'], true),
    'username' => $_SESSION['username'] ?? null,
    'role' => $role,
    'business_id' => intval($_SESSION['business_id'] ?? 0),
    'business_code' => $_SESSION['business_code'] ?? null,
    'business_name' => $_SESSION['business_name'] ?? null
]);
?>
