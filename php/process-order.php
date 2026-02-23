<?php
session_start();
header('Content-Type: application/json');
include 'db-connection.php';

try {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
    $customer_email = isset($_POST['customer_email']) ? trim($_POST['customer_email']) : '';
    $customer_phone = isset($_POST['customer_phone']) ? trim($_POST['customer_phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $city = isset($_POST['city']) ? trim($_POST['city']) : '';
    $postal_code = isset($_POST['postal_code']) ? trim($_POST['postal_code']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $cart_data = isset($_POST['cart_data']) ? $_POST['cart_data'] : '[]';
    
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
        $productStmt = $conn->prepare("SELECT id, name, price, stock FROM products WHERE id = ? FOR UPDATE");
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

            $productStmt->bind_param('i', $product_id);
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
        $sql = "INSERT INTO orders (customer_name, customer_email, customer_phone, address, city, postal_code, subtotal, tax, shipping, total, notes, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error);
        }
        
        $stmt->bind_param(
            'ssssssdddds',
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
            $notes
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to create order: ' . $stmt->error);
        }
        
        $order_id = $conn->insert_id;
        
        // Insert order items
        $stmt->close();
        $sql = "INSERT INTO order_items (order_id, product_id, product_name, quantity, price) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error);
        }
        
        $stockStmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        if (!$stockStmt) {
            throw new Exception('Database error: ' . $conn->error);
        }

        foreach ($validatedItems as $item) {
            $stmt->bind_param('iisid', $order_id, $item['id'], $item['name'], $item['quantity'], $item['price']);
            if (!$stmt->execute()) {
                throw new Exception('Failed to add order item: ' . $stmt->error);
            }

            $stockStmt->bind_param('ii', $item['quantity'], $item['id']);
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
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
