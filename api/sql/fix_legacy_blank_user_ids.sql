UPDATE users
SET id = LOWER(REPLACE(UUID(), '-', ''))
WHERE id IS NULL OR TRIM(id) = '';
