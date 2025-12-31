<?php
// api/documents/get_share.php
session_start();
require_once('../config/db.php');

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$share_id = intval($_GET['id'] ?? 0);

if($share_id === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid share ID']);
    exit;
}

// Get share data
$stmt = $conn->prepare("
    SELECT sl.*, d.original_name 
    FROM shared_links sl
    JOIN documents d ON sl.document_id = d.id
    WHERE sl.id = ? AND sl.user_id = ?
");
$stmt->bind_param("ii", $share_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Share not found']);
    exit;
}

$share = $result->fetch_assoc();
$stmt->close();

echo json_encode([
    'success' => true,
    'share' => $share
]);
?>