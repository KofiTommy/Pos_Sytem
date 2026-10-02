<?php

function ensure_delivery_fee_schema(mysqli $conn): void {
    static $ready = false;
    if ($ready) return;
    $result = $conn->query("SHOW COLUMNS FROM business_settings LIKE 'delivery_fee'");
    if ($result->num_rows === 0) {
        try {
            $conn->query("ALTER TABLE business_settings ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 5.00");
        } catch (mysqli_sql_exception $e) {
            if (intval($e->getCode()) !== 1060) throw $e;
        }
    }
    $ready = true;
}

function validate_delivery_fee($value): float {
    if (!is_scalar($value) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', trim((string)$value))) {
        throw new InvalidArgumentException('Delivery fee must be a non-negative amount with at most two decimal places.');
    }
    return round((float)$value, 2);
}

function business_delivery_fee(mysqli $conn, int $businessId): float {
    $stmt = $conn->prepare('SELECT delivery_fee FROM business_settings WHERE business_id = ? LIMIT 1');
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['delivery_fee'] ?? 5.0);
}
