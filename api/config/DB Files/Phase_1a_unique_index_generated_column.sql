-- =============================================================================
-- Phase 1a: Unique ID via GENERATED column (run after columns + dedup tables exist)
--
-- Replaces UNIQUE(Devotee_ID_Type, Devotee_ID_Number) on raw columns.
-- Placeholders ('', '-', '00', invalid Aadhaar, etc.) → NULL in Devotee_ID_Unique_Key
-- (no collision). Devotee_ID_Number display values are NOT changed.
--
-- If DROP INDEX fails with "check that it exists", skip that line and continue.
-- If ADD COLUMN fails with "Duplicate column", skip ADD and run SELECT + CREATE INDEX.
-- =============================================================================

ALTER TABLE devotee DROP INDEX idx_devotee_id_type_number;

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

-- True duplicates (same valid ID). If any rows returned → run Phase_1a_resolve_duplicate_ids.sql
-- Prefer Phase_1a_check_duplicate_ids.sql (full report). Quick check:
SELECT Devotee_ID_Unique_Key, COUNT(*) AS duplicate_count
FROM devotee
WHERE Devotee_ID_Unique_Key IS NOT NULL
GROUP BY Devotee_ID_Unique_Key
HAVING duplicate_count > 1;

-- Do NOT create unique index here until the query above returns zero rows.
-- Then run: Phase_1a_create_unique_id_index.sql
--
-- Optional (defer uniqueness until Phase 2 batch dedup): non-unique lookup index only:
-- CREATE INDEX idx_devotee_id_unique_key_lookup ON devotee (Devotee_ID_Unique_Key);
