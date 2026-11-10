-- Seq-style message templates.
--
-- When a message holds placeholders such as "GitHub {event} for {repository}"
-- and the context has those keys, the server stores the rendered text in
-- `message` and the original text here, so every entry logged from the same
-- line can be grouped or filtered by one value. Entries without a template
-- (all existing rows, and messages without placeholders) keep NULL.
--
-- TEXT keeps long templates whole; the index covers the first 255 characters,
-- enough for the exact-match filter (?message_template=) on real templates.
ALTER TABLE log_entries
    ADD COLUMN IF NOT EXISTS message_template TEXT NULL AFTER message,
    ADD INDEX IF NOT EXISTS idx_message_template (message_template(255));
