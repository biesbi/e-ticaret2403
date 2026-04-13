<?php
// ═══════════════════════════════════════════════
//  POST   /upload/product-image        — Görsel yükle
//  DELETE /upload/product-image/{id}   — Görsel sil
//  GET    /upload/product-image/{id}   — Ürünün görsellerini listele
//  PATCH  /upload/product-image/{id}   — Alt text / primary güncelle
// ═══════════════════════════════════════════════

require_once __DIR__ . '/../services/UploadService.php';

// $id = 'product-image' | numeric
// $sub = image_id | null

// ─── POST /upload/product-image ──────────────
if ($id === 'product-image' && $sub === null && $method === 'POST') {
    $admin = Auth::requireProductManager();

    $productId = trim((string) ($_POST['product_id'] ?? ''));
    $isPrimary = filter_var($_POST['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($productId === '') error('Geçerli bir product_id gönderin.');

    // Ürün var mı?
    $pChk = db()->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $pChk->execute([$productId]);
    if (!$pChk->fetch()) error('Ürün bulunamadı.', 404);

    // Dosya geldi mi?
    if (empty($_FILES['image'])) error('Dosya alanı "image" adıyla gönderilmeli.');

    // Çoklu yükleme desteği: $_FILES['image'] bir dizi olabilir
    $files = $_FILES['image'];
    $isMultiple = is_array($files['name']);

    // Tekli yüklemeyi dizi formatına normalize et
    if (!$isMultiple) {
        $files = [
            'name'     => [$files['name']],
            'type'     => [$files['type']],
            'tmp_name' => [$files['tmp_name']],
            'error'    => [$files['error']],
            'size'     => [$files['size']],
        ];
    }

    $count = count($files['name']);
    if ($count > 10) error('Tek seferde en fazla 10 görsel yüklenebilir.');

    $results = [];
    $errors  = [];

    foreach (range(0, $count - 1) as $i) {
        $file = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];

        try {
            // İlk dosyayı primary yap (eğer is_primary=true geldiyse)
            $primary  = $isPrimary && $i === 0;
            $result   = UploadService::saveProductImage(
                $file,
                $productId,
                isset($admin['sub']) ? (string) $admin['sub'] : null,
                $primary
            );
            $results[] = $result;
        } catch (RuntimeException $e) {
            $errors[] = [
                'file'    => $file['name'],
                'message' => $e->getMessage(),
            ];
        }
    }

    if (empty($results) && !empty($errors)) {
        error('Tüm yüklemeler başarısız: ' . $errors[0]['message']);
    }

    ok([
        'uploaded' => $results,
        'errors'   => $errors,
    ], count($results) . ' görsel yüklendi.');
}

// ─── GET /upload/product-image/{product_id} ── (public)
elseif ($id === 'product-image' && $sub !== null && $method === 'GET') {
    $productId = (string) $sub;
    $images    = UploadService::getProductImages($productId);
    ok($images);
}

// ─── PATCH /upload/product-image/{image_id} ──
elseif ($id === 'product-image' && is_numeric($sub) && $method === 'PATCH') {
    Auth::requireProductManager();
    $imageId = (int) $sub;

    // Görsel var mı?
    $img = db()->prepare('SELECT * FROM product_images WHERE id = ? LIMIT 1');
    $img->execute([$imageId]);
    $image = $img->fetch();
    if (!$image) error('Görsel bulunamadı.', 404);

    $updates = [];
    $values  = [];

    // Alt text güncelle
    if (array_key_exists('alt_text', body())) {
        $updates[] = 'alt_text = ?';
        $values[]  = substr(trim(input('alt_text', '')), 0, 255);
    }

    // Sort order güncelle
    if (array_key_exists('sort_order', body())) {
        $updates[] = 'sort_order = ?';
        $values[]  = (int) input('sort_order', 0);
    }

    // Primary yap
    if (input('is_primary') === true || input('is_primary') === 'true' || input('is_primary') === 1) {
        // Önce aynı ürünün diğerlerini kaldır
        db()->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')
            ->execute([$image['product_id']]);
        $updates[] = 'is_primary = ?';
        $values[]  = 1;
    }

    if (empty($updates)) error('Güncellenecek alan yok.');

    $values[] = $imageId;
    db()->prepare('UPDATE product_images SET ' . implode(', ', $updates) . ' WHERE id = ?')
        ->execute($values);

    ok(null, 'Görsel güncellendi.');
}

// ─── DELETE /upload/product-image/{image_id} ─
elseif ($id === 'product-image' && is_numeric($sub) && $method === 'DELETE') {
    $admin   = Auth::requireProductManager();
    $imageId = (int) $sub;

    $deleted = UploadService::deleteProductImage($imageId, isset($admin['sub']) ? (string) $admin['sub'] : null);
    if (!$deleted) error('Görsel bulunamadı.', 404);

    ok(null, 'Görsel silindi.');
}

else {
    error('Upload endpoint bulunamadı.', 404);
}
