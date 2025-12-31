<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once('../../config/db.php');

if (!isset($_GET['id']) || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
}

$file_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT file_name, file_path FROM documents WHERE id = ? AND user_id = ?");
if (!$stmt) { http_response_code(500); echo "DB error"; exit; }
$stmt->bind_param("ii",$file_id,$user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) { http_response_code(404); echo "File not found"; exit; }
$file = $res->fetch_assoc();
$stmt->close();

// Determine real filesystem path
$pathCandidates = [];
// If file_path looks absolute or contains uploads, try it first
if (!empty($file['file_path'])) {
    $pathCandidates[] = $file['file_path'];
    $pathCandidates[] = __DIR__ . '/../../' . $file['file_path'];
}
// fallback to uploads/docs/<file_name>
$pathCandidates[] = __DIR__ . '/../../uploads/docs/' . $file['file_name'];

$realpath = null;
foreach ($pathCandidates as $p) {
    if ($p && file_exists($p)) { $realpath = $p; break; }
}

if (!$realpath) { http_response_code(404); echo "Physical file missing"; exit; }

// Send file
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.basename($file['file_name']).'"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($realpath));
readfile($realpath);
exit;
