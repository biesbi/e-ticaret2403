<?php

switch ($id) {
    case 'cities':
        if ($method !== 'GET') error('Method not allowed.', 405);
        if (!tableExists('shipping_cities')) {
            ok([]);
        }
        ok(db()->query('SELECT id, name, code FROM shipping_cities ORDER BY name')->fetchAll());

    case 'districts':
        if ($method !== 'GET') error('Method not allowed.', 405);
        if (!$sub) error('Sehir ID gerekli.');
        if (!tableExists('shipping_districts')) {
            ok([]);
        }
        $stmt = db()->prepare('SELECT id, name FROM shipping_districts WHERE city_id = ? ORDER BY name');
        $stmt->execute([(int) $sub]);
        ok($stmt->fetchAll());

    case 'neighborhoods':
        if ($method !== 'GET') error('Method not allowed.', 405);
        if (!$sub) error('Ilce ID gerekli.');
        if (!tableExists('shipping_neighborhoods')) {
            ok([]);
        }
        $stmt = db()->prepare('SELECT id, name FROM shipping_neighborhoods WHERE district_id = ? ORDER BY name');
        $stmt->execute([(int) $sub]);
        ok($stmt->fetchAll());

    case 'streets':
        if ($method !== 'GET') error('Method not allowed.', 405);
        if (!$sub) error('Mahalle ID gerekli.');
        if (!tableExists('shipping_streets')) {
            ok([]);
        }
        $stmt = db()->prepare(
            'SELECT id, name, full_name
             FROM shipping_streets
             WHERE neighborhood_id = ?
             ORDER BY name'
        );
        $stmt->execute([(int) $sub]);
        ok($stmt->fetchAll());

    case 'groups':
        ensureShippingGroupsSchema();
        switch (true) {
            case $method === 'GET' && $sub === null:
                if (!tableExists('shipping_groups')) {
                    ok([]);
                }
                $rows = db()->query(
                    'SELECT id, name, carrier, min_desi, max_desi, base_fee, free_above, is_active
                     FROM shipping_groups
                     ORDER BY min_desi ASC, COALESCE(max_desi, 999999) ASC, id ASC'
                )->fetchAll();
                ok(array_map('normalizeShippingGroup', $rows));

            case ($method === 'PATCH' || $method === 'PUT') && is_numeric($sub):
                adminRequired();
                $allowed = ['name', 'carrier', 'base_fee', 'free_above', 'is_active', 'min_desi', 'max_desi', 'price'];
                $fields = [];
                $values = [];
                $data = body();

                foreach ($allowed as $field) {
                    if (array_key_exists($field, $data)) {
                        $column = $field === 'price' ? 'base_fee' : $field;
                        $value = $data[$field];
                        if ($column === 'min_desi') {
                            $value = max(1, (int) $value);
                        } elseif ($column === 'max_desi') {
                            $value = $value === '' || $value === null ? null : max(1, (int) $value);
                        } elseif ($column === 'base_fee' || $column === 'free_above') {
                            $value = $value === '' || $value === null ? null : (float) $value;
                        } elseif ($column === 'is_active') {
                            $value = (int) !!$value;
                        }

                        $fields[] = "$column = ?";
                        $values[] = $value;
                    }
                }

                if ($fields === []) error('Guncellenecek alan yok.');

                if (array_key_exists('min_desi', $data) && array_key_exists('max_desi', $data) && $data['max_desi'] !== null && $data['max_desi'] !== '' && (int) $data['max_desi'] < (int) $data['min_desi']) {
                    error('Maksimum desi minimum desiden kucuk olamaz.');
                }

                $values[] = (int) $sub;
                db()->prepare('UPDATE shipping_groups SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
                $stmt = db()->prepare(
                    'SELECT id, name, carrier, min_desi, max_desi, base_fee, free_above, is_active
                     FROM shipping_groups
                     WHERE id = ? LIMIT 1'
                );
                $stmt->execute([(int) $sub]);
                $row = $stmt->fetch();
                ok($row ? normalizeShippingGroup($row) : ['success' => true]);

            case $method === 'DELETE' && is_numeric($sub):
                adminRequired();
                db()->prepare('DELETE FROM shipping_groups WHERE id = ?')->execute([(int) $sub]);
                ok(['success' => true]);

            default:
                error('Kargo grubu endpoint bulunamadi.', 404);
        }

    case 'calculate':
        if ($method !== 'POST') error('Method not allowed.', 405);

        $totalDesi = (float) input('totalDesi', 0);
        $groupId = (int) input('shipping_group_id', 0);
        $subtotal = (float) input('subtotal', input('order_total', 0));
        if ($totalDesi < 0) error('Desi degeri negatif olamaz.');
        if ($totalDesi > 9999) error('Desi degeri cok buyuk.');
        $shipping = calculateShippingFeeByDesi($totalDesi, $subtotal, $groupId > 0 ? $groupId : null);
        ok($shipping);

    default:
        error('Kargo endpoint bulunamadi.', 404);
}
