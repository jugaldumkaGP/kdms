<?php

declare(strict_types=1);

require_once __DIR__ . '/IdNormalizer.php';

/**
 * Phase 2 deduplication — find, merge (repoint children), archive TBM rows.
 */
final class DeduplicationService
{
    public const MERGE_THRESHOLD = 80;

    private PDO $db;

    private string $eventId;

    private string $updatedBy;

    /** @var list<string> */
    private const CHILD_REPOINT_TABLES = [
        ['table' => 'devotee_accomodation', 'column' => 'Devotee_Key'],
        ['table' => 'devotee_seva', 'column' => 'Devotee_Key'],
        ['table' => 'devotee_photo', 'column' => 'Devotee_Key'],
        ['table' => 'devotee_id', 'column' => 'Devotee_Key'],
        ['table' => 'devotee_remarks', 'column' => 'devotee_key'],
        ['table' => 'devotee_attendance', 'column' => 'devotee_key'],
        ['table' => 'devotee_amenities_allocation', 'column' => 'Devotee_Key'],
        ['table' => 'devotee_demographics', 'column' => 'Devotee_Key'],
        ['table' => 'office_duty', 'column' => 'Devotee_Key'],
        ['table' => 'office_duty_archive', 'column' => 'Devotee_Key'],
        ['table' => 'print_log', 'column' => 'Devotee_Key'],
        ['table' => 'card_print_log', 'column' => 'Devotee_Key'],
        ['table' => 'card_print_archive', 'column' => 'Devotee_Key'],
    ];

    public function __construct(PDO $db, string $eventId = '', string $updatedBy = 'DEDUP-SVC')
    {
        $this->db = $db;
        $this->eventId = trim($eventId);
        $this->updatedBy = $updatedBy;
    }

    /**
     * @param array<string, mixed> $newRecord
     * @return array{
     *   matches: list<array{devotee_key: string, score: int, signal: int, action: string}>,
     *   recommended_action: string,
     *   survivor_key: ?string,
     *   review_base_key: ?string,
     *   review_alias_key: ?string,
     *   merge_score: int
     * }
     */
    public function findDuplicates(array $newRecord): array
    {
        $candidateKey = $this->candidateKey($newRecord);
        $matches = [];
        $bestScore = 0;
        $bestKey = null;
        $reviewAliasKey = null;

        $idType = trim((string) ($newRecord['Devotee_ID_Type'] ?? ''));
        $idNumber = IdNormalizer::normalize($idType, (string) ($newRecord['Devotee_ID_Number'] ?? ''));
        $uniqueKey = IdNormalizer::uniqueKey($idType, $idNumber);

        if ($uniqueKey !== null) {
            $stmt = $this->db->prepare(
                'SELECT Devotee_Key, Devotee_First_Name, Devotee_Last_Name, Devotee_DOB,
                        Devotee_Cell_Phone_Number, Devotee_Station, Devotee_ID_Type, Devotee_ID_Number,
                        Devotee_Record_Update_Date_Time
                 FROM devotee WHERE Devotee_ID_Unique_Key = :uk'
            );
            $stmt->execute(['uk' => $uniqueKey]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = (string) $row['Devotee_Key'];
                if ($candidateKey !== '' && strcasecmp($key, $candidateKey) === 0) {
                    continue;
                }
                $matches[] = [
                    'devotee_key' => $key,
                    'score' => 100,
                    'signal' => 1,
                    'action' => 'merge',
                ];
                if (100 > $bestScore) {
                    $bestScore = 100;
                    $bestKey = $key;
                }
            }
        }

        $first = trim((string) ($newRecord['Devotee_First_Name'] ?? ''));
        $last = trim((string) ($newRecord['Devotee_Last_Name'] ?? ''));
        $dob = $this->normalizeDate((string) ($newRecord['Devotee_DOB'] ?? ''));
        $phone = $this->normalizePhone((string) ($newRecord['Devotee_Cell_Phone_Number'] ?? ''));
        $station = $this->normalizeStation((string) ($newRecord['Devotee_Station'] ?? ''));
        $incomingName = $this->normalizeFullName($first, $last);

        if ($incomingName !== '' && ($dob !== '' || $phone !== '' || $station !== '')) {
            $stmt = $this->db->query(
                'SELECT Devotee_Key, Devotee_First_Name, Devotee_Last_Name, Devotee_DOB,
                        Devotee_Cell_Phone_Number, Devotee_Station, Devotee_ID_Type, Devotee_ID_Number,
                        Devotee_Record_Update_Date_Time
                 FROM devotee
                 WHERE Devotee_Key IS NOT NULL
                 ORDER BY COALESCE(Devotee_Record_Update_Date_Time, \'1970-01-01\') DESC
                 LIMIT 5000'
            );
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = (string) $row['Devotee_Key'];
                if ($candidateKey !== '' && strcasecmp($key, $candidateKey) === 0) {
                    continue;
                }
                if ($this->matchAlreadyListed($matches, $key)) {
                    continue;
                }

                $existingType = trim((string) ($row['Devotee_ID_Type'] ?? ''));
                $existingNumber = IdNormalizer::normalize($existingType, (string) ($row['Devotee_ID_Number'] ?? ''));
                if ($idType !== '' && $idNumber !== '' && $existingType !== '' && $existingNumber !== '') {
                    if (strcasecmp($idType, $existingType) !== 0 && $idNumber !== $existingNumber) {
                        continue;
                    }
                }

                $existingName = $this->normalizeFullName(
                    (string) ($row['Devotee_First_Name'] ?? ''),
                    (string) ($row['Devotee_Last_Name'] ?? '')
                );
                if ($existingName === '' || $this->nameDistance($incomingName, $existingName) > 2) {
                    continue;
                }

                $score = 0;
                $signal = 0;
                $existingDob = $this->normalizeDate((string) ($row['Devotee_DOB'] ?? ''));
                $existingPhone = $this->normalizePhone((string) ($row['Devotee_Cell_Phone_Number'] ?? ''));
                $existingStation = $this->normalizeStation((string) ($row['Devotee_Station'] ?? ''));

                if ($dob !== '' && $existingDob !== '' && $dob === $existingDob) {
                    $score = 90;
                    $signal = 3;
                } elseif ($phone !== '' && $existingPhone !== '' && $phone === $existingPhone) {
                    $score = 80;
                    $signal = 4;
                } elseif ($station !== '' && $existingStation !== '' && $station === $existingStation) {
                    $score = 60;
                    $signal = 5;
                    $reviewAliasKey = $key;
                } else {
                    continue;
                }

                $action = $score >= self::MERGE_THRESHOLD ? 'merge' : 'review';
                $matches[] = [
                    'devotee_key' => $key,
                    'score' => $score,
                    'signal' => $signal,
                    'action' => $action,
                ];
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestKey = $key;
                }
            }
        }

        $recommended = 'new';
        $survivor = null;
        if ($bestScore >= self::MERGE_THRESHOLD && $bestKey !== null) {
            $recommended = 'merge';
            $survivor = $this->pickSurvivorKey($matches, $bestKey);
        } elseif ($bestScore === 60 && $reviewAliasKey !== null) {
            $recommended = 'flagged_new';
            $survivor = $candidateKey !== '' ? $candidateKey : null;
        }

        return [
            'matches' => $matches,
            'recommended_action' => $recommended,
            'survivor_key' => $survivor,
            'review_base_key' => $candidateKey !== '' ? $candidateKey : null,
            'review_alias_key' => $reviewAliasKey,
            'merge_score' => $bestScore,
        ];
    }

    /**
     * Registration mode: dedup only (no devotee INSERT). Admin/full mode can persist.
     *
     * @param array<string, mixed> $newRecord
     * @return array{devotee_key: string, action: string, merge_score: int, alias_count: int}
     */
    public function applyDeduplication(array $newRecord, bool $persistInsert = false): array
    {
        $check = $this->findDuplicates($newRecord);
        $candidateKey = $this->candidateKey($newRecord);
        $action = $check['recommended_action'];
        $mergeScore = (int) $check['merge_score'];
        $aliasCount = 0;

        if ($action === 'merge' && $check['survivor_key'] !== null) {
            $survivor = (string) $check['survivor_key'];
            $tbmKeys = [];
            foreach ($check['matches'] as $m) {
                if ($m['action'] === 'merge' && $m['score'] >= self::MERGE_THRESHOLD) {
                    $k = $m['devotee_key'];
                    if (strcasecmp($k, $survivor) !== 0) {
                        $tbmKeys[] = $k;
                    }
                }
            }
            if ($candidateKey !== '' && $this->devoteeExists($candidateKey)) {
                $tbmKeys[] = $candidateKey;
            }
            $tbmKeys = array_values(array_unique($tbmKeys));

            $survivor = $this->mergeRecords($survivor, $tbmKeys, $newRecord, 'auto_definite', $mergeScore);
            if ($candidateKey !== '' && strcasecmp($candidateKey, $survivor) !== 0 && !$this->devoteeExists($candidateKey)) {
                $this->insertAlias($survivor, $candidateKey, $mergeScore >= 100 ? 'auto_definite' : 'auto_fuzzy_review', $mergeScore);
                $aliasCount++;
            }
            $aliasCount += count($tbmKeys);

            return [
                'devotee_key' => $survivor,
                'action' => 'merged',
                'merge_score' => $mergeScore,
                'alias_count' => $aliasCount,
            ];
        }

        if ($action === 'flagged_new') {
            if ($check['review_alias_key'] !== null && $candidateKey !== '') {
                $this->insertAlias(
                    $candidateKey,
                    (string) $check['review_alias_key'],
                    'auto_fuzzy_review',
                    60,
                    null,
                    null
                );
                $aliasCount = 1;
            }
            if ($persistInsert) {
                $candidateKey = $this->insertDevoteeRow($newRecord, $candidateKey);
            }

            return [
                'devotee_key' => $candidateKey,
                'action' => 'flagged_new',
                'merge_score' => 60,
                'alias_count' => $aliasCount,
            ];
        }

        if ($persistInsert) {
            $candidateKey = $this->insertDevoteeRow($newRecord, $candidateKey);
        }

        return [
            'devotee_key' => $candidateKey,
            'action' => 'inserted',
            'merge_score' => 0,
            'alias_count' => 0,
        ];
    }

    /**
     * @param list<string> $tbmKeys
     * @param array<string, mixed> $newData
     */
    public function mergeRecords(
        string $baseKey,
        array $tbmKeys,
        array $newData,
        string $mergeSource = 'auto_definite',
        int $mergeScore = 100
    ): string {
        $baseKey = strtoupper(trim($baseKey));
        $tbmKeys = array_values(array_filter(array_unique(array_map(
            static fn ($k) => strtoupper(trim((string) $k)),
            $tbmKeys
        )), static fn ($k) => $k !== '' && strcasecmp($k, $baseKey) !== 0));

        if (!$this->devoteeExists($baseKey)) {
            throw new RuntimeException('Survivor devotee not found: ' . $baseKey);
        }

        $batchId = $this->uuid4();

        try {
            $this->db->beginTransaction();

            foreach ($tbmKeys as $tbmKey) {
                if (!$this->devoteeExists($tbmKey)) {
                    continue;
                }
                $this->archiveDevoteeRow($batchId, $baseKey, $tbmKey);
                $this->repointChildRows($baseKey, $tbmKey);
                $this->insertAlias($baseKey, $tbmKey, $mergeSource, $mergeScore);
                $del = $this->db->prepare('DELETE FROM devotee WHERE Devotee_Key = :k');
                $del->execute(['k' => $tbmKey]);
            }

            $this->applyIncomingToSurvivor($baseKey, $newData);
            $this->consolidateSurvivorPhotoAndIdRows($baseKey);
            $this->writeMergeAudit($baseKey, $batchId, $tbmKeys, $mergeSource);

            if ($this->eventId !== '') {
                $this->runRefreshProcs($this->eventId);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $baseKey;
    }

    /**
     * @param list<array{devotee_key: string, score: int, signal: int, action: string}> $matches
     */
    private function pickSurvivorKey(array $matches, string $fallback): string
    {
        $mergeKeys = [];
        foreach ($matches as $m) {
            if ($m['action'] === 'merge' && $m['score'] >= self::MERGE_THRESHOLD) {
                $mergeKeys[] = $m['devotee_key'];
            }
        }
        if ($mergeKeys === []) {
            return $fallback;
        }
        $in = implode(',', array_fill(0, count($mergeKeys), '?'));
        $stmt = $this->db->prepare(
            "SELECT Devotee_Key FROM devotee WHERE Devotee_Key IN ($in)
             ORDER BY COALESCE(Devotee_Record_Update_Date_Time, '1970-01-01') DESC, Devotee_Key DESC
             LIMIT 1"
        );
        $stmt->execute($mergeKeys);
        $key = $stmt->fetchColumn();

        return $key === false ? $fallback : (string) $key;
    }

    private function repointChildRows(string $baseKey, string $tbmKey): void
    {
        $survivorHasPrint = $this->cardPrintLogExists($baseKey);

        foreach (self::CHILD_REPOINT_TABLES as $spec) {
            $table = $spec['table'];
            $col = $spec['column'];

            if ($table === 'card_print_log' && $survivorHasPrint) {
                $del = $this->db->prepare("DELETE FROM card_print_log WHERE {$col} = :tbm");
                $del->execute(['tbm' => $tbmKey]);
                continue;
            }

            if ($table === 'devotee_photo') {
                $this->repointDevoteePhotoRow($baseKey, $tbmKey);
                continue;
            }

            if ($table === 'devotee_id') {
                $this->repointDevoteeIdRow($baseKey, $tbmKey);
                continue;
            }

            if ($table === 'devotee_accomodation') {
                $this->repointDevoteeAccommodationRows($baseKey, $tbmKey);
                continue;
            }

            $sql = "UPDATE {$table} SET {$col} = :base WHERE {$col} = :tbm";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['base' => $baseKey, 'tbm' => $tbmKey]);
        }
    }

    /**
     * devotee_photo has INDEX but not UNIQUE on Devotee_Key — blind UPDATE creates duplicate survivor rows.
     */
    private function repointDevoteePhotoRow(string $baseKey, string $tbmKey): void
    {
        $stmt = $this->db->prepare(
            'SELECT Devotee_Key, Devotee_Photo_Gcs_Path, Devotee_Photo
             FROM devotee_photo WHERE Devotee_Key IN (:base, :tbm)'
        );
        $stmt->execute(['base' => $baseKey, 'tbm' => $tbmKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $survivor = null;
        $tbm = null;
        foreach ($rows as $row) {
            if (strcasecmp((string) $row['Devotee_Key'], $baseKey) === 0) {
                $survivor = $row;
            } else {
                $tbm = $row;
            }
        }
        if ($tbm === null) {
            return;
        }
        if ($survivor === null) {
            $upd = $this->db->prepare('UPDATE devotee_photo SET Devotee_Key = :base WHERE Devotee_Key = :tbm');
            $upd->execute(['base' => $baseKey, 'tbm' => $tbmKey]);

            return;
        }

        $gcs = trim((string) ($tbm['Devotee_Photo_Gcs_Path'] ?? ''));
        if ($gcs === '') {
            $gcs = trim((string) ($survivor['Devotee_Photo_Gcs_Path'] ?? ''));
        }
        $blob = $survivor['Devotee_Photo'] ?? null;
        if ($blob === null || (is_string($blob) && $blob === '')) {
            $blob = $tbm['Devotee_Photo'] ?? null;
        }

        $upd = $this->db->prepare(
            'UPDATE devotee_photo SET Devotee_Photo_Gcs_Path = :gcs, Devotee_Photo = :blob WHERE Devotee_Key = :base LIMIT 1'
        );
        $upd->execute([
            'base' => $baseKey,
            'gcs' => $gcs !== '' ? $gcs : null,
            'blob' => $blob,
        ]);
        $del = $this->db->prepare('DELETE FROM devotee_photo WHERE Devotee_Key = :tbm');
        $del->execute(['tbm' => $tbmKey]);
    }

    private function repointDevoteeIdRow(string $baseKey, string $tbmKey): void
    {
        $stmt = $this->db->prepare(
            'SELECT Devotee_Key, Devotee_ID_Type, Devotee_ID_Image_Gcs_Path, Devotee_ID_Image
             FROM devotee_id WHERE Devotee_Key IN (:base, :tbm)'
        );
        $stmt->execute(['base' => $baseKey, 'tbm' => $tbmKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $survivor = null;
        $tbm = null;
        foreach ($rows as $row) {
            if (strcasecmp((string) $row['Devotee_Key'], $baseKey) === 0) {
                $survivor = $row;
            } else {
                $tbm = $row;
            }
        }
        if ($tbm === null) {
            return;
        }
        if ($survivor === null) {
            $upd = $this->db->prepare('UPDATE devotee_id SET Devotee_Key = :base WHERE Devotee_Key = :tbm');
            $upd->execute(['base' => $baseKey, 'tbm' => $tbmKey]);

            return;
        }

        $gcs = trim((string) ($tbm['Devotee_ID_Image_Gcs_Path'] ?? ''));
        if ($gcs === '') {
            $gcs = trim((string) ($survivor['Devotee_ID_Image_Gcs_Path'] ?? ''));
        }
        $idType = trim((string) ($tbm['Devotee_ID_Type'] ?? ''));
        if ($idType === '') {
            $idType = trim((string) ($survivor['Devotee_ID_Type'] ?? ''));
        }
        $blob = $survivor['Devotee_ID_Image'] ?? null;
        if ($blob === null || (is_string($blob) && $blob === '')) {
            $blob = $tbm['Devotee_ID_Image'] ?? null;
        }

        $upd = $this->db->prepare(
            'UPDATE devotee_id SET
                Devotee_ID_Type = :type,
                Devotee_ID_Image_Gcs_Path = :gcs,
                Devotee_ID_Image = :blob
             WHERE Devotee_Key = :base LIMIT 1'
        );
        $upd->execute([
            'base' => $baseKey,
            'type' => $idType !== '' ? $idType : null,
            'gcs' => $gcs !== '' ? $gcs : null,
            'blob' => $blob,
        ]);
        $del = $this->db->prepare('DELETE FROM devotee_id WHERE Devotee_Key = :tbm');
        $del->execute(['tbm' => $tbmKey]);
    }

    /**
     * If survivor already has Allocated row for an event, drop TBM row instead of repointing (duplicate key).
     */
    private function repointDevoteeAccommodationRows(string $baseKey, string $tbmKey): void
    {
        $stmt = $this->db->prepare(
            "SELECT Accommodation_Event, Accomodation_Key
             FROM devotee_accomodation
             WHERE Devotee_Key = :tbm AND Accomodation_Status = 'Allocated'"
        );
        $stmt->execute(['tbm' => $tbmKey]);
        $tbmRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($tbmRows === []) {
            return;
        }

        $has = $this->db->prepare(
            "SELECT 1 FROM devotee_accomodation
             WHERE Devotee_Key = :base AND Accommodation_Event = :event AND Accomodation_Status = 'Allocated'
             LIMIT 1"
        );
        $repoint = $this->db->prepare(
            'UPDATE devotee_accomodation SET Devotee_Key = :base WHERE Devotee_Key = :tbm AND Accommodation_Event = :event AND Accomodation_Status = \'Allocated\''
        );
        $depart = $this->db->prepare(
            "UPDATE devotee_accomodation SET Accomodation_Status = 'Departed',
                Devotee_Accomodation_Update_Date_Time = NOW(), Devotee_Accomodation_Updated_By = :by
             WHERE Devotee_Key = :tbm AND Accommodation_Event = :event AND Accomodation_Status = 'Allocated'"
        );

        foreach ($tbmRows as $row) {
            $event = (string) ($row['Accommodation_Event'] ?? '');
            if ($event === '') {
                continue;
            }
            $has->execute(['base' => $baseKey, 'event' => $event]);
            if ($has->fetchColumn()) {
                $depart->execute(['tbm' => $tbmKey, 'event' => $event, 'by' => $this->updatedBy]);
            } else {
                $repoint->execute(['base' => $baseKey, 'tbm' => $tbmKey, 'event' => $event]);
            }
        }
    }

    /**
     * Repair duplicate devotee_photo / devotee_id rows for one Devotee_Key (CLI / one-time ops).
     */
    public function repairDuplicatePhotoAndIdRows(string $devoteeKey): void
    {
        $this->consolidateSurvivorPhotoAndIdRows(strtoupper(trim($devoteeKey)));
    }

    /**
     * Repair legacy duplicate devotee_photo / devotee_id rows for one survivor key after merge.
     */
    private function consolidateSurvivorPhotoAndIdRows(string $baseKey): void
    {
        $photoStmt = $this->db->prepare('SELECT Devotee_Photo_Gcs_Path, Devotee_Photo FROM devotee_photo WHERE Devotee_Key = :k');
        $photoStmt->execute(['k' => $baseKey]);
        $photoRows = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($photoRows) > 1) {
            $gcs = '';
            $blob = null;
            foreach ($photoRows as $row) {
                if ($gcs === '' && trim((string) ($row['Devotee_Photo_Gcs_Path'] ?? '')) !== '') {
                    $gcs = trim((string) $row['Devotee_Photo_Gcs_Path']);
                }
                if (($blob === null || $blob === '') && !empty($row['Devotee_Photo'])) {
                    $blob = $row['Devotee_Photo'];
                }
            }
            $this->db->prepare('DELETE FROM devotee_photo WHERE Devotee_Key = :k')->execute(['k' => $baseKey]);
            $ins = $this->db->prepare(
                'INSERT INTO devotee_photo (Devotee_Key, Devotee_Photo_Gcs_Path, Devotee_Photo) VALUES (:k, :gcs, :blob)'
            );
            $ins->execute(['k' => $baseKey, 'gcs' => $gcs !== '' ? $gcs : null, 'blob' => $blob]);
        }

        $idStmt = $this->db->prepare(
            'SELECT Devotee_ID_Type, Devotee_ID_Image_Gcs_Path, Devotee_ID_Image FROM devotee_id WHERE Devotee_Key = :k'
        );
        $idStmt->execute(['k' => $baseKey]);
        $idRows = $idStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($idRows) > 1) {
            $gcs = '';
            $type = '';
            $blob = null;
            foreach ($idRows as $row) {
                if ($gcs === '' && trim((string) ($row['Devotee_ID_Image_Gcs_Path'] ?? '')) !== '') {
                    $gcs = trim((string) $row['Devotee_ID_Image_Gcs_Path']);
                }
                if ($type === '' && trim((string) ($row['Devotee_ID_Type'] ?? '')) !== '') {
                    $type = trim((string) $row['Devotee_ID_Type']);
                }
                if (($blob === null || $blob === '') && !empty($row['Devotee_ID_Image'])) {
                    $blob = $row['Devotee_ID_Image'];
                }
            }
            $this->db->prepare('DELETE FROM devotee_id WHERE Devotee_Key = :k')->execute(['k' => $baseKey]);
            $ins = $this->db->prepare(
                'INSERT INTO devotee_id (Devotee_Key, Devotee_ID_Type, Devotee_ID_Image_Gcs_Path, Devotee_ID_Image)
                 VALUES (:k, :type, :gcs, :blob)'
            );
            $ins->execute([
                'k' => $baseKey,
                'type' => $type !== '' ? $type : null,
                'gcs' => $gcs !== '' ? $gcs : null,
                'blob' => $blob,
            ]);
        }
    }

    private function archiveDevoteeRow(string $batchId, string $baseKey, string $tbmKey): void
    {
        $sel = $this->db->prepare('SELECT * FROM devotee WHERE Devotee_Key = :k');
        $sel->execute(['k' => $tbmKey]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO devotee_merge_archive
                (Merge_Batch_Id, Archived_Devotee_Key, Base_Devotee_Key, Devotee_Row_Json)
             VALUES (:batch, :archived, :base, :json)'
        );
        $ins->execute([
            'batch' => $batchId,
            'archived' => $tbmKey,
            'base' => $baseKey,
            'json' => json_encode($row, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<string, mixed> $newData
     */
    private function applyIncomingToSurvivor(string $baseKey, array $newData): void
    {
        $sel = $this->db->prepare('SELECT * FROM devotee WHERE Devotee_Key = :k');
        $sel->execute(['k' => $baseKey]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $fields = [
            'Devotee_First_Name' => trim((string) ($newData['Devotee_First_Name'] ?? '')),
            'Devotee_Last_Name' => trim((string) ($newData['Devotee_Last_Name'] ?? '')),
            'Devotee_DOB' => $this->normalizeDate((string) ($newData['Devotee_DOB'] ?? '')),
            'Devotee_Cell_Phone_Number' => trim((string) ($newData['Devotee_Cell_Phone_Number'] ?? '')),
            'Devotee_Station' => trim((string) ($newData['Devotee_Station'] ?? '')),
            'Devotee_ID_Type' => trim((string) ($newData['Devotee_ID_Type'] ?? '')),
            'Devotee_ID_Number' => IdNormalizer::normalize(
                (string) ($newData['Devotee_ID_Type'] ?? ''),
                (string) ($newData['Devotee_ID_Number'] ?? '')
            ),
        ];

        $sets = [];
        $params = ['k' => $baseKey];
        foreach ($fields as $col => $incoming) {
            if ($incoming === '') {
                continue;
            }
            $current = trim((string) ($row[$col] ?? ''));
            if ($current === '' || in_array($col, ['Devotee_First_Name', 'Devotee_Last_Name', 'Devotee_DOB', 'Devotee_Cell_Phone_Number', 'Devotee_Station'], true)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $incoming;
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'Devotee_Record_Update_Date_Time = NOW()';
        $sets[] = 'Devotee_Record_Updated_By = :updated_by';
        $params['updated_by'] = $this->updatedBy;
        $sql = 'UPDATE devotee SET ' . implode(', ', $sets) . ' WHERE Devotee_Key = :k';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param list<string> $tbmKeys
     */
    private function writeMergeAudit(string $baseKey, string $batchId, array $tbmKeys, string $mergeSource): void
    {
        $ts = date('Y-m-d H:i:s');
        $short = 'Auto-merged ' . $ts . '. Batch ' . substr($batchId, 0, 8) . '.';
        if (strlen($short) > 250) {
            $short = substr($short, 0, 247) . '...';
        }
        $stmt = $this->db->prepare(
            'UPDATE devotee SET Comments = :c, Devotee_Record_Update_Date_Time = NOW() WHERE Devotee_Key = :k'
        );
        $stmt->execute(['c' => $short, 'k' => $baseKey]);

        $detail = json_encode([
            'batch_id' => $batchId,
            'survivor' => $baseKey,
            'merged_keys' => $tbmKeys,
            'source' => $mergeSource,
            'at' => $ts,
        ], JSON_UNESCAPED_UNICODE);

        $event = $this->eventId !== '' ? $this->eventId : 'SYSTEM';
        $ins = $this->db->prepare(
            'INSERT INTO devotee_remarks
                (devotee_key, remark_type, remark_event, rating, remark, remark_update_date_time, remark_updated_by)
             VALUES (:k, \'DEDUP\', :event, 0, :remark, NOW(), :by)
             ON DUPLICATE KEY UPDATE
                remark = :remark2,
                remark_update_date_time = NOW(),
                remark_updated_by = :by2'
        );
        $ins->execute([
            'k' => $baseKey,
            'event' => $event,
            'remark' => $detail,
            'by' => $this->updatedBy,
            'remark2' => $detail,
            'by2' => $this->updatedBy,
        ]);
    }

    private function insertAlias(
        string $baseKey,
        ?string $aliasKey,
        string $mergeSource,
        int $mergeScore,
        ?string $aliasIdNumber = null,
        ?string $aliasIdType = null
    ): void {
        $ins = $this->db->prepare(
            'INSERT INTO devotee_aliases
                (Base_Devotee_Key, Alias_Devotee_Key, Alias_ID_Number, Alias_ID_Type, Merge_Source, Merge_Score)
             VALUES (:base, :alias, :idn, :idt, :src, :score)'
        );
        $ins->execute([
            'base' => strtoupper($baseKey),
            'alias' => $aliasKey !== null && $aliasKey !== '' ? strtoupper($aliasKey) : null,
            'idn' => $aliasIdNumber,
            'idt' => $aliasIdType,
            'src' => $mergeSource,
            'score' => $mergeScore,
        ]);
    }

    /**
     * @param array<string, mixed> $newRecord
     */
    private function insertDevoteeRow(array $newRecord, string $devoteeKey): string
    {
        if ($devoteeKey === '') {
            $devoteeKey = $this->generateDevoteeKey();
        }
        $stmt = $this->db->prepare(
            'INSERT INTO devotee (
                Devotee_Key, Devotee_Type, Devotee_First_Name, Devotee_Last_Name, Devotee_Gender,
                Devotee_DOB, Devotee_ID_Type, Devotee_ID_Number, Devotee_Station,
                Devotee_Cell_Phone_Number, Devotee_Status, Devotee_Record_Update_Date_Time, Devotee_Record_Updated_By
            ) VALUES (
                :key, :type, :first, :last, :gender, :dob, :id_type, :id_number, :station,
                :phone, :status, NOW(), :by
            )'
        );
        $dob = $this->normalizeDate((string) ($newRecord['Devotee_DOB'] ?? ''));
        $stmt->execute([
            'key' => strtoupper($devoteeKey),
            'type' => (string) ($newRecord['Devotee_Type'] ?? 'T'),
            'first' => trim((string) ($newRecord['Devotee_First_Name'] ?? '')),
            'last' => trim((string) ($newRecord['Devotee_Last_Name'] ?? '')),
            'gender' => (string) ($newRecord['Devotee_Gender'] ?? ''),
            'dob' => $dob !== '' ? $dob : null,
            'id_type' => trim((string) ($newRecord['Devotee_ID_Type'] ?? '')),
            'id_number' => IdNormalizer::normalize(
                (string) ($newRecord['Devotee_ID_Type'] ?? ''),
                (string) ($newRecord['Devotee_ID_Number'] ?? '')
            ),
            'station' => trim((string) ($newRecord['Devotee_Station'] ?? '')),
            'phone' => trim((string) ($newRecord['Devotee_Cell_Phone_Number'] ?? '')),
            'status' => (string) ($newRecord['Devotee_Status'] ?? 'D'),
            'by' => $this->updatedBy,
        ]);

        return strtoupper($devoteeKey);
    }

    private function generateDevoteeKey(): string
    {
        do {
            $id = 'P' . date('ymd') . (string) random_int(0, 999);
            $id = strtoupper($id);
            $stmt = $this->db->prepare('SELECT 1 FROM devotee WHERE Devotee_Key = :k LIMIT 1');
            $stmt->execute(['k' => $id]);
            $exists = $stmt->fetchColumn();
        } while ($exists);

        return $id;
    }

    private function runRefreshProcs(string $eventId): void
    {
        $e = preg_replace('/[^\w.-]/', '', $eventId) ?? '';
        if ($e === '') {
            return;
        }
        $this->db->exec("CALL PROC_REFRESH_ACCO_COUNT_W_EVENT('{$e}')");
        $this->db->exec("CALL PROC_REFRESH_AMENITIES_COUNT('{$e}')");
        $this->db->exec("CALL PROC_REFRESH_SEVA_COUNT_I('{$e}')");
    }

    private function devoteeExists(string $key): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM devotee WHERE Devotee_Key = :k LIMIT 1');
        $stmt->execute(['k' => strtoupper(trim($key))]);

        return (bool) $stmt->fetchColumn();
    }

    private function cardPrintLogExists(string $key): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM card_print_log WHERE Devotee_Key = :k LIMIT 1');
        $stmt->execute(['k' => strtoupper(trim($key))]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param list<array{devotee_key: string, score: int, signal: int, action: string}> $matches
     */
    private function matchAlreadyListed(array $matches, string $key): bool
    {
        foreach ($matches as $m) {
            if (strcasecmp($m['devotee_key'], $key) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $newRecord
     */
    private function candidateKey(array $newRecord): string
    {
        $k = trim((string) ($newRecord['Devotee_Key'] ?? $newRecord['devotee_key'] ?? ''));

        return strtoupper($k);
    }

    private function normalizeFullName(string $first, string $last): string
    {
        $s = strtoupper(trim($first . ' ' . $last));
        $s = preg_replace('/\s+/', ' ', $s) ?? '';

        return trim($s);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) >= 10) {
            return substr($digits, -10);
        }

        return '';
    }

    private function normalizeStation(string $station): string
    {
        return strtoupper(trim($station));
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $d = DateTime::createFromFormat('Y-m-d', $value);

        return ($d && $d->format('Y-m-d') === $value) ? $value : '';
    }

    private function nameDistance(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        if (strlen($a) > 255 || strlen($b) > 255) {
            $a = substr($a, 0, 255);
            $b = substr($b, 0, 255);
        }

        return levenshtein($a, $b);
    }

    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Repoint staged photo / ID rows uploaded under a reserved key before devotee INSERT (staff + PWA).
     */
    public static function repointStagedMediaKeys(PDO $db, string $baseKey, string $fromKey): void
    {
        $baseKey = strtoupper(trim($baseKey));
        $fromKey = strtoupper(trim($fromKey));
        if ($baseKey === '' || $fromKey === '' || strcasecmp($baseKey, $fromKey) === 0) {
            return;
        }
        foreach (['devotee_photo', 'devotee_id'] as $table) {
            $stmt = $db->prepare("UPDATE {$table} SET Devotee_Key = :base WHERE Devotee_Key = :from");
            $stmt->execute(['base' => $baseKey, 'from' => $fromKey]);
        }
    }

    public static function devoteeRowExists(PDO $db, string $key): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM devotee WHERE Devotee_Key = :k LIMIT 1');
        $stmt->execute(['k' => strtoupper(trim($key))]);

        return (bool) $stmt->fetchColumn();
    }
}
