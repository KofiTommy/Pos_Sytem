<?php
header('Content-Type: application/json');
include_once __DIR__ . '/hq-auth.php';
hq_require_api();
include __DIR__ . '/db-connection.php';
include __DIR__ . '/tenant-context.php';

const HQ_BROADCAST_ALLOWED_AUDIENCES = ['customers', 'owners', 'all'];
const HQ_BROADCAST_ALLOWED_CHANNELS = ['in_app', 'email', 'both'];
const HQ_BROADCAST_MAX_NOTICES_PER_REQUEST = 250;
const HQ_BROADCAST_MAX_RECIPIENTS_PER_BUSINESS = 400;

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

function hq_broadcast_bind_dynamic_params(mysqli_stmt $stmt, string $types, array $values): void {
    $params = [];
    $params[] = &$types;
    foreach ($values as $index => $value) {
        $values[$index] = $value;
        $params[] = &$values[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
}

function hq_broadcast_parse_datetime_input(?string $rawValue): ?string {
    $value = trim((string)$rawValue);
    if ($value === '') {
        return null;
    }

    $formats = [
        'Y-m-d\TH:i',
        'Y-m-d\TH:i:s',
        'Y-m-d H:i',
        'Y-m-d H:i:s',
        DateTime::ATOM
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if (!$date) {
            continue;
        }
        $errors = DateTime::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }
        return $date->format('Y-m-d H:i:s');
    }

    $fallback = date_create($value);
    if ($fallback instanceof DateTime) {
        return $fallback->format('Y-m-d H:i:s');
    }

    return null;
}

function hq_broadcast_normalize_audience(string $audience): string {
    $normalized = strtolower(trim($audience));
    if ($normalized === 'owner') {
        $normalized = 'owners';
    }
    if ($normalized === 'customer') {
        $normalized = 'customers';
    }
    if (!in_array($normalized, HQ_BROADCAST_ALLOWED_AUDIENCES, true)) {
        return 'customers';
    }
    return $normalized;
}

function hq_broadcast_normalize_channel(string $channel): string {
    $normalized = strtolower(trim($channel));
    if (!in_array($normalized, HQ_BROADCAST_ALLOWED_CHANNELS, true)) {
        return 'in_app';
    }
    return $normalized;
}

function hq_broadcast_email_domain(): string {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = explode(':', $host)[0] ?? '';
    $host = preg_replace('/[^a-z0-9.-]/', '', (string)$host);
    if ($host === '') {
        return 'localhost';
    }
    return $host;
}

function hq_broadcast_email_from_address(): string {
    $configured = trim((string)hq_env('HQ_BROADCAST_FROM_EMAIL'));
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
        return $configured;
    }
    return 'no-reply@' . hq_broadcast_email_domain();
}

function hq_broadcast_email_from_name(): string {
    $configured = trim((string)hq_env('HQ_BROADCAST_FROM_NAME'));
    if ($configured !== '') {
        return $configured;
    }
    return 'CediTill HQ';
}

function hq_broadcast_send_email(string $recipientEmail, string $subject, string $body): bool {
    $to = trim($recipientEmail);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromEmail = hq_broadcast_email_from_address();
    $fromName = hq_broadcast_email_from_name();
    $safeFromName = str_replace(["\r", "\n"], '', $fromName);
    $safeFromEmail = str_replace(["\r", "\n"], '', $fromEmail);

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: {$safeFromName} <{$safeFromEmail}>\r\n";
    $headers .= "Reply-To: {$safeFromEmail}\r\n";

    return @mail($to, $subject, $body, $headers);
}

function hq_broadcast_build_email_subject(string $subject): string {
    $clean = trim($subject);
    if ($clean === '') {
        return '[CediTill Update] Important notice';
    }
    return '[CediTill Update] ' . $clean;
}

function hq_broadcast_build_email_body(string $businessName, string $subject, string $message): string {
    $title = trim($subject) !== '' ? trim($subject) : 'Important update';
    $shopName = trim($businessName) !== '' ? trim($businessName) : 'your shop';
    $safeMessage = trim($message);
    if ($safeMessage === '') {
        $safeMessage = 'A new update was published by CediTill HQ.';
    }

    return
        "Hello,\n\n" .
        "CediTill HQ shared an update for {$shopName}.\n\n" .
        "{$title}\n" .
        str_repeat('-', max(8, strlen($title))) . "\n" .
        $safeMessage . "\n\n" .
        "If you need help, contact support from your dashboard.\n\n" .
        "Regards,\nCediTill HQ";
}

function hq_ensure_broadcast_tables(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS hq_broadcast_notices (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            audience VARCHAR(20) NOT NULL DEFAULT 'customers',
            channel VARCHAR(20) NOT NULL DEFAULT 'in_app',
            subject VARCHAR(180) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            starts_at DATETIME NULL DEFAULT NULL,
            expires_at DATETIME NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(120) NOT NULL DEFAULT '',
            email_sent_count INT NOT NULL DEFAULT 0,
            email_failed_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hq_notice_business_active (business_id, is_active, created_at),
            INDEX idx_hq_notice_audience_active (audience, is_active, created_at),
            INDEX idx_hq_notice_window (starts_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS hq_broadcast_email_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            notice_id BIGINT NOT NULL,
            business_id INT NOT NULL,
            recipient_email VARCHAR(180) NOT NULL,
            recipient_type VARCHAR(20) NOT NULL DEFAULT 'customer',
            delivery_status VARCHAR(20) NOT NULL DEFAULT 'sent',
            error_message VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hq_notice_email_notice (notice_id, created_at),
            INDEX idx_hq_notice_email_business (business_id, created_at),
            INDEX idx_hq_notice_email_status (delivery_status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function hq_ensure_action_audit_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS hq_action_audit_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            business_code VARCHAR(64) NOT NULL DEFAULT '',
            action_key VARCHAR(80) NOT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            performed_by VARCHAR(120) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hq_action_business_time (business_id, created_at),
            INDEX idx_hq_action_action_time (action_key, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function hq_action_log(mysqli $conn, int $businessId, string $businessCode, string $actionKey, string $performedBy, array $payload = []): void {
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        $payloadJson = '{}';
    }
    $stmt = $conn->prepare(
        "INSERT INTO hq_action_audit_log
            (business_id, business_code, action_key, payload_json, performed_by)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $businessId, $businessCode, $actionKey, $payloadJson, $performedBy);
    $stmt->execute();
    $stmt->close();
}

function hq_broadcast_clean_subject(string $subject): string {
    $clean = trim(preg_replace('/\s+/', ' ', $subject));
    if (strlen($clean) > 180) {
        $clean = substr($clean, 0, 180);
    }
    return $clean;
}

function hq_broadcast_clean_message(string $message): string {
    $clean = trim($message);
    if (strlen($clean) > 5000) {
        $clean = substr($clean, 0, 5000);
    }
    return $clean;
}

function hq_broadcast_collect_target_businesses(mysqli $conn, string $scope, array $businessIds): array {
    if ($scope === 'selected') {
        $ids = [];
        foreach ($businessIds as $value) {
            $id = intval($value);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if (count($ids) === 0) {
            throw new Exception('Select at least one target business.', 422);
        }
        if (count($ids) > HQ_BROADCAST_MAX_NOTICES_PER_REQUEST) {
            throw new Exception('Too many selected businesses in one request.', 422);
        }

        $types = str_repeat('i', count($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "SELECT id, business_code, business_name, status
             FROM businesses
             WHERE id IN ({$placeholders})
             ORDER BY business_name ASC"
        );
        hq_broadcast_bind_dynamic_params($stmt, $types, $ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => intval($row['id'] ?? 0),
                'business_code' => (string)($row['business_code'] ?? ''),
                'business_name' => (string)($row['business_name'] ?? ''),
                'status' => strtolower(trim((string)($row['status'] ?? '')))
            ];
        }
        $stmt->close();

        if (count($rows) === 0) {
            throw new Exception('Selected businesses were not found.', 404);
        }
        return $rows;
    }

    $rows = [];
    $result = $conn->query(
        "SELECT id, business_code, business_name, status
         FROM businesses
         WHERE status = 'active'
         ORDER BY business_name ASC
         LIMIT " . intval(HQ_BROADCAST_MAX_NOTICES_PER_REQUEST)
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => intval($row['id'] ?? 0),
                'business_code' => (string)($row['business_code'] ?? ''),
                'business_name' => (string)($row['business_name'] ?? ''),
                'status' => strtolower(trim((string)($row['status'] ?? '')))
            ];
        }
    }

    if (count($rows) === 0) {
        throw new Exception('No active businesses found for broadcast.', 404);
    }
    return $rows;
}

function hq_broadcast_fetch_owner_recipients(mysqli $conn, int $businessId): array {
    $recipients = [];
    if ($businessId <= 0 || !tenant_table_exists($conn, 'users')) {
        return $recipients;
    }

    $stmt = $conn->prepare(
        "SELECT DISTINCT LOWER(TRIM(email)) AS email
         FROM users
         WHERE business_id = ?
           AND email IS NOT NULL
           AND TRIM(email) <> ''
           AND LOWER(CASE WHEN role = 'admin' THEN 'owner' ELSE role END) = 'owner'
         ORDER BY id ASC"
    );
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[$email] = 'owner';
        }
    }
    $stmt->close();

    return $recipients;
}

function hq_broadcast_fetch_customer_recipients(mysqli $conn, int $businessId, int $lookbackDays, int $limit): array {
    $recipients = [];
    if ($businessId <= 0 || !tenant_table_exists($conn, 'orders')) {
        return $recipients;
    }

    if ($lookbackDays <= 0) {
        $lookbackDays = 365;
    }
    if ($lookbackDays > 1095) {
        $lookbackDays = 1095;
    }
    if ($limit <= 0) {
        $limit = 200;
    }
    if ($limit > HQ_BROADCAST_MAX_RECIPIENTS_PER_BUSINESS) {
        $limit = HQ_BROADCAST_MAX_RECIPIENTS_PER_BUSINESS;
    }

    $since = (new DateTime('-' . $lookbackDays . ' days'))->format('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "SELECT
            LOWER(TRIM(customer_email)) AS email,
            MAX(created_at) AS last_seen
         FROM orders
         WHERE business_id = ?
           AND customer_email IS NOT NULL
           AND TRIM(customer_email) <> ''
           AND created_at >= ?
         GROUP BY LOWER(TRIM(customer_email))
         ORDER BY last_seen DESC
         LIMIT ?"
    );
    $stmt->bind_param('isi', $businessId, $since, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[$email] = 'customer';
        }
    }
    $stmt->close();

    return $recipients;
}

function hq_broadcast_collect_recipients(mysqli $conn, int $businessId, string $audience, int $lookbackDays, int $cap): array {
    if ($cap <= 0) {
        $cap = 200;
    }
    if ($cap > HQ_BROADCAST_MAX_RECIPIENTS_PER_BUSINESS) {
        $cap = HQ_BROADCAST_MAX_RECIPIENTS_PER_BUSINESS;
    }

    $recipientMap = [];
    if ($audience === 'owners' || $audience === 'all') {
        foreach (hq_broadcast_fetch_owner_recipients($conn, $businessId) as $email => $type) {
            $recipientMap[$email] = $type;
        }
    }
    if ($audience === 'customers' || $audience === 'all') {
        foreach (hq_broadcast_fetch_customer_recipients($conn, $businessId, $lookbackDays, $cap) as $email => $type) {
            if (!isset($recipientMap[$email])) {
                $recipientMap[$email] = $type;
            }
        }
    }

    $total = count($recipientMap);
    $truncated = 0;
    if ($total > $cap) {
        $truncated = $total - $cap;
        $recipientMap = array_slice($recipientMap, 0, $cap, true);
    }

    $rows = [];
    foreach ($recipientMap as $email => $type) {
        $rows[] = [
            'email' => $email,
            'type' => $type
        ];
    }

    return [
        'recipients' => $rows,
        'total_found' => $total,
        'truncated' => $truncated
    ];
}

function hq_broadcast_create_notices(mysqli $conn, array $body): array {
    if (!hq_actions_enabled()) {
        throw new Exception('HQ actions are disabled. Set HQ_ACTIONS_ENABLED=true to enable broadcasts.', 403);
    }

    $scope = strtolower(trim((string)($body['business_scope'] ?? 'all_active')));
    if (!in_array($scope, ['all_active', 'selected'], true)) {
        $scope = 'all_active';
    }

    $businessIds = [];
    if (isset($body['business_ids']) && is_array($body['business_ids'])) {
        $businessIds = $body['business_ids'];
    }
    if (isset($body['business_id'])) {
        $businessIds[] = intval($body['business_id']);
    }

    $audience = hq_broadcast_normalize_audience((string)($body['audience'] ?? 'customers'));
    $channel = hq_broadcast_normalize_channel((string)($body['channel'] ?? 'in_app'));
    $subject = hq_broadcast_clean_subject((string)($body['subject'] ?? ''));
    $message = hq_broadcast_clean_message((string)($body['message'] ?? ''));
    $startsAt = hq_broadcast_parse_datetime_input((string)($body['starts_at'] ?? ''));
    $expiresAt = hq_broadcast_parse_datetime_input((string)($body['expires_at'] ?? ''));
    $lookbackDays = intval($body['email_lookback_days'] ?? 365);
    $recipientCap = intval($body['recipient_cap'] ?? 200);

    if ($subject === '') {
        throw new Exception('Subject is required.', 422);
    }
    if ($message === '') {
        throw new Exception('Message body is required.', 422);
    }
    if ($startsAt !== null && $expiresAt !== null && strcmp($startsAt, $expiresAt) >= 0) {
        throw new Exception('Expiry must be later than start time.', 422);
    }

    $targets = hq_broadcast_collect_target_businesses($conn, $scope, $businessIds);
    if (count($targets) > HQ_BROADCAST_MAX_NOTICES_PER_REQUEST) {
        throw new Exception('Too many target businesses.', 422);
    }

    $createdBy = hq_current_username();
    $insertStmt = $conn->prepare(
        "INSERT INTO hq_broadcast_notices
            (business_id, audience, channel, subject, message, starts_at, expires_at, is_active, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)"
    );
    $updateCountsStmt = $conn->prepare(
        "UPDATE hq_broadcast_notices
         SET email_sent_count = ?, email_failed_count = ?
         WHERE id = ?"
    );
    $insertEmailLogStmt = $conn->prepare(
        "INSERT INTO hq_broadcast_email_log
            (notice_id, business_id, recipient_email, recipient_type, delivery_status, error_message)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $noticeIds = [];
    $emailSentTotal = 0;
    $emailFailedTotal = 0;
    $emailTruncatedTotal = 0;
    $recipientFoundTotal = 0;

    foreach ($targets as $target) {
        $businessId = intval($target['id'] ?? 0);
        $businessCode = (string)($target['business_code'] ?? '');
        $businessName = (string)($target['business_name'] ?? '');
        if ($businessId <= 0) {
            continue;
        }

        $startSql = $startsAt;
        $endSql = $expiresAt;
        $insertStmt->bind_param('isssssss', $businessId, $audience, $channel, $subject, $message, $startSql, $endSql, $createdBy);
        $insertStmt->execute();
        $noticeId = intval($insertStmt->insert_id);
        if ($noticeId <= 0) {
            continue;
        }
        $noticeIds[] = $noticeId;

        $sentCount = 0;
        $failedCount = 0;

        if ($channel === 'email' || $channel === 'both') {
            $recipientBundle = hq_broadcast_collect_recipients($conn, $businessId, $audience, $lookbackDays, $recipientCap);
            $recipientRows = $recipientBundle['recipients'] ?? [];
            $recipientFoundTotal += intval($recipientBundle['total_found'] ?? 0);
            $emailTruncatedTotal += intval($recipientBundle['truncated'] ?? 0);

            foreach ($recipientRows as $recipient) {
                $recipientEmail = trim((string)($recipient['email'] ?? ''));
                $recipientType = trim((string)($recipient['type'] ?? 'customer'));
                if ($recipientEmail === '') {
                    continue;
                }

                $emailSubject = hq_broadcast_build_email_subject($subject);
                $emailBody = hq_broadcast_build_email_body($businessName, $subject, $message);
                $mailSent = hq_broadcast_send_email($recipientEmail, $emailSubject, $emailBody);

                $deliveryStatus = $mailSent ? 'sent' : 'failed';
                $errorMessage = $mailSent ? '' : 'mail() returned false';

                if ($mailSent) {
                    $sentCount += 1;
                } else {
                    $failedCount += 1;
                }

                $insertEmailLogStmt->bind_param(
                    'iissss',
                    $noticeId,
                    $businessId,
                    $recipientEmail,
                    $recipientType,
                    $deliveryStatus,
                    $errorMessage
                );
                $insertEmailLogStmt->execute();
            }
        }

        $updateCountsStmt->bind_param('iii', $sentCount, $failedCount, $noticeId);
        $updateCountsStmt->execute();

        $emailSentTotal += $sentCount;
        $emailFailedTotal += $failedCount;

        hq_action_log(
            $conn,
            $businessId,
            $businessCode,
            'send_broadcast_notice',
            $createdBy,
            [
                'notice_id' => $noticeId,
                'audience' => $audience,
                'channel' => $channel,
                'subject' => $subject,
                'email_sent' => $sentCount,
                'email_failed' => $failedCount
            ]
        );
    }

    $insertStmt->close();
    $updateCountsStmt->close();
    $insertEmailLogStmt->close();

    if (count($noticeIds) === 0) {
        throw new Exception('No notices were created.', 500);
    }

    return [
        'created_count' => count($noticeIds),
        'notice_ids' => $noticeIds,
        'email_sent' => $emailSentTotal,
        'email_failed' => $emailFailedTotal,
        'email_recipients_found' => $recipientFoundTotal,
        'email_recipients_truncated' => $emailTruncatedTotal,
        'scope' => $scope,
        'audience' => $audience,
        'channel' => $channel
    ];
}

function hq_broadcast_deactivate_notice(mysqli $conn, array $body): array {
    if (!hq_actions_enabled()) {
        throw new Exception('HQ actions are disabled. Set HQ_ACTIONS_ENABLED=true to enable broadcasts.', 403);
    }

    $noticeId = intval($body['id'] ?? 0);
    if ($noticeId <= 0) {
        throw new Exception('Valid notice id is required.', 422);
    }

    $selectStmt = $conn->prepare(
        "SELECT id, business_id, subject
         FROM hq_broadcast_notices
         WHERE id = ?
         LIMIT 1"
    );
    $selectStmt->bind_param('i', $noticeId);
    $selectStmt->execute();
    $notice = $selectStmt->get_result()->fetch_assoc();
    $selectStmt->close();
    if (!$notice) {
        throw new Exception('Notice not found.', 404);
    }

    $updateStmt = $conn->prepare(
        "UPDATE hq_broadcast_notices
         SET is_active = 0
         WHERE id = ?"
    );
    $updateStmt->bind_param('i', $noticeId);
    $updateStmt->execute();
    $updateStmt->close();

    $businessId = intval($notice['business_id'] ?? 0);
    $businessCode = '';
    if ($businessId > 0) {
        $business = tenant_fetch_business_by_id($conn, $businessId);
        if ($business) {
            $businessCode = (string)($business['business_code'] ?? '');
        }
    }

    hq_action_log(
        $conn,
        $businessId,
        $businessCode,
        'deactivate_broadcast_notice',
        hq_current_username(),
        [
            'notice_id' => $noticeId,
            'subject' => (string)($notice['subject'] ?? '')
        ]
    );

    return [
        'id' => $noticeId,
        'is_active' => false
    ];
}

function hq_broadcast_list_notices(mysqli $conn): array {
    $limit = intval($_GET['limit'] ?? 20);
    if ($limit <= 0) {
        $limit = 20;
    }
    if ($limit > 60) {
        $limit = 60;
    }

    $businessId = intval($_GET['business_id'] ?? 0);
    $activeOnlyRaw = strtolower(trim((string)($_GET['active_only'] ?? '')));
    $activeOnly = in_array($activeOnlyRaw, ['1', 'true', 'yes', 'on'], true);

    $where = [];
    $types = '';
    $values = [];

    if ($businessId > 0) {
        $where[] = 'n.business_id = ?';
        $types .= 'i';
        $values[] = $businessId;
    }
    if ($activeOnly) {
        $where[] = 'n.is_active = 1';
    }

    $sql =
        "SELECT
            n.id,
            n.business_id,
            COALESCE(b.business_code, '') AS business_code,
            COALESCE(b.business_name, '') AS business_name,
            n.audience,
            n.channel,
            n.subject,
            n.message,
            DATE_FORMAT(n.starts_at, '%Y-%m-%d %H:%i:%s') AS starts_at,
            DATE_FORMAT(n.expires_at, '%Y-%m-%d %H:%i:%s') AS expires_at,
            n.is_active,
            n.created_by,
            n.email_sent_count,
            n.email_failed_count,
            DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i:%s') AS created_at
         FROM hq_broadcast_notices n
         LEFT JOIN businesses b ON b.id = n.business_id";
    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY n.id DESC LIMIT ?';
    $types .= 'i';
    $values[] = $limit;

    $stmt = $conn->prepare($sql);
    hq_broadcast_bind_dynamic_params($stmt, $types, $values);
    $stmt->execute();
    $result = $stmt->get_result();

    $broadcasts = [];
    while ($row = $result->fetch_assoc()) {
        $message = (string)($row['message'] ?? '');
        $preview = substr($message, 0, 200);
        if (strlen($message) > 200) {
            $preview .= '...';
        }
        $broadcasts[] = [
            'id' => intval($row['id'] ?? 0),
            'business_id' => intval($row['business_id'] ?? 0),
            'business_code' => (string)($row['business_code'] ?? ''),
            'business_name' => (string)($row['business_name'] ?? ''),
            'audience' => (string)($row['audience'] ?? 'customers'),
            'channel' => (string)($row['channel'] ?? 'in_app'),
            'subject' => (string)($row['subject'] ?? ''),
            'message' => $message,
            'message_preview' => $preview,
            'starts_at' => (string)($row['starts_at'] ?? ''),
            'expires_at' => (string)($row['expires_at'] ?? ''),
            'is_active' => intval($row['is_active'] ?? 0) === 1,
            'created_by' => (string)($row['created_by'] ?? ''),
            'email_sent_count' => intval($row['email_sent_count'] ?? 0),
            'email_failed_count' => intval($row['email_failed_count'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? '')
        ];
    }
    $stmt->close();

    return [
        'broadcasts' => $broadcasts,
        'meta' => [
            'count' => count($broadcasts),
            'limit' => $limit,
            'business_id' => $businessId,
            'active_only' => $activeOnly
        ]
    ];
}

try {
    ensure_multitenant_schema($conn);
    hq_ensure_broadcast_tables($conn);
    hq_ensure_action_audit_table($conn);

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $rawBody = file_get_contents('php://input');
    $body = json_decode((string)$rawBody, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    if ($method === 'GET') {
        $result = hq_broadcast_list_notices($conn);
        respond(true, '', $result);
    }

    if ($method === 'POST') {
        $action = strtolower(trim((string)($body['action'] ?? 'create')));
        if ($action !== 'create') {
            respond(false, 'Invalid action.', [], 422);
        }

        $result = hq_broadcast_create_notices($conn, $body);
        respond(true, 'Broadcast published successfully.', $result);
    }

    if ($method === 'PUT') {
        $action = strtolower(trim((string)($body['action'] ?? 'deactivate')));
        if (!in_array($action, ['deactivate', 'disable'], true)) {
            respond(false, 'Invalid action.', [], 422);
        }

        $result = hq_broadcast_deactivate_notice($conn, $body);
        respond(true, 'Broadcast notice deactivated.', $result);
    }

    respond(false, 'Method not allowed.', [], 405);
} catch (Exception $e) {
    $status = intval($e->getCode());
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    if ($status >= 500) {
        error_log('hq-broadcasts.php: ' . $e->getMessage());
        respond(false, 'Unable to process broadcasts right now.', [], $status);
    }
    respond(false, $e->getMessage(), [], $status);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
