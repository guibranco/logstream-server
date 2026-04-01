-- LogService – client registry
-- Run: mysql -u <user> -p <dbname> < migrations/002_clients.sql
--
-- Each row represents one registered application that is allowed to
-- write log entries to the service (STORAGE_TYPE=mariadb only).
--
-- Authentication flow:
--   Client sends:  X-Api-Key: <app_key>   X-Api-Token: <api_token>
--   Server checks: SELECT api_token FROM clients WHERE app_key = ? AND active = 1
--   Server verifies: hash_equals(stored_token, supplied_token)

USE logservice;

CREATE TABLE IF NOT EXISTS clients (
    -- Internal auto-increment identifier
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,

    -- Human-readable name for the application (display only)
    name        VARCHAR(255)  NOT NULL,

    -- The key the client sends in X-Api-Key (must be unique)
    app_key     VARCHAR(100)  NOT NULL,

    -- The secret token the client sends in X-Api-Token
    -- Store a strong random value (openssl rand -base64 32)
    api_token   VARCHAR(255)  NOT NULL,

    -- Set to 0 to revoke access without deleting the row
    active      TINYINT(1)    NOT NULL DEFAULT 1,

    -- Audit timestamps
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY  uq_app_key (app_key),
    INDEX       idx_active  (active)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ── Example seed data (remove or replace before production use) ───────────────
-- INSERT INTO clients (name, app_key, api_token) VALUES
--   ('Billing API',     'billing-api',     'REPLACE_WITH_SECURE_TOKEN'),
--   ('Auth Service',    'auth-service',    'REPLACE_WITH_SECURE_TOKEN'),
--   ('Worker Jobs',     'worker-jobs',     'REPLACE_WITH_SECURE_TOKEN');
