<?php

class CartService
{
    private static function identity(?string $userId, ?string $sessionId): array
    {
        if ($userId !== null) return ['user_id', $userId];
        if ($sessionId !== null) return ['session_id', $sessionId];
        return [null, null];
    }

    public static function get(?string $userId, ?string $sessionId): array
    {
        [$col, $val] = self::identity($userId, $sessionId);
        if ($col === null) return ['items' => [], 'summary' => self::emptySummary()];

        $hasVariant = tableHasColumn('cart_items', 'variant_id') && tableExists('product_variants');
        $hasProductReserved = tableHasColumn('products', 'reserved_stock');
        $hasVariantReserved = $hasVariant && tableHasColumn('product_variants', 'reserved_stock');
        $variantPriceCol = $hasVariant
            ? (tableHasColumn('product_variants', 'price_diff') ? 'pv.price_diff' : (tableHasColumn('product_variants', 'price_modifier') ? 'pv.price_modifier' : '0'))
            : '0';

        $select = [
            'ci.id',
            'ci.product_id',
            $hasVariant ? 'ci.variant_id' : 'NULL AS variant_id',
            'ci.quantity',
            'ci.added_at',
            'p.name',
            'p.price',
            'p.stock',
            $hasProductReserved ? 'p.reserved_stock' : '0 AS reserved_stock',
            tableHasColumn('products', 'sku') ? 'p.sku' : 'NULL AS sku',
            tableHasColumn('products', 'set_no') ? 'p.set_no' : 'NULL AS set_no',
            tableHasColumn('products', 'condition_tag') ? 'p.condition_tag' : 'NULL AS condition_tag',
            tableHasColumn('products', 'images') ? 'p.images' : 'NULL AS images',
            tableHasColumn('products', 'is_active') ? 'p.is_active' : '1 AS is_active',
            $hasVariant ? 'pv.color AS variant_color' : 'NULL AS variant_color',
            $hasVariant ? 'pv.sku AS variant_sku' : 'NULL AS variant_sku',
            $hasVariant ? 'pv.stock AS variant_stock' : 'NULL AS variant_stock',
            $hasVariantReserved ? 'pv.reserved_stock AS variant_reserved_stock' : '0 AS variant_reserved_stock',
            $hasVariant ? $variantPriceCol . ' AS variant_price_diff' : '0 AS variant_price_diff',
            $hasVariant ? 'pv.is_active AS variant_is_active' : '1 AS variant_is_active',
            'c.name AS category_name',
            'b.name AS brand_name',
        ];

        $sql = "SELECT " . implode(",\n", $select) . "
                FROM cart_items ci
                JOIN products p ON p.id = ci.product_id
                " . ($hasVariant ? 'LEFT JOIN product_variants pv ON pv.id = ci.variant_id' : '') . "
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE ci.$col = ?
                ORDER BY ci.added_at DESC";

        $stmt = db()->prepare($sql);
        $stmt->execute([$val]);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $qty = (int) ($row['quantity'] ?? 0);
            $variantSelected = $row['variant_id'] !== null;
            $variantActive = (int) ($row['variant_is_active'] ?? 1) === 1;
            $productActive = (int) ($row['is_active'] ?? 1) === 1;

            $stock = $variantSelected
                ? max(0, (int) ($row['variant_stock'] ?? 0) - (int) ($row['variant_reserved_stock'] ?? 0))
                : max(0, (int) ($row['stock'] ?? 0) - (int) ($row['reserved_stock'] ?? 0));
            $effectiveQty = min($qty, $stock);
            $unitPrice = max(0, (float) ($row['price'] ?? 0) + (float) ($row['variant_price_diff'] ?? 0));

            $items[] = [
                'id' => (int) $row['id'],
                'product_id' => (string) $row['product_id'],
                'variant_id' => $variantSelected ? (int) $row['variant_id'] : null,
                'variant_color' => $row['variant_color'] ?? null,
                'name' => $row['name'],
                'price' => $unitPrice,
                'quantity' => $qty,
                'effective_qty' => $effectiveQty,
                'subtotal' => round($unitPrice * $effectiveQty, 2),
                'stock' => $stock,
                'sku' => $row['variant_sku'] ?? $row['sku'],
                'set_no' => $row['set_no'],
                'condition_tag' => $row['condition_tag'],
                'images' => json_decode($row['images'] ?? '[]', true),
                'category_name' => $row['category_name'],
                'brand_name' => $row['brand_name'],
                'is_available' => $productActive && $variantActive && $stock > 0,
                'added_at' => $row['added_at'],
            ];
        }

        return ['items' => $items, 'summary' => self::summarize($items)];
    }

    public static function add(?string $userId, ?string $sessionId, string $productId, int $qty = 1, ?int $variantId = null): array
    {
        [$col, $val] = self::identity($userId, $sessionId);
        if ($col === null) throw new RuntimeException('Kimlik gerekli.');
        if ($qty < 1) throw new RuntimeException('Miktar en az 1 olmali.');

        $supportsVariants = tableHasColumn('cart_items', 'variant_id') && tableExists('product_variants');
        $productStmt = db()->prepare(
            'SELECT id, name, price, stock, ' . (tableHasColumn('products', 'reserved_stock') ? 'reserved_stock' : '0 AS reserved_stock') . ', ' . (tableHasColumn('products', 'is_active') ? 'is_active' : '1 AS is_active') . '
             FROM products WHERE id = ? LIMIT 1'
        );
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch();
        if (!$product) throw new RuntimeException('Urun bulunamadi.');
        if (tableHasColumn('products', 'is_active') && (int) ($product['is_active'] ?? 1) !== 1) {
            throw new RuntimeException('Bu urun artik satista degil.');
        }

        $variant = null;
        if ($variantId !== null) {
            if (!$supportsVariants) throw new RuntimeException('Varyant desteklenmiyor.');
            $variantStmt = db()->prepare(
                'SELECT id, product_id, stock, ' . (tableHasColumn('product_variants', 'reserved_stock') ? 'reserved_stock' : '0 AS reserved_stock') . ', is_active
                 FROM product_variants
                 WHERE id = ? AND product_id = ? LIMIT 1'
            );
            $variantStmt->execute([$variantId, $productId]);
            $variant = $variantStmt->fetch();
            if (!$variant) throw new RuntimeException('Varyant urune ait degil.');
            if ((int) ($variant['is_active'] ?? 1) !== 1) throw new RuntimeException('Secilen varyant aktif degil.');
        }

        if ($supportsVariants) {
            $existingStmt = db()->prepare(
                "SELECT id, quantity
                 FROM cart_items
                 WHERE $col = ? AND product_id = ? AND ((variant_id IS NULL AND ? IS NULL) OR variant_id = ?)
                 LIMIT 1"
            );
            $existingStmt->execute([$val, $productId, $variantId, $variantId]);
        } else {
            $existingStmt = db()->prepare("SELECT id, quantity FROM cart_items WHERE $col = ? AND product_id = ? LIMIT 1");
            $existingStmt->execute([$val, $productId]);
        }
        $existing = $existingStmt->fetch();

        $available = $variant
            ? max(0, (int) ($variant['stock'] ?? 0) - (int) ($variant['reserved_stock'] ?? 0))
            : max(0, (int) ($product['stock'] ?? 0) - (int) ($product['reserved_stock'] ?? 0));
        if ($available < 1) throw new RuntimeException('Stokta urun yok.');

        $newQty = $existing ? ((int) $existing['quantity'] + $qty) : $qty;
        $newQty = min($newQty, $available);
        if ($newQty < 1) throw new RuntimeException('Stok yetersiz.');

        if ($existing) {
            db()->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?')->execute([$newQty, $existing['id']]);
        } else {
            if ($supportsVariants) {
                db()->prepare("INSERT INTO cart_items ($col, product_id, variant_id, quantity) VALUES (?,?,?,?)")
                    ->execute([$val, $productId, $variantId, $newQty]);
            } else {
                db()->prepare("INSERT INTO cart_items ($col, product_id, quantity) VALUES (?,?,?)")
                    ->execute([$val, $productId, $newQty]);
            }
        }

        return ['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => $newQty];
    }

    public static function update(?string $userId, ?string $sessionId, int $itemId, int $qty): bool
    {
        [$col, $val] = self::identity($userId, $sessionId);
        if ($col === null) return false;
        if ($qty <= 0) return self::remove($userId, $sessionId, $itemId);

        $hasVariant = tableHasColumn('cart_items', 'variant_id') && tableExists('product_variants');
        $sql = "SELECT ci.id,
                       p.stock,
                       " . (tableHasColumn('products', 'reserved_stock') ? 'p.reserved_stock' : '0') . " AS product_reserved_stock,
                       " . ($hasVariant ? 'pv.stock AS variant_stock, ' . (tableHasColumn('product_variants', 'reserved_stock') ? 'pv.reserved_stock' : '0') . ' AS variant_reserved_stock' : 'NULL AS variant_stock, 0 AS variant_reserved_stock') . "
                FROM cart_items ci
                JOIN products p ON p.id = ci.product_id
                " . ($hasVariant ? 'LEFT JOIN product_variants pv ON pv.id = ci.variant_id' : '') . "
                WHERE ci.id = ? AND ci.$col = ? LIMIT 1";

        $chk = db()->prepare($sql);
        $chk->execute([$itemId, $val]);
        $row = $chk->fetch();
        if (!$row) return false;

        $available = $row['variant_stock'] !== null
            ? max(0, (int) ($row['variant_stock'] ?? 0) - (int) ($row['variant_reserved_stock'] ?? 0))
            : max(0, (int) ($row['stock'] ?? 0) - (int) ($row['product_reserved_stock'] ?? 0));
        $finalQty = min($qty, $available);
        if ($finalQty < 1) return self::remove($userId, $sessionId, $itemId);

        db()->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?')->execute([$finalQty, $itemId]);
        return true;
    }

    public static function remove(?string $userId, ?string $sessionId, int $itemId): bool
    {
        [$col, $val] = self::identity($userId, $sessionId);
        if ($col === null) return false;
        $stmt = db()->prepare("DELETE FROM cart_items WHERE id = ? AND $col = ?");
        $stmt->execute([$itemId, $val]);
        return $stmt->rowCount() > 0;
    }

    public static function clear(?string $userId, ?string $sessionId): void
    {
        [$col, $val] = self::identity($userId, $sessionId);
        if ($col === null) return;
        db()->prepare("DELETE FROM cart_items WHERE $col = ?")->execute([$val]);
    }

    public static function merge(string $userId, string $sessionId): array
    {
        $hasVariant = tableHasColumn('cart_items', 'variant_id');
        $stmt = db()->prepare('SELECT product_id, quantity' . ($hasVariant ? ', variant_id' : '') . ' FROM cart_items WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $guestItems = $stmt->fetchAll();
        if (empty($guestItems)) return ['merged' => 0];

        $merged = 0;
        foreach ($guestItems as $item) {
            try {
                self::add(
                    $userId,
                    null,
                    (string) $item['product_id'],
                    (int) $item['quantity'],
                    isset($item['variant_id']) ? (int) $item['variant_id'] : null
                );
                $merged++;
            } catch (RuntimeException) {
            }
        }

        db()->prepare('DELETE FROM cart_items WHERE session_id = ?')->execute([$sessionId]);
        return ['merged' => $merged];
    }

    public static function validate(?string $userId, ?string $sessionId): array
    {
        $cart = self::get($userId, $sessionId);
        $issues = [];
        foreach ($cart['items'] as $item) {
            if (!$item['is_available']) {
                $issues[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'name' => $item['name'],
                    'reason' => 'Urun satisa uygun degil.',
                ];
            } elseif ($item['quantity'] > $item['stock']) {
                $issues[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'name' => $item['name'],
                    'reason' => "Yetersiz stok. Istenen: {$item['quantity']}, Mevcut: {$item['stock']}",
                ];
            }
        }

        return ['valid' => empty($issues), 'issues' => $issues, 'cart' => $cart];
    }

    private static function summarize(array $items): array
    {
        $subtotal = 0.0;
        $itemCount = 0;
        foreach ($items as $item) {
            if (!$item['is_available']) continue;
            $subtotal += (float) ($item['subtotal'] ?? 0);
            $itemCount += (int) ($item['effective_qty'] ?? 0);
        }
        return [
            'item_count' => $itemCount,
            'subtotal' => round($subtotal, 2),
            'total_products' => count($items),
        ];
    }

    private static function emptySummary(): array
    {
        return ['item_count' => 0, 'subtotal' => 0.0, 'total_products' => 0];
    }

    public static function validateSessionId(string $id): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id
        );
    }
}
