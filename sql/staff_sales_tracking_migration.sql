ALTER TABLE orders
  ADD COLUMN staff_user_id INT NULL AFTER payment_reference,
  ADD COLUMN staff_username VARCHAR(100) NULL AFTER staff_user_id,
  ADD INDEX idx_orders_staff_user_id (staff_user_id);

