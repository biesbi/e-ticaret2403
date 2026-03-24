<?php

if ($method !== 'GET') error('Method not allowed.', 405);
adminRequired();

$select = [
    'id',
    tableHasColumn('users', 'username') ? 'username' : 'NULL AS username',
    tableHasColumn('users', 'display_name')
        ? 'display_name'
        : (tableHasColumn('users', 'name') ? 'name AS display_name' : 'NULL AS display_name'),
    'email',
    'role',
    tableHasColumn('users', 'is_active') ? 'is_active' : '1 AS is_active',
    'created_at',
];

$rows = db()->query(
    'SELECT ' . implode(', ', $select) . ' FROM users ORDER BY created_at DESC'
)->fetchAll();

foreach ($rows as &$row) {
    $row['role_label'] = ($row['role'] ?? 'user') === 'admin' ? 'admin' : 'customer';
}

ok($rows);
