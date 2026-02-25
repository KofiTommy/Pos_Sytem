<?php

function ensure_staff_tracking_schema(mysqli $conn): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $queries = [
        "ALTER TABLE orders ADD COLUMN staff_user_id INT NULL AFTER payment_reference",
        "ALTER TABLE orders ADD COLUMN staff_username VARCHAR(100) NULL AFTER staff_user_id",
        "ALTER TABLE orders ADD INDEX idx_orders_staff_user_id (staff_user_id)"
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

