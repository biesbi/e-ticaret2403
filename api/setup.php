<?php
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
//  BoomerItems â€” Kurulum & TeÅŸhis AracÄ±
//  KullanÄ±m: http://localhost/api/setup.php
//  UYARI: Production'da bu dosyayÄ± SÄ°L!
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$results = [];

// â”€â”€â”€ 1. PHP Versiyonu â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$results['php_version'] = [
    'label'  => 'PHP Versiyonu',
    'value'  => PHP_VERSION,
    'ok'     => version_compare(PHP_VERSION, '8.0', '>='),
    'note'   => 'PHP 8.0+ gerekli',
];

// â”€â”€â”€ 2. Gerekli Eklentiler â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$exts = ['pdo', 'pdo_mysql', 'gd', 'fileinfo', 'json', 'mbstring'];
foreach ($exts as $ext) {
    $results['ext_' . $ext] = [
        'label' => "PHP Extension: $ext",
        'value' => extension_loaded($ext) ? 'YÃ¼klÃ¼' : 'EKSÄ°K',
        'ok'    => extension_loaded($ext),
        'note'  => $ext === 'gd' ? 'GÃ¶rsel iÅŸleme iÃ§in gerekli' : 'Zorunlu',
    ];
}

// â”€â”€â”€ 3. .env DosyasÄ± â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$envPath = __DIR__ . '/.env';
$results['env_file'] = [
    'label' => '.env DosyasÄ±',
    'value' => file_exists($envPath) ? 'Mevcut' : 'BULUNAMADI',
    'ok'    => file_exists($envPath),
    'note'  => '.env dosyasÄ± api/ klasÃ¶rÃ¼nde olmalÄ±',
];

// â”€â”€â”€ 4. .env Ä°Ã§eriÄŸi â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (file_exists($envPath)) {
    $envContent = [];
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key] = explode('=', $line, 2);
        $envContent[] = trim($key);
    }
    $results['env_keys'] = [
        'label' => '.env AnahtarlarÄ±',
        'value' => implode(', ', $envContent),
        'ok'    => in_array('DB_HOST', $envContent) && in_array('JWT_SECRET', $envContent),
        'note'  => 'DB_HOST ve JWT_SECRET zorunlu',
    ];
}

// â”€â”€â”€ 5. VeritabanÄ± BaÄŸlantÄ±sÄ± â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$dbOk = false;
$dbMsg = '';
try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/helpers.php';
    $pdo   = db();
    $dbOk  = true;
    $dbMsg = 'BaÄŸlantÄ± baÅŸarÄ±lÄ± â€” DB: ' . DB_NAME . ' @ ' . DB_HOST;
} catch (Throwable $e) {
    $dbMsg = 'HATA: ' . $e->getMessage();
}
$results['db_connection'] = [
    'label' => 'VeritabanÄ± BaÄŸlantÄ±sÄ±',
    'value' => $dbMsg,
    'ok'    => $dbOk,
    'note'  => '.env iÃ§indeki DB_HOST, DB_NAME, DB_USER, DB_PASS kontrol et',
];

// â”€â”€â”€ 6. Tablolar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($dbOk) {
    $tables = ['users','products','categories','brands','orders','order_items',
               'coupons','cart_items','token_blacklist','rate_limits','audit_logs',
               'shipping_cities','shipping_groups','product_images'];
    foreach ($tables as $tbl) {
        try {
            $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
            $results['table_' . $tbl] = [
                'label' => "Tablo: $tbl",
                'value' => "$cnt kayÄ±t",
                'ok'    => true,
            ];
        } catch (Throwable $e) {
            $results['table_' . $tbl] = [
                'label' => "Tablo: $tbl",
                'value' => 'BULUNAMADI â€” database.sql Ã§alÄ±ÅŸtÄ±rÄ±ldÄ± mÄ±?',
                'ok'    => false,
                'note'  => 'HeidiSQL\'de database.sql dosyasÄ±nÄ± Ã§alÄ±ÅŸtÄ±r',
            ];
        }
    }
}

// â”€â”€â”€ 7. Admin KullanÄ±cÄ±sÄ± & Åifre Hash Fix â”€â”€â”€
$adminFixed = false;
$adminMsg   = '';
if ($dbOk) {
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $passwordColumn = null;
        $hasUsername = false;
        $hasDisplayName = false;
        foreach ($columns as $column) {
            if (($column['Field'] ?? '') === 'password_hash') {
                $passwordColumn = 'password_hash';
                break;
            }
            if (($column['Field'] ?? '') === 'password') {
                $passwordColumn = 'password';
            }
            if (($column['Field'] ?? '') === 'username') {
                $hasUsername = true;
            }
            if (($column['Field'] ?? '') === 'display_name') {
                $hasDisplayName = true;
            }
        }

        if ($passwordColumn === null) {
            throw new RuntimeException('users tablosunda sifre kolonu bulunamadi');
        }

        $adminWhere = $hasUsername
            ? "username = 'admin' OR email = 'admin@boomeritems.com'"
            : "email = 'admin@boomeritems.com'";
        $st = $pdo->prepare(
            "SELECT id, {$passwordColumn} AS stored_password
             FROM users
             WHERE {$adminWhere}
             LIMIT 1"
        );
        $st->execute();
        $adminUser = $st->fetch();

        $adminEmail = 'admin@boomeritems.com';
        $adminPass  = $passwordColumn === 'password_hash' ? 'boomeritemsbaran!' : 'admin123';

        if (!$adminUser) {
            $storedPassword = $passwordColumn === 'password_hash'
                ? password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12])
                : $adminPass;
            if ($hasUsername && $hasDisplayName) {
                $pdo->prepare(
                    "INSERT INTO users (username, display_name, email, {$passwordColumn}, role)
                     VALUES ('admin', 'BoomerItems Admin', ?, ?, 'admin')"
                )->execute([$adminEmail, $storedPassword]);
            } else {
                $pdo->prepare(
                    "INSERT INTO users (email, name, {$passwordColumn}, role, is_active)
                     VALUES (?, 'BoomerItems Admin', ?, 'admin', 1)"
                )->execute([$adminEmail, $storedPassword]);
            }
            $adminMsg   = "Admin olusturuldu - email: $adminEmail / sifre: $adminPass";
            $adminFixed = true;
        } else {
            $storedPassword = (string) ($adminUser['stored_password'] ?? '');
            $testOk = $passwordColumn === 'password_hash'
                ? password_verify($adminPass, $storedPassword)
                : hash_equals($storedPassword, $adminPass);

            if (!$testOk) {
                $newPassword = $passwordColumn === 'password_hash'
                    ? password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12])
                    : $adminPass;
                $pdo->prepare(
                    "UPDATE users SET {$passwordColumn} = ?, email = ? WHERE id = ?"
                )->execute([$newPassword, $adminEmail, $adminUser['id']]);
                $adminMsg   = "Admin sifre + email guncellendi - email: $adminEmail / sifre: $adminPass";
                $adminFixed = true;
            } else {
                $adminMsg   = "Admin OK - email: $adminEmail / sifre: $adminPass";
                $adminFixed = true;
            }
        }
    } catch (Throwable $e) {
        $adminMsg = 'HATA: ' . $e->getMessage();
    }
}
$results['admin_user'] = [
    'label' => 'Admin KullanÄ±cÄ±sÄ±',
    'value' => $adminMsg,
    'ok'    => $adminFixed,
    'note'  => 'Production\'da ÅŸifreyi mutlaka deÄŸiÅŸtir!',
];

// â”€â”€â”€ 8. Upload Dizini â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$uploadDir = __DIR__ . '/../public/uploads/products';
$results['upload_dir'] = [
    'label' => 'Upload Dizini',
    'value' => is_dir($uploadDir) ? (is_writable($uploadDir) ? 'Mevcut & YazÄ±labilir' : 'VAR ama YAZILAMAZ') : 'BULUNAMADI',
    'ok'    => is_dir($uploadDir) && is_writable($uploadDir),
    'note'  => 'public/uploads/products/ klasÃ¶rÃ¼ ve izinleri',
];

// â”€â”€â”€ 9. Log Dizini â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
$results['log_dir'] = [
    'label' => 'Log Dizini',
    'value' => is_dir($logDir) ? (is_writable($logDir) ? 'Mevcut & YazÄ±labilir' : 'VAR ama YAZILAMAZ') : 'OLUÅTURULAMADI',
    'ok'    => is_dir($logDir) && is_writable($logDir),
];

// â”€â”€â”€ 10. Test Login â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$loginOk  = false;
$loginMsg = '';
if ($dbOk && $adminFixed) {
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $passwordColumn = null;
        $hasUsername = false;
        foreach ($columns as $column) {
            if (($column['Field'] ?? '') === 'password_hash') {
                $passwordColumn = 'password_hash';
                break;
            }
            if (($column['Field'] ?? '') === 'password') {
                $passwordColumn = 'password';
            }
            if (($column['Field'] ?? '') === 'username') {
                $hasUsername = true;
            }
        }

        if ($passwordColumn === null) {
            throw new RuntimeException('users tablosunda sifre kolonu bulunamadi');
        }

        $adminWhere = $hasUsername
            ? "username = 'admin' OR email = 'admin@boomeritems.com'"
            : "email = 'admin@boomeritems.com'";
        $st = $pdo->prepare(
            "SELECT {$passwordColumn} AS stored_password
             FROM users
             WHERE {$adminWhere}
             LIMIT 1"
        );
        $st->execute();
        $row = $st->fetch();

        $loginPassword = $passwordColumn === 'password_hash' ? 'boomeritemsbaran!' : 'admin123';
        $loginOkNow = false;
        if ($row) {
            $storedPassword = (string) ($row['stored_password'] ?? '');
            $loginOkNow = $passwordColumn === 'password_hash'
                ? password_verify($loginPassword, $storedPassword)
                : hash_equals($storedPassword, $loginPassword);
        }

        if ($loginOkNow) {
            $loginOk  = true;
            $loginMsg = "Login testi basarili - admin@boomeritems.com / {$loginPassword} calisiyor";
        } else {
            $loginMsg = 'Login testi basarisiz - sifre dogrulanamadi';
        }
    } catch (Throwable $e) {
        $loginMsg = 'HATA: ' . $e->getMessage();
    }
}
$results['login_test'] = [
    'label' => 'Login Testi',
    'value' => $loginMsg,
    'ok'    => $loginOk,
];


// â”€â”€â”€ HTML Ã‡Ä±ktÄ± â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$allOk  = array_reduce($results, fn($carry, $r) => $carry && $r['ok'], true);
$passCount = count(array_filter($results, fn($r) => $r['ok']));
$failCount = count($results) - $passCount;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>BoomerItems â€” Kurulum TeÅŸhisi</title>
<style>
  body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 2rem; }
  h1 { color: #38bdf8; }
  h2 { color: #94a3b8; font-size: 0.9rem; margin-bottom: 1.5rem; }
  .summary { background: <?= $allOk ? '#064e3b' : '#7f1d1d' ?>; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 2rem; font-size: 1.1rem; }
  table { width: 100%; border-collapse: collapse; }
  tr { border-bottom: 1px solid #1e293b; }
  td { padding: 0.6rem 1rem; }
  .ok  { color: #4ade80; }
  .err { color: #f87171; }
  .label { color: #94a3b8; width: 200px; }
  .value { max-width: 500px; word-break: break-all; }
  .note { color: #64748b; font-size: 0.8rem; }
  .warn { background: #1e3a5f; padding: 1rem 1.5rem; border-radius: 8px; margin-top: 2rem; color: #fbbf24; }
</style>
</head>
<body>
<h1>BoomerItems â€” Kurulum TeÅŸhisi</h1>
<h2><?= date('Y-m-d H:i:s') ?></h2>

<div class="summary">
  <?= $allOk ? 'âœ… TÃ¼m kontroller baÅŸarÄ±lÄ±!' : "âš ï¸ $failCount kontrol baÅŸarÄ±sÄ±z â€” aÅŸaÄŸÄ±daki kÄ±rmÄ±zÄ± satÄ±rlara bak" ?>
  (<?= $passCount ?> / <?= count($results) ?> geÃ§ti)
</div>

<table>
<?php foreach ($results as $r): ?>
<tr>
  <td class="label"><?= htmlspecialchars($r['label']) ?></td>
  <td class="<?= $r['ok'] ? 'ok' : 'err' ?>"><?= $r['ok'] ? 'âœ…' : 'âŒ' ?></td>
  <td class="value"><?= htmlspecialchars($r['value']) ?></td>
  <td class="note"><?= htmlspecialchars($r['note'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</table>

<div class="warn">
  âš ï¸ <strong>GÃœVENLÄ°K UYARISI:</strong> Bu dosyayÄ± testler bittikten sonra <strong>hemen sil!</strong><br>
  <code>rm /path/to/api/setup.php</code> veya FTP ile sil.
</div>

<br>
<details>
  <summary style="cursor:pointer; color:#38bdf8">â–¶ HÄ±zlÄ± Test: POST /api/auth/login</summary>
  <pre style="background:#1e293b;padding:1rem;margin-top:1rem;border-radius:8px">
fetch('/api/auth/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ username: 'admin', password: 'admin123' })
}).then(r => r.json()).then(console.log)
  </pre>
  <p style="color:#94a3b8">Browser console'a yapÄ±ÅŸtÄ±rÄ±p Ã§alÄ±ÅŸtÄ±r.</p>
</details>
</body>
</html>


