-- Diagnose duplicate search rows: multiple devotee_photo / devotee_id per Devotee_Key
-- (caused by merge repoint UPDATE when survivor already had a child row; no UNIQUE on Devotee_Key).

SET @key = 'P16200766';

SELECT 'devotee_photo rows' AS section, Devotee_Key, Devotee_Photo_Gcs_Path,
       CASE WHEN Devotee_Photo IS NULL THEN 'NULL' WHEN OCTET_LENGTH(Devotee_Photo) = 0 THEN 'empty' ELSE CONCAT(OCTET_LENGTH(Devotee_Photo), ' bytes') END AS blob_size
FROM devotee_photo WHERE Devotee_Key = @key;

SELECT 'devotee_id rows' AS section, Devotee_Key, Devotee_ID_Type, Devotee_ID_Image_Gcs_Path,
       CASE WHEN Devotee_ID_Image IS NULL THEN 'NULL' WHEN OCTET_LENGTH(Devotee_ID_Image) = 0 THEN 'empty' ELSE CONCAT(OCTET_LENGTH(Devotee_ID_Image), ' bytes') END AS blob_size
FROM devotee_id WHERE Devotee_Key = @key;

SELECT 'allocated accommodations (all events)' AS section, Accommodation_Event, Accomodation_Key, Accomodation_Status,
       Devotee_Accomodation_Update_Date_Time
FROM devotee_accomodation WHERE Devotee_Key = @key AND Accomodation_Status = 'Allocated'
ORDER BY Accommodation_Event, Devotee_Accomodation_Update_Date_Time DESC;

-- Expected after fix: 1 row each in devotee_photo and devotee_id for @key.
