<?php
// ═══════════════════════════════════════════════
//  Base64 Image Migration Script
//  Converts all base64 images to actual files
// ═══════════════════════════════════════════════

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

echo "Starting base64 image migration...\n\n";

// Find all products with base64 images
$stmt = db()->query("
    SELECT id, name, img, images
    FROM products
    WHERE img LIKE 'data:image%'
    OR images LIKE '%data:image%'
");

$products = $stmt->fetchAll();
$total = count($products);
$success = 0;
$errors = 0;

echo "Found $total products with base64 images\n\n";

foreach ($products as $i => $product) {
    $productId = $product['id'];
    $productName = $product['name'];
    $num = $i + 1;

    echo "[$num/$total] Processing: $productName ($productId)\n";

    try {
        // Collect base64 images
        $base64Images = [];

        // Check img field
        if (!empty($product['img']) && str_starts_with($product['img'], 'data:image')) {
            $base64Images[] = $product['img'];
        }

        // Check images JSON field
        if (!empty($product['images'])) {
            $imagesArray = json_decode($product['images'], true) ?: [];
            foreach ($imagesArray as $img) {
                if (is_string($img) && str_starts_with($img, 'data:image')) {
                    $base64Images[] = $img;
                }
            }
        }

        if (empty($base64Images)) {
            echo "  ⚠ No base64 images found\n";
            continue;
        }

        echo "  Found " . count($base64Images) . " base64 image(s)\n";

        // Convert each base64 to file
        $savedUrls = [];
        foreach ($base64Images as $j => $dataUri) {
            // Parse data URI
            if (!preg_match('#^data:image/(\w+);base64,(.+)$#s', $dataUri, $matches)) {
                echo "  ✗ Invalid data URI format (image " . ($j + 1) . ")\n";
                continue;
            }

            $ext = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
            $base64Data = $matches[2];

            // Decode base64
            $rawData = base64_decode($base64Data);
            if ($rawData === false || strlen($rawData) < 100) {
                echo "  ✗ Failed to decode base64 (image " . ($j + 1) . ")\n";
                continue;
            }

            // Generate filename
            $date = date('Ymd');
            $random = bin2hex(random_bytes(12));
            $filename = "{$date}_{$random}.{$ext}";

            // Save original
            $uploadDir = __DIR__ . '/../uploads/products';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $originalPath = "{$uploadDir}/original_{$filename}";
            file_put_contents($originalPath, $rawData);

            // Create medium version (800x800)
            $image = null;
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $image = imagecreatefromjpeg($originalPath);
            } elseif ($ext === 'png') {
                $image = imagecreatefrompng($originalPath);
            } elseif ($ext === 'gif') {
                $image = imagecreatefromgif($originalPath);
            } elseif ($ext === 'webp') {
                $image = imagecreatefromwebp($originalPath);
            }

            if ($image) {
                $width = imagesx($image);
                $height = imagesy($image);

                // Calculate new dimensions
                $maxWidth = 800;
                $maxHeight = 800;

                if ($width > $maxWidth || $height > $maxHeight) {
                    $ratio = min($maxWidth / $width, $maxHeight / $height);
                    $newWidth = round($width * $ratio);
                    $newHeight = round($height * $ratio);
                } else {
                    $newWidth = $width;
                    $newHeight = $height;
                }

                $newImage = imagecreatetruecolor($newWidth, $newHeight);

                // Preserve transparency for PNG
                if ($ext === 'png') {
                    imagealphablending($newImage, false);
                    imagesavealpha($newImage, true);
                }

                imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

                $mediumPath = "{$uploadDir}/medium_{$filename}";

                if ($ext === 'jpg' || $ext === 'jpeg') {
                    imagejpeg($newImage, $mediumPath, 82);
                } elseif ($ext === 'png') {
                    imagepng($newImage, $mediumPath, 6);
                } elseif ($ext === 'gif') {
                    imagegif($newImage, $mediumPath);
                } elseif ($ext === 'webp') {
                    imagewebp($newImage, $mediumPath, 82);
                }

                imagedestroy($image);
                imagedestroy($newImage);

                $url = "https://www.boomeritems.com/uploads/products/medium_{$filename}";
                $savedUrls[] = $url;

                echo "  ✓ Saved: medium_{$filename}\n";
            } else {
                echo "  ✗ Failed to create image resource (image " . ($j + 1) . ")\n";
            }
        }

        if (!empty($savedUrls)) {
            // Update database
            $primaryUrl = $savedUrls[0];
            $imagesJson = json_encode($savedUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            db()->prepare("
                UPDATE products
                SET img = ?, images = ?
                WHERE id = ?
            ")->execute([$primaryUrl, $imagesJson, $productId]);

            echo "  ✓ Updated database with " . count($savedUrls) . " URL(s)\n";
            $success++;
        } else {
            echo "  ✗ No images were saved\n";
            $errors++;
        }

    } catch (Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
        $errors++;
    }

    echo "\n";
}

echo "\n═══════════════════════════════════════════════\n";
echo "Migration completed!\n";
echo "Success: $success products\n";
echo "Errors: $errors products\n";
echo "═══════════════════════════════════════════════\n";
