<?php
// ═══════════════════════════════════════════════
//  SecurityHeaders Middleware
//  XSS, Clickjacking, MIME sniffing, vb. koruma
// ═══════════════════════════════════════════════

class SecurityHeaders
{
    public static function apply(): void
    {
        // Tarayıcının içeriği MIME sniff etmesini engelle
        header('X-Content-Type-Options: nosniff');

        // Clickjacking koruması
        header('X-Frame-Options: DENY');

        // XSS filtresi (eski tarayıcılar için)
        header('X-XSS-Protection: 1; mode=block');

        // HTTPS zorunluluğu (production'da aktif)
        if (env('APP_ENV') === 'production') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // Referrer bilgisini kısıtla
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // İzin verilen kaynakları sınırla
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

        // API response'larında cache'leme yapılmasın
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        // PHP versiyonunu gizle
        header_remove('X-Powered-By');
    }
}
