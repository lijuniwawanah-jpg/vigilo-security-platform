<?php
require_once "db.php";

$query = "
    SELECT id, file_path 
    FROM deleted_documents 
    WHERE deleted_at < NOW() - INTERVAL 30 DAY
";
$result = $conn->query($query);

while ($row = $result->fetch_assoc()) {
    $path = "../uploads/docs/" . $row['file_path'];
    if (file_exists($path)) unlink($path);
    $conn->query("DELETE FROM deleted_documents WHERE id=".$row['id']);
}

echo "Old files cleaned.";
