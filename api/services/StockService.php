<?php

final class StockService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        if (tableExists('products')) {
            if (!tableHasColumn('products', 'product_condition')) {
                db()->exec("ALTER TABLE products ADD COLUMN product_condition ENUM('new','used') NOT NULL DEFAULT 'used'");

                if (tableHasColumn('products', 'condition_tag')) {
                    db()->exec(
                        "UPDATE products
                         SET product_condition = CASE
                             WHEN LOWER(TRIM(COALESCE(condition_tag, ''))) IN ('new', '0', 'zero', 'sifir', 'unused', 'sealed')
                                 THEN 'new'
                             ELSE 'used'
                         END"
                    );
                }
            }

            db()->exec(
                "UPDATE products
                 SET product_condition = CASE
                     WHEN LOWER(TRIM(COALESCE(product_condition, ''))) IN ('new', '0', 'zero', 'sifir', 'unused', 'sealed')
                         THEN 'new'
                     ELSE 'used'
                 END
                 WHERE product_condition IS NULL
                    OR LOWER(TRIM(COALESCE(product_condition, ''))) IN ('0', 'zero', 'sifir', 'unused', 'sealed', '2', '2el', '2.el', 'secondhand', 'second-hand', 'ikinciel', 'mint', 'excellent', 'good', 'fair')"
            );

            $conditionColumnMeta = db()->query("SHOW COLUMNS FROM products LIKE 'product_condition'")->fetch();
            $conditionColumnType = strtolower((string) ($conditionColumnMeta['Type'] ?? ''));
            $conditionColumnDefault = (string) ($conditionColumnMeta['Default'] ?? '');
            if ($conditionColumnType !== "enum('new','used')" || $conditionColumnDefault !== 'used') {
                db()->exec("ALTER TABLE products MODIFY COLUMN product_condition ENUM('new','used') NOT NULL DEFAULT 'used'");
            }
        }

        if (tableExists('products') && !tableHasColumn('products', 'reserved_stock')) {
            db()->exec('ALTER TABLE products ADD COLUMN reserved_stock INT NOT NULL DEFAULT 0 AFTER stock');
        }

        if (tableExists('product_variants') && !tableHasColumn('product_variants', 'reserved_stock')) {
            db()->exec('ALTER TABLE product_variants ADD COLUMN reserved_stock INT NOT NULL DEFAULT 0 AFTER stock');
        }

        if (tableExists('orders') && !tableHasColumn('orders', 'stock_state')) {
            if (tableHasColumn('orders', 'payment_status')) {
                db()->exec("ALTER TABLE orders ADD COLUMN stock_state VARCHAR(20) NOT NULL DEFAULT 'none' AFTER payment_status");
            } else {
                db()->exec("ALTER TABLE orders ADD COLUMN stock_state VARCHAR(20) NOT NULL DEFAULT 'none'");
            }
        }

        if (tableExists('order_items')) {
            if (!tableHasColumn('order_items', 'variant_id')) {
                db()->exec('ALTER TABLE order_items ADD COLUMN variant_id INT NULL AFTER product_id');
            }
            if (!tableHasColumn('order_items', 'variant_name')) {
                db()->exec('ALTER TABLE order_items ADD COLUMN variant_name VARCHAR(120) NULL AFTER variant_id');
            }
            if (!tableHasColumn('order_items', 'variant_color')) {
                db()->exec('ALTER TABLE order_items ADD COLUMN variant_color VARCHAR(120) NULL AFTER variant_name');
            }
            if (!tableHasColumn('order_items', 'sku')) {
                db()->exec('ALTER TABLE order_items ADD COLUMN sku VARCHAR(120) NULL AFTER variant_color');
            }
            if (!tableHasColumn('order_items', 'unit_price')) {
                if (tableHasColumn('order_items', 'price')) {
                    db()->exec('ALTER TABLE order_items ADD COLUMN unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER price');
                    db()->exec('UPDATE order_items SET unit_price = price WHERE unit_price = 0');
                } else {
                    if (tableHasColumn('order_items', 'product_img')) {
                        db()->exec('ALTER TABLE order_items ADD COLUMN unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER product_img');
                    } else {
                        db()->exec('ALTER TABLE order_items ADD COLUMN unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00');
                    }
                }
            }
            if (!tableHasColumn('order_items', 'line_total')) {
                db()->exec('ALTER TABLE order_items ADD COLUMN line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER quantity');
                db()->exec('UPDATE order_items SET line_total = unit_price * quantity WHERE line_total = 0');
            }
        }

        self::$schemaEnsured = true;
    }

    public static function allowedOrderStatuses(): array
    {
        $columnType = tableColumnType('orders', 'status');
        if (!is_string($columnType) || !str_starts_with($columnType, 'enum(')) {
            return ['pending', 'processing', 'preparing', 'shipped', 'delivered', 'cancelled', 'confirmed', 'paid', 'failed'];
        }

        preg_match_all("/'([^']+)'/", $columnType, $matches);
        $values = array_values(array_unique($matches[1] ?? []));
        return $values !== [] ? $values : ['pending', 'processing', 'preparing', 'shipped', 'delivered', 'cancelled'];
    }

    public static function resolveStatus(array $preferred, string $fallback): string
    {
        $allowed = self::allowedOrderStatuses();
        foreach ($preferred as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }
        return in_array($fallback, $allowed, true) ? $fallback : ($allowed[0] ?? $fallback);
    }

    public static function reserveOrderItems(array $rawItems): array
    {
        self::ensureSchema();
        $pdo = db();

        $supportsVariants = tableExists('product_variants');
        $productSql = tableHasColumn('products', 'is_active')
            ? 'SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1 FOR UPDATE'
            : 'SELECT * FROM products WHERE id = ? LIMIT 1 FOR UPDATE';

        $enriched = [];
        foreach ($rawItems as $item) {
            $productId = (string) ($item['product_id'] ?? $item['productId'] ?? $item['id'] ?? '');
            $variantId = $item['variant_id'] ?? $item['variantId'] ?? null;
            $quantity = max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));

            if ($productId === '') {
                throw new RuntimeException('Sepetteki bir urunun product_id alani eksik.');
            }

            $stmt = $pdo->prepare($productSql);
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new RuntimeException("Urun bulunamadi veya aktif degil: {$productId}");
            }

            $productStock = (int) ($product['stock'] ?? 0);
            $productReserved = (int) ($product['reserved_stock'] ?? 0);
            $productAvailable = max(0, $productStock - $productReserved);

            $line = [
                'product_id' => (string) $product['id'],
                'product_name' => (string) ($product['name'] ?? ''),
                'product_img' => (string) ($product['img'] ?? ''),
                'variant_id' => null,
                'variant_name' => null,
                'variant_color' => null,
                'sku' => (string) ($product['sku'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => (float) ($product['price'] ?? 0),
                'line_total' => 0.0,
                'desi' => (int) ($product['desi'] ?? $item['desi'] ?? 1),
                'fixed_shipping_fee' => array_key_exists('fixed_shipping_fee', $product) && $product['fixed_shipping_fee'] !== null
                    ? (float) $product['fixed_shipping_fee']
                    : null,
            ];

            if ($variantId !== null && $variantId !== '' && $supportsVariants) {
                $variantStmt = $pdo->prepare(
                    'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1 FOR UPDATE'
                );
                $variantStmt->execute([(int) $variantId, $productId]);
                $variant = $variantStmt->fetch();
                if (!$variant) {
                    throw new RuntimeException("Varyant urunle eslesmiyor. product_id={$productId}, variant_id={$variantId}");
                }
                if ((int) ($variant['is_active'] ?? 1) !== 1) {
                    throw new RuntimeException("Secilen varyant aktif degil: {$variantId}");
                }

                $variantStock = (int) ($variant['stock'] ?? 0);
                $variantReserved = (int) ($variant['reserved_stock'] ?? 0);
                $variantAvailable = max(0, $variantStock - $variantReserved);
                if ($variantAvailable < $quantity) {
                    throw new RuntimeException("'{$product['name']}' varyanti icin yeterli stok yok. Mevcut: {$variantAvailable}, istenen: {$quantity}");
                }

                $modifier = 0.0;
                if (array_key_exists('price_diff', $variant)) {
                    $modifier = (float) ($variant['price_diff'] ?? 0);
                } elseif (array_key_exists('price_modifier', $variant)) {
                    $modifier = (float) ($variant['price_modifier'] ?? 0);
                }

                $line['variant_id'] = (int) $variant['id'];
                $line['variant_name'] = (string) ($variant['color'] ?? ('Varyant #' . $variant['id']));
                $line['variant_color'] = (string) ($variant['color'] ?? '');
                $line['sku'] = (string) ($variant['sku'] ?? $line['sku']);
                $line['unit_price'] = max(0.0, ((float) ($product['price'] ?? 0)) + $modifier);

                // Stok negatife düşme koruması: reserved_stock hiçbir zaman stock'u aşamaz
                $reserveVariant = $pdo->prepare(
                    'UPDATE product_variants
                     SET reserved_stock = LEAST(reserved_stock + ?, stock)
                     WHERE id = ? AND (stock - reserved_stock) >= ?'
                );
                $reserveVariant->execute([$quantity, (int) $variant['id'], $quantity]);
                if ($reserveVariant->rowCount() === 0) {
                    throw new RuntimeException("'{$product['name']}' varyanti icin stok rezervasyonu basarisiz. Baska bir kullanici ayni anda satin aliyor olabilir.");
                }
            } else {
                if ($productAvailable < $quantity) {
                    throw new RuntimeException("'{$product['name']}' icin yeterli stok yok. Mevcut: {$productAvailable}, istenen: {$quantity}");
                }

                // Stok negatife düşme koruması: atomik kontrol
                $reserveProduct = $pdo->prepare(
                    'UPDATE products
                     SET reserved_stock = LEAST(reserved_stock + ?, stock)
                     WHERE id = ? AND (stock - reserved_stock) >= ?'
                );
                $reserveProduct->execute([$quantity, $productId, $quantity]);
                if ($reserveProduct->rowCount() === 0) {
                    throw new RuntimeException("'{$product['name']}' icin stok rezervasyonu basarisiz. Baska bir kullanici ayni anda satin aliyor olabilir.");
                }
            }

            $line['line_total'] = round($line['unit_price'] * $quantity, 2);
            $enriched[] = $line;
        }

        return $enriched;
    }

    public static function setOrderStockState(string $orderId, string $state): void
    {
        self::ensureSchema();
        if (!tableHasColumn('orders', 'stock_state')) {
            return;
        }

        db()->prepare('UPDATE orders SET stock_state = ? WHERE id = ?')->execute([$state, $orderId]);
    }

    /**
     * Terk edilen siparişlerin stok rezervasyonlarını serbest bırakır.
     * Varsayılan eşik 2 saat — PayTR ödeme oturumu max 30 dk sürer.
     */
    public static function releaseAbandonedReservations(int $hoursThreshold = 2): int
    {
        self::ensureSchema();
        $pdo = db();
        $stmt = $pdo->prepare(
            "SELECT id FROM orders
             WHERE stock_state = 'reserved'
               AND payment_status = 'pending'
               AND status = 'pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
             LIMIT 50"
        );
        $stmt->execute([$hoursThreshold]);
        $orders = $stmt->fetchAll();

        $released = 0;
        foreach ($orders as $order) {
            try {
                self::releaseReservedStock((string) $order['id']);
                $pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'failed' WHERE id = ?")
                    ->execute([$order['id']]);
                $released++;
            } catch (Throwable) {
                // Tek sipariş hatası diğerlerini engellemez
            }
        }
        return $released;
    }

    public static function finalizeReservedStock(string $orderId): void
    {
        self::transitionReservedStock($orderId, true);
    }

    public static function releaseReservedStock(string $orderId): void
    {
        self::transitionReservedStock($orderId, false);
    }

    private static function transitionReservedStock(string $orderId, bool $finalize): void
    {
        self::ensureSchema();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $order = $pdo->prepare('SELECT id, stock_state FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
            $order->execute([$orderId]);
            $row = $order->fetch();
            if (!$row) {
                throw new RuntimeException('Siparis bulunamadi.');
            }

            $state = (string) ($row['stock_state'] ?? 'none');
            if ($state !== 'reserved') {
                $pdo->commit();
                return;
            }

            $items = $pdo->prepare('SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ? FOR UPDATE');
            $items->execute([$orderId]);
            $lines = $items->fetchAll();

            foreach ($lines as $line) {
                $quantity = max(0, (int) ($line['quantity'] ?? 0));
                if ($quantity <= 0) {
                    continue;
                }

                $variantId = $line['variant_id'] ?? null;
                $productId = (string) ($line['product_id'] ?? '');

                if ($variantId !== null && tableExists('product_variants')) {
                    if ($finalize) {
                        $stmt = $pdo->prepare(
                            'UPDATE product_variants
                             SET reserved_stock = GREATEST(reserved_stock - ?, 0),
                                 stock = GREATEST(stock - ?, 0)
                             WHERE id = ?'
                        );
                        $stmt->execute([$quantity, $quantity, (int) $variantId]);
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE product_variants
                             SET reserved_stock = GREATEST(reserved_stock - ?, 0)
                             WHERE id = ?'
                        );
                        $stmt->execute([$quantity, (int) $variantId]);
                    }
                    continue;
                }

                if ($productId === '') {
                    continue;
                }

                if ($finalize) {
                    $stmt = $pdo->prepare(
                        'UPDATE products
                         SET reserved_stock = GREATEST(reserved_stock - ?, 0),
                             stock = GREATEST(stock - ?, 0)
                         WHERE id = ?'
                    );
                    $stmt->execute([$quantity, $quantity, $productId]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE products
                         SET reserved_stock = GREATEST(reserved_stock - ?, 0)
                         WHERE id = ?'
                    );
                    $stmt->execute([$quantity, $productId]);
                }
            }

            $nextState = $finalize ? 'finalized' : 'released';
            $pdo->prepare('UPDATE orders SET stock_state = ? WHERE id = ?')->execute([$nextState, $orderId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
