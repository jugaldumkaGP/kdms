-- =============================================================================
-- KDMS Phase 1a — COMPLETE production migration (single file)
--
-- Run on STAGING first, then production. Take a DB backup before running.
--
-- Includes:
--   • GCS path columns on devotee_photo / devotee_id
--   • devotee_aliases, devotee_merge_archive tables
--   • Generated column Devotee_ID_Unique_Key (valid IDs only; placeholders → NULL)
--   • Auto-resolve ~N duplicate valid ID groups (keeps newest row per ID)
--   • UNIQUE index idx_devotee_id_unique_key
--
-- Re-run / partial failure:
--   • "Duplicate column" on SECTION 1 → skip SECTION 1 lines that already ran
--   • "Duplicate column" on SECTION 2 → skip ADD COLUMN Devotee_ID_Unique_Key
--   • "Can't DROP INDEX" on SECTION 2 → skip DROP lines
--   • "Duplicate key name" on SECTION 5 → index already exists; done
--
-- NOT in this file (deploy via application release):
--   • clsDashboard.php day-visitor count fix (D + T + accommodation Other)
--
-- Supersedes (for new runs): Phase_1a_gcs_and_dedup_tables.sql,
--   Phase_1a_unique_index_generated_column.sql, Phase_1a_check_duplicate_ids.sql,
--   Phase_1a_resolve_duplicate_ids.sql, Phase_1a_create_unique_id_index.sql
-- =============================================================================

-- =============================================================================
-- SECTION 1 — Photo GCS paths + dedup tables
-- =============================================================================

ALTER TABLE devotee_photo
    ADD COLUMN Devotee_Photo_Gcs_Path VARCHAR(512) NULL;

ALTER TABLE devotee_id
    ADD COLUMN Devotee_ID_Image_Gcs_Path VARCHAR(512) NULL;

CREATE TABLE IF NOT EXISTS devotee_aliases (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    Base_Devotee_Key    VARCHAR(10) NOT NULL,
    Alias_Devotee_Key   VARCHAR(10) NULL,
    Alias_ID_Number     VARCHAR(50) NULL,
    Alias_ID_Type       VARCHAR(10) NULL,
    Merge_Source        ENUM('manual', 'auto_definite', 'auto_fuzzy_review', 'batch_dedup') NOT NULL,
    Merge_Score         INT NULL,
    Merged_At           DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_base (Base_Devotee_Key),
    INDEX idx_alias_id (Alias_ID_Number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devotee_merge_archive (
    id                      BIGINT AUTO_INCREMENT PRIMARY KEY,
    Merge_Batch_Id          VARCHAR(36) NOT NULL,
    Archived_Devotee_Key    VARCHAR(10) NOT NULL,
    Base_Devotee_Key        VARCHAR(10) NOT NULL,
    Devotee_Row_Json        JSON NOT NULL,
    Archived_At             DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_batch (Merge_Batch_Id),
    INDEX idx_archived_key (Archived_Devotee_Key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- SECTION 2 — Generated unique-key column (replaces raw-column unique index)
--
-- Error 1060 "Duplicate column Devotee_ID_Unique_Key" = column already exists (OK).
--   → Skip the ADD COLUMN below; continue from SECTION 5, or run:
--     Phase_1a_finish_unique_index_only.sql
-- =============================================================================

-- Old failed attempt on (Devotee_ID_Type, Devotee_ID_Number) — ignore error if missing
ALTER TABLE devotee DROP INDEX idx_devotee_id_type_number;

-- Skip entire ADD COLUMN block if Devotee_ID_Unique_Key already exists:
--   SHOW COLUMNS FROM devotee LIKE 'Devotee_ID_Unique_Key';

ALTER TABLE devotee
ADD COLUMN Devotee_ID_Unique_Key VARCHAR(64) GENERATED ALWAYS AS (
    CASE
        WHEN Devotee_ID_Type IS NULL OR TRIM(Devotee_ID_Type) = '' THEN NULL
        WHEN Devotee_ID_Number IS NULL OR TRIM(Devotee_ID_Number) = '' THEN NULL
        WHEN TRIM(Devotee_ID_Number) IN ('-', 'N/A', 'NA', 'n/a', 'None', 'NONE', 'null', 'NULL') THEN NULL
        WHEN REPLACE(REPLACE(TRIM(Devotee_ID_Number), ' ', ''), '-', '') REGEXP '^0+$' THEN NULL
        WHEN LENGTH(REPLACE(REPLACE(TRIM(Devotee_ID_Number), ' ', ''), '-', '')) < 4 THEN NULL
        WHEN UPPER(TRIM(Devotee_ID_Type)) IN ('AADHAAR', 'AADHAR')
             AND REPLACE(REPLACE(TRIM(Devotee_ID_Number), ' ', ''), '-', '') NOT REGEXP '^[0-9]{12}$' THEN NULL
        ELSE CONCAT(
            UPPER(TRIM(Devotee_ID_Type)),
            '|',
            REPLACE(REPLACE(TRIM(Devotee_ID_Number), ' ', ''), '-', '')
        )
    END
) STORED COMMENT 'Unique key when ID type+number valid; NULL = placeholder/invalid';

-- =============================================================================
-- SECTION 3 — Duplicate report (before fix; expect rows in prod ~160 groups)
--     Do NOT use alias "keys" — reserved word in MySQL 8.
-- =============================================================================

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

-- =============================================================================
-- SECTION 4 — Auto-resolve duplicate valid IDs
--     Per duplicate ID: keeps row with latest Devotee_Record_Update_Date_Time;
--     sets Devotee_ID_Number = NULL on other rows (Phase 2 merge should reconcile).
--     Idempotent: safe to re-run after SECTION 3 returns zero rows (no-op).
-- =============================================================================

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
        ) AS keep_devotee_key
    FROM devotee du
    WHERE du.Devotee_ID_Unique_Key IS NOT NULL
    GROUP BY du.Devotee_ID_Unique_Key
    HAVING COUNT(*) > 1
) g ON d.Devotee_ID_Unique_Key = g.Devotee_ID_Unique_Key
   AND d.Devotee_Key <> g.keep_devotee_key
SET d.Devotee_ID_Number = NULL;

-- =============================================================================
-- SECTION 5 — Verify zero duplicates (MUST return no rows before SECTION 6)
-- =============================================================================

SELECT
    Devotee_ID_Unique_Key,
    COUNT(*) AS duplicate_count
FROM devotee
WHERE Devotee_ID_Unique_Key IS NOT NULL
GROUP BY Devotee_ID_Unique_Key
HAVING duplicate_count > 1;

-- =============================================================================
-- SECTION 6 — Unique index on generated column
--     Error 1061 duplicate key name = index already exists (OK, migration done).
--     Or run Phase_1a_finish_unique_index_only.sql if you skipped here earlier.
-- =============================================================================

CREATE UNIQUE INDEX idx_devotee_id_unique_key ON devotee (Devotee_ID_Unique_Key);

-- =============================================================================
-- SECTION 7 — Done (optional verification)
-- =============================================================================

SHOW COLUMNS FROM devotee LIKE 'Devotee_ID_Unique_Key';
SHOW INDEX FROM devotee WHERE Key_name = 'idx_devotee_id_unique_key';
SHOW TABLES LIKE 'devotee_aliases';
SHOW TABLES LIKE 'devotee_merge_archive';
