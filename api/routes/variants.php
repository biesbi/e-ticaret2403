<?php

StockService::ensureSchema();

if ($method === 'GET' && $id !== null) {
    $productId = (string) $id;

    $stmt = db()->prepare(
        'SELECT id, product_id, color, color_code, sku, stock, reserved_stock, price_diff, is_active, sort_order
         FROM product_variants
         WHERE product_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll();

    $rows = array_map(static function (array $row): array {
        $stock = (int) ($row['stock'] ?? 0);
        $reserved = (int) ($row['reserved_stock'] ?? 0);
        $row['reserved_stock'] = max(0, $reserved);
        $row['available_stock'] = max(0, $stock - $reserved);
        return $row;
    }, $rows);

    ok($rows);
}

elseif ($method === 'POST' && $id === null) {
    Auth::requireProductManager();

    $productId = trim((string) input('product_id', ''));
    $color = trim((string) input('color', ''));
    $colorCode = trim((string) input('color_code', '#000000'));
    $sku = trim((string) input('sku', '')) ?: null;
    $stock = max(0, (int) input('stock', 0));
    $priceDiff = (float) input('price_diff', 0);
    $isActive = input('is_active', true) ? 1 : 0;
    $sortOrder = (int) input('sort_order', 0);

    if ($productId === '') error('product_id zorunlu.');
    if ($color === '') error('color zorunlu.');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $colorCode)) error('color_code gecersiz. (#RRGGBB)');

    $pChk = db()->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $pChk->execute([$productId]);
    if (!$pChk->fetch()) error('Urun bulunamadi.', 404);

    $dupChk = db()->prepare('SELECT id FROM product_variants WHERE product_id = ? AND color = ? LIMIT 1');
    $dupChk->execute([$productId, $color]);
    if ($dupChk->fetch()) error("Bu urunde zaten '{$color}' rengi mevcut.");

    $stmt = db()->prepare(
        'INSERT INTO product_variants
         (product_id, color, color_code, sku, stock, reserved_stock, price_diff, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)'
    );
    $stmt->execute([$productId, $color, $colorCode, $sku, $stock, $priceDiff, $isActive, $sortOrder]);

    $newId = (int) db()->lastInsertId();
    $row = db()->prepare('SELECT * FROM product_variants WHERE id = ? LIMIT 1');
    $row->execute([$newId]);
    $variant = $row->fetch() ?: ['id' => $newId];
    $variant['available_stock'] = max(0, (int) ($variant['stock'] ?? 0) - (int) ($variant['reserved_stock'] ?? 0));

    ok($variant, 'Varyant eklendi.', 201);
}

elseif ($method === 'PATCH' && $id !== null) {
    Auth::requireProductManager();

    $variantId = (int) $id;
    $existing = db()->prepare('SELECT * FROM product_variants WHERE id = ? LIMIT 1');
    $existing->execute([$variantId]);
    $variant = $existing->fetch();
    if (!$variant) error('Varyant bulunamadi.', 404);

    $updates = [];
    $values = [];
    $fields = [
        'color' => fn($v) => trim((string) $v),
        'color_code' => fn($v) => trim((string) $v),
        'sku' => fn($v) => trim((string) $v) ?: null,
        'stock' => fn($v) => max(0, (int) $v),
        'price_diff' => fn($v) => (float) $v,
        'is_active' => fn($v) => $v ? 1 : 0,
        'sort_order' => fn($v) => (int) $v,
    ];

    $payload = body();
    foreach ($fields as $field => $cast) {
        if (!array_key_exists($field, $payload)) {
            continue;
        }

        if ($field === 'color_code' && !preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $payload[$field])) {
            error('color_code gecersiz. (#RRGGBB)');
        }

        if ($field === 'stock') {
            $nextStock = max(0, (int) $payload[$field]);
            $reserved = max(0, (int) ($variant['reserved_stock'] ?? 0));
            if ($nextStock < $reserved) {
                error("Stock degeri reserved_stock degerinden kucuk olamaz ({$reserved}).");
            }
        }

        $updates[] = "{$field} = ?";
        $values[] = $cast($payload[$field]);
    }

    if ($updates === []) error('Guncellenecek alan yok.');

    $values[] = $variantId;
    db()->prepare('UPDATE product_variants SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($values);

    $row = db()->prepare('SELECT * FROM product_variants WHERE id = ? LIMIT 1');
    $row->execute([$variantId]);
    $updated = $row->fetch() ?: ['id' => $variantId];
    $updated['available_stock'] = max(0, (int) ($updated['stock'] ?? 0) - (int) ($updated['reserved_stock'] ?? 0));

    ok($updated, 'Varyant guncellendi.');
}

elseif ($method === 'DELETE' && $id !== null) {
    Auth::requireProductManager();

    $variantId = (int) $id;
    $existing = db()->prepare('SELECT id, reserved_stock FROM product_variants WHERE id = ? LIMIT 1');
    $existing->execute([$variantId]);
    $variant = $existing->fetch();
    if (!$variant) error('Varyant bulunamadi.', 404);
    if ((int) ($variant['reserved_stock'] ?? 0) > 0) {
        error('Bu varyantta aktif rezervasyon varken silinemez.', 409);
    }

    db()->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$variantId]);
    ok(null, 'Varyant silindi.');
}

else {
    error('Variants endpoint bulunamadi.', 404);
}
