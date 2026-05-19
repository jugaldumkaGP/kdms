-- =============================================================================
-- Phase 1a — FINISH ONLY (column Devotee_ID_Unique_Key already exists)
--
-- Use when:
--   • SECTION 1–2 of Phase_1a_production_complete.sql already ran
--   • Error 1060 Duplicate column 'Devotee_ID_Unique_Key' on ADD COLUMN
--   • SECTION 4 auto-resolve done and SECTION 5 duplicate check returns 0 rows
-- =============================================================================

-- Must return ZERO rows:
SELECT
    Devotee_ID_Unique_Key,
    COUNT(*) AS duplicate_count
FROM devotee
WHERE Devotee_ID_Unique_Key IS NOT NULL
GROUP BY Devotee_ID_Unique_Key
HAVING duplicate_count > 1;

-- Skip if index already exists (Error 1061 duplicate key name = already done):
CREATE UNIQUE INDEX idx_devotee_id_unique_key ON devotee (Devotee_ID_Unique_Key);

SHOW COLUMNS FROM devotee LIKE 'Devotee_ID_Unique_Key';
SHOW INDEX FROM devotee WHERE Key_name = 'idx_devotee_id_unique_key';
