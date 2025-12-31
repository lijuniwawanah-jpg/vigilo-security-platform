<?php
// save_location.php - Save user location to session/cookie
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['lat']) && isset($input['lng'])) {
        $_SESSION['user_location'] = [
            'lat' => floatval($input['lat']),
            'lng' => floatval($input['lng'])
        ];
        
        // Also save to cookie for 30 days
        setcookie('user_location', json_encode($_SESSION['user_location']), time() + (30 * 24 * 60 * 60), '/');
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?>