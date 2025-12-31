<?php
// Alternative endpoint if you need more detailed activity
require_once "../../config/functions.php";
require_once "../../config/db.php";

header('Content-Type: application/json');

$auth = authenticate();
if (!$auth || !$auth["is_admin"]) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;
$conn = getConnection();

try {
    // Try to get from audit_logs, fallback to combined activity
    $tableExists = $conn->query("SHOW TABLES LIKE 'audit_logs'")->rowCount() > 0;
    
    if ($tableExists) {
        $stmt = $conn->prepare("
            SELECT al.*, u.email, u.full_name 
            FROM audit_logs al 
            LEFT JOIN users u ON al.user_id = u.id 
            ORDER BY al.created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Combine activity from different tables
        $activities = [];
        
        // Get recent document uploads
        $stmt = $conn->prepare("
            SELECT d.*, u.email, u.full_name 
            FROM documents d 
            LEFT JOIN users u ON d.user_id = u.id 
            WHERE d.deleted_at IS NULL 
            ORDER BY d.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute();
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($documents as $doc) {
            $activities[] = [
                'user_id' => $doc['user_id'],
                'full_name' => $doc['full_name'],
                'email' => $doc['email'],
                'action' => 'document_upload',
                'details' => 'Uploaded: ' . $doc['original_name'],
                'created_at' => $doc['created_at']
            ];
        }
        
        // Sort by date
        usort($activities, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Limit
        $activities = array_slice($activities, 0, $limit);
    }
    
    echo json_encode([
        'success' => true,
        'activities' => $activities
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>