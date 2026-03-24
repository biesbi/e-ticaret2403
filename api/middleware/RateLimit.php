<?php
// ═══════════════════════════════════════════════
//  RateLimit Middleware
//  Brute force ve API abuse koruması
//  Veriler MySQL'de tutulur (APCu/Redis gerekmez)
// ═══════════════════════════════════════════════

class RateLimit
{
    /**
     * Belirtilen endpoint için rate limit uygula.
     *
     * @param string $endpoint   Tanımlayıcı (ör: 'auth_login')
     * @param int    $maxHits    İzin verilen maksimum istek sayısı
     * @param int    $windowSec  Zaman penceresi (saniye)
     */
    public static function check(string $endpoint, int $maxHits = 10, int $windowSec = 300): void
    {
        $ip  = self::getClientIp();
        $now = time();
        $pdo = db();

        self::ensureTable($pdo);

        // Süresi dolmuş kayıtları temizle (her 100 istekte bir)
        if (random_int(1, 100) === 1) {
            $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')
                ->execute([$now - $windowSec]);
        }

        // Mevcut kaydı çek
        $stmt = $pdo->prepare(
            'SELECT id, hit_count, window_start FROM rate_limits
             WHERE ip = ? AND endpoint = ? LIMIT 1'
        );
        $stmt->execute([$ip, $endpoint]);
        $row = $stmt->fetch();

        if (!$row) {
            // İlk istek — yeni kayıt oluştur
            $pdo->prepare(
                'INSERT INTO rate_limits (ip, endpoint, hit_count, window_start) VALUES (?,?,1,?)'
            )->execute([$ip, $endpoint, $now]);
            return;
        }

        // Pencere süresi dolmuşsa sıfırla
        if (($now - $row['window_start']) >= $windowSec) {
            $pdo->prepare(
                'UPDATE rate_limits SET hit_count = 1, window_start = ? WHERE id = ?'
            )->execute([$now, $row['id']]);
            return;
        }

        // Limit aşıldı mı?
        if ($row['hit_count'] >= $maxHits) {
            $retryAfter = $windowSec - ($now - $row['window_start']);
            header('Retry-After: ' . $retryAfter);
            header('X-RateLimit-Limit: '     . $maxHits);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: '     . ($row['window_start'] + $windowSec));

            // Şüpheli aktiviteyi logla
            self::logAbuse($ip, $endpoint);

            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Çok fazla istek gönderdiniz. Lütfen bekleyin.',
                'retry_after_seconds' => $retryAfter,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Sayacı artır
        $pdo->prepare('UPDATE rate_limits SET hit_count = hit_count + 1 WHERE id = ?')
            ->execute([$row['id']]);

        // Kalan hak header'ı
        header('X-RateLimit-Limit: '     . $maxHits);
        header('X-RateLimit-Remaining: ' . ($maxHits - $row['hit_count'] - 1));
        header('X-RateLimit-Reset: '     . ($row['window_start'] + $windowSec));
    }

    /**
     * Belirli bir IP'yi kalıcı olarak blokla (admin paneli için)
     */
    public static function blockIp(string $ip): void
    {
        db()->prepare(
            'INSERT INTO rate_limits (ip, endpoint, hit_count, window_start)
             VALUES (?, "__blocked__", 999999, ?)
             ON DUPLICATE KEY UPDATE hit_count = 999999'
        )->execute([$ip, time()]);
    }

    /**
     * Gerçek istemci IP'sini güvenli şekilde al.
     * Proxy/CDN arkasında çalışıyorsa X-Forwarded-For'u kullan.
     */
    public static function getClientIp(): string
    {
        // Güvenilir proxy IP'leri — production'da kendi proxy IP'nizi ekleyin
        $trustedProxies = ['127.0.0.1', '::1'];

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (
            in_array($remoteAddr, $trustedProxies, true) &&
            !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
        ) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($forwarded[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $remoteAddr;
    }

    private static function logAbuse(string $ip, string $endpoint): void
    {
        if (!defined('LOG_ENABLED') || LOG_ENABLED !== 'true') return;
        $logDir  = defined('LOG_DIR') ? LOG_DIR : __DIR__ . '/../logs';
        $logFile = rtrim($logDir, '/') . '/security.log';
        $line    = date('Y-m-d H:i:s') . " [RATE_LIMIT] IP=$ip ENDPOINT=$endpoint\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private static function ensureTable(PDO $pdo): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        $checked = true;
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS rate_limits (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                endpoint VARCHAR(120) NOT NULL,
                hit_count INT UNSIGNED NOT NULL DEFAULT 0,
                window_start INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ip_endpoint (ip, endpoint),
                KEY idx_window_start (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
