<?php

function ensure_phase3_tracking_schema(mysqli $conn): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS business_audit_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            actor_user_id INT NOT NULL DEFAULT 0,
            actor_username VARCHAR(100) NOT NULL DEFAULT '',
            action_key VARCHAR(80) NOT NULL,
            entity_type VARCHAR(60) NOT NULL DEFAULT '',
            entity_id BIGINT NOT NULL DEFAULT 0,
            details_json LONGTEXT DEFAULT NULL,
            request_ip VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_business_audit_business_time (business_id, created_at),
            INDEX idx_business_audit_action_time (action_key, created_at),
            INDEX idx_business_audit_entity_time (entity_type, entity_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_adjustments (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            product_id INT NOT NULL,
            order_id INT NOT NULL DEFAULT 0,
            adjustment_type VARCHAR(60) NOT NULL,
            quantity_delta INT NOT NULL,
            stock_before INT NOT NULL DEFAULT 0,
            stock_after INT NOT NULL DEFAULT 0,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            actor_user_id INT NOT NULL DEFAULT 0,
            actor_username VARCHAR(100) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inventory_adjustments_business_time (business_id, created_at),
            INDEX idx_inventory_adjustments_product_time (product_id, created_at),
            INDEX idx_inventory_adjustments_order_time (order_id, created_at),
            INDEX idx_inventory_adjustments_type_time (adjustment_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS cash_closures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            closure_date DATE NOT NULL,
            shift_label VARCHAR(60) NOT NULL DEFAULT 'daily',
            expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            counted_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            variance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'closed',
            closed_by_user_id INT NOT NULL DEFAULT 0,
            closed_by_username VARCHAR(100) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cash_closures_business_date_shift (business_id, closure_date, shift_label),
            INDEX idx_cash_closures_business_date (business_id, closure_date),
            INDEX idx_cash_closures_business_created (business_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($queries as $sql) {
        $conn->query($sql);
    }

    $ready = true;
}

function tracking_actor_user_id(?int $fallback = null): int {
    $fallbackId = intval($fallback ?? 0);
    if ($fallbackId > 0) {
        return $fallbackId;
    }

    $sessionId = intval($_SESSION['user_id'] ?? 0);
    return $sessionId > 0 ? $sessionId : 0;
}

function tracking_actor_username(?string $fallback = null): string {
    $username = trim((string)($fallback ?? ''));
    if ($username === '') {
        $username = trim((string)($_SESSION['username'] ?? ''));
    }
    if ($username === '') {
        $username = 'system';
    }
    if (strlen($username) > 100) {
        $username = substr($username, 0, 100);
    }
    return $username;
}

function tracking_request_ip(): string {
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }
    return '0.0.0.0';
}

function tracking_user_agent(): string {
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (strlen($userAgent) > 255) {
        $userAgent = substr($userAgent, 0, 255);
    }
    return $userAgent;
}

function tracking_action_key(string $value): string {
    $key = strtolower(trim($value));
    $key = preg_replace('/[^a-z0-9._:-]/', '_', $key);
    if (!is_string($key) || $key === '') {
        $key = 'unknown';
    }
    if (strlen($key) > 80) {
        $key = substr($key, 0, 80);
    }
    return $key;
}

function tracking_encode_details(array $details): string {
    $json = json_encode($details, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return '{}';
    }
    return $json;
}

function tracking_log_business_event(
    mysqli $conn,
    int $businessId,
    string $actionKey,
    string $entityType = '',
    int $entityId = 0,
    array $details = [],
    ?int $actorUserId = null,
    ?string $actorUsername = null
): void {
    if ($businessId <= 0 || trim($actionKey) === '') {
        return;
    }

    try {
        ensure_phase3_tracking_schema($conn);

        $safeActionKey = tracking_action_key($actionKey);
        $safeEntityType = strtolower(trim($entityType));
        $safeEntityType = preg_replace('/[^a-z0-9._:-]/', '_', (string)$safeEntityType);
        if ($safeEntityType === null || $safeEntityType === false) {
            $safeEntityType = '';
        }
        if (strlen($safeEntityType) > 60) {
            $safeEntityType = substr($safeEntityType, 0, 60);
        }

        $safeEntityId = max(0, intval($entityId));
        $safeActorUserId = tracking_actor_user_id($actorUserId);
        $safeActorUsername = tracking_actor_username($actorUsername);
        $safeDetails = tracking_encode_details($details);
        $requestIp = tracking_request_ip();
        $userAgent = tracking_user_agent();

        $stmt = $conn->prepare(
            "INSERT INTO business_audit_log
                (business_id, actor_user_id, actor_username, action_key, entity_type, entity_id, details_json, request_ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iisssisss',
            $businessId,
            $safeActorUserId,
            $safeActorUsername,
            $safeActionKey,
            $safeEntityType,
            $safeEntityId,
            $safeDetails,
            $requestIp,
            $userAgent
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('compliance-tracking audit log: ' . $e->getMessage());
    }
}

function tracking_log_inventory_adjustment(
    mysqli $conn,
    int $businessId,
    int $productId,
    int $quantityDelta,
    string $adjustmentType,
    int $orderId = 0,
    int $stockBefore = 0,
    int $stockAfter = 0,
    string $reason = '',
    ?int $actorUserId = null,
    ?string $actorUsername = null
): void {
    if ($businessId <= 0 || $productId <= 0 || $quantityDelta === 0 || trim($adjustmentType) === '') {
        return;
    }

    try {
        ensure_phase3_tracking_schema($conn);

        $safeAdjustmentType = strtolower(trim($adjustmentType));
        $safeAdjustmentType = preg_replace('/[^a-z0-9._:-]/', '_', (string)$safeAdjustmentType);
        if ($safeAdjustmentType === null || $safeAdjustmentType === false || $safeAdjustmentType === '') {
            $safeAdjustmentType = 'adjustment';
        }
        if (strlen($safeAdjustmentType) > 60) {
            $safeAdjustmentType = substr($safeAdjustmentType, 0, 60);
        }

        $safeReason = trim($reason);
        if (strlen($safeReason) > 255) {
            $safeReason = substr($safeReason, 0, 255);
        }

        $safeActorUserId = tracking_actor_user_id($actorUserId);
        $safeActorUsername = tracking_actor_username($actorUsername);
        $safeOrderId = max(0, intval($orderId));

        $stmt = $conn->prepare(
            "INSERT INTO inventory_adjustments
                (business_id, product_id, order_id, adjustment_type, quantity_delta, stock_before, stock_after, reason, actor_user_id, actor_username)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iiisiiisis',
            $businessId,
            $productId,
            $safeOrderId,
            $safeAdjustmentType,
            $quantityDelta,
            $stockBefore,
            $stockAfter,
            $safeReason,
            $safeActorUserId,
            $safeActorUsername
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('compliance-tracking inventory log: ' . $e->getMessage());
    }
}

function tracking_expected_cash_for_date(mysqli $conn, int $businessId, string $closureDate): float {
    if ($businessId <= 0 || trim($closureDate) === '') {
        return 0.0;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(total), 0) AS expected_cash
             FROM orders
             WHERE business_id = ?
               AND DATE(created_at) = ?
               AND LOWER(payment_method) IN ('cash', 'cod', 'cash_on_delivery', 'pay_on_delivery')
               AND (
                    payment_status = 'paid'
                    OR status IN ('paid', 'completed', 'processing')
               )"
        );
        $stmt->bind_param('is', $businessId, $closureDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return round(floatval($row['expected_cash'] ?? 0), 2);
    } catch (Throwable $e) {
        error_log('compliance-tracking expected cash: ' . $e->getMessage());
        return 0.0;
    }
}

