<?php
// ═══════════════════════════════════════════════
//  BoomerItems — API Router
//  Tüm istekler bu dosyaya gelir (.htaccess ile)
// ═══════════════════════════════════════════════

// ─── 1. Temel Yüklemeler ──────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// ─── 2. Middleware Sınıfları ──────────────────
require_once __DIR__ . '/middleware/ErrorHandler.php';
require_once __DIR__ . '/middleware/SecurityHeaders.php';
require_once __DIR__ . '/middleware/RateLimit.php';
require_once __DIR__ . '/middleware/Auth.php';

// ─── 3. Servisler ─────────────────────────────
require_once __DIR__ . '/services/AuditLog.php';
require_once __DIR__ . '/services/CartService.php';
require_once __DIR__ . '/services/UploadService.php';
require_once __DIR__ . '/services/AdminService.php';
require_once __DIR__ . '/services/StockService.php';
require_once __DIR__ . '/services/OrderService.php';
require_once __DIR__ . '/services/MailService.php';
require_once __DIR__ . '/services/PaytrService.php';

// ─── 4. Global Middleware'leri Uygula ─────────
ErrorHandler::register();    // Hata yakalama (ilk sıraya alınmalı)
SecurityHeaders::apply();    // HTTP güvenlik başlıkları
cors();                      // CORS headers (helpers.php'den)

// ─── 5. Eski helper fonksiyonlarını Auth sınıfına yönlendir ──
// Geriye dönük uyumluluk — mevcut route dosyaları bunları kullanıyor

// ─── 6. URL Parse ─────────────────────────────
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = preg_replace('#^/api#', '', $uri);
$uri    = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

$segments = array_values(array_filter(explode('/', $uri)));
$resource = $segments[0] ?? '';
$id       = $segments[1] ?? null;
$sub      = $segments[2] ?? null;

// ─── 7. Global Rate Limit (API geneli) ────────
// Tüm endpoint'lere dakikada max 120 istek
if ($resource !== '') {
    RateLimit::check('global_' . RateLimit::getClientIp(), 120, 60);
}

// ─── 8. Router ────────────────────────────────
try {
    match ($resource) {
        'auth'     => require __DIR__ . '/routes/auth.php',
        'products' => require __DIR__ . '/routes/products.php',
        'orders'   => require __DIR__ . '/routes/orders.php',
        'coupons'  => require __DIR__ . '/routes/coupons.php',
        'shipping' => require __DIR__ . '/routes/shipping.php',
        'payments' => require __DIR__ . '/routes/payments.php',
        'users'    => require __DIR__ . '/routes/users.php',
        'cart'     => require __DIR__ . '/routes/cart.php',
        'upload'   => require __DIR__ . '/routes/upload.php',
        'admin'    => require __DIR__ . '/routes/admin.php',
        'mail'     => require __DIR__ . '/routes/mail.php',
        'variants' => require __DIR__ . '/routes/variants.php',
        default    => error('Endpoint bulunamadı.', 404),
    };
} catch (PDOException $e) {
    // DB hatalarında detayı gizle, logla
    AuditLog::write('system.db_error', null, 'system', null, [
        'uri'     => $uri,
        'message' => $e->getMessage(),
    ]);
    error('Veritabanı hatası oluştu.', 500);
} catch (Throwable $e) {
    // ErrorHandler zaten yakalar ama match dışı için burada da varolan
    throw $e;
}
