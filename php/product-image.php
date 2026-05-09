<?php
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
include_once __DIR__ . '/admin-auth.php';
include_once __DIR__ . '/db-connection.php';
include_once __DIR__ . '/tenant-context.php';
include_once __DIR__ . '/file-storage.php';

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

function is_uploaded_product_image_name(string $name): bool {
    return preg_match('/^product-[a-z0-9._-]+\.(?:jpg|jpeg|png|gif|webp)$/i', sanitize_image_name($name)) === 1;
}

function is_uploaded_business_logo_name(string $name): bool {
    return preg_match('/^business-logo-[a-z0-9._-]+\.(?:jpg|jpeg|png|gif|webp)$/i', sanitize_image_name($name)) === 1;
}

function image_path_candidates(string $requestedName, string $defaultName): array {
    $candidates = [];
    $safeRequested = sanitize_image_name($requestedName);
    if ($safeRequested !== '' && has_allowed_image_extension($safeRequested)) {
        $candidates[] = $safeRequested;
        return array_values(array_unique($candidates));
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

function output_image_file(string $path, bool $allowPublicCache = true): void {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)($finfo->file($path) ?: 'application/octet-stream'));
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!in_array($mime, $allowedMimeTypes, true)) {
        throw new RuntimeException('Unsupported image MIME type');
    }
    $size = filesize($path);

    if ($allowPublicCache) {
        header('Cache-Control: public, max-age=86400');
    } else {
        header('Cache-Control: private, no-store, max-age=0');
    }
    header('Content-Type: ' . $mime);
    if ($size !== false) {
        header('Content-Length: ' . (string)$size);
    }
    readfile($path);
    exit();
}

function output_image_blob(string $contents, string $mimeType, bool $allowPublicCache = true, int $reportedSize = 0): void {
    $mime = strtolower(trim($mimeType));
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!in_array($mime, $allowedMimeTypes, true)) {
        throw new RuntimeException('Unsupported backup image MIME type');
    }

    if ($allowPublicCache) {
        header('Cache-Control: public, max-age=86400');
    } else {
        header('Cache-Control: private, no-store, max-age=0');
    }
    header('Content-Type: ' . $mime);

    $size = $reportedSize > 0 ? $reportedSize : strlen($contents);
    if ($size > 0) {
        header('Content-Length: ' . (string)$size);
    }

    echo $contents;
    exit();
}

function output_inline_svg(string $svg, bool $allowPublicCache = true, int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    if ($allowPublicCache) {
        header('Cache-Control: public, max-age=3600');
    } else {
        header('Cache-Control: private, no-store, max-age=0');
    }
    header('Content-Type: image/svg+xml; charset=UTF-8');
    echo $svg;
    exit();
}

function svg_escape_text($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function svg_wrap_text_lines(string $text, int $maxChars = 18, int $maxLines = 2): array {
    $clean = trim(preg_replace('/\s+/', ' ', $text));
    if ($clean === '') {
        return ['Catalog Item'];
    }

    $words = preg_split('/\s+/', $clean) ?: [$clean];
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $candidate = $current === '' ? $word : ($current . ' ' . $word);
        if (strlen($candidate) <= $maxChars) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        if (strlen($word) > $maxChars) {
            $lines[] = substr($word, 0, $maxChars);
            $current = '';
        } else {
            $current = $word;
        }

        if (count($lines) >= $maxLines) {
            break;
        }
    }

    if (count($lines) < $maxLines && $current !== '') {
        $lines[] = $current;
    }

    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
    }

    $lastIndex = count($lines) - 1;
    if ($lastIndex >= 0) {
        $remaining = trim(substr($clean, strlen(implode(' ', $lines))));
        if ($remaining !== '') {
            $lines[$lastIndex] = rtrim(substr($lines[$lastIndex], 0, max(0, $maxChars - 3))) . '...';
        }
    }

    return $lines ?: ['Catalog Item'];
}

function placeholder_palette_for_seed(string $seed, string $theme = 'default'): array {
    $palettes = [
        'perfume' => [
            ['#1d102f', '#4c1d95', '#f59e0b', '#fef3c7', '#f8e7ff'],
            ['#2b0f3a', '#7c3aed', '#fb7185', '#ffe4f1', '#fde8ff'],
            ['#10243d', '#2563eb', '#f97316', '#fff1d6', '#dbeafe'],
            ['#25112d', '#be185d', '#fbbf24', '#fff4cc', '#fce7f3']
        ],
        'default' => [
            ['#0f172a', '#0f766e', '#38bdf8', '#dff8ff', '#d9fff7'],
            ['#1f2937', '#0369a1', '#10b981', '#d9fdf3', '#e0f2fe'],
            ['#111827', '#1d4ed8', '#22c55e', '#e5ffef', '#dbeafe'],
            ['#1e293b', '#0f766e', '#f59e0b', '#fff3d7', '#d9fffb']
        ]
    ];

    $key = isset($palettes[$theme]) ? $theme : 'default';
    $seedValue = intval(sprintf('%u', crc32($seed)));
    $paletteIndex = $seedValue % count($palettes[$key]);
    return $palettes[$key][$paletteIndex];
}

function detect_placeholder_theme(string $businessName, string $productName, string $category): string {
    $haystack = strtolower(trim($businessName . ' ' . $productName . ' ' . $category));
    if (preg_match('/perfume|perfumes|scent|fragrance|beauty|cosmetic|armaf|matelot|taskeen|goco|shera|supremacy|kiss|summer|mayor|blue/i', $haystack)) {
        return 'perfume';
    }
    return 'default';
}

function build_product_placeholder_svg(string $productName, string $businessName = '', string $category = '', string $seed = ''): string {
    $title = trim($productName) !== '' ? trim($productName) : 'Catalog Item';
    $store = trim($businessName);
    $categoryLabel = trim($category);
    $theme = detect_placeholder_theme($store, $title, $categoryLabel);
    [$bgStart, $bgEnd, $accent, $panelGlow, $ink] = placeholder_palette_for_seed($seed !== '' ? $seed : ($title . '|' . $store . '|' . $categoryLabel), $theme);
    $titleLines = svg_wrap_text_lines(strtoupper($title), 16, 2);
    $subtitle = $store !== '' ? $store : 'Online Store';
    $tagline = $theme === 'perfume'
        ? 'Signature collection'
        : ($categoryLabel !== '' ? $categoryLabel : 'Available in store');

    $titleSvg = '';
    foreach ($titleLines as $index => $line) {
        $y = 318 + ($index * 60);
        $titleSvg .= '<text x="470" y="' . $y . '" fill="#ffffff" font-family="Segoe UI, Arial, sans-serif" font-size="48" font-weight="700">' . svg_escape_text($line) . '</text>';
    }

    $accentGlow = svg_escape_text($panelGlow);
    $iconHighlight = svg_escape_text($accent);
    $backgroundStart = svg_escape_text($bgStart);
    $backgroundEnd = svg_escape_text($bgEnd);
    $inkColor = svg_escape_text($ink);
    $safeTitle = svg_escape_text($title);
    $subtitleText = svg_escape_text($subtitle);
    $taglineText = svg_escape_text($tagline);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" role="img" aria-labelledby="title desc">
  <title id="title">{$subtitleText} - {$safeTitle}</title>
  <desc id="desc">Product placeholder image for {$safeTitle}</desc>
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$backgroundStart}" />
      <stop offset="100%" stop-color="{$backgroundEnd}" />
    </linearGradient>
    <radialGradient id="glow" cx="50%" cy="35%" r="65%">
      <stop offset="0%" stop-color="{$accentGlow}" stop-opacity="0.92" />
      <stop offset="100%" stop-color="{$accentGlow}" stop-opacity="0" />
    </radialGradient>
  </defs>
  <rect width="1200" height="800" fill="url(#bg)" />
  <circle cx="1010" cy="130" r="170" fill="url(#glow)" />
  <circle cx="180" cy="670" r="160" fill="{$iconHighlight}" opacity="0.14" />
  <circle cx="1060" cy="640" r="120" fill="#ffffff" opacity="0.08" />
  <rect x="120" y="130" width="320" height="540" rx="52" fill="#ffffff" opacity="0.12" />
  <rect x="176" y="284" width="208" height="246" rx="46" fill="#ffffff" opacity="0.92" />
  <rect x="228" y="222" width="104" height="78" rx="18" fill="#ffffff" opacity="0.9" />
  <rect x="205" y="196" width="150" height="34" rx="10" fill="{$iconHighlight}" opacity="0.95" />
  <rect x="220" y="350" width="120" height="90" rx="16" fill="{$inkColor}" opacity="0.16" />
  <text x="280" y="404" text-anchor="middle" fill="{$inkColor}" font-family="Segoe UI, Arial, sans-serif" font-size="24" font-weight="700">SCENT</text>
  {$titleSvg}
  <text x="470" y="470" fill="#ffffff" opacity="0.9" font-family="Segoe UI, Arial, sans-serif" font-size="26">{$subtitleText}</text>
  <text x="470" y="522" fill="#ffffff" opacity="0.72" font-family="Segoe UI, Arial, sans-serif" font-size="20">{$taglineText}</text>
  <rect x="470" y="565" width="235" height="54" rx="27" fill="#ffffff" opacity="0.12" />
  <text x="587" y="600" text-anchor="middle" fill="#ffffff" font-family="Segoe UI, Arial, sans-serif" font-size="18" letter-spacing="1">CEDITILL CATALOG</text>
</svg>
SVG;
}

function business_initials(string $name): string {
    $clean = trim(preg_replace('/\s+/', ' ', $name));
    if ($clean === '') {
        return 'ST';
    }

    $parts = preg_split('/\s+/', $clean) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : strtoupper(substr($clean, 0, 2));
}

function build_business_logo_placeholder_svg(string $businessName, string $seed = ''): string {
    [$bgStart, $bgEnd, $accent, $panelGlow, $ink] = placeholder_palette_for_seed($seed !== '' ? $seed : $businessName, 'default');
    $title = trim($businessName) !== '' ? trim($businessName) : 'Storefront';
    $subtitle = svg_escape_text($title);
    $initials = svg_escape_text(business_initials($title));
    $backgroundStart = svg_escape_text($bgStart);
    $backgroundEnd = svg_escape_text($bgEnd);
    $accentGlow = svg_escape_text($panelGlow);
    $inkColor = svg_escape_text($ink);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-labelledby="title desc">
  <title id="title">{$subtitle} logo placeholder</title>
  <desc id="desc">Store logo placeholder</desc>
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$backgroundStart}" />
      <stop offset="100%" stop-color="{$backgroundEnd}" />
    </linearGradient>
    <radialGradient id="glow" cx="50%" cy="35%" r="65%">
      <stop offset="0%" stop-color="{$accentGlow}" stop-opacity="0.9" />
      <stop offset="100%" stop-color="{$accentGlow}" stop-opacity="0" />
    </radialGradient>
  </defs>
  <rect width="512" height="512" rx="112" fill="url(#bg)" />
  <circle cx="390" cy="126" r="116" fill="url(#glow)" />
  <circle cx="256" cy="222" r="118" fill="#ffffff" opacity="0.94" />
  <text x="256" y="248" text-anchor="middle" fill="{$inkColor}" font-family="Segoe UI, Arial, sans-serif" font-size="92" font-weight="700">{$initials}</text>
  <text x="256" y="390" text-anchor="middle" fill="#ffffff" font-family="Segoe UI, Arial, sans-serif" font-size="34" font-weight="600">{$subtitle}</text>
  <text x="256" y="432" text-anchor="middle" fill="#ffffff" opacity="0.75" font-family="Segoe UI, Arial, sans-serif" font-size="20">CediTill Store</text>
</svg>
SVG;
}

function resolve_requested_business_context(mysqli $conn): ?array {
    try {
        ensure_multitenant_schema($conn);
        $business = tenant_resolve_business_context($conn, [], false);
        return is_array($business) ? $business : null;
    } catch (Exception $e) {
        return null;
    }
}

function lookup_product_context_for_missing_image(mysqli $conn, string $filename): ?array {
    $safeName = sanitize_image_name($filename);
    if ($safeName === '' || !tenant_table_exists($conn, 'products')) {
        return null;
    }

    $business = resolve_requested_business_context($conn);
    $businessId = intval($business['id'] ?? 0);

    if ($businessId > 0) {
        $stmt = $conn->prepare(
            "SELECT p.name, p.category, b.business_name
             FROM products p
             LEFT JOIN businesses b ON b.id = p.business_id
             WHERE p.image = ? AND p.business_id = ?
             ORDER BY p.id DESC
             LIMIT 1"
        );
        $stmt->bind_param('si', $safeName, $businessId);
    } else {
        $stmt = $conn->prepare(
            "SELECT p.name, p.category, b.business_name
             FROM products p
             LEFT JOIN businesses b ON b.id = p.business_id
             WHERE p.image = ?
             ORDER BY p.id DESC
             LIMIT 1"
        );
        $stmt->bind_param('s', $safeName);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'product_name' => trim((string)($row['name'] ?? 'Catalog Item')),
        'category' => trim((string)($row['category'] ?? '')),
        'business_name' => trim((string)($row['business_name'] ?? ($business['business_name'] ?? '')))
    ];
}

function unavailable_image_svg(string $title, string $message, string $fill = '#eef4f3', string $textColor = '#6b7280'): string {
    $safeTitle = svg_escape_text($title);
    $safeMessage = svg_escape_text($message);
    $safeFill = svg_escape_text($fill);
    $safeText = svg_escape_text($textColor);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500">
  <rect width="800" height="500" fill="{$safeFill}" />
  <g fill="{$safeText}" font-family="Segoe UI, Arial, sans-serif" text-anchor="middle">
    <text x="400" y="240" font-size="34" font-weight="700">{$safeTitle}</text>
    <text x="400" y="282" font-size="20">{$safeMessage}</text>
  </g>
</svg>
SVG;
}

try {
    $imagesDir = realpath(__DIR__ . '/../assets/images');
    if ($imagesDir === false || !is_dir($imagesDir)) {
        throw new Exception('Images directory is missing.');
    }

    $requestedName = isset($_GET['name']) ? (string)$_GET['name'] : '';
    $safeRequestedName = sanitize_image_name($requestedName);
    $requesterBusinessId = 0;
    $requestedNameIsProtected = false;

    if ($safeRequestedName !== '' && file_storage_is_managed_upload_filename($safeRequestedName)) {
        ensure_multitenant_schema($conn);
        ensure_file_storage_policy_table($conn);
        $requestedNameIsProtected = file_storage_has_policy_record($conn, $safeRequestedName);
        if ($requestedNameIsProtected) {
            $requesterBusinessId = file_storage_requester_business_id($conn);
            if (!file_storage_can_access_filename($conn, $safeRequestedName, $requesterBusinessId)) {
                output_inline_svg(
                    unavailable_image_svg('Image unavailable', 'Please check back later'),
                    false,
                    403
                );
            }
        }
    }

    $defaultName = PRODUCT_IMAGE_DEFAULT_FILE;
    $candidates = image_path_candidates($requestedName, $defaultName);

    foreach ($candidates as $candidateName) {
        $candidateIsProtected = false;
        if (file_storage_is_managed_upload_filename($candidateName)) {
            $candidateIsProtected = ($candidateName === $safeRequestedName)
                ? $requestedNameIsProtected
                : file_storage_has_policy_record($conn, $candidateName);
            if ($candidateIsProtected) {
                if ($requesterBusinessId <= 0) {
                    $requesterBusinessId = file_storage_requester_business_id($conn);
                }
                if (!file_storage_can_access_filename($conn, $candidateName, $requesterBusinessId)) {
                    continue;
                }
            }
        }

        $resolved = resolve_existing_image_path($imagesDir, $candidateName);
        if ($resolved !== null) {
            output_image_file($resolved, !$candidateIsProtected);
        }

        if (file_storage_is_managed_upload_filename($candidateName)) {
            $backupRecord = file_storage_fetch_backup_record($conn, $candidateName);
            if (is_array($backupRecord)) {
                $backupContents = (string)($backupRecord['file_blob'] ?? '');
                $backupMimeType = (string)($backupRecord['mime_type'] ?? 'application/octet-stream');
                $backupSize = intval($backupRecord['file_size'] ?? 0);
                $backupHash = (string)($backupRecord['sha256'] ?? '');

                if ($backupContents !== '') {
                    file_storage_restore_managed_asset_contents($candidateName, $backupContents, $backupHash);
                    $restoredPath = resolve_existing_image_path($imagesDir, $candidateName);
                    if ($restoredPath !== null) {
                        output_image_file($restoredPath, !$candidateIsProtected);
                    }

                    output_image_blob($backupContents, $backupMimeType, !$candidateIsProtected, $backupSize);
                }
            }
        }
    }

    if ($safeRequestedName !== '' && is_uploaded_product_image_name($safeRequestedName)) {
        $productContext = lookup_product_context_for_missing_image($conn, $safeRequestedName);
        if ($productContext) {
            output_inline_svg(
                build_product_placeholder_svg(
                    $productContext['product_name'],
                    $productContext['business_name'],
                    $productContext['category'],
                    $safeRequestedName
                )
            );
        }

        output_inline_svg(
            build_product_placeholder_svg('Catalog Item', 'CediTill Store', '', $safeRequestedName)
        );
    }

    if ($safeRequestedName !== '' && is_uploaded_business_logo_name($safeRequestedName)) {
        $business = resolve_requested_business_context($conn);
        $businessName = trim((string)($business['business_name'] ?? 'Storefront'));
        output_inline_svg(build_business_logo_placeholder_svg($businessName, $safeRequestedName));
    }

    output_inline_svg(
        unavailable_image_svg('Image unavailable', 'Please check back later'),
        false,
        404
    );
} catch (Exception $e) {
    error_log('product-image.php: ' . $e->getMessage());
    output_inline_svg(
        unavailable_image_svg('Unable to load image', 'Please try again later', '#fee2e2', '#991b1b'),
        false,
        500
    );
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
