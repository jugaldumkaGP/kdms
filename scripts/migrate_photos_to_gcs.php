#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-time migration: copy devotee_photo / devotee_id LONGBLOBs to GCS.
 * Does NOT null BLOB columns. Idempotent (skips rows with Gcs_Path already set).
 *
 * Usage:
 *   php scripts/migrate_photos_to_gcs.php --dry-run
 *   php scripts/migrate_photos_to_gcs.php --limit=100
 *
 * Requires: composer install (google/cloud-storage), DB env vars, GCP ADC or GOOGLE_APPLICATION_CREDENTIALS.
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/kdms_load_dotenv.php';
require_once $root . '/includes/kdms_log.php';
require_once $root . '/includes/PhotoStorage.php';
require_once $root . '/api/config/database.php';

kdms_log_bootstrap();

$dryRun = in_array('--dry-run', $argv, true);
$limit = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$batchSize = 100;
$database = new Database();
$db = $database->getConnection();

echo 'Bucket: ' . PhotoStorage::bucketName() . PHP_EOL;
echo 'Mode: ' . ($dryRun ? 'DRY-RUN' : 'LIVE') . PHP_EOL;

$stats = [
    'photo_would_migrate' => 0,
    'photo_migrated' => 0,
    'photo_skipped' => 0,
    'id_would_migrate' => 0,
    'id_migrated' => 0,
    'id_skipped' => 0,
    'errors' => 0,
];

migrateTable(
    $db,
    'devotee_photo',
    'Devotee_Key',
    'Devotee_Photo',
    'Devotee_Photo_Gcs_Path',
    static fn (string $key): string => PhotoStorage::objectPathForPhoto($key),
    $stats,
    'photo',
    $dryRun,
    $batchSize,
    $limit
);

migrateTable(
    $db,
    'devotee_id',
    'Devotee_Key',
    'Devotee_ID_Image',
    'Devotee_ID_Image_Gcs_Path',
    static fn (string $key): string => PhotoStorage::objectPathForIdImage($key),
    $stats,
    'id',
    $dryRun,
    $batchSize,
    $limit
);

echo PHP_EOL . 'Summary:' . PHP_EOL;
print_r($stats);

/**
 * @param array<string, int> $stats
 */
function migrateTable(
    PDO $db,
    string $table,
    string $keyColumn,
    string $blobColumn,
    string $pathColumn,
    callable $pathForKey,
    array &$stats,
    string $label,
    bool $dryRun,
    int $batchSize,
    int $limit
): void {
    $processed = 0;
    $offset = 0;

    while (true) {
        if ($limit > 0 && $processed >= $limit) {
            break;
        }

        $take = $limit > 0 ? min($batchSize, $limit - $processed) : $batchSize;
        $sql = "SELECT {$keyColumn}, {$blobColumn}, {$pathColumn}
                FROM {$table}
                WHERE {$blobColumn} IS NOT NULL
                  AND ({$pathColumn} IS NULL OR TRIM({$pathColumn}) = '')
                ORDER BY {$keyColumn}
                LIMIT {$take} OFFSET {$offset}";

        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $key = (string) $row[$keyColumn];
            $blob = $row[$blobColumn];
            if (is_resource($blob)) {
                $blob = stream_get_contents($blob);
            }
            if (!is_string($blob) || $blob === '') {
                $stats["{$label}_skipped"]++;
                continue;
            }

            $objectPath = $pathForKey($key);
            $wouldKey = "{$label}_would_migrate";
            $stats[$wouldKey]++;

            if ($dryRun) {
                echo "[dry-run] {$table} {$key} -> gs://" . PhotoStorage::bucketName() . "/{$objectPath}" . PHP_EOL;
                $processed++;
                continue;
            }

            $written = PhotoStorage::writeGcsObject($objectPath, $blob);
            if ($written === null) {
                $stats['errors']++;
                echo "[error] {$table} {$key} GCS write failed" . PHP_EOL;
                continue;
            }

            $update = $db->prepare(
                "UPDATE {$table} SET {$pathColumn} = :path WHERE {$keyColumn} = :key"
            );
            $update->execute(['path' => $objectPath, 'key' => $key]);
            $stats["{$label}_migrated"]++;
            echo "[ok] {$table} {$key} -> {$objectPath}" . PHP_EOL;
            $processed++;
        }

        if (count($rows) < $take) {
            break;
        }
        $offset += $take;
    }
}
