<?php
header('Content-Type: application/json');
include 'admin-auth.php';
include_once __DIR__ . '/hq-auth.php';
include 'db-connection.php';
include 'tenant-context.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function support_input(array $body, string $key): string {
    if (array_key_exists($key, $body)) {
        return trim((string)$body[$key]);
    }
    if (isset($_POST[$key])) {
        return trim((string)$_POST[$key]);
    }
    return '';
}

function support_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '' || count($params) === 0) {
        return;
    }
    $bind = [$types];
    foreach ($params as $index => $_value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function ensure_support_messages_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS hq_support_messages (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id BIGINT NULL,
            business_code VARCHAR(64) NOT NULL DEFAULT '',
            business_name VARCHAR(180) NOT NULL DEFAULT '',
            sender_role ENUM('owner','customer') NOT NULL DEFAULT 'customer',
            sender_name VARCHAR(120) NOT NULL,
            sender_email VARCHAR(160) NOT NULL,
            sender_phone VARCHAR(40) NOT NULL DEFAULT '',
            subject VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('new','in_progress','resolved','closed') NOT NULL DEFAULT 'new',
            hq_note TEXT NULL,
            resolved_by VARCHAR(80) NOT NULL DEFAULT '',
            resolved_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hq_support_status_created (status, created_at),
            INDEX idx_hq_support_business_code (business_code),
            INDEX idx_hq_support_sender_role (sender_role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function normalize_support_status(string $status): string {
    $status = strtolower(trim($status));
    if (in_array($status, ['new', 'in_progress', 'resolved', 'closed'], true)) {
        return $status;
    }
    return '';
}

try {
    ensure_multitenant_schema($conn);
    ensure_support_messages_table($conn);

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        $body = [];
    }

    if ($method === 'POST') {
        $override = strtoupper(support_input($body, '_method'));
        if (in_array($override, ['PUT'], true)) {
            $method = $override;
        }
    }

    if ($method === 'POST') {
        $isOwnerSender = is_admin_authenticated() && current_user_role() === 'owner';
        if ($isOwnerSender) {
            require_roles_api(['owner']);
        }

        $businessId = 0;
        $businessCode = '';
        $businessName = '';
        if ($isOwnerSender) {
            $businessId = current_business_id();
            $businessCode = current_business_code();
            $businessName = trim((string)($_SESSION['business_name'] ?? ''));
            if ($businessId <= 0) {
                respond(false, 'Invalid business context. Please sign in again.');
            }
        } else {
            $business = tenant_require_business_context($conn, [], true);
            $businessId = intval($business['id'] ?? 0);
            $businessCode = trim((string)($business['business_code'] ?? ''));
            $businessName = trim((string)($business['business_name'] ?? ''));
            if ($businessId <= 0) {
                respond(false, 'Unable to identify business context.');
            }
        }

        if ($businessName === '' || $businessCode === '') {
            $business = tenant_fetch_business_by_id($conn, $businessId);
            if (is_array($business)) {
                if ($businessCode === '') {
                    $businessCode = trim((string)($business['business_code'] ?? ''));
                }
                if ($businessName === '') {
                    $businessName = trim((string)($business['business_name'] ?? ''));
                }
            }
        }

        $name = support_input($body, 'name');
        $email = support_input($body, 'email');
        $phone = support_input($body, 'phone');
        $subject = support_input($body, 'subject');
        $message = support_input($body, 'message');
        $senderRole = $isOwnerSender ? 'owner' : 'customer';

        if ($name === '' && $isOwnerSender) {
            $name = trim((string)($_SESSION['username'] ?? ''));
        }
        if ($email === '' && $isOwnerSender) {
            $email = trim((string)($_SESSION['email'] ?? ''));
        }

        if ($name === '' || $email === '' || $subject === '' || $message === '') {
            respond(false, 'Name, email, subject, and message are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(false, 'Please provide a valid email address.');
        }
        if (strlen($name) > 120 || strlen($email) > 160 || strlen($phone) > 40 || strlen($subject) > 180) {
            respond(false, 'One or more fields are too long.');
        }
        if (strlen($message) > 6000) {
            respond(false, 'Message is too long.');
        }

        $stmt = $conn->prepare(
            "INSERT INTO hq_support_messages
             (business_id, business_code, business_name, sender_role, sender_name, sender_email, sender_phone, subject, message, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')"
        );
        $stmt->bind_param(
            'issssssss',
            $businessId,
            $businessCode,
            $businessName,
            $senderRole,
            $name,
            $email,
            $phone,
            $subject,
            $message
        );
        $stmt->execute();
        $ticketId = intval($conn->insert_id);
        $stmt->close();

        respond(true, 'Support message sent successfully.', [
            'ticket_id' => $ticketId
        ]);
    }

    if ($method === 'GET') {
        hq_require_api();

        $status = normalize_support_status(trim((string)($_GET['status'] ?? '')));
        $senderRole = strtolower(trim((string)($_GET['sender_role'] ?? '')));
        if ($senderRole !== '' && !in_array($senderRole, ['owner', 'customer'], true)) {
            respond(false, 'Invalid sender role filter.');
        }
        $businessCode = tenant_slugify_code(trim((string)($_GET['business_code'] ?? '')));
        $query = trim((string)($_GET['q'] ?? ''));
        $limit = intval($_GET['limit'] ?? 50);
        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $where = [];
        $types = '';
        $params = [];
        if ($status !== '') {
            $where[] = 'status = ?';
            $types .= 's';
            $params[] = $status;
        }
        if ($senderRole !== '') {
            $where[] = 'sender_role = ?';
            $types .= 's';
            $params[] = $senderRole;
        }
        if ($businessCode !== '') {
            $where[] = 'business_code = ?';
            $types .= 's';
            $params[] = $businessCode;
        }
        if ($query !== '') {
            $where[] = '(business_name LIKE ? OR business_code LIKE ? OR sender_name LIKE ? OR sender_email LIKE ? OR subject LIKE ? OR message LIKE ?)';
            $search = '%' . $query . '%';
            $types .= 'ssssss';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql = "SELECT id, business_id, business_code, business_name, sender_role, sender_name, sender_email, sender_phone, subject, message, status, hq_note, resolved_by, resolved_at, created_at, updated_at
                FROM hq_support_messages";
        if (count($where) > 0) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $stmt = $conn->prepare($sql);
        support_bind_params($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt->close();

        $countResult = $conn->query("SELECT status, COUNT(*) AS total FROM hq_support_messages GROUP BY status");
        $counts = ['new' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
        if ($countResult) {
            while ($row = $countResult->fetch_assoc()) {
                $key = normalize_support_status((string)($row['status'] ?? ''));
                if ($key !== '' && array_key_exists($key, $counts)) {
                    $counts[$key] = intval($row['total'] ?? 0);
                }
            }
            $countResult->close();
        }

        respond(true, '', [
            'messages' => $messages,
            'counts' => $counts
        ]);
    }

    if ($method === 'PUT') {
        hq_require_api();

        $id = intval($body['id'] ?? 0);
        if ($id <= 0) {
            respond(false, 'Invalid support message ID.');
        }

        $requestedStatus = normalize_support_status(trim((string)($body['status'] ?? '')));
        $noteProvided = array_key_exists('hq_note', $body);
        $requestedNote = $noteProvided ? trim((string)$body['hq_note']) : null;
        if ($requestedStatus === '' && !$noteProvided) {
            respond(false, 'No update payload provided.');
        }
        if ($requestedNote !== null && strlen($requestedNote) > 6000) {
            respond(false, 'HQ note is too long.');
        }

        $currentStmt = $conn->prepare(
            "SELECT id, status, hq_note, resolved_by, resolved_at
             FROM hq_support_messages
             WHERE id = ?
             LIMIT 1"
        );
        $currentStmt->bind_param('i', $id);
        $currentStmt->execute();
        $current = $currentStmt->get_result()->fetch_assoc();
        $currentStmt->close();
        if (!$current) {
            respond(false, 'Support message not found.');
        }

        $nextStatus = $requestedStatus !== '' ? $requestedStatus : (string)$current['status'];
        $nextNote = $noteProvided ? $requestedNote : (string)($current['hq_note'] ?? '');
        $nextResolvedBy = (string)($current['resolved_by'] ?? '');
        $nextResolvedAt = $current['resolved_at'] ?? null;
        if (in_array($nextStatus, ['resolved', 'closed'], true)) {
            $nextResolvedBy = hq_current_username();
            $nextResolvedAt = gmdate('Y-m-d H:i:s');
        } elseif ($requestedStatus !== '') {
            $nextResolvedBy = '';
            $nextResolvedAt = null;
        }

        $updateStmt = $conn->prepare(
            "UPDATE hq_support_messages
             SET status = ?, hq_note = ?, resolved_by = ?, resolved_at = ?
             WHERE id = ?"
        );
        $updateStmt->bind_param('ssssi', $nextStatus, $nextNote, $nextResolvedBy, $nextResolvedAt, $id);
        $updateStmt->execute();
        $updateStmt->close();

        respond(true, 'Support message updated successfully.');
    }

    http_response_code(405);
    respond(false, 'Method not allowed.');
} catch (Exception $e) {
    http_response_code(500);
    error_log('support-messages.php: ' . $e->getMessage());
    respond(false, 'Unable to process support messages right now.');
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
