CREATE TABLE IF NOT EXISTS retention_policies (
    name             VARCHAR(255)  NOT NULL PRIMARY KEY,
    app_key          VARCHAR(255)  NULL,
    app_id           VARCHAR(255)  NULL,
    level            VARCHAR(50)   NULL,
    category         VARCHAR(255)  NULL,
    message_regex    TEXT          NULL,
    message_glob     TEXT          NULL,
    older_than_days  INT UNSIGNED  NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
