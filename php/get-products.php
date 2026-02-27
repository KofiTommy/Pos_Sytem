<?php
header('Content-Type: application/json');
header('Cache-Control: private, max-age=60');
include 'db-connection.php';
include 'tenant-context.php';

function safe_text($value, $maxLen = 1000) {
    $text = trim(strip_tags((string)$value));
    if (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen);
    }
    return $text;
}

function safe_image_name($value) {
    return preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$value);
}

try {
    $business = tenant_require_business_context($conn, [], true);
    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        throw new Exception('Invalid business context');
    }

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 24;
    $featured = isset($_GET['featured']) ? 1 : 0;
    if ($limit <= 0) {
        $limit = 24;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    if ($featured) {
        $sql = "SELECT id, name, description, price, category, image, stock, featured, created_at
                FROM products
                WHERE business_id = ? AND featured = 1
                ORDER BY created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $businessId, $limit);
    } else {
        $sql = "SELECT id, name, description, price, category, image, stock, featured, created_at
                FROM products
                WHERE business_id = ?
                ORDER BY created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $businessId, $limit);
    }

    $stmt->execute();

    $products = [];
    $imageMap = [
        'bottle.jpg' => 'pexels-jonathan-nenemann-12114822.jpg',
        'diapers.jpg' => 'pexels-shvetsa-3845457.jpg',
        'clothing.jpg' => 'pexels-nappy-2480948.jpg',
        'monitor.jpg' => 'pexels-rdne-6849259.jpg',
        'teething.jpg' => 'pexels-public-domain-pictures-41222.jpg',
        'bathtub.jpg' => 'pexels-shvetsa-3845420.jpg',
        'nursing-pillow.jpg' => 'pexels-polina-tankilevitch-3875080.jpg',
        'wipes.jpg' => 'pexels-ismaelabdalnabystudio-20387764.jpg',
        'stroller.jpg' => 'pexels-fotios-photos-5435599.jpg',
        'sheets.jpg' => 'pexels-nerosable-19015553.jpg'
    ];

    $fetchRow = function (array $row) use (&$products, $imageMap) {
        $imageName = $row['image'] ?? '';

        if ($imageName !== '' && isset($imageMap[$imageName])) {
            $imageName = $imageMap[$imageName];
        }

        $row['id'] = intval($row['id'] ?? 0);
        $row['name'] = safe_text($row['name'] ?? '', 200);
        $row['description'] = safe_text($row['description'] ?? '', 2000);
        $row['category'] = safe_text($row['category'] ?? '', 100);
        $row['image'] = safe_image_name($imageName);
        $row['price'] = round(floatval($row['price'] ?? 0), 2);
        $row['stock'] = intval($row['stock'] ?? 0);
        $row['featured'] = intval($row['featured'] ?? 0);

        $products[] = $row;
    };

    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if (!$result) {
            throw new Exception("Query error: " . $conn->error);
        }
        while ($row = $result->fetch_assoc()) {
            $fetchRow($row);
        }
    } else {
        $stmt->bind_result($id, $name, $description, $price, $category, $image, $stock, $featuredFlag, $createdAt);
        while ($stmt->fetch()) {
            $fetchRow([
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'category' => $category,
                'image' => $image,
                'stock' => $stock,
                'featured' => $featuredFlag,
                'created_at' => $createdAt
            ]);
        }
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'products' => $products,
        'count' => count($products)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
