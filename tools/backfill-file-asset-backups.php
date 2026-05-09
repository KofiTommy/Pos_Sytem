<?php
$sessionDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'possystem-cli-sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0777, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    ini_set('session.save_path', $sessionDir);
}

include_once __DIR__ . '/../php/db-connection.php';
include_once __DIR__ . '/../php/tenant-context.php';
include_once __DIR__ . '/../php/file-storage.php';

function add_backup_candidate(array &$candidates, int $businessId, string $filename, string $assetType): void {
    $safeName = file_storage_sanitize_filename($filename);
    if ($businessId <= 0 || $safeName === '' || !file_storage_is_managed_upload_filename($safeName)) {
        return;
    }

    if (!isset($candidates[$safeName])) {
        $candidates[$safeName] = [
            'business_id' => $businessId,
            'filename' => $safeName,
            'asset_type' => $assetType
        ];
        return;
    }

    if ($candidates[$safeName]['asset_type'] === 'generic' && $assetType !== 'generic') {
        $candidates[$safeName]['asset_type'] = $assetType;
    }
}

try {
    ensure_multitenant_schema($conn);
    ensure_file_storage_policy_table($conn);
    ensure_file_storage_backup_table($conn);

    $candidates = [];

    if (tenant_table_exists($conn, 'file_assets')) {
        $result = $conn->query("SELECT business_id, filename, asset_type FROM file_assets");
        while ($row = $result->fetch_assoc()) {
            add_backup_candidate(
                $candidates,
                intval($row['business_id'] ?? 0),
                (string)($row['filename'] ?? ''),
                trim((string)($row['asset_type'] ?? 'generic')) ?: 'generic'
            );
        }
    }

    if (tenant_table_exists($conn, 'products')) {
        $result = $conn->query(
            "SELECT business_id, image
             FROM products
             WHERE image IS NOT NULL
               AND image <> ''
               AND image LIKE 'product-%'"
        );
        while ($row = $result->fetch_assoc()) {
            add_backup_candidate(
                $candidates,
                intval($row['business_id'] ?? 0),
                (string)($row['image'] ?? ''),
                'product_image'
            );
        }
    }

    if (tenant_table_exists($conn, 'business_settings')) {
        $result = $conn->query(
            "SELECT business_id, logo_filename
             FROM business_settings
             WHERE logo_filename IS NOT NULL
               AND logo_filename <> ''
               AND logo_filename LIKE 'business-logo-%'"
        );
        while ($row = $result->fetch_assoc()) {
            add_backup_candidate(
                $candidates,
                intval($row['business_id'] ?? 0),
                (string)($row['logo_filename'] ?? ''),
                'business_logo'
            );
        }
    }

    $processed = 0;
    $backedUp = 0;
    $missing = 0;
    $errors = 0;

    foreach ($candidates as $candidate) {
        $processed++;
        $path = file_storage_managed_asset_path((string)$candidate['filename']);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            $missing++;
            echo "Missing: {$candidate['filename']}" . PHP_EOL;
            continue;
        }

        try {
            file_storage_backup_asset_from_path(
                $conn,
                intval($candidate['business_id']),
                (string)$candidate['filename'],
                $path,
                (string)$candidate['asset_type']
            );
            $backedUp++;
            echo "Backed up: {$candidate['filename']}" . PHP_EOL;
        } catch (Exception $e) {
            $errors++;
            echo "Error: {$candidate['filename']} -> {$e->getMessage()}" . PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo "Processed: {$processed}" . PHP_EOL;
    echo "Backed up: {$backedUp}" . PHP_EOL;
    echo "Missing: {$missing}" . PHP_EOL;
    echo "Errors: {$errors}" . PHP_EOL;
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
