<?php
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/services/PaytrService.php';

header('Content-Type: application/json');

echo json_encode([
    'PAYTR_ENABLED' => env('PAYTR_ENABLED'),
    'PAYTR_USE_MOCK' => env('PAYTR_USE_MOCK'),
    'PAYTR_TEST_MODE' => env('PAYTR_TEST_MODE'),
    'PAYTR_MERCHANT_ID' => env('PAYTR_MERCHANT_ID'),
    'PAYTR_MERCHANT_KEY' => env('PAYTR_MERCHANT_KEY') ? 'SET' : 'NOT SET',
    'PAYTR_MERCHANT_SALT' => env('PAYTR_MERCHANT_SALT') ? 'SET' : 'NOT SET',
    'isEnabled' => PaytrService::isEnabled(),
    'useMock' => PaytrService::useMock(),
    'isTestMode' => PaytrService::isTestMode(),
    'isConfigured' => PaytrService::isConfigured(),
], JSON_PRETTY_PRINT);
