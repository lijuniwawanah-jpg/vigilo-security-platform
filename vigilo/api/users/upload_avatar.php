<?php
session_start();
header('Content-Type: application/json');
require_once('../../config/db.php');

if(!isset($_SESSION['user_id'])){ echo json_encode(['success'=>false,'message'=>'Not logged']); exit; }
$user_id = $_SESSION['user_id'];

if(!isset($_FILES['avatar'])){ echo json_encode(['success'=>false,'message'=>'No file']); exit; }

$file = $_FILES['avatar'];
if($file['error']!==UPLOAD_ERR_OK){ echo json_encode(['success'=>false,'message'=>'Upload error']); exit; }

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$targetDir = '../../uploads/avatars/';
if(!is_dir($targetDir)) mkdir($targetDir,0777,true);
$filename = time().'_u'.$user_id.'.'.preg_replace('/[^a-z0-9]/i','',$ext);
$target = $targetDir.$filename;

if(move_uploaded_file($file['tmp_name'],$target)){
    // store relative path
    $rel = 'uploads/avatars/'.$filename;
    $stmt = $conn->prepare("UPDATE users SET avatar=? WHERE id=?");
    $stmt->bind_param("si",$rel,$user_id);
    $stmt->execute();
    echo json_encode(['success'=>true,'message'=>'Avatar uploaded','avatar'=>$rel]);
}else{
    echo json_encode(['success'=>false,'message'=>'Move failed']);
}
$conn->close();
