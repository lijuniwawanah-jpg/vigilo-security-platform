<?php
// api/verify/check.php
require_once __DIR__ . "/../../config/functions.php";
// Input: doc_id or w_id (GET param)
$doc_id = intval($_GET['doc_id'] ?? 0);
$w_id = $_GET['w_id'] ?? null;

if (!$doc_id && !$w_id) err("doc_id or w_id required", 400);

if ($doc_id) {
    $stmt = $pdo->prepare("SELECT d.id, d.filename_original, d.file_hash, d.doc_type, d.status, u.w_id, u.phone FROM documents d JOIN users u ON u.id = d.user_id WHERE d.id = ?");
    $stmt->execute([$doc_id]);
    $row = $stmt->fetch();
    if (!$row) err("Not found",404);
    ok(["document" => $row]);
} else {
    $stmt = $pdo->prepare("SELECT id, phone, w_id, created_at FROM users WHERE w_id = ?");
    $stmt->execute([$w_id]);
    $row = $stmt->fetch();
    if (!$row) err("User not found",404);
    ok(["user" => $row]);
}
