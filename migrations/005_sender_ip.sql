-- Capture the IP address of the client that sent each log entry.
-- VARCHAR(45) fits the longest textual IPv6 form (IPv4-mapped).
-- Existing rows keep NULL: their origin was never recorded.
ALTER TABLE log_entries
    ADD COLUMN IF NOT EXISTS sender_ip VARCHAR(45) NULL AFTER user_agent,
    ADD INDEX IF NOT EXISTS idx_sender_ip (sender_ip);
