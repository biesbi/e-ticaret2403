<?php

require_once __DIR__ . '/../services/AdminService.php';

function adminUserSelectSql(): string
{
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
    $parts[] = tableHasColumn('users', 'email_verified')
        ? 'email_verified'
        : 'CASE WHEN email_verified_at IS NULL THEN 0 ELSE 1 END AS email_verified';
    if (tableHasColumn('users', 'email_verified_at')) {
        $parts[] = 'email_verified_at';
    }
    $parts[] = tableHasColumn('users', 'last_login')
        ? 'last_login'
        : 'NULL AS last_login';
    $parts[] = 'created_at';

    return implode(', ', $parts);
}

function adminUserNameField(): string
{
    return tableHasColumn('users', 'display_name') ? 'display_name' : 'name';
}

function adminNormalizeRoleFilter(?string $role): ?string
{
    if ($role === null || $role === '') {
        return null;
    }

    return match ($role) {
        'customer' => 'user',
        'product_editor', 'product-editor' => 'product_editor',
        'user', 'admin' => $role,
        default => null,
    };
}

function adminNormalizeOrderStatus(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === '') {
        return '';
    }

    $allowed = StockService::allowedOrderStatuses();
    if (in_array($status, $allowed, true)) {
        return $status;
    }

    $aliases = [
        'processing' => ['preparing', 'confirmed'],
        'preparing' => ['processing', 'confirmed'],
        'confirmed' => ['processing', 'preparing'],
        'cancelled' => ['failed'],
        'failed' => ['cancelled'],
    ];

    foreach ($aliases[$status] ?? [] as $candidate) {
        if (in_array($candidate, $allowed, true)) {
            return $candidate;
        }
    }

    return $status;
}

function adminHydrateUserRow(array $row): array
{
    $row['display_name'] = $row['display_name'] ?? $row['name'] ?? '';
    $dbRole = (string) ($row['role'] ?? 'user');
    $row['role'] = $dbRole === 'customer' ? 'user' : $dbRole;
    $normalizedRole = normalizeUserRole($dbRole);
    $row['role_label'] = $normalizedRole === 'admin'
        ? 'admin'
        : ($normalizedRole === 'product_editor' ? 'product_editor' : 'customer');
    $row['is_active'] = isset($row['is_active']) ? (int) $row['is_active'] : 1;
    $verifiedFlag = isset($row['email_verified']) ? (int) $row['email_verified'] : (!empty($row['email_verified_at']) ? 1 : 0);
    $row['is_verified'] = $verifiedFlag === 1 || !empty($row['email_verified_at']);
    unset($row['email_verified']);

    return $row;
}

function adminRouteRequireAdmin(array $actor): void
{
    if (!roleCanManageUsers((string) ($actor['role'] ?? ''))) {
        error('Yetkiniz yok.', 403);
    }
}

function adminRouteRequireProductManager(array $actor): void
{
    if (!roleCanManageProducts((string) ($actor['role'] ?? ''))) {
        error('Yetkiniz yok.', 403);
    }
}

$actor = Auth::require();

if ($id === 'dashboard' && $method === 'GET') {
    adminRouteRequireAdmin($actor);
    ok(AdminService::getDashboard());
}

elseif ($id === 'stock-alerts' && $method === 'GET') {
    adminRouteRequireAdmin($actor);
    ok(AdminService::stockAlerts());
}

elseif ($id === 'stats' && $sub === 'orders' && $method === 'GET') {
    adminRouteRequireAdmin($actor);

    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        error('Tarih formati YYYY-MM-DD olmali.');
    }
    if ($from > $to) {
        error('Baslangic tarihi bitis tarihinden buyuk olamaz.');
    }

    $dayDiff = (strtotime($to) - strtotime($from)) / 86400;
    if ($dayDiff > 365) {
        error('En fazla 365 gunluk rapor alinabilir.');
    }

    ok(AdminService::getOrderReport($from, $to));
}

elseif ($id === 'users' && $sub === null && $method === 'GET') {
    adminRouteRequireAdmin($actor);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $role = $_GET['role'] ?? '';

    $where = [];
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

        $where[] = '(' . implode(' OR ', $searchParts) . ')';
        $like = '%' . $search . '%';
        foreach ($searchParts as $ignored) {
            $params[] = $like;
        }
    }

    $normalizedRole = adminNormalizeRoleFilter($role);
    if ($normalizedRole !== null) {
        $where[] = 'role = ?';
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

    $rows = $stmt->fetchAll();
    foreach ($rows as &$userRow) {
        $userRow = adminHydrateUserRow($userRow);
        $orderStats = db()->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS spent
             FROM orders
             WHERE user_id = ?
               AND status != 'cancelled'"
        );
        $orderStats->execute([$userRow['id']]);
        $orderInfo = $orderStats->fetch();
        $userRow['orders'] = (int) ($orderInfo['cnt'] ?? 0);
        $userRow['spent'] = (float) ($orderInfo['spent'] ?? 0);
    }

    ok([
        'items' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => (int) ceil($total / $limit),
    ]);
}

elseif ($id === 'users' && $sub !== null && $method === 'GET') {
    adminRouteRequireAdmin($actor);

    $uid = (string) $sub;
    $stmt = db()->prepare('SELECT ' . adminUserSelectSql() . ' FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) {
        error('Kullanici bulunamadi.', 404);
    }

    $user = adminHydrateUserRow($user);

    $orders = db()->prepare(
        'SELECT id, status, total, created_at
         FROM orders
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 10'
    );
    $orders->execute([$uid]);
    $user['recent_orders'] = $orders->fetchAll();

    $cart = db()->prepare('SELECT COUNT(*) AS item_count FROM cart_items WHERE user_id = ?');
    $cart->execute([$uid]);
    $user['cart_items'] = (int) $cart->fetchColumn();

    ok($user);
}

elseif ($id === 'users' && $sub !== null && $method === 'PATCH') {
    adminRouteRequireAdmin($actor);

    $uid = (string) $sub;
    if ($uid === (string) ($actor['sub'] ?? '')) {
        error('Kendi hesabinizi bu yolla degistiremezsiniz.');
    }

    $stmt = db()->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) {
        error('Kullanici bulunamadi.', 404);
    }

    $data = body();
    $fields = [];
    $values = [];

    if (isset($data['role'])) {
        $nextRole = adminNormalizeRoleFilter((string) $data['role']);
        if ($nextRole === null) {
            error('Gecersiz rol.');
        }
        $fields[] = 'role = ?';
        $values[] = $nextRole;
    }

    if (tableHasColumn('users', 'is_active') && array_key_exists('is_active', $data)) {
        $fields[] = 'is_active = ?';
        $values[] = !empty($data['is_active']) ? 1 : 0;
    }

    if ($fields === []) {
        error('Guncellenecek alan yok.');
    }

    $values[] = $uid;
    db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

    AuditLog::write(AuditLog::ADMIN_ACTION, (string) ($actor['sub'] ?? ''), 'user', $uid, [
        'action' => 'update',
        'changes' => $data,
    ]);

    ok(null, 'Kullanici guncellendi.');
}

elseif ($id === 'users' && $sub !== null && $method === 'DELETE') {
    adminRouteRequireAdmin($actor);

    $uid = (string) $sub;
    if ($uid === (string) ($actor['sub'] ?? '')) {
        error('Kendi hesabinizi silemezsiniz.');
    }

    $check = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $check->execute([$uid]);
    if (!$check->fetch()) {
        error('Kullanici bulunamadi.', 404);
    }

    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);

    AuditLog::write(AuditLog::ADMIN_ACTION, (string) ($actor['sub'] ?? ''), 'user', $uid, [
        'action' => 'delete',
    ]);

    ok(null, 'Kullanici silindi.');
}

elseif ($id === 'bulk' && $sub === 'orders' && $method === 'POST') {
    adminRouteRequireAdmin($actor);

    $ids = input('ids', []);
    $status = adminNormalizeOrderStatus((string) input('status', ''));
    $validStatuses = StockService::allowedOrderStatuses();

    if (!is_array($ids) || empty($ids)) {
        error('Siparis ID listesi gerekli.');
    }
    if (!in_array($status, $validStatuses, true)) {
        error('Gecersiz durum.');
    }

    $ids = array_slice(
        array_values(array_filter(array_map(fn($value) => trim((string) $value), $ids))),
        0,
        100
    );
    if ($ids === []) {
        error('Gecerli siparis ID bulunamadi.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$status], $ids);
    db()->prepare("UPDATE orders SET status = ? WHERE id IN ($placeholders)")->execute($params);

    foreach ($ids as $orderId) {
        if (in_array($status, ['paid', 'confirmed', 'processing', 'preparing', 'shipped', 'delivered'], true)) {
            StockService::finalizeReservedStock((string) $orderId);
        } elseif (in_array($status, ['failed', 'cancelled'], true)) {
            StockService::releaseReservedStock((string) $orderId);
        }
    }

    AuditLog::write(AuditLog::ADMIN_ACTION, (string) ($actor['sub'] ?? ''), 'order', null, [
        'action' => 'bulk_status',
        'ids' => $ids,
        'status' => $status,
    ]);

    ok(['updated' => count($ids)], count($ids) . ' siparis guncellendi.');
}

elseif ($id === 'bulk' && $sub === 'products' && $method === 'POST') {
    adminRouteRequireProductManager($actor);

    $ids = input('ids', []);
    $isActive = input('is_active', null);

    if (!is_array($ids) || empty($ids)) {
        error('Urun ID listesi gerekli.');
    }
    if ($isActive === null) {
        error('is_active alani gerekli (true/false).');
    }

    $ids = array_slice(
        array_values(array_filter(array_map(fn($value) => trim((string) $value), $ids))),
        0,
        100
    );
    if ($ids === []) {
        error('Gecerli urun ID bulunamadi.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $active = $isActive ? 1 : 0;

    db()->prepare("UPDATE products SET is_active = ? WHERE id IN ($placeholders)")
        ->execute(array_merge([$active], $ids));

    AuditLog::write(AuditLog::ADMIN_ACTION, (string) ($actor['sub'] ?? ''), 'product', null, [
        'action' => 'bulk_active',
        'ids' => $ids,
        'is_active' => $active,
    ]);

    ok(['updated' => count($ids)], count($ids) . ' urun guncellendi.');
}

elseif ($id === 'audit-logs' && $method === 'GET') {
    adminRouteRequireAdmin($actor);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if (!empty($_GET['action'])) {
        $where[] = 'action LIKE ?';
        $params[] = '%' . $_GET['action'] . '%';
    }
    if (!empty($_GET['user_id'])) {
        $where[] = 'user_id = ?';
        $params[] = (string) $_GET['user_id'];
    }
    if (!empty($_GET['entity_type'])) {
        $where[] = 'entity_type = ?';
        $params[] = $_GET['entity_type'];
    }
    if (!empty($_GET['from'])) {
        $where[] = 'created_at >= ?';
        $params[] = $_GET['from'] . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $where[] = 'created_at <= ?';
        $params[] = $_GET['to'] . ' 23:59:59';
    }

    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cntStmt = db()->prepare("SELECT COUNT(*) FROM audit_logs $whereStr");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT al.*, "
        . (tableHasColumn('users', 'username')
            ? 'u.username'
            : (tableHasColumn('users', 'name') ? 'u.name AS username' : 'u.email AS username'))
        . "
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
        'page' => $page,
        'pages' => (int) ceil($total / $limit),
    ]);
}

else {
    error('Admin endpoint bulunamadi.', 404);
}
