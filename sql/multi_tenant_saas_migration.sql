-- Multi-tenant SaaS migration for POS system
-- Safe to run on MariaDB 10.5+ / MySQL 8+ (IF EXISTS/IF NOT EXISTS syntax)

START TRANSACTION;

CREATE TABLE IF NOT EXISTS businesses (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO businesses (business_code, business_name, business_email, contact_number, status, subscription_plan)
SELECT
  'mother-care',
  COALESCE(NULLIF((SELECT business_name FROM business_settings ORDER BY id ASC LIMIT 1), ''), 'Mother Care'),
  COALESCE(NULLIF((SELECT business_email FROM business_settings ORDER BY id ASC LIMIT 1), ''), 'info@mothercare.com'),
  COALESCE(NULLIF((SELECT contact_number FROM business_settings ORDER BY id ASC LIMIT 1), ''), '+233 000 000 000'),
  'active',
  'starter'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM businesses LIMIT 1);

SET @default_business_id := (SELECT id FROM businesses ORDER BY id ASC LIMIT 1);

ALTER TABLE users ADD COLUMN IF NOT EXISTS business_id INT NULL AFTER id;
UPDATE users SET business_id = @default_business_id WHERE business_id IS NULL OR business_id = 0;
ALTER TABLE users MODIFY business_id INT NOT NULL;
ALTER TABLE users DROP INDEX IF EXISTS username;
ALTER TABLE users DROP INDEX IF EXISTS email;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_business_id (business_id);
ALTER TABLE users ADD UNIQUE KEY IF NOT EXISTS uk_users_business_username (business_id, username);
ALTER TABLE users ADD UNIQUE KEY IF NOT EXISTS uk_users_business_email (business_id, email);

ALTER TABLE products ADD COLUMN IF NOT EXISTS business_id INT NULL AFTER id;
UPDATE products SET business_id = @default_business_id WHERE business_id IS NULL OR business_id = 0;
ALTER TABLE products MODIFY business_id INT NOT NULL;
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_business_id (business_id);
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_business_created (business_id, created_at);
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_business_featured_created (business_id, featured, created_at);
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_business_name (business_id, name);
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_business_category (business_id, category);

ALTER TABLE orders ADD COLUMN IF NOT EXISTS business_id INT NULL AFTER id;
UPDATE orders SET business_id = @default_business_id WHERE business_id IS NULL OR business_id = 0;
ALTER TABLE orders MODIFY business_id INT NOT NULL;
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_orders_business_id (business_id);

ALTER TABLE order_items ADD COLUMN IF NOT EXISTS business_id INT NULL AFTER order_id;
UPDATE order_items oi
JOIN orders o ON o.id = oi.order_id
SET oi.business_id = o.business_id
WHERE oi.business_id IS NULL OR oi.business_id = 0;
UPDATE order_items SET business_id = @default_business_id WHERE business_id IS NULL OR business_id = 0;
ALTER TABLE order_items MODIFY business_id INT NOT NULL;
ALTER TABLE order_items ADD INDEX IF NOT EXISTS idx_order_items_business_id (business_id);
ALTER TABLE order_items ADD INDEX IF NOT EXISTS idx_order_items_business_order (business_id, order_id);

ALTER TABLE business_settings MODIFY id INT NOT NULL AUTO_INCREMENT;
ALTER TABLE business_settings ADD COLUMN IF NOT EXISTS business_id INT NULL AFTER id;
ALTER TABLE business_settings ADD COLUMN IF NOT EXISTS theme_palette VARCHAR(30) NOT NULL DEFAULT 'default' AFTER logo_filename;
ALTER TABLE business_settings ADD COLUMN IF NOT EXISTS hero_tagline VARCHAR(320) NOT NULL DEFAULT 'Premium baby care products for your little ones. Quality you can trust.' AFTER theme_palette;
ALTER TABLE business_settings ADD COLUMN IF NOT EXISTS footer_note VARCHAR(320) NOT NULL DEFAULT 'Trusted essentials, safe choices, and a smooth shopping experience for every parent.' AFTER hero_tagline;
UPDATE business_settings SET business_id = @default_business_id WHERE business_id IS NULL OR business_id = 0;
ALTER TABLE business_settings MODIFY business_id INT NOT NULL;
ALTER TABLE business_settings ADD UNIQUE KEY IF NOT EXISTS uk_business_settings_business_id (business_id);

ALTER TABLE payment_intents ADD COLUMN IF NOT EXISTS business_id INT NULL AFTER id;
UPDATE payment_intents pi
JOIN orders o ON o.id = pi.order_id
SET pi.business_id = o.business_id
WHERE pi.business_id IS NULL OR pi.business_id = 0;
UPDATE payment_intents SET business_id = @default_business_id WHERE business_id IS NULL OR business_id = 0;
ALTER TABLE payment_intents MODIFY business_id INT NOT NULL;
ALTER TABLE payment_intents ADD INDEX IF NOT EXISTS idx_payment_intents_business_id (business_id);

ALTER TABLE payment_gateway_settings MODIFY id INT NOT NULL AUTO_INCREMENT;
ALTER TABLE payment_gateway_settings ADD COLUMN IF NOT EXISTS business_id INT NULL AFTER id;
UPDATE payment_gateway_settings SET business_id = @default_business_id WHERE business_id IS NULL OR business_id = 0;
ALTER TABLE payment_gateway_settings MODIFY business_id INT NOT NULL;
ALTER TABLE payment_gateway_settings ADD UNIQUE KEY IF NOT EXISTS uk_payment_gateway_business_id (business_id);

ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS business_id INT NULL AFTER id;
UPDATE contact_messages SET business_id = @default_business_id WHERE business_id IS NULL OR business_id = 0;
ALTER TABLE contact_messages MODIFY business_id INT NOT NULL;
ALTER TABLE contact_messages ADD INDEX IF NOT EXISTS idx_contact_messages_business_id (business_id);

INSERT INTO business_settings (business_id, business_name, business_email, contact_number, logo_filename, theme_palette, hero_tagline, footer_note)
SELECT
  b.id,
  b.business_name,
  b.business_email,
  COALESCE(NULLIF(b.contact_number, ''), '+233 000 000 000'),
  '',
  'default',
  'Premium baby care products for your little ones. Quality you can trust.',
  'Trusted essentials, safe choices, and a smooth shopping experience for every parent.'
FROM businesses b
LEFT JOIN business_settings s ON s.business_id = b.id
WHERE s.business_id IS NULL;

INSERT INTO payment_gateway_settings (business_id, gateway, enabled, use_sandbox, public_key)
SELECT
  b.id,
  'paystack',
  0,
  1,
  ''
FROM businesses b
LEFT JOIN payment_gateway_settings p ON p.business_id = b.id
WHERE p.business_id IS NULL;

COMMIT;
