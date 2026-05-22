<?php

declare(strict_types=1);

/**
 * Staff UI: duplicate check from current form values (new or edit).
 * GET/POST: devotee_key, devotee_id_type, devotee_id_number, names, dob, phone, station, eventId
 */
require_once __DIR__ . '/../includes/api_session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/../includes/DeduplicationService.php';
require_once __DIR__ . '/../includes/IdNormalizer.php';
require_once __DIR__ . '/../includes/kdms_dob.php';

header('Content-Type: application/json');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'Method not allowed']);
    exit;
}

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$devoteeKey = strtoupper(trim((string) ($in['devotee_key'] ?? '')));
$eventId = trim((string) ($in['eventId'] ?? getenv('KDMS_EVENT_ID') ?: ''));

$rawIdType = trim((string) ($in['devotee_id_type'] ?? $in['Devotee_ID_Type'] ?? ''));
$rawIdNumber = trim((string) ($in['devotee_id_number'] ?? $in['Devotee_ID_Number'] ?? ''));
[$idType, $idNumber] = IdNormalizer::resolveForDedup($rawIdType, $rawIdNumber);
$rawDob = trim((string) ($in['devotee_dob'] ?? $in['Devotee_DOB'] ?? ''));
$normalizedDob = $rawDob !== '' ? (kdms_normalize_devotee_dob($rawDob) ?? $rawDob) : '';

$record = [
    'Devotee_Key' => $devoteeKey,
    'Devotee_First_Name' => trim((string) ($in['devotee_first_name'] ?? $in['Devotee_First_Name'] ?? '')),
    'Devotee_Last_Name' => trim((string) ($in['devotee_last_name'] ?? $in['Devotee_Last_Name'] ?? '')),
    'Devotee_ID_Type' => $idType,
    'Devotee_ID_Number' => $idNumber,
    'Devotee_Cell_Phone_Number' => trim((string) ($in['devotee_cell_phone_number'] ?? $in['Devotee_Cell_Phone_Number'] ?? '')),
    'Devotee_DOB' => ($normalizedDob === '' || $normalizedDob === '1900-01-01') ? '' : $normalizedDob,
    'Devotee_Station' => trim((string) ($in['devotee_station'] ?? $in['Devotee_Station'] ?? '')),
];

$database = new Database();
$db = $database->getConnection();
$svc = new DeduplicationService($db, $eventId, $_SESSION['LoginID'] ?? 'STAFF');
$check = $svc->findDuplicates($record);

echo json_encode([
    'status' => true,
    'devotee_key' => $devoteeKey,
    'recommended_action' => $check['recommended_action'],
    'merge_score' => $check['merge_score'],
    'matches' => $check['matches'],
]);
exit;
