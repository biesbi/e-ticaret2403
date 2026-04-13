<?php

class Auth
{
    public static function require(): array
    {
        $token = self::extractToken();
        if (!$token) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authorization token gerekli.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (self::isBlacklisted($token)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token gecersiz kilinmis. Lutfen tekrar giris yapin.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $payload = jwtDecode($token);
        if (!$payload) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token gecersiz veya suresi dolmus.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $hydrated = hydrateAuthPayload($payload, $token);
        if (empty($hydrated['sub']) || !isset($hydrated['username'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Kullanici bulunamadi.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        return $hydrated;
    }

    public static function requireAdmin(): array
    {
        $payload = self::require();
        if (!roleCanManageUsers((string) ($payload['role'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Bu islem icin yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        return $payload;
    }

    public static function requireProductManager(): array
    {
        $payload = self::require();
        if (!roleCanManageProducts((string) ($payload['role'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Bu islem icin yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        return $payload;
    }

    public static function blacklist(string $token): void
    {
        $payload = jwtDecode($token);
        $expiresAt = $payload['exp'] ?? (time() + 86400);
        $hash = hash('sha256', $token);

        db()->prepare(
            'INSERT IGNORE INTO token_blacklist (token_hash, expires_at) VALUES (?, FROM_UNIXTIME(?))'
        )->execute([$hash, $expiresAt]);

        if (random_int(1, 50) === 1) {
            db()->exec('DELETE FROM token_blacklist WHERE expires_at < NOW()');
        }
    }

    private static function isBlacklisted(string $token): bool
    {
        $hash = hash('sha256', $token);
        $stmt = db()->prepare(
            'SELECT id FROM token_blacklist WHERE token_hash = ? AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$hash]);
        return (bool) $stmt->fetch();
    }

    public static function extractToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));
        return $token !== '' ? $token : null;
    }

    public static function optional(): ?array
    {
        $token = self::extractToken();
        if (!$token || self::isBlacklisted($token)) {
            return null;
        }

        $payload = jwtDecode($token);
        if (!$payload) {
            return null;
        }

        $hydrated = hydrateAuthPayload($payload, $token);
        if (($hydrated['_user_found'] ?? true) === false) {
            return null;
        }

        return $hydrated;
    }
}
