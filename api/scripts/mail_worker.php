<?php
// ─── Background Mail Worker ────────────────────────────────────────────────
// Çalıştırma: php mail_worker.php <queue_id>
// HTTP response'dan SONRA, ayrı bir PHP süreci olarak çalışır.
// ──────────────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$queueId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($queueId <= 0) {
    exit('Geçersiz queue_id.' . PHP_EOL);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/MailService.php';

try {
    $row = db()->prepare(
        'SELECT id, to_email, subject, body, attempts
         FROM email_queue
         WHERE id = ? AND status = "pending"
         LIMIT 1'
    );
    $row->execute([$queueId]);
    $mail = $row->fetch(PDO::FETCH_ASSOC);

    if (!$mail) {
        exit("Queue #{$queueId} bulunamadı veya zaten işlendi." . PHP_EOL);
    }

    $maxAttempts = max(1, (int) env('MAIL_MAX_ATTEMPTS', 4));
    $retryDelay  = max(0, (int) env('MAIL_RETRY_DELAY_MINUTES', 5));
    $newAttempts = (int)$mail['attempts'] + 1;

    $sent = MailService::deliver(
        (string)$mail['to_email'],
        (string)$mail['subject'],
        (string)$mail['body']
    );

    if ($sent) {
        db()->prepare(
            'UPDATE email_queue SET status="sent", attempts=?, sent_at=CURRENT_TIMESTAMP WHERE id=?'
        )->execute([$newAttempts, $queueId]);
        echo "Mail #{$queueId} gönderildi -> {$mail['to_email']}" . PHP_EOL;
    } else {
        $newStatus     = $newAttempts >= $maxAttempts ? 'failed' : 'pending';
        $nextScheduled = date('Y-m-d H:i:s', time() + ($retryDelay * 60 * $newAttempts));
        db()->prepare(
            'UPDATE email_queue SET status=?, attempts=?, scheduled_at=? WHERE id=?'
        )->execute([$newStatus, $newAttempts, $nextScheduled, $queueId]);
        echo "Mail #{$queueId} başarısız (deneme: {$newAttempts}/{$maxAttempts})" . PHP_EOL;
    }
} catch (Throwable $e) {
    error_log('[mail_worker] Hata: ' . $e->getMessage());
    exit('Hata: ' . $e->getMessage() . PHP_EOL);
}
