<?php

StockService::ensureSchema();

// Otomatik stok temizleme: terk edilen siparişleri temizle
// %5 ihtimalle 2 saatlik esikle calisir
if (random_int(1, 20) === 1) {
    try { StockService::releaseAbandonedReservations(2); } catch (Throwable) {}
}

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

// POST /orders/release-abandoned — admin: terk edilen siparis stoklarini serbest birak
if ($id === 'release-abandoned' && $method === 'POST') {
    adminRequired();
    $hours = max(0, (int) input('hours', 0));
    $released = StockService::releaseAbandonedReservations($hours);
    ok([
        'success' => true,
        'released_count' => $released,
        'threshold_hours' => $hours,
        'message' => $released > 0
            ? "{$released} terk edilmis siparisin stok rezervasyonu serbest birakildi."
            : 'Serbest birakilacak terk edilmis siparis bulunamadi.',
    ]);
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

    RateLimit::check('order_track_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 300);

    $query = trim((string) $sub);
    if (mb_strlen($query) < 5 || mb_strlen($query) > 50) {
        error('Takip numarasi 5-50 karakter arasinda olmali.');
    }

    $compact = str_replace('-', '', strtoupper($query));
    $stmt = db()->prepare(
        'SELECT id, status, total, subtotal, discount, shipping_cost,
                cargo_number, cargo_company, tracking_no, cargo_carrier,
                created_at, updated_at, payment_status
         FROM orders
         WHERE UPPER(REPLACE(id, "-", "")) = ?
            OR cargo_number = ?
         LIMIT 1'
    );
    $stmt->execute([$compact, $query]);
    $order = $stmt->fetch();
    if (!$order) error('Siparis bulunamadi.', 404);

    // Takip sayfası: sadece sipariş durumu, kargo bilgisi ve toplam göster
    // Kişisel bilgiler (email, telefon, adres) paylaşılmaz
    ok([
        'id' => (string) ($order['id'] ?? ''),
        'status' => $order['status'] ?? 'pending',
        'payment_status' => $order['payment_status'] ?? null,
        'total' => isset($order['total']) ? (float) $order['total'] : 0.0,
        'subtotal' => isset($order['subtotal']) ? (float) $order['subtotal'] : 0.0,
        'discount' => isset($order['discount']) ? (float) $order['discount'] : 0.0,
        'shipping_cost' => isset($order['shipping_cost']) ? (float) $order['shipping_cost'] : 0.0,
        'cargo_number' => $order['tracking_no'] ?? ($order['cargo_number'] ?? null),
        'cargo_company' => $order['cargo_carrier'] ?? ($order['cargo_company'] ?? null),
        'cargoNumber' => $order['tracking_no'] ?? ($order['cargo_number'] ?? null),
        'cargoCompany' => $order['cargo_carrier'] ?? ($order['cargo_company'] ?? null),
        'date' => $order['created_at'] ?? null,
        'createdAt' => $order['created_at'] ?? null,
    ]);
}

if ($id === null && $method === 'GET') {
    adminRequired();
    $stmt = db()->query('SELECT * FROM orders ORDER BY created_at DESC');
    ok(array_map(fn(array $order) => legacyOrder($order), $stmt->fetchAll()));
}

// POST /orders/retry-payment — başarısız ödeme yeniden deneme
if ($id === 'retry-payment' && $method === 'POST') {
    $data = body();
    $orderId = trim((string) ($data['order_id'] ?? ''));
    if ($orderId === '') error('Siparis ID gerekli.');

    // Sipariş ID format kontrolü (BM + 10 hex karakter)
    if (!preg_match('/^BM[0-9A-Fa-f]{10}$/', $orderId)) {
        error('Gecersiz siparis ID formati.');
    }

    $authPayload = Auth::optional();

    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) error('Siparis bulunamadi.', 404);

    // Sahiplik kontrolü — her durumda yetki denetlenir
    if ($order['user_id'] !== null) {
        // Siparişin bir sahibi var: giriş yapmış ve yetkili olmalı
        if ($authPayload === null) {
            error('Bu siparis icin giris yapmaniz gerekli.', 401);
        }
        $isAdmin = ($authPayload['role'] ?? '') === 'admin';
        $isOwner = (string) $order['user_id'] === (string) ($authPayload['sub'] ?? '');
        if (!$isAdmin && !$isOwner) {
            error('Bu siparise erisim yetkiniz yok.', 403);
        }
    }

    // Sadece başarısız ödemeler yeniden denenebilir
    if (!in_array($order['payment_status'] ?? '', ['pending', 'failed'], true)) {
        error('Bu siparisin odeme durumu yeniden denemeye uygun degil.');
    }

    // Stok durumu kontrol
    $stockState = $order['stock_state'] ?? 'none';
    if ($stockState === 'released') {
        error('Bu siparisin stok rezervasyonu serbest birakilmis. Lutfen yeni siparis olusturun.');
    }

    // Sipariş pending'e geri döndür
    db()->prepare('UPDATE orders SET payment_status = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute(['pending', 'pending', $orderId]);

    // Sipariş adresini al
    $shippingAddress = json_decode($order['shipping_address'] ?? '{}', true) ?: [];

    // Sipariş kalemlerini al
    $itemsStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll();

    // Yeni ödeme token'ı oluştur
    $payment = PaytrService::createPayment(
        ['id' => $orderId, 'total' => (float) ($order['total'] ?? 0)],
        $items,
        $shippingAddress
    );

    ok([
        'success' => true,
        'order_id' => $orderId,
        'payment' => $payment,
        'message' => 'Odeme yeniden baslatildi.',
    ]);
}

if ($id === null && $method === 'POST') {
    // Sipariş oluşturma rate limit: IP başına 5 dakikada max 10 sipariş
    RateLimit::check('order_create_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 300);

    $data = body();

    // Çift sipariş koruması: aynı kullanıcıdan 30 saniye içinde aynı sipariş engellenir
    $payload = Auth::optional();

    if ($payload !== null) {
        $recentOrder = db()->prepare(
            "SELECT id FROM orders WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1"
        );
        $recentOrder->execute([$payload['sub']]);
        if ($recentOrder->fetch()) {
            error('Cok hizli siparis veriyorsunuz. Lutfen 30 saniye bekleyiniz.');
        }
    }

    $shipping = is_array($data['shippingAddress'] ?? null) ? $data['shippingAddress'] : $data;

    // XSS koruması: tüm metin alanlarını sanitize et
    $sanitize = static fn(string $value): string => htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');

    $customerName = $sanitize((string) ($shipping['fullname'] ?? $shipping['name'] ?? ''));
    $customerEmail = strtolower(trim((string) ($shipping['email'] ?? '')));
    $customerPhone = trim((string) ($shipping['phone'] ?? ''));
    $city = $sanitize((string) ($shipping['city'] ?? ''));
    $district = $sanitize((string) ($shipping['district'] ?? ''));
    $neighborhood = $sanitize((string) ($shipping['neighborhood'] ?? ''));
    $street = $sanitize((string) ($shipping['street'] ?? ''));
    $addressDetail = $sanitize((string) ($shipping['address_detail'] ?? $shipping['addressDetail'] ?? ''));
    $orderNote = $sanitize((string) ($data['orderNote'] ?? $data['order_note'] ?? ''));
    $couponCode = strtoupper(trim((string) ($data['couponCode'] ?? $data['coupon_code'] ?? '')));
    $giftWrap = !empty($data['giftWrap']) || !empty($data['gift_wrap']);
    $giftWrapCost = $giftWrap ? 25.0 : 0.0;
    $paymentMethod = (string) ($data['paymentMethod'] ?? $data['payment_method'] ?? 'card');
    $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];

    // --- Input Validasyonları ---
    if ($customerName === '') {
        error('Ad soyad alani zorunludur.');
    }
    if ($customerEmail === '') {
        error('E-posta adresi zorunludur.');
    }
    if ($city === '') {
        error('Il alani zorunludur.');
    }
    if ($district === '') {
        error('Ilce alani zorunludur.');
    }
    if (mb_strlen($customerName) > 100) {
        error('Ad soyad en fazla 100 karakter olabilir.');
    }
    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        error('Gecerli bir e-posta adresi girin.');
    }
    if ($customerPhone === '') {
        error('Telefon numarasi zorunludur.');
    }
    if (!preg_match('/^[0-9\s\-\+\(\)]{7,20}$/', $customerPhone)) {
        error('Gecerli bir telefon numarasi girin. Ornek: 05XX XXX XX XX');
    }
    if ($addressDetail === '') {
        error('Adres detayi zorunludur. Cadde, sokak, bina ve daire bilgilerinizi yazin.');
    }
    if (mb_strlen($addressDetail) > 500) {
        error('Adres detayi en fazla 500 karakter olabilir.');
    }
    if (mb_strlen($neighborhood) > 100) {
        error('Mahalle alani en fazla 100 karakter olabilir.');
    }
    if (mb_strlen($street) > 200) {
        error('Sokak/cadde alani en fazla 200 karakter olabilir.');
    }
    if (mb_strlen($orderNote) > 1000) {
        error('Siparis notu en fazla 1000 karakter olabilir.');
    }
    if (mb_strlen($couponCode) > 50) {
        error('Kupon kodu en fazla 50 karakter olabilir.');
    }
    if (!in_array($paymentMethod, ['card', 'bank_transfer', 'cash_on_delivery'], true)) {
        error('Gecersiz odeme yontemi.');
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
            // Kupon race condition önlemi: SELECT FOR UPDATE ile kilitle
            $couponStmt = $pdo->prepare('SELECT * FROM coupons WHERE code = ? AND is_active = 1 LIMIT 1 FOR UPDATE');
            $couponStmt->execute([strtoupper($couponCode)]);
            $coupon = $couponStmt->fetch();
            if ($coupon) {
                if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at']) < time()) {
                    throw new RuntimeException('Kuponun suresi dolmus.');
                }
                $usageLimit = $coupon['usage_limit'] ?? null;
                $usedCount = (int) ($coupon['used_count'] ?? 0);
                if ($usageLimit !== null && $usedCount >= (int) $usageLimit) {
                    throw new RuntimeException('Kupon limitine ulasildi.');
                }
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
        // Kargo grubu otomatik hesaplanır, kullanıcı girdisi kabul edilmez
        $shippingCalculation = calculateShippingFeeByDesi(
            $totalDesi,
            max(0.0, $subtotal - $discount),
            null
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
    $updatedOrder = $fetch->fetch() ?: ['id' => $id, 'status' => $status];

    // Müşteriye durum değişikliği maili gönder
    $shAddr = json_decode($updatedOrder['shipping_address'] ?? '{}', true) ?: [];
    $custEmail = trim((string) ($shAddr['email'] ?? ''));
    $custName = trim((string) ($shAddr['fullname'] ?? ''));
    if ($custEmail !== '' && filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
        MailService::sendOrderStatusEmail($custEmail, $custName, (string) $id, $status);
    }

    ok(legacyOrder($updatedOrder));
}

if ($id !== null && $sub === 'cargo') {
    if ($method !== 'PATCH') error('Method not allowed.', 405);
    adminRequired();

    $tracking = trim((string) input('tracking_no', input('cargoNumber', input('cargo_number', ''))));
    $carrier = trim((string) input('cargo_carrier', input('cargoCompany', input('cargo_company', ''))));
    if ($tracking === '') error('Takip numarasi gerekli.');

    $targetStatus = StockService::resolveStatus(['shipped', 'processing', 'confirmed'], 'pending');

    $setParts = [];
    $values = [];

    if (tableHasColumn('orders', 'cargo_number')) {
        $setParts[] = 'cargo_number = ?';
        $values[] = $tracking;
    }
    if (tableHasColumn('orders', 'tracking_no')) {
        $setParts[] = 'tracking_no = ?';
        $values[] = $tracking;
    }
    if (tableHasColumn('orders', 'cargo_company')) {
        $setParts[] = 'cargo_company = ?';
        $values[] = $carrier !== '' ? $carrier : null;
    }
    if (tableHasColumn('orders', 'cargo_carrier')) {
        $setParts[] = 'cargo_carrier = ?';
        $values[] = $carrier !== '' ? $carrier : null;
    }

    $setParts[] = 'status = ?';
    $values[] = $targetStatus;
    $values[] = $id;

    $exists = db()->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
    $exists->execute([$id]);
    if (!$exists->fetch()) error('Siparis bulunamadi.', 404);

    $stmt = db()->prepare(
        'UPDATE orders SET ' . implode(', ', $setParts) . ' WHERE id = ?'
    );
    $stmt->execute($values);

    orderApplyStockTransition((string) $id, $targetStatus, null);

    $fetch = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $fetch->execute([$id]);
    $updatedOrder = $fetch->fetch() ?: ['id' => $id, 'cargo_number' => $tracking, 'cargo_company' => $carrier, 'status' => $targetStatus];

    // Müşteriye kargo bilgisi maili gönder
    $shAddr = json_decode($updatedOrder['shipping_address'] ?? '{}', true) ?: [];
    $custEmail = trim((string) ($shAddr['email'] ?? ''));
    $custName = trim((string) ($shAddr['fullname'] ?? ''));
    if ($custEmail !== '' && filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
        MailService::sendCargoEmail($custEmail, $custName, (string) $id, $tracking, $carrier !== '' ? $carrier : null);
    }

    ok(legacyOrder($updatedOrder));
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
