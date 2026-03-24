-- ================================================================
--  Migration 012: Renk Bazlı Ürün Varyantları
--  NOT: 011 uygulandıysa önce ROLLBACK 011'i çalıştır
-- ================================================================

-- ────────────────────────────────────────────────────────────────
-- 1. product_variants
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS product_variants (
  id          INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
  product_id  VARCHAR(50)    NOT NULL,
  color       VARCHAR(80)    NOT NULL,
  color_code  CHAR(7)        NOT NULL,
  sku         VARCHAR(100)   NULL,
  stock       INT UNSIGNED   NOT NULL DEFAULT 0,
  price_diff  DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  is_active   TINYINT(1)     NOT NULL DEFAULT 1,
  sort_order  SMALLINT       NOT NULL DEFAULT 0,
  created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_product_color (product_id, color),
  UNIQUE KEY uq_product_sku   (product_id, sku),
  INDEX idx_product_active    (product_id, is_active),

  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ────────────────────────────────────────────────────────────────
-- 2. cart_items — variant_id
--    _variant_key: COALESCE(variant_id, 0) ile NULL-safe unique sağlar
--    variant'sız ürünler 0 olarak işlenir → eski davranış korunur
-- ────────────────────────────────────────────────────────────────
ALTER TABLE cart_items
  ADD COLUMN variant_id   INT UNSIGNED NULL DEFAULT NULL AFTER product_id,
  ADD COLUMN _variant_key INT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(variant_id, 0)) STORED AFTER variant_id;

ALTER TABLE cart_items DROP INDEX uq_user_product;
ALTER TABLE cart_items DROP INDEX uq_session_product;

ALTER TABLE cart_items
  ADD UNIQUE KEY uq_user_product_variant    (user_id,    product_id, _variant_key),
  ADD UNIQUE KEY uq_session_product_variant (session_id, product_id, _variant_key),
  ADD CONSTRAINT fk_cart_variant
    FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL;

-- ────────────────────────────────────────────────────────────────
-- 3. order_items — variant snapshot alanları
-- ────────────────────────────────────────────────────────────────
ALTER TABLE order_items
  ADD COLUMN variant_id  INT UNSIGNED NULL DEFAULT NULL AFTER product_id,
  ADD COLUMN color       VARCHAR(80)  NULL DEFAULT NULL AFTER variant_id,
  ADD COLUMN color_code  CHAR(7)      NULL DEFAULT NULL AFTER color,
  ADD CONSTRAINT fk_order_item_variant
    FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
  ADD INDEX idx_variant_id (variant_id);
