<?php
/* ── Shared response helpers, auth guards, and validators ── */

function json_ok(array $data = [], int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_body(): array {
    static $body = null;
    if ($body === null) {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw ?: '{}', true) ?? [];
    }
    return $body;
}

function session_start_once(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/* Require an authenticated session. Returns the user row. */
function require_auth(): array {
    session_start_once();
    if (empty($_SESSION['user_id'])) {
        json_error('Not authenticated.', 401);
    }
    $db   = Database::getInstance();
    $stmt = $db->prepare(
        'SELECT user_id, first_name, last_name, email, role, date_registered
         FROM users WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        json_error('Not authenticated.', 401);
    }
    return $user;
}

/* Abort with 403 if user's role is not in $allowed. */
function require_role(array $allowed, array $user): void {
    if (!in_array($user['role'], $allowed, true)) {
        json_error('Forbidden.', 403);
    }
}

function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_password(string $password): bool {
    return strlen($password) >= 8;
}

/* Map a users DB row to the public user shape the frontend expects. */
function map_user(array $row): array {
    return [
        'id'        => (string)$row['user_id'],
        'firstName' => $row['first_name']     ?? '',
        'lastName'  => $row['last_name']      ?? '',
        'fullName'  => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        'email'     => $row['email'],
        'role'      => $row['role'],
        'createdAt' => $row['date_registered'] ?? null,
    ];
}

/* Write one row to activity_logs — silently ignore errors. */
function log_activity(int $adminId, string $entityType, $entityId, string $action): void {
    try {
        Database::getInstance()
            ->prepare('INSERT INTO activity_logs (admin_id, entity_type, entity_id, action) VALUES (?, ?, ?, ?)')
            ->execute([$adminId, $entityType, $entityId, $action]);
    } catch (Exception $e) {}
}
