-- =============================================================================
-- DEPRECATED for new installs — use Phase_1a_unique_index_generated_column.sql
--
-- Raw-column unique index cannot work with legacy placeholders ('Aadhaar' + '', '00', etc.).
-- The generated-column script enforces uniqueness only on valid type|number pairs.
-- =============================================================================

-- Inspect collisions on raw columns (informational only):
SELECT Devotee_ID_Type, Devotee_ID_Number, COUNT(*) AS cnt
FROM devotee
GROUP BY Devotee_ID_Type, Devotee_ID_Number
HAVING cnt > 1
ORDER BY cnt DESC
LIMIT 50;
