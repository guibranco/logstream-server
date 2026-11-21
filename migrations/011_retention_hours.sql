-- Retention policies can set a rolling age in hours (older_than_hours: entries
-- timestamped before now − N hours) instead of whole days (older_than_days,
-- cut at midnight). A policy sets at most one of the two.
ALTER TABLE retention_policies
    ADD COLUMN IF NOT EXISTS older_than_hours INT UNSIGNED NULL AFTER older_than_days;
