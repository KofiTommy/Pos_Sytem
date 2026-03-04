<?php
header('Content-Type: application/json');
include_once __DIR__ . '/hq-auth.php';
hq_require_api();
include __DIR__ . '/db-connection.php';
include __DIR__ . '/tenant-context.php';

const HQ_ALERT_WORKFLOW_STATUSES = ['open', 'acknowledged', 'resolved'];

function respond(bool $success, string $message = '', array $extra = [], int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function parse_ymd_date(?string $rawDate): ?DateTime {
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

function hq_alert_key(string $businessCode, string $title, string $detail): string {
    $raw = strtolower(trim($businessCode) . '|' . trim($title) . '|' . trim($detail));
    return substr(hash('sha256', $raw), 0, 24);
}

function build_shop_alerts(array $shops): array {
    $alerts = [];

    foreach ($shops as $shop) {
        $businessName = (string)($shop['business_name'] ?? '');
        $businessCode = (string)($shop['business_code'] ?? '');
        $ordersCount = intval($shop['orders_count'] ?? 0);
        $pendingRate = floatval($shop['pending_rate'] ?? 0);
        $issueRate = floatval($shop['issue_rate'] ?? 0);
        $lowStockCount = intval($shop['low_stock_count'] ?? 0);
        $stockoutCount = intval($shop['stockout_count'] ?? 0);
        $newMessages = intval($shop['new_messages'] ?? 0);
        $status = strtolower(trim((string)($shop['status'] ?? '')));

        $pushAlert = function (string $severity, string $title, string $detail) use (&$alerts, $businessCode, $businessName): void {
            $alerts[] = [
                'alert_key' => hq_alert_key($businessCode, $title, $detail),
                'severity' => $severity,
                'business_code' => $businessCode,
                'business_name' => $businessName,
                'title' => $title,
                'detail' => $detail,
                'workflow_status' => 'open',
                'workflow_updated_by' => '',
                'workflow_updated_at' => ''
            ];
        };

        if ($ordersCount >= 5 && $pendingRate > 0.2) {
            $pushAlert(
                $pendingRate >= 0.35 ? 'high' : 'medium',
                'High pending-order ratio',
                'Pending order ratio is above expected baseline for this period.'
            );
        }

        if ($ordersCount >= 5 && $issueRate > 0.08) {
            $pushAlert(
                $issueRate >= 0.15 ? 'high' : 'medium',
                'High cancellation/refund rate',
                'Cancelled and refunded orders are elevated for this period.'
            );
        }

        if ($stockoutCount >= 10 || $lowStockCount >= 25) {
            $pushAlert(
                $stockoutCount >= 10 ? 'high' : 'medium',
                'Inventory risk',
                'Stockouts or low-stock product count needs intervention.'
            );
        }

        if ($newMessages >= 10) {
            $pushAlert(
                'medium',
                'Customer message backlog',
                'Unresolved customer messages are accumulating.'
            );
        }

        if ($status === 'active' && $ordersCount === 0) {
            $pushAlert(
                'info',
                'No sales in selected range',
                'Active shop has zero orders in current report range.'
            );
        }
    }

    $severityWeight = [
        'high' => 3,
        'medium' => 2,
        'info' => 1
    ];

    usort($alerts, function ($a, $b) use ($severityWeight) {
        $aw = intval($severityWeight[strtolower((string)($a['severity'] ?? 'info'))] ?? 1);
        $bw = intval($severityWeight[strtolower((string)($b['severity'] ?? 'info'))] ?? 1);
        if ($aw !== $bw) {
            return $bw <=> $aw;
        }
        return strcmp((string)($a['business_name'] ?? ''), (string)($b['business_name'] ?? ''));
    });

    return array_slice($alerts, 0, 40);
}

function hq_ensure_alert_workflow_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS hq_alert_workflow (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            alert_key VARCHAR(64) NOT NULL,
            business_code VARCHAR(64) NOT NULL DEFAULT '',
            title VARCHAR(190) NOT NULL DEFAULT '',
            detail VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            updated_by VARCHAR(120) NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_hq_alert_key (alert_key),
            INDEX idx_hq_alert_status_time (status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function hq_apply_alert_workflow(mysqli $conn, array $alerts): array {
    if (count($alerts) === 0) {
        return $alerts;
    }

    $keys = [];
    foreach ($alerts as $alert) {
        $key = trim((string)($alert['alert_key'] ?? ''));
        if ($key !== '') {
            $keys[] = $key;
        }
    }
    $keys = array_values(array_unique($keys));
    if (count($keys) === 0) {
        return $alerts;
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $types = str_repeat('s', count($keys));
    $sql =
        "SELECT
            alert_key,
            status,
            updated_by,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
         FROM hq_alert_workflow
         WHERE alert_key IN (" . $placeholders . ")";

    $stmt = $conn->prepare($sql);
    bind_dynamic_params($stmt, $types, $keys);
    $stmt->execute();
    $result = $stmt->get_result();

    $stateMap = [];
    while ($row = $result->fetch_assoc()) {
        $alertKey = trim((string)($row['alert_key'] ?? ''));
        if ($alertKey === '') {
            continue;
        }

        $status = strtolower(trim((string)($row['status'] ?? 'open')));
        if (!in_array($status, HQ_ALERT_WORKFLOW_STATUSES, true)) {
            $status = 'open';
        }

        $stateMap[$alertKey] = [
            'workflow_status' => $status,
            'workflow_updated_by' => (string)($row['updated_by'] ?? ''),
            'workflow_updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    $stmt->close();

    foreach ($alerts as &$alert) {
        $alertKey = trim((string)($alert['alert_key'] ?? ''));
        if ($alertKey === '' || !isset($stateMap[$alertKey])) {
            continue;
        }
        $alert['workflow_status'] = (string)($stateMap[$alertKey]['workflow_status'] ?? 'open');
        $alert['workflow_updated_by'] = (string)($stateMap[$alertKey]['workflow_updated_by'] ?? '');
        $alert['workflow_updated_at'] = (string)($stateMap[$alertKey]['workflow_updated_at'] ?? '');
    }
    unset($alert);

    return $alerts;
}

try {
    $requiredTables = ['businesses', 'users', 'orders', 'products'];
    foreach ($requiredTables as $requiredTable) {
        if (!tenant_table_exists($conn, $requiredTable)) {
            respond(false, 'Required table is missing: ' . $requiredTable, [], 503);
        }
    }

    $from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $to = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
    $lowStockThreshold = isset($_GET['low_stock']) ? intval($_GET['low_stock']) : 5;
    if ($lowStockThreshold < 0) {
        $lowStockThreshold = 0;
    }
    if ($lowStockThreshold > 1000) {
        $lowStockThreshold = 1000;
    }

    $today = new DateTime('today');
    if ($from === '' || $to === '') {
        $fromDate = (clone $today)->modify('-29 days');
        $toDate = clone $today;
    } else {
        $fromDate = parse_ymd_date($from);
        $toDate = parse_ymd_date($to);
        if (!$fromDate || !$toDate) {
            respond(false, 'Invalid date format. Use YYYY-MM-DD.', [], 422);
        }
    }

    if ($fromDate > $toDate) {
        $tmp = $fromDate;
        $fromDate = $toDate;
        $toDate = $tmp;
    }

    $fromSql = $fromDate->format('Y-m-d');
    $toSql = $toDate->format('Y-m-d');
    $rangeStart = $fromSql . ' 00:00:00';
    $rangeEndExclusive = (clone $toDate)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $todayStart = $today->format('Y-m-d') . ' 00:00:00';
    $todayEndExclusive = (clone $today)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $threeDaysAgo = (new DateTime('now'))->modify('-3 days')->format('Y-m-d H:i:s');

    $hasContactTable = tenant_table_exists($conn, 'contact_messages');
    $hasVisitorsTable = tenant_table_exists($conn, 'store_visitors');

    $businessStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS registered_businesses,
            COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_businesses
         FROM businesses"
    );
    $businessStmt->execute();
    $businessSummary = $businessStmt->get_result()->fetch_assoc() ?: [];
    $businessStmt->close();

    $salesSummaryStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS orders_count,
            COALESCE(SUM(total), 0) AS gross_sales,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END), 0) AS paid_sales
         FROM orders
         WHERE created_at >= ? AND created_at < ?"
    );
    $salesSummaryStmt->bind_param('ss', $rangeStart, $rangeEndExclusive);
    $salesSummaryStmt->execute();
    $salesSummary = $salesSummaryStmt->get_result()->fetch_assoc() ?: [];
    $salesSummaryStmt->close();

    $pendingStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS pending_orders_count,
            COALESCE(SUM(total), 0) AS pending_orders_value
         FROM orders
         WHERE status = 'pending' AND created_at >= ? AND created_at < ?"
    );
    $pendingStmt->bind_param('ss', $rangeStart, $rangeEndExclusive);
    $pendingStmt->execute();
    $pendingSummary = $pendingStmt->get_result()->fetch_assoc() ?: [];
    $pendingStmt->close();

    $sellingTodayStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT business_id) AS shops_selling_today
         FROM orders
         WHERE created_at >= ? AND created_at < ?"
    );
    $sellingTodayStmt->bind_param('ss', $todayStart, $todayEndExclusive);
    $sellingTodayStmt->execute();
    $sellingToday = $sellingTodayStmt->get_result()->fetch_assoc() ?: [];
    $sellingTodayStmt->close();

    $stockSummaryStmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN stock <= ? THEN 1 ELSE 0 END), 0) AS low_stock_products,
            COALESCE(SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END), 0) AS stockout_products
         FROM products"
    );
    $stockSummaryStmt->bind_param('i', $lowStockThreshold);
    $stockSummaryStmt->execute();
    $stockSummary = $stockSummaryStmt->get_result()->fetch_assoc() ?: [];
    $stockSummaryStmt->close();

    $newMessages = 0;
    if ($hasContactTable) {
        $messageSummaryStmt = $conn->prepare(
            "SELECT COUNT(*) AS new_messages
             FROM contact_messages
             WHERE status = 'new'"
        );
        $messageSummaryStmt->execute();
        $messageSummary = $messageSummaryStmt->get_result()->fetch_assoc() ?: [];
        $messageSummaryStmt->close();
        $newMessages = intval($messageSummary['new_messages'] ?? 0);
    }

    $noSalesStmt = $conn->prepare(
        "SELECT COUNT(*) AS no_sales_3d
         FROM businesses b
         WHERE b.status = 'active'
           AND NOT EXISTS (
               SELECT 1
               FROM orders o
               WHERE o.business_id = b.id
                 AND o.created_at >= ?
           )"
    );
    $noSalesStmt->bind_param('s', $threeDaysAgo);
    $noSalesStmt->execute();
    $noSalesSummary = $noSalesStmt->get_result()->fetch_assoc() ?: [];
    $noSalesStmt->close();

    $visitorSelect = $hasVisitorsTable ? "COALESCE(vs.unique_visitors, 0) AS unique_visitors" : "0 AS unique_visitors";
    $messageSelect = $hasContactTable ? "COALESCE(ms.new_messages, 0) AS new_messages" : "0 AS new_messages";
    $visitorJoin = $hasVisitorsTable
        ? "LEFT JOIN (
                SELECT business_id, COUNT(DISTINCT visitor_key) AS unique_visitors
                FROM store_visitors
                WHERE visit_date >= ? AND visit_date <= ?
                GROUP BY business_id
            ) vs ON vs.business_id = b.id"
        : '';
    $messageJoin = $hasContactTable
        ? "LEFT JOIN (
                SELECT business_id, COUNT(*) AS new_messages
                FROM contact_messages
                WHERE status = 'new'
                GROUP BY business_id
            ) ms ON ms.business_id = b.id"
        : '';

    $scoreboardSql =
        "SELECT
            b.id AS business_id,
            b.business_code,
            b.business_name,
            b.business_email,
            b.status,
            b.subscription_plan,
            COALESCE(owner.owner_username, '') AS owner_username,
            COALESCE(owner.owner_email, '') AS owner_email,
            COALESCE(os.orders_count, 0) AS orders_count,
            COALESCE(os.gross_sales, 0) AS gross_sales,
            COALESCE(os.paid_sales, 0) AS paid_sales,
            COALESCE(os.pending_orders, 0) AS pending_orders,
            COALESCE(os.pending_value, 0) AS pending_value,
            COALESCE(os.cancelled_orders, 0) AS cancelled_orders,
            COALESCE(os.refunded_orders, 0) AS refunded_orders,
            COALESCE(ss.low_stock_count, 0) AS low_stock_count,
            COALESCE(ss.stockout_count, 0) AS stockout_count,
            " . $visitorSelect . ",
            " . $messageSelect . "
         FROM businesses b
         LEFT JOIN (
            SELECT u.business_id, u.username AS owner_username, u.email AS owner_email
            FROM users u
            INNER JOIN (
                SELECT business_id, MIN(id) AS owner_id
                FROM users
                WHERE LOWER(CASE WHEN role = 'admin' THEN 'owner' ELSE role END) = 'owner'
                GROUP BY business_id
            ) owner_min ON owner_min.owner_id = u.id
         ) owner ON owner.business_id = b.id
         LEFT JOIN (
            SELECT
                business_id,
                COUNT(*) AS orders_count,
                COALESCE(SUM(total), 0) AS gross_sales,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END), 0) AS paid_sales,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_orders,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN total ELSE 0 END), 0) AS pending_value,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
                COALESCE(SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END), 0) AS refunded_orders
            FROM orders
            WHERE created_at >= ? AND created_at < ?
            GROUP BY business_id
         ) os ON os.business_id = b.id
         LEFT JOIN (
            SELECT
                business_id,
                COALESCE(SUM(CASE WHEN stock <= ? THEN 1 ELSE 0 END), 0) AS low_stock_count,
                COALESCE(SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END), 0) AS stockout_count
            FROM products
            GROUP BY business_id
         ) ss ON ss.business_id = b.id
         " . $visitorJoin . "
         " . $messageJoin . "
         ORDER BY
            CASE WHEN b.status = 'active' THEN 0 ELSE 1 END,
            b.business_name ASC";

    $scoreboardStmt = $conn->prepare($scoreboardSql);
    $scoreboardTypes = 'ssi';
    $scoreboardValues = [$rangeStart, $rangeEndExclusive, $lowStockThreshold];
    if ($hasVisitorsTable) {
        $scoreboardTypes .= 'ss';
        $scoreboardValues[] = $fromSql;
        $scoreboardValues[] = $toSql;
    }
    bind_dynamic_params($scoreboardStmt, $scoreboardTypes, $scoreboardValues);
    $scoreboardStmt->execute();
    $scoreboardResult = $scoreboardStmt->get_result();

    $scoreboard = [];
    while ($row = $scoreboardResult->fetch_assoc()) {
        $ordersCount = intval($row['orders_count'] ?? 0);
        $grossSales = floatval($row['gross_sales'] ?? 0);
        $paidSales = floatval($row['paid_sales'] ?? 0);
        $pendingOrders = intval($row['pending_orders'] ?? 0);
        $cancelledOrders = intval($row['cancelled_orders'] ?? 0);
        $refundedOrders = intval($row['refunded_orders'] ?? 0);
        $uniqueVisitors = intval($row['unique_visitors'] ?? 0);

        $avgOrderValue = $ordersCount > 0 ? ($grossSales / $ordersCount) : 0.0;
        $paidSalesRatio = $grossSales > 0 ? ($paidSales / $grossSales) : 0.0;
        $pendingRate = $ordersCount > 0 ? ($pendingOrders / $ordersCount) : 0.0;
        $cancelRate = $ordersCount > 0 ? ($cancelledOrders / $ordersCount) : 0.0;
        $refundRate = $ordersCount > 0 ? ($refundedOrders / $ordersCount) : 0.0;
        $issueRate = $cancelRate + $refundRate;
        $conversionRate = $uniqueVisitors > 0 ? ($ordersCount / $uniqueVisitors) : 0.0;

        $riskScore = 0;
        if ($ordersCount >= 5 && $pendingRate > 0.2) {
            $riskScore += 1;
        }
        if ($ordersCount >= 5 && $issueRate > 0.08) {
            $riskScore += 2;
        }
        if (intval($row['stockout_count'] ?? 0) >= 10 || intval($row['low_stock_count'] ?? 0) >= 25) {
            $riskScore += 1;
        }
        if (strtolower((string)($row['status'] ?? '')) === 'active' && $ordersCount === 0) {
            $riskScore += 1;
        }

        $riskLevel = 'green';
        if ($riskScore >= 3) {
            $riskLevel = 'red';
        } elseif ($riskScore >= 2) {
            $riskLevel = 'amber';
        }

        $row['orders_count'] = $ordersCount;
        $row['gross_sales'] = round($grossSales, 2);
        $row['paid_sales'] = round($paidSales, 2);
        $row['pending_orders'] = $pendingOrders;
        $row['pending_value'] = round(floatval($row['pending_value'] ?? 0), 2);
        $row['cancelled_orders'] = $cancelledOrders;
        $row['refunded_orders'] = $refundedOrders;
        $row['low_stock_count'] = intval($row['low_stock_count'] ?? 0);
        $row['stockout_count'] = intval($row['stockout_count'] ?? 0);
        $row['unique_visitors'] = $uniqueVisitors;
        $row['new_messages'] = intval($row['new_messages'] ?? 0);
        $row['avg_order_value'] = round($avgOrderValue, 2);
        $row['paid_sales_ratio'] = round($paidSalesRatio, 4);
        $row['pending_rate'] = round($pendingRate, 4);
        $row['cancel_rate'] = round($cancelRate, 4);
        $row['refund_rate'] = round($refundRate, 4);
        $row['issue_rate'] = round($issueRate, 4);
        $row['conversion_rate'] = round($conversionRate, 4);
        $row['risk_score'] = $riskScore;
        $row['risk_level'] = $riskLevel;

        $scoreboard[] = $row;
    }
    $scoreboardStmt->close();

    usort($scoreboard, function ($a, $b) {
        $riskCmp = intval($b['risk_score'] ?? 0) <=> intval($a['risk_score'] ?? 0);
        if ($riskCmp !== 0) {
            return $riskCmp;
        }
        return strcmp((string)($a['business_name'] ?? ''), (string)($b['business_name'] ?? ''));
    });

    $alerts = build_shop_alerts($scoreboard);
    hq_ensure_alert_workflow_table($conn);
    $alerts = hq_apply_alert_workflow($conn, $alerts);
    usort($alerts, function ($a, $b) {
        $statusWeight = ['open' => 3, 'acknowledged' => 2, 'resolved' => 1];
        $severityWeight = ['high' => 3, 'medium' => 2, 'info' => 1];

        $aStatus = strtolower((string)($a['workflow_status'] ?? 'open'));
        $bStatus = strtolower((string)($b['workflow_status'] ?? 'open'));
        $statusCmp = intval($statusWeight[$bStatus] ?? 0) <=> intval($statusWeight[$aStatus] ?? 0);
        if ($statusCmp !== 0) {
            return $statusCmp;
        }

        $aSeverity = strtolower((string)($a['severity'] ?? 'info'));
        $bSeverity = strtolower((string)($b['severity'] ?? 'info'));
        $severityCmp = intval($severityWeight[$bSeverity] ?? 0) <=> intval($severityWeight[$aSeverity] ?? 0);
        if ($severityCmp !== 0) {
            return $severityCmp;
        }

        return strcmp((string)($a['business_name'] ?? ''), (string)($b['business_name'] ?? ''));
    });

    $trendStmt = $conn->prepare(
        "SELECT
            DATE(created_at) AS metric_day,
            COUNT(*) AS orders_count,
            COALESCE(SUM(total), 0) AS gross_sales,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END), 0) AS paid_sales
         FROM orders
         WHERE created_at >= ? AND created_at < ?
         GROUP BY DATE(created_at)
         ORDER BY metric_day ASC"
    );
    $trendStmt->bind_param('ss', $rangeStart, $rangeEndExclusive);
    $trendStmt->execute();
    $trendResult = $trendStmt->get_result();
    $trendByDay = [];
    while ($row = $trendResult->fetch_assoc()) {
        $metricDay = (string)($row['metric_day'] ?? '');
        if ($metricDay === '') {
            continue;
        }
        $trendByDay[$metricDay] = [
            'orders' => intval($row['orders_count'] ?? 0),
            'gross_sales' => round(floatval($row['gross_sales'] ?? 0), 2),
            'paid_sales' => round(floatval($row['paid_sales'] ?? 0), 2)
        ];
    }
    $trendStmt->close();

    $trendLabels = [];
    $trendOrders = [];
    $trendGrossSales = [];
    $trendPaidSales = [];
    $cursorDate = clone $fromDate;
    while ($cursorDate <= $toDate) {
        $day = $cursorDate->format('Y-m-d');
        $point = $trendByDay[$day] ?? ['orders' => 0, 'gross_sales' => 0.0, 'paid_sales' => 0.0];
        $trendLabels[] = $day;
        $trendOrders[] = intval($point['orders'] ?? 0);
        $trendGrossSales[] = round(floatval($point['gross_sales'] ?? 0), 2);
        $trendPaidSales[] = round(floatval($point['paid_sales'] ?? 0), 2);
        $cursorDate->modify('+1 day');
    }

    $ordersCount = intval($salesSummary['orders_count'] ?? 0);
    $grossSales = floatval($salesSummary['gross_sales'] ?? 0);
    $paidSales = floatval($salesSummary['paid_sales'] ?? 0);

    respond(true, '', [
        'range' => [
            'from' => $fromSql,
            'to' => $toSql
        ],
        'low_stock_threshold' => $lowStockThreshold,
        'overview' => [
            'registered_businesses' => intval($businessSummary['registered_businesses'] ?? 0),
            'active_businesses' => intval($businessSummary['active_businesses'] ?? 0),
            'shops_selling_today' => intval($sellingToday['shops_selling_today'] ?? 0),
            'orders_count' => $ordersCount,
            'gross_sales' => round($grossSales, 2),
            'paid_sales' => round($paidSales, 2),
            'avg_order_value' => $ordersCount > 0 ? round($grossSales / $ordersCount, 2) : 0.0,
            'pending_orders_count' => intval($pendingSummary['pending_orders_count'] ?? 0),
            'pending_orders_value' => round(floatval($pendingSummary['pending_orders_value'] ?? 0), 2),
            'low_stock_products' => intval($stockSummary['low_stock_products'] ?? 0),
            'stockout_products' => intval($stockSummary['stockout_products'] ?? 0),
            'new_messages' => $newMessages,
            'no_sales_3d' => intval($noSalesSummary['no_sales_3d'] ?? 0)
        ],
        'trends' => [
            'labels' => $trendLabels,
            'orders' => $trendOrders,
            'gross_sales' => $trendGrossSales,
            'paid_sales' => $trendPaidSales
        ],
        'scoreboard' => $scoreboard,
        'alerts' => $alerts,
        'meta' => [
            'has_contact_messages_table' => $hasContactTable,
            'has_store_visitors_table' => $hasVisitorsTable,
            'generated_at' => gmdate('c')
        ]
    ]);
} catch (Exception $e) {
    error_log('hq-dashboard-data.php: ' . $e->getMessage());
    respond(false, 'Unable to load HQ dashboard data right now.', [], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
