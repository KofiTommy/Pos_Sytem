<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner', 'sales']);
include 'db-connection.php';
include 'payment-schema.php';
include 'staff-tracking-schema.php';
include 'tenant-context.php';

function clean_text_input($value, $maxLen = 255) {
    $text = trim(strip_tags((string)$value));
    if (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen);
    }
    return $text;
}

try {
    ensure_payment_schema($conn);
    ensure_staff_tracking_schema($conn);
    ensure_multitenant_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        throw new Exception('Invalid business context. Please sign in again.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $customerName = clean_text_input($payload['customer_name'] ?? 'Walk-in Customer', 200);
    if ($customerName === '') {
        $customerName = 'Walk-in Customer';
    }

    $customerEmail = clean_text_input($payload['customer_email'] ?? 'pos@mothercare.local', 160);
    $customerPhone = clean_text_input($payload['customer_phone'] ?? 'N/A', 40);
    $notes = clean_text_input($payload['notes'] ?? '', 1000);
    $paymentMethod = strtolower(clean_text_input($payload['payment_method'] ?? 'cash', 40));
    $cashReceived = isset($payload['cash_received']) ? floatval($payload['cash_received']) : 0;
    $taxRate = isset($payload['tax_rate']) ? floatval($payload['tax_rate']) : 10;
    $discountAmount = isset($payload['discount_amount']) ? floatval($payload['discount_amount']) : 0;
    $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
    $staffUserId = intval($_SESSION['user_id'] ?? 0);
    $staffUsername = trim((string)($_SESSION['username'] ?? ''));

    if (count($items) === 0) {
        throw new Exception('No items in sale');
    }
    if ($taxRate < 0 || $taxRate > 100) {
        throw new Exception('Tax rate must be between 0 and 100');
    }
    if ($discountAmount < 0) {
        throw new Exception('Discount must be 0 or greater');
    }
    if ($staffUserId <= 0 || $staffUsername === '') {
        throw new Exception('Invalid staff session. Please sign in again.');
    }

    $conn->begin_transaction();
    try {
        $subtotal = 0.0;
        $validatedItems = [];

        $productStmt = $conn->prepare(
            "SELECT id, name, price, stock
             FROM products
             WHERE id = ? AND business_id = ?
             FOR UPDATE"
        );
        foreach ($items as $item) {
            $productId = isset($item['id']) ? intval($item['id']) : 0;
            $quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;

            if ($productId <= 0 || $quantity <= 0) {
                throw new Exception('Invalid cart item');
            }

            $productStmt->bind_param('ii', $productId, $businessId);
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

        $paymentStatus = 'paid';
        $paymentRef = null;
        $orderStmt = $conn->prepare("INSERT INTO orders
            (business_id, customer_name, customer_email, customer_phone, address, city, postal_code, subtotal, tax, shipping, total, notes, status, payment_method, payment_status, payment_reference, staff_user_id, staff_username, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, ?, NOW())");

        $address = 'In-store POS';
        $city = 'N/A';
        $postalCode = 'N/A';
        $orderStmt->bind_param(
            'issssssddddssssis',
            $businessId,
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
            $fullNotes,
            $paymentMethod,
            $paymentStatus,
            $paymentRef,
            $staffUserId,
            $staffUsername
        );
        $orderStmt->execute();
        $orderId = $conn->insert_id;
        $orderStmt->close();

        $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, business_id, product_id, product_name, quantity, price) VALUES (?, ?, ?, ?, ?, ?)");
        $stockStmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND business_id = ?");

        foreach ($validatedItems as $item) {
            $itemStmt->bind_param('iiisid', $orderId, $businessId, $item['id'], $item['name'], $item['quantity'], $item['price']);
            $itemStmt->execute();

            $stockStmt->bind_param('iii', $item['quantity'], $item['id'], $businessId);
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
    error_log('pos-checkout.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to complete sale. Please try again.'
    ]);
}

$conn->close();
?>
