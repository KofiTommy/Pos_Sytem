<?php
include_once __DIR__ . '/session-bootstrap.php';
secure_session_start();
header('Content-Type: application/json');
include 'db-connection.php';
include 'payment-schema.php';
include 'tenant-context.php';

function clean_text_input($value, $maxLen = 255) {
    $text = trim(strip_tags((string)$value));
    if (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen);
    }
    return $text;
}

try {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        throw new Exception('Invalid request method');
    }

    ensure_payment_schema($conn);
    $business = tenant_require_business_context(
        $conn,
        ['business_code' => $_POST['business_code'] ?? ''],
        true
    );
    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        throw new Exception('Invalid business context');
    }
    
    $customer_name = clean_text_input($_POST['customer_name'] ?? '', 120);
    $customer_email = clean_text_input($_POST['customer_email'] ?? '', 160);
    $customer_phone = clean_text_input($_POST['customer_phone'] ?? '', 40);
    $address = clean_text_input($_POST['address'] ?? '', 255);
    $city = clean_text_input($_POST['city'] ?? '', 120);
    $postal_code = clean_text_input($_POST['postal_code'] ?? '', 40);
    $notes = clean_text_input($_POST['notes'] ?? '', 1000);
    $payment_method = strtolower(clean_text_input($_POST['payment_method'] ?? 'cod', 40));
    $cart_data = isset($_POST['cart_data']) ? $_POST['cart_data'] : '[]';

    $allowed_payment_methods = ['cod', 'cash_on_delivery', 'pay_on_delivery'];
    if (!in_array($payment_method, $allowed_payment_methods, true)) {
        $payment_method = 'cod';
    }
    
    // Validate
    if (empty($customer_name) || empty($customer_email) || empty($customer_phone)) {
        throw new Exception('Please fill in all required fields');
    }
    if (strlen($customer_name) > 120 || strlen($customer_email) > 160 || strlen($customer_phone) > 40) {
        throw new Exception('Customer information is too long');
    }
    if (strlen($address) > 255 || strlen($city) > 120 || strlen($postal_code) > 40 || strlen($notes) > 1000) {
        throw new Exception('Address or notes are too long');
    }
    
    // Validate email
    if (!filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address');
    }
    
    // Parse cart data
    $cart = json_decode($cart_data, true);
    if (empty($cart)) {
        throw new Exception('Cart is empty');
    }
    if (!is_array($cart) || count($cart) > 200) {
        throw new Exception('Invalid cart payload');
    }

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Validate product IDs and prices against DB to prevent tampering and overselling.
        $productStmt = $conn->prepare(
            "SELECT id, name, price, stock
             FROM products
             WHERE id = ? AND business_id = ?
             FOR UPDATE"
        );
        if (!$productStmt) {
            throw new Exception('Database error: ' . $conn->error);
        }

        $validatedItems = [];
        $subtotal = 0.0;
        foreach ($cart as $item) {
            $product_id = isset($item['id']) ? intval($item['id']) : 0;
            $quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;

            if ($product_id <= 0 || $quantity <= 0) {
                throw new Exception('Invalid cart item');
            }

            $productStmt->bind_param('ii', $product_id, $businessId);
            $productStmt->execute();
            $product = $productStmt->get_result()->fetch_assoc();

            if (!$product) {
                throw new Exception('Product not found');
            }
            if (intval($product['stock']) < $quantity) {
                throw new Exception('Insufficient stock for ' . $product['name']);
            }

            $price = floatval($product['price']);
            $lineTotal = $price * $quantity;
            $subtotal += $lineTotal;

            $validatedItems[] = [
                'id' => intval($product['id']),
                'name' => $product['name'],
                'price' => $price,
                'quantity' => $quantity
            ];
        }
        $productStmt->close();

        $tax = round($subtotal * 0.10, 2);
        $shipping = $subtotal > 0 ? 5.0 : 0.0;
        $final_total = round($subtotal + $tax + $shipping, 2);

        // Insert order
        $sql = "INSERT INTO orders (business_id, customer_name, customer_email, customer_phone, address, city, postal_code, subtotal, tax, shipping, total, notes, status, payment_method, payment_status, payment_reference, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'unpaid', NULL, NOW())";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error);
        }
        
        $stmt->bind_param(
            'issssssddddss',
            $businessId,
            $customer_name,
            $customer_email,
            $customer_phone,
            $address,
            $city,
            $postal_code,
            $subtotal,
            $tax,
            $shipping,
            $final_total,
            $notes,
            $payment_method
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to create order: ' . $stmt->error);
        }
        
        $order_id = $conn->insert_id;
        
        // Insert order items
        $stmt->close();
        $sql = "INSERT INTO order_items (order_id, business_id, product_id, product_name, quantity, price) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error);
        }
        
        $stockStmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND business_id = ?");
        if (!$stockStmt) {
            throw new Exception('Database error: ' . $conn->error);
        }

        foreach ($validatedItems as $item) {
            $stmt->bind_param('iiisid', $order_id, $businessId, $item['id'], $item['name'], $item['quantity'], $item['price']);
            if (!$stmt->execute()) {
                throw new Exception('Failed to add order item: ' . $stmt->error);
            }

            $stockStmt->bind_param('iii', $item['quantity'], $item['id'], $businessId);
            if (!$stockStmt->execute()) {
                throw new Exception('Failed to update inventory');
            }
        }
        
        $stmt->close();
        $stockStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order placed successfully',
            'order_id' => $order_id
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('process-order.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to place order. Please review your details and try again.'
    ]);
}

$conn->close();
?>
