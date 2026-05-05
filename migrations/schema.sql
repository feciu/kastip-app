-- KasTip — initial schema (rev. 2026-05-05, no-fee MVP)
-- Deploy: mysql -u kastip -p kastip < migrations/schema.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ============================================================
-- users — twórcy + tipperzy (jedno konto)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    x_user_id VARCHAR(64) UNIQUE NOT NULL,        -- numeryczny ID z X
    x_username VARCHAR(64) UNIQUE NOT NULL,       -- @handle (lowercase)
    x_display_name VARCHAR(128),
    x_avatar_url VARCHAR(512),
    kaspa_address VARCHAR(80) NOT NULL,
    auto_reply_enabled TINYINT(1) NOT NULL DEFAULT 1,
    total_received_kas DECIMAL(20,8) NOT NULL DEFAULT 0,
    total_sent_kas DECIMAL(20,8) NOT NULL DEFAULT 0,
    tip_count_received INT NOT NULL DEFAULT 0,
    tip_count_sent INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (x_username),
    INDEX idx_kaspa_addr (kaspa_address)
) ENGINE=InnoDB;

-- ============================================================
-- tips — pojedyncze transakcje
-- ============================================================
CREATE TABLE IF NOT EXISTS tips (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    sender_user_id BIGINT NOT NULL,
    sender_kaspa_address VARCHAR(80) NOT NULL,
    receiver_x_username VARCHAR(64) NOT NULL,
    receiver_user_id BIGINT NULL,                 -- NULL jeśli niezarejestrowany (invitation flow)
    receiver_kaspa_address VARCHAR(80) NULL,
    amount_kas DECIMAL(20,8) NOT NULL,
    tweet_url VARCHAR(512),
    message VARCHAR(280),
    payload VARCHAR(128) NULL,                    -- format: kastip:tip:<unix_ts>:<hash> (cookbook §4)
    status ENUM('pending','broadcast','confirmed','failed','unclaimed') NOT NULL DEFAULT 'pending',
    txid VARCHAR(128) NULL,
    auto_reply_prefilled TINYINT(1) NOT NULL DEFAULT 0,  -- pre-fill został zaoferowany; backend nie wie czy user faktycznie posted (no Twitter API)
    initiated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP NULL,
    INDEX idx_sender (sender_user_id),
    INDEX idx_sender_initiated (sender_user_id, initiated_at DESC),  -- paginacja /api/tips/sent
    INDEX idx_receiver_user (receiver_user_id),
    INDEX idx_receiver_handle (receiver_x_username),
    INDEX idx_txid (txid),
    INDEX idx_status (status),
    FOREIGN KEY (sender_user_id) REFERENCES users(id),
    FOREIGN KEY (receiver_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- sessions — auth tokens (cookie dla web app, Bearer dla extension)
-- ============================================================
CREATE TABLE IF NOT EXISTS sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    session_token CHAR(64) UNIQUE NOT NULL,       -- 32-byte random hex (used as both cookie value AND Bearer token)
    client_kind ENUM('web','extension') NOT NULL DEFAULT 'web',
    extension_id VARCHAR(64) NULL,
    expires_at TIMESTAMP NOT NULL,
    last_used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_agent VARCHAR(512),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (session_token),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- oauth_states — PKCE state + code_verifier between /auth/x/start and /callback
-- (krótki TTL ~10 min, garbage-collect via cron lub query expires_at)
-- ============================================================
CREATE TABLE IF NOT EXISTS oauth_states (
    state CHAR(64) PRIMARY KEY,                   -- 32-byte random hex
    code_verifier VARCHAR(128) NOT NULL,          -- PKCE verifier (43-128 chars)
    client_kind ENUM('web','extension') NOT NULL DEFAULT 'web',
    extension_id VARCHAR(64) NULL,
    redirect_after VARCHAR(256) NULL,             -- gdzie redirect po sukcesie
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- ============================================================
-- rate_limits — anty-spam
-- klucz = SHA256(ip + user_id + endpoint), okno = N-min bucket
-- ============================================================
CREATE TABLE IF NOT EXISTS rate_limits (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    key_hash CHAR(64) NOT NULL,
    window_start TIMESTAMP NOT NULL,              -- zaokrąglony do np. minuty (logika w PHP)
    request_count INT NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_key_window (key_hash, window_start),
    INDEX idx_window (window_start)
) ENGINE=InnoDB;

-- ============================================================
-- invitations — viral tracking dla niezarejestrowanych receiverów
-- ============================================================
CREATE TABLE IF NOT EXISTS invitations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invite_token CHAR(64) UNIQUE NOT NULL,        -- 32-byte random hex, slug w URL
    inviter_user_id BIGINT NOT NULL,
    invitee_x_username VARCHAR(64) NOT NULL,      -- @handle którego nie ma w bazie
    intended_amount_kas DECIMAL(20,8),            -- info-only, NIE blokuje funduszy
    tweet_url VARCHAR(512),
    message VARCHAR(280),
    clicked_at TIMESTAMP NULL,
    converted_user_id BIGINT NULL,
    converted_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (invite_token),
    INDEX idx_invitee (invitee_x_username),
    INDEX idx_inviter (inviter_user_id),
    FOREIGN KEY (inviter_user_id) REFERENCES users(id),
    FOREIGN KEY (converted_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
