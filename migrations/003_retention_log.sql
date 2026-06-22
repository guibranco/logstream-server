CREATE TABLE IF NOT EXISTS retention_log (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    policy_name  VARCHAR(255)     NOT NULL,
    deleted      INT UNSIGNED     NOT NULL DEFAULT 0,
    dry_run      TINYINT(1)       NOT NULL DEFAULT 0,
    duration_ms  INT UNSIGNED     NOT NULL DEFAULT 0,
    summary      TEXT             NULL,
    warnings     JSON             NULL,
    error        TEXT             NULL,
    ran_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_retention_log_policy (policy_name),
    INDEX idx_retention_log_ran_at (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
