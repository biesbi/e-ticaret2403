<?php
// ═══════════════════════════════════════════════
//  Auth Middleware
//  JWT doğrulama + Token Blacklist kontrolü
// ═══════════════════════════════════════════════

class Auth
{
    /**
     * Token'ı doğrula ve payload döndür.
     * Blacklist'te varsa reddeder.
     */
    public static function require(): array
    {
        $token = self::extractToken();
        if (!$token) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authorization token gerekli.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Blacklist kontrolü
        if (self::isBlacklisted($token)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token geçersiz kılınmış. Lütfen tekrar giriş yapın.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $payload = jwtDecode($token);
        if (!$payload) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token geçersiz veya süresi dolmuş.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Kullanıcının hâlâ var olduğunu ve aktif olduğunu doğrula
        $nameSelect = tableHasColumn('users', 'username')
            ? 'username'
            : (tableHasColumn('users', 'name') ? 'name AS username' : 'email AS username');
        $stmt = db()->prepare("SELECT id, $nameSelect, role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Kullanıcı bulunamadı.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Payload'a güncel role'ü ekle (token'daki role eskimiş olabilir)
        $payload['role']     = $user['role'];
        $payload['username'] = $user['username'];
        $payload['_token']   = $token; // logout için

        return $payload;
    }

    /**
     * Admin rolü zorunlu.
     */
    public static function requireAdmin(): array
    {
        $payload = self::require();
        if ($payload['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $payload;
    }

    /**
     * Token'ı blacklist'e ekle (logout).
     */
    public static function blacklist(string $token): void
    {
        $payload   = jwtDecode($token);
        $expiresAt = $payload['exp'] ?? (time() + 86400);

        // Hash'lenmiş olarak sakla (token'ın kendisi değil)
        $hash = hash('sha256', $token);

        db()->prepare(
            'INSERT IGNORE INTO token_blacklist (token_hash, expires_at) VALUES (?, FROM_UNIXTIME(?))'
        )->execute([$hash, $expiresAt]);

        // Süresi dolmuş blacklist kayıtlarını temizle (her 50 logout'ta bir)
        if (random_int(1, 50) === 1) {
            db()->exec('DELETE FROM token_blacklist WHERE expires_at < NOW()');
        }
    }

    /**
     * Token blacklist'te mi?
     */
    private static function isBlacklisted(string $token): bool
    {
        $hash = hash('sha256', $token);
        $stmt = db()->prepare(
            'SELECT id FROM token_blacklist WHERE token_hash = ? AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$hash]);
        return (bool) $stmt->fetch();
    }

    /**
     * Authorization header'dan Bearer token'ı çıkar.
     */
    public static function extractToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) return null;
        $token = trim(substr($header, 7));
        return $token !== '' ? $token : null;
    }

    /**
     * Opsiyonel auth — token varsa parse et, yoksa null dön.
     * Sipariş oluşturma gibi hem guest hem auth destekleyen endpoint'ler için.
     */
    public static function optional(): ?array
    {
        $token = self::extractToken();
        if (!$token) return null;
        if (self::isBlacklisted($token)) return null;
        return jwtDecode($token) ?: null;
    }
}
