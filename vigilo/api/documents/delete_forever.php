<?php
session_start();
require_once "../db.php";

if (!isset($_GET['id']) || !isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$deleted_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// Fetch deleted record
$stmt = $conn->prepare("
    SELECT file_path FROM deleted_documents 
    WHERE id=? AND user_id=?
");
$stmt->bind_param("ii", $deleted_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Not found");
}

$file = $result->fetch_assoc();
$file_path = "../../uploads/docs/" . $file['file_path'];

// Delete file from server
if (file_exists($file_path)) {
    unlink($file_path);
}

// Remove record
$conn->query("DELETE FROM deleted_documents WHERE id=$deleted_id");

header("Location: ../../public/trash.php?deleted=1");
exit;
?>
