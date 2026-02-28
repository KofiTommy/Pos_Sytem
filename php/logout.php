<?php
include_once __DIR__ . '/session-bootstrap.php';
secure_session_start();

$tenantCode = '';
if (isset($_SESSION['business_code']) && is_string($_SESSION['business_code'])) {
    $tenantCode = strtolower(trim($_SESSION['business_code']));
}

function expire_cookie(string $name, string $path = '/'): void {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => $path,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    // Fallback for environments that do not fully support the options array signature.
    setcookie($name, '', time() - 3600, $path);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $params['path'] ?? '/', $params['domain'] ?? '', !empty($params['secure']), !empty($params['httponly']));
}

session_unset();
session_destroy();

expire_cookie('username');
expire_cookie('business_code');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="1;url=../index.html">
    <title>Logging out...</title>
</head>
<body>
    <script>
        (function () {
            try {
                const tenantCode = <?php echo json_encode($tenantCode); ?>;
                const sanitize = (value) => String(value || '')
                    .toLowerCase()
                    .trim()
                    .replace(/[^a-z0-9-]/g, '')
                    .substring(0, 64);

                const code = sanitize(tenantCode);
                localStorage.removeItem('tenant_code');
                sessionStorage.removeItem('tenant_code');
                localStorage.removeItem('cart');
                sessionStorage.removeItem('cart');

                if (code) {
                    localStorage.removeItem('cart:' + code);
                    sessionStorage.removeItem('cart:' + code);
                }
            } catch (error) {
                // Ignore browser storage cleanup errors.
            }

            window.location.replace('../index.html');
        })();
    </script>
    <noscript>
        <p>Logged out. <a href="../index.html">Continue</a>.</p>
    </noscript>
</body>
</html>
