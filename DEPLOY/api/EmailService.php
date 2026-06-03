<?php
class EmailService {
    private bool $ready;

    public function __construct() {
        $this->ready = SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== '';
    }

    public function sendCampaignRejectionEmail(
        string $toEmail,
        string $toName,
        string $campaignTitle,
        ?string $reason
    ): void {
        if (!$this->ready) {
            error_log("[email] SMTP not configured — skipping rejection email to $toEmail");
            return;
        }
        $reasonBlock = $reason
            ? "<p style='background:#fff3cd;border-left:4px solid #c9a84c;padding:12px 16px;margin:16px 0;border-radius:4px'><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>"
            : '';

        $this->send(
            $toEmail,
            "Your campaign \"$campaignTitle\" was not approved",
            "<div style='font-family:sans-serif;max-width:560px;margin:0 auto;color:#1a1a2e'>
              <h2 style='color:#d94f4f'>Campaign Not Approved</h2>
              <p>Hi " . htmlspecialchars($toName) . ",</p>
              <p>We regret to inform you that your campaign <strong>\"" . htmlspecialchars($campaignTitle) . "\"</strong> was not approved at this time.</p>
              $reasonBlock
              <p>You may revise your campaign and re-submit it for review. If you have questions, please contact our support team.</p>
              <p style='color:#666;font-size:12px;margin-top:32px'>— The KapitBisig Team</p>
            </div>"
        );
    }

    public function sendNewSubmissionNotification(string $campaignTitle, string $ngoName): void {
        if (!$this->ready || ADMIN_EMAIL === '') {
            error_log("[email] SMTP or ADMIN_EMAIL not configured — skipping submission notification");
            return;
        }
        $this->send(
            ADMIN_EMAIL,
            "New campaign pending review: \"$campaignTitle\"",
            "<div style='font-family:sans-serif;max-width:560px;margin:0 auto;color:#1a1a2e'>
              <h2 style='color:#4a9cc7'>New Campaign Submitted for Review</h2>
              <p><strong>" . htmlspecialchars($ngoName) . "</strong> has submitted a new campaign that requires your approval.</p>
              <p style='background:#f0f7ff;border-left:4px solid #4a9cc7;padding:12px 16px;border-radius:4px'>
                <strong>Campaign:</strong> " . htmlspecialchars($campaignTitle) . "
              </p>
              <p>Log in to the admin dashboard to review and approve or reject this campaign.</p>
              <p style='color:#666;font-size:12px;margin-top:32px'>— The KapitBisig Platform</p>
            </div>"
        );
    }

    public function sendPasswordResetEmail(string $toEmail, string $resetUrl): void {
        if (!$this->ready) {
            error_log("[email] SMTP not configured — password reset link: $resetUrl");
            return;
        }
        $safeUrl = htmlspecialchars($resetUrl);
        $this->send(
            $toEmail,
            'Reset your KapitBisig password',
            "<div style='font-family:sans-serif;max-width:560px;margin:0 auto;color:#1a1a2e'>
              <h2 style='color:#1B2A4A'>Password Reset Request</h2>
              <p>Click the button below to reset your password. This link expires in <strong>1 hour</strong>.</p>
              <p style='margin:24px 0'>
                <a href='$safeUrl'
                   style='background:#5BA4CF;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block'>
                  Reset Password
                </a>
              </p>
              <p style='font-size:12px;color:#666'>Or copy this link:<br>
                <code style='font-size:11px'>$safeUrl</code>
              </p>
              <p style='color:#666;font-size:12px;margin-top:32px'>
                If you did not request a password reset, you can safely ignore this email.
                <br>— The KapitBisig Team
              </p>
            </div>"
        );
    }

    private function send(string $to, string $subject, string $htmlBody): void {
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=utf-8',
            'From: KapitBisig <' . SMTP_FROM . '>',
            'X-Mailer: PHP/' . phpversion(),
        ]);
        if (!mail($to, $subject, $htmlBody, $headers)) {
            error_log("[email] mail() failed for $to — subject: $subject");
        }
    }
}
