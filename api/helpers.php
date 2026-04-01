<?php

function env(string $key, mixed $default = null): mixed
{
    return defined($key) ? constant($key) : $default;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}

function json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(mixed $data = null, string $message = 'success'): never
{
    if ($data === null) {
        json(['success' => true, 'message' => $message]);
    }

    json($data);
}

function error(string $message, int $status = 400): never
{
    json(['success' => false, 'message' => $message], $status);
}

function body(): array
{
    static $cache = null;
    if ($cache === null) {
        $raw = file_get_contents('php://input');
        $cache = json_decode($raw, true) ?? [];
    }
    return $cache;
}

function input(string $key, mixed $default = null): mixed
{
    $data = body();
    return $data[$key] ?? $default;
}

function jwtEncode(array $payload): string
{
    $header = base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRE;
    $body = base64url(json_encode($payload));
    $sig = base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function jwtDecode(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $body, $sig] = $parts;
    $expected = base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!$payload || ($payload['exp'] ?? 0) < time()) {
        return null;
    }

    return $payload;
}

function base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function authRequired(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '')
        ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        error('Token gerekli.', 401);
    }

    $payload = jwtDecode(substr($header, 7));
    if (!$payload) {
        error('Token gecersiz veya suresi dolmus.', 401);
    }

    return $payload;
}

function adminRequired(): array
{
    $payload = authRequired();
    if (($payload['role'] ?? '') !== 'admin') {
        error('Yetkiniz yok.', 403);
    }

    return $payload;
}

function slugify(string $value): string
{
    $map = [
        'ş' => 's',
        'Ş' => 'S',
        'ı' => 'i',
        'İ' => 'I',
        'ğ' => 'g',
        'Ğ' => 'G',
        'ü' => 'u',
        'Ü' => 'U',
        'ö' => 'o',
        'Ö' => 'O',
        'ç' => 'c',
        'Ç' => 'C',
    ];

    $value = strtr($value, $map);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim($value, '-');
}

function normalizeImages(mixed $images): array
{
    if (is_string($images)) {
        $decoded = json_decode($images, true);
        $images = json_last_error() === JSON_ERROR_NONE ? $decoded : [$images];
    }

    if (!is_array($images)) {
        return [];
    }

    $normalized = [];
    foreach ($images as $image) {
        if (is_string($image) && $image !== '') {
            $normalized[] = $image;
            continue;
        }

        if (is_array($image)) {
            foreach (['url', 'original', 'medium', 'thumb', 'src'] as $key) {
                if (!empty($image[$key]) && is_string($image[$key])) {
                    $normalized[] = $image[$key];
                    break;
                }
            }
        }
    }

    return array_values(array_unique($normalized));
}

function inferPiecesFromProduct(array $product): int
{
    $rawPieces = $product['pieces'] ?? null;
    $pieces = is_numeric($rawPieces) ? (int) $rawPieces : 0;
    if ($pieces > 0) {
        return $pieces;
    }

    $title = (string) ($product['name'] ?? ($product['title'] ?? ''));
    if ($title === '') {
        return 0;
    }

    if (
        preg_match('/\(([\d.]+)\s*Par[çc]a\)/iu', $title, $match) !== 1
        && preg_match('/([\d.]+)\s*Par[çc]a/iu', $title, $match) !== 1
    ) {
        return 0;
    }

    return (int) str_replace('.', '', $match[1]);
}

function normalizeProductConditionTag(mixed $value, string $default = '2. El Sıfır'): string
{
    $defaultNormalized = strtolower(trim((string) $default));
    $fallback = in_array($defaultNormalized, ['new', '0', 'zero', 'sifir', 'sifirurun', 'unused', 'sealed', '2. el sıfır', '2.el sıfır'], true)
        ? '2. El Sıfır'
        : '2. el';
    if ($value === null) {
        return $fallback;
    }

    $normalized = strtolower(trim((string) $value));
    if ($normalized === '') {
        return $fallback;
    }

    $map = [
        'new' => '2. El Sıfır',
        '0' => '2. El Sıfır',
        'zero' => '2. El Sıfır',
        'sifir' => '2. El Sıfır',
        'sifirurun' => '2. El Sıfır',
        'unused' => '2. El Sıfır',
        'sealed' => '2. El Sıfır',
        '2. el sıfır' => '2. El Sıfır',
        '2. el sifir' => '2. El Sıfır',
        '2.elsıfır' => '2. El Sıfır',
        '2.elsifir' => '2. El Sıfır',
        '2elsıfır' => '2. El Sıfır',
        '2elsifir' => '2. El Sıfır',
        'used' => '2. el',
        '2' => '2. el',
        '2el' => '2. el',
        '2.el' => '2. el',
        '2. el' => '2. el',
        'secondhand' => '2. el',
        'second-hand' => '2. el',
        'ikinciel' => '2. el',
        'ikinci el' => '2. el',
        'mint' => '2. el',
        'excellent' => '2. el',
        'good' => '2. el',
        'fair' => '2. el',
    ];

    return $map[$normalized] ?? $fallback;
}

function productConditionLabel(string $conditionTag): string
{
    return normalizeProductConditionTag($conditionTag);
}

function productConditionDisplayLabel(string $conditionTag): string
{
    return normalizeProductConditionTag($conditionTag);
}

function legacyProduct(array $product): array
{
    $images = normalizeImages($product['images'] ?? []);
    if ($images === [] && !empty($product['img']) && is_string($product['img'])) {
        $images = [$product['img']];
    }
    // product_images tablosundaki vitrin görseli öncelikli
    $primaryImg = (isset($product['primary_image_url']) && $product['primary_image_url'] !== '')
        ? $product['primary_image_url']
        : null;
    $image = $primaryImg ?? ($product['img'] ?? ($images[0] ?? '/fallback.png'));
    $stock = isset($product['stock']) ? (int) $product['stock'] : 0;
    $reservedStock = isset($product['reserved_stock']) ? max(0, (int) $product['reserved_stock']) : 0;
    $availableStock = max(0, $stock - $reservedStock);
    $isActive = isset($product['is_active']) ? (int) $product['is_active'] : 1;
    $pieces = inferPiecesFromProduct($product);
    $conditionTag = normalizeProductConditionTag(
        $product['product_condition']
        ?? ($product['condition_tag'] ?? ($product['conditionTag'] ?? null)),
        '2. El Sıfır'
    );
    $conditionLabel = productConditionDisplayLabel($conditionTag);

    return [
        ...$product,
        'title' => $product['name'] ?? ($product['title'] ?? ''),
        'img' => $image,
        'images' => $images,
        'category' => $product['category_name'] ?? ($product['category'] ?? null),
        'brand' => $product['brand_name'] ?? ($product['brand'] ?? null),
        'categoryId' => $product['category_id'] ?? ($product['categoryId'] ?? null),
        'brandId' => $product['brand_id'] ?? ($product['brandId'] ?? null),
        'desi' => isset($product['desi']) ? (float) $product['desi'] : 1.0,
        'fixed_shipping_fee' => array_key_exists('fixed_shipping_fee', $product) && $product['fixed_shipping_fee'] !== null
            ? (float) $product['fixed_shipping_fee']
            : null,
        'pieces' => $pieces,
        'price' => isset($product['price']) ? (float) $product['price'] : 0.0,
        'oldPrice' => isset($product['old_price']) ? (float) $product['old_price'] : (isset($product['oldPrice']) ? (float) $product['oldPrice'] : 0.0),
        'product_condition' => $conditionTag,
        'condition_tag' => $conditionTag,
        'conditionTag' => $conditionTag,
        'condition_label' => $conditionLabel,
        'conditionLabel' => $conditionLabel,
        'reserved_stock' => $reservedStock,
        'available_stock' => $availableStock,
        'isActive' => $isActive === 1,
        'in_stock' => $isActive === 1 && $availableStock > 0,
        'stock_status' => ($isActive === 1 && $availableStock > 0) ? 'in_stock' : 'out_of_stock',
    ];
}

function legacyOrder(array $order): array
{
    $shipping = [];
    if (!empty($order['shipping_address']) && is_string($order['shipping_address'])) {
        $decoded = json_decode($order['shipping_address'], true);
        if (is_array($decoded)) {
            $shipping = $decoded;
        }
    }

    $items = [];
    if (!empty($order['items']) && is_array($order['items'])) {
        $items = array_map('legacyOrderItem', $order['items']);
    }

    $status = (string) ($order['status'] ?? ($order['payment_status'] ?? 'pending'));
    $adminStatus = orderAdminDisplayMeta($order);

    return [
        ...$order,
        'id' => (string) ($order['id'] ?? ''),
        'date' => $order['created_at'] ?? ($order['date'] ?? null),
        'createdAt' => $order['created_at'] ?? ($order['createdAt'] ?? null),
        'cargo_number' => $order['tracking_no'] ?? ($order['cargo_number'] ?? null),
        'cargo_company' => $order['cargo_carrier'] ?? ($order['cargo_company'] ?? null),
        'cargoNumber' => $order['tracking_no'] ?? ($order['cargo_number'] ?? ($order['cargoNumber'] ?? null)),
        'cargoCompany' => $order['cargo_carrier'] ?? ($order['cargo_company'] ?? ($order['cargoCompany'] ?? null)),
        'order_note' => $order['order_note'] ?? ($order['orderNote'] ?? null),
        'shippingAddress' => [
            'fullname' => $order['fullname'] ?? ($shipping['fullname'] ?? null),
            'email' => $order['email'] ?? ($shipping['email'] ?? null),
            'phone' => $order['phone'] ?? ($shipping['phone'] ?? null),
            'city' => $order['city'] ?? ($shipping['city'] ?? null),
            'district' => $order['district'] ?? ($shipping['district'] ?? null),
            'neighborhood' => $order['neighborhood'] ?? ($shipping['neighborhood'] ?? null),
            'street' => $order['street'] ?? ($shipping['street'] ?? null),
            'address_detail' => $order['address_detail'] ?? ($shipping['address_detail'] ?? null),
        ],
        'total' => isset($order['total']) ? (float) $order['total'] : 0.0,
        'subtotal' => isset($order['subtotal']) ? (float) $order['subtotal'] : 0.0,
        'discount' => isset($order['discount']) ? (float) $order['discount'] : 0.0,
        'status_label' => orderStatusDisplayLabel($status),
        'statusLabel' => orderStatusDisplayLabel($status),
        'admin_status_key' => $adminStatus['key'],
        'adminStatusKey' => $adminStatus['key'],
        'admin_status_label' => $adminStatus['label'],
        'adminStatusLabel' => $adminStatus['label'],
        'admin_status_tone' => $adminStatus['tone'],
        'adminStatusTone' => $adminStatus['tone'],
        'items' => $items,
    ];
}

function orderStatusDisplayLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'processing', 'preparing' => "Haz\u{0131}rlan\u{0131}yor",
        'confirmed' => "Onayland\u{0131}",
        'paid' => "\u{00D6}deme Yap\u{0131}ld\u{0131}",
        'shipped' => 'Kargoya Verildi',
        'delivered' => 'Teslim Edildi',
        'cancelled' => "\u{0130}ptal Edildi",
        'failed' => "Sipari\u{015F} Verilemedi",
        default => 'Beklemede',
    };
}

function orderAdminDisplayMeta(array $order): array
{
    $status = strtolower(trim((string) ($order['status'] ?? 'pending')));
    $paymentStatus = strtolower(trim((string) ($order['payment_status'] ?? '')));
    $paymentMethod = strtolower(trim((string) ($order['payment_method'] ?? '')));
    $stockState = strtolower(trim((string) ($order['stock_state'] ?? 'none')));

    if ($paymentStatus === 'failed' || $status === 'failed') {
        return [
            'key' => 'failed',
            'label' => "Sipari\u{015F} Verilemedi",
            'tone' => 'error',
        ];
    }

    if ($paymentMethod === 'card' && $paymentStatus === 'pending' && $stockState === 'reserved') {
        return [
            'key' => 'payment_received',
            'label' => "\u{00D6}deme Yap\u{0131}ld\u{0131}",
            'tone' => 'success',
        ];
    }

    $displayStatus = $status !== '' ? $status : ($paymentStatus !== '' ? $paymentStatus : 'pending');
    $tone = in_array($displayStatus, ['paid', 'confirmed', 'processing', 'preparing', 'shipped', 'delivered'], true)
        ? 'success'
        : ($displayStatus === 'cancelled' ? 'error' : 'neutral');

    return [
        'key' => $displayStatus,
        'label' => orderStatusDisplayLabel($displayStatus),
        'tone' => $tone,
    ];
}

function legacyOrderItem(array $item): array
{
    $name = (string) ($item['product_name'] ?? ($item['title'] ?? ($item['name'] ?? '')));
    $image = (string) ($item['product_img'] ?? ($item['product_image'] ?? ($item['img'] ?? ($item['image'] ?? ''))));
    $qty = (int) ($item['quantity'] ?? 1);
    $price = isset($item['unit_price']) ? (float) $item['unit_price'] : (float) ($item['price'] ?? 0);
    $lineTotal = isset($item['line_total']) ? (float) $item['line_total'] : ($price * max(1, $qty));

    return [
        ...$item,
        'id' => (string) ($item['id'] ?? ''),
        'product_name' => $name,
        'title' => $name,
        'name' => $name,
        'product_img' => $image,
        'product_image' => $image,
        'img' => $image,
        'image' => $image,
        'quantity' => $qty,
        'price' => $price,
        'unit_price' => $price,
        'line_total' => $lineTotal,
        'lineTotal' => $lineTotal,
    ];
}

function legacyUser(array $user): array
{
    return [
        ...$user,
        'name' => $user['display_name'] ?? ($user['name'] ?? ($user['username'] ?? '')),
        'isAdmin' => ($user['role'] ?? '') === 'admin',
        'orders' => $user['orders'] ?? [],
    ];
}

function tableHasColumn(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);

    $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$key];
}

function tableColumnType(string $table, string $column): ?string
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare(
        'SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);

    $value = $stmt->fetchColumn();
    $cache[$key] = is_string($value) ? strtolower($value) : null;
    return $cache[$key];
}

function generatePublicId(int $bytes = 16): string
{
    return strtolower(bin2hex(random_bytes($bytes)));
}

function tableExists(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);

    $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$table];
}

function ensureShippingGroupsSchema(): void
{
    if (!tableExists('shipping_groups')) {
        return;
    }

    if (!tableHasColumn('shipping_groups', 'min_desi')) {
        db()->exec('ALTER TABLE shipping_groups ADD COLUMN min_desi INT UNSIGNED NOT NULL DEFAULT 1 AFTER carrier');
    }
    if (!tableHasColumn('shipping_groups', 'max_desi')) {
        db()->exec('ALTER TABLE shipping_groups ADD COLUMN max_desi INT UNSIGNED NULL AFTER min_desi');
    }

    $rows = db()->query('SELECT id, min_desi, max_desi FROM shipping_groups ORDER BY id ASC')->fetchAll();
    if ($rows === []) {
        $stmt = db()->prepare(
            'INSERT INTO shipping_groups (name, carrier, min_desi, max_desi, base_fee, free_above, is_active)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach (defaultShippingGroups() as $group) {
            $stmt->execute([
                $group['name'],
                $group['carrier'],
                $group['min_desi'],
                $group['max_desi'],
                $group['base_fee'],
                $group['free_above'],
                $group['is_active'],
            ]);
        }
        return;
    }

    $hasUsefulRange = false;
    foreach ($rows as $row) {
        if ((int) ($row['min_desi'] ?? 0) > 1 || $row['max_desi'] !== null) {
            $hasUsefulRange = true;
            break;
        }
    }

    if ($hasUsefulRange) {
        dedupeShippingGroups();
        return;
    }

    $defaults = defaultShippingGroups();
    $update = db()->prepare(
        'UPDATE shipping_groups
         SET name = ?, carrier = ?, min_desi = ?, max_desi = ?, base_fee = ?, free_above = ?, is_active = ?
         WHERE id = ?'
    );

    foreach ($rows as $index => $row) {
        $group = $defaults[$index] ?? end($defaults);
        $update->execute([
            $group['name'],
            $group['carrier'],
            $group['min_desi'],
            $group['max_desi'],
            $group['base_fee'],
            $group['free_above'],
            $group['is_active'],
            $row['id'],
        ]);
    }

    if (count($rows) < count($defaults)) {
        $insert = db()->prepare(
            'INSERT INTO shipping_groups (name, carrier, min_desi, max_desi, base_fee, free_above, is_active)
             VALUES (?,?,?,?,?,?,?)'
        );
        for ($i = count($rows); $i < count($defaults); $i++) {
            $group = $defaults[$i];
            $insert->execute([
                $group['name'],
                $group['carrier'],
                $group['min_desi'],
                $group['max_desi'],
                $group['base_fee'],
                $group['free_above'],
                $group['is_active'],
            ]);
        }
    }

    dedupeShippingGroups();
}

function dedupeShippingGroups(): void
{
    if (!tableExists('shipping_groups')) {
        return;
    }

    $rows = db()->query(
        'SELECT id, carrier, min_desi, max_desi
         FROM shipping_groups
         ORDER BY id ASC'
    )->fetchAll();

    $seen = [];
    $deleteIds = [];
    foreach ($rows as $row) {
        $key = implode('|', [
            strtolower((string) ($row['carrier'] ?? '')),
            (int) ($row['min_desi'] ?? 0),
            $row['max_desi'] === null ? 'null' : (int) $row['max_desi'],
        ]);

        if (isset($seen[$key])) {
            $deleteIds[] = (int) $row['id'];
            continue;
        }

        $seen[$key] = true;
    }

    if ($deleteIds === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
    db()->prepare("DELETE FROM shipping_groups WHERE id IN ($placeholders)")->execute($deleteIds);
}

function freeShippingThreshold(): float
{
    $value = (float) env('FREE_SHIPPING_THRESHOLD', 2500);
    return $value > 0 ? round($value, 2) : 2500.0;
}

function defaultShippingGroups(): array
{
    $freeAbove = freeShippingThreshold();

    return [
        ['name' => 'DHL Express 1-2 Desi', 'carrier' => 'DHL Express', 'min_desi' => 1, 'max_desi' => 2, 'base_fee' => 79.90, 'free_above' => $freeAbove, 'is_active' => 1],
        ['name' => 'DHL Express 3-5 Desi', 'carrier' => 'DHL Express', 'min_desi' => 3, 'max_desi' => 5, 'base_fee' => 119.90, 'free_above' => $freeAbove, 'is_active' => 1],
        ['name' => 'DHL Express 6-9 Desi', 'carrier' => 'DHL Express', 'min_desi' => 6, 'max_desi' => 9, 'base_fee' => 159.90, 'free_above' => $freeAbove, 'is_active' => 1],
        ['name' => 'DHL Express 10-14 Desi', 'carrier' => 'DHL Express', 'min_desi' => 10, 'max_desi' => 14, 'base_fee' => 219.90, 'free_above' => $freeAbove, 'is_active' => 1],
        ['name' => 'DHL Express 15+ Desi', 'carrier' => 'DHL Express', 'min_desi' => 15, 'max_desi' => null, 'base_fee' => 279.90, 'free_above' => $freeAbove, 'is_active' => 1],
    ];
}

function normalizeShippingGroup(array $row): array
{
    $price = isset($row['price']) ? (float) $row['price'] : (float) ($row['base_fee'] ?? 0);

    return [
        ...$row,
        'carrier' => $row['carrier'] ?? 'DHL Express',
        'min_desi' => max(1, (int) ($row['min_desi'] ?? 1)),
        'max_desi' => isset($row['max_desi']) && $row['max_desi'] !== null ? (int) $row['max_desi'] : null,
        'base_fee' => $price,
        'price' => $price,
        'is_active' => isset($row['is_active']) ? (int) $row['is_active'] : 1,
    ];
}

function calculateShippingFeeByDesi(float $totalDesi, float $orderTotal = 0, ?int $groupId = null): array
{
    ensureShippingGroupsSchema();
    // Desi ve tutar validasyonu
    $totalDesi = max(0.0, min($totalDesi, 9999.0));
    $orderTotal = max(0.0, $orderTotal);
    $subtotal = round(max(0.0, $orderTotal), 2);
    $freeShippingThreshold = freeShippingThreshold();

    if (!tableExists('shipping_groups')) {
        $fallback = $subtotal >= $freeShippingThreshold
            ? 0.0
            : ($totalDesi <= 0 ? 0.0 : max(50.0, ceil($totalDesi) * 12.5));

        return [
            'cost' => $fallback,
            'fee' => $fallback,
            'shipping_fee' => $fallback,
            'subtotal' => $subtotal,
            'total' => round($subtotal + $fallback, 2),
            'carrier' => 'DHL Express',
            'group_name' => null,
            'group_id' => null
        ];
    }

    $params = [];
    $sql = 'SELECT id, name, carrier, min_desi, max_desi, base_fee, free_above, is_active
            FROM shipping_groups
            WHERE is_active = 1';

    if ($groupId !== null && $groupId > 0) {
        $sql .= ' AND id = ?';
        $params[] = $groupId;
    } else {
        $sql .= ' AND min_desi <= ? AND (max_desi IS NULL OR max_desi >= ?)';
        $params[] = max(1, (int) ceil($totalDesi));
        $params[] = max(1, (int) ceil($totalDesi));
    }

    $sql .= ' ORDER BY min_desi ASC LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $group = $stmt->fetch();

    if (!$group && $groupId === null) {
        $stmt = db()->query(
            'SELECT id, name, carrier, min_desi, max_desi, base_fee, free_above, is_active
             FROM shipping_groups
             WHERE is_active = 1
             ORDER BY COALESCE(max_desi, 999999) DESC, min_desi DESC
             LIMIT 1'
        );
        $group = $stmt->fetch();
    }

    if (!$group) {
        $fallback = $subtotal >= $freeShippingThreshold
            ? 0.0
            : ($totalDesi <= 0 ? 0.0 : max(50.0, ceil($totalDesi) * 12.5));

        return [
            'cost' => $fallback,
            'fee' => $fallback,
            'shipping_fee' => $fallback,
            'subtotal' => $subtotal,
            'total' => round($subtotal + $fallback, 2),
            'carrier' => 'DHL Express',
            'group_name' => null,
            'group_id' => null
        ];
    }

    $group = normalizeShippingGroup($group);
    $cost = (
        $subtotal >= $freeShippingThreshold ||
        ($group['free_above'] !== null && $subtotal >= (float) $group['free_above'])
    )
        ? 0.0
        : (float) $group['base_fee'];

    return [
        'cost' => $cost,
        'fee' => $cost,
        'shipping_fee' => $cost,
        'subtotal' => $subtotal,
        'total' => round($subtotal + $cost, 2),
        'carrier' => $group['carrier'],
        'group_name' => $group['name'],
        'group_id' => $group['id'] ?? null,
        'group' => $group,
    ];
}

function cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $hostOrigin = isset($_SERVER['HTTP_HOST']) ? $scheme . '://' . $_SERVER['HTTP_HOST'] : '';

    $allowed = array_filter([
        ALLOWED_ORIGIN,
        'https://www.boomeritems.com',  # www subdomain
        'https://boomeritems.com',       # non-www domain
        $hostOrigin,
        'http://localhost:5173',
        'http://localhost:3000',
        'http://localhost',
    ]);

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        header('Access-Control-Allow-Origin: ' . ($hostOrigin !== '' ? $hostOrigin : ALLOWED_ORIGIN));
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-ID');
    header('Access-Control-Allow-Credentials: true');
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
