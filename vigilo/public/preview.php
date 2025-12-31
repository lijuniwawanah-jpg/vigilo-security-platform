<?php
session_start();
require_once('../config/db.php');

if(!isset($_SESSION['user_id'])) { 
    header('Location: login.php'); 
    exit; 
}

if(!isset($_GET['id'])) { 
    header('Location: documents.php'); 
    exit; 
}

$id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// Debug: Check connection
if (!$conn) {
    die("Database connection failed");
}

// Check if documents table exists
$table_check = $conn->query("SHOW TABLES LIKE 'documents'");
if (!$table_check || $table_check->num_rows === 0) {
    die("Documents table doesn't exist.");
}

// Get document with error handling
$sql = "SELECT * FROM documents WHERE id = ? AND user_id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Error preparing SQL: " . $conn->error);
}

$stmt->bind_param("ii", $id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) { 
    $stmt->close();
    header('Location: documents.php');
    exit; 
}

$doc = $result->fetch_assoc();
$stmt->close();

// Find file with multiple possible paths
$paths = [
    $doc['file_path'],
    __DIR__ . '/../' . $doc['file_path'],
    __DIR__ . '/../uploads/docs/' . basename($doc['file_path']),
    __DIR__ . '/../uploads/' . basename($doc['file_path'])
];

$real_path = null;
foreach($paths as $p) {
    if($p && file_exists($p)) {
        $real_path = $p;
        break;
    }
}

if(!$real_path) {
    echo "File not found on server.";
    exit;
}

// Determine content type
$ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
$mime_types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    'txt' => 'text/plain',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

$mime = $mime_types[$ext] ?? 'application/octet-stream';
    
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real_path));

if(in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'])) {
    header('Content-Disposition: inline; filename="' . $doc['file_name'] . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $doc['file_name'] . '"');
}

readfile($real_path);
exit;
?>