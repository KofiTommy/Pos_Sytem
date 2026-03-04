<?php
include_once __DIR__ . '/session-bootstrap.php';
secure_session_start();
include_once __DIR__ . '/csrf.php';

function current_user_role() {
    $role = $_SESSION['role'] ?? '';
    if (!is_string($role)) {
        return '';
    }
    $role = strtolower(trim($role));
    if ($role === 'admin') {
        $role = 'owner';
    }
    return in_array($role, ['owner', 'sales'], true) ? $role : '';
}

function current_business_id() {
    return intval($_SESSION['business_id'] ?? 0);
}

function current_business_code() {
    $code = $_SESSION['business_code'] ?? '';
    return is_string($code) ? trim($code) : '';
}

function resolve_admin_api_method() {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        return $method;
    }

    $overrideRaw = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_POST['_method'] ?? '');
    $override = strtoupper(trim((string)$overrideRaw));
    if (!in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
        return $method;
    }

    $_SERVER['REQUEST_METHOD'] = $override;
    return $override;
}

function is_admin_authenticated() {
    return isset($_SESSION['user_id']) && current_user_role() !== '' && current_business_id() > 0;
}

function require_admin_page($redirect_url = '../pages/login.html') {
    if (!is_admin_authenticated()) {
        header('Location: ' . $redirect_url);
        exit();
    }
    csrf_issue_cookie();
}

function require_admin_api() {
    if (!is_admin_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
        exit();
    }

    $method = resolve_admin_api_method();
    csrf_issue_cookie();
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $host = explode(':', $host)[0] ?? '';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));

        $originHost = $origin !== '' ? strtolower((string)parse_url($origin, PHP_URL_HOST)) : '';
        $refererHost = $referer !== '' ? strtolower((string)parse_url($referer, PHP_URL_HOST)) : '';

        $sameOrigin = ($originHost !== '' && strcasecmp($originHost, $host) === 0)
            || ($refererHost !== '' && strcasecmp($refererHost, $host) === 0);

        $crossSiteFetch = $fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true);
        if ($crossSiteFetch || !$sameOrigin) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Forbidden'
            ]);
            exit();
        }

        if (!csrf_validate_request_token()) {
            http_response_code(419);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'CSRF token mismatch'
            ]);
            exit();
        }
    }
}

function require_roles_page($roles, $redirect_url = '../pages/login.html') {
    require_admin_page($redirect_url);
    $allowed = is_array($roles) ? $roles : [];
    $role = current_user_role();
    if (!in_array($role, $allowed, true)) {
        $fallback = $role === 'sales' ? 'pos.php' : 'dashboard.php';
        header('Location: ' . $fallback);
        exit();
    }
}

function require_roles_api($roles) {
    require_admin_api();
    $allowed = is_array($roles) ? $roles : [];
    $role = current_user_role();
    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Forbidden'
        ]);
        exit();
    }
}
?>
