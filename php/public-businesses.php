<?php
header('Content-Type: application/json');

include 'db-connection.php';
include 'tenant-context.php';

function respond($success, $message = '', $extra = [], $statusCode = 200) {
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

try {
    ensure_multitenant_schema($conn);

    $query = trim((string)($_GET['q'] ?? ''));
    $query = preg_replace('/\s+/', ' ', $query);
    if ($query === null) {
        $query = '';
    }
    $query = str_replace(['%', '_'], '', $query);
    if (strlen($query) > 80) {
        $query = substr($query, 0, 80);
    }

    $limit = intval($_GET['limit'] ?? 8);
    if ($limit < 1) $limit = 1;
    if ($limit > 24) $limit = 24;

    $results = [];
    $total = 0;

    if ($query !== '') {
        $like = '%' . $query . '%';
        $prefix = $query . '%';

        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM businesses
             WHERE status = 'active'
               AND business_name LIKE ?"
        );
        $countStmt->bind_param('s', $like);
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc() ?: [];
        $countStmt->close();
        $total = intval($countRow['total'] ?? 0);

        $searchStmt = $conn->prepare(
            "SELECT business_code, business_name
             FROM businesses
             WHERE status = 'active'
               AND business_name LIKE ?
             ORDER BY
               CASE
                 WHEN business_name LIKE ? THEN 0
                 ELSE 1
               END,
               business_name ASC
             LIMIT ?"
        );
        $searchStmt->bind_param('ssi', $like, $prefix, $limit);
        $searchStmt->execute();
        $searchResult = $searchStmt->get_result();
        while ($row = $searchResult->fetch_assoc()) {
            $results[] = [
                'business_code' => (string)($row['business_code'] ?? ''),
                'business_name' => (string)($row['business_name'] ?? '')
            ];
        }
        $searchStmt->close();
    } else {
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM businesses
             WHERE status = 'active'"
        );
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc() ?: [];
        $countStmt->close();
        $total = intval($countRow['total'] ?? 0);

        $listStmt = $conn->prepare(
            "SELECT business_code, business_name
             FROM businesses
             WHERE status = 'active'
             ORDER BY created_at DESC, id DESC
             LIMIT ?"
        );
        $listStmt->bind_param('i', $limit);
        $listStmt->execute();
        $listResult = $listStmt->get_result();
        while ($row = $listResult->fetch_assoc()) {
            $results[] = [
                'business_code' => (string)($row['business_code'] ?? ''),
                'business_name' => (string)($row['business_name'] ?? '')
            ];
        }
        $listStmt->close();
    }

    respond(true, '', [
        'query' => $query,
        'total' => $total,
        'results' => $results
    ]);
} catch (Exception $e) {
    error_log('public-businesses.php: ' . $e->getMessage());
    respond(false, 'Unable to load business directory right now.', [], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
