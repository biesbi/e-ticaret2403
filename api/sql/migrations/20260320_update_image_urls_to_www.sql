-- Migration: Update all image URLs from boomeritems.com to www.boomeritems.com
-- Date: 2026-03-20
-- Purpose: Fix CORS issues by ensuring all URLs use the same subdomain

-- Update products.img field
UPDATE products
SET img = REPLACE(img, 'https://boomeritems.com/', 'https://www.boomeritems.com/')
WHERE img LIKE 'https://boomeritems.com/%';

-- Update products.images JSON field
UPDATE products
SET images = REPLACE(images, 'https://boomeritems.com/', 'https://www.boomeritems.com/')
WHERE images LIKE '%https://boomeritems.com/%';

-- Update product_images.url field
UPDATE product_images
SET url = REPLACE(url, 'https://boomeritems.com/', 'https://www.boomeritems.com/')
WHERE url LIKE 'https://boomeritems.com/%';

-- Show updated counts
SELECT
    (SELECT COUNT(*) FROM products WHERE img LIKE 'https://www.boomeritems.com/%') as products_img_updated,
    (SELECT COUNT(*) FROM products WHERE images LIKE '%https://www.boomeritems.com/%') as products_images_updated,
    (SELECT COUNT(*) FROM product_images WHERE url LIKE 'https://www.boomeritems.com/%') as product_images_updated;
