<?php
require_once "../../config/db.php";
require_once "../../config/functions.php";

header("Content-Type: application/json");

$admin = authenticate();
if (!$admin || !$admin["is_admin"]) {
    echo json_encode(["error" => "Admin only"]);
    exit;
}

if (!isset($_GET["user_id"])) {
    echo json_encode(["error" => "Missing user_id"]);
    exit;
}

$user_id = intval($_GET["user_id"]);
$conn = getConnection();

$sql = "SELECT id, name, email, phone FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode(["error" => "User not found"]);
    exit;
}

$user = $res->fetch_assoc();

/**
 * Generate new temporary JWT for impersonation
 */
$token = generate_jwt([
    "id" => $user["id"],
    "email" => $user["email"],
    "impersonated_by" => $admin["id"],
    "is_admin" => false
]);

echo json_encode([
    "success" => true,
    "message" => "Impersonation token created",
    "token" => $token,
    "user" => $user
]);

$stmt->close();
$conn->close();
