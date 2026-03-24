<?php
// ═══════════════════════════════════════════════
//  AdminService
//  Admin panel için tüm istatistik ve özet verileri
// ═══════════════════════════════════════════════

class AdminService
{
    private static function customerRole(): string
    {
        $type = tableColumnType('users', 'role') ?? '';
        return str_contains($type, "'customer'") ? 'customer' : 'user';
    }

    private static function userNameSql(string $alias = 'u'): string
    {
        if (tableHasColumn('users', 'display_name')) {
            return $alias . '.display_name';
        }
        if (tableHasColumn('users', 'name')) {
            return $alias . '.name';
        }
        return "''";
    }

    // ─── Ana Dashboard Özeti ──────────────────

    public static function getDashboard(): array
    {
        $pdo = db();
        $now = date('Y-m-d H:i:s');
        $todayStart  = date('Y-m-d') . ' 00:00:00';
        $weekStart   = date('Y-m-d', strtotime('-7 days')) . ' 00:00:00';
        $monthStart  = date('Y-m-01') . ' 00:00:00';
        $prevMonthS  = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
        $prevMonthE  = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';

        return [
            'orders'    => self::orderStats($pdo, $todayStart, $weekStart, $monthStart, $prevMonthS, $prevMonthE),
            'revenue'   => self::revenueStats($pdo, $todayStart, $weekStart, $monthStart, $prevMonthS, $prevMonthE),
            'products'  => self::productStats($pdo),
            'users'     => self::userStats($pdo, $monthStart),
            'stock'     => self::stockAlerts($pdo),
            'generated_at' => $now,
        ];
    }

    // ─── Sipariş İstatistikleri ───────────────

    private static function orderStats(
        PDO $pdo,
        string $todayStart,
        string $weekStart,
        string $monthStart,
        string $prevMonthS,
        string $prevMonthE
    ): array {
        // Toplam sipariş sayıları (duruma göre)
        $byStatus = $pdo->query(
            "SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status"
        )->fetchAll();

        $statusMap = [];
        foreach ($byStatus as $row) {
            $statusMap[$row['status']] = (int) $row['cnt'];
        }

        // Bugün
        $todayCnt = (int) $pdo->prepare(
            "SELECT COUNT(*) FROM orders WHERE created_at >= ?"
        )->execute([$todayStart]) ?: 0;
        $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at >= ?");
        $st->execute([$todayStart]);
        $todayCnt = (int) $st->fetchColumn();

        // Bu hafta
        $st->execute([$weekStart]);
        $weekCnt = (int) $st->fetchColumn();

        // Bu ay
        $st->execute([$monthStart]);
        $monthCnt = (int) $st->fetchColumn();

        // Geçen ay (büyüme hesabı için)
        $stPrev = $pdo->prepare(
            "SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ?"
        );
        $stPrev->execute([$prevMonthS, $prevMonthE]);
        $prevMonthCnt = (int) $stPrev->fetchColumn();

        $growth = $prevMonthCnt > 0
            ? round((($monthCnt - $prevMonthCnt) / $prevMonthCnt) * 100, 1)
            : null;

        return [
            'total'       => array_sum($statusMap),
            'by_status'   => $statusMap,
            'today'       => $todayCnt,
            'this_week'   => $weekCnt,
            'this_month'  => $monthCnt,
            'prev_month'  => $prevMonthCnt,
            'month_growth_pct' => $growth,
        ];
    }

    // ─── Gelir İstatistikleri ─────────────────

    private static function revenueStats(
        PDO $pdo,
        string $todayStart,
        string $weekStart,
        string $monthStart,
        string $prevMonthS,
        string $prevMonthE
    ): array {
        $base = "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status != 'cancelled'";

        $stDate = $pdo->prepare("$base AND created_at >= ?");

        $stDate->execute([$todayStart]);
        $today = (float) $stDate->fetchColumn();

        $stDate->execute([$weekStart]);
        $week = (float) $stDate->fetchColumn();

        $stDate->execute([$monthStart]);
        $month = (float) $stDate->fetchColumn();

        $stPrev = $pdo->prepare("$base AND created_at BETWEEN ? AND ?");
        $stPrev->execute([$prevMonthS, $prevMonthE]);
        $prevMonth = (float) $stPrev->fetchColumn();

        $growth = $prevMonth > 0
            ? round((($month - $prevMonth) / $prevMonth) * 100, 1)
            : null;

        // Toplam gelir (tüm zamanlar)
        $total = (float) $pdo->query(
            "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status != 'cancelled'"
        )->fetchColumn();

        // Günlük gelir trendi (son 30 gün)
        $trend = $pdo->prepare(
            "SELECT DATE(created_at) AS day, COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS orders
             FROM orders
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status != 'cancelled'
             GROUP BY DATE(created_at)
             ORDER BY day ASC"
        );
        $trend->execute();

        return [
            'total'            => round($total, 2),
            'today'            => round($today, 2),
            'this_week'        => round($week, 2),
            'this_month'       => round($month, 2),
            'prev_month'       => round($prevMonth, 2),
            'month_growth_pct' => $growth,
            'daily_trend'      => $trend->fetchAll(),
        ];
    }

    // ─── Ürün İstatistikleri ──────────────────

    private static function productStats(PDO $pdo): array
    {
        $total   = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $active  = tableHasColumn('products', 'is_active')
            ? (int) $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn()
            : $total;
        $passive = $total - $active;

        // Stok durumu
        $activeWhere = tableHasColumn('products', 'is_active') ? ' AND is_active = 1' : '';
        $outOfStock = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE stock = 0" . $activeWhere)->fetchColumn();
        $lowStock   = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE stock BETWEEN 1 AND 5" . $activeWhere)->fetchColumn();

        // Kategori dağılımı
        $byCat = $pdo->query(
            "SELECT c.name, COUNT(p.id) AS cnt
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             GROUP BY p.category_id, c.name
             ORDER BY cnt DESC
             LIMIT 10"
        )->fetchAll();

        // En çok satan 5 ürün (order_items'dan)
        $topSelling = $pdo->query(
            "SELECT p.id, p.name, p.price, SUM(oi.quantity) AS sold
             FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             JOIN orders o ON o.id = oi.order_id
             WHERE o.status != 'cancelled'
             GROUP BY oi.product_id
             ORDER BY sold DESC
             LIMIT 5"
        )->fetchAll();

        return [
            'total'        => $total,
            'active'       => $active,
            'passive'      => $passive,
            'out_of_stock' => $outOfStock,
            'low_stock'    => $lowStock,
            'by_category'  => $byCat,
            'top_selling'  => $topSelling,
        ];
    }

    // ─── Kullanıcı İstatistikleri ─────────────

    private static function userStats(PDO $pdo, string $monthStart): array
    {
        $customerRole = self::customerRole();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = " . $pdo->quote($customerRole))->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = ? AND created_at >= ?");
        $st->execute([$customerRole, $monthStart]);
        $newMonth = (int) $st->fetchColumn();

        // En aktif müşteriler (sipariş sayısına göre)
        $topCustomers = $pdo->query(
            "SELECT u.id, " . self::userNameSql('u') . " AS display_name, u.email,
                    COUNT(o.id) AS order_count,
                    COALESCE(SUM(o.total), 0) AS total_spent
             FROM users u
             JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
             WHERE u.role = " . $pdo->quote($customerRole) . "
             GROUP BY u.id
             ORDER BY total_spent DESC
             LIMIT 5"
        )->fetchAll();

        return [
            'total'         => $total,
            'new_this_month'=> $newMonth,
            'top_customers' => $topCustomers,
        ];
    }

    // ─── Stok Uyarıları ───────────────────────

    public static function stockAlerts(PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        $activeWhere = tableHasColumn('products', 'is_active') ? ' AND p.is_active = 1' : '';
        $skuSelect = tableHasColumn('products', 'sku') ? 'p.sku' : 'NULL AS sku';

        $outOfStock = $pdo->query(
            "SELECT p.id, p.name, $skuSelect, p.stock, c.name AS category
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.stock = 0" . $activeWhere . "
             ORDER BY p.name"
        )->fetchAll();

        $lowStock = $pdo->query(
            "SELECT p.id, p.name, $skuSelect, p.stock, c.name AS category
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.stock BETWEEN 1 AND 5" . $activeWhere . "
             ORDER BY p.stock ASC, p.name"
        )->fetchAll();

        return [
            'out_of_stock' => $outOfStock,
            'low_stock'    => $lowStock,
            'alert_count'  => count($outOfStock) + count($lowStock),
        ];
    }

    // ─── Detaylı Sipariş Raporu ───────────────

    public static function getOrderReport(string $from, string $to): array
    {
        $pdo = db();

        $st = $pdo->prepare(
            "SELECT
                DATE(created_at) AS day,
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status != 'cancelled' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'cancelled'  THEN 1 ELSE 0 END) AS cancelled,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END), 0) AS revenue
             FROM orders
             WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)
             ORDER BY day ASC"
        );
        $st->execute([$from, $to]);
        $daily = $st->fetchAll();

        // Toplam özet
        $summary = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total    ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN discount ELSE 0 END), 0) AS total_discount,
                COALESCE(AVG(CASE WHEN status != 'cancelled' THEN total    ELSE NULL END), 0) AS avg_order_value
             FROM orders
             WHERE DATE(created_at) BETWEEN ? AND ?"
        );
        $summary->execute([$from, $to]);

        return [
            'period'  => ['from' => $from, 'to' => $to],
            'summary' => $summary->fetch(),
            'daily'   => $daily,
        ];
    }
}
