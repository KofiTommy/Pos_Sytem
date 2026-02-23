<?php
// Database connection file
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = 'localhost';
$username = 'root';  // Default XAMPP username
$password = '';      // Default XAMPP password (empty)
$database = 'possystem_db';

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4 for full Unicode coverage
$conn->set_charset("utf8mb4");

?>
