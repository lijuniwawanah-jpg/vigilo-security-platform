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
    SELECT * FROM deleted_documents 
    WHERE id=? AND user_id=?
");
$stmt->bind_param("ii", $deleted_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("File not found");
}

$file = $result->fetch_assoc();

// Restore to documents table
$stmt2 = $conn->prepare("
    INSERT INTO documents (id, user_id, file_name, file_path, uploaded_at)
    VALUES (?, ?, ?, ?, NOW())
");
$stmt2->bind_param("iiss",
    $file['original_id'],
    $user_id,
    $file['file_name'],
    $file['file_path']
);
$stmt2->execute();

// Remove from trash
$conn->query("DELETE FROM deleted_documents WHERE id=$deleted_id");

header("Location: ../../public/documents.php?restored=1");
exit;
?>
