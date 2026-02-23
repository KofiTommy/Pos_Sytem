<?php
session_start();
header('Content-Type: application/json');
$role = $_SESSION['role'] ?? null;
if ($role === 'admin') {
    $role = 'owner';
}

echo json_encode([
    'authenticated' => isset($_SESSION['user_id']) && in_array($role, ['owner', 'sales'], true),
    'username' => $_SESSION['username'] ?? null,
    'role' => $role
]);
?>
