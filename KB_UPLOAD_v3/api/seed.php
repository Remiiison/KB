<?php
/*
 * ONE-TIME ADMIN ACCOUNT SEEDER
 * ─────────────────────────────
 * 1. Upload this file to public_html/api/
 * 2. Visit: https://your-site.com/api/seed.php?key=KB-SEED-KAPITBISIG-2025
 * 3. You will see a success message with accounts created
 * 4. DELETE this file immediately after — do not leave it on the server
 */

define('SEED_KEY', 'KB-SEED-KAPITBISIG-2025');

if (($_GET['key'] ?? '') !== SEED_KEY) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif;color:red">403 — Invalid or missing seed key.</h2>');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

header('Content-Type: text/html; charset=utf-8');

$accounts = [
    [
        'first_name' => 'Super',
        'last_name'  => 'Admin',
        'email'      => 'superadmin@kapitbisig.online',
        'password'   => 'KapitAdmin@2025!',
        'role'       => 'superadmin',
    ],
    [
        'first_name' => 'NGO',
        'last_name'  => 'Admin',
        'email'      => 'ngoadmin@kapitbisig.online',
        'password'   => 'KapitNGO@2025!',
        'role'       => 'ngo_admin',
    ],
];

$results = [];

try {
    $db = Database::getInstance();

    foreach ($accounts as $acc) {
        $stmt = $db->prepare('SELECT user_id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([strtolower($acc['email'])]);

        if ($stmt->fetch()) {
            $results[] = ['email' => $acc['email'], 'status' => 'already exists — skipped'];
            continue;
        }

        $hash = password_hash($acc['password'], PASSWORD_BCRYPT, ['cost' => 10]);
        $db->prepare(
            'INSERT INTO users (first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)'
        )->execute([$acc['first_name'], $acc['last_name'], strtolower($acc['email']), $hash, $acc['role']]);

        $results[] = ['email' => $acc['email'], 'role' => $acc['role'], 'status' => 'created ✅'];
    }
} catch (Exception $e) {
    die('<p style="color:red;font-family:sans-serif">Database error: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

echo '<html><body style="font-family:sans-serif;padding:40px;background:#f0f7ff">';
echo '<h2 style="color:#1B2A4A">✅ KapitBisig Admin Accounts Seeded</h2>';
echo '<table border="1" cellpadding="10" style="border-collapse:collapse">';
echo '<tr style="background:#1B2A4A;color:#fff"><th>Email</th><th>Role</th><th>Status</th></tr>';
foreach ($results as $r) {
    echo '<tr><td>' . htmlspecialchars($r['email']) . '</td>';
    echo '<td>' . htmlspecialchars($r['role'] ?? '-') . '</td>';
    echo '<td>' . htmlspecialchars($r['status']) . '</td></tr>';
}
echo '</table>';
echo '<p style="color:red;font-weight:bold;margin-top:24px">⚠️ DELETE this seed.php file from your server now!</p>';
echo '</body></html>';
