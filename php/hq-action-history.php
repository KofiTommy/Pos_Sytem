<?php
header('Content-Type: application/json');
include_once __DIR__ . '/hq-auth.php';
hq_require_api();
include __DIR__ . '/db-connection.php';
include __DIR__ . '/tenant-context.php';

const HQ_HISTORY_ALLOWED_ACTIONS = [
    'set_business_status',
    'issue_owner_reset_link',
    'set_alert_status'
];

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

function parse_action_payload(?string $payloadJson): array {
    $payloadText = trim((string)$payloadJson);
    if ($payloadText === '') {
        return [];
    }

    $payload = json_decode($payloadText, true);
    if (!is_array($payload)) {
        return [];
    }
    return $payload;
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

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(false, 'Method not allowed.', [], 405);
    }

    $limit = intval($_GET['limit'] ?? 20);
    if ($limit <= 0) {
        $limit = 20;
    }
    if ($limit > 100) {
        $limit = 100;
    }

    $actionFilter = strtolower(trim((string)($_GET['action'] ?? '')));
    if ($actionFilter !== '' && !in_array($actionFilter, HQ_HISTORY_ALLOWED_ACTIONS, true)) {
        respond(false, 'Invalid action filter.', [], 422);
    }

    $userFilter = trim((string)($_GET['user'] ?? ''));
    if (strlen($userFilter) > 120) {
        $userFilter = substr($userFilter, 0, 120);
    }

    $fromRaw = trim((string)($_GET['from'] ?? ''));
    $toRaw = trim((string)($_GET['to'] ?? ''));
    $fromDate = null;
    $toDate = null;
    if ($fromRaw !== '') {
        $fromDate = parse_ymd_date($fromRaw);
        if (!$fromDate) {
            respond(false, 'Invalid from date. Use YYYY-MM-DD.', [], 422);
        }
    }
    if ($toRaw !== '') {
        $toDate = parse_ymd_date($toRaw);
        if (!$toDate) {
            respond(false, 'Invalid to date. Use YYYY-MM-DD.', [], 422);
        }
    }
    if ($fromDate && $toDate && $fromDate > $toDate) {
        $tmp = $fromDate;
        $fromDate = $toDate;
        $toDate = $tmp;
    }

    if (!tenant_table_exists($conn, 'hq_action_audit_log')) {
        respond(true, '', [
            'actions' => [],
            'meta' => [
                'limit' => $limit,
                'count' => 0,
                'filters' => [
                    'action' => $actionFilter,
                    'user' => $userFilter,
                    'from' => $fromDate ? $fromDate->format('Y-m-d') : '',
                    'to' => $toDate ? $toDate->format('Y-m-d') : ''
                ]
            ]
        ]);
    }

    $where = [];
    $types = '';
    $values = [];

    if ($actionFilter !== '') {
        $where[] = 'action_key = ?';
        $types .= 's';
        $values[] = $actionFilter;
    }

    if ($userFilter !== '') {
        $where[] = 'performed_by LIKE ?';
        $types .= 's';
        $values[] = '%' . $userFilter . '%';
    }

    if ($fromDate) {
        $where[] = 'created_at >= ?';
        $types .= 's';
        $values[] = $fromDate->format('Y-m-d') . ' 00:00:00';
    }

    if ($toDate) {
        $where[] = 'created_at < ?';
        $types .= 's';
        $values[] = (clone $toDate)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    $sql =
        "SELECT
            id,
            business_id,
            business_code,
            action_key,
            payload_json,
            performed_by,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
         FROM hq_action_audit_log";
    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id DESC LIMIT ?';
    $types .= 'i';
    $values[] = $limit;

    $stmt = $conn->prepare($sql);
    bind_dynamic_params($stmt, $types, $values);
    $stmt->execute();
    $result = $stmt->get_result();

    $actions = [];
    while ($row = $result->fetch_assoc()) {
        $actions[] = [
            'id' => intval($row['id'] ?? 0),
            'business_id' => intval($row['business_id'] ?? 0),
            'business_code' => (string)($row['business_code'] ?? ''),
            'action_key' => (string)($row['action_key'] ?? ''),
            'payload' => parse_action_payload($row['payload_json'] ?? null),
            'performed_by' => (string)($row['performed_by'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? '')
        ];
    }
    $stmt->close();

    respond(true, '', [
        'actions' => $actions,
        'meta' => [
            'limit' => $limit,
            'count' => count($actions),
            'filters' => [
                'action' => $actionFilter,
                'user' => $userFilter,
                'from' => $fromDate ? $fromDate->format('Y-m-d') : '',
                'to' => $toDate ? $toDate->format('Y-m-d') : ''
            ]
        ]
    ]);
} catch (Exception $e) {
    error_log('hq-action-history.php: ' . $e->getMessage());
    respond(false, 'Unable to load action history right now.', [], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
