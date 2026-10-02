<?php
// Copy to payment-config.local.php. Generate a permanent key with:
// C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"
// Preserve this key in backups: changing it makes saved gateway secrets unreadable.
return ['encryption_key' => ''];
