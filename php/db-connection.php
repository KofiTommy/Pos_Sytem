<?php
// Database connection file
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = trim((string)getenv('DB_HOST'));
$username = trim((string)getenv('DB_USER'));
$password = (string)getenv('DB_PASS');
$database = trim((string)getenv('DB_NAME'));
$appEnv = strtolower(trim((string)getenv('APP_ENV')));

if ($host === '') {
    $host = 'localhost';
}
if ($username === '') {
    $username = 'root';  // Local development fallback.
}
if ($database === '') {
    $database = 'possystem_db';
}

if ($appEnv === 'production') {
    $missingConfig = (trim((string)getenv('DB_HOST')) === '')
        || (trim((string)getenv('DB_USER')) === '')
        || (trim((string)getenv('DB_NAME')) === '');
    if ($missingConfig) {
        error_log('db-connection.php: missing DB_* environment variables in production mode');
        if (!headers_sent()) {
            http_response_code(500);
        }
        die('Database connection is not configured.');
    }
}

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    error_log('db-connection.php: ' . $conn->connect_error);
    if (!headers_sent()) {
        http_response_code(500);
    }
    die('Database connection failed.');
}

// Set charset to utf8mb4 for full Unicode coverage
$conn->set_charset("utf8mb4");

?>
