<?php

declare(strict_types=1);

/**
 * Staff ID scan → Document AI via kdms-registration (Phase 6 Stream D).
 * Auth: staff session only. Forwards multipart id_image; returns registration OCR JSON.
 */
require_once __DIR__ . '/../includes/api_session.php';
require_once __DIR__ . '/../includes/kdms_log.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');

if (empty($_SESSION['LoginID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$registrationBase = getenv('KDMS_REGISTRATION_URL');
if (!is_string($registrationBase) || trim($registrationBase) === '') {
    kdms_log('ERROR', 'staffOcrExtract: KDMS_REGISTRATION_URL not configured');
    echo json_encode(staff_ocr_empty_response());
    exit;
}

$devoteeKey = strtoupper(trim((string) ($_POST['Devotee_Key'] ?? $_POST['devotee_key'] ?? '')));
if ($devoteeKey === '' || !preg_match('/^P[0-9A-Z]+$/', $devoteeKey)) {
    http_response_code(400);
    echo json_encode(['error' => 'Devotee_Key is required before ID scan.']);
    exit;
}

if (empty($_FILES['id_image']) || !is_uploaded_file($_FILES['id_image']['tmp_name'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'id_image is required']);
    exit;
}

$file = $_FILES['id_image'];
$size = (int) ($file['size'] ?? 0);
if ($size <= 0 || $size > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Image must be between 1 byte and 5MB']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']) ?: '';
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Only JPEG or PNG images are allowed']);
    exit;
}

$serviceKey = getenv('KDMS_SERVICE_KEY');
if (!is_string($serviceKey) || $serviceKey === '') {
    kdms_log('ERROR', 'staffOcrExtract: KDMS_SERVICE_KEY not configured');
    echo json_encode(staff_ocr_empty_response());
    exit;
}

$registrationUrl = rtrim($registrationBase, '/') . '/api/ocr-extract';
$cfile = new CURLFile($file['tmp_name'], $mime, $file['name'] ?? 'id_image.jpg');
$post = [
    'id_image' => $cfile,
    'Devotee_Key' => $devoteeKey,
];

$ch = curl_init($registrationUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-KDMS-SERVICE-KEY: ' . $serviceKey]);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$body = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!is_string($body) || $body === '' || $httpCode < 200 || $httpCode >= 300) {
    kdms_log('ERROR', 'staffOcrExtract: registration OCR failed', [
        'devotee_key' => $devoteeKey,
        'http_code' => $httpCode,
    ]);
    echo json_encode(staff_ocr_empty_response());
    exit;
}

$data = json_decode($body, true);
if (!is_array($data)) {
    echo json_encode(staff_ocr_empty_response());
    exit;
}

if (!empty($data['id_gcs_path'])) {
    staff_ocr_persist_id_gcs_path($devoteeKey, (string) $data['id_gcs_path']);
}

echo json_encode($data);
exit;

/**
 * @return array<string, mixed>
 */
function staff_ocr_empty_response(): array
{
    $fields = [
        'Devotee_First_Name',
        'Devotee_Last_Name',
        'Devotee_ID_Number',
        'Devotee_DOB',
        'Devotee_Gender',
        'Devotee_Email',
        'Devotee_Address_1',
        'Devotee_Address_2',
        'Devotee_Station',
        'Devotee_State',
        'Devotee_Zip',
    ];
    $out = ['id_gcs_path' => null];
    foreach ($fields as $name) {
        $out[$name] = ['value' => null, 'confidence' => 0];
    }

    return $out;
}

function staff_ocr_persist_id_gcs_path(string $devoteeKey, string $gcsPath): void
{
    $gcsPath = trim($gcsPath);
    if ($gcsPath === '') {
        return;
    }
    try {
        $database = new Database();
        $db = $database->getConnection();
        $stmt = $db->prepare(
            'INSERT INTO devotee_id (Devotee_Key, Devotee_ID_Image_Gcs_Path, Devotee_ID_Image, Devotee_ID_Type)
             VALUES (:key, :gcs, NULL, :type)
             ON DUPLICATE KEY UPDATE
                Devotee_ID_Image_Gcs_Path = VALUES(Devotee_ID_Image_Gcs_Path)'
        );
        $stmt->execute([
            'key' => $devoteeKey,
            'gcs' => $gcsPath,
            'type' => 'self',
        ]);
    } catch (Throwable $e) {
        kdms_log('ERROR', 'staffOcrExtract: could not persist id GCS path', [
            'devotee_key' => $devoteeKey,
            'error' => $e->getMessage(),
        ]);
    }
}
