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

function resolve_paystack_business_id(mysqli $conn, int $businessId = 0): int {
    if ($businessId > 0) {
        return $businessId;
    }
    if (function_exists('tenant_session_business_id')) {
        $sessionBusinessId = tenant_session_business_id();
        if ($sessionBusinessId > 0) {
            return $sessionBusinessId;
        }
    }

    try {
        if ($result = $conn->query("SELECT id FROM businesses WHERE status = 'active' ORDER BY id ASC LIMIT 1")) {
            $row = $result->fetch_assoc();
            if ($row) {
                return intval($row['id'] ?? 0);
            }
        }
        if ($result = $conn->query("SELECT id FROM businesses ORDER BY id ASC LIMIT 1")) {
            $row = $result->fetch_assoc();
            if ($row) {
                return intval($row['id'] ?? 0);
            }
        }
    } catch (Exception $e) {
        return 0;
    }

    return 0;
}

function ensure_payment_gateway_settings_table(mysqli $conn, int $businessId = 0): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS payment_gateway_settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            gateway VARCHAR(40) NOT NULL DEFAULT 'paystack',
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            use_sandbox TINYINT(1) NOT NULL DEFAULT 1,
            public_key VARCHAR(200) DEFAULT '',
            secret_key_ciphertext TEXT DEFAULT NULL,
            secret_key_iv VARCHAR(120) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_payment_gateway_business_id (business_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $schemaQueries = [
        "ALTER TABLE payment_gateway_settings MODIFY id INT NOT NULL AUTO_INCREMENT",
        "ALTER TABLE payment_gateway_settings ADD COLUMN business_id INT NULL AFTER id"
    ];
    foreach ($schemaQueries as $sql) {
        try {
            $conn->query($sql);
        } catch (mysqli_sql_exception $e) {
            $code = intval($e->getCode());
            if (!in_array($code, [1060, 1061, 1067], true)) {
                throw $e;
            }
        }
    }

    $effectiveBusinessId = resolve_paystack_business_id($conn, $businessId);
    if ($effectiveBusinessId > 0) {
        $repairStmt = $conn->prepare(
            "UPDATE payment_gateway_settings
             SET business_id = ?
             WHERE business_id IS NULL OR business_id = 0"
        );
        $repairStmt->bind_param('i', $effectiveBusinessId);
        $repairStmt->execute();
        $repairStmt->close();
        try {
            $conn->query("ALTER TABLE payment_gateway_settings MODIFY business_id INT NOT NULL");
        } catch (mysqli_sql_exception $e) {
            if (intval($e->getCode()) !== 1265) {
                throw $e;
            }
        }
        $conn->query(
            "DELETE t_old FROM payment_gateway_settings t_old
             JOIN payment_gateway_settings t_new
               ON t_old.business_id = t_new.business_id
              AND t_old.id < t_new.id"
        );
        try {
            $conn->query("ALTER TABLE payment_gateway_settings ADD UNIQUE KEY uk_payment_gateway_business_id (business_id)");
        } catch (mysqli_sql_exception $e) {
            if (intval($e->getCode()) !== 1061) {
                throw $e;
            }
        }
    }

    if ($effectiveBusinessId <= 0) {
        return;
    }

    $gateway = 'paystack';
    $enabled = 0;
    $useSandbox = 1;
    $publicKey = '';
    $stmt = $conn->prepare(
        "INSERT INTO payment_gateway_settings (business_id, gateway, enabled, use_sandbox, public_key)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE business_id = business_id"
    );
    $stmt->bind_param('isiis', $effectiveBusinessId, $gateway, $enabled, $useSandbox, $publicKey);
    $stmt->execute();
    $stmt->close();
}

function load_paystack_settings(mysqli $conn, int $businessId = 0): array {
    $effectiveBusinessId = resolve_paystack_business_id($conn, $businessId);
    ensure_payment_gateway_settings_table($conn, $effectiveBusinessId);

    if ($effectiveBusinessId <= 0) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT enabled, use_sandbox, public_key, secret_key_ciphertext, secret_key_iv, updated_at
         FROM payment_gateway_settings
         WHERE business_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $effectiveBusinessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : [];
}

function paystack_secret_key(?mysqli $conn = null, int $businessId = 0): string {
    $envSecret = trim((string)getenv('PAYSTACK_SECRET_KEY'));
    if ($envSecret !== '') {
        return $envSecret;
    }

    if (!$conn) {
        return '';
    }

    try {
        $settings = load_paystack_settings($conn, $businessId);
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

function paystack_public_key(?mysqli $conn = null, int $businessId = 0): string {
    $envPublic = trim((string)getenv('PAYSTACK_PUBLIC_KEY'));
    if ($envPublic !== '') {
        return $envPublic;
    }
    if (!$conn) {
        return '';
    }
    $settings = load_paystack_settings($conn, $businessId);
    return trim((string)($settings['public_key'] ?? ''));
}

function paystack_is_configured(?mysqli $conn = null, int $businessId = 0): bool {
    return paystack_secret_key($conn, $businessId) !== '';
}

function paystack_generate_reference(): string {
    try {
        $suffix = strtoupper(bin2hex(random_bytes(6)));
    } catch (Exception $e) {
        $suffix = strtoupper(dechex(mt_rand(100000, 999999)));
    }
    return 'MCPSK_' . date('YmdHis') . '_' . $suffix;
}

function paystack_callback_url(?string $businessCode = null): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/possystem/php/paystack-init.php';
    $projectPath = rtrim(str_replace('\\', '/', dirname(dirname($scriptName))), '/');
    $safeCode = strtolower(trim((string)$businessCode));
    $safeCode = preg_replace('/[^a-z0-9-]/', '', $safeCode);

    if ($safeCode !== '') {
        $url = $scheme . '://' . $host . $projectPath . '/b/' . rawurlencode($safeCode) . '/pages/cart.html?payment=paystack';
    } else {
        $url = $scheme . '://' . $host . $projectPath . '/pages/cart.html?payment=paystack';
    }

    return $url;
}

function paystack_api_request(string $method, string $path, ?array $payload = null, ?mysqli $conn = null, int $businessId = 0): array {
    $secret = paystack_secret_key($conn, $businessId);
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

function paystack_verify_transaction(string $reference, ?mysqli $conn = null, int $businessId = 0): array {
    $safeReference = rawurlencode($reference);
    return paystack_api_request('GET', '/transaction/verify/' . $safeReference, null, $conn, $businessId);
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
        $businessId = intval($intent['business_id'] ?? 0);
        if ($businessId <= 0) {
            throw new Exception('Invalid payment business context.');
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

        $productStmt = $conn->prepare("SELECT id, name, price, stock FROM products WHERE id = ? AND business_id = ? FOR UPDATE");
        $validatedItems = [];
        $subtotal = 0.0;

        foreach ($cart as $item) {
            $productId = isset($item['id']) ? intval($item['id']) : 0;
            $quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
            if ($productId <= 0 || $quantity <= 0) {
                throw new Exception('Invalid item in stored cart.');
            }

            $productStmt->bind_param('ii', $productId, $businessId);
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

        $tax = 0.0;
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
            (business_id, customer_name, customer_email, customer_phone, address, city, postal_code, subtotal, tax, shipping, total, notes, status, payment_method, payment_status, payment_reference, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', 'paystack_mobile_money', 'paid', ?, NOW())"
        );
        $orderStmt->bind_param(
            'issssssddddss',
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
            $notes,
            $reference
        );
        $orderStmt->execute();
        $orderId = intval($conn->insert_id);
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

