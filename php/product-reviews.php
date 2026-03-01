<?php
header('Content-Type: application/json');
include 'db-connection.php';
include 'admin-auth.php';
include 'tenant-context.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

function parse_request_payload() {
    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    return $_POST;
}

function clean_text($value, $maxLen = 1000) {
    $text = trim((string)$value);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    if (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen);
    }
    return trim((string)$text);
}

try {
    ensure_multitenant_schema($conn);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        respond(false, 'Method not allowed');
    }

    $payload = [];
    if ($method === 'POST') {
        $payload = parse_request_payload();
        if (!is_array($payload)) {
            respond(false, 'Invalid request payload');
        }
    }

    $requestedCode = trim((string)($_GET['business_code'] ?? ($_GET['tenant'] ?? ($payload['business_code'] ?? ''))));
    if ($requestedCode === '' && !is_admin_authenticated()) {
        if ($method === 'GET') {
            respond(true, '', [
                'reviews' => [],
                'summary' => [
                    'rating_avg' => 0,
                    'rating_count' => 0
                ]
            ]);
        }
        respond(false, 'Store link is missing. Open this page from the correct store URL.');
    }

    $business = tenant_require_business_context($conn, ['business_code' => $requestedCode], true);
    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        throw new Exception('Invalid business context');
    }

    if ($method === 'GET') {
        $productId = intval($_GET['product_id'] ?? 0);
        if ($productId <= 0) {
            respond(false, 'Invalid product');
        }

        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 12;
        if ($limit <= 0) {
            $limit = 12;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $productStmt = $conn->prepare(
            "SELECT id
             FROM products
             WHERE id = ? AND business_id = ?
             LIMIT 1"
        );
        $productStmt->bind_param('ii', $productId, $businessId);
        $productStmt->execute();
        $productRow = $productStmt->get_result()->fetch_assoc();
        $productStmt->close();
        if (!$productRow) {
            respond(false, 'Product not found');
        }

        $summaryStmt = $conn->prepare(
            "SELECT COUNT(*) AS rating_count, COALESCE(AVG(rating), 0) AS rating_avg
             FROM product_reviews
             WHERE business_id = ? AND product_id = ? AND status = 'approved'"
        );
        $summaryStmt->bind_param('ii', $businessId, $productId);
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc();
        $summaryStmt->close();

        $listStmt = $conn->prepare(
            "SELECT id, reviewer_name, rating, review_text, created_at
             FROM product_reviews
             WHERE business_id = ? AND product_id = ? AND status = 'approved'
             ORDER BY id DESC
             LIMIT ?"
        );
        $listStmt->bind_param('iii', $businessId, $productId, $limit);
        $listStmt->execute();
        $listResult = $listStmt->get_result();

        $reviews = [];
        while ($row = $listResult->fetch_assoc()) {
            $reviews[] = [
                'id' => intval($row['id'] ?? 0),
                'reviewer_name' => clean_text($row['reviewer_name'] ?? '', 120),
                'rating' => max(1, min(5, intval($row['rating'] ?? 0))),
                'review_text' => clean_text($row['review_text'] ?? '', 2000),
                'created_at' => (string)($row['created_at'] ?? '')
            ];
        }
        $listStmt->close();

        respond(true, '', [
            'reviews' => $reviews,
            'summary' => [
                'rating_avg' => round(floatval($summary['rating_avg'] ?? 0), 2),
                'rating_count' => intval($summary['rating_count'] ?? 0)
            ]
        ]);
    }

    $productId = intval($payload['product_id'] ?? 0);
    $reviewerName = clean_text($payload['reviewer_name'] ?? '', 120);
    $reviewerEmail = clean_text($payload['reviewer_email'] ?? '', 160);
    $reviewText = clean_text($payload['review_text'] ?? '', 2000);
    $rating = intval($payload['rating'] ?? 0);

    if ($productId <= 0) {
        respond(false, 'Invalid product');
    }
    if (strlen($reviewerName) < 2) {
        respond(false, 'Please enter your name');
    }
    if ($reviewerEmail !== '' && !filter_var($reviewerEmail, FILTER_VALIDATE_EMAIL)) {
        respond(false, 'Please enter a valid email address');
    }
    if ($rating < 1 || $rating > 5) {
        respond(false, 'Rating must be between 1 and 5');
    }
    if (strlen($reviewText) < 8) {
        respond(false, 'Please write a short review');
    }

    $productStmt = $conn->prepare(
        "SELECT id
         FROM products
         WHERE id = ? AND business_id = ?
         LIMIT 1"
    );
    $productStmt->bind_param('ii', $productId, $businessId);
    $productStmt->execute();
    $productRow = $productStmt->get_result()->fetch_assoc();
    $productStmt->close();
    if (!$productRow) {
        respond(false, 'Product not found');
    }

    $status = 'approved';
    $insertStmt = $conn->prepare(
        "INSERT INTO product_reviews (business_id, product_id, reviewer_name, reviewer_email, rating, review_text, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $insertStmt->bind_param('iississ', $businessId, $productId, $reviewerName, $reviewerEmail, $rating, $reviewText, $status);
    $insertStmt->execute();
    $insertStmt->close();

    respond(true, 'Review submitted successfully.');
} catch (Exception $e) {
    http_response_code(500);
    error_log('product-reviews.php: ' . $e->getMessage());
    respond(false, 'Unable to process review right now. Please try again shortly.');
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
