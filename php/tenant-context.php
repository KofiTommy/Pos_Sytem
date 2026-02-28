<?php
include_once __DIR__ . '/session-bootstrap.php';
secure_session_start();

const MULTI_TENANT_DEFAULT_NAME = 'CediTill';
const MULTI_TENANT_DEFAULT_EMAIL = 'info@ceditill.com';
const MULTI_TENANT_DEFAULT_PHONE = '+233 000 000 000';
const MULTI_TENANT_DEFAULT_PLAN = 'starter';
const MULTI_TENANT_DEFAULT_THEME_PALETTE = 'default';
const MULTI_TENANT_DEFAULT_HERO_TAGLINE = 'Universal POS tools to manage sales, inventory, and customers with confidence.';
const MULTI_TENANT_DEFAULT_FOOTER_NOTE = 'CediTill helps businesses run faster checkout, smarter stock control, and clear daily sales insights.';

function run_tenant_schema_query(mysqli $conn, string $sql, array $ignoreCodes = [1060, 1061, 1091]): void {
    try {
        $conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        if (!in_array(intval($e->getCode()), $ignoreCodes, true)) {
            throw $e;
        }
    }
}

function tenant_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0) > 0;
}

function tenant_multitenant_schema_cached(?bool $set = null): ?bool {
    static $cached = null;
    if ($set !== null) {
        $cached = $set;
    }
    return $cached;
}

function tenant_is_multitenant_schema_ready(mysqli $conn): bool {
    $cached = tenant_multitenant_schema_cached();
    if ($cached !== null) {
        return $cached;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND (
                (TABLE_NAME = 'businesses' AND COLUMN_NAME = 'business_code')
             OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'business_id')
             OR (TABLE_NAME = 'products' AND COLUMN_NAME = 'business_id')
             OR (TABLE_NAME = 'orders' AND COLUMN_NAME = 'business_id')
             OR (TABLE_NAME = 'order_items' AND COLUMN_NAME = 'business_id')
             OR (TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'business_id')
             OR (TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'theme_palette')
             OR (TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'hero_tagline')
             OR (TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'footer_note')
             OR (TABLE_NAME = 'payment_intents' AND COLUMN_NAME = 'business_id')
             OR (TABLE_NAME = 'payment_gateway_settings' AND COLUMN_NAME = 'business_id')
           )"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $indexStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'products'
           AND INDEX_NAME IN (
             'idx_products_business_id',
             'idx_products_business_created',
             'idx_products_business_featured_created',
             'idx_products_business_name',
             'idx_products_business_category'
           )"
    );
    $indexStmt->execute();
    $indexRow = $indexStmt->get_result()->fetch_assoc();
    $indexStmt->close();

    $hasProductPerformanceIndexes = intval($indexRow['total'] ?? 0) >= 5;
    $isReady = intval($row['total'] ?? 0) >= 11 && $hasProductPerformanceIndexes;
    tenant_multitenant_schema_cached($isReady);
    return $isReady;
}

function tenant_slugify_code(string $value): string {
    $code = strtolower(trim($value));
    $code = preg_replace('/[^a-z0-9]+/', '-', $code);
    $code = trim((string)$code, '-');
    if ($code === '') {
        $code = 'business';
    }
    if (strlen($code) > 64) {
        $code = rtrim(substr($code, 0, 64), '-');
    }
    if ($code === '') {
        $code = 'business';
    }
    return $code;
}

function tenant_generate_unique_code(mysqli $conn, string $base): string {
    $base = tenant_slugify_code($base);
    $candidate = $base;

    $checkStmt = $conn->prepare("SELECT id FROM businesses WHERE business_code = ? LIMIT 1");
    for ($i = 1; $i <= 2000; $i++) {
        $checkStmt->bind_param('s', $candidate);
        $checkStmt->execute();
        $row = $checkStmt->get_result()->fetch_assoc();
        if (!$row) {
            $checkStmt->close();
            return $candidate;
        }
        $suffix = '-' . ($i + 1);
        $trimmedBase = $base;
        if (strlen($trimmedBase) + strlen($suffix) > 64) {
            $trimmedBase = substr($trimmedBase, 0, 64 - strlen($suffix));
            $trimmedBase = rtrim($trimmedBase, '-');
        }
        $candidate = $trimmedBase . $suffix;
    }
    $checkStmt->close();

    try {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    } catch (Exception $e) {
        $suffix = strtoupper(dechex(mt_rand(100000, 999999)));
    }
    return $base . '-' . $suffix;
}

function tenant_fetch_business_by_code(mysqli $conn, string $businessCode): ?array {
    $code = tenant_slugify_code($businessCode);
    $stmt = $conn->prepare(
        "SELECT id, business_code, business_name, business_email, contact_number, status, subscription_plan
         FROM businesses
         WHERE business_code = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

function tenant_fetch_business_by_id(mysqli $conn, int $businessId): ?array {
    if ($businessId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, business_code, business_name, business_email, contact_number, status, subscription_plan
         FROM businesses
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

function tenant_fetch_default_business(mysqli $conn): ?array {
    $stmt = $conn->prepare(
        "SELECT id, business_code, business_name, business_email, contact_number, status, subscription_plan
         FROM businesses
         WHERE status = 'active'
         ORDER BY id ASC
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return $row;
    }

    $stmt = $conn->prepare(
        "SELECT id, business_code, business_name, business_email, contact_number, status, subscription_plan
         FROM businesses
         ORDER BY id ASC
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

function tenant_session_business_id(): int {
    return intval($_SESSION['business_id'] ?? 0);
}

function tenant_session_business_code(): string {
    $code = $_SESSION['business_code'] ?? '';
    return is_string($code) ? tenant_slugify_code($code) : '';
}

function tenant_set_session_context(array $business): void {
    $_SESSION['business_id'] = intval($business['id'] ?? 0);
    $_SESSION['business_code'] = (string)($business['business_code'] ?? '');
    $_SESSION['business_name'] = (string)($business['business_name'] ?? '');

    if (!headers_sent()) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('business_code', (string)($business['business_code'] ?? ''), [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

function tenant_request_uri_business_code(): string {
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($requestUri === '') {
        return '';
    }

    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '';
    }

    $segments = array_values(array_filter(
        explode('/', trim($path, '/')),
        static function ($part) {
            return $part !== '';
        }
    ));

    for ($i = 0; $i < count($segments) - 1; $i++) {
        if (strtolower((string)$segments[$i]) !== 'b') {
            continue;
        }
        $candidate = tenant_slugify_code((string)$segments[$i + 1]);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function tenant_request_business_code(array $payload = []): string {
    $candidates = [];
    if (isset($payload['business_code'])) {
        $candidates[] = $payload['business_code'];
    }
    if (isset($_POST['business_code'])) {
        $candidates[] = $_POST['business_code'];
    }
    if (isset($_GET['business_code'])) {
        $candidates[] = $_GET['business_code'];
    }
    if (isset($_GET['tenant'])) {
        $candidates[] = $_GET['tenant'];
    }
    $fromRequestPath = tenant_request_uri_business_code();
    if ($fromRequestPath !== '') {
        $candidates[] = $fromRequestPath;
    }
    if (!empty($_SERVER['HTTP_X_BUSINESS_CODE'])) {
        $candidates[] = $_SERVER['HTTP_X_BUSINESS_CODE'];
    }
    if (!empty($_SESSION['business_code'])) {
        $candidates[] = $_SESSION['business_code'];
    }
    if (!empty($_COOKIE['business_code'])) {
        $candidates[] = $_COOKIE['business_code'];
    }

    foreach ($candidates as $raw) {
        $trimmed = trim((string)$raw);
        if ($trimmed !== '') {
            return tenant_slugify_code($trimmed);
        }
    }

    return '';
}

function tenant_ensure_business_settings_row(mysqli $conn, int $businessId, string $name, string $email, string $phone): void {
    if ($businessId <= 0 || !tenant_table_exists($conn, 'business_settings')) {
        return;
    }

    $logo = '';
    $palette = MULTI_TENANT_DEFAULT_THEME_PALETTE;
    $heroTagline = MULTI_TENANT_DEFAULT_HERO_TAGLINE;
    $footerNote = MULTI_TENANT_DEFAULT_FOOTER_NOTE;
    $stmt = $conn->prepare(
        "INSERT INTO business_settings (business_id, business_name, business_email, contact_number, logo_filename, theme_palette, hero_tagline, footer_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE business_id = business_id"
    );
    $stmt->bind_param('isssssss', $businessId, $name, $email, $phone, $logo, $palette, $heroTagline, $footerNote);
    $stmt->execute();
    $stmt->close();
}

function tenant_ensure_payment_gateway_row(mysqli $conn, int $businessId): void {
    if ($businessId <= 0 || !tenant_table_exists($conn, 'payment_gateway_settings')) {
        return;
    }

    $gateway = 'paystack';
    $enabled = 0;
    $useSandbox = 1;
    $public = '';
    $stmt = $conn->prepare(
        "INSERT INTO payment_gateway_settings (business_id, gateway, enabled, use_sandbox, public_key)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE business_id = business_id"
    );
    $stmt->bind_param('isiis', $businessId, $gateway, $enabled, $useSandbox, $public);
    $stmt->execute();
    $stmt->close();
}

function ensure_multitenant_schema(mysqli $conn): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    if (tenant_is_multitenant_schema_ready($conn)) {
        $defaultBusiness = tenant_fetch_default_business($conn);
        if ($defaultBusiness) {
            $ready = true;
            return;
        }
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS businesses (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_code VARCHAR(64) NOT NULL UNIQUE,
            business_name VARCHAR(180) NOT NULL,
            business_email VARCHAR(160) NOT NULL,
            contact_number VARCHAR(40) DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            subscription_plan VARCHAR(40) NOT NULL DEFAULT 'starter',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_businesses_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $defaultName = MULTI_TENANT_DEFAULT_NAME;
    $defaultEmail = MULTI_TENANT_DEFAULT_EMAIL;
    $defaultPhone = MULTI_TENANT_DEFAULT_PHONE;

    if (tenant_table_exists($conn, 'business_settings')) {
        $legacyStmt = $conn->prepare(
            "SELECT business_name, business_email, contact_number
             FROM business_settings
             ORDER BY id ASC
             LIMIT 1"
        );
        $legacyStmt->execute();
        $legacy = $legacyStmt->get_result()->fetch_assoc();
        $legacyStmt->close();
        if ($legacy) {
            $defaultName = trim((string)($legacy['business_name'] ?? $defaultName)) ?: $defaultName;
            $defaultEmail = trim((string)($legacy['business_email'] ?? $defaultEmail)) ?: $defaultEmail;
            $defaultPhone = trim((string)($legacy['contact_number'] ?? $defaultPhone)) ?: $defaultPhone;
        }
    }

    $defaultBusiness = tenant_fetch_default_business($conn);
    if (!$defaultBusiness) {
        $defaultCode = tenant_generate_unique_code($conn, $defaultName);
        $status = 'active';
        $plan = MULTI_TENANT_DEFAULT_PLAN;
        $seedStmt = $conn->prepare(
            "INSERT INTO businesses
             (business_code, business_name, business_email, contact_number, status, subscription_plan)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $seedStmt->bind_param('ssssss', $defaultCode, $defaultName, $defaultEmail, $defaultPhone, $status, $plan);
        $seedStmt->execute();
        $seedStmt->close();

        $defaultBusiness = tenant_fetch_business_by_code($conn, $defaultCode);
    }
    if (!$defaultBusiness) {
        throw new Exception('Unable to initialize tenant business context.');
    }
    $defaultBusinessId = intval($defaultBusiness['id']);

    if (tenant_table_exists($conn, 'business_settings')) {
        run_tenant_schema_query($conn, "ALTER TABLE business_settings MODIFY id INT NOT NULL AUTO_INCREMENT", [1060, 1067, 1833]);
        run_tenant_schema_query($conn, "ALTER TABLE business_settings ADD COLUMN business_id INT NULL AFTER id");
        run_tenant_schema_query($conn, "ALTER TABLE business_settings ADD COLUMN theme_palette VARCHAR(30) NOT NULL DEFAULT 'default' AFTER logo_filename");
        run_tenant_schema_query($conn, "ALTER TABLE business_settings ADD COLUMN hero_tagline VARCHAR(320) NOT NULL DEFAULT 'Universal POS tools to manage sales, inventory, and customers with confidence.' AFTER theme_palette");
        run_tenant_schema_query($conn, "ALTER TABLE business_settings ADD COLUMN footer_note VARCHAR(320) NOT NULL DEFAULT 'CediTill helps businesses run faster checkout, smarter stock control, and clear daily sales insights.' AFTER hero_tagline");
        run_tenant_schema_query($conn, "ALTER TABLE business_settings ADD UNIQUE KEY uk_business_settings_business_id (business_id)");
        $updateStmt = $conn->prepare(
            "UPDATE business_settings
             SET business_id = ?
             WHERE business_id IS NULL OR business_id = 0"
        );
        $updateStmt->bind_param('i', $defaultBusinessId);
        $updateStmt->execute();
        $updateStmt->close();
        run_tenant_schema_query($conn, "ALTER TABLE business_settings MODIFY business_id INT NOT NULL", [1265]);
    }

    if (tenant_table_exists($conn, 'users')) {
        run_tenant_schema_query($conn, "ALTER TABLE users ADD COLUMN business_id INT NULL AFTER id");
        $updateStmt = $conn->prepare("UPDATE users SET business_id = ? WHERE business_id IS NULL OR business_id = 0");
        $updateStmt->bind_param('i', $defaultBusinessId);
        $updateStmt->execute();
        $updateStmt->close();
        run_tenant_schema_query($conn, "ALTER TABLE users MODIFY business_id INT NOT NULL", [1265]);
        run_tenant_schema_query($conn, "ALTER TABLE users ADD INDEX idx_users_business_id (business_id)");
        run_tenant_schema_query($conn, "ALTER TABLE users DROP INDEX username", [1091]);
        run_tenant_schema_query($conn, "ALTER TABLE users DROP INDEX email", [1091]);
        run_tenant_schema_query($conn, "ALTER TABLE users ADD UNIQUE KEY uk_users_business_username (business_id, username)");
        run_tenant_schema_query($conn, "ALTER TABLE users ADD UNIQUE KEY uk_users_business_email (business_id, email)");
    }

    if (tenant_table_exists($conn, 'products')) {
        run_tenant_schema_query($conn, "ALTER TABLE products ADD COLUMN business_id INT NULL AFTER id");
        $updateStmt = $conn->prepare("UPDATE products SET business_id = ? WHERE business_id IS NULL OR business_id = 0");
        $updateStmt->bind_param('i', $defaultBusinessId);
        $updateStmt->execute();
        $updateStmt->close();
        run_tenant_schema_query($conn, "ALTER TABLE products MODIFY business_id INT NOT NULL", [1265]);
        run_tenant_schema_query($conn, "ALTER TABLE products ADD INDEX idx_products_business_id (business_id)");
        run_tenant_schema_query($conn, "ALTER TABLE products ADD INDEX idx_products_business_created (business_id, created_at)");
        run_tenant_schema_query($conn, "ALTER TABLE products ADD INDEX idx_products_business_featured_created (business_id, featured, created_at)");
        run_tenant_schema_query($conn, "ALTER TABLE products ADD INDEX idx_products_business_name (business_id, name)");
        run_tenant_schema_query($conn, "ALTER TABLE products ADD INDEX idx_products_business_category (business_id, category)");
    }

    if (tenant_table_exists($conn, 'orders')) {
        run_tenant_schema_query($conn, "ALTER TABLE orders ADD COLUMN business_id INT NULL AFTER id");
        $updateStmt = $conn->prepare("UPDATE orders SET business_id = ? WHERE business_id IS NULL OR business_id = 0");
        $updateStmt->bind_param('i', $defaultBusinessId);
        $updateStmt->execute();
        $updateStmt->close();
        run_tenant_schema_query($conn, "ALTER TABLE orders MODIFY business_id INT NOT NULL", [1265]);
        run_tenant_schema_query($conn, "ALTER TABLE orders ADD INDEX idx_orders_business_id (business_id)");
    }

    if (tenant_table_exists($conn, 'order_items')) {
        run_tenant_schema_query($conn, "ALTER TABLE order_items ADD COLUMN business_id INT NULL AFTER order_id");
        if (tenant_table_exists($conn, 'orders')) {
            $conn->query(
                "UPDATE order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 SET oi.business_id = o.business_id
                 WHERE oi.business_id IS NULL OR oi.business_id = 0"
            );
        }
        $updateStmt = $conn->prepare("UPDATE order_items SET business_id = ? WHERE business_id IS NULL OR business_id = 0");
        $updateStmt->bind_param('i', $defaultBusinessId);
        $updateStmt->execute();
        $updateStmt->close();
        run_tenant_schema_query($conn, "ALTER TABLE order_items MODIFY business_id INT NOT NULL", [1265]);
        run_tenant_schema_query($conn, "ALTER TABLE order_items ADD INDEX idx_order_items_business_id (business_id)");
        run_tenant_schema_query($conn, "ALTER TABLE order_items ADD INDEX idx_order_items_business_order (business_id, order_id)");
    }

    if (tenant_table_exists($conn, 'contact_messages')) {
        run_tenant_schema_query($conn, "ALTER TABLE contact_messages ADD COLUMN business_id INT NULL AFTER id");
        $updateStmt = $conn->prepare("UPDATE contact_messages SET business_id = ? WHERE business_id IS NULL OR business_id = 0");
        $updateStmt->bind_param('i', $defaultBusinessId);
        $updateStmt->execute();
        $updateStmt->close();
        run_tenant_schema_query($conn, "ALTER TABLE contact_messages MODIFY business_id INT NOT NULL", [1265]);
        run_tenant_schema_query($conn, "ALTER TABLE contact_messages ADD INDEX idx_contact_messages_business_id (business_id)");
    }

    if (tenant_table_exists($conn, 'payment_intents')) {
        run_tenant_schema_query($conn, "ALTER TABLE payment_intents ADD COLUMN business_id INT NULL AFTER id");
        if (tenant_table_exists($conn, 'orders')) {
            $conn->query(
                "UPDATE payment_intents pi
                 JOIN orders o ON o.id = pi.order_id
                 SET pi.business_id = o.business_id
                 WHERE pi.business_id IS NULL OR pi.business_id = 0"
            );
        }
        $updateStmt = $conn->prepare("UPDATE payment_intents SET business_id = ? WHERE business_id IS NULL OR business_id = 0");
        $updateStmt->bind_param('i', $defaultBusinessId);
        $updateStmt->execute();
        $updateStmt->close();
        run_tenant_schema_query($conn, "ALTER TABLE payment_intents MODIFY business_id INT NOT NULL", [1265]);
        run_tenant_schema_query($conn, "ALTER TABLE payment_intents ADD INDEX idx_payment_intents_business_id (business_id)");
    }

    if (tenant_table_exists($conn, 'payment_gateway_settings')) {
        run_tenant_schema_query($conn, "ALTER TABLE payment_gateway_settings MODIFY id INT NOT NULL AUTO_INCREMENT", [1060, 1067, 1833]);
        run_tenant_schema_query($conn, "ALTER TABLE payment_gateway_settings ADD COLUMN business_id INT NULL AFTER id");
        $updateStmt = $conn->prepare(
            "UPDATE payment_gateway_settings
             SET business_id = ?
             WHERE business_id IS NULL OR business_id = 0"
        );
        $updateStmt->bind_param('i', $defaultBusinessId);
        $updateStmt->execute();
        $updateStmt->close();
        run_tenant_schema_query($conn, "ALTER TABLE payment_gateway_settings MODIFY business_id INT NOT NULL", [1265]);
        $conn->query(
            "DELETE t_old FROM payment_gateway_settings t_old
             JOIN payment_gateway_settings t_new
               ON t_old.business_id = t_new.business_id
              AND t_old.id < t_new.id"
        );
        run_tenant_schema_query($conn, "ALTER TABLE payment_gateway_settings ADD UNIQUE KEY uk_payment_gateway_business_id (business_id)");
    }

    tenant_ensure_business_settings_row($conn, $defaultBusinessId, $defaultName, $defaultEmail, $defaultPhone);
    tenant_ensure_payment_gateway_row($conn, $defaultBusinessId);

    tenant_multitenant_schema_cached(true);
    $ready = true;
}

function tenant_resolve_business_context(mysqli $conn, array $payload = [], bool $allowDefault = true): ?array {
    ensure_multitenant_schema($conn);

    $business = null;
    $requestedCode = tenant_request_business_code($payload);
    if ($requestedCode !== '') {
        $business = tenant_fetch_business_by_code($conn, $requestedCode);
        if (!$business) {
            return null;
        }
    }

    if (!$business) {
        $sessionBusinessId = tenant_session_business_id();
        if ($sessionBusinessId > 0) {
            $business = tenant_fetch_business_by_id($conn, $sessionBusinessId);
        }
    }

    if (!$business && $allowDefault) {
        $business = tenant_fetch_default_business($conn);
    }

    if (!$business) {
        return null;
    }

    tenant_set_session_context($business);
    return $business;
}

function tenant_require_business_context(mysqli $conn, array $payload = [], bool $allowDefault = true): array {
    $business = tenant_resolve_business_context($conn, $payload, $allowDefault);
    if (!$business) {
        throw new Exception('Business account not found. Check your business code.');
    }
    if (strtolower((string)($business['status'] ?? 'active')) !== 'active') {
        throw new Exception('Business account is not active.');
    }
    return $business;
}
