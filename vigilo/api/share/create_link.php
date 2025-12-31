<?php
// api/share/create_link.php
require_once __DIR__ . "/../../config/functions.php";

// auth
$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
if (!$auth || !preg_match('/Bearer\s+(.+)/', $auth, $m)) err("Authorization header required", 401);
$token = $m[1];

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? AND expires_at > NOW()");
$stmt->execute([$token]);
$sess = $stmt->fetch();
if (!$sess) err("Invalid session", 401);
$user_id = $sess['user_id'];

// request body: doc_id, expire_minutes
$doc_id = intval($_POST['doc_id'] ?? 0);
$expire_min = intval($_POST['expire_min'] ?? 60);
if (!$doc_id) err("doc_id required", 400);

// ensure user owns the doc
$stmt = $pdo->prepare("SELECT id FROM documents WHERE id = ? AND user_id = ?");
$stmt->execute([$doc_id, $user_id]);
if (!$stmt->fetch()) err("Not authorized for this doc", 403);

$token_share = bin2hex(random_bytes(18));
$expires_at = date("Y-m-d H:i:s", time() + $expire_min*60);

$stmt = $pdo->prepare("INSERT INTO shares (doc_id, user_id, token, expires_at) VALUES (?, ?, ?, ?)");
$stmt->execute([$doc_id, $user_id, $token_share, $expires_at]);

audit($pdo, $user_id, "create_share", ["doc_id"=>$doc_id, "expires_in_min"=>$expire_min]);

ok(["share_token" => $token_share, "expires_at" => $expires_at, "download_url" => "/api/documents/download.php?doc_id={$doc_id}&token={$token_share}"]);
