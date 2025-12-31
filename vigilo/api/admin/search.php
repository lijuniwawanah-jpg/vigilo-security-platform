<?php
require_once "../../config/functions.php";
require_once "../../config/db.php";

header('Content-Type: application/json');

$auth = authenticate();
if (!$auth || !$auth["is_admin"]) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['q']) || empty(trim($_GET['q']))) {
    echo json_encode(['success' => false, 'error' => 'Search query required']);
    exit;
}

$query = trim($_GET['q']);
$conn = getConnection();

try {
    $results = [];
    
    // Search users
    $stmt = $conn->prepare("
        SELECT id, email, full_name, created_at 
        FROM users 
        WHERE email LIKE ? OR full_name LIKE ?
        LIMIT 5
    ");
    $searchTerm = "%$query%";
    $stmt->execute([$searchTerm, $searchTerm]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        $results[] = [
            'type' => 'user',
            'id' => $user['id'],
            'title' => $user['full_name'] ?: $user['email'],
            'description' => $user['email'],
            'date' => $user['created_at'],
            'url' => '../user_management.php?user_id=' . $user['id']
        ];
    }
    
    // Search documents
    $stmt = $conn->prepare("
        SELECT d.id, d.filename, d.original_name, d.created_at, u.email 
        FROM documents d
        LEFT JOIN users u ON d.user_id = u.id
        WHERE d.original_name LIKE ? OR d.filename LIKE ?
        AND d.deleted_at IS NULL
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($documents as $doc) {
        $results[] = [
            'type' => 'document',
            'id' => $doc['id'],
            'title' => $doc['original_name'],
            'description' => 'Uploaded by: ' . $doc['email'],
            'date' => $doc['created_at'],
            'url' => '../documents.php?doc_id=' . $doc['id']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results)
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>