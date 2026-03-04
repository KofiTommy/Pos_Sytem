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

function normalize_shift_label($value): string {
    $label = strtolower(trim((string)$value));
    if ($label === '') {
        $label = 'daily';
    }
    $label = preg_replace('/[^a-z0-9._:-]/', '-', $label);
    if (!is_string($label) || $label === '') {
        $label = 'daily';
    }
    if (strlen($label) > 60) {
        $label = substr($label, 0, 60);
    }
    return $label;
}

try {
    ensure_multitenant_schema($conn);
    ensure_phase3_tracking_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        respond(false, 'Invalid business context. Please sign in again.', [], 401);
    }

    $actorUserId = tracking_actor_user_id();
    $actorUsername = tracking_actor_username();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $today = new DateTime('today');
        $fromRaw = trim((string)($_GET['from'] ?? ''));
        $toRaw = trim((string)($_GET['to'] ?? ''));

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

        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 120;
        if ($limit <= 0) {
            $limit = 120;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        $fromSql = $fromDate->format('Y-m-d');
        $toSql = $toDate->format('Y-m-d');

        $listStmt = $conn->prepare(
            "SELECT
                id,
                closure_date,
                shift_label,
                expected_cash,
                counted_cash,
                variance,
                notes,
                status,
                closed_by_user_id,
                closed_by_username,
                created_at,
                updated_at
             FROM cash_closures
             WHERE business_id = ? AND closure_date >= ? AND closure_date <= ?
             ORDER BY closure_date DESC, id DESC
             LIMIT ?"
        );
        $listStmt->bind_param('issi', $businessId, $fromSql, $toSql, $limit);
        $listStmt->execute();
        $listResult = $listStmt->get_result();
        $closures = [];
        while ($row = $listResult->fetch_assoc()) {
            $closures[] = $row;
        }
        $listStmt->close();

        $summaryStmt = $conn->prepare(
            "SELECT
                COUNT(*) AS closures_count,
                COALESCE(SUM(expected_cash), 0) AS expected_total,
                COALESCE(SUM(counted_cash), 0) AS counted_total,
                COALESCE(SUM(variance), 0) AS variance_total
             FROM cash_closures
             WHERE business_id = ? AND closure_date >= ? AND closure_date <= ?"
        );
        $summaryStmt->bind_param('iss', $businessId, $fromSql, $toSql);
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
        $summaryStmt->close();

        $todaySql = $today->format('Y-m-d');
        $suggestedExpected = tracking_expected_cash_for_date($conn, $businessId, $todaySql);

        respond(true, '', [
            'range' => [
                'from' => $fromSql,
                'to' => $toSql
            ],
            'closures' => $closures,
            'summary' => [
                'closures_count' => intval($summary['closures_count'] ?? 0),
                'expected_total' => round(floatval($summary['expected_total'] ?? 0), 2),
                'counted_total' => round(floatval($summary['counted_total'] ?? 0), 2),
                'variance_total' => round(floatval($summary['variance_total'] ?? 0), 2)
            ],
            'suggested_today' => [
                'closure_date' => $todaySql,
                'expected_cash' => $suggestedExpected
            ]
        ]);
    }

    if ($method === 'POST') {
        $rawBody = file_get_contents('php://input');
        $body = json_decode((string)$rawBody, true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        $closureDateRaw = trim((string)($body['closure_date'] ?? ''));
        $closureDateObj = parse_ymd_date($closureDateRaw);
        if (!$closureDateObj) {
            $closureDateObj = new DateTime('today');
        }
        $closureDate = $closureDateObj->format('Y-m-d');

        $shiftLabel = normalize_shift_label($body['shift_label'] ?? 'daily');
        $countedCashRaw = $body['counted_cash'] ?? null;
        if ($countedCashRaw === null || $countedCashRaw === '') {
            respond(false, 'Counted cash is required.', [], 422);
        }
        $countedCash = round(floatval($countedCashRaw), 2);
        if ($countedCash < -99999999 || $countedCash > 99999999) {
            respond(false, 'Counted cash is out of allowed range.', [], 422);
        }

        $expectedCashProvided = isset($body['expected_cash']) && $body['expected_cash'] !== '';
        if ($expectedCashProvided) {
            $expectedCash = round(floatval($body['expected_cash']), 2);
        } else {
            $expectedCash = tracking_expected_cash_for_date($conn, $businessId, $closureDate);
        }
        if ($expectedCash < -99999999 || $expectedCash > 99999999) {
            respond(false, 'Expected cash is out of allowed range.', [], 422);
        }

        $notes = trim((string)($body['notes'] ?? ''));
        if (strlen($notes) > 500) {
            $notes = substr($notes, 0, 500);
        }

        $variance = round($countedCash - $expectedCash, 2);
        $status = 'closed';

        $upsertStmt = $conn->prepare(
            "INSERT INTO cash_closures
                (business_id, closure_date, shift_label, expected_cash, counted_cash, variance, notes, status, closed_by_user_id, closed_by_username)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                expected_cash = VALUES(expected_cash),
                counted_cash = VALUES(counted_cash),
                variance = VALUES(variance),
                notes = VALUES(notes),
                status = VALUES(status),
                closed_by_user_id = VALUES(closed_by_user_id),
                closed_by_username = VALUES(closed_by_username),
                updated_at = CURRENT_TIMESTAMP"
        );
        $upsertStmt->bind_param(
            'issdddssis',
            $businessId,
            $closureDate,
            $shiftLabel,
            $expectedCash,
            $countedCash,
            $variance,
            $notes,
            $status,
            $actorUserId,
            $actorUsername
        );
        $upsertStmt->execute();
        $upsertStmt->close();

        $fetchStmt = $conn->prepare(
            "SELECT
                id,
                closure_date,
                shift_label,
                expected_cash,
                counted_cash,
                variance,
                notes,
                status,
                closed_by_user_id,
                closed_by_username,
                created_at,
                updated_at
             FROM cash_closures
             WHERE business_id = ? AND closure_date = ? AND shift_label = ?
             LIMIT 1"
        );
        $fetchStmt->bind_param('iss', $businessId, $closureDate, $shiftLabel);
        $fetchStmt->execute();
        $closure = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        $closureId = intval($closure['id'] ?? 0);
        tracking_log_business_event(
            $conn,
            $businessId,
            'cash_closure.upsert',
            'cash_closure',
            $closureId,
            [
                'closure_date' => $closureDate,
                'shift_label' => $shiftLabel,
                'expected_cash' => $expectedCash,
                'counted_cash' => $countedCash,
                'variance' => $variance
            ],
            $actorUserId,
            $actorUsername
        );

        respond(true, 'Cash closure saved.', [
            'closure' => $closure
        ]);
    }

    respond(false, 'Method not allowed.', [], 405);
} catch (Exception $e) {
    error_log('cash-closures.php: ' . $e->getMessage());
    respond(false, 'Unable to process cash closure request right now.', [], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
