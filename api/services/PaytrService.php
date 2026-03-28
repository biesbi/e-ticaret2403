<?php

final class PaytrService {
    private static function cleanEnv(string $key, string $default = ''): string {
        $value = (string) env($key, $default);
        $value = trim($value);
        return trim($value, "\"' \t\n\r\0\x0B");
    }

    public static function isEnabled(): bool {
        return filter_var(env('PAYTR_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function isTestMode(): bool {
        return filter_var(env('PAYTR_TEST_MODE', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function useMock(): bool {
        return filter_var(env('PAYTR_USE_MOCK', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function isConfigured(): bool {
        return self::cleanEnv('PAYTR_MERCHANT_ID') !== ''
            && self::cleanEnv('PAYTR_MERCHANT_KEY') !== ''
            && self::cleanEnv('PAYTR_MERCHANT_SALT') !== '';
    }

    public static function createPayment(array $order, array $items, array $shippingAddress): array {
        if (!self::isEnabled()) {
            return [
                'provider' => 'paytr',
                'enabled' => false,
                'test_mode' => self::isTestMode(),
                'mock' => true,
                'status' => 'disabled',
                'message' => 'PAYTR odeme entegrasyonu devre disi.',
            ];
        }

        if (self::useMock() || !self::isConfigured()) {
            return self::createMockPayment($order);
        }

        return self::createIframePayment($order, $items, $shippingAddress);
    }

    public static function completeMock(string $orderId, string $status): array {
        $paymentStatus = $status === 'success' ? 'paid' : 'failed';
        $orderStatus = $status === 'success'
            ? StockService::resolveStatus(['processing', 'confirmed', 'paid'], 'pending')
            : StockService::resolveStatus(['failed', 'cancelled', 'pending'], 'pending');

        $stmt = db()->prepare(
            'UPDATE orders
             SET payment_status = ?, status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$paymentStatus, $orderStatus, $orderId]);

        if ($stmt->rowCount() === 0) {
            error('Siparis bulunamadi.', 404);
        }

        $fetch = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $fetch->execute([$orderId]);
        $order = $fetch->fetch();

        if ($status === 'success') {
            StockService::finalizeReservedStock($orderId);
        } else {
            StockService::releaseReservedStock($orderId);
        }

        ok([
            'success' => true,
            'order' => legacyOrder($order ?: ['id' => $orderId]),
            'payment_status' => $paymentStatus,
        ]);
    }

    public static function handleCallback(array $data): never {
        $merchantOid = trim((string) ($data['merchant_oid'] ?? ''));
        $status = trim((string) ($data['status'] ?? ''));
        $totalAmount = trim((string) ($data['total_amount'] ?? ''));
        $hash = trim((string) ($data['hash'] ?? ''));

        if ($merchantOid === '' || $status === '' || $totalAmount === '' || $hash === '') {
            http_response_code(400);
            exit('FAILED');
        }

        $merchantKey = self::cleanEnv('PAYTR_MERCHANT_KEY');
        $merchantSalt = self::cleanEnv('PAYTR_MERCHANT_SALT');
        $expected = base64_encode(hash_hmac('sha256', $merchantOid . $merchantSalt . $status . $totalAmount, $merchantKey, true));

        if (!hash_equals($expected, $hash)) {
            http_response_code(400);
            exit('FAILED');
        }

        // İdempotency kontrolü: zaten işlenmiş callback'i tekrar işleme
        $orderCheck = db()->prepare('SELECT id, payment_status, stock_state FROM orders WHERE id = ? LIMIT 1');
        $orderCheck->execute([$merchantOid]);
        $existingOrder = $orderCheck->fetch();
        if (!$existingOrder) {
            http_response_code(404);
            exit('FAILED');
        }
        // Ödeme zaten işlenmişse tekrar işleme
        if (in_array($existingOrder['payment_status'] ?? '', ['paid', 'failed'], true)
            && ($existingOrder['stock_state'] ?? 'none') !== 'reserved') {
            http_response_code(200);
            exit('OK');
        }

        $paymentStatus = $status === 'success' ? 'paid' : 'failed';
        $orderStatus = $status === 'success'
            ? StockService::resolveStatus(['processing', 'confirmed', 'paid'], 'pending')
            : StockService::resolveStatus(['failed', 'cancelled', 'pending'], 'pending');

        $stmt = db()->prepare(
            'UPDATE orders
             SET payment_status = ?, status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$paymentStatus, $orderStatus, $merchantOid]);

        if ($status === 'success') {
            StockService::finalizeReservedStock($merchantOid);
        } else {
            StockService::releaseReservedStock($merchantOid);
        }

        http_response_code(200);
        exit('OK');
    }

    private static function createMockPayment(array $order, ?string $reason = null): array {
        $baseUrl = rtrim((string) env('APP_URL', 'http://localhost:5173'), '/');
        $message = 'PAYTR test akisi mock modda hazirlandi.';
        if ($reason !== null && $reason !== '') {
            $message .= ' Neden: ' . $reason;
        }
        return [
            'provider' => 'paytr',
            'enabled' => true,
            'test_mode' => true,
            'mock' => true,
            'status' => 'mock_ready',
            'merchant_oid' => (string) $order['id'],
            'payment_url' => $baseUrl . '/paytr-test.html?order_id=' . urlencode((string) $order['id']) . '&amount=' . urlencode((string) $order['total']),
            'message' => $message,
            'reason' => $reason,
        ];
    }

    private static function createIframePayment(array $order, array $items, array $shippingAddress): array {
        $merchantId = self::cleanEnv('PAYTR_MERCHANT_ID');
        $merchantKey = self::cleanEnv('PAYTR_MERCHANT_KEY');
        $merchantSalt = self::cleanEnv('PAYTR_MERCHANT_SALT');
        $callbackUrl = self::cleanEnv('PAYTR_CALLBACK_URL');
        $currency = self::cleanEnv('PAYTR_CURRENCY', 'TL');
        $timeoutLimit = (int) env('PAYTR_TIMEOUT', 30);
        $noInstallment = (int) env('PAYTR_NO_INSTALLMENT', 0);
        $maxInstallment = (int) env('PAYTR_MAX_INSTALLMENT', 0);

        $userIp = self::resolveUserIp();
        $email = (string) ($shippingAddress['email'] ?? '');
        if ($email === '') {
            throw new RuntimeException('PAYTR icin e-posta gerekli.');
        }

        $basket = [];
        foreach ($items as $item) {
            $name = (string) ($item['product']['name'] ?? $item['product_name'] ?? $item['name'] ?? 'Urun');
            $price = (float) ($item['price'] ?? $item['unit_price'] ?? 0);
            $basket[] = [
                $name,
                number_format($price, 2, '.', ''),
                (int) $item['quantity'],
            ];
        }

        $merchantOid = (string) $order['id'];
        $paymentAmount = (int) round(((float) ($order['total'] ?? 0)) * 100);
        $userName = (string) ($shippingAddress['fullname'] ?? '');
        $userAddress = implode(', ', array_filter([
            $shippingAddress['neighborhood'] ?? '',
            $shippingAddress['street'] ?? '',
            $shippingAddress['address_detail'] ?? '',
            $shippingAddress['district'] ?? '',
            $shippingAddress['city'] ?? '',
        ]));
        $userPhone = (string) ($shippingAddress['phone'] ?? '');

        $hashStr = $merchantId . $userIp . $merchantOid . $email . $paymentAmount . base64_encode(json_encode($basket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . $noInstallment . $maxInstallment . $currency . (self::isTestMode() ? 1 : 0);
        $paytrToken = base64_encode(hash_hmac('sha256', $hashStr . $merchantSalt, $merchantKey, true));

        $payload = [
            'merchant_id' => $merchantId,
            'user_ip' => $userIp,
            'merchant_oid' => $merchantOid,
            'email' => $email,
            'payment_amount' => $paymentAmount,
            'paytr_token' => $paytrToken,
            'user_basket' => base64_encode(json_encode($basket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'debug_on' => self::isTestMode() ? 1 : 0,
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'user_name' => $userName,
            'user_address' => $userAddress,
            'user_phone' => $userPhone,
            'merchant_ok_url' => rtrim((string) env('APP_URL', ''), '/') . '/paytr-result.html?status=success&order_id=' . urlencode($merchantOid),
            'merchant_fail_url' => rtrim((string) env('APP_URL', ''), '/') . '/paytr-result.html?status=failed&order_id=' . urlencode($merchantOid),
            'timeout_limit' => $timeoutLimit,
            'currency' => $currency,
            'test_mode' => self::isTestMode() ? 1 : 0,
            'lang' => 'tr',
            'callback_url' => $callbackUrl,
        ];

        $response = self::postForm('https://www.paytr.com/odeme/api/get-token', $payload);
        if (($response['status'] ?? '') !== 'success' || empty($response['token'])) {
            $reason = (string) ($response['reason'] ?? $response['raw'] ?? 'token_alinamadi');
            return [
                'provider' => 'paytr',
                'enabled' => true,
                'test_mode' => self::isTestMode(),
                'mock' => false,
                'status' => 'failed',
                'merchant_oid' => $merchantOid,
                'message' => 'PAYTR token alinamadi. Neden: ' . $reason,
                'reason' => $reason,
            ];
        }

        db()->prepare('UPDATE orders SET paytr_token = ?, paytr_merchant_oid = ? WHERE id = ?')
            ->execute([(string) $response['token'], $merchantOid, $merchantOid]);

        return [
            'provider' => 'paytr',
            'enabled' => true,
            'test_mode' => self::isTestMode(),
            'mock' => false,
            'status' => 'iframe_ready',
            'merchant_oid' => $merchantOid,
            'iframe_token' => (string) $response['token'],
            'payment_url' => 'https://www.paytr.com/odeme/guvenli/' . rawurlencode((string) $response['token']),
            'message' => 'PAYTR iframe odeme oturumu hazirlandi.',
        ];
    }

    private static function resolveUserIp(): string {
        $explicit = self::cleanEnv('PAYTR_TEST_USER_IP');
        if ($explicit !== '' && filter_var($explicit, FILTER_VALIDATE_IP)) {
            return $explicit;
        }

        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $ip = trim(explode(',', $candidate)[0] ?? '');
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (self::isTestMode()) {
            return '1.2.3.4';
        }

        if (self::useMock()) {
            return '127.0.0.1';
        }

        throw new RuntimeException('PAYTR icin gecerli user_ip veya PAYTR_TEST_USER_IP gerekli.');
    }

    private static function isPublicIp(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function postForm(string $url, array $payload): array {
        $body = http_build_query($payload);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT => 30,
            ]);
            $result = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $body,
                    'timeout' => 30,
                ],
            ]);
            $result = @file_get_contents($url, false, $context);
        }

        if (!is_string($result) || $result === '') {
            return ['status' => 'failed', 'reason' => 'empty_response'];
        }

        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : ['status' => 'failed', 'raw' => $result];
    }
}
