<?php
// api/users/me.php
require_once __DIR__ . "/../../config/functions.php";

// read bearer token from Authorization header
$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
if (!$auth) err("Authorization header required", 401);

if (!preg_match('/Bearer\s+(.+)/', $auth, $m)) err("Invalid auth header", 401);
$token = $m[1];

// find session
$stmt = $pdo->prepare("SELECT s.user_id, u.phone, u.name, u.w_id FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ? AND s.expires_at > NOW()");
$stmt->execute([$token]);
$row = $stmt->fetch();
if (!$row) err("Invalid or expired token", 401);

ok(["user" => $row]);
