<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner', 'sales']);
include 'db-connection.php';
include 'tenant-context.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

try {
    ensure_multitenant_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        respond(false, 'Invalid business context. Please sign in again.');
    }

    $pendingOrders = 0;
    $newMessages = 0;

    $ordersStmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE business_id = ? AND status = 'pending'");
    $ordersStmt->bind_param('i', $businessId);
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
        $messagesStmt = $conn->prepare("SELECT COUNT(*) AS total FROM contact_messages WHERE business_id = ? AND status = 'new'");
        $messagesStmt->bind_param('i', $businessId);
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
    error_log('admin-notifications.php: ' . $e->getMessage());
    respond(false, 'Unable to load notifications right now.');
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
