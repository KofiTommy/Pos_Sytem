<?php
include_once __DIR__ . '/session-bootstrap.php';
secure_session_start();

const CSRF_SESSION_KEY = '_csrf_token';
const CSRF_COOKIE_NAME = 'XSRF-TOKEN';

function csrf_is_safe_method(string $method): bool {
    $m = strtoupper(trim($method));
    return in_array($m, ['GET', 'HEAD', 'OPTIONS'], true);
}

function csrf_generate_token(): string {
    try {
        return bin2hex(random_bytes(32));
    } catch (Exception $e) {
        return hash('sha256', uniqid('csrf', true) . mt_rand());
    }
}

function csrf_token(): string {
    $current = $_SESSION[CSRF_SESSION_KEY] ?? '';
    if (is_string($current) && $current !== '') {
        return $current;
    }

    $created = csrf_generate_token();
    $_SESSION[CSRF_SESSION_KEY] = $created;
    return $created;
}

function csrf_rotate_token(): string {
    $token = csrf_generate_token();
    $_SESSION[CSRF_SESSION_KEY] = $token;
    return $token;
}

function csrf_cookie_options(): array {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443);

    return [
        'expires' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax'
    ];
}

function csrf_issue_cookie(): void {
    if (headers_sent()) {
        return;
    }
    $token = csrf_token();
    setcookie(CSRF_COOKIE_NAME, $token, csrf_cookie_options());
}

function csrf_clear_cookie(): void {
    if (headers_sent()) {
        return;
    }
    $options = csrf_cookie_options();
    $options['expires'] = time() - 3600;
    setcookie(CSRF_COOKIE_NAME, '', $options);
    setcookie(CSRF_COOKIE_NAME, '', time() - 3600, '/');
}

function csrf_request_token(): string {
    $candidates = [];
    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $candidates[] = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (isset($_SERVER['HTTP_X_XSRF_TOKEN'])) {
        $candidates[] = $_SERVER['HTTP_X_XSRF_TOKEN'];
    }
    if (isset($_POST['_csrf'])) {
        $candidates[] = $_POST['_csrf'];
    }

    foreach ($candidates as $candidate) {
        $value = trim((string)$candidate);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function csrf_validate_request_token(): bool {
    $expected = csrf_token();
    $provided = csrf_request_token();
    if ($expected === '' || $provided === '') {
        return false;
    }
    return hash_equals($expected, $provided);
}

