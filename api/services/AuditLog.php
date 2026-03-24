<?php
// ═══════════════════════════════════════════════
//  AuditLog Servisi
//  Kritik işlemleri hem DB hem dosyaya yazar.
//  Saldırı tespiti ve adli analiz için.
// ═══════════════════════════════════════════════

class AuditLog
{
    // Takip edilecek eylem sabitleri
    const LOGIN_SUCCESS      = 'auth.login.success';
    const LOGIN_FAIL         = 'auth.login.fail';
    const LOGOUT             = 'auth.logout';
    const REGISTER           = 'auth.register';
    const PASSWORD_CHANGE    = 'auth.password.change';

    const ORDER_CREATE       = 'order.create';
    const ORDER_STATUS       = 'order.status.change';
    const ORDER_CARGO        = 'order.cargo.update';

    const PRODUCT_CREATE     = 'product.create';
    const PRODUCT_UPDATE     = 'product.update';
    const PRODUCT_DELETE     = 'product.delete';

    const COUPON_CREATE      = 'coupon.create';
    const COUPON_DELETE      = 'coupon.delete';
    const COUPON_USE         = 'coupon.use';

    const UPLOAD_SUCCESS     = 'upload.success';
    const UPLOAD_FAIL        = 'upload.fail';

    const ADMIN_ACTION       = 'admin.action';

    /**
     * Audit kaydı yaz.
     *
     * @param string   $action      Eylem sabiti (yukarıdaki const'lar)
     * @param string|null $userId   İşlemi yapan kullanıcı ID
     * @param string   $entityType  İlgili varlık tipi (order, product, user...)
     * @param string|int|null $entityId İlgili varlık ID
     * @param array    $meta        Ek bilgi (ör: eski/yeni durum)
     */
    public static function write(
        string $action,
        ?string $userId    = null,
        string $entityType = '',
        string|int|null $entityId = null,
        array  $meta       = []
    ): void {
        $ip        = RateLimit::getClientIp();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $metaJson  = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

        // DB'ye yaz (sessizce — audit log ana akışı durdurmamalı)
        try {
            db()->prepare(
                'INSERT INTO audit_logs
                 (user_id, action, entity_type, entity_id, ip, user_agent, meta)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$userId, $action, $entityType, $entityId, $ip, $userAgent, $metaJson]);
        } catch (Throwable) {
            // DB yazma başarısız olursa dosyaya düş
        }

        // Dosyaya da yaz
        if (env('LOG_ENABLED', 'true') === 'true') {
            self::writeFile($action, $userId, $entityType, $entityId, $ip, $meta);
        }
    }

    private static function writeFile(
        string $action,
        ?string $userId,
        string $entityType,
        string|int|null $entityId,
        string $ip,
        array  $meta
    ): void {
        $logDir  = rtrim(env('LOG_DIR', __DIR__ . '/../logs'), '/');
        $logFile = $logDir . '/audit.log';

        if (!is_dir($logDir)) @mkdir($logDir, 0750, true);

        $uid    = $userId   ?? 'guest';
        $eid    = $entityId ?? '-';
        $extra  = !empty($meta) ? ' META=' . json_encode($meta) : '';
        $line   = sprintf(
            "%s [AUDIT] action=%s user=%s entity=%s/%s ip=%s%s\n",
            date('Y-m-d H:i:s'),
            $action,
            $uid,
            $entityType ?: '-',
            $eid,
            $ip,
            $extra
        );

        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
