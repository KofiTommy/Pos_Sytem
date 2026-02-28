<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner']);
include 'db-connection.php';
include 'tenant-context.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

try {
    ensure_multitenant_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        respond(false, 'Invalid business context. Please sign in again.');
    }

    $from = isset($_GET['from']) ? trim($_GET['from']) : '';
    $to = isset($_GET['to']) ? trim($_GET['to']) : '';
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
        $fromDate = DateTime::createFromFormat('Y-m-d', $from);
        $toDate = DateTime::createFromFormat('Y-m-d', $to);
        if (!$fromDate || !$toDate) {
            respond(false, 'Invalid date format. Use YYYY-MM-DD');
        }
    }

    if ($fromDate > $toDate) {
        $temp = $fromDate;
        $fromDate = $toDate;
        $toDate = $temp;
    }

    $fromSql = $fromDate->format('Y-m-d');
    $toSql = $toDate->format('Y-m-d');
    $rangeStart = $fromSql . ' 00:00:00';
    $rangeEndExclusive = (clone $toDate)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $summaryStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS orders_count,
            COALESCE(SUM(total), 0) AS gross_sales,
            COALESCE(AVG(total), 0) AS avg_order_value
         FROM orders
         WHERE business_id = ? AND created_at >= ? AND created_at < ?"
    );
    $summaryStmt->bind_param('iss', $businessId, $rangeStart, $rangeEndExclusive);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();

    $todaySql = $today->format('Y-m-d');
    $todayStart = $todaySql . ' 00:00:00';
    $todayEndExclusive = (clone $today)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $todayStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS orders_today,
            COALESCE(SUM(total), 0) AS sales_today
         FROM orders
         WHERE business_id = ? AND created_at >= ? AND created_at < ?"
    );
    $todayStmt->bind_param('iss', $businessId, $todayStart, $todayEndExclusive);
    $todayStmt->execute();
    $todaySummary = $todayStmt->get_result()->fetch_assoc();
    $todayStmt->close();

    $productStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS products_count,
            COALESCE(SUM(stock), 0) AS units_in_stock
         FROM products
         WHERE business_id = ?"
    );
    $productStmt->bind_param('i', $businessId);
    $productStmt->execute();
    $productSummary = $productStmt->get_result()->fetch_assoc();
    $productStmt->close();

    $lowStockStmt = $conn->prepare(
        "SELECT id, name, stock, category
         FROM products
         WHERE business_id = ? AND stock <= ?
         ORDER BY stock ASC, name ASC
         LIMIT 15"
    );
    $lowStockStmt->bind_param('ii', $businessId, $lowStockThreshold);
    $lowStockStmt->execute();
    $lowStockResult = $lowStockStmt->get_result();
    $lowStockProducts = [];
    while ($row = $lowStockResult->fetch_assoc()) {
        $lowStockProducts[] = $row;
    }
    $lowStockStmt->close();

    $dailyStmt = $conn->prepare(
        "SELECT
            DATE(created_at) AS sales_date,
            COUNT(*) AS orders_count,
            COALESCE(SUM(total), 0) AS gross_sales
         FROM orders
         WHERE business_id = ? AND created_at >= ? AND created_at < ?
         GROUP BY DATE(created_at)
         ORDER BY sales_date ASC"
    );
    $dailyStmt->bind_param('iss', $businessId, $rangeStart, $rangeEndExclusive);
    $dailyStmt->execute();
    $dailyResult = $dailyStmt->get_result();
    $dailySales = [];
    while ($row = $dailyResult->fetch_assoc()) {
        $dailySales[] = $row;
    }
    $dailyStmt->close();

    $topProductsStmt = $conn->prepare(
        "SELECT
            oi.product_id,
            COALESCE(NULLIF(oi.product_name, ''), p.name) AS product_name,
            COALESCE(p.category, '-') AS category,
            SUM(oi.quantity) AS units_sold,
            SUM(oi.quantity * oi.price) AS gross_sales
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id AND o.business_id = oi.business_id
         LEFT JOIN products p ON p.id = oi.product_id AND p.business_id = oi.business_id
         WHERE o.business_id = ? AND o.created_at >= ? AND o.created_at < ?
         GROUP BY oi.product_id, product_name, category
         ORDER BY units_sold DESC, gross_sales DESC
         LIMIT 10"
    );
    $topProductsStmt->bind_param('iss', $businessId, $rangeStart, $rangeEndExclusive);
    $topProductsStmt->execute();
    $topProductsResult = $topProductsStmt->get_result();
    $topProducts = [];
    while ($row = $topProductsResult->fetch_assoc()) {
        $topProducts[] = $row;
    }
    $topProductsStmt->close();

    $categoryStmt = $conn->prepare(
        "SELECT
            COALESCE(NULLIF(p.category, ''), 'Uncategorized') AS category,
            SUM(oi.quantity) AS units_sold,
            SUM(oi.quantity * oi.price) AS gross_sales
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id AND o.business_id = oi.business_id
         LEFT JOIN products p ON p.id = oi.product_id AND p.business_id = oi.business_id
         WHERE o.business_id = ? AND o.created_at >= ? AND o.created_at < ?
         GROUP BY category
         ORDER BY gross_sales DESC"
    );
    $categoryStmt->bind_param('iss', $businessId, $rangeStart, $rangeEndExclusive);
    $categoryStmt->execute();
    $categoryResult = $categoryStmt->get_result();
    $categorySales = [];
    while ($row = $categoryResult->fetch_assoc()) {
        $categorySales[] = $row;
    }
    $categoryStmt->close();

    $recentStmt = $conn->prepare(
        "SELECT
            o.id,
            o.customer_name,
            o.total,
            o.status,
            o.created_at,
            COUNT(oi.id) AS item_count
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.business_id = o.business_id
         WHERE o.business_id = ?
         GROUP BY o.id, o.customer_name, o.total, o.status, o.created_at
         ORDER BY o.id DESC
         LIMIT 12"
    );
    $recentStmt->bind_param('i', $businessId);
    $recentStmt->execute();
    $recentResult = $recentStmt->get_result();
    $recentSales = [];
    while ($row = $recentResult->fetch_assoc()) {
        $recentSales[] = $row;
    }
    $recentStmt->close();

    // Contact messages (if table exists)
    $contactMessages = [];
    $contactCounts = ['new' => 0, 'read' => 0, 'replied' => 0, 'closed' => 0];
    $hasContactTable = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'contact_messages'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $hasContactTable = true;

        $messageStmt = $conn->prepare(
            "SELECT id, name, email, subject, status, created_at
             FROM contact_messages
             WHERE business_id = ?
             ORDER BY created_at DESC
             LIMIT 8"
        );
        $messageStmt->bind_param('i', $businessId);
        $messageStmt->execute();
        $messageResult = $messageStmt->get_result();
        while ($row = $messageResult->fetch_assoc()) {
            $contactMessages[] = $row;
        }
        $messageStmt->close();

        $countStmt = $conn->prepare("SELECT status, COUNT(*) AS total FROM contact_messages WHERE business_id = ? GROUP BY status");
        $countStmt->bind_param('i', $businessId);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        while ($row = $countResult->fetch_assoc()) {
            $key = strtolower($row['status']);
            if (isset($contactCounts[$key])) {
                $contactCounts[$key] = intval($row['total']);
            }
        }
        $countStmt->close();
    }

    respond(true, '', [
        'range' => [
            'from' => $fromSql,
            'to' => $toSql
        ],
        'kpis' => [
            'orders_count' => intval($summary['orders_count'] ?? 0),
            'gross_sales' => floatval($summary['gross_sales'] ?? 0),
            'avg_order_value' => floatval($summary['avg_order_value'] ?? 0),
            'orders_today' => intval($todaySummary['orders_today'] ?? 0),
            'sales_today' => floatval($todaySummary['sales_today'] ?? 0),
            'products_count' => intval($productSummary['products_count'] ?? 0),
            'units_in_stock' => intval($productSummary['units_in_stock'] ?? 0),
            'low_stock_count' => count($lowStockProducts)
        ],
        'daily_sales' => $dailySales,
        'top_products' => $topProducts,
        'category_sales' => $categorySales,
        'low_stock_products' => $lowStockProducts,
        'recent_sales' => $recentSales,
        'contact_messages' => $contactMessages,
        'contact_counts' => $contactCounts,
        'has_contact_messages_table' => $hasContactTable
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('dashboard-data.php: ' . $e->getMessage());
    respond(false, 'Unable to load dashboard data right now.');
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
