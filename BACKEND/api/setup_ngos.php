<?php
/*
 * ── KAPITBISIG NGO ADMIN SETUP ──
 * Creates NGO Admin 1, NGO Admin 2, and their linked NGO profiles.
 * Access: https://yourdomain.com/api/setup_ngos.php?key=KB-SETUP-NGOS-2025
 * DELETE THIS FILE after running it.
 */

if (($_GET['key'] ?? '') !== 'KB-SETUP-NGOS-2025') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

header('Content-Type: application/json');

try {
    $db  = Database::getInstance();
    $log = [];

    $accounts = [
        [
            'email'      => 'ngoadmin1@kapitbisig.online',
            'firstName'  => 'NGO1',
            'lastName'   => 'Admin',
            'password'   => 'NGO1@2025',
            'ngoName'    => 'Barangay 105 Health Initiative',
            'ngoDesc'    => 'Healthcare and medical assistance for Barangay 105 residents.',
            'contact'    => 'NGO1 Admin',
        ],
        [
            'email'      => 'ngoadmin2@kapitbisig.online',
            'firstName'  => 'NGO2',
            'lastName'   => 'Admin',
            'password'   => 'NGO2@2025',
            'ngoName'    => 'Barangay 105 Education Foundation',
            'ngoDesc'    => 'Educational support and livelihood programs for Barangay 105 residents.',
            'contact'    => 'NGO2 Admin',
        ],
    ];

    foreach ($accounts as $a) {
        // Check if user already exists
        $check = $db->prepare('SELECT user_id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $check->execute([strtolower($a['email'])]);
        $existing = $check->fetch();

        if ($existing) {
            $userId = (int)$existing['user_id'];
            // Update password to ensure it's correct
            $hash = password_hash($a['password'], PASSWORD_BCRYPT, ['cost' => 10]);
            $db->prepare('UPDATE users SET password_hash = ?, role = ? WHERE user_id = ?')
               ->execute([$hash, 'ngo_admin', $userId]);
            $log[] = "Updated user: {$a['email']}";
        } else {
            $hash = password_hash($a['password'], PASSWORD_BCRYPT, ['cost' => 10]);
            $db->prepare(
                'INSERT INTO users (first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)'
            )->execute([$a['firstName'], $a['lastName'], $a['email'], $hash, 'ngo_admin']);
            $userId = (int)$db->lastInsertId();
            $log[]  = "Created user: {$a['email']}";
        }

        // Check if NGO already exists for this user
        $ngoCheck = $db->prepare('SELECT ngo_id FROM ngos WHERE user_id = ? LIMIT 1');
        $ngoCheck->execute([$userId]);
        $existingNgo = $ngoCheck->fetch();

        if ($existingNgo) {
            $db->prepare(
                'UPDATE ngos SET ngo_name = ?, description = ?, contact_person = ?, verification_status = ? WHERE ngo_id = ?'
            )->execute([$a['ngoName'], $a['ngoDesc'], $a['contact'], 'verified', $existingNgo['ngo_id']]);
            $log[] = "Updated NGO: {$a['ngoName']}";
        } else {
            $db->prepare(
                "INSERT INTO ngos (ngo_name, description, contact_person, user_id, verification_status) VALUES (?, ?, ?, ?, 'verified')"
            )->execute([$a['ngoName'], $a['ngoDesc'], $a['contact'], $userId]);
            $log[] = "Created NGO: {$a['ngoName']}";
        }
    }

    echo json_encode([
        'success' => true,
        'log'     => $log,
        'accounts' => [
            ['email' => 'ngoadmin1@kapitbisig.online', 'password' => 'NGO1@2025', 'code' => 'KBNG1-2025', 'ngo' => 'Barangay 105 Health Initiative'],
            ['email' => 'ngoadmin2@kapitbisig.online', 'password' => 'NGO2@2025', 'code' => 'KBNG2-2025', 'ngo' => 'Barangay 105 Education Foundation'],
        ],
        'next' => 'DELETE this file from the server immediately!',
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
