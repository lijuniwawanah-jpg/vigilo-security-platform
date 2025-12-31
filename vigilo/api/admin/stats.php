<?php
require_once "../../config/functions.php";
require_once "../../config/db.php";

header('Content-Type: application/json');

// Start session and check if user is logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if user is admin
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Check if user has admin role
$role = strtolower($user['role'] ?? '');
$is_admin = ($role === 'admin' || $role === 'administrator' || $role === '1');

if (!$is_admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden - Admin only']);
    exit;
}

// Get stats
$stats = [];

try {
    // 1. Total Users
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['users'] = (int)$row['count'];
    } else {
        $stats['users'] = 0;
    }
    
    // 2. Total Documents (active documents in documents table)
    $result = $conn->query("SELECT COUNT(*) as count FROM documents");
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['documents'] = (int)$row['count'];
    } else {
        $stats['documents'] = 0;
    }
    
    // 3. Deleted Documents (from deleted_documents table)
    $table_check = $conn->query("SHOW TABLES LIKE 'deleted_documents'");
    if ($table_check && $table_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as count FROM deleted_documents");
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['deleted'] = (int)$row['count'];
        } else {
            $stats['deleted'] = 0;
        }
    } else {
        $stats['deleted'] = 0;
    }
    
    // 4. Pending Verifications (from verifications table)
    $table_check = $conn->query("SHOW TABLES LIKE 'verifications'");
    if ($table_check && $table_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as count FROM verifications WHERE status = 'pending'");
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['pending'] = (int)$row['count'];
        } else {
            $stats['pending'] = 0;
        }
    } else {
        $stats['pending'] = 0;
    }
    
    // 5. Active Shared Links (from shared_links table)
    $table_check = $conn->query("SHOW TABLES LIKE 'shared_links'");
    if ($table_check && $table_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as count FROM shared_links WHERE is_active = 1 AND (expires_at > NOW() OR expires_at IS NULL)");
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['links'] = (int)$row['count'];
        } else {
            $stats['links'] = 0;
        }
    } else {
        // Fallback to document_shares table if exists
        $table_check = $conn->query("SHOW TABLES LIKE 'document_shares'");
        if ($table_check && $table_check->num_rows > 0) {
            $result = $conn->query("SELECT COUNT(*) as count FROM document_shares WHERE is_active = 1 AND (expires_at > NOW() OR expires_at IS NULL)");
            if ($result) {
                $row = $result->fetch_assoc();
                $stats['links'] = (int)$row['count'];
            } else {
                $stats['links'] = 0;
            }
        } else {
            $stats['links'] = 0;
        }
    }
    
    // Additional stats that might be useful
    $stats['total_storage'] = 0;
    $stats['today_uploads'] = 0;
    $stats['today_users'] = 0;
    
    // Get today's uploads
    $today = date('Y-m-d');
    $result = $conn->query("SELECT COUNT(*) as count FROM documents WHERE DATE(created_at) = '$today'");
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['today_uploads'] = (int)$row['count'];
    }
    
    // Get today's new users
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = '$today'");
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['today_users'] = (int)$row['count'];
    }
    
    echo json_encode([
        'success' => true,
        'users' => $stats['users'],
        'documents' => $stats['documents'],
        'deleted' => $stats['deleted'],
        'pending' => $stats['pending'],
        'links' => $stats['links'],
        'today_uploads' => $stats['today_uploads'],
        'today_users' => $stats['today_users'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage(),
        'users' => 0,
        'documents' => 0,
        'deleted' => 0,
        'pending' => 0,
        'links' => 0
    ]);
}

$conn->close();
?>