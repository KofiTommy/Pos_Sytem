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

function clean_filter_text($value, int $max = 60): string {
    $text = trim((string)$value);
    $text = preg_replace('/\s+/', ' ', $text);
    if (!is_string($text)) {
        $text = '';
    }
    if (strlen($text) > $max) {
        $text = substr($text, 0, $max);
    }
    return $text;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(false, 'Method not allowed.', [], 405);
    }

    ensure_multitenant_schema($conn);
    ensure_phase3_tracking_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        respond(false, 'Invalid business context. Please sign in again.', [], 401);
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

    $fromSql = $fromDate->format('Y-m-d');
    $toSql = $toDate->format('Y-m-d');
    $rangeStart = $fromSql . ' 00:00:00';
    $rangeEndExclusive = (clone $toDate)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $limitEvents = intval($_GET['limit_events'] ?? 120);
    $limitInventory = intval($_GET['limit_inventory'] ?? 120);
    if ($limitEvents <= 0) {
        $limitEvents = 120;
    }
    if ($limitInventory <= 0) {
        $limitInventory = 120;
    }
    if ($limitEvents > 500) {
        $limitEvents = 500;
    }
    if ($limitInventory > 500) {
        $limitInventory = 500;
    }

    $actionFilter = clean_filter_text($_GET['action'] ?? '', 80);
    $entityTypeFilter = clean_filter_text($_GET['entity_type'] ?? '', 60);
    $inventoryTypeFilter = clean_filter_text($_GET['adjustment_type'] ?? '', 60);

    $eventWhere = "WHERE business_id = ? AND created_at >= ? AND created_at < ?";
    $eventTypes = 'iss';
    $eventValues = [$businessId, $rangeStart, $rangeEndExclusive];

    if ($actionFilter !== '') {
        $eventWhere .= " AND action_key LIKE ?";
        $eventTypes .= 's';
        $eventValues[] = '%' . $actionFilter . '%';
    }
    if ($entityTypeFilter !== '') {
        $eventWhere .= " AND entity_type = ?";
        $eventTypes .= 's';
        $eventValues[] = $entityTypeFilter;
    }

    $eventListSql =
        "SELECT
            id,
            actor_user_id,
            actor_username,
            action_key,
            entity_type,
            entity_id,
            details_json,
            request_ip,
            user_agent,
            created_at
         FROM business_audit_log
         " . $eventWhere . "
         ORDER BY id DESC
         LIMIT ?";
    $eventListTypes = $eventTypes . 'i';
    $eventListValues = $eventValues;
    $eventListValues[] = $limitEvents;
    $eventListStmt = $conn->prepare($eventListSql);
    bind_dynamic_params($eventListStmt, $eventListTypes, $eventListValues);
    $eventListStmt->execute();
    $eventResult = $eventListStmt->get_result();
    $events = [];
    while ($row = $eventResult->fetch_assoc()) {
        $detailsRaw = (string)($row['details_json'] ?? '');
        $detailsPreview = '';
        if ($detailsRaw !== '') {
            $details = json_decode($detailsRaw, true);
            if (is_array($details)) {
                $preview = json_encode($details, JSON_UNESCAPED_SLASHES);
                if ($preview !== false) {
                    $detailsPreview = $preview;
                }
            }
        }
        if ($detailsPreview === '') {
            $detailsPreview = $detailsRaw;
        }
        if (strlen($detailsPreview) > 260) {
            $detailsPreview = substr($detailsPreview, 0, 260) . '...';
        }
        $row['details_preview'] = $detailsPreview;
        $events[] = $row;
    }
    $eventListStmt->close();

    $eventCountSql = "SELECT COUNT(*) AS total_events FROM business_audit_log " . $eventWhere;
    $eventCountStmt = $conn->prepare($eventCountSql);
    bind_dynamic_params($eventCountStmt, $eventTypes, $eventValues);
    $eventCountStmt->execute();
    $eventCountRow = $eventCountStmt->get_result()->fetch_assoc() ?: [];
    $eventCountStmt->close();

    $inventoryWhere = "WHERE ia.business_id = ? AND ia.created_at >= ? AND ia.created_at < ?";
    $inventoryTypes = 'iss';
    $inventoryValues = [$businessId, $rangeStart, $rangeEndExclusive];
    if ($inventoryTypeFilter !== '') {
        $inventoryWhere .= " AND ia.adjustment_type LIKE ?";
        $inventoryTypes .= 's';
        $inventoryValues[] = '%' . $inventoryTypeFilter . '%';
    }

    $inventoryListSql =
        "SELECT
            ia.id,
            ia.product_id,
            COALESCE(NULLIF(p.name, ''), CONCAT('Product #', ia.product_id)) AS product_name,
            ia.order_id,
            ia.adjustment_type,
            ia.quantity_delta,
            ia.stock_before,
            ia.stock_after,
            ia.reason,
            ia.actor_user_id,
            ia.actor_username,
            ia.created_at
         FROM inventory_adjustments ia
         LEFT JOIN products p ON p.id = ia.product_id AND p.business_id = ia.business_id
         " . $inventoryWhere . "
         ORDER BY ia.id DESC
         LIMIT ?";
    $inventoryListTypes = $inventoryTypes . 'i';
    $inventoryListValues = $inventoryValues;
    $inventoryListValues[] = $limitInventory;
    $inventoryListStmt = $conn->prepare($inventoryListSql);
    bind_dynamic_params($inventoryListStmt, $inventoryListTypes, $inventoryListValues);
    $inventoryListStmt->execute();
    $inventoryResult = $inventoryListStmt->get_result();
    $inventoryAdjustments = [];
    while ($row = $inventoryResult->fetch_assoc()) {
        $inventoryAdjustments[] = $row;
    }
    $inventoryListStmt->close();

    $inventorySummarySql =
        "SELECT
            COUNT(*) AS total_adjustments,
            COALESCE(SUM(CASE WHEN ia.quantity_delta > 0 THEN ia.quantity_delta ELSE 0 END), 0) AS stock_in_units,
            COALESCE(SUM(CASE WHEN ia.quantity_delta < 0 THEN ABS(ia.quantity_delta) ELSE 0 END), 0) AS stock_out_units
         FROM inventory_adjustments ia
         " . $inventoryWhere;
    $inventorySummaryStmt = $conn->prepare($inventorySummarySql);
    bind_dynamic_params($inventorySummaryStmt, $inventoryTypes, $inventoryValues);
    $inventorySummaryStmt->execute();
    $inventorySummary = $inventorySummaryStmt->get_result()->fetch_assoc() ?: [];
    $inventorySummaryStmt->close();

    $hasCashClosuresTable = tenant_table_exists($conn, 'cash_closures');
    $cashSummary = [
        'closures_count' => 0,
        'variance_total' => 0.0
    ];
    $recentClosures = [];
    if ($hasCashClosuresTable) {
        $cashSummaryStmt = $conn->prepare(
            "SELECT
                COUNT(*) AS closures_count,
                COALESCE(SUM(variance), 0) AS variance_total
             FROM cash_closures
             WHERE business_id = ? AND closure_date >= ? AND closure_date <= ?"
        );
        $cashSummaryStmt->bind_param('iss', $businessId, $fromSql, $toSql);
        $cashSummaryStmt->execute();
        $cashSummary = $cashSummaryStmt->get_result()->fetch_assoc() ?: $cashSummary;
        $cashSummaryStmt->close();

        $closureLimit = 30;
        $recentClosuresStmt = $conn->prepare(
            "SELECT
                id,
                closure_date,
                shift_label,
                expected_cash,
                counted_cash,
                variance,
                closed_by_username,
                updated_at
             FROM cash_closures
             WHERE business_id = ? AND closure_date >= ? AND closure_date <= ?
             ORDER BY closure_date DESC, id DESC
             LIMIT ?"
        );
        $recentClosuresStmt->bind_param('issi', $businessId, $fromSql, $toSql, $closureLimit);
        $recentClosuresStmt->execute();
        $recentClosuresResult = $recentClosuresStmt->get_result();
        while ($row = $recentClosuresResult->fetch_assoc()) {
            $recentClosures[] = $row;
        }
        $recentClosuresStmt->close();
    }

    respond(true, '', [
        'range' => [
            'from' => $fromSql,
            'to' => $toSql
        ],
        'filters' => [
            'action' => $actionFilter,
            'entity_type' => $entityTypeFilter,
            'adjustment_type' => $inventoryTypeFilter
        ],
        'summary' => [
            'total_events' => intval($eventCountRow['total_events'] ?? 0),
            'total_adjustments' => intval($inventorySummary['total_adjustments'] ?? 0),
            'stock_in_units' => intval($inventorySummary['stock_in_units'] ?? 0),
            'stock_out_units' => intval($inventorySummary['stock_out_units'] ?? 0),
            'closures_count' => intval($cashSummary['closures_count'] ?? 0),
            'variance_total' => round(floatval($cashSummary['variance_total'] ?? 0), 2)
        ],
        'events' => $events,
        'inventory_adjustments' => $inventoryAdjustments,
        'recent_cash_closures' => $recentClosures,
        'has_cash_closures_table' => $hasCashClosuresTable
    ]);
} catch (Exception $e) {
    error_log('audit-trail.php: ' . $e->getMessage());
    respond(false, 'Unable to load audit trail right now.', [], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

