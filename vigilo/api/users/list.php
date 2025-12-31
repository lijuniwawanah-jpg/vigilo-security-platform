<?php
require_once "../../config/db.php";
require_once "../../config/functions.php";

header("Content-Type: application/json");

$auth = authenticate();
if (!$auth || !$auth["is_admin"]) {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$conn = getConnection();

$sql = "SELECT id, name, email, phone, created_at FROM users ORDER BY id DESC";
$result = $conn->query($sql);

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

echo json_encode(["success" => true, "users" => $users]);
$conn->close();
