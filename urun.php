<?php
declare(strict_types=1);

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/helpers.php';

function pageEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pagePlainText(mixed $value): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
    return $text;
}

function pageTruncate(string $value, int $limit): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $limit - 3), 'UTF-8')) . '...';
    }

    if (strlen($value) <= $limit) {
        return $value;
    }

    return rtrim(substr($value, 0, max(0, $limit - 3))) . '...';
}

function pageMoney(float $amount): string
{
    return number_format($amount, 2, ',', '.') . ' TL';
}

function pageMetaDescription(array $product): string
{
    $parts = [];

    $category = trim((string) ($product['category'] ?? ''));
    $brand = trim((string) ($product['brand'] ?? ''));
    $pieces = (int) ($product['pieces'] ?? 0);
    $condition = trim((string) ($product['conditionLabel'] ?? ($product['condition_label'] ?? '')));
    $price = (float) ($product['price'] ?? 0);
    $summary = pagePlainText($product['description'] ?? '');

    if ($category !== '') {
        $parts[] = $category;
    }
    if ($brand !== '') {
        $parts[] = $brand;
    }
    if ($pieces > 0) {
        $parts[] = number_format($pieces, 0, ',', '.') . ' parca';
    }
    if ($condition !== '') {
        $parts[] = 'Durum: ' . $condition;
    }
    if ($price > 0) {
        $parts[] = 'Fiyat: ' . pageMoney($price);
    }

    $prefix = trim((string) ($product['title'] ?? ($product['name'] ?? '')));
    $meta = $prefix;
    if ($parts !== []) {
        $meta .= '. ' . implode(', ', $parts) . '.';
    }
    if ($summary !== '') {
        $meta .= ' ' . $summary;
    }

    return pageTruncate(trim($meta), 160);
}

$slug = trim((string) ($_GET['slug'] ?? ''));
$product = $slug !== '' ? fetchProductDetailBySlug($slug) : null;
$isNotFound = $product === null;

if ($isNotFound) {
    http_response_code(404);
}

$baseUrl = currentBaseUrl();
$pageUrl = $isNotFound
    ? toAbsoluteUrl($_SERVER['REQUEST_URI'] ?? '/urun', $baseUrl)
    : toAbsoluteUrl('/urun/' . rawurlencode((string) $product['slug']), $baseUrl);

$siteName = 'BoomerItems';
$fallbackImage = toAbsoluteUrl('/logo_v2_full.png', $baseUrl);

$title = $isNotFound
    ? 'Urun Bulunamadi | ' . $siteName
    : trim((string) ($product['title'] ?? $product['name'])) . ' | ' . $siteName;

$description = $isNotFound
    ? 'Aradiginiz urun bulunamadi. Dilerseniz BoomerItems anasayfasina donup diger koleksiyon urunlerini inceleyebilirsiniz.'
    : pageMetaDescription($product);
$productDescriptionText = !$isNotFound ? pagePlainText($product['description'] ?? '') : '';

$galleryItems = [];
if (!$isNotFound) {
    foreach (($product['gallery'] ?? []) as $galleryItem) {
        $url = trim((string) ($galleryItem['url'] ?? ''));
        if ($url === '') {
            continue;
        }

        $galleryItems[] = [
            'url' => toAbsoluteUrl($url, $baseUrl),
            'alt' => trim((string) ($galleryItem['alt_text'] ?? '')) ?: trim((string) ($product['title'] ?? $product['name'] ?? '')),
        ];
    }

    if ($galleryItems === []) {
        foreach (($product['images'] ?? []) as $imageUrl) {
            $imageUrl = trim((string) $imageUrl);
            if ($imageUrl === '') {
                continue;
            }

            $galleryItems[] = [
                'url' => toAbsoluteUrl($imageUrl, $baseUrl),
                'alt' => trim((string) ($product['title'] ?? $product['name'] ?? '')),
            ];
        }
    }
}

$primaryImage = $galleryItems[0]['url'] ?? (!$isNotFound ? toAbsoluteUrl((string) ($product['img'] ?? ''), $baseUrl) : $fallbackImage);
if ($primaryImage === '') {
    $primaryImage = $fallbackImage;
}

$activeVariants = [];
if (!$isNotFound) {
    foreach (($product['variants'] ?? []) as $variant) {
        if ((int) ($variant['is_active'] ?? 1) !== 1) {
            continue;
        }
        $activeVariants[] = $variant;
    }
}

$defaultVariantId = '';
$defaultVariantColor = '';
$defaultVariantColorCode = '#d0d5dd';
if ($activeVariants !== []) {
    foreach ($activeVariants as $variant) {
        if ((int) ($variant['available_stock'] ?? 0) > 0) {
            $defaultVariantId = (string) ($variant['id'] ?? '');
            $defaultVariantColor = (string) ($variant['color'] ?? '');
            $candidateColorCode = trim((string) ($variant['color_code'] ?? ''));
            if ($candidateColorCode !== '') {
                $defaultVariantColorCode = $candidateColorCode;
            }
            break;
        }
    }

    if ($defaultVariantId === '') {
        $defaultVariantId = (string) ($activeVariants[0]['id'] ?? '');
        $defaultVariantColor = (string) ($activeVariants[0]['color'] ?? '');
        $candidateColorCode = trim((string) ($activeVariants[0]['color_code'] ?? ''));
        if ($candidateColorCode !== '') {
            $defaultVariantColorCode = $candidateColorCode;
        }
    }
}

$availabilityUrl = (!$isNotFound && !empty($product['in_stock']))
    ? 'https://schema.org/InStock'
    : 'https://schema.org/OutOfStock';

$conditionValue = strtolower(trim((string) (!$isNotFound ? ($product['conditionTag'] ?? $product['condition_tag'] ?? '') : '')));
$conditionUrl = in_array($conditionValue, ['2. el', 'used', '2'], true)
    ? 'https://schema.org/UsedCondition'
    : 'https://schema.org/NewCondition';

$jsonLd = null;
if (!$isNotFound) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => (string) ($product['title'] ?? $product['name']),
        'description' => $description,
        'sku' => (string) ($product['sku'] ?? ($product['set_no'] ?? '')),
        'image' => array_values(array_map(static fn(array $item): string => $item['url'], $galleryItems ?: [['url' => $primaryImage]])),
        'category' => (string) ($product['category'] ?? ''),
        'brand' => !empty($product['brand']) ? [
            '@type' => 'Brand',
            'name' => (string) $product['brand'],
        ] : null,
        'offers' => [
            '@type' => 'Offer',
            'url' => $pageUrl,
            'priceCurrency' => 'TRY',
            'price' => number_format((float) ($product['price'] ?? 0), 2, '.', ''),
            'availability' => $availabilityUrl,
            'itemCondition' => $conditionUrl,
        ],
    ];

    $jsonLd = array_filter($jsonLd, static fn(mixed $value): bool => $value !== null && $value !== '');
}

$productPayload = !$isNotFound ? [
    'id' => (string) ($product['id'] ?? ''),
    'slug' => (string) ($product['slug'] ?? ''),
    'title' => (string) ($product['title'] ?? ($product['name'] ?? '')),
    'category' => (string) ($product['category'] ?? ($product['category_name'] ?? '')),
    'description' => (string) ($product['description'] ?? ''),
    'conditionLabel' => (string) ($product['conditionLabel'] ?? ($product['condition_label'] ?? '')),
    'img' => $primaryImage,
    'desi' => (float) ($product['desi'] ?? 1),
    'price' => (float) ($product['price'] ?? 0),
    'oldPrice' => (float) ($product['oldPrice'] ?? ($product['old_price'] ?? 0)),
    'stock' => (int) ($product['available_stock'] ?? ($product['stock'] ?? 0)),
    'availableStock' => (int) ($product['available_stock'] ?? ($product['stock'] ?? 0)),
    'inStock' => !empty($product['in_stock']),
    'hasVariants' => !empty($product['hasVariants']),
    'variants' => array_values(array_map(static function (array $variant): array {
        return [
            'id' => (string) ($variant['id'] ?? ''),
            'color' => (string) ($variant['color'] ?? ''),
            'color_code' => (string) ($variant['color_code'] ?? ''),
            'sku' => (string) ($variant['sku'] ?? ''),
            'stock' => (int) ($variant['stock'] ?? 0),
            'available_stock' => (int) ($variant['available_stock'] ?? 0),
            'price_diff' => (float) ($variant['price_diff'] ?? 0),
            'is_active' => (int) ($variant['is_active'] ?? 1),
        ];
    }, $activeVariants)),
] : null;
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= pageEscape($title) ?></title>
    <meta name="description" content="<?= pageEscape($description) ?>">
    <meta name="robots" content="<?= $isNotFound ? 'noindex, nofollow' : 'index, follow' ?>">
    <meta name="theme-color" content="#f4b400">
    <link rel="canonical" href="<?= pageEscape($pageUrl) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon_zoomed.png">
    <link rel="icon" type="image/svg+xml" href="/Boomer.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Manrope:wght@400;500;700;800&display=swap" rel="stylesheet">
    <meta property="og:locale" content="tr_TR">
    <meta property="og:site_name" content="<?= pageEscape($siteName) ?>">
    <meta property="og:type" content="<?= $isNotFound ? 'website' : 'product' ?>">
    <meta property="og:title" content="<?= pageEscape($title) ?>">
    <meta property="og:description" content="<?= pageEscape($description) ?>">
    <meta property="og:url" content="<?= pageEscape($pageUrl) ?>">
    <meta property="og:image" content="<?= pageEscape($primaryImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= pageEscape($title) ?>">
    <meta name="twitter:description" content="<?= pageEscape($description) ?>">
    <meta name="twitter:image" content="<?= pageEscape($primaryImage) ?>">
    <?php if (!$isNotFound): ?>
    <meta property="product:price:amount" content="<?= pageEscape(number_format((float) ($product['price'] ?? 0), 2, '.', '')) ?>">
    <meta property="product:price:currency" content="TRY">
    <?php endif; ?>
    <?php if ($jsonLd !== null): ?>
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php endif; ?>
    <style>
        :root {
            --bg: #f6f3ea;
            --surface: rgba(255, 255, 255, 0.9);
            --ink: #1f2937;
            --muted: #667085;
            --line: rgba(31, 41, 55, 0.12);
            --brand: #f4b400;
            --brand-deep: #da1f26;
            --success: #14804a;
            --warning: #a15c07;
            --shadow: 0 24px 80px rgba(31, 41, 55, 0.12);
            --radius: 26px;
            --site-banner-height: 42px;
            --site-header-height: 100px;
            --mobile-header-height: 62px;
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            padding-top: calc(var(--site-banner-height) + var(--site-header-height));
            color: var(--ink);
            font-family: "Manrope", system-ui, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(244, 180, 0, 0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(218, 31, 38, 0.1), transparent 24%),
                linear-gradient(180deg, #fffef8 0%, #f6f3ea 100%);
        }

        body.mobile-nav-open {
            overflow: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page-shell { min-height: 100vh; }

        .top-shipping-banner {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 18000;
            height: var(--site-banner-height);
            display: flex;
            align-items: center;
            overflow: hidden;
            border-bottom: 1px solid rgba(255, 204, 0, 0.28);
            background: linear-gradient(90deg, #0f172a 0%, #111827 52%, #1f2937 100%);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.24);
        }

        .top-shipping-banner::before,
        .top-shipping-banner::after {
            content: "";
            position: absolute;
            top: 0;
            bottom: 0;
            width: 72px;
            z-index: 1;
            pointer-events: none;
        }

        .top-shipping-banner::before {
            left: 0;
            background: linear-gradient(90deg, #0f172a 0%, rgba(15, 23, 42, 0) 100%);
        }

        .top-shipping-banner::after {
            right: 0;
            background: linear-gradient(270deg, #1f2937 0%, rgba(31, 41, 55, 0) 100%);
        }

        .top-shipping-banner-track {
            display: flex;
            align-items: center;
            flex: 0 0 auto;
            min-width: max-content;
            animation: shippingBannerMarquee 30s linear infinite;
        }

        .top-shipping-banner-text {
            display: inline-flex;
            align-items: center;
            gap: 24px;
            padding-right: 24px;
            font-size: 0.88rem;
            font-weight: 900;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            white-space: nowrap;
            color: #fff7cc;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
        }

        .top-shipping-banner-text strong {
            color: #ffcc00;
        }

        .top-shipping-banner-dot {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: #ffcc00;
            box-shadow: 0 0 12px rgba(255, 204, 0, 0.72);
            flex: 0 0 auto;
        }

        @keyframes shippingBannerMarquee {
            0% { transform: translate3d(0, 0, 0); }
            100% { transform: translate3d(-50%, 0, 0); }
        }

        .site-header-desktop {
            position: fixed;
            top: var(--site-banner-height);
            left: 0;
            right: 0;
            z-index: 17000;
            display: grid;
            grid-template-columns: 300px 1fr auto;
            align-items: center;
            gap: 24px;
            height: var(--site-header-height);
            padding: 0 28px;
            background: linear-gradient(to bottom, rgba(28, 25, 23, 0.36), rgba(28, 25, 23, 0.16));
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: none;
        }

        .site-header-desktop::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 1px;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }

        .content,
        .footer-inner {
            width: min(1200px, calc(100% - 32px));
            margin: 0 auto;
        }

        .site-header-mobile,
        .mobile-menu-overlay,
        .mobile-nav-drawer {
            display: none;
        }

        .site-header-desktop .brand {
            display: flex;
            align-items: center;
            justify-self: start;
            width: 320px;
            overflow: visible;
            text-decoration: none;
        }

        .site-header-desktop .brand img {
            height: 110px;
            width: auto;
            object-fit: contain;
            filter: drop-shadow(0 0 18px rgba(255, 255, 255, 0.42)) drop-shadow(0 10px 20px rgba(0, 0, 0, 0.24));
            transform: scale(1.32);
            transform-origin: left center;
        }

        .site-header-desktop .nav-links {
            display: flex;
            align-items: center;
            justify-self: center;
            gap: 34px;
            white-space: nowrap;
        }

        .site-header-desktop .nav-link {
            position: relative;
            padding: 6px 0;
            border: none;
            background: transparent;
            color: #f8fafc;
            font-size: 0.95rem;
            font-weight: 900;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            text-decoration: none;
            cursor: pointer;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
        }

        .site-header-desktop .nav-link.active {
            color: #ffcc00;
        }

        .site-header-desktop .nav-link.active::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: -8px;
            height: 3px;
            border-radius: 999px;
            background: #ffcc00;
        }

        .site-header-desktop .actions {
            display: flex;
            align-items: center;
            justify-self: end;
            gap: 16px;
            white-space: nowrap;
        }

        .site-header-desktop .icon-btn,
        .site-header-mobile .icon-btn,
        .mobile-menu-toggle {
            position: relative;
            width: 36px;
            height: 36px;
            padding: 0;
            border: none;
            background: transparent;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.2));
        }

        .site-header-desktop .icon-btn svg,
        .site-header-mobile .icon-btn svg,
        .mobile-menu-toggle svg,
        .mobile-drawer-close svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .site-header-desktop .tracking-btn svg {
            width: 19px;
            height: 19px;
        }

        .cart-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            display: none;
            align-items: center;
            justify-content: center;
            min-width: 17px;
            height: 17px;
            padding: 0 5px;
            border-radius: 999px;
            background: #dd101f;
            color: #fff;
            font-size: 0.62rem;
            font-weight: 900;
        }

        .content {
            padding: 34px 0 64px;
        }

        .breadcrumbs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 700;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.02fr) minmax(340px, 0.98fr);
            gap: 28px;
            align-items: start;
        }

        .panel {
            background: var(--surface);
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: var(--shadow);
            border-radius: var(--radius);
        }

        .gallery-panel { padding: 24px; }

        .main-image {
            position: relative;
            display: grid;
            place-items: center;
            min-height: 520px;
            border-radius: 22px;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(249, 247, 240, 0.92)),
                linear-gradient(135deg, rgba(244, 180, 0, 0.12), rgba(255, 255, 255, 0));
            border: 1px solid rgba(31, 41, 55, 0.06);
            overflow: hidden;
        }

        .main-image img {
            width: min(100%, 560px);
            max-height: 520px;
            object-fit: contain;
            mix-blend-mode: multiply;
        }

        .thumb-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(88px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .thumb-btn {
            appearance: none;
            border: 1px solid rgba(31, 41, 55, 0.1);
            border-radius: 18px;
            padding: 8px;
            background: rgba(255, 255, 255, 0.8);
            cursor: pointer;
            transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
        }

        .thumb-btn.is-active {
            border-color: rgba(244, 180, 0, 0.9);
            box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.16);
        }

        .thumb-btn img {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: contain;
            mix-blend-mode: multiply;
        }

        .info-panel { padding: 30px; }

        .eyebrow {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            background: rgba(31, 41, 55, 0.06);
            color: var(--ink);
        }

        .pill.brand {
            background: rgba(244, 180, 0, 0.14);
        }

        .pill.condition-new {
            background: rgba(20, 128, 74, 0.12);
            color: var(--success);
        }

        .pill.condition-used {
            background: rgba(161, 92, 7, 0.12);
            color: var(--warning);
        }

        .product-title {
            margin: 0;
            font-family: "Bebas Neue", sans-serif;
            font-size: clamp(2.4rem, 4.6vw, 4.4rem);
            line-height: 0.94;
            letter-spacing: 0.03em;
        }

        .product-subline {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin: 16px 0 24px;
            color: var(--muted);
            font-size: 0.95rem;
            font-weight: 700;
        }

        .price-block {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 12px;
            margin-bottom: 22px;
        }

        .current-price {
            font-size: clamp(2rem, 4vw, 3.2rem);
            line-height: 1;
            font-weight: 800;
            color: var(--brand-deep);
        }

        .old-price {
            font-size: 1rem;
            font-weight: 800;
            color: #98a2b3;
            text-decoration: line-through;
            padding-bottom: 8px;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 22px;
        }

        .feature-card {
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(31, 41, 55, 0.04);
            border: 1px solid rgba(31, 41, 55, 0.08);
        }

        .feature-card span {
            display: block;
            color: var(--muted);
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .feature-card strong {
            display: block;
            margin-top: 6px;
            font-size: 1rem;
            line-height: 1.35;
        }

        .description {
            margin: 0;
            color: #344054;
            font-size: 1rem;
            line-height: 1.8;
        }

        .detail-category-tag {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 8px 14px;
            background: rgba(17, 24, 39, 0.06);
            color: #374151;
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .detail-price-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 12px;
            margin-bottom: 22px;
        }

        .detail-price {
            font-size: clamp(2rem, 4vw, 3.2rem);
            line-height: 1;
            font-weight: 800;
            color: var(--brand-deep);
        }

        .detail-old-price {
            font-size: 1rem;
            font-weight: 800;
            color: #98a2b3;
            text-decoration: line-through;
            padding-bottom: 8px;
        }

        .detail-description {
            margin: 0;
            color: #344054;
            font-size: 1rem;
            line-height: 1.8;
        }

        .detail-stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 18px 0 16px;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 0.92rem;
            font-weight: 800;
            border: 1px solid rgba(17, 24, 39, 0.08);
            background: rgba(255, 255, 255, 0.92);
        }

        .detail-stock-badge.in-stock {
            color: var(--success);
            background: rgba(20, 128, 74, 0.08);
            border-color: rgba(20, 128, 74, 0.16);
        }

        .detail-stock-badge.out-of-stock {
            color: #b42318;
            background: rgba(217, 45, 32, 0.08);
            border-color: rgba(217, 45, 32, 0.16);
        }

        .product-stock-total {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 12px 0 18px;
            color: #475467;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .product-stock-total strong {
            font-weight: 900;
        }

        .variant-label {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.94rem;
            font-weight: 800;
            color: #1f2937;
        }

        .variant-label span {
            color: var(--muted);
            font-size: 0.82rem;
            font-weight: 700;
        }

        .variant-label strong {
            font-weight: 900;
        }

        .detail-qty-panel {
            margin: 18px 0 8px;
        }

        .detail-qty-panel .variant-label {
            margin-bottom: 12px;
        }

        .qty-stepper {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 4px;
            background: #fff;
        }

        .qty-stepper button {
            width: 42px;
            height: 40px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            font-size: 1.2rem;
            font-weight: 900;
            cursor: pointer;
            color: #111827;
        }

        .qty-stepper button[disabled] {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .qty-stepper span {
            width: 36px;
            text-align: center;
            font-weight: 900;
        }

        .variant-section {
            margin: 22px 0 8px;
            padding: 18px;
            border-radius: 24px;
            border: 1px solid rgba(17, 24, 39, 0.08);
            background: linear-gradient(180deg, rgba(255, 248, 220, 0.58) 0%, rgba(255, 255, 255, 0.96) 100%);
        }

        .variant-section-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }

        .variant-heading {
            min-width: 0;
        }

        .variant-heading h2 {
            margin: 0;
            font-size: 0.92rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .variant-heading p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 0.86rem;
            line-height: 1.5;
        }

        .variant-selected-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(244, 180, 0, 0.28);
            background: rgba(255, 255, 255, 0.88);
            white-space: nowrap;
            font-size: 0.88rem;
            font-weight: 800;
            color: #1f2937;
        }

        .variant-selected-chip strong {
            font-weight: 900;
        }

        .variant-selected-dot {
            width: 16px;
            height: 16px;
            border-radius: 999px;
            border: 1px solid rgba(31, 41, 55, 0.18);
            flex: 0 0 auto;
        }

        .variant-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(148px, 1fr));
            gap: 12px;
        }

        .variant-option {
            position: relative;
        }

        .variant-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .variant-option label {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            min-height: 62px;
            padding: 12px 14px;
            border-radius: 18px;
            border: 1px solid rgba(31, 41, 55, 0.12);
            background: rgba(255, 255, 255, 0.88);
            cursor: pointer;
            transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
        }

        .variant-option input:checked + label {
            border-color: rgba(244, 180, 0, 0.9);
            box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.14);
            transform: translateY(-1px);
        }

        .variant-option.is-disabled label {
            opacity: 0.55;
        }

        .variant-swatch {
            width: 18px;
            height: 18px;
            border-radius: 999px;
            border: 1px solid rgba(31, 41, 55, 0.14);
            flex: 0 0 auto;
        }

        .variant-copy strong {
            display: block;
            font-size: 0.96rem;
        }

        .variant-copy span {
            display: block;
            margin-top: 3px;
            color: var(--muted);
            font-size: 0.82rem;
            font-weight: 700;
        }

        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        #productPurchase {
            scroll-margin-top: calc(var(--site-banner-height) + var(--site-header-height) + 24px);
        }

        .btn {
            appearance: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 999px;
            padding: 15px 22px;
            font-family: inherit;
            font-size: 0.96rem;
            font-weight: 800;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            color: #111827;
            background: linear-gradient(135deg, #ffd24d, #f4b400);
            box-shadow: 0 14px 30px rgba(244, 180, 0, 0.24);
        }

        .btn-primary[disabled] {
            opacity: 0.45;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .btn-secondary {
            color: #ffffff;
            background: linear-gradient(135deg, #d9293a, #ab1625);
            box-shadow: 0 14px 30px rgba(171, 22, 37, 0.2);
        }

        .status-note {
            margin-top: 16px;
            min-height: 24px;
            color: var(--muted);
            font-size: 0.92rem;
            font-weight: 700;
        }

        .status-note.success {
            color: var(--success);
        }

        .status-note.error {
            color: #b42318;
        }

        .footer {
            padding: 20px 0 44px;
        }

        .footer-inner {
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 700;
        }

        .not-found {
            width: min(780px, calc(100% - 32px));
            margin: 72px auto;
            padding: 42px;
            text-align: center;
        }

        .not-found h1 {
            margin: 0 0 12px;
            font-family: "Bebas Neue", sans-serif;
            font-size: clamp(2.8rem, 7vw, 5.4rem);
            letter-spacing: 0.06em;
        }

        .not-found p {
            margin: 0 0 24px;
            color: var(--muted);
            line-height: 1.8;
        }

        @media (min-width: 1024px) and (max-width: 1279px) {
            .site-header-desktop {
                grid-template-columns: 200px 1fr 180px;
                gap: 16px;
                padding: 0 14px;
            }

            .site-header-desktop .brand {
                width: 220px;
            }

            .site-header-desktop .brand img {
                height: 85px;
                transform: scale(1.15);
            }

            .site-header-desktop .nav-links {
                gap: 22px;
            }

            .site-header-desktop .nav-link {
                font-size: 0.82rem;
            }

            .site-header-desktop .icon-btn {
                width: 34px;
                height: 34px;
            }
        }

        @media (min-width: 1600px) {
            .site-header-desktop {
                padding: 0 34px;
            }

            .site-header-desktop .brand img {
                height: 120px;
                transform: scale(1.25);
            }
        }

        @media (max-width: 920px) {
            .hero-grid {
                grid-template-columns: 1fr;
            }

            .main-image {
                min-height: 360px;
            }

            .feature-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1023px) {
            body {
                padding-top: calc(var(--site-banner-height) + var(--mobile-header-height));
            }

            #productPurchase {
                scroll-margin-top: calc(var(--site-banner-height) + var(--mobile-header-height) + 24px);
            }

            .site-header-desktop {
                display: none;
            }

            .site-header-mobile {
                position: fixed;
                top: var(--site-banner-height);
                left: 0;
                right: 0;
                z-index: 17000;
                display: block;
                height: var(--mobile-header-height);
                background: rgba(255, 255, 255, 0.52);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border-bottom: 1px solid rgba(255, 255, 255, 0.4);
                box-shadow: 0 8px 32px rgba(31, 38, 135, 0.05);
            }

            .mobile-header-inner {
                width: min(100%, calc(100% - 24px));
                height: 100%;
                margin: 0 auto;
                display: grid;
                grid-template-columns: 44px 1fr auto;
                align-items: center;
                gap: 12px;
            }

            .site-header-mobile .icon-btn,
            .mobile-menu-toggle,
            .mobile-drawer-close {
                appearance: none;
                width: 40px;
                height: 40px;
                padding: 0;
                border: 0;
                border-radius: 14px;
                background: rgba(255, 255, 255, 0.72);
                color: #111827;
                filter: none;
                box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
            }

            .mobile-brand {
                display: flex;
                align-items: center;
                justify-content: center;
                min-width: 0;
            }

            .mobile-brand img {
                height: 92px;
                width: auto;
                max-width: min(168px, 100%);
                object-fit: contain;
                filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.75)) drop-shadow(0 6px 14px rgba(15, 23, 42, 0.08));
            }

            .mobile-header-actions {
                display: flex;
                align-items: center;
                justify-self: end;
                gap: 8px;
            }

            .mobile-menu-overlay {
                position: fixed;
                inset: 0;
                display: block;
                opacity: 0;
                pointer-events: none;
                background: rgba(15, 23, 42, 0.38);
                transition: opacity 0.2s ease;
                z-index: 16980;
            }

            .mobile-nav-drawer {
                position: fixed;
                top: calc(var(--site-banner-height) + var(--mobile-header-height) + 12px);
                left: 12px;
                right: 12px;
                display: block;
                padding: 20px;
                border-radius: 28px;
                background: rgba(255, 255, 255, 0.96);
                border: 1px solid rgba(255, 255, 255, 0.84);
                box-shadow: 0 28px 48px rgba(15, 23, 42, 0.2);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                opacity: 0;
                pointer-events: none;
                transform: translateY(-12px) scale(0.98);
                transform-origin: top center;
                transition: opacity 0.2s ease, transform 0.2s ease;
                z-index: 16990;
            }

            body.mobile-nav-open .mobile-menu-overlay {
                opacity: 1;
                pointer-events: auto;
            }

            body.mobile-nav-open .mobile-nav-drawer {
                opacity: 1;
                pointer-events: auto;
                transform: translateY(0) scale(1);
            }

            .mobile-drawer-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 16px;
            }

            .mobile-drawer-head strong {
                font-size: 1rem;
                font-weight: 900;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .mobile-nav-links {
                display: grid;
                gap: 12px;
            }

            .mobile-nav-link,
            .mobile-quick-action {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 14px 16px;
                border-radius: 18px;
                background: #fff;
                border: 1px solid rgba(17, 24, 39, 0.08);
                font-size: 0.86rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }

            .mobile-nav-link.active {
                color: #111827;
                background: rgba(255, 204, 0, 0.14);
                border-color: rgba(255, 204, 0, 0.6);
            }

            .mobile-quick-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
                margin-top: 12px;
            }
        }

        @media (max-width: 640px) {
            .top-shipping-banner::before,
            .top-shipping-banner::after {
                width: 32px;
            }

            .top-shipping-banner-track {
                animation-duration: 24s;
            }

            .top-shipping-banner-text {
                gap: 12px;
                padding-right: 12px;
                font-size: 0.72rem;
                letter-spacing: 0.08em;
            }

            .top-shipping-banner-dot {
                width: 4px;
                height: 4px;
            }

            .mobile-header-inner {
                width: calc(100% - 20px);
                gap: 10px;
            }

            .mobile-brand img {
                height: 76px;
                max-width: 144px;
            }

            .mobile-nav-drawer {
                left: 10px;
                right: 10px;
                padding: 18px;
            }

            .mobile-quick-actions {
                grid-template-columns: 1fr;
            }

            .gallery-panel,
            .info-panel,
            .not-found {
                padding: 20px;
            }

            .main-image {
                min-height: 300px;
            }

            .variant-section {
                padding: 16px;
            }

            .variant-section-head {
                flex-direction: column;
                align-items: stretch;
            }

            .variant-selected-chip {
                width: 100%;
                justify-content: flex-start;
            }

            .variant-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="top-shipping-banner" aria-label="Kargo kampanyasi duyuru bandi">
        <div class="top-shipping-banner-track">
            <div class="top-shipping-banner-text">
                <strong>2500 TL UZERI KARGO BEDAVA</strong>
                <span class="top-shipping-banner-dot"></span>
                <span>2500 TL VE UZERI SIPARISLERDE UCRETSIZ KARGO</span>
                <span class="top-shipping-banner-dot"></span>
                <strong>2500 TL UZERI KARGO BEDAVA</strong>
                <span class="top-shipping-banner-dot"></span>
                <span>2500 TL VE UZERI SIPARISLERDE UCRETSIZ KARGO</span>
                <span class="top-shipping-banner-dot"></span>
                <strong>2500 TL UZERI KARGO BEDAVA</strong>
                <span class="top-shipping-banner-dot"></span>
                <span>2500 TL VE UZERI SIPARISLERDE UCRETSIZ KARGO</span>
                <span class="top-shipping-banner-dot"></span>
            </div>
            <div class="top-shipping-banner-text">
                <strong>2500 TL UZERI KARGO BEDAVA</strong>
                <span class="top-shipping-banner-dot"></span>
                <span>2500 TL VE UZERI SIPARISLERDE UCRETSIZ KARGO</span>
                <span class="top-shipping-banner-dot"></span>
                <strong>2500 TL UZERI KARGO BEDAVA</strong>
                <span class="top-shipping-banner-dot"></span>
                <span>2500 TL VE UZERI SIPARISLERDE UCRETSIZ KARGO</span>
                <span class="top-shipping-banner-dot"></span>
                <strong>2500 TL UZERI KARGO BEDAVA</strong>
                <span class="top-shipping-banner-dot"></span>
                <span>2500 TL VE UZERI SIPARISLERDE UCRETSIZ KARGO</span>
                <span class="top-shipping-banner-dot"></span>
            </div>
        </div>
    </div>
    <header class="site-header-desktop" aria-label="Ana navigasyon">
        <a class="brand" href="/">
            <img src="/Boomer.svg" alt="Boomer Items">
        </a>
        <nav class="nav-links" aria-label="Desktop">
            <a class="nav-link" href="/">Ana Sayfa</a>
            <a class="nav-link active" href="/#products">Urunler</a>
            <a class="nav-link" href="/hakkimizda">Biz Kimiz</a>
            <a class="nav-link" href="/iletisim">Iletisim</a>
        </nav>
        <div class="actions">
            <a class="icon-btn search-btn" href="/#products" aria-label="Ara">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
            </a>
            <a class="icon-btn account-btn" href="/" aria-label="Hesap">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 19c1.6-3 4.1-4.5 7-4.5S17.4 16 19 19"></path></svg>
            </a>
            <a class="icon-btn tracking-btn" href="/siparis-takip" aria-label="Siparis Takibi">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 18.5 7v10L12 20.5 5.5 17V7L12 3.5Z"></path><path d="M5.5 7 12 10.6 18.5 7"></path><path d="M12 10.6v9.9"></path></svg>
            </a>
            <a class="icon-btn cart-btn" href="#productPurchase" aria-label="Sepet">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1"></circle><circle cx="18" cy="20" r="1"></circle><path d="M3 4h2l2.2 10.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L22 8H7"></path></svg>
                <span class="cart-badge" data-cart-count>0</span>
            </a>
        </div>
    </header>
    <header class="site-header-mobile" aria-label="Mobil navigasyon">
        <div class="mobile-header-inner">
            <button class="mobile-menu-toggle" type="button" aria-label="Menuyu ac" aria-expanded="false" aria-controls="mobileNavDrawer" data-mobile-nav-toggle>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>
            </button>
            <a class="mobile-brand" href="/">
                <img src="/Boomer.svg" alt="Boomer Items">
            </a>
            <div class="mobile-header-actions">
                <a class="icon-btn search-btn" href="/#products" aria-label="Ara">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
                </a>
                <a class="icon-btn cart-btn" href="#productPurchase" aria-label="Sepet">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1"></circle><circle cx="18" cy="20" r="1"></circle><path d="M3 4h2l2.2 10.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L22 8H7"></path></svg>
                    <span class="cart-badge" data-cart-count>0</span>
                </a>
            </div>
        </div>
    </header>
    <div class="mobile-menu-overlay" data-mobile-nav-close></div>
    <aside class="mobile-nav-drawer" id="mobileNavDrawer" aria-hidden="true">
        <div class="mobile-drawer-head">
            <strong>Menu</strong>
            <button class="mobile-drawer-close" type="button" aria-label="Menuyu kapat" data-mobile-nav-close>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6 18 18"></path><path d="M18 6 6 18"></path></svg>
            </button>
        </div>
        <nav class="mobile-nav-links" aria-label="Mobil menu">
            <a class="mobile-nav-link" href="/">Ana Sayfa</a>
            <a class="mobile-nav-link active" href="/#products">Urunler</a>
            <a class="mobile-nav-link" href="/hakkimizda">Biz Kimiz</a>
            <a class="mobile-nav-link" href="/iletisim">Iletisim</a>
        </nav>
        <div class="mobile-quick-actions">
            <a class="mobile-quick-action" href="/siparis-takip">Siparis Takibi</a>
            <a class="mobile-quick-action" href="/iletisim">Iletisim</a>
        </div>
    </aside>

    <?php if ($isNotFound): ?>
    <main class="panel not-found">
        <h1>Urun Bulunamadi</h1>
        <p>Baglantidaki urun kaldirilmis, pasife alinmis ya da URL degismis olabilir. Anasayfaya donup aktif urunleri inceleyebilirsiniz.</p>
        <div class="cta-row" style="justify-content:center;">
            <a class="btn btn-primary" href="/">Anasayfaya Don</a>
            <a class="btn btn-secondary" href="/#products">Urunleri Gor</a>
        </div>
    </main>
    <?php else: ?>
    <main class="content">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <a href="/">Anasayfa</a>
            <span>/</span>
            <?php if (!empty($product['category'])): ?>
            <span><?= pageEscape($product['category']) ?></span>
            <span>/</span>
            <?php endif; ?>
            <span><?= pageEscape((string) ($product['title'] ?? $product['name'])) ?></span>
        </nav>

        <section class="hero-grid">
            <div class="panel gallery-panel">
                <div class="main-image">
                    <img id="mainProductImage" src="<?= pageEscape($primaryImage) ?>" alt="<?= pageEscape((string) ($product['title'] ?? $product['name'])) ?>">
                </div>
                <?php if (count($galleryItems) > 1): ?>
                <div class="thumb-list">
                    <?php foreach ($galleryItems as $index => $galleryItem): ?>
                    <button
                        class="thumb-btn<?= $index === 0 ? ' is-active' : '' ?>"
                        type="button"
                        data-gallery-thumb="<?= $index ?>"
                        data-image-src="<?= pageEscape($galleryItem['url']) ?>"
                        data-image-alt="<?= pageEscape($galleryItem['alt']) ?>"
                    >
                        <img src="<?= pageEscape($galleryItem['url']) ?>" alt="<?= pageEscape($galleryItem['alt']) ?>">
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="panel info-panel">
                <div class="eyebrow">
                    <?php if (!empty($product['category'])): ?>
                    <span class="detail-category-tag"><?= pageEscape($product['category']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($product['brand'])): ?>
                    <span class="pill brand"><?= pageEscape($product['brand']) ?></span>
                    <?php endif; ?>
                    <span class="pill <?= strtolower((string) ($product['conditionTag'] ?? '')) === '2. el' ? 'condition-used' : 'condition-new' ?>">
                        <?= pageEscape((string) ($product['conditionLabel'] ?? $product['condition_label'])) ?>
                    </span>
                </div>

                <h1 class="product-title"><?= pageEscape((string) ($product['title'] ?? $product['name'])) ?></h1>

                <div class="product-subline">
                    <?php if (!empty($product['sku'])): ?>
                    <span>SKU: <?= pageEscape($product['sku']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($product['set_no'])): ?>
                    <span>Set No: <?= pageEscape($product['set_no']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="price-block detail-price-row">
                    <div class="current-price detail-price" id="productCurrentPrice"><?= pageEscape(pageMoney((float) ($product['price'] ?? 0))) ?></div>
                    <?php if ((float) ($product['oldPrice'] ?? 0) > (float) ($product['price'] ?? 0)): ?>
                    <div class="old-price detail-old-price"><?= pageEscape(pageMoney((float) $product['oldPrice'])) ?></div>
                    <?php endif; ?>
                </div>

                <?php if ($activeVariants !== []): ?>
                <section class="variant-section" aria-labelledby="variantSectionTitle">
                    <div class="variant-section-head">
                        <div class="variant-heading">
                            <h2 id="variantSectionTitle">Renk Secenekleri</h2>
                            <p>Parca urunlerinde secmek istedigin renk veya ton secenegini buradan belirleyebilirsin.</p>
                        </div>
                        <div class="variant-selected-chip">
                            <span class="variant-selected-dot" id="selectedVariantSwatch" style="background: <?= pageEscape($defaultVariantColorCode) ?>;"></span>
                            <span>Secili: <strong id="selectedVariantLabel"><?= pageEscape($defaultVariantColor !== '' ? $defaultVariantColor : '-') ?></strong></span>
                        </div>
                    </div>
                    <div class="variant-grid">
                        <?php foreach ($activeVariants as $index => $variant): ?>
                        <?php
                            $variantId = (string) ($variant['id'] ?? ('variant-' . $index));
                            $variantAvailable = (int) ($variant['available_stock'] ?? 0);
                            $variantDiff = (float) ($variant['price_diff'] ?? 0);
                            $colorCode = trim((string) ($variant['color_code'] ?? ''));
                            $swatchColor = $colorCode !== '' ? $colorCode : '#d0d5dd';
                        ?>
                        <div class="variant-option<?= $variantAvailable <= 0 ? ' is-disabled' : '' ?>">
                            <input
                                id="variant-<?= pageEscape($variantId) ?>"
                                name="variant"
                                type="radio"
                                value="<?= pageEscape($variantId) ?>"
                                data-variant-id="<?= pageEscape($variantId) ?>"
                                data-variant-color="<?= pageEscape((string) ($variant['color'] ?? '')) ?>"
                                data-variant-color-code="<?= pageEscape($swatchColor) ?>"
                                data-variant-sku="<?= pageEscape((string) ($variant['sku'] ?? '')) ?>"
                                data-variant-stock="<?= pageEscape((string) $variantAvailable) ?>"
                                data-variant-price-diff="<?= pageEscape(number_format($variantDiff, 2, '.', '')) ?>"
                                <?= $variantId === $defaultVariantId ? 'checked' : '' ?>
                            >
                            <label for="variant-<?= pageEscape($variantId) ?>">
                                <span class="variant-swatch" style="background: <?= pageEscape($swatchColor) ?>;"></span>
                                <span class="variant-copy">
                                    <strong><?= pageEscape((string) ($variant['color'] ?? 'Varyant')) ?></strong>
                                    <span>
                                        <?php if (abs($variantDiff) > 0.001): ?>
                                        <?= $variantDiff > 0 ? '+' : '-' ?><?= pageEscape(pageMoney(abs($variantDiff))) ?>
                                        <?php else: ?>
                                        Secenek
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <div class="feature-grid">
                    <div class="feature-card">
                        <span>Kategori</span>
                        <strong><?= pageEscape((string) ($product['category'] ?? 'Koleksiyon Urunu')) ?></strong>
                    </div>
                    <div class="feature-card">
                        <span>Parca / Adet Bilgisi</span>
                        <strong><?= pageEscape((int) ($product['pieces'] ?? 0) > 0 ? number_format((int) $product['pieces'], 0, ',', '.') . ' parca' : 'Urun detayinda belirtilmedi') ?></strong>
                    </div>
                </div>

                <?php if ($productDescriptionText !== ''): ?>
                <p class="description detail-description"><?= nl2br(pageEscape($productDescriptionText)) ?></p>
                <?php endif; ?>

                <div class="detail-qty-panel" id="detailQuantityPanel">
                    <div class="variant-label">Adet:</div>
                    <div class="qty-stepper">
                        <button type="button" id="qtyDecreaseButton">-</button>
                        <span id="selectedQtyValue">1</span>
                        <button type="button" id="qtyIncreaseButton">+</button>
                    </div>
                </div>

                <div class="cta-row" id="productPurchase">
                    <button class="btn btn-primary" id="addToCartButton" type="button">Sepete Ekle</button>
                    <a class="btn btn-secondary" href="/#products">Diger Urunlere Don</a>
                </div>

                <div class="status-note" id="cartStatusNote" aria-live="polite"></div>
            </div>
        </section>
    </main>

    <script id="product-page-data" type="application/json"><?= json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script>
        (function () {
            var dataNode = document.getElementById('product-page-data');
            if (!dataNode) return;

            var product = {};
            try {
                product = JSON.parse(dataNode.textContent || '{}') || {};
            } catch (error) {
                product = {};
            }

            var formatter = new Intl.NumberFormat('tr-TR', {
                style: 'currency',
                currency: 'TRY',
                minimumFractionDigits: 2
            });

            var currentPriceNode = document.getElementById('productCurrentPrice');
            var selectedVariantLabelNode = document.getElementById('selectedVariantLabel');
            var selectedVariantSwatchNode = document.getElementById('selectedVariantSwatch');
            var addToCartButton = document.getElementById('addToCartButton');
            var statusNote = document.getElementById('cartStatusNote');
            var mainImage = document.getElementById('mainProductImage');
            var selectedQtyValueNode = document.getElementById('selectedQtyValue');
            var qtyDecreaseButton = document.getElementById('qtyDecreaseButton');
            var qtyIncreaseButton = document.getElementById('qtyIncreaseButton');
            var quantityPanel = document.getElementById('detailQuantityPanel');
            var variantInputs = Array.prototype.slice.call(document.querySelectorAll('input[name="variant"]'));
            var selectedQty = 1;

            var readCartItems = function () {
                try {
                    var parsed = JSON.parse(localStorage.getItem('cartItems') || '[]');
                    return Array.isArray(parsed) ? parsed : [];
                } catch (error) {
                    return [];
                }
            };

            var getSelectedVariant = function () {
                var selected = variantInputs.find(function (input) {
                    return input.checked;
                });

                if (!selected) return null;

                return {
                    id: selected.getAttribute('data-variant-id') || '',
                    color: selected.getAttribute('data-variant-color') || '',
                    colorCode: selected.getAttribute('data-variant-color-code') || '#d0d5dd',
                    sku: selected.getAttribute('data-variant-sku') || '',
                    stock: parseInt(selected.getAttribute('data-variant-stock') || '0', 10) || 0,
                    priceDiff: parseFloat(selected.getAttribute('data-variant-price-diff') || '0') || 0
                };
            };

            var getCurrentState = function () {
                var variant = getSelectedVariant();
                var basePrice = parseFloat(product.price || 0) || 0;
                var currentPrice = basePrice + (variant ? variant.priceDiff : 0);
                var stock = variant
                    ? variant.stock
                    : (parseInt(product.availableStock || product.stock || 0, 10) || 0);
                var cartQty = readCartItems().reduce(function (sum, entry) {
                    var sameProduct = String(entry && entry.id || '') === String(product.id || '');
                    var sameVariant = String(entry && (entry.variantId || '') || '') === String(variant ? variant.id : '');
                    if (!sameProduct || !sameVariant) return sum;
                    return sum + Math.max(0, parseInt(entry.qty || 0, 10) || 0);
                }, 0);
                var addable = Math.max(0, stock - cartQty);
                var stockAvailable = stock > 0;
                return {
                    variant: variant,
                    currentPrice: currentPrice,
                    stock: stock,
                    cartQty: cartQty,
                    addable: addable,
                    stockAvailable: stockAvailable
                };
            };

            var setStatus = function (message, tone) {
                if (!statusNote) return;
                statusNote.textContent = message || '';
                statusNote.className = 'status-note' + (tone ? ' ' + tone : '');
            };

            var syncUi = function () {
                var state = getCurrentState();
                var displayQty = state.addable === 0 ? 0 : Math.min(Math.max(1, selectedQty), state.addable);

                if (state.addable > 0) {
                    selectedQty = displayQty;
                }

                if (currentPriceNode) {
                    currentPriceNode.textContent = formatter.format(state.currentPrice);
                }

                if (selectedVariantLabelNode && state.variant) {
                    selectedVariantLabelNode.textContent = state.variant.color || '-';
                }

                if (selectedVariantSwatchNode && state.variant) {
                    selectedVariantSwatchNode.style.background = state.variant.colorCode || '#d0d5dd';
                }

                if (selectedQtyValueNode) {
                    selectedQtyValueNode.textContent = String(displayQty);
                }

                if (qtyDecreaseButton) {
                    qtyDecreaseButton.disabled = state.addable === 0 || displayQty <= 1;
                }

                if (qtyIncreaseButton) {
                    qtyIncreaseButton.disabled = state.addable === 0 || displayQty >= state.addable;
                }

                if (quantityPanel) {
                    quantityPanel.style.display = state.stockAvailable ? '' : 'none';
                }

                if (addToCartButton) {
                    addToCartButton.disabled = state.addable <= 0;
                    addToCartButton.textContent = !state.stockAvailable
                        ? 'Su an eklenemiyor'
                        : (state.addable === 0 ? 'Daha fazla eklenemiyor' : 'Sepete Ekle');
                }
            };

            var activateGallery = function () {
                Array.prototype.slice.call(document.querySelectorAll('[data-gallery-thumb]')).forEach(function (button) {
                    button.addEventListener('click', function () {
                        var nextSrc = button.getAttribute('data-image-src') || '';
                        var nextAlt = button.getAttribute('data-image-alt') || '';
                        if (mainImage && nextSrc) {
                            mainImage.src = nextSrc;
                            mainImage.alt = nextAlt;
                        }

                        Array.prototype.slice.call(document.querySelectorAll('[data-gallery-thumb]')).forEach(function (item) {
                            item.classList.remove('is-active');
                        });
                        button.classList.add('is-active');
                    });
                });
            };

            var upsertCartItem = function (item, maxStock, qtyToAdd) {
                var cartItems = readCartItems();

                var matchIndex = cartItems.findIndex(function (entry) {
                    return String(entry.id || '') === String(item.id || '') &&
                        String(entry.variantId || '') === String(item.variantId || '');
                });

                if (matchIndex >= 0) {
                    var nextQty = (parseInt(cartItems[matchIndex].qty || 0, 10) || 0) + qtyToAdd;
                    if (maxStock > 0 && nextQty > maxStock) {
                        return false;
                    }
                    cartItems[matchIndex].qty = nextQty;
                } else {
                    item.qty = qtyToAdd;
                    cartItems.push(item);
                }

                localStorage.setItem('cartItems', JSON.stringify(cartItems));
                window.dispatchEvent(new Event('bi-cart-sync'));
                return true;
            };

            variantInputs.forEach(function (input) {
                input.addEventListener('change', function () {
                    setStatus('', '');
                    selectedQty = 1;
                    syncUi();
                });
            });

            if (qtyDecreaseButton) {
                qtyDecreaseButton.addEventListener('click', function () {
                    selectedQty = Math.max(1, selectedQty - 1);
                    syncUi();
                });
            }

            if (qtyIncreaseButton) {
                qtyIncreaseButton.addEventListener('click', function () {
                    var state = getCurrentState();
                    if (state.addable <= 0) {
                        syncUi();
                        return;
                    }
                    selectedQty = Math.min(state.addable, selectedQty + 1);
                    syncUi();
                });
            }

            if (addToCartButton) {
                addToCartButton.addEventListener('click', function () {
                    var state = getCurrentState();
                    var variant = state.variant;
                    var maxStock = state.stock;
                    var qtyToAdd = state.addable === 0 ? 0 : Math.min(Math.max(1, selectedQty), state.addable);

                    if (maxStock <= 0) {
                        setStatus('Bu urun su anda sepete eklenemiyor.', 'error');
                        return;
                    }

                    if (qtyToAdd <= 0) {
                        setStatus('Bu urunden daha fazla eklenemiyor.', 'error');
                        syncUi();
                        return;
                    }

                    var finalPrice = state.currentPrice;
                    var item = {
                        id: product.id || '',
                        variantId: variant ? variant.id : null,
                        variantColor: variant ? variant.color : null,
                        variantSku: variant ? variant.sku : null,
                        title: product.title || '',
                        price: finalPrice,
                        priceStr: formatter.format(finalPrice),
                        img: product.img || '',
                        desi: parseFloat(product.desi || 1) || 1,
                        maxStock: maxStock
                    };

                    if (!upsertCartItem(item, maxStock, qtyToAdd)) {
                        setStatus('Sepetteki adet sinira ulasmis durumda.', 'error');
                        syncUi();
                        return;
                    }

                    setStatus(qtyToAdd + ' adet urun sepete eklendi.', 'success');
                    selectedQty = 1;
                    syncUi();
                });
            }

            window.addEventListener('storage', syncUi);

            activateGallery();
            syncUi();
        })();
    </script>
    <?php endif; ?>

    <footer class="footer">
        <div class="footer-inner">
            &copy; <?= pageEscape((string) date('Y')) ?> BoomerItems
        </div>
    </footer>
    <script>
        (function () {
            var body = document.body;
            var mobileNavToggle = document.querySelector('[data-mobile-nav-toggle]');
            var mobileNavDrawer = document.getElementById('mobileNavDrawer');
            var mobileNavCloseNodes = Array.prototype.slice.call(document.querySelectorAll('[data-mobile-nav-close], .mobile-nav-link, .mobile-quick-action'));
            var cartCountNodes = Array.prototype.slice.call(document.querySelectorAll('[data-cart-count]'));

            var setMobileNavOpen = function (open) {
                body.classList.toggle('mobile-nav-open', !!open);
                if (mobileNavToggle) {
                    mobileNavToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
                if (mobileNavDrawer) {
                    mobileNavDrawer.setAttribute('aria-hidden', open ? 'false' : 'true');
                }
            };

            var syncCartBadges = function () {
                var totalQty = 0;

                try {
                    var parsed = JSON.parse(localStorage.getItem('cartItems') || '[]');
                    if (Array.isArray(parsed)) {
                        totalQty = parsed.reduce(function (sum, entry) {
                            return sum + Math.max(0, parseInt(entry && entry.qty || 0, 10) || 0);
                        }, 0);
                    }
                } catch (error) {
                    totalQty = 0;
                }

                cartCountNodes.forEach(function (node) {
                    if (!node) return;
                    node.textContent = String(totalQty);
                    node.style.display = totalQty > 0 ? 'inline-flex' : 'none';
                });
            };

            if (mobileNavToggle) {
                mobileNavToggle.addEventListener('click', function () {
                    setMobileNavOpen(!body.classList.contains('mobile-nav-open'));
                });
            }

            mobileNavCloseNodes.forEach(function (node) {
                node.addEventListener('click', function () {
                    setMobileNavOpen(false);
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setMobileNavOpen(false);
                }
            });

            window.addEventListener('storage', syncCartBadges);
            window.addEventListener('bi-cart-sync', syncCartBadges);

            syncCartBadges();
        })();
    </script>
</div>
</body>
</html>
