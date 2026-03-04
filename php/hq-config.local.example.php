<?php
/**
 * Local-only HQ config fallback.
 * Copy this file to hq-config.local.php on the server and set real values.
 * Do not commit hq-config.local.php to git.
 */
return [
    'HQ_ADMIN_USERNAME' => 'hqadmin',
    'HQ_ADMIN_PASSWORD_HASH' => '$2y$10$replace_with_real_bcrypt_hash',
    'HQ_ACTIONS_ENABLED' => 'false',
    // Optional hardening:
    // 'HQ_ALLOWED_IPS' => '203.0.113.10/32',
    // 'HQ_SESSION_IDLE_SECONDS' => '900',
    // 'APP_ENV' => 'production',
];
