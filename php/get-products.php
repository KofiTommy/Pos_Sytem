<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=60');
include 'db-connection.php';

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
                WHERE featured = 1
                ORDER BY created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $limit);
    } else {
        $sql = "SELECT id, name, description, price, category, image, stock, featured, created_at
                FROM products
                ORDER BY created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result) {
        throw new Exception("Query error: " . $conn->error);
    }
    
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

    while ($row = $result->fetch_assoc()) {
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
