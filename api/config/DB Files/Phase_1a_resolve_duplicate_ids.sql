-- =============================================================================
-- Phase 1a: Resolve TRUE duplicate IDs (same valid Devotee_ID_Unique_Key)
--
-- Error 1062 on 'AADHAAR|666652808744' means two+ devotees share a real Aadhaar.
-- Run section 1 first. Then either section 2A (auto) or 2B (manual), then section 3.
--
-- Prerequisite: Devotee_ID_Unique_Key column exists (Phase_1a_unique_index_generated_column.sql).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) Report all duplicate valid IDs (or run Phase_1a_check_duplicate_ids.sql)
--     Do NOT use column alias "keys" — reserved in MySQL 8.
-- -----------------------------------------------------------------------------
SELECT
    d.Devotee_ID_Unique_Key,
    COUNT(*) AS duplicate_count,
    GROUP_CONCAT(
        d.Devotee_Key
        ORDER BY COALESCE(d.Devotee_Record_Update_Date_Time, '1970-01-01') DESC, d.Devotee_Key DESC
        SEPARATOR ', '
    ) AS devotee_key_list,
    MAX(d.Devotee_Record_Update_Date_Time) AS latest_update
FROM devotee d
WHERE d.Devotee_ID_Unique_Key IS NOT NULL
GROUP BY d.Devotee_ID_Unique_Key
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC, d.Devotee_ID_Unique_Key;

-- Detail for one key (replace the literal):
-- SELECT Devotee_Key, Devotee_First_Name, Devotee_Last_Name, Devotee_ID_Type, Devotee_ID_Number,
--        Devotee_Record_Update_Date_Time, Devotee_Status, Devotee_Type
-- FROM devotee WHERE Devotee_ID_Unique_Key = 'AADHAAR|666652808744';

-- -----------------------------------------------------------------------------
-- 2A) AUTO-RESOLVE (recommended before unique index) — review section 1 output first
--     Keeps the most recently updated row per ID; clears Devotee_ID_Number on others
--     so they drop out of Devotee_ID_Unique_Key (Phase 2 merge should reconcile properly).
-- -----------------------------------------------------------------------------
/*
UPDATE devotee d
INNER JOIN (
    SELECT
        du.Devotee_ID_Unique_Key,
        SUBSTRING_INDEX(
            GROUP_CONCAT(
                du.Devotee_Key
                ORDER BY COALESCE(du.Devotee_Record_Update_Date_Time, '1970-01-01') DESC, du.Devotee_Key DESC
            ),
            ',',
            1
        ) AS keep_key
    FROM devotee du
    WHERE du.Devotee_ID_Unique_Key IS NOT NULL
    GROUP BY du.Devotee_ID_Unique_Key
    HAVING COUNT(*) > 1
) g ON d.Devotee_ID_Unique_Key = g.Devotee_ID_Unique_Key
   AND d.Devotee_Key <> g.keep_key
SET d.Devotee_ID_Number = NULL;
*/

-- -----------------------------------------------------------------------------
-- 2B) MANUAL — fix specific keys, then re-run section 1 until zero rows
--     Example: clear ID on the older duplicate only
-- -----------------------------------------------------------------------------
-- UPDATE devotee SET Devotee_ID_Number = NULL WHERE Devotee_Key = 'P........';

-- -----------------------------------------------------------------------------
-- 3) After section 1 returns ZERO rows, create the unique index
-- -----------------------------------------------------------------------------
-- CREATE UNIQUE INDEX idx_devotee_id_unique_key ON devotee (Devotee_ID_Unique_Key);
