<?php

MailService::ensureSchema();

$passwordColumn = tableHasColumn('users', 'password_hash') ? 'password_hash' : 'password';
$hasUsername    = tableHasColumn('users', 'username');
$hasDisplayName = tableHasColumn('users', 'display_name');
$nameColumn     = $hasDisplayName ? 'display_name' : (tableHasColumn('users', 'name') ? 'name' : 'email');
$isActiveColumn = tableHasColumn('users', 'is_active');

switch ($id) {
    case 'login':
        if ($method !== 'POST') error('Method not allowed.', 405);

        RateLimit::check('auth_login', (int) env('RATE_LIMIT_LOGIN', 5), (int) env('RATE_LIMIT_WINDOW', 300));
        if (!tableHasColumn('users', 'last_login')) {
            db()->exec('ALTER TABLE users ADD COLUMN last_login DATETIME NULL');
        }

        $identifier = strtolower(trim(input('email', input('username', ''))));
        $password   = (string) input('password', '');

        if ($identifier === '' || $password === '') {
            error('E-posta ve sifre gerekli.');
        }

        $userSql = $hasUsername
            ? 'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1'
            : 'SELECT * FROM users WHERE email = ? LIMIT 1';
        $stmt = db()->prepare($userSql);
        $stmt->execute($hasUsername ? [$identifier, $identifier] : [$identifier]);
        $user = $stmt->fetch();

        $storedPassword = $user[$passwordColumn] ?? null;
        $isValidPassword = is_string($storedPassword) && password_verify($password, $storedPassword);

        if (!$user || !$isValidPassword) {
            AuditLog::write(AuditLog::LOGIN_FAIL, null, 'user', null, ['identifier' => $identifier]);
            error('Kullanici adi veya sifre hatali.', 401);
        }

        $isAdmin       = (($user['role'] ?? 'user') === 'admin');
        $emailVerified = !empty($user['email_verified']) || !empty($user['email_verified_at']);

        if ($isActiveColumn && !$isAdmin && isset($user['is_active']) && (int) $user['is_active'] !== 1) {
            if ($emailVerified) {
                error('Hesabiniz aktif degil. Destek ile iletisime gecin.', 403);
            }
            db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$user['id']]);
        }

        db()->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([$user['id']]);
        $user['last_login'] = date('Y-m-d H:i:s');

        AuditLog::write(AuditLog::LOGIN_SUCCESS, (string) $user['id'], 'user', $user['id']);

        $token = jwtEncode([
            'sub'  => $user['id'],
            'user' => $user['username'] ?? $user['email'],
            'role' => ($isAdmin ? 'admin' : 'customer'),
        ]);

        $orders = db()->prepare(
            'SELECT id, status, total, subtotal, discount, shipping_address, cargo_number, cargo_company, created_at
             FROM orders
             WHERE user_id = ?
               AND ' . OrderService::visibleListSql() . '
             ORDER BY created_at DESC'
        );
        $orders->execute([$user['id']]);

        ok([
            'token'                => $token,
            'email_verified'       => $emailVerified,
            'verification_pending' => !$emailVerified,
            'user'                 => legacyUser([
                'id'             => $user['id'],
                'username'       => $user['username'] ?? $user['email'],
                'display_name'   => $user['display_name'] ?? $user['name'] ?? '',
                'email'          => $user['email'],
                'role'           => ($isAdmin ? 'admin' : 'customer'),
                'email_verified' => $emailVerified,
                'orders'         => array_map(fn(array $order) => legacyOrder($order), $orders->fetchAll()),
            ]),
        ]);
        break;

    case 'register':
        if ($method !== 'POST') error('Method not allowed.', 405);
        RateLimit::check('auth_register', 5, 600);

        $displayName = trim((string) input('name', input('display_name', '')));
        $email       = strtolower(trim((string) input('email', '')));
        $password    = (string) input('password', '');

        if ($displayName === '' || $email === '' || $password === '') {
            error('Tum alanlar zorunlu.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error('Gecerli bir e-posta girin.');
        }
        if (strlen($password) < 8) {
            error('Sifre en az 8 karakter olmali.');
        }
        if (strlen($password) > 128) {
            error('Sifre en fazla 128 karakter olabilir.');
        }

        $baseUsername = strtolower(preg_replace('/[^a-z0-9]+/', '', strstr($email, '@', true) ?: $displayName));
        $username     = $baseUsername !== '' ? $baseUsername : 'user' . time();

        if ($hasUsername) {
            $suffix = 1;
            $exists = db()->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            while (true) {
                $exists->execute([$username, $email]);
                $taken = $exists->fetch();
                if (!$taken) {
                    break;
                }

                $emailExists = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $emailExists->execute([$email]);
                if ($emailExists->fetch()) {
                    error('Bu e-posta zaten kayitli.', 409);
                }

                $username = $baseUsername . $suffix;
                $suffix++;
            }
        } else {
            $emailExists = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $emailExists->execute([$email]);
            if ($emailExists->fetch()) {
                error('Bu e-posta zaten kayitli.', 409);
            }
        }

        $hash       = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $userIdType = tableColumnType('users', 'id') ?? '';
        $userId     = str_contains($userIdType, 'char') || str_contains($userIdType, 'varchar')
            ? substr(generatePublicId(18), 0, 36)
            : null;

        if ($hasUsername && $hasDisplayName) {
            $columns = $userId !== null
                ? ['id', 'username', 'display_name', 'email', $passwordColumn, 'role', 'email_verified', 'email_verified_at']
                : ['username', 'display_name', 'email', $passwordColumn, 'role', 'email_verified', 'email_verified_at'];
            if ($isActiveColumn) {
                $columns[] = 'is_active';
            }

            $values = $userId !== null
                ? [$userId, $username, $displayName, $email, $hash, 'customer', 0, null]
                : [$username, $displayName, $email, $hash, 'customer', 0, null];
            if ($isActiveColumn) {
                $values[] = 1;
            }

            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $stmt = db()->prepare('INSERT INTO users (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute($values);
        } else {
            $columns = $userId !== null
                ? ['id', 'email', 'name', $passwordColumn, 'role', 'email_verified', 'email_verified_at']
                : ['email', 'name', $passwordColumn, 'role', 'email_verified', 'email_verified_at'];
            if ($isActiveColumn) {
                $columns[] = 'is_active';
            }

            $values = $userId !== null
                ? [$userId, $email, $displayName, $hash, 'user', 0, null]
                : [$email, $displayName, $hash, 'user', 0, null];
            if ($isActiveColumn) {
                $values[] = 1;
            }

            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $stmt = db()->prepare('INSERT INTO users (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute($values);
        }

        $createdId = $userId ?? db()->lastInsertId();
        MailService::issueVerificationToken($createdId, $email, $displayName);
        MailService::sendRegistrationAdminEmail($email, $displayName);
        AuditLog::write(AuditLog::REGISTER, (string) $createdId, 'user', $createdId, ['email' => $email]);

        ok([
            'success'               => true,
            'verification_pending'  => true,
            'email'                 => $email,
            'message'               => 'Kayit tamamlandi. Giris yapabilirsiniz. E-posta adresinize dogrulama linki gonderildi.',
        ]);
        break;

    case 'resend-verification':
        if ($method !== 'POST') error('Method not allowed.', 405);
        RateLimit::check('auth_resend_verification', 5, 900);

        $email = strtolower(trim((string) input('email', '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error('Gecerli bir e-posta adresi gerekli.', 422);
        }

        $stmt = db()->prepare(
            'SELECT id, email, role, '
            . ($hasUsername ? 'username, ' : '')
            . $nameColumn . ' AS display_name, email_verified, email_verified_at
             FROM users
             WHERE email = ?
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            ok([
                'success' => true,
                'message' => 'Bu e-posta adresi sistemde varsa yeni dogrulama maili gonderilecektir.',
            ]);
        }

        if (($user['role'] ?? 'user') !== 'admin' && (!empty($user['email_verified']) || !empty($user['email_verified_at']))) {
            ok([
                'success'          => true,
                'already_verified' => true,
                'message'          => 'Bu e-posta adresi zaten dogrulanmis.',
            ]);
        }

        MailService::issueVerificationToken($user['id'], $user['email'], (string) ($user['display_name'] ?? $user['email']));

        ok([
            'success' => true,
            'message' => 'Yeni dogrulama e-postasi gonderildi.',
        ]);
        break;

    case 'forgot-password':
        if ($method !== 'POST') error('Method not allowed.', 405);
        RateLimit::check('auth_forgot_password', 5, 900);

        $email = strtolower(trim((string) input('email', '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error('Gecerli bir e-posta adresi gerekli.', 422);
        }

        $stmt = db()->prepare(
            'SELECT id, email, '
            . ($hasUsername ? 'username, ' : '')
            . $nameColumn . ' AS display_name
             FROM users
             WHERE email = ?
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            MailService::issuePasswordResetToken(
                $user['id'],
                (string) $user['email'],
                (string) ($user['display_name'] ?? $user['email'])
            );
        }

        ok([
            'success' => true,
            'message' => 'Bu e-posta adresi sistemde varsa sifre sifirlama baglantisi gonderilecektir.',
        ]);
        break;

    case 'reset-password':
        if ($method !== 'POST') error('Method not allowed.', 405);
        RateLimit::check('auth_reset_password', 10, 3600);

        $token                = trim((string) input('token', ''));
        $password             = (string) input('password', '');
        $passwordConfirmation = (string) input('password_confirmation', input('passwordConfirmation', ''));

        if ($token === '') {
            error('Sifre sifirlama baglantisi gecersiz.', 422);
        }
        if ($password === '') {
            error('Yeni sifre gerekli.', 422);
        }
        if (strlen($password) < 8) {
            error('Sifre en az 8 karakter olmali.', 422);
        }
        if (strlen($password) > 128) {
            error('Sifre en fazla 128 karakter olabilir.', 422);
        }
        if ($passwordConfirmation !== '' && !hash_equals($password, $passwordConfirmation)) {
            error('Sifre tekrar alani eslesmiyor.', 422);
        }

        $stmt = db()->prepare(
            'SELECT id, email, password_reset_expires_at
             FROM users
             WHERE password_reset_token = ?
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            error('Sifre sifirlama baglantisi gecersiz veya daha once kullanilmis.', 404);
        }

        if (
            !empty($user['password_reset_expires_at'])
            && strtotime((string) $user['password_reset_expires_at']) < time()
        ) {
            error('Sifre sifirlama baglantisinin suresi dolmus. Lutfen yeni bir talep olusturun.', 410);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        db()->prepare(
            'UPDATE users
             SET ' . $passwordColumn . ' = ?,
                 password_reset_token = NULL,
                 password_reset_sent_at = NULL,
                 password_reset_expires_at = NULL
             WHERE id = ?'
        )->execute([$hash, $user['id']]);

        ok([
            'success' => true,
            'message' => 'Sifreniz basariyla guncellendi. Artik yeni sifrenizle giris yapabilirsiniz.',
        ]);
        break;

    case 'verify-email':
        if ($method !== 'GET') error('Method not allowed.', 405);

        $token = trim((string) ($sub ?? ($_GET['token'] ?? '')));
        if ($token === '') {
            error('Dogrulama tokeni gerekli.');
        }

        $stmt = db()->prepare(
            "SELECT id, email, role, email_verified, email_verified_at, email_verification_expires_at, $nameColumn AS display_name
             FROM users
             WHERE email_verification_token = ?
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            error('Dogrulama baglantisi gecersiz veya daha once kullanilmis.', 404);
        }

        if (!empty($user['email_verified']) || !empty($user['email_verified_at'])) {
            ok([
                'success'          => true,
                'already_verified' => true,
                'message'          => 'E-posta adresiniz zaten dogrulanmis. Giris yapabilirsiniz.',
            ]);
        }

        if (
            !empty($user['email_verification_expires_at'])
            && strtotime((string) $user['email_verification_expires_at']) < time()
        ) {
            error('Dogrulama baglantisinin suresi dolmus. Yeni bir dogrulama e-postasi isteyebilirsiniz.', 410);
        }

        $sql = 'UPDATE users
                SET email_verified = 1,
                    email_verified_at = CURRENT_TIMESTAMP,
                    email_verification_token = NULL,
                    email_verification_sent_at = NULL,
                    email_verification_expires_at = NULL';
        if ($isActiveColumn) {
            $sql .= ', is_active = 1';
        }
        $sql .= ' WHERE id = ?';

        db()->prepare($sql)->execute([$user['id']]);

        ok([
            'success' => true,
            'message' => 'E-posta adresiniz basariyla dogrulandi. Artik giris yapabilirsiniz.',
        ]);
        break;

    case 'logout':
        if ($method !== 'POST') error('Method not allowed.', 405);
        $token = Auth::extractToken();
        if ($token) Auth::blacklist($token);
        ok(['success' => true]);
        break;

    case 'me':
        if ($method !== 'GET') error('Method not allowed.', 405);
        $payload = Auth::require();

        $select = [
            'id',
            $hasUsername ? 'username' : 'NULL AS username',
            $hasDisplayName
                ? 'display_name'
                : (tableHasColumn('users', 'name') ? 'name AS display_name' : 'NULL AS display_name'),
            'email',
            'role',
            tableHasColumn('users', 'email_verified') ? 'email_verified' : 'CASE WHEN email_verified_at IS NULL THEN 0 ELSE 1 END AS email_verified',
            'email_verified_at',
        ];
        $stmt = db()->prepare('SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();
        if (!$user) error('Kullanici bulunamadi.', 404);

        $orders = db()->prepare(
            'SELECT id, status, total, city, district, tracking_no, cargo_carrier, created_at
             FROM orders
             WHERE user_id = ?
               AND ' . OrderService::visibleListSql() . '
             ORDER BY created_at DESC'
        );
        $orders->execute([$user['id']]);
        $user['orders'] = array_map(fn(array $order) => legacyOrder($order), $orders->fetchAll());

        ok(legacyUser($user));
        break;

    case 'users':
        if ($method !== 'GET') error('Method not allowed.', 405);
        Auth::requireAdmin();

        $rows = db()->query(
            'SELECT id, '
            . ($hasUsername ? 'username' : 'NULL AS username') . ', '
            . ($hasDisplayName
                ? 'display_name'
                : (tableHasColumn('users', 'name') ? 'name AS display_name' : 'NULL AS display_name'))
            . ', email, role, created_at, '
            . (tableHasColumn('users', 'email_verified') ? 'email_verified' : 'CASE WHEN email_verified_at IS NULL THEN 0 ELSE 1 END AS email_verified')
            . ', email_verified_at FROM users ORDER BY created_at DESC'
        )->fetchAll();

        ok(array_map(fn(array $user) => legacyUser($user), $rows));
        break;

    default:
        error('Auth endpoint bulunamadi.', 404);
}
