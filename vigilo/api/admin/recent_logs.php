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

// Get recent activity
try {
    // First try to get from audit_logs table
    $table_check = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT al.*, u.email, u.fullName 
            FROM audit_logs al 
            LEFT JOIN users u ON al.user_id = u.id 
            ORDER BY al.created_at DESC 
            LIMIT 10
        ");
        
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $logs = [];
            
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
            $stmt->close();
            
            $formattedLogs = [];
            foreach ($logs as $log) {
                $time = strtotime($log['created_at'] ?? time());
                $formattedLogs[] = [
                    'user' => $log['fullName'] ?? ($log['email'] ?? 'System'),
                    'email' => $log['email'] ?? '',
                    'action' => ucfirst($log['action'] ?? 'Unknown'),
                    'details' => $log['details'] ?? 'No details',
                    'time' => time_ago($time)
                ];
            }
            
            echo json_encode([
                'success' => true,
                'logs' => $formattedLogs
            ]);
            exit;
        }
    }
    
    // If no audit_logs, try to combine recent activity from multiple tables
    $all_activities = [];
    
    // 1. Recent document uploads
    $table_check = $conn->query("SHOW TABLES LIKE 'documents'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT d.*, u.email, u.fullName 
            FROM documents d 
            LEFT JOIN users u ON d.user_id = u.id 
            ORDER BY d.created_at DESC 
            LIMIT 5
        ");
        
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $time = strtotime($row['created_at'] ?? $row['uploaded_at'] ?? time());
                $all_activities[] = [
                    'timestamp' => $time,
                    'data' => [
                        'user' => $row['fullName'] ?? ($row['email'] ?? 'Unknown'),
                        'email' => $row['email'] ?? '',
                        'action' => 'Upload',
                        'details' => 'Uploaded: ' . ($row['file_name'] ?? $row['original_name'] ?? 'Document'),
                        'time' => time_ago($time)
                    ]
                ];
            }
            $stmt->close();
        }
    }
    
    // 2. Recent user registrations
    $stmt = $conn->prepare("
        SELECT email, fullName, created_at 
        FROM users 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $time = strtotime($row['created_at'] ?? time());
            $all_activities[] = [
                'timestamp' => $time,
                'data' => [
                    'user' => $row['fullName'] ?? ($row['email'] ?? 'Unknown'),
                    'email' => $row['email'] ?? '',
                    'action' => 'Registration',
                    'details' => 'New user registered',
                    'time' => time_ago($time)
                ]
            ];
        }
        $stmt->close();
    }
    
    // 3. Recent verifications
    $table_check = $conn->query("SHOW TABLES LIKE 'verifications'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT v.*, u.email, u.fullName 
            FROM verifications v 
            LEFT JOIN users u ON v.verifier_id = u.id 
            ORDER BY v.created_at DESC 
            LIMIT 5
        ");
        
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $time = strtotime($row['created_at'] ?? $row['verified_at'] ?? time());
                $all_activities[] = [
                    'timestamp' => $time,
                    'data' => [
                        'user' => $row['fullName'] ?? ($row['email'] ?? 'System'),
                        'email' => $row['email'] ?? '',
                        'action' => 'Verification',
                        'details' => 'Document verification: ' . ($row['status'] ?? 'unknown'),
                        'time' => time_ago($time)
                    ]
                ];
            }
            $stmt->close();
        }
    }
    
    // Sort all activities by timestamp (newest first)
    usort($all_activities, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Take only top 10
    $formattedLogs = [];
    $count = 0;
    foreach ($all_activities as $activity) {
        if ($count >= 10) break;
        $formattedLogs[] = $activity['data'];
        $count++;
    }
    
    if (count($formattedLogs) > 0) {
        echo json_encode([
            'success' => true,
            'logs' => $formattedLogs
        ]);
    } else {
        // Fallback to sample data
        echo json_encode([
            'success' => true,
            'logs' => [
                [
                    'user' => 'System',
                    'email' => 'system@vigilo.com',
                    'action' => 'System',
                    'details' => 'Admin dashboard loaded',
                    'time' => 'Just now'
                ],
                [
                    'user' => 'Admin',
                    'email' => 'admin@vigilo.com',
                    'action' => 'Login',
                    'details' => 'Logged into admin panel',
                    'time' => '5 minutes ago'
                ]
            ]
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage(),
        'logs' => []
    ]);
}

$conn->close();

function time_ago($time) {
    $time_diff = time() - $time;
    
    if ($time_diff < 60) {
        return 'Just now';
    } elseif ($time_diff < 3600) {
        $mins = floor($time_diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($time_diff < 86400) {
        $hours = floor($time_diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($time_diff < 2592000) {
        $days = floor($time_diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}
?>