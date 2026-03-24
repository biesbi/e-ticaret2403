SET NAMES utf8mb4;
USE boomeritems;

INSERT INTO categories (id, name, slug) VALUES
  ('cat-star-wars', 'Star Wars', 'star-wars'),
  ('cat-icons', 'Icons', 'icons'),
  ('cat-ninjago', 'NINJAGO', 'ninjago'),
  ('cat-wicked', 'Wicked', 'wicked')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  slug = VALUES(slug);

INSERT INTO brands (id, name, slug, logo_url) VALUES
  ('brand-lego', 'LEGO', 'lego', 'https://upload.wikimedia.org/wikipedia/commons/2/24/LEGO_logo.svg')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  slug = VALUES(slug),
  logo_url = VALUES(logo_url);

INSERT INTO products (
  id,
  name,
  description,
  price,
  old_price,
  category_id,
  brand_id,
  images,
  img,
  dimensions,
  desi,
  stock,
  pieces,
  is_active
) VALUES
  (
    'prd-barc-75378',
    'LEGO Star Wars: The Mandalorian BARC Motoru Kaçışı 75378 (221 Parça)',
    'Mandalorian temalı hızlı kurulum seti. Sergi ve oyun için dengeli bir Star Wars ürünü.',
    1100.00,
    1220.00,
    'cat-star-wars',
    'brand-lego',
    JSON_ARRAY('https://images.unsplash.com/photo-1587654780291-39c9404d746b?auto=format&fit=crop&w=900&q=80'),
    'https://images.unsplash.com/photo-1587654780291-39c9404d746b?auto=format&fit=crop&w=900&q=80',
    JSON_OBJECT('width_cm', 28, 'height_cm', 19, 'depth_cm', 8),
    2,
    18,
    221,
    1
  ),
  (
    'prd-snub-75346',
    'LEGO Star Wars Korsan Snub Fighter 75346 (285 Parça)',
    'Star Wars koleksiyonuna güçlü bir başlangıç seti. Çocuklar için hızlı kurulum ve sağlam oyun deneyimi sunar.',
    1750.00,
    1909.00,
    'cat-star-wars',
    'brand-lego',
    JSON_ARRAY('https://images.unsplash.com/photo-1608889175123-8ee362201f81?auto=format&fit=crop&w=900&q=80'),
    'https://images.unsplash.com/photo-1608889175123-8ee362201f81?auto=format&fit=crop&w=900&q=80',
    JSON_OBJECT('width_cm', 31, 'height_cm', 22, 'depth_cm', 8),
    2,
    14,
    285,
    1
  ),
  (
    'prd-yoda-75360',
    'LEGO Star Wars Yoda''nın Jedi Starfighter''ı 75360 (253 Parça)',
    'Yoda minifigürü ve detaylı gemi gövdesi ile çocuklara yönelik yaratıcı bir oyun seti.',
    5200.00,
    6999.00,
    'cat-star-wars',
    'brand-lego',
    JSON_ARRAY('https://images.unsplash.com/photo-1618336753974-aae8e04506aa?auto=format&fit=crop&w=900&q=80'),
    'https://images.unsplash.com/photo-1618336753974-aae8e04506aa?auto=format&fit=crop&w=900&q=80',
    JSON_OBJECT('width_cm', 35, 'height_cm', 24, 'depth_cm', 9),
    3,
    9,
    253,
    1
  ),
  (
    'prd-ninjago-71841',
    'LEGO NINJAGO Ejderinsan Fırtına Köyü 71841 (3052 Parça)',
    'Büyük koleksiyon seti. Geniş sergileme alanı ve zengin minifigür içeriğiyle öne çıkar.',
    6000.00,
    6450.00,
    'cat-ninjago',
    'brand-lego',
    JSON_ARRAY('https://images.unsplash.com/photo-1518331647614-7a1f04cd34cf?auto=format&fit=crop&w=900&q=80'),
    'https://images.unsplash.com/photo-1518331647614-7a1f04cd34cf?auto=format&fit=crop&w=900&q=80',
    JSON_OBJECT('width_cm', 48, 'height_cm', 37, 'depth_cm', 14),
    6,
    5,
    3052,
    1
  ),
  (
    'prd-wicked-75685',
    'LEGO Wicked Emerald City Duvar Tablosu 75685 (1518 Parça)',
    'Dekoratif sergileme için hazırlanmış büyük parça sayılı duvar tablosu seti.',
    5600.00,
    6000.00,
    'cat-wicked',
    'brand-lego',
    JSON_ARRAY('https://images.unsplash.com/photo-1526506118085-60ce8714f8c5?auto=format&fit=crop&w=900&q=80'),
    'https://images.unsplash.com/photo-1526506118085-60ce8714f8c5?auto=format&fit=crop&w=900&q=80',
    JSON_OBJECT('width_cm', 52, 'height_cm', 40, 'depth_cm', 12),
    5,
    7,
    1518,
    1
  ),
  (
    'prd-icons-typewriter',
    'LEGO Icons Daktilo 21327 (2079 Parça)',
    'Masa üstü dekorasyonu için ikonik LEGO Ideas seti. Koleksiyon odaklı yetişkin kullanıcılar için uygundur.',
    4890.00,
    5390.00,
    'cat-icons',
    'brand-lego',
    JSON_ARRAY('https://images.unsplash.com/photo-1516979187457-637abb4f9353?auto=format&fit=crop&w=900&q=80'),
    'https://images.unsplash.com/photo-1516979187457-637abb4f9353?auto=format&fit=crop&w=900&q=80',
    JSON_OBJECT('width_cm', 39, 'height_cm', 29, 'depth_cm', 14),
    4,
    8,
    2079,
    1
  ),
  (
    'prd-starwars-tie',
    'LEGO Star Wars TIE Bomber 75347 (625 Parça)',
    'Star Wars uzay aracı koleksiyonuna uygun orta ölçekli set. Hem oynanış hem sergileme için ideal.',
    3290.00,
    3590.00,
    'cat-star-wars',
    'brand-lego',
    JSON_ARRAY('https://images.unsplash.com/photo-1566576912321-d58ddd7a6088?auto=format&fit=crop&w=900&q=80'),
    'https://images.unsplash.com/photo-1566576912321-d58ddd7a6088?auto=format&fit=crop&w=900&q=80',
    JSON_OBJECT('width_cm', 34, 'height_cm', 24, 'depth_cm', 10),
    3,
    11,
    625,
    1
  ),
  (
    'prd-starwars-xwing',
    'LEGO Star Wars X-Wing Starfighter 75355 (1949 Parça)',
    'Detay seviyesi yüksek Ultimate Collector Series seti. Büyük ölçekli sergileme ürünüdür.',
    8990.00,
    9490.00,
    'cat-star-wars',
    'brand-lego',
    JSON_ARRAY('https://images.unsplash.com/photo-1576179635662-9d1983e97e1b?auto=format&fit=crop&w=900&q=80'),
    'https://images.unsplash.com/photo-1576179635662-9d1983e97e1b?auto=format&fit=crop&w=900&q=80',
    JSON_OBJECT('width_cm', 55, 'height_cm', 42, 'depth_cm', 15),
    7,
    4,
    1949,
    1
  )
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  price = VALUES(price),
  old_price = VALUES(old_price),
  category_id = VALUES(category_id),
  brand_id = VALUES(brand_id),
  images = VALUES(images),
  img = VALUES(img),
  dimensions = VALUES(dimensions),
  desi = VALUES(desi),
  stock = VALUES(stock),
  pieces = VALUES(pieces),
  is_active = VALUES(is_active);

DELETE FROM product_images
WHERE product_id IN (
  'prd-barc-75378',
  'prd-snub-75346',
  'prd-yoda-75360',
  'prd-ninjago-71841',
  'prd-wicked-75685',
  'prd-icons-typewriter',
  'prd-starwars-tie',
  'prd-starwars-xwing'
);

INSERT INTO product_images (product_id, url, alt_text, is_primary, sort_order) VALUES
  ('prd-barc-75378', 'https://images.unsplash.com/photo-1587654780291-39c9404d746b?auto=format&fit=crop&w=900&q=80', 'BARC Motoru Kaçışı', 1, 1),
  ('prd-snub-75346', 'https://images.unsplash.com/photo-1608889175123-8ee362201f81?auto=format&fit=crop&w=900&q=80', 'Korsan Snub Fighter', 1, 1),
  ('prd-yoda-75360', 'https://images.unsplash.com/photo-1618336753974-aae8e04506aa?auto=format&fit=crop&w=900&q=80', 'Yoda Jedi Starfighter', 1, 1),
  ('prd-ninjago-71841', 'https://images.unsplash.com/photo-1518331647614-7a1f04cd34cf?auto=format&fit=crop&w=900&q=80', 'NINJAGO Ejderinsan Fırtına Köyü', 1, 1),
  ('prd-wicked-75685', 'https://images.unsplash.com/photo-1526506118085-60ce8714f8c5?auto=format&fit=crop&w=900&q=80', 'Wicked Emerald City Duvar Tablosu', 1, 1),
  ('prd-icons-typewriter', 'https://images.unsplash.com/photo-1516979187457-637abb4f9353?auto=format&fit=crop&w=900&q=80', 'LEGO Icons Daktilo', 1, 1),
  ('prd-starwars-tie', 'https://images.unsplash.com/photo-1566576912321-d58ddd7a6088?auto=format&fit=crop&w=900&q=80', 'TIE Bomber', 1, 1),
  ('prd-starwars-xwing', 'https://images.unsplash.com/photo-1576179635662-9d1983e97e1b?auto=format&fit=crop&w=900&q=80', 'X-Wing Starfighter', 1, 1);

INSERT INTO coupons (code, discount_type, value, min_order_amount, usage_limit, is_active) VALUES
  ('WELCOME10', 'percentage', 10.00, 500.00, NULL, 1),
  ('SEPET100', 'fixed', 100.00, 1500.00, NULL, 1)
ON DUPLICATE KEY UPDATE
  discount_type = VALUES(discount_type),
  value = VALUES(value),
  min_order_amount = VALUES(min_order_amount),
  usage_limit = VALUES(usage_limit),
  is_active = VALUES(is_active);
