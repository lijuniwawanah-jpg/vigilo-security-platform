<?php
session_start();
header('Content-Type: application/json');
require_once('../../config/db.php');

if(!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not logged in']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$old = $input['old'] ?? ''; $new = $input['new'] ?? '';

if(!$old || !$new || strlen($new) < 6) { echo json_encode(['success'=>false,'message'=>'Invalid password']); exit; }

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
$stmt->bind_param("i",$user_id); $stmt->execute();
$res = $stmt->get_result();
if($res->num_rows===0){ echo json_encode(['success'=>false,'message'=>'User not found']); exit; }
$row = $res->fetch_assoc();
if(!password_verify($old,$row['password'])){ echo json_encode(['success'=>false,'message'=>'Current password incorrect']); exit; }
$stmt->close();

$hash = password_hash($new, PASSWORD_BCRYPT);
$up = $conn->prepare("UPDATE users SET password=? WHERE id=?");
$up->bind_param("si",$hash,$user_id);
if($up->execute()) echo json_encode(['success'=>true,'message'=>'Password changed']);
else echo json_encode(['success'=>false,'message'=>'DB error']);
$up->close(); $conn->close();
