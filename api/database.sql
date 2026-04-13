-- ═══════════════════════════════════════════════
--  BoomerItems — Veritabanı Şeması
--  HeidiSQL / MySQL 5.7+
-- ═══════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS boomeritems CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci;
USE boomeritems;

-- ─── KULLANICILAR ────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  display_name  VARCHAR(120) NOT NULL,
  email         VARCHAR(180) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','product_editor','customer') NOT NULL DEFAULT 'customer',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  email_verification_token VARCHAR(128) NULL,
  email_verification_sent_at DATETIME NULL,
  email_verification_expires_at DATETIME NULL,
  email_verified_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── KATEGORİLER ─────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  slug       VARCHAR(120) NOT NULL UNIQUE,
  parent_id  INT UNSIGNED NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── MARKALAR ────────────────────────────────────
CREATE TABLE IF NOT EXISTS brands (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  slug       VARCHAR(120) NOT NULL UNIQUE,
  logo_url   VARCHAR(500) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── ÜRÜNLER ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id   INT UNSIGNED NULL,
  brand_id      INT UNSIGNED NULL,
  name          VARCHAR(255) NOT NULL,
  slug          VARCHAR(255) NOT NULL UNIQUE,
  description   TEXT         NULL,
  price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  stock         INT          NOT NULL DEFAULT 0,
  sku           VARCHAR(100) NULL UNIQUE,
  set_no        VARCHAR(60)  NULL,
  product_condition ENUM('2. el','2. El Sıfır') NOT NULL DEFAULT '2. El Sıfır',
  images        JSON         NULL,   -- ["url1","url2",...]
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  FOREIGN KEY (brand_id)    REFERENCES brands(id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── ÜRÜN GÖRSELLERİ (normalize, JSON yerine) ───
CREATE TABLE IF NOT EXISTS product_images (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED  NOT NULL,
  filename    VARCHAR(120)  NOT NULL,         -- disk'teki UUID dosya adı
  url         VARCHAR(500)  NOT NULL,         -- public erişim URL'i
  alt_text    VARCHAR(255)  NULL,
  size_bytes  INT UNSIGNED  NOT NULL DEFAULT 0,
  width       SMALLINT UNSIGNED NULL,
  height      SMALLINT UNSIGNED NULL,
  is_primary  TINYINT(1)    NOT NULL DEFAULT 0,
  sort_order  SMALLINT      NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED  NULL,             -- admin user_id
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id)     ON DELETE SET NULL,
  INDEX idx_product  (product_id),
  INDEX idx_primary  (product_id, is_primary)
) ENGINE=InnoDB;

-- ─── KUPONLAR ────────────────────────────────────
CREATE TABLE IF NOT EXISTS coupons (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(60)   NOT NULL UNIQUE,
  type            ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value           DECIMAL(10,2) NOT NULL,
  min_order_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  usage_limit     INT UNSIGNED  NULL,           -- NULL = sınırsız
  used_count      INT UNSIGNED  NOT NULL DEFAULT 0,
  expires_at      DATETIME      NULL,
  is_active       TINYINT(1)    NOT NULL DEFAULT 1,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── KARGo ŞEHİRLERİ ─────────────────────────────
CREATE TABLE IF NOT EXISTS shipping_cities (
  id   SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  code VARCHAR(10) NULL
) ENGINE=InnoDB;

-- ─── KARGO İLÇELERİ ──────────────────────────────
CREATE TABLE IF NOT EXISTS shipping_districts (
  id      SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  city_id SMALLINT UNSIGNED NOT NULL,
  name    VARCHAR(80) NOT NULL,
  FOREIGN KEY (city_id) REFERENCES shipping_cities(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── KARGO MAHALLELERİ ───────────────────────────
CREATE TABLE IF NOT EXISTS shipping_neighborhoods (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id SMALLINT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  FOREIGN KEY (district_id) REFERENCES shipping_districts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── KARGO GRUPLARI ──────────────────────────────
CREATE TABLE IF NOT EXISTS shipping_groups (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120)  NOT NULL,
  carrier    VARCHAR(60)   NOT NULL,             -- aras, yurtici, mng, surat, dhl, ptt
  base_fee   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  free_above DECIMAL(10,2) NULL,                 -- şu tutarın üstü ücretsiz
  is_active  TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ─── SİPARİŞLER ──────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED  NULL,
  coupon_id       INT UNSIGNED  NULL,
  status          ENUM('pending','confirmed','preparing','shipped','delivered','cancelled')
                  NOT NULL DEFAULT 'pending',

  -- Alıcı bilgileri
  customer_name   VARCHAR(120) NOT NULL,
  customer_email  VARCHAR(180) NOT NULL,
  customer_phone  VARCHAR(20)  NULL,

  -- Teslimat adresi
  address_line    VARCHAR(255) NOT NULL,
  city            VARCHAR(80)  NOT NULL,
  district        VARCHAR(80)  NOT NULL,
  neighborhood    VARCHAR(120) NULL,
  postal_code     VARCHAR(10)  NULL,

  -- Kargo
  shipping_group_id INT UNSIGNED NULL,
  shipping_fee    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tracking_no     VARCHAR(100)  NULL,
  cargo_carrier   VARCHAR(60)   NULL,

  -- Tutar
  subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  notes           TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (user_id)           REFERENCES users(id)           ON DELETE SET NULL,
  FOREIGN KEY (coupon_id)         REFERENCES coupons(id)         ON DELETE SET NULL,
  FOREIGN KEY (shipping_group_id) REFERENCES shipping_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── SİPARİŞ KALEMLERİ ───────────────────────────
CREATE TABLE IF NOT EXISTS order_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     INT UNSIGNED  NOT NULL,
  product_id   INT UNSIGNED  NULL,
  product_name VARCHAR(255)  NOT NULL,   -- snapshot
  unit_price   DECIMAL(10,2) NOT NULL,
  quantity     SMALLINT      NOT NULL DEFAULT 1,
  FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── SEPET ───────────────────────────────────────
-- user_id: giriş yapmış kullanıcı
-- session_id: guest kullanıcı (frontend'den UUID gelir)
-- İkisi de NULL olamaz: ya user_id ya session_id zorunlu (uygulama katmanında kontrol)

CREATE TABLE IF NOT EXISTS cart_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED  NULL,
  session_id   CHAR(36)      NULL,       -- UUID v4 (guest)
  product_id   INT UNSIGNED  NOT NULL,
  quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  added_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Aynı kullanıcı/session + ürün kombinasyonu benzersiz olmalı
  UNIQUE KEY uq_user_product    (user_id,    product_id),
  UNIQUE KEY uq_session_product (session_id, product_id),
  INDEX idx_user    (user_id),
  INDEX idx_session (session_id),

  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── SEED: Varsayılan admin ───────────────────────
-- Şifre: boomeritemsbaran!
-- NOT: Bu hash placeholder'dır. Gerçek hash için setup.php çalıştır.
-- setup.php hem admin kullanıcısını oluşturur hem de hash'i düzeltir.
INSERT IGNORE INTO users (username, display_name, email, password_hash, role)
VALUES (
  'admin',
  'BoomerItems Admin',
  'admin@boomeritems.com',
  'SETUP_PHP_ILE_GUNCELLE',
  'admin'
);

-- ─── GÜVENLİK TABLOLARI ─────────────────────────

-- Token kara listesi (logout edilen JWT'ler)
CREATE TABLE IF NOT EXISTS token_blacklist (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash  CHAR(64)  NOT NULL UNIQUE,   -- SHA-256 hex
  expires_at  DATETIME  NOT NULL,
  created_at  DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- Rate limit takibi
CREATE TABLE IF NOT EXISTS rate_limits (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(45)  NOT NULL,
  endpoint     VARCHAR(60)  NOT NULL,
  hit_count    INT UNSIGNED NOT NULL DEFAULT 1,
  window_start INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_ip_endpoint (ip, endpoint),
  INDEX idx_window (window_start)
) ENGINE=InnoDB;

-- Audit log (kritik işlemler)
CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NULL,
  action      VARCHAR(80)   NOT NULL,
  entity_type VARCHAR(40)   NOT NULL DEFAULT '',
  entity_id   INT UNSIGNED  NULL,
  ip          VARCHAR(45)   NOT NULL,
  user_agent  VARCHAR(255)  NULL,
  meta        JSON          NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user   (user_id),
  INDEX idx_action (action),
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_date   (created_at)
) ENGINE=InnoDB;

-- Email kuyruğu (KAPALI — varsayılan olarak devre dışı)
CREATE TABLE IF NOT EXISTS email_queue (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email     VARCHAR(180) NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  body         TEXT         NOT NULL,
  status       ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  scheduled_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (status, scheduled_at)
) ENGINE=InnoDB;

-- ─── SEED: Demo kargo grupları ────────────────────
INSERT IGNORE INTO shipping_groups (name, carrier, base_fee, free_above) VALUES
  ('Aras Kargo',    'aras',    39.90, 500.00),
  ('Yurtiçi Kargo', 'yurtici', 39.90, 500.00),
  ('MNG Kargo',     'mng',     44.90, 500.00),
  ('Sürat Kargo',   'surat',   39.90, 500.00),
  ('DHL Express',   'dhl',     89.90, NULL),
  ('PTT Kargo',     'ptt',     34.90, 500.00);
