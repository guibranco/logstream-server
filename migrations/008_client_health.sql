-- Client health monitoring (last-seen tracking).
--
-- last_seen_at          — when the client last had log entries accepted.
--                         Updated by the server on every successful ingestion
--                         (coalesced: at most one UPDATE per client every few
--                         seconds). NULL = registered but never seen.
-- heartbeat_timeout_min — silence threshold in minutes. When set and the client
--                         sends nothing for longer than this, its status turns
--                         "silent" and, if CLIENT_SILENCE_WEBHOOK_URL is set, a
--                         webhook fires. NULL = monitoring disabled.
--
-- Updates to last_seen_at keep updated_at unchanged on purpose: updated_at
-- tracks edits to the client, not traffic from it.
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS last_seen_at          DATETIME     NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS heartbeat_timeout_min INT UNSIGNED NULL DEFAULT NULL;
