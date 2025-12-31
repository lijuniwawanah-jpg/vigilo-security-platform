<?php
// nearby_items.php - API endpoint for nearby items
require_once('../config/db.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$radius = isset($_GET['radius']) ? intval($_GET['radius']) : 10; // km
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

if (!$lat || !$lng) {
    echo json_encode(['success' => false, 'error' => 'Coordinates required']);
    exit;
}

// Calculate distance using Haversine formula
$sql = "
    SELECT 
        i.*,
        (6371 * ACOS(
            COS(RADIANS(?)) * 
            COS(RADIANS(i.latitude)) * 
            COS(RADIANS(i.longitude) - RADIANS(?)) + 
            SIN(RADIANS(?)) * 
            SIN(RADIANS(i.latitude))
        )) AS distance
    FROM items i 
    WHERE i.is_public = 1 
    AND i.status IN ('lost', 'stolen')
    AND i.latitude IS NOT NULL 
    AND i.longitude IS NOT NULL
    HAVING distance <= ?
    ORDER BY distance ASC
    LIMIT ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("dddi", $lat, $lng, $lat, $radius, $limit);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    // Format photos
    $photos = [];
    if (!empty($row['photos'])) {
        $photo_data = json_decode($row['photos'], true);
        if ($photo_data && is_array($photo_data) && !empty($photo_data[0]['path'])) {
            $photos[] = '../' . $photo_data[0]['path'];
        }
    }
    
    $items[] = [
        'id' => $row['id'],
        'name' => $row['item_name'],
        'description' => $row['description'],
        'category' => $row['category'],
        'brand' => $row['brand'],
        'location' => $row['incident_location'],
        'reported_date' => $row['reported_date'],
        'reward' => floatval($row['reward_amount']),
        'status' => $row['status'],
        'distance' => floatval($row['distance']),
        'lat' => floatval($row['latitude']),
        'lng' => floatval($row['longitude']),
        'photos' => $photos,
        'url' => "item_detail.php?id=" . $row['id']
    ];
}

echo json_encode([
    'success' => true,
    'count' => count($items),
    'items' => $items
]);

$stmt->close();
$conn->close();
?>