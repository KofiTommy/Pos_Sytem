<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

include 'db-connection.php';
include 'tenant-context.php';

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

function validate_password_strength($password): bool {
    if (!is_string($password) || strlen($password) < 10) {
        return false;
    }
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) return false;
    return true;
}

function ensure_owner_reset_tokens_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS owner_password_reset_tokens (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            request_ip VARCHAR(45) NOT NULL DEFAULT '',
            request_user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_owner_reset_token_hash (token_hash),
            INDEX idx_owner_reset_user (user_id, created_at),
            INDEX idx_owner_reset_business (business_id, created_at),
            INDEX idx_owner_reset_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(false, 'Method not allowed', [], 405);
    }

    ensure_multitenant_schema($conn);
    ensure_owner_reset_tokens_table($conn);

    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $token = trim((string)($body['token'] ?? ''));
    $businessCode = tenant_slugify_code(trim((string)($body['business_code'] ?? ($body['tenant'] ?? ''))));
    $newPassword = (string)($body['new_password'] ?? '');
    $confirmPassword = (string)($body['confirm_password'] ?? '');

    if ($token === '' || $newPassword === '') {
        respond(false, 'Reset token and new password are required.', [], 422);
    }
    if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
        respond(false, 'Invalid or expired reset link.', [], 400);
    }
    if ($confirmPassword !== '' && $newPassword !== $confirmPassword) {
        respond(false, 'Password confirmation does not match.', [], 422);
    }
    if (!validate_password_strength($newPassword)) {
        respond(false, 'Password must be at least 10 chars and include uppercase, lowercase, number, and symbol.', [], 422);
    }

    $tokenHash = hash('sha256', $token);
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $conn->begin_transaction();
    try {
        $tokenStmt = $conn->prepare(
            "SELECT
                t.id,
                t.user_id,
                t.business_id,
                t.expires_at,
                t.used_at,
                u.role,
                b.business_code
             FROM owner_password_reset_tokens t
             JOIN users u ON u.id = t.user_id AND u.business_id = t.business_id
             JOIN businesses b ON b.id = t.business_id
             WHERE t.token_hash = ?
             LIMIT 1
             FOR UPDATE"
        );
        $tokenStmt->bind_param('s', $tokenHash);
        $tokenStmt->execute();
        $tokenRow = $tokenStmt->get_result()->fetch_assoc();
        $tokenStmt->close();

        if (!$tokenRow) {
            throw new Exception('Invalid or expired reset link.', 400);
        }

        $usedAt = trim((string)($tokenRow['used_at'] ?? ''));
        if ($usedAt !== '') {
            throw new Exception('This reset link has already been used.', 400);
        }

        $expiresAt = trim((string)($tokenRow['expires_at'] ?? ''));
        if ($expiresAt === '' || strtotime($expiresAt) <= time()) {
            throw new Exception('This reset link has expired. Request a new one.', 400);
        }

        if ($businessCode !== '' && strtolower($businessCode) !== strtolower((string)($tokenRow['business_code'] ?? ''))) {
            throw new Exception('Invalid reset link for this business.', 400);
        }

        $role = strtolower(trim((string)($tokenRow['role'] ?? '')));
        if ($role === 'admin') {
            $role = 'owner';
        }
        if ($role !== 'owner') {
            throw new Exception('Reset is only available for owner accounts.', 403);
        }

        $tokenId = intval($tokenRow['id'] ?? 0);
        $userId = intval($tokenRow['user_id'] ?? 0);
        $tokenBusinessId = intval($tokenRow['business_id'] ?? 0);
        if ($tokenId <= 0 || $userId <= 0 || $tokenBusinessId <= 0) {
            throw new Exception('Invalid or expired reset link.', 400);
        }

        $updateUserStmt = $conn->prepare(
            "UPDATE users
             SET password = ?
             WHERE id = ? AND business_id = ?"
        );
        $updateUserStmt->bind_param('sii', $passwordHash, $userId, $tokenBusinessId);
        $updateUserStmt->execute();
        if (intval($updateUserStmt->affected_rows) <= 0) {
            $updateUserStmt->close();
            throw new Exception('Unable to reset password for this account.');
        }
        $updateUserStmt->close();

        $markCurrentStmt = $conn->prepare(
            "UPDATE owner_password_reset_tokens
             SET used_at = NOW()
             WHERE id = ? AND used_at IS NULL"
        );
        $markCurrentStmt->bind_param('i', $tokenId);
        $markCurrentStmt->execute();
        if (intval($markCurrentStmt->affected_rows) <= 0) {
            $markCurrentStmt->close();
            throw new Exception('This reset link has already been used.', 400);
        }
        $markCurrentStmt->close();

        $invalidateOthersStmt = $conn->prepare(
            "UPDATE owner_password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = ? AND id <> ? AND used_at IS NULL AND expires_at > NOW()"
        );
        $invalidateOthersStmt->bind_param('ii', $userId, $tokenId);
        $invalidateOthersStmt->execute();
        $invalidateOthersStmt->close();

        $conn->commit();
    } catch (Exception $inner) {
        $conn->rollback();
        throw $inner;
    }

    respond(true, 'Password reset successful. You can now sign in.');
} catch (Exception $e) {
    $status = intval($e->getCode());
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('owner-password-reset.php: ' . $e->getMessage());
        respond(false, 'Unable to reset password right now.', [], $status);
    }
    respond(false, $e->getMessage(), [], $status);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
