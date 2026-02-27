<?php
header('Content-Type: application/json');

include 'admin-auth.php';
include 'db-connection.php';
include 'tenant-context.php';

const DEFAULT_BUSINESS_NAME = 'Mother Care';
const DEFAULT_BUSINESS_EMAIL = 'info@mothercare.com';
const DEFAULT_CONTACT_NUMBER = '+233 000 000 000';
const DEFAULT_THEME_PALETTE = 'default';
const DEFAULT_HERO_TAGLINE = 'Premium baby care products for your little ones. Quality you can trust.';
const DEFAULT_FOOTER_NOTE = 'Trusted essentials, safe choices, and a smooth shopping experience for every parent.';

function allowed_theme_palettes() {
    return ['default', 'ocean', 'sunset', 'forest', 'mono'];
}

function normalize_theme_palette($value) {
    $palette = strtolower(trim((string)$value));
    if ($palette === '') {
        $palette = DEFAULT_THEME_PALETTE;
    }
    return in_array($palette, allowed_theme_palettes(), true) ? $palette : DEFAULT_THEME_PALETTE;
}

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function default_settings_for_business(array $business = []) {
    $name = trim((string)($business['business_name'] ?? ''));
    $email = trim((string)($business['business_email'] ?? ''));
    $phone = trim((string)($business['contact_number'] ?? ''));

    return [
        'business_name' => $name !== '' ? $name : DEFAULT_BUSINESS_NAME,
        'business_email' => $email !== '' ? $email : DEFAULT_BUSINESS_EMAIL,
        'contact_number' => $phone !== '' ? $phone : DEFAULT_CONTACT_NUMBER,
        'logo_filename' => '',
        'theme_palette' => DEFAULT_THEME_PALETTE,
        'hero_tagline' => DEFAULT_HERO_TAGLINE,
        'footer_note' => DEFAULT_FOOTER_NOTE,
        'updated_at' => null
    ];
}

function get_request_data() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
    return $_POST;
}

function handle_logo_upload($fieldName) {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Logo upload failed');
    }

    $tmpPath = $file['tmp_name'] ?? '';
    $originalName = $file['name'] ?? '';
    $size = intval($file['size'] ?? 0);

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new Exception('Invalid uploaded logo file');
    }
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new Exception('Logo size must be between 1 byte and 5MB');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowedExt, true)) {
        throw new Exception('Only JPG, JPEG, PNG, GIF, and WEBP logos are allowed');
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        throw new Exception('Uploaded logo is not a valid image');
    }

    $uploadDir = realpath(__DIR__ . '/../assets/images');
    if ($uploadDir === false || !is_dir($uploadDir) || !is_writable($uploadDir)) {
        throw new Exception('Logo upload directory is not writable');
    }

    $filename = 'business-logo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        throw new Exception('Could not save uploaded logo');
    }

    return $filename;
}

function load_settings(mysqli $conn, int $businessId, array $business = []) {
    $stmt = $conn->prepare(
        "SELECT business_name, business_email, contact_number, logo_filename, theme_palette, hero_tagline, footer_note, updated_at
         FROM business_settings
         WHERE business_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return default_settings_for_business($business);
    }

    $defaults = default_settings_for_business($business);
    return [
        'business_name' => $row['business_name'] ?? $defaults['business_name'],
        'business_email' => $row['business_email'] ?? $defaults['business_email'],
        'contact_number' => $row['contact_number'] ?? $defaults['contact_number'],
        'logo_filename' => $row['logo_filename'] ?? '',
        'theme_palette' => normalize_theme_palette($row['theme_palette'] ?? $defaults['theme_palette']),
        'hero_tagline' => trim((string)($row['hero_tagline'] ?? $defaults['hero_tagline'])) ?: $defaults['hero_tagline'],
        'footer_note' => trim((string)($row['footer_note'] ?? $defaults['footer_note'])) ?: $defaults['footer_note'],
        'updated_at' => $row['updated_at'] ?? null
    ];
}

function resolve_business_for_request(mysqli $conn, string $method): array {
    if ($method === 'GET') {
        $requestedCode = trim((string)($_GET['business_code'] ?? ($_GET['tenant'] ?? '')));
        return tenant_require_business_context(
            $conn,
            ['business_code' => $requestedCode],
            true
        );
    }

    require_roles_api(['owner']);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        throw new Exception('Invalid business context. Please sign in again.');
    }
    $business = tenant_fetch_business_by_id($conn, $businessId);
    if (!$business) {
        throw new Exception('Business account not found.');
    }
    tenant_set_session_context($business);
    return $business;
}

try {
    ensure_multitenant_schema($conn);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST' && isset($_POST['_method'])) {
        $override = strtoupper(trim($_POST['_method']));
        if (in_array($override, ['PUT', 'DELETE'], true)) {
            $method = $override;
        }
    }

    $business = resolve_business_for_request($conn, $method);
    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        throw new Exception('Business account not found.');
    }

    if ($method === 'GET') {
        respond(true, '', [
            'business' => [
                'id' => $businessId,
                'business_code' => $business['business_code'] ?? '',
                'status' => $business['status'] ?? 'active',
                'subscription_plan' => $business['subscription_plan'] ?? MULTI_TENANT_DEFAULT_PLAN
            ],
            'settings' => load_settings($conn, $businessId, $business)
        ]);
    }

    if ($method === 'PUT') {
        tenant_ensure_business_settings_row(
            $conn,
            $businessId,
            (string)($business['business_name'] ?? DEFAULT_BUSINESS_NAME),
            (string)($business['business_email'] ?? DEFAULT_BUSINESS_EMAIL),
            (string)($business['contact_number'] ?? DEFAULT_CONTACT_NUMBER)
        );
        $data = get_request_data();
        if (!is_array($data)) {
            respond(false, 'Invalid request payload');
        }

        $existing = load_settings($conn, $businessId, $business);

        $businessName = trim($data['business_name'] ?? '');
        $businessEmail = trim($data['business_email'] ?? '');
        $contactNumber = trim($data['contact_number'] ?? '');
        $removeLogo = !empty($data['remove_logo']);
        $themePalette = normalize_theme_palette($data['theme_palette'] ?? DEFAULT_THEME_PALETTE);
        $heroTagline = trim((string)($data['hero_tagline'] ?? DEFAULT_HERO_TAGLINE));
        $footerNote = trim((string)($data['footer_note'] ?? DEFAULT_FOOTER_NOTE));

        if ($businessName === '') {
            respond(false, 'Business name is required');
        }
        if (strlen($businessName) > 180) {
            respond(false, 'Business name is too long');
        }
        if ($businessEmail === '') {
            respond(false, 'Business email is required');
        }
        if (!filter_var($businessEmail, FILTER_VALIDATE_EMAIL) || strlen($businessEmail) > 160) {
            respond(false, 'Business email is invalid');
        }
        if ($contactNumber === '') {
            respond(false, 'Contact number is required');
        }
        if (strlen($contactNumber) > 40) {
            respond(false, 'Contact number is too long');
        }
        if ($heroTagline === '') {
            $heroTagline = DEFAULT_HERO_TAGLINE;
        }
        if (strlen($heroTagline) > 320) {
            respond(false, 'Welcome tagline is too long');
        }
        if ($footerNote === '') {
            $footerNote = DEFAULT_FOOTER_NOTE;
        }
        if (strlen($footerNote) > 320) {
            respond(false, 'Footer note is too long');
        }

        $logoFilename = $removeLogo ? '' : ($existing['logo_filename'] ?? '');
        $uploadedLogo = handle_logo_upload('logo_file');
        if ($uploadedLogo !== null) {
            $logoFilename = $uploadedLogo;
        }

        $stmt = $conn->prepare(
            "UPDATE business_settings
             SET business_name = ?, business_email = ?, contact_number = ?, logo_filename = ?, theme_palette = ?, hero_tagline = ?, footer_note = ?
             WHERE business_id = ?"
        );
        $stmt->bind_param('sssssssi', $businessName, $businessEmail, $contactNumber, $logoFilename, $themePalette, $heroTagline, $footerNote, $businessId);
        $stmt->execute();
        $stmt->close();

        $businessUpdateStmt = $conn->prepare(
            "UPDATE businesses
             SET business_name = ?, business_email = ?, contact_number = ?
             WHERE id = ?"
        );
        $businessUpdateStmt->bind_param('sssi', $businessName, $businessEmail, $contactNumber, $businessId);
        $businessUpdateStmt->execute();
        $businessUpdateStmt->close();

        $business = tenant_fetch_business_by_id($conn, $businessId) ?: $business;
        tenant_set_session_context($business);

        respond(true, 'Business settings updated successfully', [
            'business' => [
                'id' => $businessId,
                'business_code' => $business['business_code'] ?? '',
                'status' => $business['status'] ?? 'active',
                'subscription_plan' => $business['subscription_plan'] ?? MULTI_TENANT_DEFAULT_PLAN
            ],
            'settings' => load_settings($conn, $businessId, $business)
        ]);
    }

    if ($method === 'DELETE') {
        tenant_ensure_business_settings_row(
            $conn,
            $businessId,
            (string)($business['business_name'] ?? DEFAULT_BUSINESS_NAME),
            (string)($business['business_email'] ?? DEFAULT_BUSINESS_EMAIL),
            (string)($business['contact_number'] ?? DEFAULT_CONTACT_NUMBER)
        );
        $fallback = default_settings_for_business($business);
        $emptyLogo = '';
        $palette = DEFAULT_THEME_PALETTE;
        $heroTagline = $fallback['hero_tagline'];
        $footerNote = $fallback['footer_note'];

        $stmt = $conn->prepare(
            "UPDATE business_settings
             SET business_name = ?, business_email = ?, contact_number = ?, logo_filename = ?, theme_palette = ?, hero_tagline = ?, footer_note = ?
             WHERE business_id = ?"
        );
        $stmt->bind_param(
            'sssssssi',
            $fallback['business_name'],
            $fallback['business_email'],
            $fallback['contact_number'],
            $emptyLogo,
            $palette,
            $heroTagline,
            $footerNote,
            $businessId
        );
        $stmt->execute();
        $stmt->close();

        respond(true, 'Business info reset successfully', [
            'business' => [
                'id' => $businessId,
                'business_code' => $business['business_code'] ?? '',
                'status' => $business['status'] ?? 'active',
                'subscription_plan' => $business['subscription_plan'] ?? MULTI_TENANT_DEFAULT_PLAN
            ],
            'settings' => load_settings($conn, $businessId, $business)
        ]);
    }

    http_response_code(405);
    respond(false, 'Method not allowed');
} catch (Exception $e) {
    http_response_code(500);
    respond(false, $e->getMessage());
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
