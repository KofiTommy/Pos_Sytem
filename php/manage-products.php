<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner']);
include 'db-connection.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
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

function handle_uploaded_image($fieldName) {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Image upload failed');
    }

    $tmpPath = $file['tmp_name'] ?? '';
    $originalName = $file['name'] ?? '';
    $size = intval($file['size'] ?? 0);

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new Exception('Invalid uploaded file');
    }
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new Exception('Image size must be between 1 byte and 5MB');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowedExt, true)) {
        throw new Exception('Only JPG, JPEG, PNG, GIF, and WEBP images are allowed');
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        throw new Exception('Uploaded file is not a valid image');
    }

    $uploadDir = realpath(__DIR__ . '/../assets/images');
    if ($uploadDir === false || !is_dir($uploadDir) || !is_writable($uploadDir)) {
        throw new Exception('Image upload directory is not writable');
    }

    $filename = 'product-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        throw new Exception('Could not save uploaded image');
    }

    return $filename;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST' && isset($_POST['_method'])) {
        $override = strtoupper(trim($_POST['_method']));
        if (in_array($override, ['PUT', 'DELETE'], true)) {
            $method = $override;
        }
    }

    if ($method === 'GET') {
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 200;
        if ($limit <= 0) {
            $limit = 200;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        $stmt = $conn->prepare(
            "SELECT id, name, description, price, category, image, stock, featured, created_at
             FROM products
             ORDER BY created_at ASC, id ASC
             LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $stmt->close();

        respond(true, '', ['products' => $products]);
    }

    if ($method === 'POST') {
        $data = get_request_data();
        if (!is_array($data)) {
            respond(false, 'Invalid JSON payload');
        }

        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $category = trim($data['category'] ?? '');
        $image = trim($data['image'] ?? '');
        $price = isset($data['price']) ? floatval($data['price']) : -1;
        $stock = isset($data['stock']) ? intval($data['stock']) : -1;
        $featured = !empty($data['featured']) ? 1 : 0;

        if ($name === '') {
            respond(false, 'Product name is required');
        }
        if (strlen($name) > 200) {
            respond(false, 'Product name is too long');
        }
        if ($price < 0) {
            respond(false, 'Price must be 0 or greater');
        }
        if ($stock < 0) {
            respond(false, 'Quantity (stock) must be 0 or greater');
        }
        if (strlen($category) > 100) {
            respond(false, 'Category is too long');
        }
        if (strlen($image) > 255) {
            respond(false, 'Image filename is too long');
        }

        $uploadedImage = handle_uploaded_image('image_file');
        if ($uploadedImage !== null) {
            $image = $uploadedImage;
        } elseif ($image !== '') {
            $image = basename($image);
        }

        $stmt = $conn->prepare(
            "INSERT INTO products (name, description, price, category, image, stock, featured)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssdssii', $name, $description, $price, $category, $image, $stock, $featured);
        $stmt->execute();

        $newId = $conn->insert_id;
        $stmt->close();

        respond(true, 'Product created successfully', ['product_id' => $newId]);
    }

    if ($method === 'PUT') {
        $data = get_request_data();
        if (!is_array($data)) {
            respond(false, 'Invalid JSON payload');
        }

        $id = isset($data['id']) ? intval($data['id']) : 0;
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $category = trim($data['category'] ?? '');
        $image = trim($data['image'] ?? '');
        $price = isset($data['price']) ? floatval($data['price']) : -1;
        $stock = isset($data['stock']) ? intval($data['stock']) : -1;
        $featured = !empty($data['featured']) ? 1 : 0;

        if ($id <= 0) {
            respond(false, 'Invalid product ID');
        }
        if ($name === '') {
            respond(false, 'Product name is required');
        }
        if (strlen($name) > 200) {
            respond(false, 'Product name is too long');
        }
        if ($price < 0) {
            respond(false, 'Price must be 0 or greater');
        }
        if ($stock < 0) {
            respond(false, 'Quantity (stock) must be 0 or greater');
        }
        if (strlen($category) > 100) {
            respond(false, 'Category is too long');
        }
        if (strlen($image) > 255) {
            respond(false, 'Image filename is too long');
        }

        $uploadedImage = handle_uploaded_image('image_file');
        if ($uploadedImage !== null) {
            $image = $uploadedImage;
        } elseif ($image !== '') {
            $image = basename($image);
        }

        $stmt = $conn->prepare(
            "UPDATE products
             SET name = ?, description = ?, price = ?, category = ?, image = ?, stock = ?, featured = ?
             WHERE id = ?"
        );
        $stmt->bind_param('ssdssiii', $name, $description, $price, $category, $image, $stock, $featured, $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            $checkStmt = $conn->prepare("SELECT id FROM products WHERE id = ?");
            $checkStmt->bind_param('i', $id);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            if (!$exists) {
                respond(false, 'Product not found');
            }
        }

        respond(true, 'Product updated successfully');
    }

    if ($method === 'DELETE') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $id = 0;

        if (is_array($data) && isset($data['id'])) {
            $id = intval($data['id']);
        } elseif (isset($_GET['id'])) {
            $id = intval($_GET['id']);
        }

        if ($id <= 0) {
            respond(false, 'Invalid product ID');
        }

        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            respond(false, 'Product not found');
        }

        respond(true, 'Product deleted successfully');
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
