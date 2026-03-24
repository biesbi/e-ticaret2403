-- Fix product_images table URLs to include medium_ prefix
UPDATE product_images 
SET url = REPLACE(url, '/uploads/products/', '/uploads/products/medium_')
WHERE url LIKE '%/uploads/products/%' 
  AND url NOT LIKE '%/uploads/products/medium_%'
  AND url NOT LIKE '%/uploads/products/original_%'
  AND url NOT LIKE '%/uploads/products/thumb_%';

-- Fix products table img column to include medium_ prefix
UPDATE products 
SET img = REPLACE(img, '/uploads/products/', '/uploads/products/medium_')
WHERE img LIKE '%/uploads/products/%' 
  AND img NOT LIKE '%/uploads/products/medium_%'
  AND img NOT LIKE '%/uploads/products/original_%'
  AND img NOT LIKE '%/uploads/products/thumb_%';
