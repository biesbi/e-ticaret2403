<?php
// ══════════════════════════════════════════════════
//  POST /api/mail/test   — Test e-postası gönder
//  Sadece admin kullanıcılar erişebilir.
// ══════════════════════════════════════════════════

Auth::requireAdmin();

if ($method !== 'POST') {
    error('Method Not Allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$to   = trim((string) ($body['email'] ?? ''));

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    error('Geçerli bir e-posta adresi gerekli. {"email":"test@example.com"}', 422);
}

$result = MailService::sendTestEmail($to);

json([
    'success'      => $result['sent'] || !$result['mail_enabled'],
    'sent'         => $result['sent'],
    'queued'       => $result['queued'],
    'queue_id'     => $result['queue_id'],
    'mail_enabled' => $result['mail_enabled'],
    'smtp_host'    => $result['smtp_host'] ?: '(boş)',
    'message'      => $result['mail_enabled']
        ? ($result['sent'] ? 'E-posta SMTP üzerinden başarıyla gönderildi.' : 'SMTP gönderimi başarısız — loglara bakın.')
        : 'MAIL_ENABLED=false: e-posta queue\'ya kaydedildi, gönderilmedi.',
]);
