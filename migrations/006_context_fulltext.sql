-- Full-text search across the structured `context` document (context_search).
--
-- 1. Collation: MariaDB's JSON type is LONGTEXT with utf8mb4_bin, which makes a
--    FULLTEXT index case-sensitive ("card" would not find "Card"). The column
--    keeps its json_valid() CHECK, so MariaDB still treats it as JSON, and
--    context[...] equality stays exact because JSON_EXTRACT/JSON_UNQUOTE
--    return binary-collated strings regardless of the column collation.
--
-- 2. The index sits directly on the column, so no copy of the data is stored.
--    A VIRTUAL generated column is not an option: InnoDB cannot build a
--    FULLTEXT index on one (error 1907).
--
-- Words shorter than innodb_ft_min_token_size (default 3) are not indexed,
-- which is why the API rejects shorter search terms.
--
-- Both changes run in a single table rebuild; on large tables run it off-peak.
ALTER TABLE log_entries
    MODIFY COLUMN context LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
        CHECK (json_valid(context)),
    ADD FULLTEXT INDEX IF NOT EXISTS ft_context (context);
