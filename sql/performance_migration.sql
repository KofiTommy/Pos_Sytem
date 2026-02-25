ALTER TABLE orders
  ADD INDEX idx_orders_created_id (created_at, id);

ALTER TABLE order_items
  ADD INDEX idx_order_items_product_id (product_id);

ALTER TABLE products
  ADD INDEX idx_products_stock_name (stock, name);

