-- ─────────────────────────────────────────────────────────────
--  KapitBisig · Hostinger-Compatible Schema
--  Import this file via phpMyAdmin on Hostinger.
--  DO NOT use 001_init_schema.sql on Hostinger — use this one.
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Drop tables in safe order
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS campaign_comments;
DROP TABLE IF EXISTS campaign_likes;
DROP TABLE IF EXISTS transparency_reports;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS donations;
DROP TABLE IF EXISTS campaigns;
DROP TABLE IF EXISTS ngos;
DROP TABLE IF EXISTS payment_providers;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ── USERS ────────────────────────────────────────────────────
CREATE TABLE users (
    user_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name      VARCHAR(150)    NOT NULL,
    last_name       VARCHAR(150)    NOT NULL,
    email           VARCHAR(190)    NOT NULL,
    password_hash   VARCHAR(255)    NULL,
    role            ENUM('donor','ngo_admin','admin','superadmin') NOT NULL DEFAULT 'donor',
    date_registered DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── NGOS ─────────────────────────────────────────────────────
CREATE TABLE ngos (
    ngo_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ngo_name            VARCHAR(180)    NOT NULL,
    description         TEXT            NULL,
    contact_person      VARCHAR(150)    NULL,
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    user_id             BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ngo_id),
    KEY idx_ngos_user_id (user_id),
    CONSTRAINT fk_ngos_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CAMPAIGNS ────────────────────────────────────────────────
CREATE TABLE campaigns (
    campaign_id    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    title          VARCHAR(200)     NOT NULL,
    description    TEXT             NULL,
    category       VARCHAR(100)     NULL,
    image_url      VARCHAR(500)     NULL,
    target_amount  DECIMAL(14,2)    NOT NULL,
    current_amount DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
    start_date     DATE             NULL,
    end_date       DATE             NULL,
    status         ENUM('draft','pending','active','completed','cancelled') NOT NULL DEFAULT 'draft',
    ngo_id         BIGINT UNSIGNED  NOT NULL,
    created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id),
    KEY idx_campaigns_ngo_id (ngo_id),
    KEY idx_campaigns_status (status),
    CONSTRAINT fk_campaigns_ngo
        FOREIGN KEY (ngo_id) REFERENCES ngos(ngo_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── DONATIONS ────────────────────────────────────────────────
CREATE TABLE donations (
    donation_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    amount        DECIMAL(14,2)   NOT NULL,
    donation_date DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id       BIGINT UNSIGNED NOT NULL,
    campaign_id   BIGINT UNSIGNED NOT NULL,
    message       VARCHAR(255)    NULL,
    PRIMARY KEY (donation_id),
    KEY idx_donations_user_id (user_id),
    KEY idx_donations_campaign_id (campaign_id),
    CONSTRAINT fk_donations_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_donations_campaign
        FOREIGN KEY (campaign_id) REFERENCES campaigns(campaign_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PAYMENT PROVIDERS ────────────────────────────────────────
CREATE TABLE payment_providers (
    provider_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_name VARCHAR(120)    NOT NULL,
    type          ENUM('ewallet','bank','card','other') NOT NULL DEFAULT 'other',
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (provider_id),
    UNIQUE KEY uq_payment_providers_name (provider_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TRANSACTIONS ─────────────────────────────────────────────
CREATE TABLE transactions (
    transaction_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    amount           DECIMAL(14,2)   NOT NULL,
    transaction_date DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status           ENUM('pending','success','failed','refunded') NOT NULL DEFAULT 'pending',
    donation_id      BIGINT UNSIGNED NOT NULL,
    provider_id      BIGINT UNSIGNED NOT NULL,
    reference_no     VARCHAR(120)    NULL,
    gateway_response TEXT            NULL,
    PRIMARY KEY (transaction_id),
    UNIQUE KEY uq_transactions_reference_no (reference_no),
    KEY idx_transactions_donation_id (donation_id),
    KEY idx_transactions_provider_id (provider_id),
    CONSTRAINT fk_transactions_donation
        FOREIGN KEY (donation_id) REFERENCES donations(donation_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_transactions_provider
        FOREIGN KEY (provider_id) REFERENCES payment_providers(provider_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TRANSPARENCY REPORTS ─────────────────────────────────────
CREATE TABLE transparency_reports (
    report_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    date_generated DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    details        TEXT            NOT NULL,
    transaction_id BIGINT UNSIGNED NULL,
    campaign_id    BIGINT UNSIGNED NULL,
    generated_by   BIGINT UNSIGNED NULL,
    PRIMARY KEY (report_id),
    CONSTRAINT fk_tr_transaction
        FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tr_campaign
        FOREIGN KEY (campaign_id) REFERENCES campaigns(campaign_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tr_user
        FOREIGN KEY (generated_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CAMPAIGN LIKES ───────────────────────────────────────────
CREATE TABLE campaign_likes (
    like_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (like_id),
    UNIQUE KEY uq_campaign_likes (user_id, campaign_id),
    CONSTRAINT fk_likes_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_likes_campaign
        FOREIGN KEY (campaign_id) REFERENCES campaigns(campaign_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CAMPAIGN COMMENTS ────────────────────────────────────────
CREATE TABLE campaign_comments (
    comment_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    text        TEXT            NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY idx_comments_campaign (campaign_id),
    CONSTRAINT fk_comments_campaign
        FOREIGN KEY (campaign_id) REFERENCES campaigns(campaign_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_comments_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PASSWORD RESET TOKENS ────────────────────────────────────
CREATE TABLE password_reset_tokens (
    token_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(64)     NOT NULL,
    expires_at DATETIME        NOT NULL,
    used_at    DATETIME        NULL,
    PRIMARY KEY (token_id),
    KEY idx_prt_token_hash (token_hash),
    CONSTRAINT fk_prt_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ACTIVITY LOGS ────────────────────────────────────────────
CREATE TABLE activity_logs (
    log_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id    BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(60)     NOT NULL,
    entity_id   VARCHAR(40)     NOT NULL,
    action      VARCHAR(60)     NOT NULL,
    timestamp   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_activity_logs_admin_id (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── STARTER DATA ─────────────────────────────────────────────
INSERT INTO payment_providers (provider_name, type) VALUES
    ('GCash',         'ewallet'),
    ('Maya',          'ewallet'),
    ('Bank Transfer', 'bank')
ON DUPLICATE KEY UPDATE provider_name = VALUES(provider_name);
