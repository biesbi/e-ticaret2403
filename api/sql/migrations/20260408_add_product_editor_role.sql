ALTER TABLE users
MODIFY COLUMN role ENUM('user', 'admin', 'product_editor') NOT NULL DEFAULT 'user';
