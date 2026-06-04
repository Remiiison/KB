<?php
class AuthController {

    public function signup(): void {
        $body      = get_body();
        $firstName = trim($body['firstName'] ?? '');
        $lastName  = trim($body['lastName']  ?? '');
        $email     = strtolower(trim($body['email'] ?? ''));
        $password  = $body['password'] ?? '';

        if (!$firstName || !$lastName || !$email || !$password) {
            json_error('Missing required fields.');
        }
        if (!validate_email($email)) {
            json_error('Invalid email format.');
        }
        if (!validate_password($password)) {
            json_error('Password must be at least 8 characters.');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT user_id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            json_error('Email already registered.', 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $db->prepare(
            'INSERT INTO users (first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, \'donor\')'
        )->execute([$firstName, $lastName, $email, $hash]);

        $userId = (int)$db->lastInsertId();
        session_start_once();
        $_SESSION['user_id'] = $userId;

        json_ok(['message' => 'Account created.', 'user' => map_user($this->findById($userId))], 201);
    }

    public function signin(): void {
        $body     = get_body();
        $email    = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            json_error('Email and password are required.');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT user_id, first_name, last_name, email, password_hash, role, date_registered
             FROM users WHERE LOWER(email) = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
            json_error('Invalid email or password.', 401);
        }

        session_start_once();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['user_id'];

        json_ok(['message' => 'Signed in.', 'user' => map_user($user)]);
    }

    public function logout(): void {
        session_start_once();
        $_SESSION = [];
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');
        json_ok(['message' => 'Signed out.']);
    }

    public function getMe(): void {
        $user = require_auth();
        json_ok(['user' => map_user($user)]);
    }

    public function updateMe(): void {
        $user    = require_auth();
        $body    = get_body();
        $updates = [];
        $params  = [];

        if (isset($body['firstName']) && trim($body['firstName']) !== '') {
            $updates[] = 'first_name = ?';
            $params[]  = trim($body['firstName']);
        }
        if (isset($body['lastName']) && trim($body['lastName']) !== '') {
            $updates[] = 'last_name = ?';
            $params[]  = trim($body['lastName']);
        }
        if (empty($updates)) {
            json_error('No fields to update.');
        }

        $params[] = $user['user_id'];
        Database::getInstance()
            ->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = ?')
            ->execute($params);

        json_ok(['message' => 'Profile updated.', 'user' => map_user($this->findById($user['user_id']))]);
    }

    public function forgotPassword(): void {
        $body  = get_body();
        $email = strtolower(trim($body['email'] ?? ''));
        if (!$email) {
            json_error('Email is required.');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT user_id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        $found = $stmt->fetch();

        if ($found) {
            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires   = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $db->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
            )->execute([$found['user_id'], $tokenHash, $expires]);

            $resetUrl = FRONTEND_URL . '/HTML/SignIn.html?token=' . $token;
            (new EmailService())->sendPasswordResetEmail($email, $resetUrl);
        }

        json_ok(['message' => 'If that email is registered, a reset link has been sent.']);
    }

    public function resetPassword(): void {
        $body     = get_body();
        $token    = $body['token']    ?? '';
        $password = $body['password'] ?? '';

        if (!$token || !$password) {
            json_error('Token and new password are required.');
        }
        if (!validate_password($password)) {
            json_error('Password must be at least 8 characters.');
        }

        $tokenHash = hash('sha256', $token);
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT token_id, user_id FROM password_reset_tokens
             WHERE token_hash = ? AND expires_at > NOW() AND used_at IS NULL LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $record = $stmt->fetch();

        if (!$record) {
            json_error('This reset link is invalid or has expired.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([$hash, $record['user_id']]);
        $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE token_id = ?')->execute([$record['token_id']]);

        json_ok(['message' => 'Password updated successfully. You can now sign in.']);
    }

    /* ── Quick Access Code login ── */
    public function adminCode(): void {
        $code = strtoupper(trim(get_body()['code'] ?? ''));
        if (!$code) json_error('Access code is required.');

        $superCode = strtoupper(getenv('ADMIN_SUPER_CODE') ?: '');
        $ngo1Code  = strtoupper(getenv('ADMIN_NGO1_CODE')  ?: '');
        $ngo2Code  = strtoupper(getenv('ADMIN_NGO2_CODE')  ?: '');

        // Legacy fallback: old ADMIN_NGO_CODE maps to NGO Admin 1
        $ngoLegacy = strtoupper(getenv('ADMIN_NGO_CODE') ?: '');

        if ($superCode === '') {
            json_error('Access codes are not configured on this server.', 503);
        }

        // Map code → target email
        $targetEmail = null;
        if ($code === $superCode) {
            $targetEmail = 'superadmin@kapitbisig.online';
        } elseif ($ngo1Code !== '' && $code === $ngo1Code) {
            $targetEmail = 'ngoadmin1@kapitbisig.online';
        } elseif ($ngo2Code !== '' && $code === $ngo2Code) {
            $targetEmail = 'ngoadmin2@kapitbisig.online';
        } elseif ($ngoLegacy !== '' && $code === $ngoLegacy) {
            $targetEmail = 'ngoadmin1@kapitbisig.online'; // legacy maps to NGO 1
        } else {
            json_error('Invalid access code.', 401);
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT user_id, first_name, last_name, email, role, date_registered
             FROM users WHERE LOWER(email) = ? LIMIT 1'
        );
        $stmt->execute([strtolower($targetEmail)]);
        $user = $stmt->fetch();

        if (!$user) {
            json_error('Admin account not found. Run the NGO setup script first.', 404);
        }

        session_start_once();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['user_id'];

        json_ok(['message' => 'Signed in.', 'user' => map_user($user)]);
    }

    private function findById(int $id): array {
        $stmt = Database::getInstance()->prepare(
            'SELECT user_id, first_name, last_name, email, role, date_registered
             FROM users WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
}
