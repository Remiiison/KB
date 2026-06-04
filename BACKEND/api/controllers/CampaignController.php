<?php
class CampaignController {

    /* Resolve the NGO id that the current session's ngo_admin owns.
       Returns null for superadmin / admin (no restriction). */
    private function sessionNgoId(): ?int {
        session_start_once();
        if (empty($_SESSION['user_id'])) return null;
        $stmt = Database::getInstance()->prepare('SELECT role FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $u = $stmt->fetch();
        if (!$u || $u['role'] !== 'ngo_admin') return null;
        return get_user_ngo_id((int)$_SESSION['user_id']);
    }

    /* Abort with 403 if the campaign does not belong to the given NGO. */
    private function assertOwns(int $campaignId, int $ngoId): void {
        $stmt = Database::getInstance()->prepare(
            'SELECT ngo_id FROM campaigns WHERE campaign_id = ? LIMIT 1'
        );
        $stmt->execute([$campaignId]);
        $row = $stmt->fetch();
        if (!$row) json_error('Campaign not found.', 404);
        if ((int)$row['ngo_id'] !== $ngoId) json_error('Forbidden.', 403);
    }

    /* ── list GET /campaigns ── */
    public function list(): void {
        $status   = $_GET['status']   ?? null;
        $category = $_GET['category'] ?? null;
        $search   = $_GET['search']   ?? null;
        $ngoId    = $_GET['ngoId']    ?? null;
        $limit    = min((int)($_GET['limit']  ?? 50), 100);
        $offset   = (int)($_GET['offset'] ?? 0);

        // RBAC: ngo_admin is silently restricted to their own NGO
        $myNgoId = $this->sessionNgoId();
        if ($myNgoId !== null) {
            $ngoId = $myNgoId;
        }

        $where  = [];
        $params = [];

        if ($status)   { $where[] = 'c.status = ?';             $params[] = $status; }
        if ($category) { $where[] = 'c.category = ?';           $params[] = $category; }
        if ($search)   { $where[] = '(c.title LIKE ? OR c.description LIKE ?)';
                         $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($ngoId)    { $where[] = 'c.ngo_id = ?';             $params[] = (int)$ngoId; }

        $sql = 'SELECT c.*, n.ngo_name FROM campaigns c
                LEFT JOIN ngos n ON c.ngo_id = n.ngo_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY c.created_at DESC LIMIT ? OFFSET ?';

        $params[] = $limit;
        $params[] = $offset;

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        json_ok(['campaigns' => array_map([$this, 'map'], $stmt->fetchAll())]);
    }

    /* ── getById GET /campaigns/:id ── */
    public function getById(string $id): void {
        $stmt = Database::getInstance()->prepare(
            'SELECT c.*, n.ngo_name FROM campaigns c
             LEFT JOIN ngos n ON c.ngo_id = n.ngo_id
             WHERE c.campaign_id = ? LIMIT 1'
        );
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();
        if (!$row) json_error('Campaign not found.', 404);

        // ngo_admin may only view campaigns belonging to their NGO
        $myNgoId = $this->sessionNgoId();
        if ($myNgoId !== null && (int)$row['ngo_id'] !== $myNgoId) {
            json_error('Forbidden.', 403);
        }

        json_ok(['campaign' => $this->map($row)]);
    }

    /* ── create POST /campaigns ── */
    public function create(): void {
        $user = require_auth();
        require_role(['ngo_admin', 'ngo', 'admin', 'superadmin'], $user);
        $body = get_body();

        $title        = trim($body['title']        ?? '');
        $description  = trim($body['description']  ?? '');
        $category     = trim($body['category']     ?? '');
        $targetAmount = (float)($body['targetAmount'] ?? 0);
        $imageUrl     = $body['imageUrl'] ?? null;

        if (!$title || !$description || !$category || $targetAmount <= 0) {
            json_error('Missing required fields or invalid amount.');
        }

        $db = Database::getInstance();

        // Resolve ngo_id — ngo_admin is always locked to their own NGO
        $myNgoId = get_user_ngo_id((int)$user['user_id']);
        if ($user['role'] === 'ngo_admin') {
            if (!$myNgoId) json_error('NGO profile not found for your account.', 404);
            $ngoId = $myNgoId;
        } else {
            $ngoId = (int)($body['ngoId'] ?? 0);
            if (!$ngoId && $myNgoId) $ngoId = $myNgoId;
            if (!$ngoId) json_error('NGO ID is required.', 400);
        }

        $db->prepare(
            "INSERT INTO campaigns (title, description, category, target_amount, ngo_id, image_url, status)
             VALUES (?, ?, ?, ?, ?, ?, 'draft')"
        )->execute([$title, $description, $category, $targetAmount, $ngoId, $imageUrl]);

        $newId = (int)$db->lastInsertId();
        log_activity($user['user_id'], 'campaign', $newId, 'create');

        $stmt = $db->prepare(
            'SELECT c.*, n.ngo_name FROM campaigns c LEFT JOIN ngos n ON c.ngo_id = n.ngo_id WHERE c.campaign_id = ?'
        );
        $stmt->execute([$newId]);
        json_ok(['message' => 'Campaign created.', 'campaign' => $this->map($stmt->fetch())], 201);
    }

    /* ── update PUT /campaigns/:id ── */
    public function update(string $id): void {
        $user = require_auth();
        require_role(['ngo_admin', 'ngo', 'admin', 'superadmin'], $user);

        // ngo_admin may only edit their own NGO's campaigns
        $myNgoId = $this->sessionNgoId();
        if ($myNgoId !== null) $this->assertOwns((int)$id, $myNgoId);

        $body = get_body();
        $db   = Database::getInstance();

        $updates = [];
        $params  = [];
        if (isset($body['title']))        { $updates[] = 'title = ?';         $params[] = trim($body['title']); }
        if (isset($body['description']))  { $updates[] = 'description = ?';   $params[] = trim($body['description']); }
        if (isset($body['category']))     { $updates[] = 'category = ?';      $params[] = $body['category']; }
        if (isset($body['targetAmount'])) { $updates[] = 'target_amount = ?'; $params[] = (float)$body['targetAmount']; }
        if (isset($body['imageUrl']))     { $updates[] = 'image_url = ?';     $params[] = $body['imageUrl']; }

        if ($updates) {
            $params[] = (int)$id;
            $db->prepare('UPDATE campaigns SET ' . implode(', ', $updates) . ' WHERE campaign_id = ?')->execute($params);
        }

        log_activity($user['user_id'], 'campaign', $id, 'update');
        $stmt = $db->prepare(
            'SELECT c.*, n.ngo_name FROM campaigns c LEFT JOIN ngos n ON c.ngo_id = n.ngo_id WHERE c.campaign_id = ?'
        );
        $stmt->execute([(int)$id]);
        json_ok(['message' => 'Campaign updated.', 'campaign' => $this->map($stmt->fetch())]);
    }

    /* ── delete DELETE /campaigns/:id ── */
    public function delete(string $id): void {
        $user = require_auth();
        require_role(['admin', 'superadmin'], $user);
        Database::getInstance()->prepare('DELETE FROM campaigns WHERE campaign_id = ?')->execute([(int)$id]);
        log_activity($user['user_id'], 'campaign', $id, 'delete');
        json_ok(['message' => 'Campaign deleted.']);
    }

    /* ── submit POST /campaigns/:id/submit ── */
    public function submit(string $id): void {
        $user = require_auth();
        $db   = Database::getInstance();

        $stmt = $db->prepare('SELECT ngo_id, title FROM campaigns WHERE campaign_id = ? LIMIT 1');
        $stmt->execute([(int)$id]);
        $campaign = $stmt->fetch();
        if (!$campaign) json_error('Campaign not found.', 404);

        // ngo_admin may only submit their own NGO's campaigns
        $myNgoId = $this->sessionNgoId();
        if ($myNgoId !== null && (int)$campaign['ngo_id'] !== $myNgoId) {
            json_error('Forbidden.', 403);
        }

        $db->prepare("UPDATE campaigns SET status = 'pending' WHERE campaign_id = ?")->execute([(int)$id]);
        log_activity($user['user_id'], 'campaign', $id, 'submit');

        $ns = $db->prepare('SELECT ngo_name FROM ngos WHERE ngo_id = ? LIMIT 1');
        $ns->execute([$campaign['ngo_id']]);
        $ngoRow = $ns->fetch();
        (new EmailService())->sendNewSubmissionNotification(
            $campaign['title'],
            $ngoRow['ngo_name'] ?? 'Unknown NGO'
        );

        json_ok(['message' => 'Campaign submitted for approval.']);
    }

    /* ── approve POST /campaigns/:id/approve ── */
    public function approve(string $id): void {
        $user = require_auth();
        require_role(['admin', 'superadmin'], $user);
        Database::getInstance()->prepare("UPDATE campaigns SET status = 'active' WHERE campaign_id = ?")->execute([(int)$id]);
        log_activity($user['user_id'], 'campaign', $id, 'approve');
        json_ok(['message' => 'Campaign approved.']);
    }

    /* ── reject POST /campaigns/:id/reject ── */
    public function reject(string $id): void {
        $user   = require_auth();
        require_role(['admin', 'superadmin'], $user);
        $reason = get_body()['reason'] ?? null;
        $db     = Database::getInstance();

        $stmt = $db->prepare(
            'SELECT c.title, u.email, u.first_name, u.last_name
             FROM campaigns c
             JOIN ngos n ON c.ngo_id = n.ngo_id
             JOIN users u ON n.user_id = u.user_id
             WHERE c.campaign_id = ? LIMIT 1'
        );
        $stmt->execute([(int)$id]);
        $info = $stmt->fetch();

        $db->prepare("UPDATE campaigns SET status = 'cancelled' WHERE campaign_id = ?")->execute([(int)$id]);
        log_activity($user['user_id'], 'campaign', $id, 'reject');

        if ($info) {
            (new EmailService())->sendCampaignRejectionEmail(
                $info['email'],
                trim($info['first_name'] . ' ' . $info['last_name']),
                $info['title'],
                $reason
            );
        }

        json_ok(['message' => 'Campaign rejected.']);
    }

    /* ── getLikes GET /campaigns/:id/likes ── */
    public function getLikes(string $id): void {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT COUNT(*) AS total FROM campaign_likes WHERE campaign_id = ?');
        $stmt->execute([(int)$id]);
        $total = (int)$stmt->fetch()['total'];

        $liked = false;
        session_start_once();
        if (!empty($_SESSION['user_id'])) {
            $s = $db->prepare('SELECT like_id FROM campaign_likes WHERE campaign_id = ? AND user_id = ? LIMIT 1');
            $s->execute([(int)$id, $_SESSION['user_id']]);
            $liked = (bool)$s->fetch();
        }
        json_ok(['likes' => $total, 'liked' => $liked]);
    }

    /* ── toggleLike POST /campaigns/:id/like ── */
    public function toggleLike(string $id): void {
        $user = require_auth();
        $db   = Database::getInstance();
        $s    = $db->prepare('SELECT like_id FROM campaign_likes WHERE campaign_id = ? AND user_id = ? LIMIT 1');
        $s->execute([(int)$id, $user['user_id']]);

        if ($s->fetch()) {
            $db->prepare('DELETE FROM campaign_likes WHERE campaign_id = ? AND user_id = ?')
               ->execute([(int)$id, $user['user_id']]);
            $liked = false;
        } else {
            $db->prepare('INSERT INTO campaign_likes (campaign_id, user_id) VALUES (?, ?)')
               ->execute([(int)$id, $user['user_id']]);
            $liked = true;
        }

        $ct = $db->prepare('SELECT COUNT(*) AS total FROM campaign_likes WHERE campaign_id = ?');
        $ct->execute([(int)$id]);
        json_ok(['liked' => $liked, 'likes' => (int)$ct->fetch()['total']]);
    }

    /* ── getComments GET /campaigns/:id/comments ── */
    public function getComments(string $id): void {
        $limit  = min((int)($_GET['limit']  ?? 20), 100);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = Database::getInstance()->prepare(
            'SELECT cc.comment_id, cc.text, cc.user_id, cc.created_at, u.first_name, u.last_name
             FROM campaign_comments cc
             JOIN users u ON cc.user_id = u.user_id
             WHERE cc.campaign_id = ?
             ORDER BY cc.created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([(int)$id, $limit, $offset]);

        $comments = array_map(fn($r) => [
            'id'        => (string)$r['comment_id'],
            'text'      => $r['text'],
            'userId'    => (string)$r['user_id'],
            'userName'  => trim($r['first_name'] . ' ' . $r['last_name']),
            'createdAt' => $r['created_at'],
        ], $stmt->fetchAll());

        json_ok(['comments' => $comments]);
    }

    /* ── addComment POST /campaigns/:id/comments ── */
    public function addComment(string $id): void {
        $user = require_auth();
        $text = trim(get_body()['text'] ?? '');
        if (!$text) json_error('Comment text is required.');

        $db = Database::getInstance();
        $db->prepare('INSERT INTO campaign_comments (campaign_id, user_id, text) VALUES (?, ?, ?)')
           ->execute([(int)$id, $user['user_id'], $text]);
        $cid = (int)$db->lastInsertId();

        $stmt = $db->prepare(
            'SELECT cc.comment_id, cc.text, cc.user_id, cc.created_at, u.first_name, u.last_name
             FROM campaign_comments cc JOIN users u ON cc.user_id = u.user_id WHERE cc.comment_id = ?'
        );
        $stmt->execute([$cid]);
        $r = $stmt->fetch();

        json_ok(['message' => 'Comment added.', 'comment' => [
            'id'        => (string)$r['comment_id'],
            'text'      => $r['text'],
            'userId'    => (string)$r['user_id'],
            'userName'  => trim($r['first_name'] . ' ' . $r['last_name']),
            'createdAt' => $r['created_at'],
        ]], 201);
    }

    private function map(array $row): array {
        return [
            'id'            => (string)$row['campaign_id'],
            'title'         => $row['title'],
            'description'   => $row['description'],
            'category'      => $row['category']       ?? null,
            'targetAmount'  => (float)$row['target_amount'],
            'currentAmount' => (float)$row['current_amount'],
            'startDate'     => $row['start_date']     ?? null,
            'endDate'       => $row['end_date']        ?? null,
            'status'        => $row['status'],
            'ngoId'         => (string)$row['ngo_id'],
            'imageUrl'      => $row['image_url']       ?? null,
            'createdAt'     => $row['created_at']      ?? null,
            'ngoName'       => $row['ngo_name']        ?? null,
        ];
    }
}
