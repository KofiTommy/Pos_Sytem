<?php
header('Content-Type: application/json');
include 'db-connection.php';
include 'payment-schema.php';
include 'tenant-context.php';
include 'paystack-service.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Invalid request method');
    }

    ensure_payment_schema($conn);

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $explicitBusinessCode = trim((string)($body['business_code'] ?? ''));
    if ($explicitBusinessCode === '') {
        $explicitBusinessCode = trim((string)($_GET['business_code'] ?? ($_GET['tenant'] ?? '')));
    }
    if ($explicitBusinessCode === '') {
        $explicitBusinessCode = tenant_request_uri_business_code();
    }
    if ($explicitBusinessCode === '') {
        throw new Exception('Missing business code for payment initialization.');
    }

    $business = tenant_require_business_context(
        $conn,
        ['business_code' => $explicitBusinessCode],
        false
    );
    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        throw new Exception('Business account not found.');
    }

    if (!paystack_is_configured($conn, $businessId)) {
        throw new Exception('Mobile Money is not configured yet for this business. Set Paystack key in Payment Settings.');
    }

    $customerName = trim((string)($body['customer_name'] ?? ''));
    $customerEmail = trim((string)($body['customer_email'] ?? ''));
    $customerPhone = trim((string)($body['customer_phone'] ?? ''));
    $address = trim((string)($body['address'] ?? ''));
    $city = trim((string)($body['city'] ?? ''));
    $postalCode = trim((string)($body['postal_code'] ?? ''));
    $notes = trim((string)($body['notes'] ?? ''));
    $cartData = (string)($body['cart_data'] ?? '[]');

    if ($customerName === '' || $customerEmail === '' || $customerPhone === '' || $address === '' || $city === '') {
        throw new Exception('Please fill in all required checkout fields.');
    }
    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address.');
    }

    $cart = json_decode($cartData, true);
    if (!is_array($cart) || count($cart) === 0) {
        throw new Exception('Cart is empty.');
    }
    if (count($cart) > 200) {
        throw new Exception('Cart is too large.');
    }

    $productStmt = $conn->prepare("SELECT id, name, price, stock FROM products WHERE id = ? AND business_id = ?");
    $validatedItems = [];
    $subtotal = 0.0;
    foreach ($cart as $item) {
        $productId = isset($item['id']) ? intval($item['id']) : 0;
        $quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
        if ($productId <= 0 || $quantity <= 0) {
            throw new Exception('Invalid cart item.');
        }

        $productStmt->bind_param('ii', $productId, $businessId);
        $productStmt->execute();
        $product = $productStmt->get_result()->fetch_assoc();
        if (!$product) {
            throw new Exception('A product in your cart was not found.');
        }
        if (intval($product['stock']) < $quantity) {
            throw new Exception('Insufficient stock for ' . $product['name'] . '.');
        }

        $price = floatval($product['price']);
        $lineTotal = $price * $quantity;
        $subtotal += $lineTotal;
        $validatedItems[] = [
            'id' => intval($product['id']),
            'name' => $product['name'],
            'quantity' => $quantity,
            'price' => $price
        ];
    }
    $productStmt->close();

    $tax = 0.0;
    $shipping = $subtotal > 0 ? 5.0 : 0.0;
    $total = round($subtotal + $tax + $shipping, 2);
    $reference = paystack_generate_reference();
    $cartJson = json_encode($validatedItems);
    if ($cartJson === false) {
        throw new Exception('Failed to encode cart for payment.');
    }

    $status = 'initialized';
    $intentStmt = $conn->prepare(
        "INSERT INTO payment_intents
         (business_id, reference, customer_name, customer_email, customer_phone, address, city, postal_code, notes, cart_json, subtotal, tax, shipping, total, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $intentStmt->bind_param(
        'isssssssssdddds',
        $businessId,
        $reference,
        $customerName,
        $customerEmail,
        $customerPhone,
        $address,
        $city,
        $postalCode,
        $notes,
        $cartJson,
        $subtotal,
        $tax,
        $shipping,
        $total,
        $status
    );
    $intentStmt->execute();
    $intentId = intval($conn->insert_id);
    $intentStmt->close();

    try {
        $gatewayPayload = [
            'email' => $customerEmail,
            'amount' => intval(round($total * 100)),
            'currency' => 'GHS',
            'reference' => $reference,
            'callback_url' => paystack_callback_url((string)($business['business_code'] ?? '')),
            'channels' => ['mobile_money'],
            'metadata' => [
                    'intent_id' => $intentId,
                    'business_id' => $businessId,
                    'customer_name' => $customerName
                ]
            ];
        $gatewayResp = paystack_api_request('POST', '/transaction/initialize', $gatewayPayload, $conn, $businessId);
    } catch (Exception $e) {
        $failedStatus = 'init_failed';
        $gatewayResponse = json_encode(['error' => $e->getMessage()]);
        $failStmt = $conn->prepare(
            "UPDATE payment_intents SET status = ?, gateway_response = ? WHERE id = ? AND business_id = ?"
        );
        $failStmt->bind_param('ssii', $failedStatus, $gatewayResponse, $intentId, $businessId);
        $failStmt->execute();
        $failStmt->close();
        throw $e;
    }

    $gatewayData = $gatewayResp['data'] ?? [];
    $authorizationUrl = (string)($gatewayData['authorization_url'] ?? '');
    $accessCode = (string)($gatewayData['access_code'] ?? '');
    if ($authorizationUrl === '' || $accessCode === '') {
        throw new Exception('Payment gateway did not return authorization data.');
    }

    $pendingStatus = 'pending';
    $gatewayResponseJson = json_encode($gatewayResp);
    $updateStmt = $conn->prepare(
        "UPDATE payment_intents
         SET status = ?, paystack_access_code = ?, gateway_response = ?
         WHERE id = ? AND business_id = ?"
    );
    $updateStmt->bind_param('sssii', $pendingStatus, $accessCode, $gatewayResponseJson, $intentId, $businessId);
    $updateStmt->execute();
    $updateStmt->close();

    respond(true, 'Payment initialized', [
        'reference' => $reference,
        'authorization_url' => $authorizationUrl
    ]);
} catch (Exception $e) {
    http_response_code(400);
    error_log('paystack-init.php: ' . $e->getMessage());
    respond(false, 'Unable to initialize payment right now.');
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

