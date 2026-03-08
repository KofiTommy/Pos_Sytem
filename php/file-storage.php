<?php

const FILE_STORAGE_VISIBILITY_TENANT_PUBLIC = 'tenant_public';
const FILE_STORAGE_ALLOWED_VISIBILITIES = [
    FILE_STORAGE_VISIBILITY_TENANT_PUBLIC
];

function file_storage_sanitize_filename($value): string {
    $raw = str_replace('\\', '/', trim((string)$value));
    $base = basename($raw);
    $base = preg_replace('/[\x00-\x1F\x7F]/', '', $base);
    if (!is_string($base)) {
        return '';
    }
    if (strlen($base) > 255) {
        $base = substr($base, 0, 255);
    }
    return trim($base);
}

function file_storage_is_managed_upload_filename(string $filename): bool {
    $safe = file_storage_sanitize_filename($filename);
    if ($safe === '') {
        return false;
    }
    return preg_match('/^(?:product|business-logo)-[a-z0-9._-]+\.(?:jpg|jpeg|png|gif|webp)$/i', $safe) === 1;
}

function ensure_file_storage_policy_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS file_assets (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            asset_type VARCHAR(40) NOT NULL DEFAULT 'generic',
            visibility VARCHAR(20) NOT NULL DEFAULT 'tenant_public',
            created_by_user_id INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_file_assets_filename (filename),
            INDEX idx_file_assets_business_id (business_id),
            INDEX idx_file_assets_business_visibility (business_id, visibility),
            INDEX idx_file_assets_asset_type (asset_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function register_file_asset_policy(
    mysqli $conn,
    int $businessId,
    string $filename,
    string $assetType = 'generic',
    string $visibility = FILE_STORAGE_VISIBILITY_TENANT_PUBLIC,
    int $createdByUserId = 0
): void {
    $safeName = file_storage_sanitize_filename($filename);
    if ($businessId <= 0 || $safeName === '') {
        return;
    }

    $safeVisibility = strtolower(trim($visibility));
    if (!in_array($safeVisibility, FILE_STORAGE_ALLOWED_VISIBILITIES, true)) {
        $safeVisibility = FILE_STORAGE_VISIBILITY_TENANT_PUBLIC;
    }

    $safeAssetType = strtolower(trim($assetType));
    if ($safeAssetType === '') {
        $safeAssetType = 'generic';
    }
    if (strlen($safeAssetType) > 40) {
        $safeAssetType = substr($safeAssetType, 0, 40);
    }

    ensure_file_storage_policy_table($conn);
    $stmt = $conn->prepare(
        "INSERT INTO file_assets (business_id, filename, asset_type, visibility, created_by_user_id)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            business_id = VALUES(business_id),
            asset_type = VALUES(asset_type),
            visibility = VALUES(visibility),
            created_by_user_id = VALUES(created_by_user_id)"
    );
    $stmt->bind_param('isssi', $businessId, $safeName, $safeAssetType, $safeVisibility, $createdByUserId);
    $stmt->execute();
    $stmt->close();
}

function file_storage_owner_business_ids(mysqli $conn, string $filename): array {
    $safeName = file_storage_sanitize_filename($filename);
    if ($safeName === '') {
        return [];
    }

    $ids = [];

    if (tenant_table_exists($conn, 'file_assets')) {
        $stmt = $conn->prepare(
            "SELECT business_id
             FROM file_assets
             WHERE filename = ?
             LIMIT 10"
        );
        $stmt->bind_param('s', $safeName);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = intval($row['business_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $stmt->close();
    }

    // Backward compatibility for files uploaded before file_assets policy table existed.
    if (tenant_table_exists($conn, 'products')) {
        $stmt = $conn->prepare(
            "SELECT DISTINCT business_id
             FROM products
             WHERE image = ?
             LIMIT 10"
        );
        $stmt->bind_param('s', $safeName);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = intval($row['business_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $stmt->close();
    }

    if (tenant_table_exists($conn, 'business_settings')) {
        $stmt = $conn->prepare(
            "SELECT DISTINCT business_id
             FROM business_settings
             WHERE logo_filename = ?
             LIMIT 10"
        );
        $stmt->bind_param('s', $safeName);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = intval($row['business_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $stmt->close();
    }

    $unique = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
        return $id > 0;
    })));
    return $unique;
}

function file_storage_explicit_business_code(array $payload = []): string {
    $candidates = [];
    if (isset($payload['business_code'])) {
        $candidates[] = $payload['business_code'];
    }
    if (isset($payload['tenant'])) {
        $candidates[] = $payload['tenant'];
    }
    if (isset($_GET['business_code'])) {
        $candidates[] = $_GET['business_code'];
    }
    if (isset($_GET['tenant'])) {
        $candidates[] = $_GET['tenant'];
    }
    if (isset($_POST['business_code'])) {
        $candidates[] = $_POST['business_code'];
    }
    if (isset($_POST['tenant'])) {
        $candidates[] = $_POST['tenant'];
    }
    if (function_exists('tenant_request_uri_business_code')) {
        $fromPath = tenant_request_uri_business_code();
        if ($fromPath !== '') {
            $candidates[] = $fromPath;
        }
    }
    if (!empty($_SERVER['HTTP_X_BUSINESS_CODE'])) {
        $candidates[] = $_SERVER['HTTP_X_BUSINESS_CODE'];
    }

    foreach ($candidates as $candidate) {
        $raw = trim((string)$candidate);
        if ($raw === '') {
            continue;
        }

        if (function_exists('tenant_slugify_code')) {
            $normalized = tenant_slugify_code($raw);
        } else {
            $normalized = strtolower($raw);
            $normalized = preg_replace('/[^a-z0-9-]+/', '-', $normalized);
            $normalized = trim((string)$normalized, '-');
        }

        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

function file_storage_requester_business_id(mysqli $conn, array $payload = []): int {
    if (function_exists('is_admin_authenticated') && function_exists('current_business_id')) {
        if (is_admin_authenticated()) {
            $id = intval(current_business_id());
            if ($id > 0) {
                return $id;
            }
        }
    }

    $code = file_storage_explicit_business_code($payload);
    if ($code === '') {
        return 0;
    }

    if (!function_exists('tenant_fetch_business_by_code')) {
        return 0;
    }

    $business = tenant_fetch_business_by_code($conn, $code);
    if (!is_array($business)) {
        return 0;
    }
    if (strtolower((string)($business['status'] ?? 'active')) !== 'active') {
        return 0;
    }
    return intval($business['id'] ?? 0);
}

function file_storage_can_access_filename(mysqli $conn, string $filename, int $requesterBusinessId): bool {
    $safeName = file_storage_sanitize_filename($filename);
    if ($safeName === '') {
        return false;
    }

    // Static platform assets remain public.
    if (!file_storage_is_managed_upload_filename($safeName)) {
        return true;
    }

    if ($requesterBusinessId <= 0) {
        return false;
    }

    $owners = file_storage_owner_business_ids($conn, $safeName);
    if (count($owners) === 0) {
        return false;
    }

    return in_array($requesterBusinessId, $owners, true);
}

