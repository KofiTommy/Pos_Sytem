<?php
include_once __DIR__ . '/session-bootstrap.php';
secure_session_start();

const HQ_SESSION_AUTH_KEY = 'hq_admin_authenticated';
const HQ_SESSION_USER_KEY = 'hq_admin_username';
const HQ_SESSION_LOGIN_AT_KEY = 'hq_admin_login_at';
const HQ_SESSION_LAST_SEEN_KEY = 'hq_admin_last_seen';
const HQ_SESSION_UA_HASH_KEY = 'hq_admin_ua_hash';
const HQ_DEFAULT_SESSION_IDLE_SECONDS = 1800;

function hq_env(string $key): string {
    return trim((string)getenv($key));
}

function hq_request_host(): string {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = explode(':', $host)[0] ?? '';
    return trim((string)$host);
}

function hq_client_ip(): string {
    // Do not trust forwarding headers by default.
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }
    return '0.0.0.0';
}

function hq_request_user_agent(): string {
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (strlen($ua) > 255) {
        $ua = substr($ua, 0, 255);
    }
    return $ua;
}

function hq_user_agent_hash(): string {
    return hash('sha256', hq_request_user_agent());
}

function hq_session_idle_seconds(): int {
    $value = intval(hq_env('HQ_SESSION_IDLE_SECONDS'));
    if ($value <= 0) {
        return HQ_DEFAULT_SESSION_IDLE_SECONDS;
    }
    if ($value < 300) {
        $value = 300;
    }
    if ($value > 86400) {
        $value = 86400;
    }
    return $value;
}

function hq_plain_password_allowed(): bool {
    $override = strtolower(hq_env('HQ_ALLOW_PLAIN_PASSWORD'));
    if (in_array($override, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    $appEnv = strtolower(hq_env('APP_ENV'));
    return $appEnv !== 'production';
}

function hq_allowed_ip_rules(): array {
    $raw = trim((string)getenv('HQ_ALLOWED_IPS'));
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[\s,]+/', $raw) ?: [];
    $rules = [];
    foreach ($parts as $part) {
        $rule = trim((string)$part);
        if ($rule === '') {
            continue;
        }
        $rules[] = $rule;
    }
    return array_values(array_unique($rules));
}

function hq_ip_in_cidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '' || strpos($cidr, '/') === false) {
        return false;
    }

    $parts = explode('/', $cidr, 2);
    $subnet = trim((string)($parts[0] ?? ''));
    $prefixRaw = trim((string)($parts[1] ?? ''));
    if ($subnet === '' || $prefixRaw === '' || !ctype_digit($prefixRaw)) {
        return false;
    }

    $ipBin = inet_pton($ip);
    $subnetBin = inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) {
        return false;
    }
    if (strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    $prefix = intval($prefixRaw);
    $maxBits = strlen($ipBin) * 8;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;

    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
}

function hq_ip_matches_rule(string $ip, string $rule): bool {
    $rule = trim($rule);
    if ($rule === '') {
        return false;
    }

    if (filter_var($rule, FILTER_VALIDATE_IP) && strcasecmp($ip, $rule) === 0) {
        return true;
    }

    return hq_ip_in_cidr($ip, $rule);
}

function hq_ip_is_allowed(): bool {
    $rules = hq_allowed_ip_rules();
    if (count($rules) === 0) {
        return true;
    }

    $ip = hq_client_ip();
    foreach ($rules as $rule) {
        if (hq_ip_matches_rule($ip, $rule)) {
            return true;
        }
    }
    return false;
}

function hq_configured_username(): string {
    return hq_env('HQ_ADMIN_USERNAME');
}

function hq_configured_password_hash(): string {
    return hq_env('HQ_ADMIN_PASSWORD_HASH');
}

function hq_configured_password_plain(): string {
    return (string)getenv('HQ_ADMIN_PASSWORD');
}

function hq_is_enabled(): bool {
    $username = hq_configured_username();
    if ($username === '') {
        return false;
    }

    $hash = hq_configured_password_hash();
    if ($hash !== '') {
        return true;
    }

    $plain = hq_configured_password_plain();
    if ($plain === '') {
        return false;
    }

    return hq_plain_password_allowed();
}

function hq_actions_enabled(): bool {
    $raw = strtolower(hq_env('HQ_ACTIONS_ENABLED'));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function hq_is_authenticated(): bool {
    $authenticated = !empty($_SESSION[HQ_SESSION_AUTH_KEY]) && is_string($_SESSION[HQ_SESSION_USER_KEY] ?? null);
    if (!$authenticated) {
        return false;
    }

    $username = trim((string)($_SESSION[HQ_SESSION_USER_KEY] ?? ''));
    if ($username === '') {
        hq_logout();
        return false;
    }

    $uaHash = trim((string)($_SESSION[HQ_SESSION_UA_HASH_KEY] ?? ''));
    if ($uaHash !== '' && !hash_equals($uaHash, hq_user_agent_hash())) {
        hq_logout();
        return false;
    }

    $now = time();
    $lastSeen = intval($_SESSION[HQ_SESSION_LAST_SEEN_KEY] ?? 0);
    if ($lastSeen <= 0) {
        hq_logout();
        return false;
    }

    $idleLimit = hq_session_idle_seconds();
    if (($now - $lastSeen) > $idleLimit) {
        hq_logout();
        return false;
    }

    $_SESSION[HQ_SESSION_LAST_SEEN_KEY] = $now;
    return true;
}

function hq_current_username(): string {
    if (!hq_is_authenticated()) {
        return '';
    }
    return trim((string)($_SESSION[HQ_SESSION_USER_KEY] ?? ''));
}

function hq_password_matches(string $password): bool {
    $hash = hq_configured_password_hash();
    if ($hash !== '') {
        return password_verify($password, $hash);
    }

    if (!hq_plain_password_allowed()) {
        return false;
    }

    $plain = hq_configured_password_plain();
    if ($plain === '') {
        return false;
    }

    return hash_equals($plain, $password);
}

function hq_authenticate(string $username, string $password): bool {
    if (!hq_is_enabled()) {
        return false;
    }

    $configuredUsername = hq_configured_username();
    $providedUsername = trim($username);
    if ($configuredUsername === '' || $providedUsername === '' || $password === '') {
        return false;
    }

    if (!hash_equals(strtolower($configuredUsername), strtolower($providedUsername))) {
        return false;
    }

    return hq_password_matches($password);
}

function hq_mark_authenticated(string $username): void {
    session_regenerate_id(true);
    $now = time();
    $_SESSION[HQ_SESSION_AUTH_KEY] = true;
    $_SESSION[HQ_SESSION_USER_KEY] = trim($username);
    $_SESSION[HQ_SESSION_LOGIN_AT_KEY] = $now;
    $_SESSION[HQ_SESSION_LAST_SEEN_KEY] = $now;
    $_SESSION[HQ_SESSION_UA_HASH_KEY] = hq_user_agent_hash();
}

function hq_logout(): void {
    unset(
        $_SESSION[HQ_SESSION_AUTH_KEY],
        $_SESSION[HQ_SESSION_USER_KEY],
        $_SESSION[HQ_SESSION_LOGIN_AT_KEY],
        $_SESSION[HQ_SESSION_LAST_SEEN_KEY],
        $_SESSION[HQ_SESSION_UA_HASH_KEY]
    );
}

function hq_is_same_origin_write_request(): bool {
    $host = hq_request_host();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));

    $originHost = $origin !== '' ? strtolower((string)parse_url($origin, PHP_URL_HOST)) : '';
    $refererHost = $referer !== '' ? strtolower((string)parse_url($referer, PHP_URL_HOST)) : '';

    $sameOrigin = ($originHost !== '' && strcasecmp($originHost, $host) === 0)
        || ($refererHost !== '' && strcasecmp($refererHost, $host) === 0);
    $crossSiteFetch = $fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true);

    return !$crossSiteFetch && $sameOrigin;
}

function hq_require_page(string $redirectUrl = 'login.php'): void {
    if (!hq_is_enabled()) {
        http_response_code(503);
        echo 'HQ dashboard is not configured. Set HQ_ADMIN_USERNAME and HQ_ADMIN_PASSWORD_HASH (or HQ_ADMIN_PASSWORD).';
        exit();
    }

    if (!hq_ip_is_allowed()) {
        http_response_code(403);
        echo 'Forbidden';
        exit();
    }

    if (!hq_is_authenticated()) {
        header('Location: ' . $redirectUrl);
        exit();
    }
}

function hq_require_api(): void {
    header('Content-Type: application/json');

    if (!hq_is_enabled()) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'HQ dashboard is not configured.'
        ]);
        exit();
    }

    if (!hq_is_authenticated()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
        exit();
    }

    if (!hq_ip_is_allowed()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Forbidden'
        ]);
        exit();
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    if (!hq_is_same_origin_write_request()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Forbidden'
        ]);
        exit();
    }
}
