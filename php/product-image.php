<?php
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

const PRODUCT_IMAGE_DEFAULT_FILE = 'pexels-jonathan-nenemann-12114822.jpg';
const PRODUCT_IMAGE_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

function has_allowed_image_extension(string $name): bool {
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return $extension !== '' && in_array($extension, PRODUCT_IMAGE_ALLOWED_EXTENSIONS, true);
}

function sanitize_image_name($value): string {
    $raw = str_replace('\\', '/', trim((string)$value));
    $base = basename($raw);
    $base = preg_replace('/[\x00-\x1F\x7F]/', '', $base);
    if (strlen($base) > 255) {
        $base = substr($base, 0, 255);
    }
    return trim($base);
}

function image_path_candidates(string $imagesDir, string $requestedName, string $defaultName): array {
    $candidates = [];
    $safeRequested = sanitize_image_name($requestedName);
    if ($safeRequested !== '' && has_allowed_image_extension($safeRequested)) {
        $candidates[] = $safeRequested;
    }
    $safeDefault = sanitize_image_name($defaultName);
    if ($safeDefault !== '' && has_allowed_image_extension($safeDefault)) {
        $candidates[] = $safeDefault;
    }
    return array_values(array_unique($candidates));
}

function resolve_existing_image_path(string $imagesDir, string $name): ?string {
    $safeName = sanitize_image_name($name);
    if ($safeName === '' || !has_allowed_image_extension($safeName)) {
        return null;
    }

    $directPath = $imagesDir . DIRECTORY_SEPARATOR . $safeName;
    if (is_file($directPath) && is_readable($directPath)) {
        return $directPath;
    }

    $targetLower = strtolower($safeName);
    $iterator = @scandir($imagesDir);
    if (!is_array($iterator)) {
        return null;
    }
    foreach ($iterator as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (strtolower($entry) !== $targetLower) {
            continue;
        }
        $candidate = $imagesDir . DIRECTORY_SEPARATOR . $entry;
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function output_image_file(string $path): void {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)($finfo->file($path) ?: 'application/octet-stream'));
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!in_array($mime, $allowedMimeTypes, true)) {
        throw new RuntimeException('Unsupported image MIME type');
    }
    $size = filesize($path);

    header('Cache-Control: public, max-age=86400');
    header('Content-Type: ' . $mime);
    if ($size !== false) {
        header('Content-Length: ' . (string)$size);
    }
    readfile($path);
    exit();
}

try {
    $imagesDir = realpath(__DIR__ . '/../assets/images');
    if ($imagesDir === false || !is_dir($imagesDir)) {
        throw new Exception('Images directory is missing.');
    }

    $requestedName = isset($_GET['name']) ? (string)$_GET['name'] : '';
    $defaultName = PRODUCT_IMAGE_DEFAULT_FILE;
    $candidates = image_path_candidates($imagesDir, $requestedName, $defaultName);

    foreach ($candidates as $candidateName) {
        $resolved = resolve_existing_image_path($imagesDir, $candidateName);
        if ($resolved !== null) {
            output_image_file($resolved);
        }
    }

    http_response_code(404);
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: image/svg+xml; charset=UTF-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500"><rect width="800" height="500" fill="#eef4f3"/><g fill="#6b7280" font-family="Segoe UI, Arial, sans-serif" text-anchor="middle"><text x="400" y="240" font-size="34" font-weight="700">Image unavailable</text><text x="400" y="282" font-size="20">Please check back later</text></g></svg>';
    exit();
} catch (Exception $e) {
    error_log('product-image.php: ' . $e->getMessage());
    http_response_code(500);
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: image/svg+xml; charset=UTF-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500"><rect width="800" height="500" fill="#fee2e2"/><g fill="#991b1b" font-family="Segoe UI, Arial, sans-serif" text-anchor="middle"><text x="400" y="240" font-size="30" font-weight="700">Unable to load image</text><text x="400" y="282" font-size="18">Please try again later</text></g></svg>';
    exit();
}
?>
