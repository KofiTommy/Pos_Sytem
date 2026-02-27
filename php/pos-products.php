<?php
header('Content-Type: application/json');
include 'admin-auth.php';
require_roles_api(['owner', 'sales']);
include 'db-connection.php';
include 'tenant-context.php';

try {
    ensure_multitenant_schema($conn);
    $businessId = current_business_id();
    if ($businessId <= 0) {
        throw new Exception('Invalid business context. Please sign in again.');
    }

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
                WHERE business_id = ? AND (name LIKE ? OR category LIKE ? OR description LIKE ?)
                ORDER BY name ASC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isssi', $businessId, $like, $like, $like, $limit);
    } else {
        $sql = "SELECT id, name, price, stock, category, image
                FROM products
                WHERE business_id = ?
                ORDER BY name ASC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $businessId, $limit);
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
