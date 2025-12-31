<?php
session_start();
require_once('../config/db.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user info from database
$user_id = $_SESSION['user_id'];

// First, check if users table exists and has required columns
$table_check = $conn->query("SHOW TABLES LIKE 'users'");
if (!$table_check || $table_check->num_rows === 0) {
    die("Error: Users table doesn't exist in the database.");
}

// Check columns in users table
$column_check = $conn->query("DESCRIBE users");
$columns = [];
while ($row = $column_check->fetch_assoc()) {
    $columns[] = $row['Field'];
}

// Build SQL query based on actual columns
$select_columns = ['id', 'email'];
if (in_array('fullName', $columns)) $select_columns[] = 'fullName';
if (in_array('full_name', $columns)) $select_columns[] = 'full_name AS fullName';
if (in_array('role', $columns)) $select_columns[] = 'role';
if (in_array('storage_limit', $columns)) $select_columns[] = 'storage_limit';
if (in_array('storage_used', $columns)) $select_columns[] = 'storage_used';
if (in_array('created_at', $columns)) $select_columns[] = 'created_at';

$sql = "SELECT " . implode(', ', $select_columns) . " FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Error preparing SQL: " . $conn->error . "<br>SQL: " . htmlspecialchars($sql));
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Get user stats
$stats = [];

// Check if documents table exists
$table_check = $conn->query("SHOW TABLES LIKE 'documents'");
if ($table_check && $table_check->num_rows > 0) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM documents WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_documents'] = (int)$row['count'];
        $stmt->close();
    } else {
        $stats['total_documents'] = 0;
    }
} else {
    $stats['total_documents'] = 0;
}

// Check if shared_links table exists
$table_check = $conn->query("SHOW TABLES LIKE 'shared_links'");
if ($table_check && $table_check->num_rows > 0) {
    // Check if shared_links has user_id column
    $column_check = $conn->query("SHOW COLUMNS FROM shared_links LIKE 'user_id'");
    if ($column_check && $column_check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM shared_links WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stats['shared_links'] = (int)$row['count'];
            $stmt->close();
        } else {
            $stats['shared_links'] = 0;
        }
    } else {
        // If no user_id column, count all links
        $result = $conn->query("SELECT COUNT(*) as count FROM shared_links");
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['shared_links'] = (int)$row['count'];
        } else {
            $stats['shared_links'] = 0;
        }
    }
} else {
    $stats['shared_links'] = 0;
}

// Storage usage
$stats['storage_used'] = (int)($user['storage_used'] ?? 0);
$stats['storage_limit'] = (int)($user['storage_limit'] ?? 1073741824); // Default 1GB
$stats['storage_percentage'] = $stats['storage_limit'] > 0 ? round(($stats['storage_used'] / $stats['storage_limit']) * 100) : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Vigilo</title>
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .user-dashboard .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .user-dashboard .stat-card:nth-child(2) {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .user-dashboard .stat-card:nth-child(3) {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .user-dashboard .stat-card:nth-child(4) {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .storage-bar {
            height: 10px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 5px;
            margin-top: 10px;
            overflow: hidden;
        }
        
        .storage-fill {
            height: 100%;
            background: white;
            border-radius: 5px;
            transition: width 0.3s ease;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .quick-action {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: var(--text-primary);
        }
        
        .quick-action:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-color);
        }
        
        .quick-action i {
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        
        .quick-action h3 {
            margin-bottom: 10px;
            font-size: 1.2rem;
        }
        
        .recent-uploads {
            margin-top: 30px;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 30px;
            color: white;
        }
        
        .welcome-banner h1 {
            margin-bottom: 10px;
            font-size: 2rem;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            margin-bottom: 20px;
        }
        
        .user-role-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-left: 10px;
        }
        
        .avatar.large {
            width: 60px;
            height: 60px;
            font-size: 24px;
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        
        /* Ensure text is white in stat cards */
        .user-dashboard .stat-card .stat-value,
        .user-dashboard .stat-card .stat-label,
        .user-dashboard .stat-card .stat-trend {
            color: white !important;
        }
        
        .user-dashboard .stat-card .stat-trend i {
            color: rgba(255, 255, 255, 0.8) !important;
        }
    </style>
</head>
<body>
    <div class="admin-container user-dashboard">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <div class="brand-logo">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="brand-text">
                        <h2>Vigilo</h2>
                        <span>User Dashboard</span>
                    </div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <h3>Main</h3>
                    <ul class="nav-links">
                        <li><a href="dashboard.php" class="active">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span>Dashboard</span>
                        </a></li>
                        <li><a href="upload.php">
                            <span class="nav-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                            <span>Upload</span>
                        </a></li>
                        <li><a href="documents.php">
                            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                            <span>Documents</span>
                            <span class="nav-badge"><?php echo $stats['total_documents']; ?></span>
                        </a></li>
                        <li><a href="shared_links.php">
                            <span class="nav-icon"><i class="fas fa-link"></i></span>
                            <span>Shared Links</span>
                            <span class="nav-badge"><?php echo $stats['shared_links']; ?></span>
                        </a></li>
                    </ul>
                </div>

                <div class="nav-section">
                    <h3>Account</h3>
                    <ul class="nav-links">
                        <li><a href="profile.php">
                            <span class="nav-icon"><i class="fas fa-user-circle"></i></span>
                            <span>Profile</span>
                        </a></li>
                        <li><a href="settings.php">
                            <span class="nav-icon"><i class="fas fa-cog"></i></span>
                            <span>Settings</span>
                        </a></li>
                        <li><a href="verifier.php">
                            <span class="nav-icon"><i class="fas fa-check-circle"></i></span>
                            <span>Document Verifier</span>
                        </a></li>
                    </ul>
                </div>

                <div class="nav-section">
                    <h3>System</h3>
                    <ul class="nav-links">
                        <li><a href="help.php">
                            <span class="nav-icon"><i class="fas fa-question-circle"></i></span>
                            <span>Help & Support</span>
                        </a></li>
                        <li><a href="../auth/logout.php">
                            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                            <span>Logout</span>
                        </a></li>
                    </ul>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="d-flex align-center justify-between">
                    <div>
                        <h1>Welcome back, <?php echo htmlspecialchars($user['fullName'] ?? $user['email']); ?>!</h1>
                        <p>Your secure document management dashboard</p>
                        <span class="user-role-badge"><?php echo htmlspecialchars(ucfirst($user['role'] ?? 'user')); ?></span>
                    </div>
                    <div class="avatar large">
                        <?php echo strtoupper(substr($user['fullName'] ?? $user['email'], 0, 1)); ?>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo $stats['total_documents']; ?></div>
                            <div class="stat-label">Total Documents</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                    <div class="stat-trend trend-up">
                        <i class="fas fa-arrow-up"></i>
                        <span>Your uploaded files</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo $stats['shared_links']; ?></div>
                            <div class="stat-label">Shared Links</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-link"></i>
                        </div>
                    </div>
                    <div class="stat-trend trend-up">
                        <i class="fas fa-external-link-alt"></i>
                        <span>Active shares</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo formatBytes($stats['storage_used']); ?></div>
                            <div class="stat-label">Storage Used</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-database"></i>
                        </div>
                    </div>
                    <div class="storage-bar">
                        <div class="storage-fill" style="width: <?php echo min($stats['storage_percentage'], 100); ?>%"></div>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-hdd"></i>
                        <span><?php echo $stats['storage_percentage']; ?>% of <?php echo formatBytes($stats['storage_limit']); ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php 
                                if (isset($user['created_at'])) {
                                    echo date('M Y', strtotime($user['created_at']));
                                } else {
                                    echo 'N/A';
                                }
                            ?></div>
                            <div class="stat-label">Member Since</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-user-clock"></i>
                        <span>Account created</span>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="upload.php" class="quick-action">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <h3>Upload Document</h3>
                    <p>Upload new files securely</p>
                </a>
                
                <a href="documents.php" class="quick-action">
                    <i class="fas fa-folder-open"></i>
                    <h3>View Documents</h3>
                    <p>Manage all your files</p>
                </a>
                
                <a href="shared_links.php" class="quick-action">
                    <i class="fas fa-share-alt"></i>
                    <h3>Share Documents</h3>
                    <p>Create shareable links</p>
                </a>
                
                <a href="verifier.php" class="quick-action">
                    <i class="fas fa-check-circle"></i>
                    <h3>Verify Document</h3>
                    <p>Check document authenticity</p>
                </a>
            </div>

            <!-- Recent Uploads Section -->
            <div class="data-table-card recent-uploads">
                <div class="table-header">
                    <h3>Recent Documents</h3>
                    <div class="table-actions">
                        <a href="documents.php" class="btn btn-primary btn-sm">View All</a>
                    </div>
                </div>
                
                <div class="table-container">
                    <?php
                    // Get recent documents if documents table exists
                    if ($stats['total_documents'] > 0) {
                        $conn = new mysqli("127.0.0.1", "root", "", "vigilo_db");
                        
                        // Check columns in documents table
                        $column_check = $conn->query("DESCRIBE documents");
                        $doc_columns = [];
                        while ($row = $column_check->fetch_assoc()) {
                            $doc_columns[] = $row['Field'];
                        }
                        
                        // Build query based on actual columns
                        $doc_select = ['id'];
                        if (in_array('file_name', $doc_columns)) $doc_select[] = 'file_name';
                        if (in_array('original_name', $doc_columns)) $doc_select[] = 'original_name';
                        if (in_array('file_type', $doc_columns)) $doc_select[] = 'file_type';
                        if (in_array('file_size', $doc_columns)) $doc_select[] = 'file_size';
                        if (in_array('created_at', $doc_columns)) $doc_select[] = 'created_at';
                        if (in_array('uploaded_at', $doc_columns)) $doc_select[] = 'uploaded_at';
                        
                        $doc_sql = "SELECT " . implode(', ', $doc_select) . " FROM documents WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
                        $stmt = $conn->prepare($doc_sql);
                        
                        if ($stmt) {
                            $stmt->bind_param("i", $user_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $recent_docs = [];
                            
                            while ($row = $result->fetch_assoc()) {
                                $recent_docs[] = $row;
                            }
                            $stmt->close();
                            
                            if (!empty($recent_docs)): 
                    ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Document Name</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Uploaded</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_docs as $doc): 
                                $doc_name = $doc['original_name'] ?? $doc['file_name'] ?? 'Unknown';
                                $file_ext = pathinfo($doc_name, PATHINFO_EXTENSION);
                                $file_size = $doc['file_size'] ?? 0;
                                $upload_time = $doc['created_at'] ?? $doc['uploaded_at'] ?? '';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($doc_name, 0, 30)); ?><?php if (strlen($doc_name) > 30) echo '...'; ?></td>
                                <td>
                                    <span class="status-badge status-review">
                                        <?php echo strtoupper($file_ext ?: 'FILE'); ?>
                                    </span>
                                </td>
                                <td><?php echo formatBytes($file_size); ?></td>
                                <td><?php echo $upload_time ? time_ago(strtotime($upload_time)) : 'Unknown'; ?></td>
                                <td>
                                    <button class="btn btn-secondary btn-sm" onclick="viewDocument(<?php echo $doc['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php 
                            endif;
                        }
                        $conn->close();
                    } else {
                    ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <h3>No documents uploaded yet</h3>
                        <p>Get started by uploading your first document</p>
                        <a href="upload.php" class="btn btn-primary" style="margin-top: 20px;">
                            <i class="fas fa-cloud-upload-alt"></i> Upload Document
                        </a>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Add mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth < 1024) {
                const sidebar = document.querySelector('.admin-sidebar');
                const toggleBtn = document.createElement('button');
                toggleBtn.className = 'mobile-menu-toggle';
                toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
                toggleBtn.onclick = () => sidebar.classList.toggle('active');
                document.body.appendChild(toggleBtn);
            }
        });
        
        function viewDocument(id) {
            window.open('preview.php?id=' + id, '_blank');
        }
        
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>

<?php
// Helper functions
function formatBytes($bytes, $decimals = 2) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $dm = $decimals < 0 ? 0 : $decimals;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
}

function time_ago($time) {
    if (!$time) return 'Unknown';
    
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