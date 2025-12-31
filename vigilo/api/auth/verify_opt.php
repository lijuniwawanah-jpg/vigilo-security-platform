<?php
// api/auth/verify_otp.php
require_once __DIR__ . "/../../config/functions.php";

$phone = $_POST['phone'] ?? null;
$otp = $_POST['otp'] ?? null;
if (!$phone || !$otp) err("phone and otp are required");

$stmt = $pdo->prepare("SELECT otp, expires_at FROM otp_store WHERE phone = ?");
$stmt->execute([$phone]);
$row = $stmt->fetch();

if (!$row) err("No OTP requested for this phone", 404);
if ($row['expires_at'] < date("Y-m-d H:i:s")) err("OTP expired", 400);
if ($row['otp'] !== $otp) err("Invalid OTP", 400);

// create or get user
$stmt = $pdo->prepare("SELECT id, w_id, name FROM users WHERE phone = ?");
$stmt->execute([$phone]);
$user = $stmt->fetch();

if (!$user) {
    // create new user
    $w_id = "VIG-" . strtoupper(substr(bin2hex(random_bytes(5)),0,10));
    $stmt = $pdo->prepare("INSERT INTO users (phone, w_id) VALUES (?, ?)");
    $stmt->execute([$phone, $w_id]);
    $user_id = $pdo->lastInsertId();
    $user = ["id" => $user_id, "w_id" => $w_id, "name" => null];
} else {
    $user_id = $user['id'];
}

// create session token (expires in 30 days)
$token = bin2hex(random_bytes(24));
$expires_at = date("Y-m-d H:i:s", time() + 60*60*24*30);
$stmt = $pdo->prepare("INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)");
$stmt->execute([$user_id, $token, $expires_at]);

// cleanup OTP (optional)
$stmt = $pdo->prepare("DELETE FROM otp_store WHERE phone = ?");
$stmt->execute([$phone]);

audit($pdo, $user_id, "login", ["by" => "otp", "phone" => $phone]);

ok(["token" => $token, "user" => ["id" => $user_id, "w_id" => $user['w_id'] ?? $w_id]]);
