#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Event registration statistics for KDMS.
 *
 * Metrics:
 *   - Total devotees registered for the event (allocated accommodation).
 *   - Total locals (Devotee_Referral match) and first-time locals.
 *   - Devotees with specific seva (excludes UN = no seva, GEN = General).
 *
 * Usage:
 *   php scripts/event_registration_stats.php --event-id=2026JB
 *   php scripts/event_registration_stats.php --event-id=2026JB --referral=local
 *   php scripts/event_registration_stats.php --event-id=2026JB --breakdown
 */

$root = dirname(__DIR__);
require_once $root . '/includes/kdms_load_dotenv.php';
require_once $root . '/api/config/database.php';

/** Seva codes treated as "no seva" or "general" (not counted in metric 1). */
const DEFAULT_EXCLUDED_SEVA_IDS = ['UN', 'GEN'];

function usage(): string
{
    return <<<'TXT'
Usage: php scripts/event_registration_stats.php [options]

Options:
  --event-id=ID     Event ID (default: KDMS_EVENT_ID env or 2026JB)
  --referral=TEXT   Referral value for metric 2 (default: local, matches Devotee_Referral)
  --breakdown       Print seva and referral breakdown tables
  --help            Show this help

TXT;
}

function parseArgs(array $argv): array
{
    $opts = [
        'event_id' => getenv('KDMS_EVENT_ID') ?: '2026JB',
        'referral' => 'local',
        'breakdown' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
        } elseif ($arg === '--breakdown') {
            $opts['breakdown'] = true;
        } elseif (str_starts_with($arg, '--event-id=')) {
            $opts['event_id'] = trim(substr($arg, 11));
        } elseif (str_starts_with($arg, '--referral=')) {
            $opts['referral'] = trim(substr($arg, 11));
        }
    }

    return $opts;
}

function excludedSevaPlaceholders(PDO $conn, array $ids): string
{
    return implode(',', array_map(static fn ($id) => $conn->quote($id), $ids));
}

function countRegisteredForEvent(PDO $conn, string $eventId): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(DISTINCT Devotee_Key) FROM devotee_accomodation
         WHERE Accommodation_Event = :event AND Accomodation_Status = \'Allocated\''
    );
    $stmt->execute(['event' => $eventId]);

    return (int) $stmt->fetchColumn();
}

function countAssignedSevaRegistered(PDO $conn, string $eventId, array $sevaIds): int
{
    if ($sevaIds === []) {
        return 0;
    }
    $in = excludedSevaPlaceholders($conn, $sevaIds);
    $sql = "SELECT COUNT(DISTINCT da.Devotee_Key)
            FROM devotee_accomodation da
            INNER JOIN devotee_seva ds
                ON ds.Devotee_Key = da.Devotee_Key
                AND ds.Seva_Event = da.Accommodation_Event
                AND ds.Seva_Status = 'Assigned'
            WHERE da.Accommodation_Event = :event
              AND da.Accomodation_Status = 'Allocated'
              AND ds.Seva_ID IN ({$in})";

    $stmt = $conn->prepare($sql);
    $stmt->execute(['event' => $eventId]);

    return (int) $stmt->fetchColumn();
}

function countSpecificSevaRegistered(PDO $conn, string $eventId, array $excludedSevaIds): int
{
    $in = excludedSevaPlaceholders($conn, $excludedSevaIds);
    $sql = "SELECT COUNT(DISTINCT da.Devotee_Key)
            FROM devotee_accomodation da
            INNER JOIN devotee_seva ds
                ON ds.Devotee_Key = da.Devotee_Key
                AND ds.Seva_Event = da.Accommodation_Event
                AND ds.Seva_Status = 'Assigned'
            WHERE da.Accommodation_Event = :event
              AND da.Accomodation_Status = 'Allocated'
              AND ds.Seva_ID NOT IN ({$in})";

    $stmt = $conn->prepare($sql);
    $stmt->execute(['event' => $eventId]);

    return (int) $stmt->fetchColumn();
}

function countReferralRegistered(PDO $conn, string $eventId, string $referral): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(DISTINCT da.Devotee_Key)
         FROM devotee_accomodation da
         INNER JOIN devotee d ON d.Devotee_Key = da.Devotee_Key
         WHERE da.Accommodation_Event = :event
           AND da.Accomodation_Status = \'Allocated\'
           AND LOWER(TRIM(d.Devotee_Referral)) = LOWER(TRIM(:referral))'
    );
    $stmt->execute([
        'event' => $eventId,
        'referral' => $referral,
    ]);

    return (int) $stmt->fetchColumn();
}

function countFirstTimeReferralRegistered(PDO $conn, string $eventId, string $referral): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(DISTINCT da.Devotee_Key)
         FROM devotee_accomodation da
         INNER JOIN devotee d ON d.Devotee_Key = da.Devotee_Key
         WHERE da.Accommodation_Event = :event
           AND da.Accomodation_Status = \'Allocated\'
           AND LOWER(TRIM(d.Devotee_Referral)) = LOWER(TRIM(:referral))
           AND NOT EXISTS (
               SELECT 1 FROM devotee_accomodation da2
               WHERE da2.Devotee_Key = da.Devotee_Key
                 AND da2.Accommodation_Event <> :event2
                 AND da2.Accomodation_Status = \'Allocated\'
           )'
    );
    $stmt->execute([
        'event' => $eventId,
        'event2' => $eventId,
        'referral' => $referral,
    ]);

    return (int) $stmt->fetchColumn();
}

function printSevaBreakdown(PDO $conn, string $eventId): void
{
    $stmt = $conn->prepare(
        "SELECT ds.Seva_ID,
                IFNULL(sm.seva_description, '--') AS seva_description,
                COUNT(DISTINCT da.Devotee_Key) AS devotee_count
         FROM devotee_accomodation da
         INNER JOIN devotee_seva ds
             ON ds.Devotee_Key = da.Devotee_Key
             AND ds.Seva_Event = da.Accommodation_Event
             AND ds.Seva_Status = 'Assigned'
         LEFT JOIN seva_master sm ON sm.seva_id = ds.Seva_ID
         WHERE da.Accommodation_Event = :event
           AND da.Accomodation_Status = 'Allocated'
         GROUP BY ds.Seva_ID, sm.seva_description
         ORDER BY devotee_count DESC, ds.Seva_ID"
    );
    $stmt->execute(['event' => $eventId]);

    echo "\nSeva breakdown (registered devotees by assigned seva):\n";
    printf("%-8s %-30s %8s\n", 'Seva_ID', 'Description', 'Count');
    printf("%'-8s-%-30s-%-8s\n", '', '', '');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf(
            "%-8s %-30s %8d\n",
            $row['Seva_ID'],
            substr((string) $row['seva_description'], 0, 30),
            (int) $row['devotee_count']
        );
    }
}

function printReferralBreakdown(PDO $conn, string $eventId, string $referralFilter): void
{
    $stmt = $conn->prepare(
        'SELECT LOWER(TRIM(d.Devotee_Referral)) AS referral_norm,
                COUNT(DISTINCT da.Devotee_Key) AS total_registered,
                SUM(CASE WHEN prior.prior_events = 0 THEN 1 ELSE 0 END) AS first_time_registered
         FROM devotee_accomodation da
         INNER JOIN devotee d ON d.Devotee_Key = da.Devotee_Key
         LEFT JOIN (
             SELECT Devotee_Key, COUNT(DISTINCT Accommodation_Event) AS prior_events
             FROM devotee_accomodation
             WHERE Accomodation_Status = \'Allocated\'
               AND Accommodation_Event <> :event
             GROUP BY Devotee_Key
         ) prior ON prior.Devotee_Key = da.Devotee_Key
         WHERE da.Accommodation_Event = :event2
           AND da.Accomodation_Status = \'Allocated\'
           AND LOWER(TRIM(d.Devotee_Referral)) LIKE :like
         GROUP BY referral_norm
         ORDER BY total_registered DESC'
    );
    $stmt->execute([
        'event' => $eventId,
        'event2' => $eventId,
        'like' => '%' . strtolower(trim($referralFilter)) . '%',
    ]);

    echo "\nReferral breakdown (matching \"{$referralFilter}\"):\n";
    printf("%-30s %12s %12s\n", 'Referral', 'Registered', 'First-time');
    printf("%'-30s-%-12s-%-12s\n", '', '', '');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf(
            "%-30s %12d %12d\n",
            substr((string) ($row['referral_norm'] ?: '(blank)'), 0, 30),
            (int) $row['total_registered'],
            (int) $row['first_time_registered']
        );
    }
}

$opts = parseArgs($argv);
if ($opts['help']) {
    fwrite(STDOUT, usage());
    exit(0);
}

if ($opts['event_id'] === '') {
    fwrite(STDERR, "Error: --event-id is required.\n");
    exit(1);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn instanceof PDO) {
    fwrite(STDERR, "Error: could not connect to the database.\n");
    exit(1);
}

$eventId = $opts['event_id'];
$referral = $opts['referral'];

$registered = countRegisteredForEvent($conn, $eventId);
$specificSeva = countSpecificSevaRegistered($conn, $eventId, DEFAULT_EXCLUDED_SEVA_IDS);
$generalOrNoSeva = countAssignedSevaRegistered($conn, $eventId, DEFAULT_EXCLUDED_SEVA_IDS);
$totalLocal = countReferralRegistered($conn, $eventId, $referral);
$firstTimeLocal = countFirstTimeReferralRegistered($conn, $eventId, $referral);
$returningLocal = max(0, $totalLocal - $firstTimeLocal);

echo "Event registration statistics\n";
echo str_repeat('=', 40) . "\n";
echo "Event ID: {$eventId}\n";
echo "Generated: " . date('Y-m-d H:i:s T') . "\n\n";

echo "Registration totals\n";
echo str_repeat('-', 40) . "\n";
echo "Total registered (allocated accommodation): {$registered}\n";
echo "Total with Devotee_Referral = \"{$referral}\": {$totalLocal}\n";
echo "  First-time local (no prior event):         {$firstTimeLocal}\n";
echo "  Returning local (prior event attendance):  {$returningLocal}\n\n";

echo "Seva totals\n";
echo str_repeat('-', 40) . "\n";
echo "Total registered:                            {$registered}\n";
echo "With specific seva (excludes UN, GEN):      {$specificSeva}\n";
echo "With UN (no seva) or GEN (General):         {$generalOrNoSeva}\n";

if ($opts['breakdown']) {
    printSevaBreakdown($conn, $eventId);
    printReferralBreakdown($conn, $eventId, $referral);
}

echo "\nNote: KDMS stores referral in devotee.Devotee_Referral (not devotee_reference).\n";
