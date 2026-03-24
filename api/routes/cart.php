<?php
// ═══════════════════════════════════════════════
//  GET    /cart              — Sepeti getir
//  POST   /cart              — Ürün ekle
//  PATCH  /cart/{item_id}    — Miktar güncelle
//  DELETE /cart/{item_id}    — Ürün sil
//  DELETE /cart              — Sepeti tamamen temizle
//  POST   /cart/merge        — Guest → User birleştir
//  GET    /cart/validate     — Checkout öncesi stok kontrolü
// ═══════════════════════════════════════════════

require_once __DIR__ . '/../services/CartService.php';

// ─── Kimliği Belirle ─────────────────────────
// Auth kullanıcısı ya da X-Session-ID header'ıyla guest
$authPayload = Auth::optional();   // token varsa parse et, yoksa null
$userId      = $authPayload ? (string) $authPayload['sub'] : null;

// Guest için X-Session-ID header'ından UUID al
$rawSession  = $_SERVER['HTTP_X_SESSION_ID'] ?? '';
$sessionId   = null;

if ($userId === null) {
    // Auth yoksa session_id zorunlu
    if ($rawSession === '') {
        error('Giriş yapın veya X-Session-ID header\'ı gönderin.');
    }
    if (!CartService::validateSessionId($rawSession)) {
        error('Geçersiz session_id formatı. UUID v4 bekleniyor.');
    }
    $sessionId = $rawSession;
}

// ─── Route ───────────────────────────────────

// POST /cart/merge — login sonrası guest → user birleştirme
if ($id === 'merge' && $method === 'POST') {
    if ($userId === null) error('Bu işlem için giriş yapmanız gerekli.', 401);

    $guestSessionId = input('session_id', '');
    if (!CartService::validateSessionId($guestSessionId)) {
        error('Geçerli bir session_id gönderin.');
    }

    $result = CartService::merge($userId, $guestSessionId);
    ok($result, "{$result['merged']} ürün sepetinize aktarıldı.");
}

// GET /cart/validate — checkout öncesi stok kontrolü
elseif ($id === 'validate' && $method === 'GET') {
    $result = CartService::validate($userId, $sessionId);
    ok($result, $result['valid'] ? 'Sepet geçerli.' : 'Sepette stok sorunu var.');
}

// GET /cart — sepeti getir
elseif ($id === null && $method === 'GET') {
    $cart = CartService::get($userId, $sessionId);
    ok($cart);
}

// POST /cart — ürün ekle
elseif ($id === null && $method === 'POST') {
    $productId = trim((string) input('product_id', ''));
    $variantId = input('variant_id', input('variantId'));
    $qty       = (int) input('quantity', 1);

    if ($productId === '') error('Geçerli bir product_id gönderin.');
    if ($qty < 1 || $qty > 999) error('Miktar 1-999 arasında olmalı.');

    try {
        $result = CartService::add($userId, $sessionId, $productId, $qty, $variantId !== null && $variantId !== '' ? (int) $variantId : null);
        // Güncel sepeti de döndür
        $cart = CartService::get($userId, $sessionId);
        ok([
            'added'   => $result,
            'cart'    => $cart,
        ], 'Ürün sepete eklendi.');
    } catch (RuntimeException $e) {
        error($e->getMessage());
    }
}

// PATCH /cart/{item_id} — miktar güncelle
elseif (is_numeric($id) && $sub === null && $method === 'PATCH') {
    $itemId = (int) $id;
    $qty    = (int) input('quantity', 0);

    if ($qty < 0 || $qty > 999) error('Miktar 0-999 arasında olmalı (0 = sil).');

    $updated = CartService::update($userId, $sessionId, $itemId, $qty);
    if (!$updated) error('Sepet öğesi bulunamadı veya bu işlem için yetkiniz yok.', 404);

    $cart = CartService::get($userId, $sessionId);
    ok(['cart' => $cart], $qty === 0 ? 'Ürün sepetten kaldırıldı.' : 'Miktar güncellendi.');
}

// DELETE /cart/{item_id} — tek ürün sil
elseif (is_numeric($id) && $sub === null && $method === 'DELETE') {
    $itemId = (int) $id;

    $removed = CartService::remove($userId, $sessionId, $itemId);
    if (!$removed) error('Sepet öğesi bulunamadı veya bu işlem için yetkiniz yok.', 404);

    $cart = CartService::get($userId, $sessionId);
    ok(['cart' => $cart], 'Ürün sepetten kaldırıldı.');
}

// DELETE /cart — tüm sepeti temizle
elseif ($id === null && $method === 'DELETE') {
    CartService::clear($userId, $sessionId);
    ok(null, 'Sepet temizlendi.');
}

else {
    error('Sepet endpoint bulunamadı.', 404);
}
