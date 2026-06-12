<?php

declare(strict_types=1);

namespace KdmsRegistration;

use PDO;
use PDOException;

final class RegistrationService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success: bool, Devotee_Key?: string, action?: string, error?: string}
     */
    public function register(array $input): array
    {
        $fields = RegistrationFields::fromRegistrationInput($input);

        if ($fields['first'] === '' || $fields['last'] === '' || $fields['idType'] === '' || $fields['idNumber'] === '') {
            if (($fields['rawIdType'] ?? '') !== '' && $fields['idType'] === '') {
                return ['success' => false, 'error' => 'Please select a valid ID type.'];
            }

            return ['success' => false, 'error' => 'Please fill in all required fields.'];
        }

        $rawEmail = trim(strip_tags((string) ($input['Devotee_Email'] ?? '')));
        if ($rawEmail !== '' && $fields['email'] === '') {
            return ['success' => false, 'error' => 'Please enter a valid email address.'];
        }

        $rawPhone = trim(strip_tags((string) ($input['Devotee_Cell_Phone_Number'] ?? '')));
        if ($rawPhone !== '' && $fields['phone'] === '') {
            return ['success' => false, 'error' => 'Please enter a valid 10-digit phone number.'];
        }

        $candidateKey = strtoupper(trim((string) ($input['Devotee_Key'] ?? '')));
        if ($candidateKey === '') {
            $candidateKey = GenerateId::generate($this->db);
        }

        // Determine status: 'PO' for Prasad Only registrations, 'D' for standard day visitors
        $prasadOnly = !empty($input['prasad_only']) && $input['prasad_only'] !== false && $input['prasad_only'] !== 'false';
        $status = $prasadOnly ? 'PO' : 'D';

        $idPath = $this->sanitizeGcsPath(
            (string) ($input['id_gcs_path'] ?? $input['id_staging_gcs_path'] ?? ''),
            $candidateKey
        );
        $selfiePath = $this->sanitizeGcsPath((string) ($input['selfie_gcs_path'] ?? ''), $candidateKey);
        $eventId = reg_active_event_id();

        $dedup = KdmsApiClient::deduplicate(RegistrationFields::toDedupPayload($candidateKey, $fields, $eventId, $status));
        if (!$dedup['ok']) {
            return [
                'success' => false,
                'error' => 'Registration could not be verified. Please try again or ask for help at the counter.',
            ];
        }

        $survivorKey = $dedup['survivor_key'];
        $action = $dedup['action'];

        if (strcasecmp($candidateKey, $survivorKey) !== 0) {
            if ($idPath !== '') {
                $idPath = RegistrationGcs::relocateToSurvivorKey($idPath, $survivorKey, true);
            }
            if ($selfiePath !== '') {
                $selfiePath = RegistrationGcs::relocateToSurvivorKey($selfiePath, $survivorKey, false);
            }
        }
        $idPath = $this->sanitizeGcsPath($idPath, $survivorKey);
        $selfiePath = $this->sanitizeGcsPath($selfiePath, $survivorKey);

        if ($action === 'merged') {
            try {
                $this->db->beginTransaction();
                $this->saveDevoteeRow($survivorKey, $fields, true, $status);
                $this->attachChildRows($survivorKey, $fields['idType'], $idPath, $selfiePath);
                if ($status !== 'PO' && $eventId !== '' && !$this->hasAccommodationForEvent($survivorKey, $eventId)) {
                    AccommodationAssigner::assignOther($this->db, $survivorKey, $eventId);
                }
                $this->db->commit();
            } catch (PDOException $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                kdms_log('ERROR', 'Registration attach after merge failed', ['error' => $e->getMessage()]);

                return ['success' => false, 'error' => 'Registration could not be completed. Please try again or ask for help at the counter.'];
            }
        } else {
            try {
                $this->db->beginTransaction();
                $this->saveDevoteeRow($survivorKey, $fields, false, $status);
                $this->attachChildRows($survivorKey, $fields['idType'], $idPath, $selfiePath);
                if ($status !== 'PO') {
                    AccommodationAssigner::assignOther($this->db, $survivorKey, $eventId);
                }
                $this->db->commit();
            } catch (PDOException $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                kdms_log('ERROR', 'Registration DB transaction failed', ['error' => $e->getMessage()]);

                return ['success' => false, 'error' => 'Registration could not be completed. Please try again or ask for help at the counter.'];
            }
        }

        if ($eventId !== '') {
            KdmsApiClient::addToPrintQueue($survivorKey, $eventId);
        }

        return [
            'success' => true,
            'Devotee_Key' => $survivorKey,
            'action' => $action,
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    private function saveDevoteeRow(string $key, array $fields, bool $overwrite, string $status = 'D'): void
    {
        if ($overwrite) {
            $this->patchDevoteeRowFromRegistration($key, $fields);

            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO devotee (
                Devotee_Key, Devotee_Type, Devotee_First_Name, Devotee_Last_Name, Devotee_Gender,
                Devotee_DOB, Devotee_ID_Type, Devotee_ID_Number,
                Devotee_Address_1, Devotee_Address_2, Devotee_Station, Devotee_State, Devotee_Zip,
                Devotee_Cell_Phone_Number, Devotee_Email, Devotee_Referral,
                Devotee_Status, Devotee_Record_Update_Date_Time, Devotee_Record_Updated_By
            ) VALUES (
                :key, :type, :first, :last, :gender, :dob, :id_type, :id_number,
                :addr1, :addr2, :station, :state, :zip,
                :phone, :email, :referral, :status, NOW(), :updated_by
            )'
        );
        $params = $this->devoteeBindParams($key, $fields, 'REG-PWA');
        $params['type'] = 'T';
        $params['status'] = $status;
        $stmt->execute($params);
    }

    /**
     * On merge into an existing devotee, update only fields the visitor actually provided.
     * Empty optional PWA fields must not clear phone, address, etc. already on file.
     *
     * @param array<string, string> $fields
     */
    private function patchDevoteeRowFromRegistration(string $key, array $fields): void
    {
        $set = [];
        $params = ['key' => $key, 'updated_by' => 'REG-PWA'];

        $textMap = [
            'first' => 'Devotee_First_Name',
            'last' => 'Devotee_Last_Name',
            'idType' => 'Devotee_ID_Type',
            'idNumber' => 'Devotee_ID_Number',
            'address1' => 'Devotee_Address_1',
            'address2' => 'Devotee_Address_2',
            'station' => 'Devotee_Station',
            'state' => 'Devotee_State',
            'zip' => 'Devotee_Zip',
            'phone' => 'Devotee_Cell_Phone_Number',
            'email' => 'Devotee_Email',
            'referral' => 'Devotee_Referral',
        ];

        foreach ($textMap as $fieldKey => $column) {
            $value = trim((string) ($fields[$fieldKey] ?? ''));
            if ($value === '') {
                continue;
            }
            $set[] = $column . ' = :' . $fieldKey;
            $params[$fieldKey] = $value;
        }

        if (($fields['gender'] ?? '') !== '') {
            $set[] = 'Devotee_Gender = :gender';
            $params['gender'] = $fields['gender'];
        }
        if (($fields['dob'] ?? '') !== '') {
            $set[] = 'Devotee_DOB = :dob';
            $params['dob'] = $fields['dob'];
        }

        if ($set === []) {
            return;
        }

        $set[] = 'Devotee_Record_Update_Date_Time = NOW()';
        $set[] = 'Devotee_Record_Updated_By = :updated_by';

        $sql = 'UPDATE devotee SET ' . implode(', ', $set) . ' WHERE Devotee_Key = :key';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function devoteeBindParams(string $key, array $fields, string $updatedBy): array
    {
        return [
            'key' => $key,
            'first' => $fields['first'],
            'last' => $fields['last'],
            'gender' => $fields['gender'],
            'dob' => $fields['dob'] !== '' ? $fields['dob'] : null,
            'id_type' => $fields['idType'],
            'id_number' => $fields['idNumber'],
            'addr1' => $fields['address1'],
            'addr2' => $fields['address2'],
            'station' => $fields['station'],
            'state' => $fields['state'],
            'zip' => $fields['zip'],
            'phone' => $fields['phone'],
            'email' => $fields['email'],
            'referral' => $fields['referral'],
            'updated_by' => $updatedBy,
        ];
    }

    private function attachChildRows(
        string $devoteeKey,
        string $idType,
        string $idGcsPath,
        string $selfiePath
    ): void {
        if ($idGcsPath !== '') {
            $idStmt = $this->db->prepare(
                'INSERT INTO devotee_id (Devotee_Key, Devotee_ID_Type, Devotee_ID_Image_Gcs_Path)
                 VALUES (:key, :type, :gcs_path)
                 ON DUPLICATE KEY UPDATE
                    Devotee_ID_Image_Gcs_Path = VALUES(Devotee_ID_Image_Gcs_Path),
                    Devotee_ID_Type = VALUES(Devotee_ID_Type)'
            );
            $idStmt->execute([
                'key' => $devoteeKey,
                'type' => $idType,
                'gcs_path' => $idGcsPath,
            ]);
        }

        if ($selfiePath !== '') {
            $photoStmt = $this->db->prepare(
                'INSERT INTO devotee_photo (Devotee_Key, Devotee_Photo_Gcs_Path)
                 VALUES (:key, :gcs_path)
                 ON DUPLICATE KEY UPDATE Devotee_Photo_Gcs_Path = VALUES(Devotee_Photo_Gcs_Path)'
            );
            $photoStmt->execute(['key' => $devoteeKey, 'gcs_path' => $selfiePath]);
        }
    }

    private function hasAccommodationForEvent(string $devoteeKey, string $eventId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM devotee_accomodation
             WHERE Devotee_Key = :key AND Accommodation_Event = :event AND Accomodation_Status = 'Allocated'
             LIMIT 1"
        );
        $stmt->execute(['key' => $devoteeKey, 'event' => $eventId]);

        return (bool) $stmt->fetchColumn();
    }

    private function sanitizeGcsPath(string $value, string $devoteeKey): string
    {
        $value = trim($value);

        return RegistrationGcs::isAllowedPath($value, $devoteeKey) ? $value : '';
    }
}
