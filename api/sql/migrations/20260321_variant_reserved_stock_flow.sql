-- Variant aware order + reserved stock migration
-- NOTE: Runtime compatibility is also handled in StockService::ensureSchema().

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS reserved_stock INT NOT NULL DEFAULT 0 AFTER stock;

ALTER TABLE product_variants
  ADD COLUMN IF NOT EXISTS reserved_stock INT NOT NULL DEFAULT 0 AFTER stock;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS stock_state VARCHAR(20) NOT NULL DEFAULT 'none';

ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS variant_id INT NULL AFTER product_id,
  ADD COLUMN IF NOT EXISTS variant_name VARCHAR(120) NULL AFTER variant_id,
  ADD COLUMN IF NOT EXISTS variant_color VARCHAR(120) NULL AFTER variant_name,
  ADD COLUMN IF NOT EXISTS sku VARCHAR(120) NULL AFTER variant_color,
  ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00;

UPDATE order_items
SET unit_price = COALESCE(unit_price, price, 0)
WHERE unit_price = 0;

UPDATE order_items
SET line_total = unit_price * quantity
WHERE line_total = 0;
