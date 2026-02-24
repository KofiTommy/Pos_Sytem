<?php

function payment_settings_crypto_key(): string {
    return trim((string)getenv('PAYMENT_SETTINGS_KEY'));
}

function encrypt_payment_secret(string $plainText): array {
    $key = payment_settings_crypto_key();
    if ($key === '') {
        throw new Exception('PAYMENT_SETTINGS_KEY is not configured on the server.');
    }

    $cipher = 'aes-256-cbc';
    $ivLength = openssl_cipher_iv_length($cipher);
    $iv = random_bytes($ivLength);
    $encrypted = openssl_encrypt($plainText, $cipher, hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        throw new Exception('Failed to encrypt payment secret.');
    }

    return [
        'ciphertext' => base64_encode($encrypted),
        'iv' => base64_encode($iv)
    ];
}

function decrypt_payment_secret(string $ciphertextB64, string $ivB64): string {
    $key = payment_settings_crypto_key();
    if ($key === '') {
        throw new Exception('PAYMENT_SETTINGS_KEY is not configured on the server.');
    }

    $ciphertext = base64_decode($ciphertextB64, true);
    $iv = base64_decode($ivB64, true);
    if ($ciphertext === false || $iv === false) {
        throw new Exception('Stored payment secret is invalid.');
    }

    $cipher = 'aes-256-cbc';
    $decrypted = openssl_decrypt($ciphertext, $cipher, hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        throw new Exception('Unable to decrypt stored payment secret.');
    }
    return $decrypted;
}

function ensure_payment_gateway_settings_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS payment_gateway_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            gateway VARCHAR(40) NOT NULL DEFAULT 'paystack',
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            use_sandbox TINYINT(1) NOT NULL DEFAULT 1,
            public_key VARCHAR(200) DEFAULT '',
            secret_key_ciphertext TEXT DEFAULT NULL,
            secret_key_iv VARCHAR(120) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $conn->prepare(
        "INSERT INTO payment_gateway_settings (id, gateway, enabled, use_sandbox, public_key)
         VALUES (1, 'paystack', 0, 1, '')
         ON DUPLICATE KEY UPDATE id = id"
    );
    $stmt->execute();
    $stmt->close();
}

function load_paystack_settings(mysqli $conn): array {
    ensure_payment_gateway_settings_table($conn);
    $stmt = $conn->prepare(
        "SELECT enabled, use_sandbox, public_key, secret_key_ciphertext, secret_key_iv, updated_at
         FROM payment_gateway_settings
         WHERE id = 1
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : [];
}

function paystack_secret_key(?mysqli $conn = null): string {
    $envSecret = trim((string)getenv('PAYSTACK_SECRET_KEY'));
    if ($envSecret !== '') {
        return $envSecret;
    }

    if (!$conn) {
        return '';
    }

    try {
        $settings = load_paystack_settings($conn);
        $enabled = intval($settings['enabled'] ?? 0) === 1;
        $ciphertext = (string)($settings['secret_key_ciphertext'] ?? '');
        $iv = (string)($settings['secret_key_iv'] ?? '');
        if (!$enabled || $ciphertext === '' || $iv === '') {
            return '';
        }
        return trim(decrypt_payment_secret($ciphertext, $iv));
    } catch (Exception $e) {
        return '';
    }
}

function paystack_public_key(?mysqli $conn = null): string {
    $envPublic = trim((string)getenv('PAYSTACK_PUBLIC_KEY'));
    if ($envPublic !== '') {
        return $envPublic;
    }
    if (!$conn) {
        return '';
    }
    $settings = load_paystack_settings($conn);
    return trim((string)($settings['public_key'] ?? ''));
}

function paystack_is_configured(?mysqli $conn = null): bool {
    return paystack_secret_key($conn) !== '';
}

function paystack_generate_reference(): string {
    try {
        $suffix = strtoupper(bin2hex(random_bytes(6)));
    } catch (Exception $e) {
        $suffix = strtoupper(dechex(mt_rand(100000, 999999)));
    }
    return 'MCPSK_' . date('YmdHis') . '_' . $suffix;
}

function paystack_callback_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/possystem/php/paystack-init.php';
    $projectPath = rtrim(str_replace('\\', '/', dirname(dirname($scriptName))), '/');
    return $scheme . '://' . $host . $projectPath . '/pages/cart.html?payment=paystack';
}

function paystack_api_request(string $method, string $path, ?array $payload = null, ?mysqli $conn = null): array {
    $secret = paystack_secret_key($conn);
    if ($secret === '') {
        throw new Exception('PAYSTACK_SECRET_KEY is not configured on the server.');
    }

    $url = 'https://api.paystack.co' . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new Exception('Failed to initialize payment gateway request.');
    }

    $headers = [
        'Authorization: Bearer ' . $secret,
        'Content-Type: application/json'
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    if ($payload !== null) {
        $json = json_encode($payload);
        if ($json === false) {
            curl_close($ch);
            throw new Exception('Invalid payment request payload.');
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $raw = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $curlError !== '') {
        throw new Exception('Payment gateway network error: ' . ($curlError ?: 'Unknown error'));
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('Invalid response from payment gateway.');
    }

    if ($httpCode >= 400 || empty($data['status'])) {
        $message = isset($data['message']) ? (string)$data['message'] : 'Payment gateway request failed.';
        throw new Exception($message);
    }

    return $data;
}

function paystack_verify_transaction(string $reference, ?mysqli $conn = null): array {
    $safeReference = rawurlencode($reference);
    return paystack_api_request('GET', '/transaction/verify/' . $safeReference, null, $conn);
}

function append_payment_note(string $notes, string $reference): string {
    $paymentNote = 'Payment Ref: ' . $reference;
    if (strpos($notes, $paymentNote) !== false) {
        return $notes;
    }
    return trim($notes . ($notes !== '' ? ' | ' : '') . $paymentNote);
}

function finalize_paystack_intent(mysqli $conn, string $reference, array $verifiedData): array {
    ensure_payment_schema($conn);

    $conn->begin_transaction();
    try {
        $intentStmt = $conn->prepare(
            "SELECT * FROM payment_intents WHERE reference = ? FOR UPDATE"
        );
        $intentStmt->bind_param('s', $reference);
        $intentStmt->execute();
        $intent = $intentStmt->get_result()->fetch_assoc();
        $intentStmt->close();

        if (!$intent) {
            throw new Exception('Payment intent not found.');
        }

        $existingOrderId = intval($intent['order_id'] ?? 0);
        if ($existingOrderId > 0 && in_array((string)$intent['status'], ['paid', 'fulfilled'], true)) {
            $conn->commit();
            return [
                'already_processed' => true,
                'order_id' => $existingOrderId
            ];
        }

        $gatewayAmountPesewas = intval($verifiedData['amount'] ?? 0);
        $intentTotal = floatval($intent['total'] ?? 0);
        $intentAmountPesewas = intval(round($intentTotal * 100));
        if ($gatewayAmountPesewas !== $intentAmountPesewas) {
            throw new Exception('Verified payment amount does not match order total.');
        }

        $gatewayCurrency = strtoupper((string)($verifiedData['currency'] ?? ''));
        if ($gatewayCurrency !== 'GHS') {
            throw new Exception('Unexpected payment currency from gateway.');
        }

        $cart = json_decode((string)($intent['cart_json'] ?? ''), true);
        if (!is_array($cart) || count($cart) === 0) {
            throw new Exception('Stored cart data is invalid.');
        }

        $productStmt = $conn->prepare("SELECT id, name, price, stock FROM products WHERE id = ? FOR UPDATE");
        $validatedItems = [];
        $subtotal = 0.0;

        foreach ($cart as $item) {
            $productId = isset($item['id']) ? intval($item['id']) : 0;
            $quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
            if ($productId <= 0 || $quantity <= 0) {
                throw new Exception('Invalid item in stored cart.');
            }

            $productStmt->bind_param('i', $productId);
            $productStmt->execute();
            $product = $productStmt->get_result()->fetch_assoc();
            if (!$product) {
                throw new Exception('Product not found during payment finalization.');
            }
            if (intval($product['stock']) < $quantity) {
                throw new Exception('Insufficient stock for ' . $product['name'] . ' during finalization.');
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
        $total = round($subtotal + $tax + $shipping, 2);

        $intentSubtotal = round(floatval($intent['subtotal'] ?? 0), 2);
        $intentTax = round(floatval($intent['tax'] ?? 0), 2);
        $intentShipping = round(floatval($intent['shipping'] ?? 0), 2);
        $intentTotalRounded = round(floatval($intent['total'] ?? 0), 2);
        if ($subtotal !== $intentSubtotal || $tax !== $intentTax || $shipping !== $intentShipping || $total !== $intentTotalRounded) {
            throw new Exception('Cart totals changed before payment finalization.');
        }

        $customerName = (string)$intent['customer_name'];
        $customerEmail = (string)$intent['customer_email'];
        $customerPhone = (string)$intent['customer_phone'];
        $address = (string)$intent['address'];
        $city = (string)$intent['city'];
        $postalCode = (string)$intent['postal_code'];
        $notes = append_payment_note((string)($intent['notes'] ?? ''), $reference);

        $orderStmt = $conn->prepare(
            "INSERT INTO orders
            (customer_name, customer_email, customer_phone, address, city, postal_code, subtotal, tax, shipping, total, notes, status, payment_method, payment_status, payment_reference, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', 'paystack_mobile_money', 'paid', ?, NOW())"
        );
        $orderStmt->bind_param(
            'ssssssddddss',
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
            $notes,
            $reference
        );
        $orderStmt->execute();
        $orderId = intval($conn->insert_id);
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

        $gatewayResponseJson = json_encode($verifiedData);
        $status = 'paid';
        $updateIntentStmt = $conn->prepare(
            "UPDATE payment_intents
             SET status = ?, order_id = ?, gateway_response = ?, paid_at = NOW()
             WHERE id = ?"
        );
        $intentId = intval($intent['id']);
        $updateIntentStmt->bind_param('sisi', $status, $orderId, $gatewayResponseJson, $intentId);
        $updateIntentStmt->execute();
        $updateIntentStmt->close();

        $conn->commit();
        return [
            'already_processed' => false,
            'order_id' => $orderId
        ];
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}
