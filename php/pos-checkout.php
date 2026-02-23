<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner', 'sales']);
include 'db-connection.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $customerName = isset($payload['customer_name']) ? trim($payload['customer_name']) : 'Walk-in Customer';
    if ($customerName === '') {
        $customerName = 'Walk-in Customer';
    }

    $customerEmail = isset($payload['customer_email']) ? trim($payload['customer_email']) : 'pos@mothercare.local';
    $customerPhone = isset($payload['customer_phone']) ? trim($payload['customer_phone']) : 'N/A';
    $notes = isset($payload['notes']) ? trim($payload['notes']) : '';
    $paymentMethod = isset($payload['payment_method']) ? trim($payload['payment_method']) : 'cash';
    $cashReceived = isset($payload['cash_received']) ? floatval($payload['cash_received']) : 0;
    $taxRate = isset($payload['tax_rate']) ? floatval($payload['tax_rate']) : 10;
    $discountAmount = isset($payload['discount_amount']) ? floatval($payload['discount_amount']) : 0;
    $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

    if (count($items) === 0) {
        throw new Exception('No items in sale');
    }
    if ($taxRate < 0 || $taxRate > 100) {
        throw new Exception('Tax rate must be between 0 and 100');
    }
    if ($discountAmount < 0) {
        throw new Exception('Discount must be 0 or greater');
    }

    $conn->begin_transaction();
    try {
        $subtotal = 0.0;
        $validatedItems = [];

        $productStmt = $conn->prepare("SELECT id, name, price, stock FROM products WHERE id = ? FOR UPDATE");
        foreach ($items as $item) {
            $productId = isset($item['id']) ? intval($item['id']) : 0;
            $quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;

            if ($productId <= 0 || $quantity <= 0) {
                throw new Exception('Invalid cart item');
            }

            $productStmt->bind_param('i', $productId);
            $productStmt->execute();
            $productResult = $productStmt->get_result();
            $product = $productResult->fetch_assoc();

            if (!$product) {
                throw new Exception('Product not found: ID ' . $productId);
            }

            if (intval($product['stock']) < $quantity) {
                throw new Exception('Insufficient stock for ' . $product['name']);
            }

            $lineTotal = floatval($product['price']) * $quantity;
            $subtotal += $lineTotal;

            $validatedItems[] = [
                'id' => intval($product['id']),
                'name' => $product['name'],
                'price' => floatval($product['price']),
                'quantity' => $quantity,
                'line_total' => $lineTotal
            ];
        }
        $productStmt->close();

        $discount = round(min($discountAmount, $subtotal), 2);
        $taxableSubtotal = max($subtotal - $discount, 0);
        $tax = round($taxableSubtotal * ($taxRate / 100), 2);
        $shipping = 0.0;
        $total = round($taxableSubtotal + $tax + $shipping, 2);

        if (strtolower($paymentMethod) === 'cash' && $cashReceived > 0 && $cashReceived < $total) {
            throw new Exception('Cash received is less than total');
        }

        $fullNotes = trim(
            'POS Sale | Payment: ' . $paymentMethod .
            ' | Tax Rate: ' . number_format($taxRate, 2, '.', '') . '%' .
            ' | Discount: ' . number_format($discount, 2, '.', '') .
            ($notes !== '' ? ' | ' . $notes : '')
        );

        $orderStmt = $conn->prepare("INSERT INTO orders
            (customer_name, customer_email, customer_phone, address, city, postal_code, subtotal, tax, shipping, total, notes, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', NOW())");

        $address = 'In-store POS';
        $city = 'N/A';
        $postalCode = 'N/A';
        $orderStmt->bind_param(
            'ssssssdddds',
            $customerName,
            $customerEmail,
            $customerPhone,
            $address,
            $city,
            $postalCode,
            $subtotal,
            $tax,
            $shipping,
            $total,
            $fullNotes
        );
        $orderStmt->execute();
        $orderId = $conn->insert_id;
        $orderStmt->close();

        $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
        $stockStmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");

        foreach ($validatedItems as $item) {
            $itemStmt->bind_param('iisid', $orderId, $item['id'], $item['name'], $item['quantity'], $item['price']);
            $itemStmt->execute();

            $stockStmt->bind_param('ii', $item['quantity'], $item['id']);
            $stockStmt->execute();
        }

        $itemStmt->close();
        $stockStmt->close();

        $conn->commit();

        echo json_encode([
            'success' => true,
            'order_id' => $orderId,
            'summary' => [
                'customer_name' => $customerName,
                'payment_method' => $paymentMethod,
                'tax_rate' => $taxRate,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'taxable_subtotal' => $taxableSubtotal,
                'tax' => $tax,
                'total' => $total,
                'cash_received' => $cashReceived,
                'change_due' => ($cashReceived > 0 ? round($cashReceived - $total, 2) : 0),
                'items' => $validatedItems
            ]
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
