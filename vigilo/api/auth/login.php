<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

session_start();
require_once('../../config/db.php');

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['email'], $data['password'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$email = trim($data['email']);
$password = $data['password'];

// Prepare the query - ADD role to SELECT
$stmt = $conn->prepare("SELECT id, fullName, email, password, role FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();

// Use get_result() instead
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();

    // Verify password
    if (password_verify($password, $user['password'])) {
        // Save session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['fullName'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role']; // Save role in session
        
        // Check if user is admin
        $is_admin = ($user['role'] === 'admin' || $user['role'] === 'administrator' || $user['role'] === '1');
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'fullName' => $user['fullName'],
                'email' => $user['email'],
                'role' => $user['role'],
                'is_admin' => $is_admin
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect password']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Email not registered']);
}

$stmt->close();
$conn->close();
?>