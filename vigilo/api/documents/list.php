<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'User not logged in', 
        'documents' => []
    ]);
    exit;
}

require_once('../../config/db.php');

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT id, filename, uploaded_at FROM documents WHERE user_id = ?");
$stmt->bind_param("i", $user_id);

$stmt->execute();
$result = $stmt->get_result();

$documents = [];

while ($row = $result->fetch_assoc()) {
    $documents[] = [
        "id" => $row["id"],
        "filename" => $row["filename"],
        "uploaded_at" => $row["uploaded_at"]
    ];
}

echo json_encode([
    'success' => true,
    'documents' => $documents
]);

$conn->close();
?>
