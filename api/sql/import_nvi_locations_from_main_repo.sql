SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

USE boomeritems;

DROP TABLE IF EXISTS shipping_districts;
DROP TABLE IF EXISTS shipping_cities;

CREATE TABLE IF NOT EXISTS shipping_cities (
  id SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  code VARCHAR(10) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS shipping_districts (
  id INT UNSIGNED NOT NULL,
  city_id SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_shipping_districts_city (city_id),
  CONSTRAINT fk_shipping_districts_city
    FOREIGN KEY (city_id) REFERENCES shipping_cities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

INSERT INTO shipping_cities (id, name, code)
SELECT
  CAST(c.id AS UNSIGNED) AS id,
  TRIM(c.name) AS name,
  CAST(c.plaka AS CHAR(10)) AS code
FROM iller c
ORDER BY c.id;

INSERT INTO shipping_districts (id, city_id, name)
SELECT
  CAST(d.id AS UNSIGNED) AS id,
  CAST(d.il_id AS UNSIGNED) AS city_id,
  TRIM(d.name) AS name
FROM ilceler d
INNER JOIN shipping_cities c ON c.id = d.il_id
ORDER BY d.il_id, d.name;

SET FOREIGN_KEY_CHECKS = 1;
