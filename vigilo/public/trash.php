<?php
session_start();
require_once "../api/db.php";

if (!isset($_SESSION['user_id'])) {
    echo "Unauthorized";
    exit;
}

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT id, original_id, file_name, file_path, deleted_at 
    FROM deleted_documents 
    WHERE user_id=? 
    ORDER BY deleted_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Trash</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 10px; border: 1px solid #ccc; }
        .btn { padding: 5px 10px; background: blue; color: #fff; text-decoration: none; border-radius: 5px; }
        .del { background: red; }
    </style>
</head>

<body>
<h2>Trash – Deleted Files (kept for 30 days)</h2>

<table>
    <tr>
        <th>Name</th>
        <th>Deleted At</th>
        <th>Restore</th>
        <th>Delete Forever</th>
    </tr>

    <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($row['file_name']) ?></td>
            <td><?= $row['deleted_at'] ?></td>
            <td><a class="btn" href="../api/documents/restore.php?id=<?= $row['id'] ?>">Restore</a></td>
            <td><a class="btn del" href="../api/documents/delete_forever.php?id=<?= $row['id'] ?>">Delete</a></td>
        </tr>
    <?php endwhile; ?>
</table>

</body>
</html>
