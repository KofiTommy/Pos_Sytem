<?php
// Database connection file
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = trim((string)getenv('DB_HOST'));
$username = trim((string)getenv('DB_USER'));
$password = (string)getenv('DB_PASS');
$database = trim((string)getenv('DB_NAME'));
$appEnv = strtolower(trim((string)getenv('APP_ENV')));
$databaseConfigured = $database !== '';

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

// Create connection. Support the legacy local typo'd database name when DB_NAME is not set.
$databaseCandidates = [$database];
if (!$databaseConfigured) {
    $databaseCandidates[] = 'possytem_db';
}

$conn = null;
$lastConnectionError = null;
foreach (array_values(array_unique($databaseCandidates)) as $candidateDatabase) {
    try {
        $conn = new mysqli($host, $username, $password, $candidateDatabase);
        $database = $candidateDatabase;
        if ($candidateDatabase !== $databaseCandidates[0]) {
            error_log('db-connection.php: using fallback database "' . $candidateDatabase . '"');
        }
        break;
    } catch (mysqli_sql_exception $e) {
        $lastConnectionError = $e;
        if ($databaseConfigured || intval($e->getCode()) !== 1049) {
            throw $e;
        }
    }
}

if (!$conn instanceof mysqli) {
    if ($lastConnectionError instanceof mysqli_sql_exception) {
        throw $lastConnectionError;
    }
    throw new mysqli_sql_exception('Database connection failed.');
}

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
