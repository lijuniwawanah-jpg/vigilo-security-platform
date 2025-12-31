<?php
session_start();
require_once('../config/db.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Get user info for display
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT fullName, email FROM users WHERE id = ?");
if (!$stmt) {
    die("SQL ERROR: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// First, check what columns exist in the documents table
$column_check = $conn->query("DESCRIBE documents");
$columns = [];
if ($column_check) {
    while ($row = $column_check->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
}

// Build query based on available columns
$select_fields = ['id', 'file_name']; // Always include these
if (in_array('uploaded_at', $columns)) $select_fields[] = 'uploaded_at';
if (in_array('created_at', $columns)) $select_fields[] = 'created_at';
if (in_array('file_size', $columns)) $select_fields[] = 'file_size';
if (in_array('file_type', $columns)) $select_fields[] = 'file_type';
if (in_array('original_name', $columns)) $select_fields[] = 'original_name';

$sql = "SELECT " . implode(', ', $select_fields) . " FROM documents WHERE user_id = ? ORDER BY uploaded_at DESC";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("SQL ERROR: " . $conn->error . "<br>Query: " . htmlspecialchars($sql));
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$documents = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get document stats
$total_documents = count($documents);
$total_size = 0;

foreach ($documents as $doc) {
    if (isset($doc['file_size'])) {
        $total_size += (int)$doc['file_size'];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Documents - Vigilo</title>
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .documents-page .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .documents-page .stat-card:nth-child(2) {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .documents-page .stat-card:nth-child(3) {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .documents-page .stat-card .stat-value,
        .documents-page .stat-card .stat-label,
        .documents-page .stat-card .stat-trend {
            color: white !important;
        }
        
        .file-icon {
            width: 40px;
            height: 40px;
            background: var(--card-bg);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            border: 1px solid var(--border-color);
        }
        
        .file-icon.pdf { color: #e74c3c; }
        .file-icon.image { color: #3498db; }
        .file-icon.word { color: #2e86c1; }
        .file-icon.excel { color: #27ae60; }
        .file-icon.default { color: var(--text-secondary); }
        
        .doc-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .file-info {
            display: flex;
            align-items: center;
        }
        
        .file-details {
            display: flex;
            flex-direction: column;
        }
        
        .file-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .file-meta {
            font-size: 0.85rem;
            color: var(--text-secondary);
            display: flex;
            gap: 10px;
            margin-top: 4px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--text-secondary);
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--text-primary);
        }
        
        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 30px;
        }
        
        .search-box-full {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 30px;
        }
        
        .search-box-full .search-input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            color: var(--text-primary);
            font-size: 1rem;
        }
        
        .search-box-full .search-icon {
            position: absolute;
            left: 30px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }
        
        .upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-color);
            color: white;
            padding: 12px 24px;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .upload-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .status-badge.available {
            background: rgba(6, 214, 160, 0.1);
            color: var(--success-color);
        }
    </style>
</head>
<body>
    <div class="admin-container documents-page">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <div class="brand-logo">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <div class="brand-text">
                        <h2>Vigilo</h2>
                        <span>Documents</span>
                    </div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <h3>Navigation</h3>
                    <ul class="nav-links">
                        <li><a href="dashboard.php">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span>Dashboard</span>
                        </a></li>
                        <li><a href="upload.php">
                            <span class="nav-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                            <span>Upload</span>
                        </a></li>
                        <li><a href="documents.php" class="active">
                            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                            <span>Documents</span>
                            <span class="nav-badge"><?php echo $total_documents; ?></span>
                        </a></li>
                        <li><a href="shared_links.php">
                            <span class="nav-icon"><i class="fas fa-link"></i></span>
                            <span>Shared Links</span>
                        </a></li>
                    </ul>
                </div>

                <div class="nav-section">
                    <h3>Tools</h3>
                    <ul class="nav-links">
                        <li><a href="verifier.php">
                            <span class="nav-icon"><i class="fas fa-check-circle"></i></span>
                            <span>Verifier</span>
                        </a></li>
                        <li><a href="trash.php">
                            <span class="nav-icon"><i class="fas fa-trash"></i></span>
                            <span>Trash</span>
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
                    <h1>My Documents</h1>
                    <p>Manage all your uploaded files and documents</p>
                </div>
                
                <div class="top-actions">
                    <a href="upload.php" class="upload-btn">
                        <i class="fas fa-plus"></i>
                        Upload New
                    </a>
                    
                    <div class="user-menu">
                        <div class="avatar">
                            <?php echo strtoupper(substr($user['fullName'] ?? $user['email'], 0, 1)); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo $total_documents; ?></div>
                            <div class="stat-label">Total Documents</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                    <div class="stat-trend trend-up">
                        <i class="fas fa-folder-open"></i>
                        <span>All your files</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo formatBytes($total_size); ?></div>
                            <div class="stat-label">Total Size</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-database"></i>
                        </div>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-hdd"></i>
                        <span>Storage used</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo date('M Y'); ?></div>
                            <div class="stat-label">Current Month</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-clock"></i>
                        <span>Active period</span>
                    </div>
                </div>
            </div>

            <!-- Search Box -->
            <div class="search-box-full">
                <div class="search-box" style="position: relative;">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" id="search-input" placeholder="Search documents by name...">
                </div>
            </div>

            <!-- Documents Table -->
            <div class="data-table-card">
                <div class="table-header">
                    <h3>All Documents</h3>
                    <div class="table-actions">
                        <button class="btn btn-secondary btn-sm" onclick="refreshDocuments()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
                
                <div class="table-container">
                    <?php if($total_documents == 0): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <h3>No documents uploaded yet</h3>
                            <p>Get started by uploading your first document</p>
                            <a href="upload.php" class="upload-btn" style="margin-top: 20px;">
                                <i class="fas fa-cloud-upload-alt"></i> Upload Document
                            </a>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Type</th>
                                    <th>Size</th>
                                    <th>Uploaded</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="doc-tbody">
                                <?php foreach($documents as $doc): 
                                    // Use original_name if available, otherwise use file_name
                                    $file_name = $doc['original_name'] ?? $doc['file_name'] ?? 'Unknown';
                                    $file_type = $doc['file_type'] ?? 'Unknown';
                                    $file_size = (int)($doc['file_size'] ?? 0);
                                    $uploaded_at = $doc['uploaded_at'] ?? $doc['created_at'] ?? 'Unknown';
                                    
                                    // Determine file icon
                                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                    $icon_class = 'default';
                                    $icon = 'fa-file';
                                    
                                    if (in_array($file_ext, ['pdf'])) {
                                        $icon_class = 'pdf';
                                        $icon = 'fa-file-pdf';
                                    } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg'])) {
                                        $icon_class = 'image';
                                        $icon = 'fa-file-image';
                                    } elseif (in_array($file_ext, ['doc', 'docx'])) {
                                        $icon_class = 'word';
                                        $icon = 'fa-file-word';
                                    } elseif (in_array($file_ext, ['xls', 'xlsx', 'csv'])) {
                                        $icon_class = 'excel';
                                        $icon = 'fa-file-excel';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="file-info">
                                            <div class="file-icon <?php echo $icon_class; ?>">
                                                <i class="fas <?php echo $icon; ?>"></i>
                                            </div>
                                            <div class="file-details">
                                                <div class="file-name"><?php echo htmlspecialchars($file_name); ?></div>
                                                <div class="file-meta">
                                                    <span>ID: <?php echo $doc['id']; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-review">
                                            <?php echo strtoupper($file_ext ?: 'FILE'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatBytes($file_size); ?></td>
                                    <td><?php echo htmlspecialchars($uploaded_at); ?></td>
                                    <td>
                                        <span class="status-badge available">Available</span>
                                    </td>
                                    <td>
                                        <div class="doc-actions">
                                            <button class="btn btn-primary btn-sm" 
                                                onclick="window.location.href='../api/documents/download.php?id=<?php echo $doc['id']; ?>'">
                                                <i class="fas fa-download"></i> Download
                                            </button>
                                            <button class="btn btn-secondary btn-sm" onclick="previewDoc(<?php echo $doc['id']; ?>)">
                                                <i class="fas fa-eye"></i> Preview
                                            </button>
                                            <button class="btn btn-danger btn-sm" onclick="deleteDoc(<?php echo $doc['id']; ?>)">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                         <!-- Option 1: Simple link styled as button -->


<!-- Option 2: Form with redirect -->
<td>
    <form method="GET" action="shared_links.php" style="display: inline;">
        <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
        <button type="submit" class="btn btn-info btn-sm">
            <i class="fas fa-share-alt"></i> Share
        </button>
    </form>
</td> </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Search functionality
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const filter = searchInput.value.toLowerCase();
                const rows = document.querySelectorAll('#doc-tbody tr');
                
                rows.forEach(row => {
                    const text = row.cells[0].textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? "" : "none";
                });
            });
        }
        
        // Document actions
        async function deleteDoc(id) {
            if (!confirm('Are you sure you want to delete this document?')) return;
            
            try {
                const res = await fetch('../api/documents/delete.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id })
                });
                
                const data = await res.json();
                if (data.success) {
                    showNotification('Document deleted successfully', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(data.message || "Failed to delete document", 'error');
                }
            } catch(err) {
                showNotification("Error deleting document", 'error');
                console.error(err);
            }
        }
        
        function previewDoc(id) {
            window.open(`preview.php?id=${id}`, '_blank', 'width=900,height=700');
        }
        
        async function shareDoc(id) {
            const days = prompt("Expire after how many days? (leave empty for default)", "7");
            if (days === null) return;
            
            try {
                const res = await fetch('../api/documents/create_share_link.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ 
                        document_id: id, 
                        expires_in: days || "7" 
                    })
                });
                
                const data = await res.json();
                if (data.success) {
                    prompt("Shareable link (copy):", data.link);
                } else {
                    showNotification(data.message || "Failed to create link", 'error');
                }
            } catch(err) {
                console.error(err);
                showNotification("Error creating link", 'error');
            }
        }
        
        function refreshDocuments() {
            location.reload();
        }
        
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            `;
            
            // Add styles
            const style = document.createElement('style');
            style.textContent = `
                .notification {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 15px 20px;
                    border-radius: var(--border-radius);
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    z-index: 10000;
                    animation: slideIn 0.3s ease;
                }
                
                .notification-success {
                    background: var(--success-color);
                    color: white;
                }
                
                .notification-error {
                    background: var(--danger-color);
                    color: white;
                }
                
                @keyframes slideIn {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
            `;
            
            document.head.appendChild(style);
            document.body.appendChild(notification);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }
        
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
// Helper function
function formatBytes($bytes, $decimals = 2) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $dm = $decimals < 0 ? 0 : $decimals;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
}
?>