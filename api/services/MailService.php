<?php

final class MailService
{
    public static function ensureSchema(): void
    {
        if (!tableExists('email_queue')) {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS email_queue (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    to_email VARCHAR(180) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    body TEXT NOT NULL,
                    status ENUM("pending","sent","failed") NOT NULL DEFAULT "pending",
                    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    sent_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_email_queue_status (status, scheduled_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci'
            );
        }

        if (tableExists('users')) {
            if (!tableHasColumn('users', 'email_verified')) {
                db()->exec('ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0');
            }
            if (!tableHasColumn('users', 'email_verification_token')) {
                db()->exec('ALTER TABLE users ADD COLUMN email_verification_token VARCHAR(128) NULL');
            }
            if (!tableHasColumn('users', 'email_verification_sent_at')) {
                db()->exec('ALTER TABLE users ADD COLUMN email_verification_sent_at DATETIME NULL');
            }
            if (!tableHasColumn('users', 'email_verification_expires_at')) {
                db()->exec('ALTER TABLE users ADD COLUMN email_verification_expires_at DATETIME NULL');
            }
            if (!tableHasColumn('users', 'email_verified_at')) {
                db()->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL');
            }
            if (!tableHasColumn('users', 'password_reset_token')) {
                db()->exec('ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(128) NULL');
            }
            if (!tableHasColumn('users', 'password_reset_sent_at')) {
                db()->exec('ALTER TABLE users ADD COLUMN password_reset_sent_at DATETIME NULL');
            }
            if (!tableHasColumn('users', 'password_reset_expires_at')) {
                db()->exec('ALTER TABLE users ADD COLUMN password_reset_expires_at DATETIME NULL');
            }
        }
    }

    public static function verificationLifetimeHours(): int
    {
        $hours = (int) env('MAIL_VERIFICATION_EXPIRE_HOURS', 24);
        return $hours > 0 ? $hours : 24;
    }

    public static function generateVerificationToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function passwordResetLifetimeHours(): int
    {
        $hours = (int) env('MAIL_PASSWORD_RESET_EXPIRE_HOURS', 2);
        return $hours > 0 ? $hours : 2;
    }

    public static function generatePasswordResetToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function issueVerificationToken(string|int $userId, string $email, string $name): string
    {
        self::ensureSchema();

        $token = self::generateVerificationToken();
        $expiresAt = date('Y-m-d H:i:s', time() + (self::verificationLifetimeHours() * 3600));

        $sql = 'UPDATE users
                SET email_verified = 0,
                    email_verified_at = NULL,
                    email_verification_token = ?,
                    email_verification_sent_at = CURRENT_TIMESTAMP,
                    email_verification_expires_at = ?';
        $params = [$token, $expiresAt];

        if (tableHasColumn('users', 'is_active')) {
            $sql .= ', is_active = 0';
        }

        $sql .= ' WHERE id = ?';
        $params[] = $userId;

        db()->prepare($sql)->execute($params);
        self::sendVerificationEmail($email, $name, $token);

        return $token;
    }

    public static function sendVerificationEmail(string $email, string $name, string $token): void
    {
        $verifyUrl = rtrim((string) env('APP_URL', ''), '/') . '/verify-email?token=' . urlencode($token);
        $subject = 'BoomerItems e-posta dogrulama';
        $html = self::wrapTemplate(
            'Hesabinizi aktifleştirin',
            $name,
            '<p style="margin:0 0 14px;">BoomerItems ailesine hos geldiniz. Hesabinizi guvenli sekilde aktifleştirmek icin e-posta adresinizi dogrulamaniz gerekiyor.</p>'
            . '<p style="margin:0 0 16px;">Asagidaki butona tiklayarak dogrulama islemini tamamlayabilirsiniz. Baglanti '
            . self::verificationLifetimeHours() . ' saat boyunca gecerlidir ve tek kullanimliktir.</p>'
            . self::renderButton($verifyUrl, 'E-postami Dogrula')
            . self::renderInfoTable([
                'E-posta Adresi' => $email,
                'Gecerlilik Suresi' => self::verificationLifetimeHours() . ' saat',
                'Guvenlik' => 'Tek kullanimlik dogrulama baglantisi',
            ])
            . '<p style="margin:0 0 10px;color:#475569;">Buton acilmazsa asagidaki baglantiyi tarayiciniza yapistirabilirsiniz:</p>'
            . '<p style="margin:0 0 14px;color:#0f172a;word-break:break-all;">' . self::escape($verifyUrl) . '</p>'
            . '<p style="margin:0;color:#64748b;">Bu islemi siz yapmadiysaniz bu e-postayi dikkate almayabilirsiniz.</p>'
        );

        self::queueAndAttempt($email, $subject, $html);
    }

    public static function issuePasswordResetToken(string|int $userId, string $email, string $name): string
    {
        self::ensureSchema();

        $token = self::generatePasswordResetToken();
        $expiresAt = date('Y-m-d H:i:s', time() + (self::passwordResetLifetimeHours() * 3600));

        db()->prepare(
            'UPDATE users
             SET password_reset_token = ?,
                 password_reset_sent_at = CURRENT_TIMESTAMP,
                 password_reset_expires_at = ?
             WHERE id = ?'
        )->execute([$token, $expiresAt, $userId]);

        self::sendPasswordResetEmail($email, $name, $token);

        return $token;
    }

    public static function sendPasswordResetEmail(string $email, string $name, string $token): void
    {
        $resetUrl = rtrim((string) env('APP_URL', ''), '/') . '/sifre-sifirla?token=' . urlencode($token);
        $subject = 'BoomerItems sifre sifirlama';
        $html = self::wrapTemplate(
            'Sifrenizi yenileyin',
            $name,
            '<p style="margin:0 0 14px;">BoomerItems hesabiniz icin sifre sifirlama talebi aldik.</p>'
            . '<p style="margin:0 0 16px;">Asagidaki butona tiklayarak yeni sifrenizi belirleyebilirsiniz. Baglanti '
            . self::passwordResetLifetimeHours() . ' saat boyunca gecerlidir ve tek kullanimliktir.</p>'
            . self::renderButton($resetUrl, 'Yeni Sifre Belirle')
            . self::renderInfoTable([
                'E-posta Adresi' => $email,
                'Gecerlilik Suresi' => self::passwordResetLifetimeHours() . ' saat',
                'Guvenlik' => 'Tek kullanimlik sifre sifirlama baglantisi',
            ])
            . '<p style="margin:0 0 10px;color:#475569;">Buton acilmazsa asagidaki baglantiyi tarayiciniza yapistirabilirsiniz:</p>'
            . '<p style="margin:0 0 14px;color:#0f172a;word-break:break-all;">' . self::escape($resetUrl) . '</p>'
            . '<p style="margin:0;color:#64748b;">Bu istegi siz yapmadiysaniz bu e-postayi dikkate almayabilir ve hesabinizin sifresini guvenlik icin degistirebilirsiniz.</p>'
        );

        self::queueAndAttempt($email, $subject, $html);
    }

    public static function sendOrderReceivedEmail(string $email, string $name, array $order): void
    {
        $orderId = (string) ($order['id'] ?? '');
        $subtotal = number_format((float) ($order['subtotal'] ?? 0), 2, ',', '.');
        $discount = (float) ($order['discount'] ?? 0);
        $shippingFee = (float) ($order['shipping_fee'] ?? 0);
        $giftWrapCost = (float) ($order['gift_wrap_cost'] ?? 0);
        $total = number_format((float) ($order['total'] ?? 0), 2, ',', '.');
        $city = (string) (($order['shippingAddress']['city'] ?? $order['shipping_address']['city'] ?? ''));
        $district = (string) (($order['shippingAddress']['district'] ?? $order['shipping_address']['district'] ?? ''));
        $addressDetail = trim((string) (($order['shippingAddress']['address_detail'] ?? $order['shipping_address']['address_detail'] ?? '')));
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $subject = 'BoomerItems siparisiniz alindi';
        $html = self::wrapTemplate(
            'Siparisiniz alindi',
            $name,
            '<p style="margin:0 0 14px;">Siparisiniz basariyla alindi. Ekibimiz urunlerinizi kontrol ederek hazirlama surecine gececektir.</p>'
            . self::renderNoticeBox(
                'Siparisiniz onay surecinde',
                'Odeme ve stok kontrolleri tamamlandiktan sonra siparisiniz hizla hazirlama asamasina gecirilecektir.'
            )
            . self::renderInfoTable([
                'Siparis No' => $orderId,
                'Ara Toplam' => $subtotal . ' TL',
                'Indirim' => $discount > 0 ? number_format($discount, 2, ',', '.') . ' TL' : 'Yok',
                'Kargo' => $shippingFee > 0 ? number_format($shippingFee, 2, ',', '.') . ' TL' : 'Ucretsiz',
                'Hediye Paketi' => $giftWrapCost > 0 ? number_format($giftWrapCost, 2, ',', '.') . ' TL' : 'Yok',
                'Toplam' => $total . ' TL',
                'Teslimat' => trim($district . ' / ' . $city, ' /'),
            ])
            . self::renderOrderItems($items)
            . self::renderMiniCard(
                'Teslimat adresi',
                trim(($addressDetail !== '' ? $addressDetail . '<br>' : '') . self::escape(trim($district . ' / ' . $city, ' /')))
            )
            . '<p style="margin:18px 0 0;color:#475569;">Siparis durumunu hesabinizdan veya destek kanallarimizdan takip edebilirsiniz. Kargo asamasina gecildiginde ayrica bilgilendirme alacaksiniz.</p>'
        );

        self::queueAndAttempt($email, $subject, $html);
    }

    public static function sendOrderStatusEmail(string $email, string $name, string $orderId, string $newStatus): void
    {
        $statusLabels = [
            'pending' => 'Beklemede',
            'confirmed' => 'Onaylandi',
            'processing' => 'Hazirlaniyor',
            'preparing' => 'Hazirlaniyor',
            'shipped' => 'Kargoya Verildi',
            'delivered' => 'Teslim Edildi',
            'cancelled' => 'Iptal Edildi',
            'failed' => 'Basarisiz',
        ];
        $label = $statusLabels[$newStatus] ?? $newStatus;
        $subject = 'BoomerItems siparis durumu guncellendi - #' . $orderId;

        $bodyParts = '<p style="margin:0 0 14px;">Siparisinizin durumu guncellendi.</p>'
            . self::renderInfoTable([
                'Siparis No' => $orderId,
                'Yeni Durum' => $label,
                'Guncelleme Tarihi' => date('d.m.Y H:i'),
            ]);

        if ($newStatus === 'cancelled' || $newStatus === 'failed') {
            $bodyParts .= self::renderNoticeBox(
                'Siparisiniz iptal edildi',
                'Sorulariniz icin destek ekibimizle iletisime gecebilirsiniz.'
            );
        } elseif ($newStatus === 'processing' || $newStatus === 'preparing') {
            $bodyParts .= self::renderNoticeBox(
                'Siparisiniz hazirlaniyor',
                'Urunleriniz paketleniyor. Kargoya verildikten sonra takip numarasi gonderilecektir.'
            );
        } elseif ($newStatus === 'delivered') {
            $bodyParts .= self::renderNoticeBox(
                'Siparisiniz teslim edildi',
                'Alisveris deneyiminizden memnun kaldiysaniz bizi tercih ettiginiz icin tesekkur ederiz!'
            );
        }

        $bodyParts .= '<p style="margin:18px 0 0;color:#475569;">Siparis durumunu hesabinizdan takip edebilirsiniz.</p>';

        $html = self::wrapTemplate('Siparis durumu: ' . $label, $name, $bodyParts);
        self::queueAndAttempt($email, $subject, $html);
    }

    public static function sendCargoEmail(string $email, string $name, string $orderId, string $trackingNo, ?string $carrier): void
    {
        $subject = 'BoomerItems siparisiniz kargoya verildi - #' . $orderId;
        $infoRows = [
            'Siparis No' => $orderId,
            'Kargo Takip No' => $trackingNo,
        ];
        if ($carrier !== null && $carrier !== '') {
            $infoRows['Kargo Firmasi'] = $carrier;
        }
        $infoRows['Kargo Tarihi'] = date('d.m.Y H:i');

        $html = self::wrapTemplate(
            'Siparisiniz kargoda',
            $name,
            '<p style="margin:0 0 14px;">Siparisiniz kargoya verildi! Asagidaki takip numarasi ile kargonuzu takip edebilirsiniz.</p>'
            . self::renderNoticeBox(
                'Kargo takip numaraniz',
                $trackingNo . ($carrier !== null && $carrier !== '' ? ' (' . $carrier . ')' : '')
            )
            . self::renderInfoTable($infoRows)
            . '<p style="margin:18px 0 0;color:#475569;">Teslimat surecinde herhangi bir sorun yasarsaniz destek ekibimizle iletisime gecebilirsiniz.</p>'
        );

        self::queueAndAttempt($email, $subject, $html);
    }

    public static function sendRegistrationAdminEmail(string $registeredEmail, string $registeredName): void
    {
        $adminEmail = trim((string) env('MAIL_ADMIN_EMAIL', 'boomeritems@gmail.com'));
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $subject = 'Yeni uye kaydi: ' . $registeredName;
        $html = self::wrapTemplate(
            'Yeni uye kaydi alindi',
            'BoomerItems',
            '<p>Sitede yeni bir kullanici kaydi olustu.</p>'
            . self::renderInfoTable([
                'Ad Soyad' => $registeredName,
                'E-posta' => $registeredEmail,
                'Kayit Tarihi' => date('d.m.Y H:i'),
                'Bildirim Adresi' => $adminEmail,
            ])
            . '<p style="margin:18px 0 0;color:#475569;">Bu bildirim otomatik olarak olusturulmustur.</p>'
        );

        self::queueAndAttempt($adminEmail, $subject, $html);
    }

    public static function wrapTemplate(string $title, string $name, string $bodyHtml): string
    {
        $safeTitle = self::escape($title);
        $safeName = self::escape($name !== '' ? $name : 'BoomerItems kullanicisi');
        $year = date('Y');

        return '<!doctype html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . $safeTitle . '</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  <div style="padding:28px 12px;background:linear-gradient(180deg,#fffbee 0%,#f5f7fb 46%,#eef2f7 100%);">
    <div style="max-width:680px;margin:0 auto;">
      <div style="margin:0 auto 18px;max-width:680px;text-align:center;padding:8px 0 4px;">
        ' . self::renderBrandLogo() . '
      </div>
      <div style="background:#ffffff;border:1px solid #e7ebf1;border-radius:30px;overflow:hidden;box-shadow:0 24px 60px rgba(15,23,42,0.10);">
        <div style="padding:12px 34px 0;background:#ffffff;">
          <div style="height:6px;border-radius:999px;background:linear-gradient(90deg,#ffcc00 0%,#ffd84d 58%,#dd101f 100%);"></div>
        </div>
        <div style="padding:34px 34px 24px;background:
          radial-gradient(circle at top left, rgba(255,204,0,0.22) 0%, rgba(255,204,0,0) 34%),
          radial-gradient(circle at top right, rgba(221,16,31,0.14) 0%, rgba(221,16,31,0) 28%),
          linear-gradient(180deg,#ffffff 0%,#fffdfa 100%);">
          <div style="display:inline-block;padding:8px 14px;border-radius:999px;background:#fff7d6;border:1px solid rgba(255,204,0,0.45);font-size:12px;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;color:#7a5800;">BoomerItems</div>
          <h1 style="margin:18px 0 10px;font-size:34px;line-height:1.05;color:#101828;font-weight:900;letter-spacing:0.02em;">' . $safeTitle . '</h1>
          <p style="margin:0;font-size:16px;line-height:1.7;color:#334155;">Merhaba ' . $safeName . ',</p>
        </div>
        <div style="padding:34px;">
          <div style="font-size:15px;line-height:1.8;color:#334155;">' . $bodyHtml . '</div>
        </div>
      </div>
      <div style="padding:18px 8px 0;text-align:center;font-size:12px;line-height:1.7;color:#6b7280;">
        <div style="font-weight:700;color:#111827;">BoomerItems</div>
        <div>Koleksiyon urunleri ve ozel secimler</div>
        <div><a href="' . self::escape(rtrim((string) env('APP_URL', 'https://www.boomeritems.com'), '/')) . '" style="color:#dd101f;text-decoration:none;font-weight:700;">boomeritems.com</a></div>
        <div style="margin-top:4px;">&copy; ' . $year . ' BoomerItems</div>
      </div>
    </div>
  </div>
</body>
</html>';
    }

    public static function sendTestEmail(string $toEmail): array
    {
        self::ensureSchema();
        $enabled = filter_var(env('MAIL_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        $html = self::wrapTemplate(
            'Test e-postasi',
            'Admin',
            '<p>Bu bir test e-postasidir. SMTP yapilandirmaniz dogruysa mesaj aliciya ulasir.</p>'
            . self::renderInfoTable([
                'MAIL_ENABLED' => $enabled ? 'true' : 'false',
                'MAIL_HOST' => (string) env('MAIL_HOST', '(bos)'),
                'Gonderim Zamani' => date('Y-m-d H:i:s'),
            ])
        );
        $subject = 'BoomerItems test e-postasi';

        $stmt = db()->prepare(
            'INSERT INTO email_queue (to_email, subject, body, status) VALUES (?,?,?,?)'
        );
        $stmt->execute([$toEmail, $subject, $html, 'pending']);
        $queueId = (int) db()->lastInsertId();

        $sent = false;
        if ($enabled) {
            $sent = self::deliver($toEmail, $subject, $html);
            db()->prepare(
                'UPDATE email_queue
                 SET status = ?, attempts = attempts + 1,
                     sent_at = CASE WHEN ? = "sent" THEN CURRENT_TIMESTAMP ELSE sent_at END
                 WHERE id = ?'
            )->execute([$sent ? 'sent' : 'failed', $sent ? 'sent' : 'failed', $queueId]);
        }

        return [
            'sent' => $sent,
            'queued' => true,
            'queue_id' => $queueId,
            'mail_enabled' => $enabled,
            'smtp_host' => (string) env('MAIL_HOST', ''),
        ];
    }

    private static function queueAndAttempt(string $toEmail, string $subject, string $html): void
    {
        self::ensureSchema();

        $stmt = db()->prepare('INSERT INTO email_queue (to_email, subject, body, status) VALUES (?,?,?,?)');
        $stmt->execute([$toEmail, $subject, $html, 'pending']);
        $queueId = (int) db()->lastInsertId();

        if (!filter_var(env('MAIL_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $sent = self::deliver($toEmail, $subject, $html);
        db()->prepare(
            'UPDATE email_queue
             SET status = ?, attempts = attempts + 1, sent_at = CASE WHEN ? = "sent" THEN CURRENT_TIMESTAMP ELSE sent_at END
             WHERE id = ?'
        )->execute([$sent ? 'sent' : 'failed', $sent ? 'sent' : 'failed', $queueId]);
    }

    private static function deliver(string $toEmail, string $subject, string $html): bool
    {
        $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (!file_exists($vendorAutoload)) {
            $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
        }
        if (!file_exists($vendorAutoload)) {
            error_log('[MailService] vendor/autoload.php bulunamadi. "composer install" calistirin.');
            return false;
        }
        require_once $vendorAutoload;

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) env('MAIL_HOST', 'localhost');
            $mail->SMTPAuth = true;
            $mail->Username = (string) env('MAIL_USER', '');
            $mail->Password = (string) env('MAIL_PASS', '');
            $secure = strtolower((string) env('MAIL_SECURE', 'tls'));
            $mail->SMTPSecure = $secure === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) env('MAIL_PORT', 587);
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 20;

            if (filter_var(env('MAIL_ALLOW_SELF_SIGNED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            $fromEmail = trim((string) env('MAIL_FROM_EMAIL', 'noreply@boomeritems.com'));
            $fromName = trim((string) env('MAIL_FROM_NAME', 'BoomerItems'));
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>'], "\n", $html))));

            $mail->send();
            return true;
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            error_log('[MailService] SMTP hatasi: ' . $e->getMessage());
            return false;
        }
    }

    private static function renderInfoTable(array $rows): string
    {
        $html = '<table style="width:100%;border-collapse:separate;border-spacing:0;margin:24px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;">';
        $total = count($rows);
        $index = 0;
        foreach ($rows as $label => $value) {
            $index++;
            $borderStyle = $index < $total ? 'border-bottom:1px solid #e2e8f0;' : '';
            $html .= '<tr>'
                . '<td style="padding:14px 16px;' . $borderStyle . 'font-weight:700;color:#0f172a;width:34%;">' . self::escape((string) $label) . '</td>'
                . '<td style="padding:14px 16px;' . $borderStyle . 'color:#334155;">' . self::escape((string) $value) . '</td>'
                . '</tr>';
        }
        $html .= '</table>';
        return $html;
    }

    private static function renderNoticeBox(string $title, string $text): string
    {
        return '<div style="margin:22px 0 18px;padding:18px 20px;border-radius:20px;background:linear-gradient(135deg,#fff8dc 0%,#fff1bd 100%);border:1px solid rgba(255,204,0,0.45);">'
            . '<div style="font-size:13px;font-weight:900;letter-spacing:0.08em;text-transform:uppercase;color:#8a6500;margin-bottom:6px;">' . self::escape($title) . '</div>'
            . '<div style="color:#4b5563;">' . self::escape($text) . '</div>'
            . '</div>';
    }

    private static function renderMiniCard(string $title, string $content): string
    {
        return '<div style="margin:22px 0 0;padding:20px;border-radius:20px;background:#ffffff;border:1px solid #e7ebf1;box-shadow:0 10px 30px rgba(15,23,42,0.06);">'
            . '<div style="font-size:13px;font-weight:900;letter-spacing:0.08em;text-transform:uppercase;color:#dd101f;margin-bottom:8px;">' . self::escape($title) . '</div>'
            . '<div style="color:#334155;line-height:1.7;">' . $content . '</div>'
            . '</div>';
    }

    private static function renderOrderItems(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $html = '<div style="margin:24px 0 0;">'
            . '<div style="font-size:13px;font-weight:900;letter-spacing:0.08em;text-transform:uppercase;color:#111827;margin-bottom:12px;">Siparis ozeti</div>'
            . '<div style="border:1px solid #e7ebf1;border-radius:22px;overflow:hidden;background:#ffffff;">';

        $count = count($items);
        foreach ($items as $index => $item) {
            $title = trim((string) ($item['product_name'] ?? 'Urun'));
            $variant = trim((string) ($item['variant_name'] ?? ''));
            $color = trim((string) ($item['variant_color'] ?? ''));
            $quantity = (int) ($item['quantity'] ?? 1);
            $lineTotal = number_format((float) ($item['line_total'] ?? 0), 2, ',', '.');
            $separator = $index < ($count - 1) ? 'border-bottom:1px solid #eef2f7;' : '';

            $meta = array_filter([$variant, $color]);
            $html .= '<div style="padding:16px 18px;' . $separator . '">'
                . '<div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;">'
                . '<div>'
                . '<div style="font-weight:800;color:#111827;margin-bottom:4px;">' . self::escape($title) . '</div>'
                . (!empty($meta)
                    ? '<div style="font-size:13px;color:#64748b;margin-bottom:4px;">' . self::escape(implode(' | ', $meta)) . '</div>'
                    : '')
                . '<div style="font-size:13px;color:#64748b;">Adet: ' . self::escape((string) $quantity) . '</div>'
                . '</div>'
                . '<div style="white-space:nowrap;font-weight:900;color:#111827;">' . self::escape($lineTotal) . ' TL</div>'
                . '</div>'
                . '</div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    private static function renderButton(string $url, string $label): string
    {
        return '<div style="margin:24px 0;">'
            . '<a href="' . self::escape($url) . '" style="display:inline-block;padding:15px 28px;border-radius:16px;background:linear-gradient(135deg,#ffcc00 0%,#f2b900 100%);border:1px solid #d8a400;color:#111827;text-decoration:none;font-weight:800;box-shadow:0 10px 24px rgba(255,204,0,0.28);">' . self::escape($label) . '</a>'
            . '</div>';
    }

    private static function renderBrandLogo(): string
    {
        $baseUrl = rtrim((string) env('APP_URL', 'https://www.boomeritems.com'), '/');
        $logoUrl = $baseUrl . '/Boomer.svg';

        return '<div style="display:inline-block;">'
            . '<img src="' . self::escape($logoUrl) . '" alt="BoomerItems" style="display:block;max-width:280px;width:100%;height:auto;margin:0 auto;">'
            . '</div>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
