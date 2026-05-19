-- =============================================================================
-- Phase 1a: List duplicate valid IDs (safe syntax for MySQL 8)
-- Do NOT use alias "keys" — reserved word in MySQL 8.0+.
-- =============================================================================

SELECT
    d.Devotee_ID_Unique_Key,
    COUNT(*) AS duplicate_count,
    GROUP_CONCAT(
        d.Devotee_Key
        ORDER BY COALESCE(d.Devotee_Record_Update_Date_Time, '1970-01-01') DESC, d.Devotee_Key DESC
        SEPARATOR ', '
    ) AS devotee_key_list
FROM devotee d
WHERE d.Devotee_ID_Unique_Key IS NOT NULL
GROUP BY d.Devotee_ID_Unique_Key
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC, d.Devotee_ID_Unique_Key;
