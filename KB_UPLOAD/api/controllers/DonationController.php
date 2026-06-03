<?php
class DonationController {

    /* ── create POST /donations ── */
    public function create(): void {
        $user = require_auth();
        $body = get_body();

        $campaignId = (int)($body['campaignId'] ?? 0);
        $amount     = (float)($body['amount']   ?? 0);
        $message    = $body['message']          ?? null;

        if (!$campaignId || $amount <= 0) {
            json_error('Campaign ID and a positive amount are required.');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT campaign_id, status FROM campaigns WHERE campaign_id = ? LIMIT 1");
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch();

        if (!$campaign || $campaign['status'] !== 'active') {
            json_error('Campaign not found or not accepting donations.', 404);
        }

        $db->prepare(
            'INSERT INTO donations (amount, user_id, campaign_id, message) VALUES (?, ?, ?, ?)'
        )->execute([$amount, $user['user_id'], $campaignId, $message]);

        $donationId = (int)$db->lastInsertId();

        // Keep campaign current_amount in sync
        $db->prepare('UPDATE campaigns SET current_amount = current_amount + ? WHERE campaign_id = ?')
           ->execute([$amount, $campaignId]);

        $s = $db->prepare('SELECT * FROM donations WHERE donation_id = ? LIMIT 1');
        $s->execute([$donationId]);
        json_ok(['message' => 'Donation recorded.', 'donation' => $this->map($s->fetch())], 201);
    }

    /* ── getById GET /donations/:id ── */
    public function getById(string $id): void {
        require_auth();
        $stmt = Database::getInstance()->prepare('SELECT * FROM donations WHERE donation_id = ? LIMIT 1');
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();
        if (!$row) json_error('Donation not found.', 404);
        json_ok(['donation' => $this->map($row)]);
    }

    /* ── getMyDonations GET /donations/my-donations ── */
    public function getMyDonations(): void {
        $user   = require_auth();
        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = Database::getInstance()->prepare(
            'SELECT d.*, c.title AS campaign_title
             FROM donations d
             LEFT JOIN campaigns c ON d.campaign_id = c.campaign_id
             WHERE d.user_id = ?
             ORDER BY d.donation_date DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$user['user_id'], $limit, $offset]);
        json_ok(['donations' => array_map([$this, 'map'], $stmt->fetchAll())]);
    }

    /* ── getCampaignDonations GET /donations/campaign/:id/donations ── */
    public function getCampaignDonations(string $campaignId): void {
        $limit  = min((int)($_GET['limit']  ?? 100), 200);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = Database::getInstance()->prepare(
            'SELECT d.*, u.first_name, u.last_name
             FROM donations d
             LEFT JOIN users u ON d.user_id = u.user_id
             WHERE d.campaign_id = ?
             ORDER BY d.donation_date DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([(int)$campaignId, $limit, $offset]);
        json_ok(['donations' => array_map([$this, 'map'], $stmt->fetchAll())]);
    }

    /* ── getCampaignStats GET /donations/campaign/:id/stats ── */
    public function getCampaignStats(string $campaignId): void {
        $db = Database::getInstance();

        $s1 = $db->prepare(
            'SELECT COUNT(*) AS total_donations,
                    COALESCE(SUM(amount), 0) AS total_amount,
                    COALESCE(AVG(amount), 0) AS avg_donation
             FROM donations WHERE campaign_id = ?'
        );
        $s1->execute([(int)$campaignId]);
        $agg = $s1->fetch();

        $s2 = $db->prepare('SELECT target_amount, current_amount FROM campaigns WHERE campaign_id = ? LIMIT 1');
        $s2->execute([(int)$campaignId]);
        $camp = $s2->fetch();

        json_ok(['stats' => [
            'totalDonations' => (int)$agg['total_donations'],
            'totalAmount'    => (float)$agg['total_amount'],
            'avgDonation'    => (float)$agg['avg_donation'],
            'targetAmount'   => $camp ? (float)$camp['target_amount']  : 0,
            'currentAmount'  => $camp ? (float)$camp['current_amount'] : 0,
        ]]);
    }

    private function map(array $row): array {
        return [
            'id'            => (string)$row['donation_id'],
            'amount'        => (float)$row['amount'],
            'donationDate'  => $row['donation_date']   ?? null,
            'userId'        => (string)$row['user_id'],
            'campaignId'    => (string)$row['campaign_id'],
            'message'       => $row['message']         ?? null,
            'campaignTitle' => $row['campaign_title']  ?? null,
        ];
    }
}
