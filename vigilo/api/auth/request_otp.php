<?php
// api/auth/request_otp.php
require_once __DIR__ . "/../../config/functions.php";

$phone = $_POST['phone'] ?? null;
if (!$phone) err("phone is required");

// generate 6-digit OTP
$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
// expire in 5 minutes
$expires_at = date("Y-m-d H:i:s", time() + 300);

// upsert into otp_store
$stmt = $pdo->prepare("REPLACE INTO otp_store (phone, otp, expires_at) VALUES (?, ?, ?)");
$stmt->execute([$phone, $otp, $expires_at]);

// TODO: integrate SMS provider here (Twilio, Africa's providers).
// For demo we return OTP in the response (remove in prod)
ok(["otp" => $otp, "message" => "OTP generated (demo). Integrate SMS gateway in production."]);
