<?php
// ═══════════════════════════════════════════════
//  Admin Panel Route'ları
//  Tümü admin JWT gerektirir.
//
//  GET    /admin/dashboard          — Genel özet
//  GET    /admin/stats/orders       — Sipariş raporu (tarih aralığı)
//  GET    /admin/stock-alerts       — Stok uyarıları
//  GET    /admin/users              — Kullanıcı listesi
//  GET    /admin/users/{id}         — Kullanıcı detayı
//  PATCH  /admin/users/{id}         — Rol değiştir / hesap askıya al
//  DELETE /admin/users/{id}         — Hesap sil
//  POST   /admin/bulk/orders        — Toplu sipariş durumu güncelle
//  POST   /admin/bulk/products      — Toplu ürün aktif/pasif
//  GET    /admin/audit-logs         — Audit log görüntüle
// ═══════════════════════════════════════════════

require_once __DIR__ . '/../services/AdminService.php';

function adminUserSelectSql(): string {
    $parts = ['id'];
    $parts[] = tableHasColumn('users', 'username')
        ? 'username'
        : 'NULL AS username';
    $parts[] = tableHasColumn('users', 'display_name')
        ? 'display_name'
        : (tableHasColumn('users', 'name') ? 'name AS display_name' : 'NULL AS display_name');
    $parts[] = 'email';
    $parts[] = 'role';
    if (tableHasColumn('users', 'is_active')) {
        $parts[] = 'is_active';
    }
    if (tableHasColumn('users', 'email_verified_at')) {
        $parts[] = 'email_verified_at';
    }
    $parts[] = tableHasColumn('users', 'last_login')
        ? 'last_login'
        : 'NULL AS last_login';
    $parts[] = 'created_at';
    return implode(', ', $parts);
}

function adminUserNameField(): string {
    return tableHasColumn('users', 'display_name') ? 'display_name' : 'name';
}

function adminNormalizeRoleFilter(?string $role): ?string {
    if ($role === null || $role === '') {
        return null;
    }
    return match ($role) {
        'customer' => 'user',
        'user', 'admin' => $role,
        default => null,
    };
}

function adminHydrateUserRow(array $row): array {
    $row['display_name'] = $row['display_name'] ?? $row['name'] ?? '';
    $role = $row['role'] ?? 'user';
    $row['role'] = $role === 'customer' ? 'user' : $role;
    $row['role_label'] = $role === 'admin' ? 'admin' : 'customer';
    $row['is_active'] = isset($row['is_active']) ? (int) $row['is_active'] : 1;
    $row['is_verified'] = !empty($row['email_verified_at']);
    return $row;
}

// Tüm admin endpoint'leri admin rolü gerektirir
$admin = Auth::requireAdmin();

// Segments: /admin/{id}/{sub}
// $id  = 'dashboard' | 'stats' | 'stock-alerts' | 'users' | 'bulk' | 'audit-logs'
// $sub = numeric id | 'orders' | 'products' | null

// ─── GET /admin/dashboard ─────────────────────
if ($id === 'dashboard' && $method === 'GET') {
    ok(AdminService::getDashboard());
}

// ─── GET /admin/stock-alerts ──────────────────
elseif ($id === 'stock-alerts' && $method === 'GET') {
    ok(AdminService::stockAlerts());
}

// ─── GET /admin/stats/orders ──────────────────
elseif ($id === 'stats' && $sub === 'orders' && $method === 'GET') {
    $from = $_GET['from'] ?? date('Y-m-01');           // Bu ayın başı
    $to   = $_GET['to']   ?? date('Y-m-d');            // Bugün

    // Tarih format doğrulama
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        error('Tarih formatı YYYY-MM-DD olmalı.');
    }
    if ($from > $to) error('Başlangıç tarihi bitiş tarihinden büyük olamaz.');

    // Max 365 gün aralık
    $dayDiff = (strtotime($to) - strtotime($from)) / 86400;
    if ($dayDiff > 365) error('En fazla 365 günlük rapor alınabilir.');

    ok(AdminService::getOrderReport($from, $to));
}

// ─── KULLANICI YÖNETİMİ ──────────────────────

// GET /admin/users — liste
elseif ($id === 'users' && $sub === null && $method === 'GET') {
    $page   = max(1, (int) ($_GET['page']  ?? 1));
    $limit  = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $role   = $_GET['role'] ?? '';

    $where  = [];
    $params = [];
    $nameField = adminUserNameField();

    if ($search !== '') {
        $searchParts = ['email LIKE ?'];
        if (tableHasColumn('users', 'username')) {
            $searchParts[] = 'username LIKE ?';
        }
        if ($nameField !== '') {
            $searchParts[] = $nameField . ' LIKE ?';
        }
        $where[]  = '(' . implode(' OR ', $searchParts) . ')';
        $like     = '%' . $search . '%';
        foreach ($searchParts as $ignored) {
            $params[] = $like;
        }
    }
    $normalizedRole = adminNormalizeRoleFilter($role);
    if ($normalizedRole !== null) {
        $where[]  = 'role = ?';
        $params[] = $normalizedRole;
    }

    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cntStmt = db()->prepare("SELECT COUNT(*) FROM users $whereStr");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT " . adminUserSelectSql() . "
         FROM users $whereStr
         ORDER BY created_at DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);

    // Her kullanıcı için sipariş sayısı ve toplam harcama
    $rows = $stmt->fetchAll();
    foreach ($rows as &$u) {
        $u = adminHydrateUserRow($u);
        $os = db()->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS spent
             FROM orders WHERE user_id = ? AND status != 'cancelled'"
        );
        $os->execute([$u['id']]);
        $orderInfo    = $os->fetch();
        $u['orders']  = (int) $orderInfo['cnt'];
        $u['spent']   = (float) $orderInfo['spent'];
    }

    ok([
        'items' => $rows,
        'total' => $total,
        'page'  => $page,
        'pages' => (int) ceil($total / $limit),
    ]);
}

// GET /admin/users/{id} — detay
elseif ($id === 'users' && $sub !== null && $method === 'GET') {
    $uid = (string) $sub;

    $stmt = db()->prepare(
        'SELECT ' . adminUserSelectSql() . ' FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) error('Kullanıcı bulunamadı.', 404);
    $user = adminHydrateUserRow($user);

    // Sipariş geçmişi
    $orders = db()->prepare(
        'SELECT id, status, total, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 10'
    );
    $orders->execute([$uid]);
    $user['recent_orders'] = $orders->fetchAll();

    // Sepet özeti
    $cart = db()->prepare(
        'SELECT COUNT(*) AS item_count FROM cart_items WHERE user_id = ?'
    );
    $cart->execute([$uid]);
    $user['cart_items'] = (int) $cart->fetchColumn();

    ok($user);
}

// PATCH /admin/users/{id} — rol değiştir veya askıya al
elseif ($id === 'users' && $sub !== null && $method === 'PATCH') {
    $uid  = (string) $sub;

    // Admin kendini değiştiremez
    if ($uid === (string) $admin['sub']) error('Kendi hesabınızı bu yolla değiştiremezsiniz.');

    $stmt = db()->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) error('Kullanıcı bulunamadı.', 404);

    $data   = body();
    $fields = [];
    $values = [];

    if (isset($data['role'])) {
        $nextRole = adminNormalizeRoleFilter((string) $data['role']);
        if ($nextRole === null) error('Geçersiz rol.');
        $fields[] = 'role = ?';
        $values[] = $nextRole;
    }

    if (tableHasColumn('users', 'is_active') && array_key_exists('is_active', $data)) {
        $fields[] = 'is_active = ?';
        $values[] = !empty($data['is_active']) ? 1 : 0;
    }

    if (empty($fields)) error('Güncellenecek alan yok.');

    $values[] = $uid;
    db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

    AuditLog::write(AuditLog::ADMIN_ACTION, (string) $admin['sub'], 'user', $uid, [
        'action'  => 'update',
        'changes' => $data,
    ]);

    ok(null, 'Kullanıcı güncellendi.');
}

// DELETE /admin/users/{id} — hesap sil
elseif ($id === 'users' && $sub !== null && $method === 'DELETE') {
    $uid = (string) $sub;

    if ($uid === (string) $admin['sub']) error('Kendi hesabınızı silemezsiniz.');

    $chk = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $chk->execute([$uid]);
    if (!$chk->fetch()) error('Kullanıcı bulunamadı.', 404);

    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);

    AuditLog::write(AuditLog::ADMIN_ACTION, (string) $admin['sub'], 'user', $uid, [
        'action' => 'delete',
    ]);

    ok(null, 'Kullanıcı silindi.');
}

// ─── TOPLU İŞLEMLER ─────────────────────────

// POST /admin/bulk/orders — toplu sipariş durumu güncelle
elseif ($id === 'bulk' && $sub === 'orders' && $method === 'POST') {
    $ids    = input('ids', []);
    $status = input('status', '');

    $validStatuses = StockService::allowedOrderStatuses();
    if (!is_array($ids) || empty($ids)) error('Sipariş ID listesi gerekli.');
    if (!in_array($status, $validStatuses))  error('Geçersiz durum.');

    // Maksimum 100 kayıt
    $ids = array_slice(array_values(array_filter(array_map(fn($value) => trim((string) $value), $ids))), 0, 100);

    if (empty($ids)) error('Geçerli sipariş ID bulunamadı.');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params       = array_merge([$status], $ids);

    db()->prepare(
        "UPDATE orders SET status = ? WHERE id IN ($placeholders)"
    )->execute($params);

    foreach ($ids as $orderId) {
        if (in_array($status, ['paid', 'confirmed', 'processing', 'shipped', 'delivered'], true)) {
            StockService::finalizeReservedStock((string) $orderId);
        } elseif (in_array($status, ['failed', 'cancelled'], true)) {
            StockService::releaseReservedStock((string) $orderId);
        }
    }

    AuditLog::write(AuditLog::ADMIN_ACTION, (string) $admin['sub'], 'order', null, [
        'action' => 'bulk_status',
        'ids'    => $ids,
        'status' => $status,
    ]);

    ok(['updated' => count($ids)], count($ids) . ' sipariş güncellendi.');
}

// POST /admin/bulk/products — toplu ürün aktif/pasif
elseif ($id === 'bulk' && $sub === 'products' && $method === 'POST') {
    $ids      = input('ids', []);
    $isActive = input('is_active', null);

    if (!is_array($ids) || empty($ids)) error('Ürün ID listesi gerekli.');
    if ($isActive === null) error('is_active alanı gerekli (true/false).');

    $ids          = array_slice(array_values(array_filter(array_map(fn($value) => trim((string) $value), $ids))), 0, 100);
    if (empty($ids)) error('Geçerli ürün ID bulunamadı.');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $active       = $isActive ? 1 : 0;

    db()->prepare(
        "UPDATE products SET is_active = ? WHERE id IN ($placeholders)"
    )->execute(array_merge([$active], $ids));

    AuditLog::write(AuditLog::ADMIN_ACTION, (string) $admin['sub'], 'product', null, [
        'action'    => 'bulk_active',
        'ids'       => $ids,
        'is_active' => $active,
    ]);

    ok(['updated' => count($ids)], count($ids) . ' ürün güncellendi.');
}

// ─── AUDIT LOG ───────────────────────────────

// GET /admin/audit-logs
elseif ($id === 'audit-logs' && $method === 'GET') {
    $page   = max(1, (int) ($_GET['page']  ?? 1));
    $limit  = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where  = [];
    $params = [];

    if (!empty($_GET['action'])) {
        $where[]  = 'action LIKE ?';
        $params[] = '%' . $_GET['action'] . '%';
    }
    if (!empty($_GET['user_id'])) {
        $where[]  = 'user_id = ?';
        $params[] = (string) $_GET['user_id'];
    }
    if (!empty($_GET['entity_type'])) {
        $where[]  = 'entity_type = ?';
        $params[] = $_GET['entity_type'];
    }
    if (!empty($_GET['from'])) {
        $where[]  = 'created_at >= ?';
        $params[] = $_GET['from'] . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $where[]  = 'created_at <= ?';
        $params[] = $_GET['to'] . ' 23:59:59';
    }

    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cntStmt = db()->prepare("SELECT COUNT(*) FROM audit_logs $whereStr");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT al.*, "
        . (tableHasColumn('users', 'username') ? 'u.username' : (tableHasColumn('users', 'name') ? 'u.name AS username' : 'u.email AS username')) . "
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         $whereStr
         ORDER BY al.created_at DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    foreach ($logs as &$log) {
        $log['meta'] = $log['meta'] ? json_decode($log['meta'], true) : null;
    }

    ok([
        'items' => $logs,
        'total' => $total,
        'page'  => $page,
        'pages' => (int) ceil($total / $limit),
    ]);
}

else {
    error('Admin endpoint bulunamadı.', 404);
}
