<?php

const FILE_STORAGE_VISIBILITY_TENANT_PUBLIC = 'tenant_public';
const FILE_STORAGE_ALLOWED_VISIBILITIES = [
    FILE_STORAGE_VISIBILITY_TENANT_PUBLIC
];
const FILE_STORAGE_BACKUP_MAX_BYTES = 12 * 1024 * 1024;

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

function file_storage_managed_assets_dir(): ?string {
    $dir = realpath(__DIR__ . '/../assets/images');
    return ($dir !== false && is_dir($dir)) ? $dir : null;
}

function file_storage_managed_asset_path(string $filename): ?string {
    $safeName = file_storage_sanitize_filename($filename);
    $baseDir = file_storage_managed_assets_dir();
    if ($safeName === '' || $baseDir === null) {
        return null;
    }
    return $baseDir . DIRECTORY_SEPARATOR . $safeName;
}

function file_storage_detect_mime_type_for_path(string $path): string {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return strtolower((string)($finfo->file($path) ?: 'application/octet-stream'));
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

function ensure_file_storage_backup_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS file_asset_backups (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            asset_type VARCHAR(40) NOT NULL DEFAULT 'generic',
            mime_type VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            sha256 CHAR(64) NOT NULL DEFAULT '',
            file_blob LONGBLOB NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_file_asset_backups_filename (filename),
            INDEX idx_file_asset_backups_business_id (business_id),
            INDEX idx_file_asset_backups_asset_type (asset_type)
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

function file_storage_backup_asset_from_path(
    mysqli $conn,
    int $businessId,
    string $filename,
    string $path,
    string $assetType = 'generic'
): void {
    $safeName = file_storage_sanitize_filename($filename);
    $safePath = trim($path);
    if ($businessId <= 0 || $safeName === '' || $safePath === '') {
        return;
    }
    if (!is_file($safePath) || !is_readable($safePath)) {
        throw new RuntimeException('Managed asset file is not readable for backup.');
    }

    $fileSize = filesize($safePath);
    if ($fileSize === false || $fileSize <= 0) {
        throw new RuntimeException('Managed asset file is empty.');
    }
    if ($fileSize > FILE_STORAGE_BACKUP_MAX_BYTES) {
        throw new RuntimeException('Managed asset file exceeds backup size limit.');
    }

    $contents = file_get_contents($safePath);
    if (!is_string($contents) || $contents === '') {
        throw new RuntimeException('Managed asset file could not be read for backup.');
    }

    $safeAssetType = strtolower(trim($assetType));
    if ($safeAssetType === '') {
        $safeAssetType = 'generic';
    }
    if (strlen($safeAssetType) > 40) {
        $safeAssetType = substr($safeAssetType, 0, 40);
    }

    $mimeType = file_storage_detect_mime_type_for_path($safePath);
    if (strlen($mimeType) > 120) {
        $mimeType = substr($mimeType, 0, 120);
    }
    $sha256 = hash('sha256', $contents);

    ensure_file_storage_backup_table($conn);
    $stmt = $conn->prepare(
        "INSERT INTO file_asset_backups
            (business_id, filename, asset_type, mime_type, file_size, sha256, file_blob)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            business_id = VALUES(business_id),
            asset_type = VALUES(asset_type),
            mime_type = VALUES(mime_type),
            file_size = VALUES(file_size),
            sha256 = VALUES(sha256),
            file_blob = VALUES(file_blob)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare managed asset backup statement.');
    }

    $blob = null;
    $sizeInt = intval($fileSize);
    $stmt->bind_param('isssisb', $businessId, $safeName, $safeAssetType, $mimeType, $sizeInt, $sha256, $blob);
    $stmt->send_long_data(6, $contents);
    $stmt->execute();
    $stmt->close();
}

function file_storage_fetch_backup_record(mysqli $conn, string $filename): ?array {
    $safeName = file_storage_sanitize_filename($filename);
    if ($safeName === '' || !tenant_table_exists($conn, 'file_asset_backups')) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT business_id, filename, asset_type, mime_type, file_size, sha256, file_blob
         FROM file_asset_backups
         WHERE filename = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $safeName);
    $stmt->execute();

    $row = null;
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
        }
    } else {
        $stmt->bind_result($businessId, $filenameValue, $assetType, $mimeType, $fileSize, $sha256, $fileBlob);
        if ($stmt->fetch()) {
            $row = [
                'business_id' => $businessId,
                'filename' => $filenameValue,
                'asset_type' => $assetType,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'sha256' => $sha256,
                'file_blob' => $fileBlob
            ];
        }
    }
    $stmt->close();

    return is_array($row) ? $row : null;
}

function file_storage_restore_managed_asset_contents(string $filename, string $contents, string $sha256 = ''): bool {
    $safeName = file_storage_sanitize_filename($filename);
    $destination = file_storage_managed_asset_path($safeName);
    if ($safeName === '' || $destination === null || $contents === '') {
        return false;
    }

    $baseDir = dirname($destination);
    if (!is_dir($baseDir) || !is_writable($baseDir)) {
        return false;
    }

    if ($sha256 !== '' && !hash_equals(strtolower($sha256), strtolower(hash('sha256', $contents)))) {
        return false;
    }

    $written = @file_put_contents($destination, $contents, LOCK_EX);
    if ($written === false) {
        return false;
    }

    @chmod($destination, 0644);
    if (!is_file($destination) || !is_readable($destination)) {
        return false;
    }

    if ($sha256 !== '') {
        $restoredHash = @hash_file('sha256', $destination);
        if (!is_string($restoredHash) || !hash_equals(strtolower($sha256), strtolower($restoredHash))) {
            return false;
        }
    }

    return true;
}

function file_storage_has_policy_record(mysqli $conn, string $filename): bool {
    $safeName = file_storage_sanitize_filename($filename);
    if ($safeName === '') {
        return false;
    }
    if (!tenant_table_exists($conn, 'file_assets')) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
         FROM file_assets
         WHERE filename = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $safeName);
    $stmt->execute();
    $result = $stmt->get_result();
    $hasRecord = $result instanceof mysqli_result && $result->num_rows > 0;
    $stmt->close();

    return $hasRecord;
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

    // Legacy uploads remain public until they are explicitly enrolled in file_assets.
    if (!file_storage_has_policy_record($conn, $safeName)) {
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
