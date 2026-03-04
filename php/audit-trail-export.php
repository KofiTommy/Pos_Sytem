<?php
include 'admin-auth.php';
require_roles_api(['owner']);
include 'db-connection.php';
include 'tenant-context.php';
include_once __DIR__ . '/compliance-tracking.php';

function fail_csv(string $message, int $status = 400): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
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

function bind_dynamic_params(mysqli_stmt $stmt, string $types, array $values): void {
    $params = [];
    $params[] = &$types;
    foreach ($values as $index => $value) {
        $values[$index] = $value;
        $params[] = &$values[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
}

function output_csv(string $filename, array $headers, array $rows): void {
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    $out = fopen('php://output', 'w');
    if ($out === false) {
        fail_csv('Unable to open CSV stream.', 500);
    }

    fputcsv($out, $headers, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($out, $row, ',', '"', '\\');
    }
    fclose($out);
    exit();
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        fail_csv('Method not allowed.', 405);
    }

    ensure_multitenant_schema($conn);
    ensure_phase3_tracking_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        fail_csv('Invalid business context. Please sign in again.', 401);
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
            fail_csv('Invalid date format. Use YYYY-MM-DD.', 422);
        }
    }
    if ($fromDate > $toDate) {
        $tmp = $fromDate;
        $fromDate = $toDate;
        $toDate = $tmp;
    }

    $dataset = strtolower(trim((string)($_GET['dataset'] ?? 'events')));
    $allowedDatasets = ['events', 'inventory', 'closures'];
    if (!in_array($dataset, $allowedDatasets, true)) {
        fail_csv('Dataset must be one of: events, inventory, closures.', 422);
    }

    $limit = intval($_GET['limit'] ?? 5000);
    if ($limit <= 0) {
        $limit = 5000;
    }
    if ($limit > 20000) {
        $limit = 20000;
    }

    $fromSql = $fromDate->format('Y-m-d');
    $toSql = $toDate->format('Y-m-d');
    $rangeStart = $fromSql . ' 00:00:00';
    $rangeEndExclusive = (clone $toDate)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $stamp = gmdate('Ymd-His');
    if ($dataset === 'events') {
        $actionFilter = clean_filter_text($_GET['action'] ?? '', 80);
        $entityTypeFilter = clean_filter_text($_GET['entity_type'] ?? '', 60);

        $where = "WHERE business_id = ? AND created_at >= ? AND created_at < ?";
        $types = 'iss';
        $values = [$businessId, $rangeStart, $rangeEndExclusive];
        if ($actionFilter !== '') {
            $where .= " AND action_key LIKE ?";
            $types .= 's';
            $values[] = '%' . $actionFilter . '%';
        }
        if ($entityTypeFilter !== '') {
            $where .= " AND entity_type = ?";
            $types .= 's';
            $values[] = $entityTypeFilter;
        }

        $sql =
            "SELECT
                created_at,
                action_key,
                entity_type,
                entity_id,
                actor_username,
                actor_user_id,
                request_ip,
                user_agent,
                details_json
             FROM business_audit_log
             " . $where . "
             ORDER BY id DESC
             LIMIT ?";
        $types .= 'i';
        $values[] = $limit;
        $stmt = $conn->prepare($sql);
        bind_dynamic_params($stmt, $types, $values);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                (string)($row['created_at'] ?? ''),
                (string)($row['action_key'] ?? ''),
                (string)($row['entity_type'] ?? ''),
                intval($row['entity_id'] ?? 0),
                (string)($row['actor_username'] ?? ''),
                intval($row['actor_user_id'] ?? 0),
                (string)($row['request_ip'] ?? ''),
                (string)($row['user_agent'] ?? ''),
                (string)($row['details_json'] ?? '')
            ];
        }
        $stmt->close();

        output_csv(
            'audit-events-' . $stamp . '.csv',
            ['created_at', 'action_key', 'entity_type', 'entity_id', 'actor_username', 'actor_user_id', 'request_ip', 'user_agent', 'details_json'],
            $rows
        );
    }

    if ($dataset === 'inventory') {
        $adjustmentTypeFilter = clean_filter_text($_GET['adjustment_type'] ?? '', 60);

        $where = "WHERE ia.business_id = ? AND ia.created_at >= ? AND ia.created_at < ?";
        $types = 'iss';
        $values = [$businessId, $rangeStart, $rangeEndExclusive];
        if ($adjustmentTypeFilter !== '') {
            $where .= " AND ia.adjustment_type LIKE ?";
            $types .= 's';
            $values[] = '%' . $adjustmentTypeFilter . '%';
        }

        $sql =
            "SELECT
                ia.created_at,
                ia.adjustment_type,
                ia.product_id,
                COALESCE(NULLIF(p.name, ''), CONCAT('Product #', ia.product_id)) AS product_name,
                ia.order_id,
                ia.quantity_delta,
                ia.stock_before,
                ia.stock_after,
                ia.reason,
                ia.actor_username,
                ia.actor_user_id
             FROM inventory_adjustments ia
             LEFT JOIN products p ON p.id = ia.product_id AND p.business_id = ia.business_id
             " . $where . "
             ORDER BY ia.id DESC
             LIMIT ?";
        $types .= 'i';
        $values[] = $limit;
        $stmt = $conn->prepare($sql);
        bind_dynamic_params($stmt, $types, $values);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                (string)($row['created_at'] ?? ''),
                (string)($row['adjustment_type'] ?? ''),
                intval($row['product_id'] ?? 0),
                (string)($row['product_name'] ?? ''),
                intval($row['order_id'] ?? 0),
                intval($row['quantity_delta'] ?? 0),
                intval($row['stock_before'] ?? 0),
                intval($row['stock_after'] ?? 0),
                (string)($row['reason'] ?? ''),
                (string)($row['actor_username'] ?? ''),
                intval($row['actor_user_id'] ?? 0)
            ];
        }
        $stmt->close();

        output_csv(
            'audit-inventory-' . $stamp . '.csv',
            ['created_at', 'adjustment_type', 'product_id', 'product_name', 'order_id', 'quantity_delta', 'stock_before', 'stock_after', 'reason', 'actor_username', 'actor_user_id'],
            $rows
        );
    }

    $sql =
        "SELECT
            closure_date,
            shift_label,
            expected_cash,
            counted_cash,
            variance,
            notes,
            status,
            closed_by_username,
            closed_by_user_id,
            updated_at,
            created_at
         FROM cash_closures
         WHERE business_id = ? AND closure_date >= ? AND closure_date <= ?
         ORDER BY closure_date DESC, id DESC
         LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('issi', $businessId, $fromSql, $toSql, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            (string)($row['closure_date'] ?? ''),
            (string)($row['shift_label'] ?? ''),
            round(floatval($row['expected_cash'] ?? 0), 2),
            round(floatval($row['counted_cash'] ?? 0), 2),
            round(floatval($row['variance'] ?? 0), 2),
            (string)($row['notes'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['closed_by_username'] ?? ''),
            intval($row['closed_by_user_id'] ?? 0),
            (string)($row['updated_at'] ?? ''),
            (string)($row['created_at'] ?? '')
        ];
    }
    $stmt->close();

    output_csv(
        'audit-closures-' . $stamp . '.csv',
        ['closure_date', 'shift_label', 'expected_cash', 'counted_cash', 'variance', 'notes', 'status', 'closed_by_username', 'closed_by_user_id', 'updated_at', 'created_at'],
        $rows
    );
} catch (Exception $e) {
    error_log('audit-trail-export.php: ' . $e->getMessage());
    fail_csv('Unable to export audit data right now.', 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
