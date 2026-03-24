<?php

function couponValue(string $column, mixed $default = null): mixed {
    return match ($column) {
        'code' => strtoupper(trim((string) input('code', $default ?? ''))),
        'discount_type' => input('discount_type', input('type', input('discountType', $default ?? 'percentage'))),
        'value' => (float) input('value', input('discount_value', input('discountValue', $default ?? 0))),
        'min_order_amount' => (float) input('min_order_amount', input('min_order_total', input('minOrderAmount', $default ?? 0))),
        'usage_limit' => (($raw = input('usage_limit', input('usageLimit', $default))) === '' || $raw === null) ? null : (int) $raw,
        'expires_at' => (($raw = input('expires_at', input('expiresAt', $default))) === '' || $raw === null) ? null : date('Y-m-d H:i:s', strtotime((string) $raw)),
        'is_active' => (int) input('is_active', input('isActive', $default ?? 1)),
        default => input($column, $default),
    };
}

function couponResponse(array $row): array {
    $type = $row['discount_type'] ?? ($row['type'] ?? 'percentage');
    $minOrder = isset($row['min_order_amount']) ? (float) $row['min_order_amount'] : (float) ($row['min_order_total'] ?? 0);

    return [
        ...$row,
        'type' => $type === 'percentage' ? 'percent' : $type,
        'discount_type' => $type,
        'min_order_total' => $minOrder,
        'min_order_amount' => $minOrder,
        'usage_limit' => isset($row['usage_limit']) && $row['usage_limit'] !== null ? (int) $row['usage_limit'] : null,
        'used_count' => (int) ($row['used_count'] ?? 0),
        'is_active' => (int) ($row['is_active'] ?? 1),
        'value' => (float) ($row['value'] ?? 0),
    ];
}

switch (true) {
    case $method === 'GET' && $id === null:
        adminRequired();
        $select = [
            'id',
            'code',
            tableHasColumn('coupons', 'discount_type') ? 'discount_type' : 'type AS discount_type',
            'value',
            tableHasColumn('coupons', 'min_order_amount') ? 'min_order_amount' : 'min_order_total AS min_order_amount',
            'usage_limit',
            'used_count',
            'expires_at',
            'is_active',
            'created_at',
        ];
        $rows = db()->query(
            'SELECT ' . implode(', ', $select) . ' FROM coupons ORDER BY created_at DESC'
        )->fetchAll();
        ok(array_map(fn(array $row) => couponResponse($row), $rows));
        break;

    case $method === 'POST' && $id === null:
        adminRequired();

        $code = couponValue('code');
        if ($code === '') error('Kupon kodu gerekli.');
        if (!preg_match('/^[A-Z0-9_\-]{3,30}$/', $code)) {
            error('Kupon kodu 3-30 karakter olmali ve sadece buyuk harf, rakam, _ veya - icermeli.');
        }

        $type = (string) couponValue('discount_type');
        if ($type === 'percent') {
            $type = 'percentage';
        }
        if (!in_array($type, ['percentage', 'fixed'], true)) {
            error('Indirim tipi "percentage" veya "fixed" olmali.');
        }

        $value = (float) couponValue('value');
        if ($value <= 0) error('Indirim degeri 0\'dan buyuk olmali.');
        if ($type === 'percentage' && $value > 100) error('Yuzde indirimi 100\'den buyuk olamaz.');

        $chk = db()->prepare('SELECT id FROM coupons WHERE code = ? LIMIT 1');
        $chk->execute([$code]);
        if ($chk->fetch()) {
            error('Bu kupon kodu zaten mevcut.', 409);
        }

        $insertColumns = ['code', 'discount_type', 'value', 'min_order_amount', 'usage_limit', 'expires_at'];
        $insertValues = [
            $code,
            $type,
            $value,
            (float) couponValue('min_order_amount', 0),
            couponValue('usage_limit'),
            couponValue('expires_at'),
        ];

        if (tableHasColumn('coupons', 'is_active')) {
            $insertColumns[] = 'is_active';
            $insertValues[] = couponValue('is_active', 1);
        }

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        db()->prepare(
            'INSERT INTO coupons (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')'
        )->execute($insertValues);

        $createdId = (int) db()->lastInsertId();
        $fetch = db()->prepare(
            'SELECT id, code, discount_type, value, min_order_amount, usage_limit, used_count, expires_at, is_active, created_at
             FROM coupons WHERE id = ? LIMIT 1'
        );
        $fetch->execute([$createdId]);
        ok(couponResponse($fetch->fetch() ?: ['id' => $createdId, 'code' => $code]));
        break;

    case $method === 'PATCH' && is_numeric($id):
        adminRequired();

        $allowed = ['code', 'discount_type', 'value', 'min_order_amount', 'usage_limit', 'expires_at', 'is_active'];
        $fields = [];
        $values = [];
        $data = body();

        foreach ($allowed as $column) {
            $aliases = match ($column) {
                'discount_type' => ['discount_type', 'type', 'discountType'],
                'min_order_amount' => ['min_order_amount', 'min_order_total', 'minOrderAmount'],
                'usage_limit' => ['usage_limit', 'usageLimit'],
                'expires_at' => ['expires_at', 'expiresAt'],
                'is_active' => ['is_active', 'isActive'],
                default => [$column],
            };

            $hasValue = false;
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $data)) {
                    $hasValue = true;
                    break;
                }
            }

            if (!$hasValue) {
                continue;
            }

            $value = couponValue($column);
            if ($column === 'discount_type' && $value === 'percent') {
                $value = 'percentage';
            }

            $fields[] = $column . ' = ?';
            $values[] = $value;
        }

        if ($fields === []) error('Guncellenecek alan yok.');

        $values[] = (int) $id;
        db()->prepare('UPDATE coupons SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

        $fetch = db()->prepare(
            'SELECT id, code, discount_type, value, min_order_amount, usage_limit, used_count, expires_at, is_active, created_at
             FROM coupons WHERE id = ? LIMIT 1'
        );
        $fetch->execute([(int) $id]);
        ok(couponResponse($fetch->fetch() ?: ['id' => (int) $id]));
        break;

    case $method === 'DELETE' && is_numeric($id):
        adminRequired();
        $stmt = db()->prepare('DELETE FROM coupons WHERE id = ?');
        $stmt->execute([(int) $id]);
        if ($stmt->rowCount() === 0) {
            error('Kupon bulunamadi.', 404);
        }
        ok(['success' => true]);
        break;

    default:
        error('Kupon endpoint bulunamadi.', 404);
}
