<?php
class NgoController {

    /* ── create POST /ngos ── */
    public function create(): void {
        $user = require_auth();
        $body = get_body();

        $ngoName       = trim($body['ngoName']       ?? '');
        $description   = trim($body['description']   ?? '');
        $contactPerson = trim($body['contactPerson'] ?? '');

        if (!$ngoName) json_error('NGO name is required.');

        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO ngos (ngo_name, description, contact_person, user_id, verification_status)
             VALUES (?, ?, ?, ?, 'pending')"
        )->execute([$ngoName, $description, $contactPerson, $user['user_id']]);

        $ngoId = (int)$db->lastInsertId();
        $stmt  = $db->prepare('SELECT * FROM ngos WHERE ngo_id = ? LIMIT 1');
        $stmt->execute([$ngoId]);
        json_ok(['message' => 'NGO profile created.', 'ngo' => $this->map($stmt->fetch())], 201);
    }

    /* ── list GET /ngos ── */
    public function list(): void {
        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM ngos ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        json_ok(['ngos' => array_map([$this, 'map'], $stmt->fetchAll())]);
    }

    /* ── getVerified GET /ngos/verified ── */
    public function getVerified(): void {
        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM ngos WHERE verification_status = 'verified' ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        json_ok(['ngos' => array_map([$this, 'map'], $stmt->fetchAll())]);
    }

    /* ── getById GET /ngos/:id ── */
    public function getById(string $id): void {
        $stmt = Database::getInstance()->prepare('SELECT * FROM ngos WHERE ngo_id = ? LIMIT 1');
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();
        if (!$row) json_error('NGO not found.', 404);
        json_ok(['ngo' => $this->map($row)]);
    }

    /* ── getMyProfile GET /ngos/my-profile ── */
    public function getMyProfile(): void {
        $user = require_auth();
        $stmt = Database::getInstance()->prepare('SELECT * FROM ngos WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['user_id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('NGO profile not found.', 404);
        json_ok(['ngo' => $this->map($row)]);
    }

    /* ── update PUT /ngos/:id ── */
    public function update(string $id): void {
        $user = require_auth();
        require_role(['ngo_admin', 'ngo', 'admin', 'superadmin'], $user);

        // ngo_admin may only update their own NGO
        if ($user['role'] === 'ngo_admin') {
            $myNgoId = get_user_ngo_id((int)$user['user_id']);
            if (!$myNgoId || $myNgoId !== (int)$id) json_error('Forbidden.', 403);
        }

        $body    = get_body();
        $updates = [];
        $params  = [];

        if (isset($body['ngoName']))       { $updates[] = 'ngo_name = ?';       $params[] = trim($body['ngoName']); }
        if (isset($body['description']))   { $updates[] = 'description = ?';    $params[] = trim($body['description']); }
        if (isset($body['contactPerson'])) { $updates[] = 'contact_person = ?'; $params[] = trim($body['contactPerson']); }

        if ($updates) {
            $params[] = (int)$id;
            Database::getInstance()
                ->prepare('UPDATE ngos SET ' . implode(', ', $updates) . ' WHERE ngo_id = ?')
                ->execute($params);
        }

        $stmt = Database::getInstance()->prepare('SELECT * FROM ngos WHERE ngo_id = ? LIMIT 1');
        $stmt->execute([(int)$id]);
        json_ok(['message' => 'NGO updated.', 'ngo' => $this->map($stmt->fetch())]);
    }

    /* ── delete DELETE /ngos/:id ── */
    public function delete(string $id): void {
        $user = require_auth();
        require_role(['admin', 'superadmin'], $user);
        Database::getInstance()->prepare('DELETE FROM ngos WHERE ngo_id = ?')->execute([(int)$id]);
        log_activity($user['user_id'], 'ngo', $id, 'delete');
        json_ok(['message' => 'NGO deleted.']);
    }

    /* ── getPendingVerifications GET /ngos/verification/pending ── */
    public function getPendingVerifications(): void {
        $user   = require_auth();
        require_role(['admin', 'superadmin'], $user);
        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM ngos WHERE verification_status = 'pending' ORDER BY created_at ASC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        json_ok(['ngos' => array_map([$this, 'map'], $stmt->fetchAll())]);
    }

    /* ── getAnalytics GET /ngos/:id/analytics ── */
    public function getAnalytics(string $id): void {
        $db = Database::getInstance();

        $s1 = $db->prepare('SELECT COUNT(*) AS total FROM campaigns WHERE ngo_id = ?');
        $s1->execute([(int)$id]);

        $s2 = $db->prepare("SELECT COUNT(*) AS active FROM campaigns WHERE ngo_id = ? AND status = 'active'");
        $s2->execute([(int)$id]);

        $s3 = $db->prepare(
            'SELECT COALESCE(SUM(d.amount), 0) AS raised, COUNT(d.donation_id) AS donors
             FROM donations d JOIN campaigns c ON d.campaign_id = c.campaign_id WHERE c.ngo_id = ?'
        );
        $s3->execute([(int)$id]);
        $agg = $s3->fetch();

        json_ok(['analytics' => [
            'totalCampaigns'  => (int)$s1->fetch()['total'],
            'activeCampaigns' => (int)$s2->fetch()['active'],
            'totalRaised'     => (float)$agg['raised'],
            'totalDonors'     => (int)$agg['donors'],
        ]]);
    }

    /* ── verify POST /ngos/:id/verify ── */
    public function verify(string $id): void {
        $user = require_auth();
        require_role(['admin', 'superadmin'], $user);
        Database::getInstance()
            ->prepare("UPDATE ngos SET verification_status = 'verified' WHERE ngo_id = ?")
            ->execute([(int)$id]);
        log_activity($user['user_id'], 'ngo', $id, 'verify');
        json_ok(['message' => 'NGO verified.']);
    }

    /* ── reject POST /ngos/:id/reject ── */
    public function reject(string $id): void {
        $user = require_auth();
        require_role(['admin', 'superadmin'], $user);
        Database::getInstance()
            ->prepare("UPDATE ngos SET verification_status = 'rejected' WHERE ngo_id = ?")
            ->execute([(int)$id]);
        log_activity($user['user_id'], 'ngo', $id, 'reject');
        json_ok(['message' => 'NGO rejected.']);
    }

    private function map(array $row): array {
        return [
            'id'                 => (string)$row['ngo_id'],
            'ngoName'            => $row['ngo_name'],
            'description'        => $row['description']        ?? null,
            'contactPerson'      => $row['contact_person']     ?? null,
            'verificationStatus' => $row['verification_status'],
            'userId'             => (string)$row['user_id'],
            'createdAt'          => $row['created_at']         ?? null,
        ];
    }
}
