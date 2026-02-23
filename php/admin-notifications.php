<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner', 'sales']);
include 'db-connection.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

try {
    $pendingOrders = 0;
    $newMessages = 0;

    $ordersStmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE status = 'pending'");
    $ordersStmt->execute();
    $ordersRow = $ordersStmt->get_result()->fetch_assoc();
    $ordersStmt->close();
    if ($ordersRow) {
        $pendingOrders = intval($ordersRow['total']);
    }

    $hasContactTable = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'contact_messages'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $hasContactTable = true;
        $messagesStmt = $conn->prepare("SELECT COUNT(*) AS total FROM contact_messages WHERE status = 'new'");
        $messagesStmt->execute();
        $messagesRow = $messagesStmt->get_result()->fetch_assoc();
        $messagesStmt->close();
        if ($messagesRow) {
            $newMessages = intval($messagesRow['total']);
        }
    }

    respond(true, '', [
        'notifications' => [
            'pending_orders' => $pendingOrders,
            'new_messages' => $newMessages,
            'total' => $pendingOrders + $newMessages
        ],
        'has_contact_messages_table' => $hasContactTable
    ]);
} catch (Exception $e) {
    http_response_code(500);
    respond(false, $e->getMessage());
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
