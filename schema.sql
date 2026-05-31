-- ============================================================================
-- schema.sql — MMIT Developer Assessment server schema
--
-- Target: MySQL 8.x on Namecheap cPanel (utf8mb4 / utf8mb4_unicode_ci).
-- Run order: this file is idempotent on a fresh database. To re-run on an
-- existing database, drop tables in reverse dependency order:
--     DROP TABLE IF EXISTS api_requests;
--     DROP TABLE IF EXISTS submissions;
--     DROP TABLE IF EXISTS users;
--
-- Naming: snake_case columns, plural table names. All timestamps stored as
-- DATETIME(3) in UTC — the API writes UTC_TIMESTAMP(3); the email body
-- formats to ISO-8601 with a trailing Z so the auditor sees an unambiguous
-- timestamp regardless of cPanel's session timezone.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

-- ----------------------------------------------------------------------------
-- users
-- One row per registered device. device_id is the lowercase 64-char SHA-256
-- hex digest produced by the Electron main process (see electron-main.js:
-- sha256(`<MachineGuid>:<hostname>:mmit-developer-assessment-v1`)).
--
-- We store the OS-level identity (win_username, computer_name) so the
-- submission notification email can name the candidate without the
-- reviewer having to decrypt the ciphertext. weak_fingerprint propagates
-- the renderer's flag for downstream filtering.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    user_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id         CHAR(64)        NOT NULL,
    win_username      VARCHAR(255)    NOT NULL DEFAULT '',
    computer_name     VARCHAR(255)    NOT NULL DEFAULT '',
    app_version       VARCHAR(32)     NOT NULL DEFAULT '',
    weak_fingerprint  TINYINT(1)      NOT NULL DEFAULT 0,
    registered_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    registered_ip     VARCHAR(45)     NOT NULL DEFAULT '',  -- IPv4 or IPv6
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_device_id (device_id),
    KEY ix_users_registered_at (registered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- submissions
-- One row per submitted assessment. The unique index on user_id enforces
-- the "one submission per device" rule at the database level so a race
-- between two concurrent POSTs cannot create duplicates (the second hits
-- a duplicate-key error, which submit.php translates to 409).
--
-- ciphertext is the sodium.crypto_box_seal output, base64-encoded with
-- the original alphabet (+/, padded). At 20 questions plus the device
-- context envelope this lands well under 64 KB in practice; MEDIUMTEXT
-- gives generous headroom (16 MB) without forcing LONGTEXT page-split
-- behavior on InnoDB.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS submissions (
    submission_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NOT NULL,
    ciphertext_b64    MEDIUMTEXT      NOT NULL,
    ciphertext_bytes  INT UNSIGNED    NOT NULL,  -- decoded length, for quick sanity checks
    submitted_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    submitted_ip      VARCHAR(45)     NOT NULL DEFAULT '',
    PRIMARY KEY (submission_id),
    UNIQUE KEY uq_submissions_user_id (user_id),
    KEY ix_submissions_submitted_at (submitted_at),
    CONSTRAINT fk_submissions_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- api_requests
-- Sliding-window rate-limit ledger. We log every authenticated request
-- (after secret-check, before business logic) with enough context to
-- enforce the three concurrent limits:
--   - 30 requests / 5 minutes / IP            (any endpoint)
--   - 5 register / day / device_id            (register.php only)
--   - 5 submit   / day / device_id            (submit.php only)
--
-- Pruning: the bootstrap deletes rows older than 24h opportunistically on
-- ~1 in 50 requests. This keeps the table small without a cron job and
-- avoids a separate maintenance script on shared hosting.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_requests (
    request_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    endpoint          VARCHAR(32)     NOT NULL,  -- 'register' | 'submit' | 'health' | 'other'
    ip_address        VARCHAR(45)     NOT NULL,
    device_id         CHAR(64)        NOT NULL DEFAULT '',  -- '' for endpoints without a device_id
    requested_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    response_status   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (request_id),
    KEY ix_api_requests_ip_time      (ip_address, requested_at),
    KEY ix_api_requests_device_time  (device_id, endpoint, requested_at),
    KEY ix_api_requests_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
