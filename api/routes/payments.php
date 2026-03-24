<?php

if ($id === 'paytr' && $sub === 'callback') {
    if ($method !== 'POST') error('Method not allowed.', 405);
    PaytrService::handleCallback($_POST);
}

if ($id === 'paytr' && $sub === 'mock-complete') {
    if ($method !== 'POST') error('Method not allowed.', 405);
    if (!PaytrService::isTestMode()) error('Mock odeme sadece test modunda kullanilir.', 403);

    // Mock ödeme güvenliği: admin veya sipariş sahibi olmalı
    $authPayload = Auth::optional();
    $orderId = trim((string) input('order_id', ''));
    $status = trim((string) input('status', ''));
    if ($orderId === '' || !in_array($status, ['success', 'failed'], true)) {
        error('Gecersiz mock odeme verisi.');
    }

    // Sipariş sahiplik kontrolü
    if ($authPayload !== null) {
        $orderCheck = db()->prepare('SELECT user_id FROM orders WHERE id = ? LIMIT 1');
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if ($orderRow && $orderRow['user_id'] !== null) {
            $isAdmin = ($authPayload['role'] ?? '') === 'admin';
            $isOwner = (string) ($orderRow['user_id'] ?? '') === (string) ($authPayload['sub'] ?? '');
            if (!$isAdmin && !$isOwner) {
                error('Bu siparis icin odeme tamamlama yetkiniz yok.', 403);
            }
        }
    }

    PaytrService::completeMock($orderId, $status);
}

if ($id === 'paytr' && $sub === 'status') {
    if ($method !== 'GET') error('Method not allowed.', 405);
    if ($segments[3] ?? null) {
        $orderId = (string) $segments[3];
    } else {
        $orderId = (string) ($_GET['order_id'] ?? '');
    }

    if ($orderId === '') error('Siparis ID gerekli.');

    // Sahiplik kontrolü: sadece sipariş sahibi veya admin görebilir
    $authPayload = Auth::optional();
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) error('Siparis bulunamadi.', 404);

    if ($authPayload !== null) {
        $isAdmin = ($authPayload['role'] ?? '') === 'admin';
        $isOwner = (string) ($order['user_id'] ?? '') === (string) ($authPayload['sub'] ?? '');
        if (!$isAdmin && !$isOwner && $order['user_id'] !== null) {
            error('Bu siparise erisim yetkiniz yok.', 403);
        }
    }

    ok([
        'order' => legacyOrder($order),
        'payment_status' => $order['payment_status'] ?? null,
        'paytr_token' => $order['paytr_token'] ?? null,
        'paytr_merchant_oid' => $order['paytr_merchant_oid'] ?? null,
    ]);
}

error('Odeme endpoint bulunamadi.', 404);
