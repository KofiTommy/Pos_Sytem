<?php
include_once __DIR__ . '/hq-auth.php';

hq_logout();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Logged out'
    ]);
    exit();
}

header('Location: ../pages/hq/login.php');
exit();
