<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner']);
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

    $tableCheck = $conn->query("SHOW TABLES LIKE 'contact_messages'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        respond(false, 'Contact messages table is not configured. Please run setup.');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        $body = [];
    }

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT id, name, email, phone, subject, message, admin_reply, status, created_at, updated_at
                                    FROM contact_messages
                                    WHERE id = ? AND business_id = ?");
            $stmt->bind_param('ii', $id, $businessId);
            $stmt->execute();
            $message = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$message) {
                respond(false, 'Message not found');
            }
            respond(true, '', ['message_item' => $message]);
        }

        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        if ($limit <= 0) $limit = 20;
        if ($limit > 200) $limit = 200;

        $allowedStatuses = ['new', 'read', 'replied', 'closed'];
        if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
            respond(false, 'Invalid status filter');
        }

        if ($status !== '') {
            $stmt = $conn->prepare("SELECT id, name, email, phone, subject, status, created_at, updated_at
                                    FROM contact_messages
                                    WHERE business_id = ? AND status = ?
                                    ORDER BY created_at DESC
                                    LIMIT ?");
            $stmt->bind_param('isi', $businessId, $status, $limit);
        } else {
            $stmt = $conn->prepare("SELECT id, name, email, phone, subject, status, created_at, updated_at
                                    FROM contact_messages
                                    WHERE business_id = ?
                                    ORDER BY created_at DESC
                                    LIMIT ?");
            $stmt->bind_param('ii', $businessId, $limit);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt->close();

        $countStmt = $conn->prepare("SELECT status, COUNT(*) AS total FROM contact_messages WHERE business_id = ? GROUP BY status");
        $countStmt->bind_param('i', $businessId);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $counts = ['new' => 0, 'read' => 0, 'replied' => 0, 'closed' => 0];
        while ($row = $countResult->fetch_assoc()) {
            $key = strtolower($row['status']);
            if (isset($counts[$key])) {
                $counts[$key] = intval($row['total']);
            }
        }
        $countStmt->close();

        respond(true, '', [
            'messages' => $messages,
            'counts' => $counts
        ]);
    }

    if ($method === 'PUT') {
        $id = isset($body['id']) ? intval($body['id']) : 0;
        $status = isset($body['status']) ? trim($body['status']) : '';
        $adminReply = isset($body['admin_reply']) ? trim($body['admin_reply']) : null;

        if ($id <= 0) {
            respond(false, 'Invalid message ID');
        }

        $allowedStatuses = ['new', 'read', 'replied', 'closed'];
        if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
            respond(false, 'Invalid status value');
        }

        $currentStmt = $conn->prepare("SELECT id, status, admin_reply FROM contact_messages WHERE id = ? AND business_id = ?");
        $currentStmt->bind_param('ii', $id, $businessId);
        $currentStmt->execute();
        $current = $currentStmt->get_result()->fetch_assoc();
        $currentStmt->close();
        if (!$current) {
            respond(false, 'Message not found');
        }

        $nextStatus = $status !== '' ? $status : $current['status'];
        $nextReply = $adminReply !== null ? $adminReply : $current['admin_reply'];

        $stmt = $conn->prepare("UPDATE contact_messages SET status = ?, admin_reply = ? WHERE id = ? AND business_id = ?");
        $stmt->bind_param('ssii', $nextStatus, $nextReply, $id, $businessId);
        $stmt->execute();
        $stmt->close();

        respond(true, 'Message updated successfully');
    }

    if ($method === 'DELETE') {
        $id = isset($body['id']) ? intval($body['id']) : 0;
        if ($id <= 0 && isset($_GET['id'])) {
            $id = intval($_GET['id']);
        }
        if ($id <= 0) {
            respond(false, 'Invalid message ID');
        }

        $stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ? AND business_id = ?");
        $stmt->bind_param('ii', $id, $businessId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected <= 0) {
            respond(false, 'Message not found');
        }

        respond(true, 'Message deleted successfully');
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
