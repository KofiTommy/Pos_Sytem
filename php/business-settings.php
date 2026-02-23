<?php
header('Content-Type: application/json');

include 'admin-auth.php';
include 'db-connection.php';

const DEFAULT_BUSINESS_NAME = 'Mother Care';
const DEFAULT_BUSINESS_EMAIL = 'info@mothercare.com';
const DEFAULT_CONTACT_NUMBER = '+233 000 000 000';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function ensure_business_settings_table($conn) {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS business_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            business_name VARCHAR(180) NOT NULL,
            business_email VARCHAR(160) NOT NULL,
            contact_number VARCHAR(40) NOT NULL,
            logo_filename VARCHAR(255) DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $conn->prepare(
        "INSERT INTO business_settings (id, business_name, business_email, contact_number, logo_filename)
         VALUES (1, ?, ?, ?, '')
         ON DUPLICATE KEY UPDATE id = id"
    );
    $defaultName = DEFAULT_BUSINESS_NAME;
    $defaultEmail = DEFAULT_BUSINESS_EMAIL;
    $defaultContact = DEFAULT_CONTACT_NUMBER;
    $stmt->bind_param('sss', $defaultName, $defaultEmail, $defaultContact);
    $stmt->execute();
    $stmt->close();
}

function default_settings() {
    return [
        'business_name' => DEFAULT_BUSINESS_NAME,
        'business_email' => DEFAULT_BUSINESS_EMAIL,
        'contact_number' => DEFAULT_CONTACT_NUMBER,
        'logo_filename' => '',
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

function load_settings($conn) {
    $stmt = $conn->prepare(
        "SELECT business_name, business_email, contact_number, logo_filename, updated_at
         FROM business_settings
         WHERE id = 1
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return default_settings();
    }

    return [
        'business_name' => $row['business_name'] ?? DEFAULT_BUSINESS_NAME,
        'business_email' => $row['business_email'] ?? DEFAULT_BUSINESS_EMAIL,
        'contact_number' => $row['contact_number'] ?? DEFAULT_CONTACT_NUMBER,
        'logo_filename' => $row['logo_filename'] ?? '',
        'updated_at' => $row['updated_at'] ?? null
    ];
}

try {
    ensure_business_settings_table($conn);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST' && isset($_POST['_method'])) {
        $override = strtoupper(trim($_POST['_method']));
        if (in_array($override, ['PUT', 'DELETE'], true)) {
            $method = $override;
        }
    }

    if ($method === 'GET') {
        respond(true, '', ['settings' => load_settings($conn)]);
    }

    if ($method === 'PUT') {
        require_roles_api(['owner']);

        $data = get_request_data();
        if (!is_array($data)) {
            respond(false, 'Invalid request payload');
        }

        $existing = load_settings($conn);

        $businessName = trim($data['business_name'] ?? '');
        $businessEmail = trim($data['business_email'] ?? '');
        $contactNumber = trim($data['contact_number'] ?? '');
        $removeLogo = !empty($data['remove_logo']);

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

        $logoFilename = $removeLogo ? '' : ($existing['logo_filename'] ?? '');
        $uploadedLogo = handle_logo_upload('logo_file');
        if ($uploadedLogo !== null) {
            $logoFilename = $uploadedLogo;
        }

        $stmt = $conn->prepare(
            "UPDATE business_settings
             SET business_name = ?, business_email = ?, contact_number = ?, logo_filename = ?
             WHERE id = 1"
        );
        $stmt->bind_param('ssss', $businessName, $businessEmail, $contactNumber, $logoFilename);
        $stmt->execute();
        $stmt->close();

        respond(true, 'Business settings updated successfully', ['settings' => load_settings($conn)]);
    }

    if ($method === 'DELETE') {
        require_roles_api(['owner']);

        $defaultName = DEFAULT_BUSINESS_NAME;
        $defaultEmail = DEFAULT_BUSINESS_EMAIL;
        $defaultContact = DEFAULT_CONTACT_NUMBER;
        $emptyLogo = '';

        $stmt = $conn->prepare(
            "UPDATE business_settings
             SET business_name = ?, business_email = ?, contact_number = ?, logo_filename = ?
             WHERE id = 1"
        );
        $stmt->bind_param('ssss', $defaultName, $defaultEmail, $defaultContact, $emptyLogo);
        $stmt->execute();
        $stmt->close();

        respond(true, 'Business info deleted and reset to default', ['settings' => load_settings($conn)]);
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
