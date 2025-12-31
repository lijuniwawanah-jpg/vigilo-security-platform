<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
header('Content-Type: application/json');

require_once('../../config/db.php');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Not logged in']);
    exit;
}
$user_id = $_SESSION['user_id'];

// Accept id via POST (form-data or JSON) or fallback to GET param
$inputId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // JSON body?
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if ($json && isset($json['id'])) $inputId = intval($json['id']);
    if ($inputId === null && isset($_POST['id'])) $inputId = intval($_POST['id']);
} 
if ($inputId === null && isset($_GET['id'])) $inputId = intval($_GET['id']);

if (!$inputId) {
    echo json_encode(['success'=>false,'message'=>'Missing file id']);
    exit;
}

// Fetch file record and verify ownership
$stmt = $conn->prepare("SELECT id, file_name, file_path FROM documents WHERE id = ? AND user_id = ?");
if (!$stmt) { echo json_encode(['success'=>false,'message'=>$conn->error]); exit; }
$stmt->bind_param("ii",$inputId,$user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo json_encode(['success'=>false,'message'=>'File not found or not yours']);
    exit;
}
$file = $res->fetch_assoc();
$stmt->close();

// Insert into deleted_documents
$stmt2 = $conn->prepare("INSERT INTO deleted_documents (original_id, user_id, file_name, file_path, deleted_at) VALUES (?, ?, ?, ?, NOW())");
if (!$stmt2) { echo json_encode(['success'=>false,'message'=>$conn->error]); exit; }
$stmt2->bind_param("iiss",$file['id'],$user_id,$file['file_name'],$file['file_path']);
$stmt2->execute();
$stmt2->close();

// Delete from documents table
$stmt3 = $conn->prepare("DELETE FROM documents WHERE id = ? AND user_id = ?");
$stmt3->bind_param("ii",$inputId,$user_id);
$stmt3->execute();
$stmt3->close();

echo json_encode(['success'=>true,'message'=>'File moved to trash (kept 30 days)']);
$conn->close();

