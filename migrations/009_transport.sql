-- Record how each log entry reached the server: 'tcp' for HTTP ingestion
-- (POST /api/logs, /api/demo/run) and 'udp' for the UDP listener.
-- Existing rows keep NULL: their transport was never recorded. The server's
-- own logs (app_key logstream-server) are generated in-process and stay NULL.
ALTER TABLE log_entries
    ADD COLUMN IF NOT EXISTS transport VARCHAR(8) NULL AFTER sender_ip,
    ADD INDEX IF NOT EXISTS idx_transport (transport);
