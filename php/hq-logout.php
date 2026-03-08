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

$target = redirect_resolve_allowlisted('../pages/hq/login.php', hq_redirect_allowlist(), '../pages/hq/login.php');
header('Location: ' . $target);
exit();
