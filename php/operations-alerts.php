<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner']);
include 'db-connection.php';
include 'tenant-context.php';
include_once __DIR__ . '/compliance-tracking.php';

function respond($success, $message = '', $extra = [], $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function parse_ymd_date($rawDate): ?DateTime {
    $date = trim((string)$rawDate);
    if ($date === '') {
        return null;
    }

    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj) {
        return null;
    }

    $errors = DateTime::getLastErrors();
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }

    if ($dateObj->format('Y-m-d') !== $date) {
        return null;
    }

    return $dateObj;
}

function bind_dynamic_params(mysqli_stmt $stmt, string $types, array $values): void {
    $params = [];
    $params[] = &$types;
    foreach ($values as $index => $value) {
        $values[$index] = $value;
        $params[] = &$values[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
}

function ensure_operations_alerts_schema(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS operations_alerts (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            alert_key VARCHAR(120) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'medium',
            title VARCHAR(180) NOT NULL,
            details VARCHAR(500) NOT NULL DEFAULT '',
            metric_value DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
            threshold_value DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
            alert_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            first_detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            acknowledged_at DATETIME DEFAULT NULL,
            acknowledged_by_user_id INT NOT NULL DEFAULT 0,
            acknowledged_by_username VARCHAR(100) NOT NULL DEFAULT '',
            context_json LONGTEXT DEFAULT NULL,
            UNIQUE KEY uk_operations_alerts_business_key_date (business_id, alert_key, alert_date),
            INDEX idx_operations_alerts_business_status_date (business_id, status, alert_date),
            INDEX idx_operations_alerts_business_severity_date (business_id, severity, alert_date),
            INDEX idx_operations_alerts_business_detected (business_id, last_detected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function detect_operations_alerts(mysqli $conn, int $businessId, int $lowStockThreshold = 5): array {
    $alerts = [];
    $now = new DateTime('now');
    $today = (new DateTime('today'))->format('Y-m-d');
    $sevenDaysAgo = (new DateTime('today'))->modify('-6 days')->format('Y-m-d') . ' 00:00:00';
    $threeDaysAgo = $now->modify('-3 days')->format('Y-m-d H:i:s');

    $ordersStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS orders_count,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_orders,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
            COALESCE(SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END), 0) AS refunded_orders
         FROM orders
         WHERE business_id = ? AND created_at >= ?"
    );
    $ordersStmt->bind_param('is', $businessId, $sevenDaysAgo);
    $ordersStmt->execute();
    $orders = $ordersStmt->get_result()->fetch_assoc() ?: [];
    $ordersStmt->close();

    $ordersCount = intval($orders['orders_count'] ?? 0);
    $pendingOrders = intval($orders['pending_orders'] ?? 0);
    $cancelledOrders = intval($orders['cancelled_orders'] ?? 0);
    $refundedOrders = intval($orders['refunded_orders'] ?? 0);

    if ($ordersCount >= 5) {
        $pendingRate = $pendingOrders / $ordersCount;
        if ($pendingRate > 0.20) {
            $alerts[] = [
                'alert_key' => 'pending_rate_high',
                'severity' => $pendingRate > 0.35 ? 'high' : 'medium',
                'title' => 'High pending-order ratio',
                'details' => 'Pending order ratio over last 7 days is above acceptable threshold.',
                'metric_value' => $pendingRate,
                'threshold_value' => 0.20,
                'alert_date' => $today,
                'context' => [
                    'orders_count' => $ordersCount,
                    'pending_orders' => $pendingOrders,
                    'window' => '7d'
                ]
            ];
        }

        $issueRate = ($cancelledOrders + $refundedOrders) / $ordersCount;
        if ($issueRate > 0.08) {
            $alerts[] = [
                'alert_key' => 'issue_rate_high',
                'severity' => $issueRate > 0.15 ? 'high' : 'medium',
                'title' => 'High cancellation/refund ratio',
                'details' => 'Cancelled + refunded order ratio over last 7 days is elevated.',
                'metric_value' => $issueRate,
                'threshold_value' => 0.08,
                'alert_date' => $today,
                'context' => [
                    'orders_count' => $ordersCount,
                    'cancelled_orders' => $cancelledOrders,
                    'refunded_orders' => $refundedOrders,
                    'window' => '7d'
                ]
            ];
        }
    }

    $stockStmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN stock <= ? THEN 1 ELSE 0 END), 0) AS low_stock_count,
            COALESCE(SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END), 0) AS stockout_count
         FROM products
         WHERE business_id = ?"
    );
    $stockStmt->bind_param('ii', $lowStockThreshold, $businessId);
    $stockStmt->execute();
    $stock = $stockStmt->get_result()->fetch_assoc() ?: [];
    $stockStmt->close();

    $lowStockCount = intval($stock['low_stock_count'] ?? 0);
    $stockoutCount = intval($stock['stockout_count'] ?? 0);

    if ($lowStockCount >= 25) {
        $alerts[] = [
            'alert_key' => 'low_stock_high',
            'severity' => $lowStockCount >= 40 ? 'high' : 'medium',
            'title' => 'High low-stock SKU count',
            'details' => 'Many products are at or below low-stock threshold.',
            'metric_value' => $lowStockCount,
            'threshold_value' => 25,
            'alert_date' => $today,
            'context' => [
                'low_stock_threshold' => $lowStockThreshold
            ]
        ];
    }

    if ($stockoutCount >= 10) {
        $alerts[] = [
            'alert_key' => 'stockout_high',
            'severity' => $stockoutCount >= 20 ? 'high' : 'medium',
            'title' => 'High stockout SKU count',
            'details' => 'Too many products are fully out of stock.',
            'metric_value' => $stockoutCount,
            'threshold_value' => 10,
            'alert_date' => $today,
            'context' => []
        ];
    }

    if (tenant_table_exists($conn, 'cash_closures')) {
        $cashStmt = $conn->prepare(
            "SELECT
                id,
                closure_date,
                shift_label,
                variance
             FROM cash_closures
             WHERE business_id = ?
             ORDER BY closure_date DESC, id DESC
             LIMIT 1"
        );
        $cashStmt->bind_param('i', $businessId);
        $cashStmt->execute();
        $latestClosure = $cashStmt->get_result()->fetch_assoc();
        $cashStmt->close();

        if ($latestClosure) {
            $variance = round(floatval($latestClosure['variance'] ?? 0), 2);
            if ($variance <= -50) {
                $alerts[] = [
                    'alert_key' => 'cash_variance_negative',
                    'severity' => $variance <= -200 ? 'high' : 'medium',
                    'title' => 'Negative cash variance',
                    'details' => 'Latest cash closure has a significant negative variance.',
                    'metric_value' => $variance,
                    'threshold_value' => -50,
                    'alert_date' => $today,
                    'context' => [
                        'closure_id' => intval($latestClosure['id'] ?? 0),
                        'closure_date' => (string)($latestClosure['closure_date'] ?? ''),
                        'shift_label' => (string)($latestClosure['shift_label'] ?? '')
                    ]
                ];
            }
        }
    }

    $recentSalesStmt = $conn->prepare(
        "SELECT COUNT(*) AS recent_orders FROM orders WHERE business_id = ? AND created_at >= ?"
    );
    $recentSalesStmt->bind_param('is', $businessId, $threeDaysAgo);
    $recentSalesStmt->execute();
    $recentSales = $recentSalesStmt->get_result()->fetch_assoc() ?: [];
    $recentSalesStmt->close();
    $recentOrders = intval($recentSales['recent_orders'] ?? 0);

    if ($recentOrders === 0) {
        $historyStmt = $conn->prepare(
            "SELECT COUNT(*) AS historical_orders FROM orders WHERE business_id = ?"
        );
        $historyStmt->bind_param('i', $businessId);
        $historyStmt->execute();
        $history = $historyStmt->get_result()->fetch_assoc() ?: [];
        $historyStmt->close();
        $historicalOrders = intval($history['historical_orders'] ?? 0);

        if ($historicalOrders >= 10) {
            $alerts[] = [
                'alert_key' => 'no_sales_3d',
                'severity' => 'medium',
                'title' => 'No sales in last 3 days',
                'details' => 'Business has historical activity but no recent orders.',
                'metric_value' => 0,
                'threshold_value' => 1,
                'alert_date' => $today,
                'context' => [
                    'historical_orders' => $historicalOrders
                ]
            ];
        }
    }

    return $alerts;
}

function upsert_operations_alerts(mysqli $conn, int $businessId, array $alerts): array {
    $inserted = 0;
    $updated = 0;
    $keys = [];
    $today = (new DateTime('today'))->format('Y-m-d');

    $upsertStmt = $conn->prepare(
        "INSERT INTO operations_alerts
            (business_id, alert_key, severity, title, details, metric_value, threshold_value, alert_date, status, context_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)
         ON DUPLICATE KEY UPDATE
            severity = VALUES(severity),
            title = VALUES(title),
            details = VALUES(details),
            metric_value = VALUES(metric_value),
            threshold_value = VALUES(threshold_value),
            status = 'open',
            context_json = VALUES(context_json),
            acknowledged_at = NULL,
            acknowledged_by_user_id = 0,
            acknowledged_by_username = '',
            last_detected_at = CURRENT_TIMESTAMP"
    );

    foreach ($alerts as $alert) {
        $alertKey = strtolower(trim((string)($alert['alert_key'] ?? '')));
        if ($alertKey === '') {
            continue;
        }
        $keys[] = $alertKey;
        $severity = strtolower(trim((string)($alert['severity'] ?? 'medium')));
        if (!in_array($severity, ['low', 'medium', 'high'], true)) {
            $severity = 'medium';
        }
        $title = trim((string)($alert['title'] ?? 'Operational Alert'));
        $details = trim((string)($alert['details'] ?? ''));
        if (strlen($title) > 180) {
            $title = substr($title, 0, 180);
        }
        if (strlen($details) > 500) {
            $details = substr($details, 0, 500);
        }
        $metricValue = round(floatval($alert['metric_value'] ?? 0), 4);
        $thresholdValue = round(floatval($alert['threshold_value'] ?? 0), 4);
        $alertDate = trim((string)($alert['alert_date'] ?? $today));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $alertDate)) {
            $alertDate = $today;
        }
        $contextJson = json_encode($alert['context'] ?? [], JSON_UNESCAPED_SLASHES);
        if ($contextJson === false) {
            $contextJson = '{}';
        }

        $upsertStmt->bind_param(
            'issssddss',
            $businessId,
            $alertKey,
            $severity,
            $title,
            $details,
            $metricValue,
            $thresholdValue,
            $alertDate,
            $contextJson
        );
        $upsertStmt->execute();
        if ($upsertStmt->affected_rows === 1) {
            $inserted++;
        } elseif ($upsertStmt->affected_rows > 1) {
            $updated++;
        }
    }
    $upsertStmt->close();

    if (count($keys) > 0) {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $types = str_repeat('s', count($keys));
        $params = $keys;
        $sql = "UPDATE operations_alerts
                SET status = 'resolved'
                WHERE business_id = ?
                  AND alert_date = ?
                  AND status = 'open'
                  AND alert_key NOT IN (" . $placeholders . ")";
        $stmt = $conn->prepare($sql);
        $bindTypes = 'is' . $types;
        $bindValues = array_merge([$businessId, $today], $params);
        bind_dynamic_params($stmt, $bindTypes, $bindValues);
        $stmt->execute();
        $resolved = intval($stmt->affected_rows);
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "UPDATE operations_alerts
             SET status = 'resolved'
             WHERE business_id = ? AND alert_date = ? AND status = 'open'"
        );
        $stmt->bind_param('is', $businessId, $today);
        $stmt->execute();
        $resolved = intval($stmt->affected_rows);
        $stmt->close();
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
        'resolved' => $resolved
    ];
}

try {
    ensure_multitenant_schema($conn);
    ensure_phase3_tracking_schema($conn);
    ensure_operations_alerts_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        respond(false, 'Invalid business context. Please sign in again.', [], 401);
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        $action = strtolower(trim((string)($body['action'] ?? 'scan_now')));
        if ($action === 'scan_now') {
            $lowStockThreshold = isset($body['low_stock_threshold']) ? intval($body['low_stock_threshold']) : 5;
            if ($lowStockThreshold < 0) {
                $lowStockThreshold = 0;
            }
            if ($lowStockThreshold > 1000) {
                $lowStockThreshold = 1000;
            }

            $alerts = detect_operations_alerts($conn, $businessId, $lowStockThreshold);
            $results = upsert_operations_alerts($conn, $businessId, $alerts);

            tracking_log_business_event(
                $conn,
                $businessId,
                'operations_alerts.scan',
                'operations_alert',
                0,
                [
                    'low_stock_threshold' => $lowStockThreshold,
                    'detected_count' => count($alerts),
                    'upsert' => $results
                ],
                tracking_actor_user_id(),
                tracking_actor_username()
            );

            respond(true, 'Operations scan completed.', [
                'scan' => [
                    'detected_count' => count($alerts),
                    'inserted' => intval($results['inserted'] ?? 0),
                    'updated' => intval($results['updated'] ?? 0),
                    'resolved' => intval($results['resolved'] ?? 0)
                ],
                'detected_alerts' => $alerts
            ]);
        }

        if ($action === 'acknowledge') {
            $alertId = intval($body['alert_id'] ?? 0);
            if ($alertId <= 0) {
                respond(false, 'Valid alert_id is required.', [], 422);
            }

            $actorUserId = tracking_actor_user_id();
            $actorUsername = tracking_actor_username();
            $stmt = $conn->prepare(
                "UPDATE operations_alerts
                 SET status = 'acknowledged',
                     acknowledged_at = NOW(),
                     acknowledged_by_user_id = ?,
                     acknowledged_by_username = ?
                 WHERE id = ? AND business_id = ?"
            );
            $stmt->bind_param('isii', $actorUserId, $actorUsername, $alertId, $businessId);
            $stmt->execute();
            $affected = intval($stmt->affected_rows);
            $stmt->close();
            if ($affected <= 0) {
                respond(false, 'Alert not found or already updated.', [], 404);
            }

            tracking_log_business_event(
                $conn,
                $businessId,
                'operations_alerts.acknowledge',
                'operations_alert',
                $alertId,
                [],
                $actorUserId,
                $actorUsername
            );

            respond(true, 'Alert acknowledged.');
        }

        if ($action === 'acknowledge_all_open') {
            $actorUserId = tracking_actor_user_id();
            $actorUsername = tracking_actor_username();
            $today = (new DateTime('today'))->format('Y-m-d');

            $stmt = $conn->prepare(
                "UPDATE operations_alerts
                 SET status = 'acknowledged',
                     acknowledged_at = NOW(),
                     acknowledged_by_user_id = ?,
                     acknowledged_by_username = ?
                 WHERE business_id = ?
                   AND status = 'open'
                   AND alert_date = ?"
            );
            $stmt->bind_param('isis', $actorUserId, $actorUsername, $businessId, $today);
            $stmt->execute();
            $affected = intval($stmt->affected_rows);
            $stmt->close();

            tracking_log_business_event(
                $conn,
                $businessId,
                'operations_alerts.acknowledge_all_open',
                'operations_alert',
                0,
                [
                    'affected' => $affected,
                    'alert_date' => $today
                ],
                $actorUserId,
                $actorUsername
            );

            respond(true, 'Open alerts acknowledged.', [
                'affected' => $affected
            ]);
        }

        respond(false, 'Invalid action.', [], 422);
    }

    if ($method !== 'GET') {
        respond(false, 'Method not allowed.', [], 405);
    }

    $today = new DateTime('today');
    $fromRaw = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $toRaw = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
    if ($fromRaw === '' || $toRaw === '') {
        $fromDate = (clone $today)->modify('-13 days');
        $toDate = clone $today;
    } else {
        $fromDate = parse_ymd_date($fromRaw);
        $toDate = parse_ymd_date($toRaw);
        if (!$fromDate || !$toDate) {
            respond(false, 'Invalid date format. Use YYYY-MM-DD.', [], 422);
        }
    }
    if ($fromDate > $toDate) {
        $tmp = $fromDate;
        $fromDate = $toDate;
        $toDate = $tmp;
    }

    $statusFilter = strtolower(trim((string)($_GET['status'] ?? 'open')));
    if (!in_array($statusFilter, ['open', 'acknowledged', 'resolved', 'all'], true)) {
        $statusFilter = 'open';
    }
    $severityFilter = strtolower(trim((string)($_GET['severity'] ?? 'all')));
    if (!in_array($severityFilter, ['low', 'medium', 'high', 'all'], true)) {
        $severityFilter = 'all';
    }

    $limit = intval($_GET['limit'] ?? 200);
    if ($limit <= 0) {
        $limit = 200;
    }
    if ($limit > 1000) {
        $limit = 1000;
    }

    $fromSql = $fromDate->format('Y-m-d');
    $toSql = $toDate->format('Y-m-d');
    $where = "WHERE business_id = ? AND alert_date >= ? AND alert_date <= ?";
    $types = 'iss';
    $values = [$businessId, $fromSql, $toSql];

    if ($statusFilter !== 'all') {
        $where .= " AND status = ?";
        $types .= 's';
        $values[] = $statusFilter;
    }
    if ($severityFilter !== 'all') {
        $where .= " AND severity = ?";
        $types .= 's';
        $values[] = $severityFilter;
    }

    $listSql =
        "SELECT
            id,
            alert_key,
            severity,
            title,
            details,
            metric_value,
            threshold_value,
            alert_date,
            status,
            first_detected_at,
            last_detected_at,
            acknowledged_at,
            acknowledged_by_user_id,
            acknowledged_by_username,
            context_json
         FROM operations_alerts
         " . $where . "
         ORDER BY
            CASE severity
                WHEN 'high' THEN 1
                WHEN 'medium' THEN 2
                ELSE 3
            END,
            alert_date DESC,
            id DESC
         LIMIT ?";
    $listTypes = $types . 'i';
    $listValues = $values;
    $listValues[] = $limit;
    $listStmt = $conn->prepare($listSql);
    bind_dynamic_params($listStmt, $listTypes, $listValues);
    $listStmt->execute();
    $result = $listStmt->get_result();
    $alerts = [];
    while ($row = $result->fetch_assoc()) {
        $contextRaw = (string)($row['context_json'] ?? '');
        $context = json_decode($contextRaw, true);
        if (!is_array($context)) {
            $context = [];
        }
        $row['context'] = $context;
        unset($row['context_json']);
        $alerts[] = $row;
    }
    $listStmt->close();

    $summarySql =
        "SELECT
            COUNT(*) AS total_alerts,
            COALESCE(SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END), 0) AS open_alerts,
            COALESCE(SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END), 0) AS acknowledged_alerts,
            COALESCE(SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END), 0) AS resolved_alerts,
            COALESCE(SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END), 0) AS high_alerts,
            COALESCE(SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END), 0) AS medium_alerts,
            COALESCE(SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END), 0) AS low_alerts
         FROM operations_alerts
         WHERE business_id = ? AND alert_date >= ? AND alert_date <= ?";
    $summaryStmt = $conn->prepare($summarySql);
    $summaryStmt->bind_param('iss', $businessId, $fromSql, $toSql);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    respond(true, '', [
        'range' => [
            'from' => $fromSql,
            'to' => $toSql
        ],
        'filters' => [
            'status' => $statusFilter,
            'severity' => $severityFilter
        ],
        'summary' => [
            'total_alerts' => intval($summary['total_alerts'] ?? 0),
            'open_alerts' => intval($summary['open_alerts'] ?? 0),
            'acknowledged_alerts' => intval($summary['acknowledged_alerts'] ?? 0),
            'resolved_alerts' => intval($summary['resolved_alerts'] ?? 0),
            'high_alerts' => intval($summary['high_alerts'] ?? 0),
            'medium_alerts' => intval($summary['medium_alerts'] ?? 0),
            'low_alerts' => intval($summary['low_alerts'] ?? 0)
        ],
        'alerts' => $alerts
    ]);
} catch (Exception $e) {
    error_log('operations-alerts.php: ' . $e->getMessage());
    respond(false, 'Unable to load operations alerts right now.', [], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
