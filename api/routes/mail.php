<?php
// ══════════════════════════════════════════════════
//  Mail Routes — Sadece admin erişimi
//  POST /api/mail/test             — Test e-postası gönder
//  POST /api/mail/queue/process    — Bekleyen mailleri işle (retry)
//  GET  /api/mail/queue/status     — Queue istatistikleri
// ══════════════════════════════════════════════════

Auth::requireAdmin();

// /api/mail/queue/process  veya  /api/mail/queue/status
if (($segments[1] ?? '') === 'queue') {
    $action = $segments[2] ?? '';

    if ($action === 'process' && $method === 'POST') {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $limit = max(1, min(100, (int) ($body['limit'] ?? 20)));
        $result = MailService::processQueue($limit);
        json(['success' => true, 'result' => $result]);
    }

    if ($action === 'status' && $method === 'GET') {
        MailService::ensureSchema();
        $rows = db()->query(
            'SELECT status, COUNT(*) as total, MAX(created_at) as last_created
             FROM email_queue
             GROUP BY status'
        )->fetchAll(PDO::FETCH_ASSOC);
        json(['success' => true, 'queue' => $rows]);
    }

    error('Bilinmeyen mail queue eylemi.', 404);
}

// POST /api/mail/test
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
