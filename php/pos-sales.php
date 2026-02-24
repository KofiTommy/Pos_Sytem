<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner', 'sales']);
include 'db-connection.php';
include 'payment-schema.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

try {
    ensure_payment_schema($conn);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        $body = [];
    }

    if ($method === 'PUT') {
        $orderId = isset($body['order_id']) ? intval($body['order_id']) : 0;
        $customerName = isset($body['customer_name']) ? trim($body['customer_name']) : '';
        $status = isset($body['status']) ? trim($body['status']) : '';
        $notes = isset($body['notes']) ? trim($body['notes']) : '';

        if ($orderId <= 0) {
            respond(false, 'Invalid order ID');
        }
        if ($customerName === '') {
            respond(false, 'Customer name is required');
        }
        if (strlen($customerName) > 200) {
            respond(false, 'Customer name is too long');
        }

        $allowedStatuses = ['pending', 'paid', 'processing', 'completed', 'cancelled', 'refunded'];
        if (!in_array($status, $allowedStatuses, true)) {
            respond(false, 'Invalid status');
        }

        $stmt = $conn->prepare("UPDATE orders SET customer_name = ?, status = ?, notes = ? WHERE id = ?");
        $stmt->bind_param('sssi', $customerName, $status, $notes, $orderId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            $checkStmt = $conn->prepare("SELECT id FROM orders WHERE id = ?");
            $checkStmt->bind_param('i', $orderId);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            if (!$exists) {
                respond(false, 'Sale not found');
            }
        }

        respond(true, 'Sale updated successfully');
    }

    if ($method === 'DELETE') {
        if (current_user_role() !== 'owner') {
            respond(false, 'Only owner can delete sales records');
        }

        $orderId = isset($body['order_id']) ? intval($body['order_id']) : 0;
        if ($orderId <= 0 && isset($_GET['order_id'])) {
            $orderId = intval($_GET['order_id']);
        }
        if ($orderId <= 0) {
            respond(false, 'Invalid order ID');
        }

        $conn->begin_transaction();
        try {
            $existsStmt = $conn->prepare("SELECT id FROM orders WHERE id = ? FOR UPDATE");
            $existsStmt->bind_param('i', $orderId);
            $existsStmt->execute();
            $exists = $existsStmt->get_result()->fetch_assoc();
            $existsStmt->close();
            if (!$exists) {
                throw new Exception('Sale not found');
            }

            $itemsStmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $itemsStmt->bind_param('i', $orderId);
            $itemsStmt->execute();
            $itemsResult = $itemsStmt->get_result();
            $items = [];
            while ($row = $itemsResult->fetch_assoc()) {
                $items[] = $row;
            }
            $itemsStmt->close();

            $restoreStmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            foreach ($items as $item) {
                $productId = intval($item['product_id'] ?? 0);
                $quantity = intval($item['quantity'] ?? 0);
                if ($productId > 0 && $quantity > 0) {
                    $restoreStmt->bind_param('ii', $quantity, $productId);
                    $restoreStmt->execute();
                }
            }
            $restoreStmt->close();

            $deleteStmt = $conn->prepare("DELETE FROM orders WHERE id = ?");
            $deleteStmt->bind_param('i', $orderId);
            $deleteStmt->execute();
            $deleted = $deleteStmt->affected_rows;
            $deleteStmt->close();

            if ($deleted <= 0) {
                throw new Exception('Failed to delete sale');
            }

            $conn->commit();
            respond(true, 'Sale deleted and stock restored');
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    $orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

    if ($orderId > 0) {
        $orderStmt = $conn->prepare("SELECT id, customer_name, subtotal, tax, shipping, total, status, payment_method, payment_status, payment_reference, notes, created_at
                                     FROM orders
                                     WHERE id = ?");
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        $order = $orderResult->fetch_assoc();
        $orderStmt->close();

        if (!$order) {
            throw new Exception('Sale not found');
        }

        $itemStmt = $conn->prepare("SELECT product_id, product_name, quantity, price
                                    FROM order_items
                                    WHERE order_id = ?");
        $itemStmt->bind_param('i', $orderId);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        $items = [];
        while ($row = $itemResult->fetch_assoc()) {
            $items[] = $row;
        }
        $itemStmt->close();

        respond(true, '', [
            'order' => $order,
            'items' => $items
        ]);
    }

    $date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    if ($limit <= 0) {
        $limit = 100;
    }
    if ($limit > 500) {
        $limit = 500;
    }

        $stmt = $conn->prepare("SELECT o.id, o.customer_name, o.total, o.status, o.payment_method, o.payment_status, o.payment_reference, o.created_at,
                                       COUNT(oi.id) AS item_count
                                FROM orders o
                                LEFT JOIN order_items oi ON oi.order_id = o.id
                                WHERE DATE(o.created_at) = ?
                                GROUP BY o.id, o.customer_name, o.total, o.status, o.payment_method, o.payment_status, o.payment_reference, o.created_at
                                ORDER BY o.id ASC
                                LIMIT ?");
    $stmt->bind_param('si', $date, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $sales = [];
    while ($row = $result->fetch_assoc()) {
        $sales[] = $row;
    }
    $stmt->close();

    $sumStmt = $conn->prepare("SELECT COUNT(*) AS orders_count, COALESCE(SUM(total), 0) AS gross_total
                               FROM orders
                               WHERE DATE(created_at) = ?");
    $sumStmt->bind_param('s', $date);
    $sumStmt->execute();
    $summaryResult = $sumStmt->get_result();
    $summary = $summaryResult->fetch_assoc();
    $sumStmt->close();

    respond(true, '', [
        'date' => $date,
        'sales' => $sales,
        'summary' => $summary
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
