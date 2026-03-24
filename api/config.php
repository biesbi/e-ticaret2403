<?php
// ═══════════════════════════════════════════════
//  BoomerItems — Konfigürasyon
//  Tüm değerler .env dosyasından okunur.
//  Hiçbir değer burada hardcode edilmez.
// ═══════════════════════════════════════════════

// ─── .env Yükleyici ───────────────────────────
(function () {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        die(json_encode(['success' => false, 'message' => '.env dosyası bulunamadı.']));
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (!defined($key)) define($key, $value);
    }
})();

// ─── Zorunlu Değer Kontrolü ───────────────────
foreach (['DB_HOST','DB_NAME','DB_USER','JWT_SECRET','APP_ENV'] as $required) {
    if (!defined($required) || constant($required) === '') {
        die(json_encode(['success' => false, 'message' => "$required .env içinde tanımlı değil."]));
    }
}

// JWT_SECRET minimum uzunluk kontrolü
if (strlen(JWT_SECRET) < 32) {
    die(json_encode(['success' => false, 'message' => 'JWT_SECRET en az 32 karakter olmalı.']));
}
