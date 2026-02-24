<?php

function ensure_payment_schema(mysqli $conn): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS payment_intents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reference VARCHAR(120) NOT NULL UNIQUE,
            customer_name VARCHAR(200) NOT NULL,
            customer_email VARCHAR(160) NOT NULL,
            customer_phone VARCHAR(40) NOT NULL,
            address VARCHAR(255) NOT NULL,
            city VARCHAR(120) NOT NULL,
            postal_code VARCHAR(40) DEFAULT '',
            notes TEXT DEFAULT NULL,
            cart_json LONGTEXT NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            shipping DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(40) NOT NULL DEFAULT 'initialized',
            order_id INT DEFAULT NULL,
            paystack_access_code VARCHAR(120) DEFAULT NULL,
            gateway_response LONGTEXT DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_payment_intents_status (status),
            INDEX idx_payment_intents_order_id (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $queries = [
        "ALTER TABLE orders ADD COLUMN payment_method VARCHAR(40) NOT NULL DEFAULT 'cod' AFTER status",
        "ALTER TABLE orders ADD COLUMN payment_status VARCHAR(40) NOT NULL DEFAULT 'unpaid' AFTER payment_method",
        "ALTER TABLE orders ADD COLUMN payment_reference VARCHAR(120) DEFAULT NULL AFTER payment_status",
        "ALTER TABLE orders ADD INDEX idx_orders_payment_reference (payment_reference)"
    ];

    foreach ($queries as $sql) {
        try {
            $conn->query($sql);
        } catch (mysqli_sql_exception $e) {
            $code = intval($e->getCode());
            if (!in_array($code, [1060, 1061], true)) {
                throw $e;
            }
        }
    }

    $ready = true;
}

