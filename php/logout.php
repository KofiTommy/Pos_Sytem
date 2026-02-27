<?php
session_start();

// Destroy session
session_unset();
session_destroy();

// Clear cookie
if (isset($_COOKIE['username'])) {
    setcookie('username', '', time() - 3600, '/');
}
if (isset($_COOKIE['business_code'])) {
    setcookie('business_code', '', time() - 3600, '/');
}

// Redirect to home
header('Location: ../index.html');
exit();
?>
