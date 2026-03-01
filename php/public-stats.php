<?php
header('Content-Type: application/json');

include 'db-connection.php';
include 'tenant-context.php';

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

try {
    ensure_multitenant_schema($conn);

    $summaryStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS registered_businesses,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_businesses
         FROM businesses"
    );
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    respond(true, '', [
        'stats' => [
            'registered_businesses' => intval($summary['registered_businesses'] ?? 0),
            'active_businesses' => intval($summary['active_businesses'] ?? 0)
        ],
        'generated_at' => gmdate('c')
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('public-stats.php: ' . $e->getMessage());
    respond(false, 'Unable to load platform statistics right now.');
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
