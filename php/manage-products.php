<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner']);
include 'db-connection.php';
include 'tenant-context.php';
include_once __DIR__ . '/compliance-tracking.php';

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
    $size = intval($file['size'] ?? 0);

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new Exception('Invalid uploaded file');
    }
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new Exception('Image size must be between 1 byte and 5MB');
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        throw new Exception('Uploaded file is not a valid image');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = strtolower((string)$finfo->file($tmpPath));
    $allowedMimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    if (!isset($allowedMimeToExt[$mimeType])) {
        throw new Exception('Only JPG, PNG, GIF, and WEBP images are allowed');
    }
    $extension = $allowedMimeToExt[$mimeType];

    $uploadDir = realpath(__DIR__ . '/../assets/images');
    if ($uploadDir === false || !is_dir($uploadDir) || !is_writable($uploadDir)) {
        throw new Exception('Image upload directory is not writable');
    }

    $filename = 'product-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        throw new Exception('Could not save uploaded image');
    }
    @chmod($destination, 0644);

    return $filename;
}

try {
    ensure_multitenant_schema($conn);
    ensure_phase3_tracking_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        respond(false, 'Invalid business context. Please sign in again.');
    }
    $actorUserId = tracking_actor_user_id();
    $actorUsername = tracking_actor_username();

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
             WHERE business_id = ?
             ORDER BY created_at ASC, id ASC
             LIMIT ?"
        );
        $stmt->bind_param('ii', $businessId, $limit);
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
            "INSERT INTO products (business_id, name, description, price, category, image, stock, featured)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issdssii', $businessId, $name, $description, $price, $category, $image, $stock, $featured);
        $stmt->execute();

        $newId = $conn->insert_id;
        $stmt->close();

        tracking_log_business_event(
            $conn,
            $businessId,
            'product.create',
            'product',
            intval($newId),
            [
                'name' => $name,
                'price' => round($price, 2),
                'stock' => $stock,
                'category' => $category,
                'featured' => $featured
            ],
            $actorUserId,
            $actorUsername
        );
        if ($stock > 0) {
            tracking_log_inventory_adjustment(
                $conn,
                $businessId,
                intval($newId),
                $stock,
                'product_initial_stock',
                0,
                0,
                $stock,
                'Initial stock recorded on product creation',
                $actorUserId,
                $actorUsername
            );
        }

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

        $existingStmt = $conn->prepare(
            "SELECT id, name, price, stock, category, featured
             FROM products
             WHERE id = ? AND business_id = ?
             LIMIT 1"
        );
        $existingStmt->bind_param('ii', $id, $businessId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if (!$existing) {
            respond(false, 'Product not found');
        }

        $previousStock = intval($existing['stock'] ?? 0);

        $uploadedImage = handle_uploaded_image('image_file');
        if ($uploadedImage !== null) {
            $image = $uploadedImage;
        } elseif ($image !== '') {
            $image = basename($image);
        }

        $stmt = $conn->prepare(
            "UPDATE products
             SET name = ?, description = ?, price = ?, category = ?, image = ?, stock = ?, featured = ?
             WHERE id = ? AND business_id = ?"
        );
        $stmt->bind_param('ssdssiiii', $name, $description, $price, $category, $image, $stock, $featured, $id, $businessId);
        $stmt->execute();
        $stmt->close();

        tracking_log_business_event(
            $conn,
            $businessId,
            'product.update',
            'product',
            $id,
            [
                'before' => [
                    'name' => (string)($existing['name'] ?? ''),
                    'price' => round(floatval($existing['price'] ?? 0), 2),
                    'stock' => $previousStock,
                    'category' => (string)($existing['category'] ?? ''),
                    'featured' => intval($existing['featured'] ?? 0)
                ],
                'after' => [
                    'name' => $name,
                    'price' => round($price, 2),
                    'stock' => $stock,
                    'category' => $category,
                    'featured' => $featured
                ]
            ],
            $actorUserId,
            $actorUsername
        );

        if ($previousStock !== $stock) {
            tracking_log_inventory_adjustment(
                $conn,
                $businessId,
                $id,
                $stock - $previousStock,
                'manual_stock_update',
                0,
                $previousStock,
                $stock,
                'Stock updated from manage-products',
                $actorUserId,
                $actorUsername
            );
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

        $existingStmt = $conn->prepare(
            "SELECT id, name, price, stock, category, featured
             FROM products
             WHERE id = ? AND business_id = ?
             LIMIT 1"
        );
        $existingStmt->bind_param('ii', $id, $businessId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if (!$existing) {
            respond(false, 'Product not found');
        }

        $stmt = $conn->prepare("DELETE FROM products WHERE id = ? AND business_id = ?");
        $stmt->bind_param('ii', $id, $businessId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            respond(false, 'Product not found');
        }

        tracking_log_business_event(
            $conn,
            $businessId,
            'product.delete',
            'product',
            $id,
            [
                'name' => (string)($existing['name'] ?? ''),
                'price' => round(floatval($existing['price'] ?? 0), 2),
                'stock' => intval($existing['stock'] ?? 0),
                'category' => (string)($existing['category'] ?? ''),
                'featured' => intval($existing['featured'] ?? 0)
            ],
            $actorUserId,
            $actorUsername
        );

        respond(true, 'Product deleted successfully');
    }

    http_response_code(405);
    respond(false, 'Method not allowed');
} catch (Exception $e) {
    http_response_code(500);
    error_log('manage-products.php: ' . $e->getMessage());
    respond(false, 'Unable to process product request right now.');
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
