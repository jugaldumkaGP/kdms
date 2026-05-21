-- One-time cleanup: multiple Allocated rows for same Devotee_Key + Accommodation_Event
-- (common after PWA merge repoints TBM accommodation onto an existing survivor).
-- Keeps the row with latest Devotee_Accomodation_Update_Date_Time; sets others to Departed.

-- Preview duplicates:
-- SELECT Devotee_Key, Accommodation_Event, COUNT(*) AS cnt
-- FROM devotee_accomodation
-- WHERE Accomodation_Status = 'Allocated'
-- GROUP BY Devotee_Key, Accommodation_Event
-- HAVING cnt > 1;

UPDATE devotee_accomodation da_old
INNER JOIN (
    SELECT Devotee_Key, Accommodation_Event, MAX(Devotee_Accomodation_Update_Date_Time) AS keep_ts
    FROM devotee_accomodation
    WHERE Accomodation_Status = 'Allocated'
    GROUP BY Devotee_Key, Accommodation_Event
    HAVING COUNT(*) > 1
) dup ON da_old.Devotee_Key = dup.Devotee_Key
    AND da_old.Accommodation_Event = dup.Accommodation_Event
    AND da_old.Accomodation_Status = 'Allocated'
    AND da_old.Devotee_Accomodation_Update_Date_Time < dup.keep_ts
SET
    da_old.Accomodation_Status = 'Departed',
    da_old.Devotee_Accomodation_Update_Date_Time = NOW(),
    da_old.Devotee_Accomodation_Updated_By = 'collapse_dup_script';
