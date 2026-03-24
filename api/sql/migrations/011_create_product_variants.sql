-- ================================================================
--  Migration 011: Renk Bazlı Varyant Sistemi
--  Mevcut yapıyla tam uyumlu — hiçbir tabloyu yeniden oluşturmaz
-- ================================================================

-- ────────────────────────────────────────────────────────────────
-- 1. VARIANT_TYPES — Renk, Beden, Malzeme vb.
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS variant_types (
  id         INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50)       NOT NULL,
  slug       VARCHAR(50)       NOT NULL,
  sort_order TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

INSERT IGNORE INTO variant_types (name, slug, sort_order) VALUES ('Renk', 'color', 1);

-- ────────────────────────────────────────────────────────────────
-- 2. VARIANT_VALUES — Kırmızı, Mavi, S, M, L vb.
--    meta JSON: renk için {"hex":"#FF0000"} | beden için boş
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS variant_values (
  id         INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
  type_id    INT UNSIGNED      NOT NULL,
  label      VARCHAR(100)      NOT NULL,
  meta       JSON              NULL,
  sort_order TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  UNIQUE KEY uq_type_label (type_id, label),
  INDEX idx_type (type_id),
  FOREIGN KEY (type_id) REFERENCES variant_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ────────────────────────────────────────────────────────────────
-- 3. PRODUCT_VARIANTS — Her ürün + kombinasyon için stok satırı
--    price_modifier: -50 → 50 TL indirim, +50 → ek ücret
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS product_variants (
  id             INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED   NOT NULL,
  sku            VARCHAR(100)   NULL,
  stock          INT UNSIGNED   NOT NULL DEFAULT 0,
  price_modifier DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active      TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order     SMALLINT      NOT NULL DEFAULT 0,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_sku  (product_id, sku),
  INDEX idx_product_active   (product_id, is_active),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ────────────────────────────────────────────────────────────────
-- 4. PRODUCT_VARIANT_VALUES — Varyant ↔ Değer M2M köprüsü
--    Şu an 1 renk = 1 satır, ileride (renk + beden) = 2 satır
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS product_variant_values (
  variant_id INT UNSIGNED NOT NULL,
  value_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (variant_id, value_id),
  INDEX idx_value (value_id),
  FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
  FOREIGN KEY (value_id)   REFERENCES variant_values(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ────────────────────────────────────────────────────────────────
-- 5. PRODUCTS — Varyant bayrağı
--    has_variants = 1 → stok products.stock'tan değil,
--                       SUM(product_variants.stock)'tan okunur
-- ────────────────────────────────────────────────────────────────
ALTER TABLE products
  ADD COLUMN has_variants TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

-- ────────────────────────────────────────────────────────────────
-- 6. CART_ITEMS — Varyant desteği
--    _variant_key: NULL-safe unique için generated sütun
--    COALESCE(variant_id, 0) → variant'sız ürünlerde 0 kullanır
--    Böylece (user, product, 0) çakışması yine engellenir
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
-- 7. ORDER_ITEMS — Varyant snapshot alanları
--    variant_label: sipariş anındaki değer — "Kırmızı", "Mavi / L" vb.
--    variant_id nullable → silinmiş varyant için tarihsel kayıt korunur
-- ────────────────────────────────────────────────────────────────
ALTER TABLE order_items
  ADD COLUMN variant_id    INT UNSIGNED  NULL DEFAULT NULL AFTER product_id,
  ADD COLUMN variant_label VARCHAR(200)  NULL DEFAULT NULL AFTER variant_id,
  ADD CONSTRAINT fk_order_item_variant
    FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
  ADD INDEX idx_variant_id (variant_id);
