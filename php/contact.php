<?php
header('Content-Type: application/json');
include 'db-connection.php';
include 'tenant-context.php';

function respond($success, $message = '') {
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit();
}

try {
    $business = tenant_require_business_context($conn, [], true);
    $businessId = intval($business['id'] ?? 0);
    if ($businessId <= 0) {
        throw new Exception('Invalid business context');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';

    if ($name === '' || $email === '' || $subject === '' || $message === '') {
        throw new Exception('Please complete all required fields');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address');
    }
    if (strlen($name) > 120 || strlen($email) > 160 || strlen($phone) > 40 || strlen($subject) > 180) {
        throw new Exception('One or more fields are too long');
    }
    if (strlen($message) > 4000) {
        throw new Exception('Message is too long');
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'contact_messages'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        throw new Exception('Contact form is not configured. Please run setup.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO contact_messages (business_id, name, email, phone, subject, message, status)
         VALUES (?, ?, ?, ?, ?, ?, 'new')"
    );
    $stmt->bind_param('isssss', $businessId, $name, $email, $phone, $subject, $message);
    $stmt->execute();
    $stmt->close();

    respond(true, 'Message sent successfully. We will get back to you soon.');
} catch (Exception $e) {
    error_log('contact.php: ' . $e->getMessage());
    respond(false, 'Unable to send your message right now. Please try again later.');
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
