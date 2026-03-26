<?php
/**
 * Eksik gorselleri bulan script
 * Kullanim: http://localhost/find-missing-images.php
 */
require_once __DIR__ . '/api/config.php';

header('Content-Type: text/html; charset=utf-8');

$pdo = db();

// product_images tablosundaki tum gorselleri al
$stmt = $pdo->query('
    SELECT pi.id, pi.product_id, pi.filename, pi.url, pi.is_primary,
           p.name as product_name
    FROM product_images pi
    LEFT JOIN products p ON p.id = pi.product_id
    ORDER BY p.name, pi.sort_order
');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$uploadDir = __DIR__ . '/uploads/products/';
$missing = [];
$found = 0;

echo '<h1>Gorsel Kontrol Raporu</h1>';
echo '<p>Upload dizini: ' . htmlspecialchars($uploadDir) . '</p>';
echo '<hr>';

foreach ($rows as $row) {
    $filename = $row['filename'] ?? '';
    if ($filename === '') continue;

    $mediumFile = $uploadDir . 'medium_' . $filename;
    $originalFile = $uploadDir . 'original_' . $filename;
    $thumbFile = $uploadDir . 'thumb_' . $filename;

    $mediumExists = file_exists($mediumFile);
    $originalExists = file_exists($originalFile);
    $thumbExists = file_exists($thumbFile);

    if (!$mediumExists || !$originalExists || !$thumbExists) {
        $missing[] = [
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'filename' => $filename,
            'url' => $row['url'],
            'medium' => $mediumExists,
            'original' => $originalExists,
            'thumb' => $thumbExists,
        ];
    } else {
        $found++;
    }
}

echo '<h2 style="color:green">Mevcut Gorseller: ' . $found . '</h2>';
echo '<h2 style="color:red">Eksik Gorseller: ' . count($missing) . '</h2>';

if (count($missing) > 0) {
    echo '<table border="1" cellpadding="8" cellspacing="0">';
    echo '<tr><th>Urun ID</th><th>Urun Adi</th><th>Dosya</th><th>Medium</th><th>Original</th><th>Thumb</th></tr>';
    foreach ($missing as $m) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($m['product_id']) . '</td>';
        echo '<td>' . htmlspecialchars($m['product_name']) . '</td>';
        echo '<td style="font-size:11px">' . htmlspecialchars($m['filename']) . '</td>';
        echo '<td style="color:' . ($m['medium'] ? 'green' : 'red') . '">' . ($m['medium'] ? 'VAR' : 'YOK') . '</td>';
        echo '<td style="color:' . ($m['original'] ? 'green' : 'red') . '">' . ($m['original'] ? 'VAR' : 'YOK') . '</td>';
        echo '<td style="color:' . ($m['thumb'] ? 'green' : 'red') . '">' . ($m['thumb'] ? 'VAR' : 'YOK') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '<br><p><strong>Bu urunlerin gorsellerini yeniden yuklemeniz gerekiyor.</strong></p>';
} else {
    echo '<p style="color:green"><strong>Tum gorseller mevcut!</strong></p>';
}
