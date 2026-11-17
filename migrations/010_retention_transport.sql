-- Retention policies can target entries by the transport they arrived over
-- ('tcp' for HTTP ingestion, 'udp' for the UDP listener; see 009_transport.sql).
-- NULL means any transport. Entries with no recorded transport (legacy rows,
-- the server's own logs) never match a policy that sets one.
ALTER TABLE retention_policies
    ADD COLUMN IF NOT EXISTS transport VARCHAR(8) NULL AFTER category;
