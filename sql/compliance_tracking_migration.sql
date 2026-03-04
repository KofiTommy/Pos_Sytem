-- Phase 3: Additive compliance and operations tracking schema

CREATE TABLE IF NOT EXISTS business_audit_log (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  actor_user_id INT NOT NULL DEFAULT 0,
  actor_username VARCHAR(100) NOT NULL DEFAULT '',
  action_key VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NOT NULL DEFAULT '',
  entity_id BIGINT NOT NULL DEFAULT 0,
  details_json LONGTEXT DEFAULT NULL,
  request_ip VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_business_audit_business_time (business_id, created_at),
  INDEX idx_business_audit_action_time (action_key, created_at),
  INDEX idx_business_audit_entity_time (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_adjustments (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  product_id INT NOT NULL,
  order_id INT NOT NULL DEFAULT 0,
  adjustment_type VARCHAR(60) NOT NULL,
  quantity_delta INT NOT NULL,
  stock_before INT NOT NULL DEFAULT 0,
  stock_after INT NOT NULL DEFAULT 0,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  actor_user_id INT NOT NULL DEFAULT 0,
  actor_username VARCHAR(100) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inventory_adjustments_business_time (business_id, created_at),
  INDEX idx_inventory_adjustments_product_time (product_id, created_at),
  INDEX idx_inventory_adjustments_order_time (order_id, created_at),
  INDEX idx_inventory_adjustments_type_time (adjustment_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_closures (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  closure_date DATE NOT NULL,
  shift_label VARCHAR(60) NOT NULL DEFAULT 'daily',
  expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  counted_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  variance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'closed',
  closed_by_user_id INT NOT NULL DEFAULT 0,
  closed_by_username VARCHAR(100) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cash_closures_business_date_shift (business_id, closure_date, shift_label),
  INDEX idx_cash_closures_business_date (business_id, closure_date),
  INDEX idx_cash_closures_business_created (business_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

