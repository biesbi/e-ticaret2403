<?php

final class OrderService
{
    public static function visibleListSql(string $alias = ''): string
    {
        if (!tableHasColumn('orders', 'payment_method') || !tableHasColumn('orders', 'payment_status')) {
            return '1 = 1';
        }

        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $paymentMethod = "COALESCE({$prefix}payment_method, '')";
        $paymentStatus = "COALESCE({$prefix}payment_status, 'pending')";
        $status = "COALESCE({$prefix}status, 'pending')";
        $stockState = tableHasColumn('orders', 'stock_state')
            ? "COALESCE({$prefix}stock_state, 'none')"
            : "'none'";

        // Admin listesinde sadece yarim kalmis kart denemelerini gizle.
        // Gecmiste statusu ilerlemis veya stok islemi tamamlanmis siparisler tekrar gorunur olsun.
        return sprintf(
            "NOT (%s = 'card' AND %s <> 'paid' AND %s IN ('pending', 'failed', 'cancelled') AND %s <> 'finalized')",
            $paymentMethod,
            $paymentStatus,
            $status,
            $stockState
        );
    }

    public static function markCardPaymentFailed(string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }

        $existingOrder = self::fetchOrder($orderId);
        if (!$existingOrder) {
            return null;
        }

        if (($existingOrder['stock_state'] ?? 'none') === 'reserved') {
            StockService::releaseReservedStock($orderId);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                $pdo->commit();
                return null;
            }

            if (($order['payment_status'] ?? '') !== 'failed') {
                self::restoreCouponUsage((string) ($order['coupon_code'] ?? ''));
            }

            $fields = [
                'payment_status = ?',
                'status = ?',
                'updated_at = CURRENT_TIMESTAMP',
            ];
            $values = [
                'failed',
                StockService::resolveStatus(['failed', 'cancelled', 'pending'], 'pending'),
            ];

            if (tableHasColumn('orders', 'paytr_token')) {
                $fields[] = 'paytr_token = NULL';
            }
            if (tableHasColumn('orders', 'paytr_merchant_oid')) {
                $fields[] = 'paytr_merchant_oid = NULL';
            }
            if (tableHasColumn('orders', 'paytr_token_created_at')) {
                $fields[] = 'paytr_token_created_at = NULL';
            }
            $values[] = $orderId;

            $pdo->prepare('UPDATE orders SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

            $fetch = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
            $fetch->execute([$orderId]);
            $updatedOrder = $fetch->fetch() ?: $order;

            $pdo->commit();

            return $updatedOrder;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public static function purgeUnpaidOrder(string $orderId): bool
    {
        if ($orderId === '') {
            return false;
        }

        $existingOrder = self::fetchOrder($orderId);
        if (!$existingOrder) {
            return false;
        }

        if (($existingOrder['stock_state'] ?? 'none') === 'reserved') {
            StockService::releaseReservedStock($orderId);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                $pdo->commit();
                return false;
            }

            if (($order['payment_status'] ?? '') === 'paid') {
                throw new RuntimeException('Odenmis siparis silinemez.');
            }

            if (($order['payment_status'] ?? '') !== 'failed') {
                self::restoreCouponUsage((string) ($order['coupon_code'] ?? ''));
            }

            $delete = $pdo->prepare('DELETE FROM orders WHERE id = ?');
            $delete->execute([$orderId]);

            $pdo->commit();

            return $delete->rowCount() > 0;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public static function cleanupAbandonedCardPayments(int $hoursThreshold = 2): int
    {
        if (!tableHasColumn('orders', 'payment_method')) {
            return 0;
        }

        $stmt = db()->prepare(
            "SELECT id
             FROM orders
             WHERE payment_method = 'card'
               AND payment_status = 'pending'
               AND status = 'pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY created_at ASC
             LIMIT 50"
        );
        $stmt->execute([$hoursThreshold]);

        $released = 0;

        foreach ($stmt->fetchAll() as $order) {
            try {
                if (self::markCardPaymentFailed((string) ($order['id'] ?? '')) !== null) {
                    $released++;
                }
            } catch (Throwable) {
                // Tek sipariş hatası diğer bekleyen ödemeleri durdurmamalı.
            }
        }

        return $released;
    }

    private static function restoreCouponUsage(string $couponCode): void
    {
        $couponCode = strtoupper(trim($couponCode));
        if ($couponCode === '' || !tableExists('coupons') || !tableHasColumn('coupons', 'used_count')) {
            return;
        }

        db()->prepare(
            'UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE code = ?'
        )->execute([$couponCode]);
    }

    private static function fetchOrder(string $orderId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);

        $order = $stmt->fetch();

        return $order ?: null;
    }
}
