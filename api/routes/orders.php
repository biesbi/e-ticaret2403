<?php

StockService::ensureSchema();

function generateOrderId(): string {
    return 'BM' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
}

function normalizeCoupon(array $coupon, float $total): array {
    $type = $coupon['discount_type'] ?? ($coupon['type'] ?? 'fixed');
    $value = (float) ($coupon['value'] ?? 0);

    $discount = in_array($type, ['percentage', 'percent'], true)
        ? round($total * $value / 100, 2)
        : min($value, $total);

    return [
        'code' => (string) ($coupon['code'] ?? ''),
        'discount' => max(0.0, $discount),
    ];
}

function fetchCoupon(string $code): ?array {
    $stmt = db()->prepare('SELECT * FROM coupons WHERE code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([strtoupper($code)]);
    $coupon = $stmt->fetch();
    if (!$coupon) return null;

    if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at']) < time()) {
        error('Kuponun suresi dolmus.');
    }

    $usageLimit = $coupon['usage_limit'] ?? null;
    $usedCount = (int) ($coupon['used_count'] ?? 0);
    if ($usageLimit !== null && $usedCount >= (int) $usageLimit) {
        error('Kupon limitine ulasildi.');
    }

    return $coupon;
}

function orderAllowedStatuses(): array {
    return StockService::allowedOrderStatuses();
}

function orderStatusSupported(string $status): bool {
    return in_array($status, orderAllowedStatuses(), true);
}

function orderApplyStockTransition(string $orderId, ?string $status, ?string $paymentStatus): void {
    $status = $status ?? '';
    $paymentStatus = $paymentStatus ?? '';

    if (in_array($paymentStatus, ['paid', 'confirmed', 'success'], true)
        || in_array($status, ['paid', 'confirmed', 'processing', 'shipped', 'delivered'], true)) {
        StockService::finalizeReservedStock($orderId);
        return;
    }

    if (in_array($paymentStatus, ['failed', 'cancelled'], true)
        || in_array($status, ['failed', 'cancelled'], true)) {
        StockService::releaseReservedStock($orderId);
    }
}

function orderInsertItem(string $orderId, array $line): void {
    $columns = ['order_id'];
    $values = [$orderId];

    $append = static function (string $column, mixed $value) use (&$columns, &$values): void {
        if (tableHasColumn('order_items', $column)) {
            $columns[] = $column;
            $values[] = $value;
        }
    };

    $append('product_id', $line['product_id'] ?? null);
    $append('variant_id', $line['variant_id'] ?? null);
    $append('product_name', (string) ($line['product_name'] ?? ''));
    $append('variant_name', $line['variant_name'] ?? null);
    $append('variant_color', $line['variant_color'] ?? null);
    $append('sku', $line['sku'] ?? null);
    $append('product_img', (string) ($line['product_img'] ?? ''));
    $append('price', (float) ($line['unit_price'] ?? 0));
    $append('unit_price', (float) ($line['unit_price'] ?? 0));
    $append('quantity', (int) ($line['quantity'] ?? 1));
    $append('line_total', (float) ($line['line_total'] ?? 0));
    $append('desi', (int) ($line['desi'] ?? 1));

    $placeholder = implode(', ', array_fill(0, count($columns), '?'));
    db()->prepare(
        'INSERT INTO order_items (' . implode(', ', $columns) . ') VALUES (' . $placeholder . ')'
    )->execute($values);
}

if ($id === 'validate-coupon') {
    if ($method !== 'POST') error('Method not allowed.', 405);

    $code = strtoupper(trim((string) input('code', '')));
    $total = (float) input('total', input('subtotal', 0));
    if ($code === '') error('Kupon kodu gerekli.');

    $coupon = fetchCoupon($code);
    if (!$coupon) error('Kupon bulunamadi veya aktif degil.', 404);

    $minOrderAmount = (float) ($coupon['min_order_amount'] ?? ($coupon['min_order_total'] ?? 0));
    if ($total < $minOrderAmount) error('Minimum siparis tutari saglanmadi.');

    $normalized = normalizeCoupon($coupon, $total);
    ok([
        'coupon_id' => $coupon['id'] ?? null,
        'code' => $normalized['code'],
        'discount' => $normalized['discount'],
        'type' => $coupon['discount_type'] ?? ($coupon['type'] ?? 'fixed'),
        'value' => (float) ($coupon['value'] ?? 0),
    ]);
}

if ($id === 'track') {
    if ($method !== 'GET') error('Method not allowed.', 405);
    if (!$sub) error('Takip numarasi gerekli.');

    $query = trim((string) $sub);
    $compact = str_replace('-', '', strtoupper($query));
    $stmt = db()->prepare(
        'SELECT *
         FROM orders
         WHERE UPPER(REPLACE(id, "-", "")) = ?
            OR cargo_number = ?
         LIMIT 1'
    );
    $stmt->execute([$compact, $query]);
    $order = $stmt->fetch();
    if (!$order) error('Siparis bulunamadi.', 404);
    ok(legacyOrder($order));
}

if ($id === null && $method === 'GET') {
    adminRequired();
    $stmt = db()->query('SELECT * FROM orders ORDER BY created_at DESC');
    ok(array_map(fn(array $order) => legacyOrder($order), $stmt->fetchAll()));
}

if ($id === null && $method === 'POST') {
    $data = body();

    $payload = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $payload = jwtDecode(substr($authHeader, 7));
    }

    $shipping = is_array($data['shippingAddress'] ?? null) ? $data['shippingAddress'] : $data;
    $customerName = trim((string) ($shipping['fullname'] ?? $shipping['name'] ?? ''));
    $customerEmail = strtolower(trim((string) ($shipping['email'] ?? '')));
    $customerPhone = trim((string) ($shipping['phone'] ?? ''));
    $city = trim((string) ($shipping['city'] ?? ''));
    $district = trim((string) ($shipping['district'] ?? ''));
    $neighborhood = trim((string) ($shipping['neighborhood'] ?? ''));
    $street = trim((string) ($shipping['street'] ?? ''));
    $addressDetail = trim((string) ($shipping['address_detail'] ?? $shipping['addressDetail'] ?? ''));
    $orderNote = trim((string) ($data['orderNote'] ?? $data['order_note'] ?? ''));
    $couponCode = strtoupper(trim((string) ($data['couponCode'] ?? $data['coupon_code'] ?? '')));
    $giftWrap = !empty($data['giftWrap']) || !empty($data['gift_wrap']);
    $giftWrapCost = $giftWrap ? 25.0 : 0.0;
    $paymentMethod = (string) ($data['paymentMethod'] ?? $data['payment_method'] ?? 'card');
    $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];

    if ($customerName === '' || $customerEmail === '' || $city === '' || $district === '') {
        error('Teslimat bilgileri eksik.');
    }
    if ($rawItems === []) error('Siparis kalemleri bos.');

    $pdo = db();
    $orderId = generateOrderId();
    $pdo->beginTransaction();

    try {
        $items = StockService::reserveOrderItems($rawItems);
        $subtotal = (float) array_sum(array_map(fn(array $line) => (float) ($line['line_total'] ?? 0), $items));

        $discount = 0.0;
        if ($couponCode !== '') {
            $coupon = fetchCoupon($couponCode);
            if ($coupon) {
                $minOrderAmount = (float) ($coupon['min_order_amount'] ?? ($coupon['min_order_total'] ?? 0));
                if ($subtotal < $minOrderAmount) {
                    throw new RuntimeException('Minimum siparis tutari saglanmadi.');
                }
                $discount = normalizeCoupon($coupon, $subtotal)['discount'];
            }
        }

        $totalDesi = 0.0;
        foreach ($items as $line) {
            $totalDesi += max(1, (float) ($line['desi'] ?? 1)) * (int) ($line['quantity'] ?? 1);
        }
        $shippingCalculation = calculateShippingFeeByDesi(
            $totalDesi,
            max(0.0, $subtotal - $discount),
            isset($data['shipping_group_id']) ? (int) $data['shipping_group_id'] : null
        );
        $shippingFee = (float) ($shippingCalculation['fee'] ?? 0);

        $shippingAddress = [
            'fullname' => $customerName,
            'email' => $customerEmail,
            'phone' => $customerPhone,
            'city' => $city,
            'district' => $district,
            'neighborhood' => $neighborhood,
            'street' => $street,
            'address_detail' => $addressDetail,
        ];

        $total = max(0.0, $subtotal - $discount) + $shippingFee + $giftWrapCost;

        $insertOrder = $pdo->prepare(
            'INSERT INTO orders
             (id, user_id, shipping_address, subtotal, shipping_cost, discount, gift_wrap_cost, total, coupon_code, gift_wrap, order_note, payment_method, payment_status, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insertOrder->execute([
            $orderId,
            $payload['sub'] ?? null,
            json_encode($shippingAddress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $subtotal,
            $shippingFee,
            $discount,
            $giftWrapCost,
            $total,
            $couponCode !== '' ? $couponCode : null,
            $giftWrap ? 1 : 0,
            $orderNote !== '' ? $orderNote : null,
            $paymentMethod,
            'pending',
            StockService::resolveStatus(['pending'], 'pending'),
        ]);

        foreach ($items as $line) {
            orderInsertItem($orderId, $line);
        }

        StockService::setOrderStockState($orderId, 'reserved');

        if ($couponCode !== '') {
            $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE code = ?')->execute([$couponCode]);
        }

        $pdo->commit();

        $response = [
            'id' => $orderId,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping_fee' => $shippingFee,
            'shipping_group' => $shippingCalculation['group'] ?? null,
            'gift_wrap_cost' => $giftWrapCost,
            'total' => $total,
            'status' => 'pending',
            'stock_state' => 'reserved',
            'shippingAddress' => $shippingAddress,
            'items' => array_map(static fn(array $line) => [
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'product_name' => $line['product_name'],
                'variant_name' => $line['variant_name'],
                'variant_color' => $line['variant_color'],
                'sku' => $line['sku'],
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'line_total' => $line['line_total'],
            ], $items),
        ];

        MailService::sendOrderReceivedEmail($customerEmail, $customerName, $response);

        if ($paymentMethod === 'card') {
            $payment = PaytrService::createPayment(['id' => $orderId, 'total' => $total], $items, $shippingAddress);
            $response['payment'] = $payment;
        }

        ok($response);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error($e->getMessage());
    }
}

if ($id !== null && $sub === 'status') {
    if ($method !== 'PATCH') error('Method not allowed.', 405);
    adminRequired();

    $status = (string) input('status', '');
    if (!orderStatusSupported($status)) {
        error('Gecersiz durum.');
    }

    $stmt = db()->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    if ($stmt->rowCount() === 0) error('Siparis bulunamadi.', 404);

    orderApplyStockTransition((string) $id, $status, null);

    $fetch = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $fetch->execute([$id]);
    ok(legacyOrder($fetch->fetch() ?: ['id' => $id, 'status' => $status]));
}

if ($id !== null && $sub === 'cargo') {
    if ($method !== 'PATCH') error('Method not allowed.', 405);
    adminRequired();

    $tracking = trim((string) input('tracking_no', input('cargoNumber', input('cargo_number', ''))));
    $carrier = trim((string) input('cargo_carrier', input('cargoCompany', input('cargo_company', ''))));
    if ($tracking === '') error('Takip numarasi gerekli.');

    $targetStatus = StockService::resolveStatus(['shipped', 'processing', 'confirmed'], 'pending');
    $stmt = db()->prepare(
        'UPDATE orders SET cargo_number = ?, cargo_company = ?, status = ? WHERE id = ?'
    );
    $stmt->execute([$tracking, $carrier !== '' ? $carrier : null, $targetStatus, $id]);
    if ($stmt->rowCount() === 0) error('Siparis bulunamadi.', 404);

    orderApplyStockTransition((string) $id, $targetStatus, null);

    $fetch = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $fetch->execute([$id]);
    ok(legacyOrder($fetch->fetch() ?: ['id' => $id, 'cargo_number' => $tracking, 'cargo_company' => $carrier, 'status' => $targetStatus]));
}

if ($id !== null && $sub === null && $method === 'GET') {
    $payload = authRequired();

    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) error('Siparis bulunamadi.', 404);

    if (($payload['role'] ?? '') !== 'admin' && (string) ($order['user_id'] ?? '') !== (string) ($payload['sub'] ?? '')) {
        error('Bu siparise erisim yetkiniz yok.', 403);
    }

    $items = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $items->execute([$id]);
    $order['items'] = $items->fetchAll();

    ok(legacyOrder($order));
}

error('Siparis endpoint bulunamadi.', 404);
