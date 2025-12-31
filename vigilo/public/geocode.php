<?php
// geocode.php - Geocode addresses to coordinates
session_start();
require_once('../config/db.php');

// Only accessible via AJAX
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    die('Access denied');
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['address']) || empty($input['address'])) {
    echo json_encode(['success' => false, 'error' => 'Address required']);
    exit;
}

$address = urlencode($input['address']);

// Use Nominatim (OpenStreetMap) for geocoding
$url = "https://nominatim.openstreetmap.org/search?format=json&q={$address}&limit=1";

// Set user agent as required by Nominatim
$options = [
    'http' => [
        'header' => "User-Agent: Vigilo-Lost-Found-System/1.0\r\n"
    ]
];
$context = stream_context_create($options);

$response = @file_get_contents($url, false, $context);

if ($response === false) {
    echo json_encode(['success' => false, 'error' => 'Geocoding service unavailable']);
    exit;
}

$data = json_decode($response, true);

if (empty($data)) {
    echo json_encode(['success' => false, 'error' => 'Address not found']);
    exit;
}

$result = $data[0];

echo json_encode([
    'success' => true,
    'lat' => floatval($result['lat']),
    'lng' => floatval($result['lon']),
    'display_name' => $result['display_name']
]);
?>