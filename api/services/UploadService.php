<?php
// ═══════════════════════════════════════════════
//  UploadService
//  Güvenli dosya yükleme — tüm validasyon burada
// ═══════════════════════════════════════════════

class UploadService
{
    // İzin verilen MIME tipleri ve uzantı eşleşmeleri
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    // Görsel boyutları: [max_width, max_height, quality]
    private const SIZES = [
        'original' => [2000, 2000, 85],   // tam boyut (sıkıştırılmış)
        'medium'   => [800,  800,  82],   // ürün listesi
        'thumb'    => [300,  300,  80],   // küçük önizleme
    ];

    // ─── Ana Yükleme Fonksiyonu ──────────────

    /**
     * $_FILES['image'] gibi bir dosyayı alır, doğrular ve kaydeder.
     *
     * @param array  $file        $_FILES array elemanı
     * @param string $productId   İlgili ürün ID
     * @param ?string $uploadedBy Admin user ID
     * @param bool   $isPrimary   Ana görsel mi?
     * @return array              Kaydedilen görsel bilgisi
     */
    public static function saveProductImage(
        array $file,
        string $productId,
        ?string $uploadedBy,
        bool  $isPrimary = false
    ): array {
        // 1. PHP upload hatası kontrolü
        self::checkUploadError($file['error'] ?? UPLOAD_ERR_NO_FILE);

        // 2. Dosya boyutu kontrolü
        $maxBytes = (int) env('UPLOAD_MAX_MB', 5) * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            throw new RuntimeException(
                'Dosya boyutu çok büyük. Maksimum: ' . env('UPLOAD_MAX_MB', 5) . 'MB'
            );
        }
        if ($file['size'] < 100) {
            throw new RuntimeException('Dosya çok küçük veya bozuk.');
        }

        // 3. Gerçek MIME tipi kontrolü (finfo ile — extension'a güvenme!)
        $realMime = self::detectMime($file['tmp_name']);
        if (!array_key_exists($realMime, self::ALLOWED)) {
            AuditLog::write('upload.fail', $uploadedBy, 'product', $productId, [
                'reason' => 'invalid_mime',
                'mime'   => $realMime,
            ]);
            throw new RuntimeException(
                'Desteklenmeyen dosya tipi. Sadece JPG, PNG, GIF, WEBP yüklenebilir.'
            );
        }

        // 4. Görsel mi gerçekten? (image bomb koruması)
        $imageInfo = @getimagesize($file['tmp_name']);
        if (!$imageInfo) {
            throw new RuntimeException('Dosya geçerli bir görsel değil.');
        }

        [$origWidth, $origHeight] = $imageInfo;

        // Çok büyük çözünürlük = image bomb riski
        if ($origWidth > 8000 || $origHeight > 8000) {
            throw new RuntimeException('Görsel çözünürlüğü çok yüksek. Maksimum 8000x8000px.');
        }

        // 5. Benzersiz dosya adı üret (path traversal imkansız)
        $ext      = self::ALLOWED[$realMime];
        $baseName = self::generateFilename($ext);

        // 6. Upload dizinini hazırla
        $uploadDir = self::getUploadDir();

        // 7. Görsel boyutlarını üret (original, medium, thumb)
        $savedFiles = self::processAndSave($file['tmp_name'], $realMime, $baseName, $uploadDir);

        // 8. DB'ye kaydet
        $appUrl  = rtrim(env('APP_URL', 'http://localhost'), '/');
        $url     = $appUrl . '/uploads/products/medium_' . $baseName;

        // Eğer bu primary olacaksa diğerlerini kaldır
        if ($isPrimary) {
            db()->prepare(
                'UPDATE product_images SET is_primary = 0 WHERE product_id = ?'
            )->execute([$productId]);
        }

        // Mevcut görsel sayısını sort_order için al
        $countStmt = db()->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ?');
        $countStmt->execute([$productId]);
        $count = (int) $countStmt->fetchColumn();

        $stmt = db()->prepare(
            'INSERT INTO product_images
             (product_id, filename, url, alt_text, size_bytes, width, height, is_primary, sort_order, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $productId,
            $baseName,
            $url,
            null,          // alt_text sonradan güncellenebilir
            $file['size'],
            $origWidth,
            $origHeight,
            $isPrimary ? 1 : 0,
            $count,
            $uploadedBy,
        ]);
        $imageId = (int) db()->lastInsertId();

        AuditLog::write(AuditLog::UPLOAD_SUCCESS, $uploadedBy, 'product_image', $imageId, [
            'product_id' => $productId,
            'filename'   => $baseName,
            'size'       => $file['size'],
        ]);

        return [
            'id'         => $imageId,
            'url'        => $url,
            'urls'       => [
                'original' => $appUrl . '/uploads/products/original_' . $baseName,
                'medium'   => $appUrl . '/uploads/products/medium_'   . $baseName,
                'thumb'    => $appUrl . '/uploads/products/thumb_'    . $baseName,
            ],
            'width'      => $origWidth,
            'height'     => $origHeight,
            'size_bytes' => $file['size'],
            'is_primary' => $isPrimary,
        ];
    }

    // ─── Görsel Sil ──────────────────────────

    /**
     * Görseli DB'den ve diskten sil.
     */
    public static function deleteProductImage(int $imageId, ?string $deletedBy): bool
    {
        $stmt = db()->prepare('SELECT * FROM product_images WHERE id = ? LIMIT 1');
        $stmt->execute([$imageId]);
        $image = $stmt->fetch();

        if (!$image) return false;

        // Disk'ten sil (tüm boyutlar)
        $uploadDir = self::getUploadDir();
        foreach (['original_', 'medium_', 'thumb_', ''] as $prefix) {
            $path = $uploadDir . '/' . $prefix . $image['filename'];
            if (file_exists($path)) @unlink($path);
        }

        // DB'den sil
        db()->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);

        // Primary silinmişse bir sonrakini primary yap
        if ($image['is_primary']) {
            db()->prepare(
                'UPDATE product_images SET is_primary = 1 WHERE product_id = ? ORDER BY sort_order ASC LIMIT 1'
            )->execute([$image['product_id']]);
        }

        AuditLog::write('upload.delete', $deletedBy, 'product_image', $imageId, [
            'filename'   => $image['filename'],
            'product_id' => $image['product_id'],
        ]);

        return true;
    }

    // ─── Görselleri Getir ─────────────────────

    public static function getProductImages(string $productId): array
    {
        $appUrl = rtrim(env('APP_URL', 'http://localhost'), '/');
        $stmt   = db()->prepare(
            'SELECT id, filename, url, alt_text, size_bytes, width, height, is_primary, sort_order
             FROM product_images WHERE product_id = ? ORDER BY sort_order ASC'
        );
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['urls'] = [
                'original' => $appUrl . '/uploads/products/original_' . $row['filename'],
                'medium'   => $appUrl . '/uploads/products/medium_'   . $row['filename'],
                'thumb'    => $appUrl . '/uploads/products/thumb_'    . $row['filename'],
            ];
        }

        return $rows;
    }

    // ─── Görsel İşleme (GD ile resize) ───────

    private static function processAndSave(
        string $tmpPath,
        string $mime,
        string $baseName,
        string $uploadDir
    ): array {
        $saved = [];

        foreach (self::SIZES as $sizeName => [$maxW, $maxH, $quality]) {
            $destName = ($sizeName === 'original' ? 'original_' : $sizeName . '_') . $baseName;
            $destPath = $uploadDir . '/' . $destName;

            self::resizeAndSave($tmpPath, $mime, $destPath, $maxW, $maxH, $quality);
            $saved[$sizeName] = $destName;
        }

        return $saved;
    }

    private static function resizeAndSave(
        string $srcPath,
        string $mime,
        string $destPath,
        int    $maxW,
        int    $maxH,
        int    $quality
    ): void {
        // GD kütüphanesi kontrolü
        if (!extension_loaded('gd')) {
            // GD yoksa dosyayı olduğu gibi kopyala
            copy($srcPath, $destPath);
            return;
        }

        // Kaynak görseli yükle
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($srcPath),
            'image/png'  => @imagecreatefrompng($srcPath),
            'image/gif'  => @imagecreatefromgif($srcPath),
            'image/webp' => @imagecreatefromwebp($srcPath),
            default      => false,
        };

        if (!$src) {
            copy($srcPath, $destPath);
            return;
        }

        $origW = imagesx($src);
        $origH = imagesy($src);

        // Boyut hesaplama (orantılı)
        [$newW, $newH] = self::calcDimensions($origW, $origH, $maxW, $maxH);

        // Hedef canvas oluştur
        $dst = imagecreatetruecolor($newW, $newH);

        // PNG/GIF için şeffaflık koru
        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        // Kaydet
        match ($mime) {
            'image/jpeg' => imagejpeg($dst, $destPath, $quality),
            'image/png'  => imagepng($dst, $destPath, (int) round((100 - $quality) / 10)),
            'image/gif'  => imagegif($dst, $destPath),
            'image/webp' => imagewebp($dst, $destPath, $quality),
            default      => copy($srcPath, $destPath),
        };

        imagedestroy($src);
        imagedestroy($dst);
    }

    private static function calcDimensions(int $w, int $h, int $maxW, int $maxH): array
    {
        if ($w <= $maxW && $h <= $maxH) return [$w, $h];

        $ratio  = min($maxW / $w, $maxH / $h);
        return [(int) round($w * $ratio), (int) round($h * $ratio)];
    }

    // ─── Yardımcı ─────────────────────────────

    private static function detectMime(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($path) ?: 'application/octet-stream';
    }

    private static function generateFilename(string $ext): string
    {
        return sprintf(
            '%s_%s.%s',
            date('Ymd'),
            bin2hex(random_bytes(12)),   // 24 hex karakter
            $ext
        );
    }

    private static function getUploadDir(): string
    {
        $base = rtrim(env('UPLOAD_DIR', __DIR__ . '/../../public/uploads'), '/');
        $dir  = $base . '/products';

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException('Upload dizini oluşturulamadı.');
            }
        }

        return $dir;
    }

    private static function checkUploadError(int $errorCode): void
    {
        match ($errorCode) {
            UPLOAD_ERR_OK       => null,
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => throw new RuntimeException('Dosya boyutu çok büyük.'),
            UPLOAD_ERR_PARTIAL   => throw new RuntimeException('Dosya eksik yüklendi. Tekrar deneyin.'),
            UPLOAD_ERR_NO_FILE   => throw new RuntimeException('Dosya seçilmedi.'),
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE => throw new RuntimeException('Sunucu depolama hatası.'),
            UPLOAD_ERR_EXTENSION  => throw new RuntimeException('Dosya uzantısı engellendi.'),
            default               => throw new RuntimeException('Bilinmeyen yükleme hatası.'),
        };
    }
}
