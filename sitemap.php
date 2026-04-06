<?php
declare(strict_types=1);

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/helpers.php';

function sitemapEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function sitemapLastmod(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return gmdate('Y-m-d', $timestamp);
}

$baseUrl = currentBaseUrl();
$staticPages = [
    ['path' => '/', 'changefreq' => 'daily', 'priority' => '1.0'],
    ['path' => '/hakkimizda', 'changefreq' => 'monthly', 'priority' => '0.6'],
    ['path' => '/iletisim', 'changefreq' => 'monthly', 'priority' => '0.6'],
    ['path' => '/collector', 'changefreq' => 'weekly', 'priority' => '0.7'],
    ['path' => '/siparis-takip', 'changefreq' => 'monthly', 'priority' => '0.4'],
    ['path' => '/gizlilik-politikasi', 'changefreq' => 'yearly', 'priority' => '0.2'],
    ['path' => '/mesafeli-satis-sozlesmesi', 'changefreq' => 'yearly', 'priority' => '0.2'],
    ['path' => '/iade-cayma-kosullari', 'changefreq' => 'yearly', 'priority' => '0.2'],
];

$products = [];
try {
    $products = fetchSitemapProducts();
} catch (Throwable $ignored) {
    $products = [];
}

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', PHP_EOL;
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($staticPages as $page): ?>
  <url>
    <loc><?= sitemapEscape(toAbsoluteUrl($page['path'], $baseUrl)) ?></loc>
    <changefreq><?= sitemapEscape($page['changefreq']) ?></changefreq>
    <priority><?= sitemapEscape($page['priority']) ?></priority>
  </url>
<?php endforeach; ?>
<?php foreach ($products as $product): ?>
  <?php
    $slug = trim((string) ($product['slug'] ?? ''));
    if ($slug === '') {
        continue;
    }
    $lastmod = sitemapLastmod((string) ($product['last_modified'] ?? ''));
  ?>
  <url>
    <loc><?= sitemapEscape(toAbsoluteUrl('/urun/' . rawurlencode($slug), $baseUrl)) ?></loc>
    <?php if ($lastmod !== null): ?>
    <lastmod><?= sitemapEscape($lastmod) ?></lastmod>
    <?php endif; ?>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>
<?php endforeach; ?>
</urlset>
