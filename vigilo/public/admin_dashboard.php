<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once ('../config/functions.php');

// Start session and check authentication
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit;
}

// Check if user is admin
require_once ('../config/db.php');

// DEBUG: First check if the users table exists
$table_check = $conn->query("SHOW TABLES LIKE 'users'");
if (!$table_check || $table_check->num_rows === 0) {
    die("Error: Users table doesn't exist in the database.");
}

$user_id = $_SESSION['user_id'];

// Check the actual column names in users table
$column_check = $conn->query("DESCRIBE users");
$columns = [];
while ($row = $column_check->fetch_assoc()) {
    $columns[] = $row['Field'];
}

echo "<!-- DEBUG: Available columns: " . implode(', ', $columns) . " -->";

// Determine which columns we can select based on what exists
$select_columns = [];
if (in_array('email', $columns)) $select_columns[] = 'email';
if (in_array('full_name', $columns)) $select_columns[] = 'full_name';
elseif (in_array('name', $columns)) $select_columns[] = 'name AS full_name';
elseif (in_array('username', $columns)) $select_columns[] = 'username AS full_name';

if (in_array('is_admin', $columns)) $select_columns[] = 'is_admin';
elseif (in_array('admin', $columns)) $select_columns[] = 'admin AS is_admin';
elseif (in_array('role', $columns)) $select_columns[] = 'role AS is_admin';
else {
    // If no admin column exists, assume all users are not admin or add a default
    $select_columns[] = '0 AS is_admin';
}

// If no columns were found, use a default selection
if (empty($select_columns)) {
    $select_columns = ['*'];
}

$sql = "SELECT " . implode(', ', $select_columns) . " FROM users WHERE id = ?";
echo "<!-- DEBUG: SQL Query: " . htmlspecialchars($sql) . " -->";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Error preparing SQL: " . htmlspecialchars($conn->error) . "<br>SQL: " . htmlspecialchars($sql));
}

// Now bind the parameter
$stmt->bind_param("i", $user_id);

if (!$stmt->execute()) {
    die("Error executing SQL: " . $stmt->error);
}

$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Debug output
echo "<!-- DEBUG: User data: " . print_r($user, true) . " -->";

// Check if user exists and is admin
// Since we might not have an is_admin column, we'll handle it differently
$is_admin = false;
if ($user) {
    // Check if we have an is_admin field (could be from column alias)
    if (isset($user['is_admin'])) {
        $is_admin = ($user['is_admin'] == 1 || $user['is_admin'] === true || strtolower($user['is_admin']) === 'admin');
    } else {
        // If no admin column exists, check for specific emails or IDs
        $admin_emails = ['admin@vigilo.com', 'admin@example.com'];
        $admin_ids = [1]; // User ID 1 is often admin
        $user_email = $user['email'] ?? '';
        
        $is_admin = in_array($user_email, $admin_emails) || in_array($user_id, $admin_ids);
    }
}

if (!$user || !$is_admin) {
    // For development, you might want to see why
    echo "<!-- DEBUG: User check failed. User exists: " . ($user ? 'Yes' : 'No') . ", Is admin: " . ($is_admin ? 'Yes' : 'No') . " -->";
    header("Location: login.html");
    exit;
}

// Build auth array with available data
$auth = [
    'id' => $user_id,
    'email' => $user['email'] ?? $_SESSION['email'] ?? 'admin@vigilo.com',
    'full_name' => $user['full_name'] ?? $user['name'] ?? $user['username'] ?? $_SESSION['full_name'] ?? 'Admin User',
    'is_admin' => true
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Vigilo</title>
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <div class="brand-logo">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="brand-text">
                        <h2>Vigilo</h2>
                        <span>Admin Panel</span>
                    </div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <h3>Main</h3>
                    <ul class="nav-links">
                        <li><a href="admin_dashboard.php" class="active">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span>Dashboard</span>
                        </a></li>
                        <li><a href="documents.php">
                            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                            <span>All Documents</span>
                        </a></li>
                        <li><a href="trash.php">
                            <span class="nav-icon"><i class="fas fa-trash"></i></span>
                            <span>Trash</span>
                            <span class="nav-badge" id="trash-badge">0</span>
                        </a></li>
                    </ul>
                </div>

                <div class="nav-section">
                    <h3>Management</h3>
                    <ul class="nav-links">
                        <li><a href="user_management.php">
                            <span class="nav-icon"><i class="fas fa-users"></i></span>
                            <span>Users</span>
                            <span class="nav-badge" id="users-badge">0</span>
                        </a></li>
                        <li><a href="verification_queue.php">
                            <span class="nav-icon"><i class="fas fa-check-circle"></i></span>
                            <span>Verification</span>
                            <span class="nav-badge status-pending" id="pending-badge">0</span>
                        </a></li>
                        <li><a href="shared_links.php">
                            <span class="nav-icon"><i class="fas fa-link"></i></span>
                            <span>Shared Links</span>
                            <span class="nav-badge" id="links-badge">0</span>
                        </a></li>
                    </ul>
                </div>

                <div class="nav-section">
                    <h3>System</h3>
                    <ul class="nav-links">
                        <li><a href="system_logs.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span>System Logs</span>
                        </a></li>
                        <li><a href="settings.php">
                            <span class="nav-icon"><i class="fas fa-cog"></i></span>
                            <span>Settings</span>
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
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="page-title">
                    <h1>Admin Dashboard</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($auth['full_name'] ?? $auth['email']); ?>!</p>
                </div>
                
                <div class="top-actions">
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" placeholder="Search users, documents, logs..." id="global-search">
                    </div>
                    
                    <div class="user-menu">
                        <div class="avatar">
                            <?php echo strtoupper(substr($auth['full_name'] ?? $auth['email'], 0, 1)); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="stat_users">0</div>
                            <div class="stat-label">Total Users</div>
                        </div>
                        <div class="stat-icon users">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-trend trend-up">
                        <i class="fas fa-arrow-up"></i>
                        <span>Loading...</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="stat_docs">0</div>
                            <div class="stat-label">Total Documents</div>
                        </div>
                        <div class="stat-icon documents">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                    <div class="stat-trend trend-up">
                        <i class="fas fa-arrow-up"></i>
                        <span>Loading...</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="stat_deleted">0</div>
                            <div class="stat-label">Deleted Documents</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-trash"></i>
                        </div>
                    </div>
                    <div class="stat-trend trend-down">
                        <i class="fas fa-arrow-down"></i>
                        <span>Loading...</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="stat_links">0</div>
                            <div class="stat-label">Active Shared Links</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-link"></i>
                        </div>
                    </div>
                    <div class="stat-trend trend-up">
                        <i class="fas fa-arrow-up"></i>
                        <span>Loading...</span>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Table -->
            <div class="data-table-card">
                <div class="table-header">
                    <h3>Recent Activity</h3>
                    <div class="table-actions">
                        <button class="btn btn-secondary btn-sm" onclick="loadRecentLogs()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        <a href="system_logs.php" class="btn btn-primary btn-sm">View All Logs</a>
                    </div>
                </div>
                
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody id="recent_logs">
                            <!-- Will be populated by JavaScript -->
                            <tr id="loading-row">
                                <td colspan="4" style="text-align: center; padding: 2rem;">
                                    <i class="fas fa-spinner fa-spin" style="margin-right: 10px;"></i>
                                    Loading activity logs...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

   <script src="js/admin.js"></script>
<script>
    // Enhanced JavaScript that works with real data
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize your existing functions
        if (typeof loadStats === 'function') loadStats();
        if (typeof loadRecentLogs === 'function') loadRecentLogs();
        
        // Add mobile menu toggle for responsive design
        if (window.innerWidth < 1024) {
            const sidebar = document.querySelector('.admin-sidebar');
            const toggleBtn = document.createElement('button');
            toggleBtn.className = 'mobile-menu-toggle';
            toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
            toggleBtn.onclick = () => sidebar.classList.toggle('active');
            document.body.appendChild(toggleBtn);
        }
        
        // Add search functionality
        const searchInput = document.getElementById('global-search');
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const query = this.value.trim();
                    if (query) {
                        performSearch(query);
                    }
                }
            });
        }
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            if (typeof loadStats === 'function') loadStats();
        }, 30000);
    });
    
    // Enhanced loadStats function
    function loadStats() {
        fetch("../api/admin/stats.php")
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Update main stats
                    document.getElementById("stat_users").innerText = data.users || 0;
                    document.getElementById("stat_docs").innerText = data.documents || 0;
                    document.getElementById("stat_deleted").innerText = data.deleted || 0;
                    document.getElementById("stat_links").innerText = data.links || 0;
                    
                    // Update sidebar badges
                    const trashBadge = document.getElementById("trash-badge");
                    const usersBadge = document.getElementById("users-badge");
                    const linksBadge = document.getElementById("links-badge");
                    const pendingBadge = document.getElementById("pending-badge");
                    
                    if (trashBadge) trashBadge.innerText = data.deleted || 0;
                    if (usersBadge) usersBadge.innerText = data.users || 0;
                    if (linksBadge) linksBadge.innerText = data.links || 0;
                    if (pendingBadge) pendingBadge.innerText = data.pending || 0;
                    
                    // Update trend text with real data
                    updateTrendTexts(data);
                } else {
                    console.error("Failed to load stats:", data.error);
                    setDefaultStats();
                }
            })
            .catch(error => {
                console.error("Error loading stats:", error);
                setDefaultStats();
            });
    }
    
    function setDefaultStats() {
        document.getElementById("stat_users").innerText = "0";
        document.getElementById("stat_docs").innerText = "0";
        document.getElementById("stat_deleted").innerText = "0";
        document.getElementById("stat_links").innerText = "0";
        
        const badges = ['trash-badge', 'users-badge', 'links-badge', 'pending-badge'];
        badges.forEach(id => {
            const badge = document.getElementById(id);
            if (badge) badge.innerText = "0";
        });
        
        updateTrendTexts(null);
    }
    
    // Enhanced loadRecentLogs function
    function loadRecentLogs() {
        // Show loading state
        const tbody = document.getElementById("recent_logs");
        tbody.innerHTML = `
            <tr id="loading-row">
                <td colspan="4" style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="margin-right: 10px;"></i>
                    Loading activity logs...
                </td>
            </tr>
        `;
        
        fetch("../api/admin/recent_logs.php")
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    tbody.innerHTML = "";
                    
                    if (data.logs && data.logs.length > 0) {
                        data.logs.forEach(log => {
                            // Determine status badge class
                            let statusClass = 'status-review';
                            if (log.action.toLowerCase().includes('delete')) statusClass = 'status-rejected';
                            if (log.action.toLowerCase().includes('upload')) statusClass = 'status-pending';
                            if (log.action.toLowerCase().includes('verify') || log.action.toLowerCase().includes('approve')) statusClass = 'status-verified';
                            if (log.action.toLowerCase().includes('login')) statusClass = 'status-review';
                            
                            // Get user initial
                            const userInitial = log.user ? log.user.charAt(0).toUpperCase() : '?';
                            
                            tbody.innerHTML += `
                                <tr>
                                    <td>
                                        <div class="d-flex align-center gap-1">
                                            <div class="avatar" style="width: 30px; height: 30px; font-size: 12px;">
                                                ${userInitial}
                                            </div>
                                            <div>
                                                <div>${log.user || 'System'}</div>
                                                <div class="text-muted" style="font-size: 12px;">${log.email || 'System Activity'}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge ${statusClass}">
                                            ${log.action || 'Unknown Action'}
                                        </span>
                                    </td>
                                    <td style="max-width: 250px; word-wrap: break-word;">
                                        ${log.details || 'No details available'}
                                    </td>
                                    <td>
                                        ${log.time || 'Unknown time'}
                                    </td>
                                </tr>
                            `;
                        });
                    } else {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 2rem;">
                                    <i class="fas fa-info-circle" style="font-size: 1.5rem; margin-bottom: 1rem; color: var(--text-secondary); display: block;"></i>
                                    No recent activity found
                                </td>
                            </tr>
                        `;
                    }
                } else {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 2rem; color: var(--danger-color);">
                                <i class="fas fa-exclamation-circle" style="font-size: 1.5rem; margin-bottom: 1rem; display: block;"></i>
                                Failed to load activity logs
                            </td>
                        </tr>
                    `;
                }
            })
            .catch(error => {
                console.error('Error loading logs:', error);
                tbody.innerHTML = `
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 2rem; color: var(--danger-color);">
                            <i class="fas fa-exclamation-circle" style="font-size: 1.5rem; margin-bottom: 1rem; display: block;"></i>
                            Error loading activity logs
                        </td>
                    </tr>
                `;
            });
    }
    
    function updateTrendTexts(data) {
        const trends = document.querySelectorAll('.stat-trend span');
        
        if (data && data.trends) {
            // Update with real trend data
            if (trends[0]) {
                trends[0].innerHTML = data.trends.users > 0 ? 
                    `<i class="fas fa-arrow-up"></i> +${Math.abs(data.trends.users)}% from yesterday` :
                    `<i class="fas fa-arrow-down"></i> ${Math.abs(data.trends.users)}% from yesterday`;
                trends[0].parentElement.className = data.trends.users >= 0 ? 'stat-trend trend-up' : 'stat-trend trend-down';
            }
            
            if (trends[1]) {
                trends[1].innerHTML = data.trends.uploads > 0 ? 
                    `<i class="fas fa-arrow-up"></i> +${Math.abs(data.trends.uploads)}% from yesterday` :
                    `<i class="fas fa-arrow-down"></i> ${Math.abs(data.trends.uploads)}% from yesterday`;
                trends[1].parentElement.className = data.trends.uploads >= 0 ? 'stat-trend trend-up' : 'stat-trend trend-down';
            }
        } else {
            // Default placeholder text
            trends.forEach(trend => {
                trend.innerHTML = '<i class="fas fa-chart-line"></i> No trend data';
            });
        }
    }
    
    function performSearch(query) {
        if (!query.trim()) return;
        
        // Show loading state
        const searchIcon = document.querySelector('.search-icon');
        const originalIcon = searchIcon.innerHTML;
        searchIcon.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        // Search API call
        fetch(`../api/admin/search.php?q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showSearchResults(data.results);
                } else {
                    alert('Search failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Search error:', error);
                alert('Search failed. Please try again.');
            })
            .finally(() => {
                searchIcon.innerHTML = originalIcon;
            });
    }
    
    function showSearchResults(results) {
        // Create a modal to show search results
        const modal = document.createElement('div');
        modal.className = 'modal-overlay active';
        modal.innerHTML = `
            <div class="modal">
                <div class="modal-header">
                    <h3>Search Results</h3>
                    <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
                </div>
                <div class="modal-body">
                    ${results.length > 0 ? 
                        results.map(r => `
                            <div class="search-result-item">
                                <h4>${r.title}</h4>
                                <p>${r.description}</p>
                                <small>Type: ${r.type} | Date: ${r.date}</small>
                            </div>
                        `).join('') : 
                        '<p>No results found</p>'
                    }
                </div>
            </div>
        `;
        
        // Add some styles
        const style = document.createElement('style');
        style.textContent = `
            .search-result-item {
                padding: 10px;
                border-bottom: 1px solid var(--border-color);
                margin-bottom: 10px;
            }
            .search-result-item:last-child {
                border-bottom: none;
            }
        `;
        
        document.head.appendChild(style);
        document.body.appendChild(modal);
    }
</script>
</body>
</html>
