-- =============================================================================
-- Phase 1a: GCS photo paths, dedup tables (partial — see combined file for full run)
-- For production/staging full migration use: Phase_1a_production_complete.sql
-- =============================================================================

-- Unique index (step 5 below): normalize placeholders first, or run
-- Phase_1a_normalize_id_before_unique_index.sql if index creation fails with Error 1062.

-- -----------------------------------------------------------------------------
-- Photo GCS path columns (dual-read; BLOB columns unchanged)
-- -----------------------------------------------------------------------------
ALTER TABLE devotee_photo
    ADD COLUMN Devotee_Photo_Gcs_Path VARCHAR(512) NULL;

ALTER TABLE devotee_id
    ADD COLUMN Devotee_ID_Image_Gcs_Path VARCHAR(512) NULL;

-- -----------------------------------------------------------------------------
-- Dedup support tables (Phase 2+)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 5) Unique ID enforcement — run separately (do not use raw-column unique index):
--     Phase_1a_unique_index_generated_column.sql
-- -----------------------------------------------------------------------------
-- Raw UNIQUE(Devotee_ID_Type, Devotee_ID_Number) fails on legacy placeholders ('', '-', '00').
-- Generated column Devotee_ID_Unique_Key maps placeholders to NULL (allowed many times).
