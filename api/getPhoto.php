<?php

declare(strict_types=1);

/**
 * Devotee photo as base64 JSON. Dual-read: GCS path if set, else LONGBLOB.
 */
require_once __DIR__ . '/../includes/api_session.php';
require_once __DIR__ . '/../includes/PhotoStorage.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');

$devoteeKey = '';
if (!empty($_GET['devotee_key'])) {
    $devoteeKey = htmlspecialchars(strip_tags((string) $_GET['devotee_key']));
} elseif (!empty($_POST['devotee_key'])) {
    $devoteeKey = htmlspecialchars(strip_tags((string) $_POST['devotee_key']));
}

if ($devoteeKey === '') {
    echo json_encode(['status' => false, 'message' => 'devotee_key is required', 'image_base64' => '']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$result = PhotoStorage::readDevoteePhoto($db, $devoteeKey);

if ($result === null) {
    echo json_encode([
        'status' => false,
        'message' => 'No photo found for devotee',
        'devotee_key' => $devoteeKey,
        'image_base64' => '',
        'source' => null,
    ]);
    exit;
}

echo json_encode([
    'status' => true,
    'message' => 'ok',
    'devotee_key' => $devoteeKey,
    'image_base64' => base64_encode($result['bytes']),
    'source' => $result['source'],
]);
