<?php
require_once __DIR__ . '/api/config.php';

$merchant_id = PAYTR_MERCHANT_ID;
$merchant_key = PAYTR_MERCHANT_KEY;
$merchant_salt = PAYTR_MERCHANT_SALT;

$email = 'test@example.com';
$user_name = 'Test Kullanici';
$user_address = 'Test Mahallesi, Test Sokak No:1, Istanbul';
$user_phone = '05551234567';
$payment_amount = 100;
$merchant_oid = 'TEST' . time();
$currency = defined('PAYTR_CURRENCY') && PAYTR_CURRENCY !== '' ? PAYTR_CURRENCY : 'TL';
$test_mode = filter_var(PAYTR_TEST_MODE ?? 'true', FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
$no_installment = (int) (PAYTR_NO_INSTALLMENT ?? 0);
$max_installment = (int) (PAYTR_MAX_INSTALLMENT ?? 0);
$timeout_limit = (int) (PAYTR_TIMEOUT ?? 30);
$debug_on = 1;
$lang = 'tr';

$baseUrl = rtrim(APP_URL, '/');
$merchant_ok_url = $baseUrl . '/paytr-result.html?status=success';
$merchant_fail_url = $baseUrl . '/paytr-result.html?status=failed';
$callback_url = PAYTR_CALLBACK_URL;

$user_ip = defined('PAYTR_TEST_USER_IP') && PAYTR_TEST_USER_IP !== ''
    ? PAYTR_TEST_USER_IP
    : ($_SERVER['REMOTE_ADDR'] ?? '1.2.3.4');

$basketItems = [
    ['Test Urun 1', '0.50', 1],
    ['Test Urun 2', '0.50', 1],
];
$user_basket = base64_encode(json_encode($basketItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$hash_str = $merchant_id . $user_ip . $merchant_oid . $email . $payment_amount
    . $user_basket . $no_installment . $max_installment . $currency . $test_mode;
$paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $merchant_salt, $merchant_key, true));

$post_vals = [
    'merchant_id' => $merchant_id,
    'user_ip' => $user_ip,
    'merchant_oid' => $merchant_oid,
    'email' => $email,
    'payment_amount' => $payment_amount,
    'paytr_token' => $paytr_token,
    'user_basket' => $user_basket,
    'debug_on' => $debug_on,
    'no_installment' => $no_installment,
    'max_installment' => $max_installment,
    'user_name' => $user_name,
    'user_address' => $user_address,
    'user_phone' => $user_phone,
    'merchant_ok_url' => $merchant_ok_url,
    'merchant_fail_url' => $merchant_fail_url,
    'timeout_limit' => $timeout_limit,
    'currency' => $currency,
    'test_mode' => $test_mode,
    'lang' => $lang,
    'callback_url' => $callback_url,
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://www.paytr.com/odeme/api/get-token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$result = @curl_exec($ch);
$curl_error = curl_errno($ch) ? curl_error($ch) : '';
curl_close($ch);

$token = null;
$resultData = is_string($result) ? json_decode($result, true) : null;
$errorMessage = '';

if ($curl_error !== '') {
    $errorMessage = 'PayTR baglanti hatasi: ' . $curl_error;
} elseif (!is_array($resultData)) {
    $errorMessage = 'PayTR beklenmeyen cevap dondu.';
} elseif (($resultData['status'] ?? '') === 'success') {
    $token = (string) $resultData['token'];
} else {
    $errorMessage = 'PayTR Token Hatasi: ' . (string) ($resultData['reason'] ?? 'bilinmeyen_hata');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayTR Test Odeme Sayfasi</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 20px; color: #0f172a; }
        .container { max-width: 760px; margin: 0 auto; }
        .header { background: #1a56db; color: #fff; padding: 16px 20px; border-radius: 10px 10px 0 0; }
        .header h1 { font-size: 18px; }
        .header p { font-size: 13px; opacity: 0.88; margin-top: 4px; }
        .info-box { background: #fff; border: 1px solid #e2e8f0; padding: 16px 20px; font-size: 13px; }
        .info-box table { width: 100%; border-collapse: collapse; }
        .info-box td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .info-box td:first-child { font-weight: 700; color: #475569; width: 38%; }
        .badge { display: inline-block; background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .iframe-wrap { background: #fff; border: 1px solid #e2e8f0; border-top: none; padding: 16px; border-radius: 0 0 10px 10px; }
        .alert { border-radius: 8px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; }
        .alert-warning { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; }
        .alert-error { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; }
        .meta { margin-top: 14px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 12px; font-size: 12px; line-height: 1.7; }
        iframe { width: 100%; border: none; min-height: 620px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>PayTR iFrame API Test Sayfasi</h1>
        <p>Bu sayfa mevcut .env ayarlari ile token alip iframe acmayi dogrular.</p>
    </div>
    <div class="info-box">
        <table>
            <tr><td>Siparis No</td><td><?= htmlspecialchars($merchant_oid, ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>Tutar</td><td>1,00 TL</td></tr>
            <tr><td>Musteri</td><td><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>)</td></tr>
            <tr><td>Mod</td><td><span class="badge"><?= $test_mode ? 'TEST MODE' : 'CANLI MODE' ?></span></td></tr>
            <tr><td>Kullanilan IP</td><td><?= htmlspecialchars($user_ip, ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>Para Birimi</td><td><?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?></td></tr>
        </table>
    </div>
    <div class="iframe-wrap">
        <div class="alert alert-warning">
            Test karti: <strong>4355 0843 5508 4358</strong> | SKT: <strong>12/26</strong> | CVV: <strong>000</strong> | 3D Sifre: <strong>a</strong>
        </div>

        <?php if ($token !== null): ?>
            <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
            <iframe src="https://www.paytr.com/odeme/guvenli/<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>" id="paytriframe" scrolling="no"></iframe>
            <script>iFrameResize({}, '#paytriframe');</script>
        <?php else: ?>
            <div class="alert alert-error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="meta">
                <div><strong>Merchant ID:</strong> <?= htmlspecialchars($merchant_id, ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Callback URL:</strong> <?= htmlspecialchars($callback_url, ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Raw Response:</strong> <?= htmlspecialchars(is_string($result) ? $result : '', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
