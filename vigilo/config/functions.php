<?php
// config/functions.php

// File size functions
function human_filesize($bytes, $decimals = 2) {
    $sz = ['B','KB','MB','GB','TB'];
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $sz[$factor];
}

function get_user_storage_usage($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(file_size),0) as total FROM documents WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($r['total']);
}

function update_user_storage_used($conn, $user_id) {
    $used = get_user_storage_usage($conn, $user_id);
    $stmt = $conn->prepare("UPDATE users SET storage_used = ? WHERE id = ?");
    $stmt->bind_param("ii", $used, $user_id);
    $stmt->execute();
    $stmt->close();
    return $used;
}

function can_user_upload($conn, $user_id, $newFileSize) {
    $stmt = $conn->prepare("SELECT storage_limit, storage_used FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$r) return false;
    $limit = intval($r['storage_limit']);
    $used = intval($r['storage_used']);
    return ($used + $newFileSize) <= $limit;
}

// NEW FUNCTIONS FOR ADMIN DASHBOARD & AUTHENTICATION

// Authentication function
function authenticate() {
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    require_once 'db.php';
    global $conn;
    
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT id, email, full_name, is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'is_admin' => $user['is_admin'] == 1
        ];
    }
    
    return false;
}

// Get database connection (for API files)
function getConnection() {
    require_once 'db.php';
    global $conn;
    return $conn;
}

// Admin helper functions
function is_admin($conn, $user_id) {
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user && $user['is_admin'] == 1;
}

// Get admin stats
function get_admin_stats($conn) {
    $stats = [];
    
    // Total Users
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    $row = $result->fetch_assoc();
    $stats['total_users'] = (int)$row['count'];
    
    // Total Documents (not deleted)
    $result = $conn->query("SELECT COUNT(*) as count FROM documents WHERE deleted_at IS NULL");
    $row = $result->fetch_assoc();
    $stats['total_documents'] = (int)$row['count'];
    
    // Deleted Documents
    $result = $conn->query("SELECT COUNT(*) as count FROM documents WHERE deleted_at IS NOT NULL");
    $row = $result->fetch_assoc();
    $stats['deleted_documents'] = (int)$row['count'];
    
    // Pending Verifications
    $result = $conn->query("SELECT COUNT(*) as count FROM documents WHERE verification_status = 'pending' AND deleted_at IS NULL");
    $row = $result->fetch_assoc();
    $stats['pending_verifications'] = (int)$row['count'];
    
    // Today's date
    $today = date('Y-m-d');
    
    // Today's New Users
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = ?");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['today_users'] = (int)$row['count'];
    $stmt->close();
    
    // Today's Uploads
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM documents WHERE DATE(created_at) = ? AND deleted_at IS NULL");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['today_uploads'] = (int)$row['count'];
    $stmt->close();
    
    // Active Shared Links (check if table exists)
    $result = $conn->query("SHOW TABLES LIKE 'document_shares'");
    if ($result->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as count FROM document_shares WHERE (expires_at > NOW() OR expires_at IS NULL) AND is_active = 1");
        $row = $result->fetch_assoc();
        $stats['active_links'] = (int)$row['count'];
    } else {
        $stats['active_links'] = 0;
    }
    
    return $stats;
}

// Get recent activity
function get_recent_activity($conn, $limit = 10) {
    $activity = [];
    
    // Check if audit_logs table exists
    $result = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT al.*, u.email, u.full_name 
            FROM audit_logs al 
            LEFT JOIN users u ON al.user_id = u.id 
            ORDER BY al.created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $activity[] = [
                'user' => $row['full_name'] ?: $row['email'] ?: 'System',
                'email' => $row['email'],
                'action' => ucfirst($row['action']),
                'details' => $row['details'],
                'time' => time_ago(strtotime($row['created_at']))
            ];
        }
        $stmt->close();
    } else {
        // Fallback: Get from documents and users
        $stmt = $conn->prepare("
            SELECT d.*, u.email, u.full_name 
            FROM documents d 
            LEFT JOIN users u ON d.user_id = u.id 
            WHERE d.deleted_at IS NULL 
            ORDER BY d.created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $activity[] = [
                'user' => $row['full_name'] ?: $row['email'],
                'email' => $row['email'],
                'action' => 'Upload',
                'details' => 'Uploaded: ' . $row['original_name'],
                'time' => time_ago(strtotime($row['created_at']))
            ];
        }
        $stmt->close();
    }
    
    return $activity;
}

// Time ago helper
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

// JSON response helper
function json_response($data, $status = 200) {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Check if user is logged in
function require_login() {
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../public/login.html');
        exit;
    }
}

// Check if user is admin
function require_admin($conn) {
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../public/login.html');
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user || !$user['is_admin']) {
        header('Location: ../public/login.html');
        exit;
    }
}

// Sanitize input
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Generate random token
function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

// Log activity
function log_activity($conn, $user_id, $action, $details = '', $status = 'success') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Check if audit_logs table exists
    $result = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isssss", $user_id, $action, $details, $ip_address, $user_agent, $status);
        $stmt->execute();
        $stmt->close();
    }
}
?>