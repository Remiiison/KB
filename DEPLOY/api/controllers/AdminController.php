<?php
class AdminController {

    /* ── createUser POST /admin/users ── */
    public function createUser(): void {
        $user = require_auth();
        require_role(['admin', 'superadmin'], $user);
        $body = get_body();

        $firstName = trim($body['firstName'] ?? '');
        $lastName  = trim($body['lastName']  ?? '');
        $email     = strtolower(trim($body['email'] ?? ''));
        $password  = $body['password'] ?? '';
        $role      = $body['role']     ?? 'donor';

        if (!$firstName || !$lastName || !$email || !$password) json_error('Missing required fields.');
        if (!validate_email($email))    json_error('Invalid email format.');
        if (!validate_password($password)) json_error('Password must be at least 8 characters.');

        $allowed = ['donor', 'ngo_admin', 'ngo', 'admin', 'superadmin'];
        if (!in_array($role, $allowed, true)) json_error('Invalid role.');

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT user_id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) json_error('Email already registered.', 409);

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $db->prepare('INSERT INTO users (first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)')
           ->execute([$firstName, $lastName, $email, $hash, $role]);

        $newId = (int)$db->lastInsertId();
        log_activity($user['user_id'], 'user', $newId, 'create');

        $s = $db->prepare('SELECT user_id, first_name, last_name, email, role, date_registered FROM users WHERE user_id = ?');
        $s->execute([$newId]);
        json_ok(['message' => 'User created.', 'user' => map_user($s->fetch())], 201);
    }

    /* ── createNGOProfile POST /admin/ngos ── */
    public function createNGOProfile(): void {
        $user = require_auth();
        require_role(['admin', 'superadmin'], $user);
        $body   = get_body();
        $db     = Database::getInstance();

        $ngoName       = trim($body['ngoName']       ?? '');
        $description   = trim($body['description']   ?? '');
        $contactPerson = trim($body['contactPerson'] ?? '');
        $targetUserId  = (int)($body['userId'] ?? $user['user_id']);

        if (!$ngoName) json_error('NGO name is required.');

        $db->prepare(
            "INSERT INTO ngos (ngo_name, description, contact_person, user_id, verification_status)
             VALUES (?, ?, ?, ?, 'pending')"
        )->execute([$ngoName, $description, $contactPerson, $targetUserId]);

        $ngoId = (int)$db->lastInsertId();
        log_activity($user['user_id'], 'ngo', $ngoId, 'create');

        $s = $db->prepare('SELECT * FROM ngos WHERE ngo_id = ?');
        $s->execute([$ngoId]);
        json_ok(['message' => 'NGO profile created.', 'ngo' => $s->fetch()], 201);
    }

    /* ── getUsers GET /admin/users ── */
    public function getUsers(): void {
        $user   = require_auth();
        require_role(['admin', 'superadmin'], $user);
        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = Database::getInstance()->prepare(
            'SELECT user_id, first_name, last_name, email, role, date_registered
             FROM users ORDER BY date_registered DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        json_ok(['users' => array_map('map_user', $stmt->fetchAll())]);
    }

    /* ── updateUserRole PUT /admin/users/:id/role ── */
    public function updateUserRole(string $userId): void {
        $user = require_auth();
        require_role(['admin', 'superadmin'], $user);
        $role    = get_body()['role'] ?? '';
        $allowed = ['donor', 'ngo_admin', 'ngo', 'admin', 'superadmin'];
        if (!in_array($role, $allowed, true)) json_error('Invalid role.');

        Database::getInstance()
            ->prepare('UPDATE users SET role = ? WHERE user_id = ?')
            ->execute([$role, (int)$userId]);

        log_activity($user['user_id'], 'user', $userId, 'update_role');
        json_ok(['message' => 'User role updated.']);
    }

    /* ── deleteUser DELETE /admin/users/:id ── */
    public function deleteUser(string $userId): void {
        $user = require_auth();
        require_role(['superadmin'], $user);
        Database::getInstance()->prepare('DELETE FROM users WHERE user_id = ?')->execute([(int)$userId]);
        log_activity($user['user_id'], 'user', $userId, 'delete');
        json_ok(['message' => 'User deleted.']);
    }

    /* ── getActivityLogs GET /admin/activity-logs ── */
    public function getActivityLogs(): void {
        $user   = require_auth();
        require_role(['admin', 'superadmin'], $user);
        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);

        $where  = [];
        $params = [];
        if (!empty($_GET['adminId']))    { $where[] = 'admin_id = ?';    $params[] = (int)$_GET['adminId']; }
        if (!empty($_GET['entityType'])) { $where[] = 'entity_type = ?'; $params[] = $_GET['entityType']; }
        if (!empty($_GET['action']))     { $where[] = 'action = ?';      $params[] = $_GET['action']; }
        if (!empty($_GET['startDate']))  { $where[] = 'timestamp >= ?';  $params[] = $_GET['startDate']; }
        if (!empty($_GET['endDate']))    { $where[] = 'timestamp <= ?';  $params[] = $_GET['endDate']; }

        $sql = 'SELECT * FROM activity_logs'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY timestamp DESC LIMIT ? OFFSET ?';

        $params[] = $limit;
        $params[] = $offset;

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        json_ok(['logs' => $stmt->fetchAll()]);
    }

    /* ── getMyActivityLogs GET /admin/my-activity-logs ── */
    public function getMyActivityLogs(): void {
        $user   = require_auth();
        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM activity_logs WHERE admin_id = ? ORDER BY timestamp DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$user['user_id'], $limit, $offset]);
        json_ok(['logs' => $stmt->fetchAll()]);
    }

    /* ── getActivityLog GET /admin/activity-logs/:id ── */
    public function getActivityLog(string $id): void {
        $user = require_auth();
        require_role(['admin', 'superadmin'], $user);
        $stmt = Database::getInstance()->prepare('SELECT * FROM activity_logs WHERE log_id = ? LIMIT 1');
        $stmt->execute([(int)$id]);
        $log = $stmt->fetch();
        if (!$log) json_error('Log not found.', 404);
        json_ok(['log' => $log]);
    }
}
