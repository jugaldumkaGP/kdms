-- One-time repair: keep one devotee_photo / devotee_id row per Devotee_Key (merge GCS + blob).
-- Run diagnose_duplicate_child_rows.sql first. Set @key for one devotee, or NULL to preview counts only.

SET @key = 'P16200766';

-- Preview duplicate keys
SELECT 'devotee_photo dup keys' AS what, Devotee_Key, COUNT(*) AS cnt
FROM devotee_photo
WHERE (@key IS NULL OR Devotee_Key = @key)
GROUP BY Devotee_Key HAVING cnt > 1;

SELECT 'devotee_id dup keys' AS what, Devotee_Key, COUNT(*) AS cnt
FROM devotee_id
WHERE (@key IS NULL OR Devotee_Key = @key)
GROUP BY Devotee_Key HAVING cnt > 1;

-- Manual repair for a single key (@key): run via app after kdms-api deploy (admin merge / re-save),
-- or use DeduplicationService.consolidate via a small admin script.
-- Safer: delete extras after backing up — contact DBA for bulk fix.
