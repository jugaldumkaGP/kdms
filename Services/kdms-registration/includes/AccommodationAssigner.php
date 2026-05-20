<?php

declare(strict_types=1);

namespace KdmsRegistration;

use PDO;

final class AccommodationAssigner
{
    /** Day-visitor / PWA default accommodation (accommodation_master.Accomodation_Key). */
    public const DAY_VISITOR_ACCOM_KEY = 'othr';

    public static function warnIfOtherMissing(PDO $db, string $eventId): void
    {
        if ($eventId === '') {
            return;
        }
        $stmt = $db->prepare(
            'SELECT 1 FROM accommodation_master WHERE Accomodation_Key = :key LIMIT 1'
        );
        $stmt->execute(['key' => self::DAY_VISITOR_ACCOM_KEY]);
        if ($stmt->fetchColumn() === false) {
            kdms_log('WARNING', 'accommodation_master missing day-visitor accommodation key', [
                'Accomodation_Key' => self::DAY_VISITOR_ACCOM_KEY,
                'event' => $eventId,
            ]);
        }
    }

    public static function assignOther(PDO $db, string $devoteeKey, string $eventId): bool
    {
        if ($eventId === '') {
            kdms_log('WARNING', 'ACTIVE_EVENT_ID not configured; skipping accommodation');

            return false;
        }

        $accomKey = self::DAY_VISITOR_ACCOM_KEY;
        $stmt = $db->prepare(
            'SELECT 1 FROM accommodation_master WHERE Accomodation_Key = :key LIMIT 1'
        );
        $stmt->execute(['key' => $accomKey]);
        if ($stmt->fetchColumn() === false) {
            kdms_log('WARNING', 'No day-visitor accommodation in accommodation_master; skipping insert', [
                'Accomodation_Key' => $accomKey,
            ]);

            return false;
        }

        $insert = $db->prepare(
            'INSERT INTO devotee_accomodation (
                Accomodation_Key,
                Devotee_Key,
                Accommodation_Event,
                Arrival_Date_Time,
                Departure_Date_Time,
                Accomodation_Status,
                Devotee_Accomodation_Update_Date_Time,
                Devotee_Accomodation_Updated_By
            ) VALUES (
                :accom_key,
                :devotee_key,
                :event_id,
                NOW(),
                NULL,
                :status,
                NOW(),
                :updated_by
            )'
        );
        $insert->execute([
            'accom_key' => $accomKey,
            'devotee_key' => $devoteeKey,
            'event_id' => $eventId,
            'status' => 'Allocated',
            'updated_by' => 'REG-PWA',
        ]);

        try {
            $upd = $db->prepare(
                'UPDATE accommodation_availability SET
                    Allocated_Count = Allocated_Count + 1,
                    Available_Count = Available_Count - 1
                 WHERE Accomodation_Key = :accom_key AND Accommodation_Event = :event_id'
            );
            $upd->execute(['accom_key' => $accomKey, 'event_id' => $eventId]);
        } catch (\Throwable $e) {
            kdms_log('WARNING', 'accommodation_availability update skipped', ['error' => $e->getMessage()]);
        }

        return true;
    }
}
