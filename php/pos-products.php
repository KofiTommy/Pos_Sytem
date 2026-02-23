<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner', 'sales']);
include 'db-connection.php';

try {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    if ($limit <= 0) {
        $limit = 50;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql = "SELECT id, name, price, stock, category, image
                FROM products
                WHERE name LIKE ? OR category LIKE ? OR description LIKE ?
                ORDER BY name ASC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssi', $like, $like, $like, $limit);
    } else {
        $sql = "SELECT id, name, price, stock, category, image
                FROM products
                ORDER BY name ASC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();

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
            $row['image'] = $imageMap[$imageName];
        }

        $products[] = $row;
    }

    echo json_encode([
        'success' => true,
        'products' => $products
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
