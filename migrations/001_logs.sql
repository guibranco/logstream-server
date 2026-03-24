-- LogService – initial schema
-- Run: mysql -u <user> -p <dbname> < migrations/001_logs.sql

CREATE DATABASE IF NOT EXISTS logservice
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE logservice;

CREATE TABLE IF NOT EXISTS log_entries (
    -- Internal time-sortable primary key (ULID, 26 chars)
    id          VARCHAR(26)   NOT NULL,

    -- Client-supplied trace / correlation ID (UUID v4)
    trace_id    VARCHAR(36)   NOT NULL,

    -- Groups multiple entries belonging to the same request / job
    batch_id    VARCHAR(36)   NULL,

    -- Application slug, e.g. "billing-api"
    app_key     VARCHAR(100)  NOT NULL,

    -- Deployment or environment, e.g. "production" / "worker-3"
    app_id      VARCHAR(100)  NOT NULL,

    -- Free-form UA string: "BillingService/2.1.0 (PHP 8.3; Linux)"
    user_agent  VARCHAR(255)  NULL,

    -- Severity
    level       ENUM('debug','info','notice','warning','error','critical')
                NOT NULL DEFAULT 'info',

    -- Short grouping tag (max 100 chars)
    category    VARCHAR(100)  NOT NULL DEFAULT 'general',

    -- The log message
    message     TEXT          NOT NULL,

    -- Arbitrary structured data (JSON)
    context     JSON          NULL,

    -- When the event occurred (microsecond precision)
    timestamp   DATETIME(6)   NOT NULL,

    -- When the server stored the entry
    created_at  DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),

    -- Retrieval by trace / batch
    INDEX idx_trace_id  (trace_id),
    INDEX idx_batch_id  (batch_id),

    -- Filtering
    INDEX idx_app_key   (app_key),
    INDEX idx_app_id    (app_id),
    INDEX idx_level     (level),
    INDEX idx_category  (category),

    -- Time-range scans
    INDEX idx_timestamp (timestamp),

    -- Composite: the most common query pattern
    INDEX idx_app_time  (app_key, app_id, timestamp DESC)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
