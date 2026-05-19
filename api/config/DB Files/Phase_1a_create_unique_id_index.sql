-- =============================================================================
-- Phase 1a: Create UNIQUE index on Devotee_ID_Unique_Key (run last)
--
-- Prerequisites:
--   1) Devotee_ID_Unique_Key column exists
--   2) Phase_1a_resolve_duplicate_ids.sql section 1 returns NO rows
-- =============================================================================

-- Must return zero rows:
SELECT Devotee_ID_Unique_Key, COUNT(*) AS cnt
FROM devotee
WHERE Devotee_ID_Unique_Key IS NOT NULL
GROUP BY Devotee_ID_Unique_Key
HAVING cnt > 1;

CREATE UNIQUE INDEX idx_devotee_id_unique_key ON devotee (Devotee_ID_Unique_Key);
