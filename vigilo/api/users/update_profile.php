<?php
session_start();
header('Content-Type: application/json');
require_once('../../config/db.php');

if(!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not logged in']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if(!$input) { echo json_encode(['success'=>false,'message'=>'Invalid input']); exit; }

$full = trim($input['fullName'] ?? '');
$email = trim($input['email'] ?? '');

if(!$full || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success'=>false,'message'=>'Invalid data']); exit;
}

$user_id = $_SESSION['user_id'];

// Check email uniqueness if changed
$stmt = $conn->prepare("SELECT id FROM users WHERE email=? AND id<>?");
$stmt->bind_param("si",$email,$user_id);
$stmt->execute();
if($stmt->get_result()->num_rows>0){
    echo json_encode(['success'=>false,'message'=>'Email already used']);
    $stmt->close(); exit;
}
$stmt->close();

$st = $conn->prepare("UPDATE users SET fullName=?, email=? WHERE id=?");
$st->bind_param("ssi",$full,$email,$user_id);
if($st->execute()){
    echo json_encode(['success'=>true,'message'=>'Profile updated']);
}else{
    echo json_encode(['success'=>false,'message'=>'DB error: '.$st->error]);
}
$st->close();
$conn->close();
