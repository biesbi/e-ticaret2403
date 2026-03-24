<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$baseDir = dirname(__DIR__);
require $baseDir . '/config.php';
require $baseDir . '/helpers.php';

$source = $argv[1] ?? null;
if (!$source) {
    fwrite(STDERR, "Usage: php api/scripts/import_shipping_locations.php <json-file>\n");
    exit(1);
}

if (!is_file($source)) {
    fwrite(STDERR, "JSON file not found: {$source}\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($source), true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid JSON payload.\n");
    exit(1);
}

$cities = $payload['cities'] ?? $payload;
if (!is_array($cities)) {
    fwrite(STDERR, "Expected an array or a top-level 'cities' key.\n");
    exit(1);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shipping_cities (
        id SMALLINT UNSIGNED NOT NULL,
        name VARCHAR(80) NOT NULL,
        code VARCHAR(10) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shipping_cities_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shipping_districts (
        id INT UNSIGNED NOT NULL,
        city_id SMALLINT UNSIGNED NOT NULL,
        name VARCHAR(80) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_shipping_districts_city (city_id),
        UNIQUE KEY uq_shipping_districts_city_name (city_id, name),
        CONSTRAINT fk_shipping_districts_city
            FOREIGN KEY (city_id) REFERENCES shipping_cities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shipping_neighborhoods (
        id INT UNSIGNED NOT NULL,
        district_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_shipping_neighborhoods_district (district_id),
        UNIQUE KEY uq_shipping_neighborhoods_district_name (district_id, name),
        CONSTRAINT fk_shipping_neighborhoods_district
            FOREIGN KEY (district_id) REFERENCES shipping_districts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci'
);

$insertCity = $pdo->prepare(
    'INSERT INTO shipping_cities (id, name, code)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code)'
);

$insertDistrict = $pdo->prepare(
    'INSERT INTO shipping_districts (id, city_id, name)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE city_id = VALUES(city_id), name = VALUES(name)'
);

$insertNeighborhood = $pdo->prepare(
    'INSERT INTO shipping_neighborhoods (id, district_id, name)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE district_id = VALUES(district_id), name = VALUES(name)'
);

$cityCount = 0;
$districtCount = 0;
$neighborhoodCount = 0;

$pdo->beginTransaction();

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE shipping_neighborhoods');
    $pdo->exec('TRUNCATE TABLE shipping_districts');
    $pdo->exec('TRUNCATE TABLE shipping_cities');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    foreach ($cities as $city) {
        if (!is_array($city)) {
            continue;
        }

        $cityId = (int) ($city['id'] ?? 0);
        $cityName = trim((string) ($city['name'] ?? ''));
        $cityCode = isset($city['code']) ? trim((string) $city['code']) : null;

        if ($cityId <= 0 || $cityName === '') {
            throw new RuntimeException('Each city must have a numeric id and a name.');
        }

        $insertCity->execute([$cityId, $cityName, $cityCode !== '' ? $cityCode : null]);
        $cityCount++;

        foreach (($city['districts'] ?? []) as $district) {
            if (!is_array($district)) {
                continue;
            }

            $districtId = (int) ($district['id'] ?? 0);
            $districtName = trim((string) ($district['name'] ?? ''));

            if ($districtId <= 0 || $districtName === '') {
                throw new RuntimeException("District in {$cityName} is missing id or name.");
            }

            $insertDistrict->execute([$districtId, $cityId, $districtName]);
            $districtCount++;

            foreach (($district['neighborhoods'] ?? []) as $neighborhood) {
                if (!is_array($neighborhood)) {
                    continue;
                }

                $neighborhoodId = (int) ($neighborhood['id'] ?? 0);
                $neighborhoodName = trim((string) ($neighborhood['name'] ?? ''));

                if ($neighborhoodId <= 0 || $neighborhoodName === '') {
                    throw new RuntimeException("Neighborhood in {$cityName}/{$districtName} is missing id or name.");
                }

                $insertNeighborhood->execute([$neighborhoodId, $districtId, $neighborhoodName]);
                $neighborhoodCount++;
            }
        }
    }

    $pdo->commit();
    fwrite(
        STDOUT,
        "Imported {$cityCount} cities, {$districtCount} districts, {$neighborhoodCount} neighborhoods.\n"
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
