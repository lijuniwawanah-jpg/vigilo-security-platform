<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

header('Content-Type: application/json');
require_once('../../config/db.php');

// Read JSON input
$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['fullName'], $data['email'], $data['password'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$fullName = trim($data['fullName']);
$email = trim($data['email']);
$password = $data['password'];

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

// Check if the email already exists
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Email already registered']);
    exit;
}
$stmt->close();

// Hash password
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

// Insert new user
$stmt = $conn->prepare("INSERT INTO users (fullName, email, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $fullName, $email, $hashedPassword);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: Unable to register user'
    ]);
}

$stmt->close();
$conn->close();
?>
