<?php
session_start();
require_once('../config/db.php');

if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle actions
if(isset($_POST['action'])) {
    switch($_POST['action']) {
        case 'create_share':
            createShareLink($conn, $user_id);
            break;
        case 'update_share':
            updateShareLink($conn, $user_id);
            break;
        case 'delete_share':
            deleteShareLink($conn, $user_id);
            break;
    }
}

function createShareLink($conn, $user_id) {
    $document_id = intval($_POST['document_id']);
    $share_type = $_POST['share_type'] ?? 'public';
    $password = $_POST['password'] ?? null;
    $expiration_date = $_POST['expiration_date'] ?? null;
    $max_views = intval($_POST['max_views'] ?? 0);
    
    // Verify document belongs to user
    $stmt = $conn->prepare("SELECT id FROM documents WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $document_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows === 0) {
        $_SESSION['error'] = "Document not found or access denied";
        return;
    }
    
    // Generate unique share code
    $share_code = bin2hex(random_bytes(16));
    
    // Hash password if provided
    $password_hash = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
    
    // Prepare expiration date
    if($expiration_date && !empty($expiration_date)) {
        $expiration_date = date('Y-m-d H:i:s', strtotime($expiration_date));
    } else {
        $expiration_date = null;
    }
    
    // Insert share link
    $stmt = $conn->prepare("INSERT INTO shared_links (document_id, user_id, share_code, share_type, password_hash, expiration_date, max_views) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissssi", $document_id, $user_id, $share_code, $share_type, $password_hash, $expiration_date, $max_views);
    
    if($stmt->execute()) {
        $_SESSION['success'] = "Share link created successfully!";
    } else {
        $_SESSION['error'] = "Failed to create share link";
    }
}

function updateShareLink($conn, $user_id) {
    $share_id = intval($_POST['share_id']);
    $share_type = $_POST['share_type'] ?? 'public';
    $password = $_POST['password'] ?? null;
    $expiration_date = $_POST['expiration_date'] ?? null;
    $max_views = intval($_POST['max_views'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Verify share belongs to user
    $stmt = $conn->prepare("SELECT id FROM shared_links WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $share_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows === 0) {
        $_SESSION['error'] = "Share link not found or access denied";
        return;
    }
    
    // Hash password if provided
    $password_hash = null;
    if($password && !empty($password)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
    } elseif(isset($_POST['keep_password'])) {
        // Keep existing password
        $password_hash = $_POST['current_password_hash'];
    }
    
    // Prepare expiration date
    if($expiration_date && !empty($expiration_date)) {
        $expiration_date = date('Y-m-d H:i:s', strtotime($expiration_date));
    } else {
        $expiration_date = null;
    }
    
    // Update share link
    if($password_hash) {
        $stmt = $conn->prepare("UPDATE shared_links SET share_type = ?, password_hash = ?, expiration_date = ?, max_views = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("sssiii", $share_type, $password_hash, $expiration_date, $max_views, $is_active, $share_id);
    } else {
        $stmt = $conn->prepare("UPDATE shared_links SET share_type = ?, expiration_date = ?, max_views = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("ssiii", $share_type, $expiration_date, $max_views, $is_active, $share_id);
    }
    
    if($stmt->execute()) {
        $_SESSION['success'] = "Share link updated successfully!";
    } else {
        $_SESSION['error'] = "Failed to update share link";
    }
}

function deleteShareLink($conn, $user_id) {
    $share_id = intval($_POST['share_id']);
    
    // Verify share belongs to user
    $stmt = $conn->prepare("SELECT id FROM shared_links WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $share_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows === 0) {
        $_SESSION['error'] = "Share link not found or access denied";
        return;
    }
    
    // Delete share link
    $stmt = $conn->prepare("DELETE FROM shared_links WHERE id = ?");
    $stmt->bind_param("i", $share_id);
    
    if($stmt->execute()) {
        $_SESSION['success'] = "Share link deleted successfully!";
    } else {
        $_SESSION['error'] = "Failed to delete share link";
    }
}

// Get user's documents
$documents_stmt = $conn->prepare("SELECT id, original_name, uploaded_at FROM documents WHERE user_id = ? ORDER BY uploaded_at DESC");
$documents_stmt->bind_param("i", $user_id);
$documents_stmt->execute();
$documents_result = $documents_stmt->get_result();

// Get user's share links with document info
$shares_stmt = $conn->prepare("
    SELECT sl.*, d.original_name, d.file_path 
    FROM shared_links sl 
    JOIN documents d ON sl.document_id = d.id 
    WHERE sl.user_id = ? 
    ORDER BY sl.created_at DESC
");
$shares_stmt->bind_param("i", $user_id);
$shares_stmt->execute();
$shares_result = $shares_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Documents - Vigilo</title>
    <link rel="stylesheet" href="css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #667eea;
            --primary-dark: #5a67d8;
            --secondary-color: #764ba2;
            --success-color: #43e97b;
            --warning-color: #ffd166;
            --danger-color: #f5576c;
            --text-primary: #2d3748;
            --text-secondary: #718096;
            --light-bg: #f7fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
            --border-radius: 12px;
            --border-radius-lg: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background: var(--light-bg);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 40px 0;
            margin-bottom: 30px;
            text-align: center;
        }

        .header h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: rgba(67, 233, 123, 0.1);
            border: 1px solid rgba(67, 233, 123, 0.3);
            color: #2e7d32;
        }

        .alert-error {
            background: rgba(245, 87, 108, 0.1);
            border: 1px solid rgba(245, 87, 108, 0.3);
            color: #c62828;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        .card h2 {
            font-family: 'Poppins', sans-serif;
            margin-bottom: 25px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card h2 i {
            color: var(--primary-color);
        }

        /* Share Grid */
        .share-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        @media (max-width: 1024px) {
            .share-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-family: 'Roboto', sans-serif;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23718096' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 45px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .data-table th {
            background: rgba(102, 126, 234, 0.1);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-color);
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table tr:hover {
            background: rgba(102, 126, 234, 0.05);
        }

        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .badge-success {
            background: rgba(67, 233, 123, 0.2);
            color: #2e7d32;
        }

        .badge-warning {
            background: rgba(255, 209, 102, 0.2);
            color: #f57c00;
        }

        .badge-danger {
            background: rgba(245, 87, 108, 0.2);
            color: #c62828;
        }

        .badge-info {
            background: rgba(102, 126, 234, 0.2);
            color: var(--primary-dark);
        }

        /* Share Link Box */
        .share-link-box {
            background: rgba(102, 126, 234, 0.05);
            border: 2px dashed var(--primary-color);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 20px;
        }

        .share-url {
            font-family: monospace;
            background: white;
            padding: 10px 15px;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            word-break: break-all;
            margin-bottom: 15px;
        }

        .share-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* QR Code */
        .qr-code-container {
            text-align: center;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        /* Social Sharing */
        .social-sharing {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .social-btn {
            padding: 10px 15px;
            border-radius: var(--border-radius);
            text-decoration: none;
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .social-whatsapp { background: #25D366; }
        .social-telegram { background: #0088cc; }
        .social-email { background: #EA4335; }
        .social-facebook { background: #1877F2; }
        .social-twitter { background: #1DA1F2; }
        .social-linkedin { background: #0A66C2; }

        .social-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        /* Navigation */
        .navbar {
            background: white;
            padding: 1rem 0;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .nav-brand {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            color: var(--primary-color);
            text-decoration: none;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-links {
            display: flex;
            gap: 10px;
        }

        .nav-link {
            text-decoration: none;
            color: var(--text-primary);
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        .nav-link.active {
            color: var(--primary-color);
            background: rgba(102, 126, 234, 0.1);
        }

        /* Status indicators */
        .status-active { color: var(--success-color); }
        .status-expired { color: var(--danger-color); }
        .status-limited { color: var(--warning-color); }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="dashboard.php" class="nav-brand">
                <i class="fas fa-user-shield"></i> Vigilo
            </a>
            <div class="nav-links">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="upload.php" class="nav-link">Upload</a>
                <a href="documents.php" class="nav-link">Documents</a>
                <a href="shared_links.php" class="nav-link active">Share</a>
                <a href="profile.php" class="nav-link">Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="header">
        <div class="container">
            <h1><i class="fas fa-share-alt"></i> Share Documents</h1>
            <p>Create secure share links for your documents and track their usage</p>
        </div>
    </div>

    <div class="container">
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?= $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="share-grid">
            <!-- Create Share Form -->
            <div class="card">
                <h2><i class="fas fa-link"></i> Create Share Link</h2>
                <form method="POST" action="" id="createShareForm">
                    <input type="hidden" name="action" value="create_share">
                    
                    <div class="form-group">
                        <label class="form-label" for="document_id">Select Document</label>
                        <select class="form-control form-select" id="document_id" name="document_id" required>
                            <option value="">Choose a document...</option>
                            <?php while($doc = $documents_result->fetch_assoc()): ?>
                                <option value="<?= $doc['id'] ?>">
                                    <?= htmlspecialchars($doc['original_name']) ?> 
                                    (<?= date('M d, Y', strtotime($doc['uploaded_at'])) ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="share_type">Share Type</label>
                        <select class="form-control form-select" id="share_type" name="share_type" required>
                            <option value="public">Public (Anyone with link)</option>
                            <option value="private">Private (Password protected)</option>
                            <option value="password">Password Protected</option>
                        </select>
                    </div>

                    <div class="form-group" id="password_field" style="display: none;">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Enter password for this link">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="expiration_date">Expiration Date (Optional)</label>
                        <input type="datetime-local" class="form-control" id="expiration_date" name="expiration_date">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="max_views">Max Views (Optional)</label>
                        <input type="number" class="form-control" id="max_views" name="max_views" 
                               placeholder="0 = unlimited" min="0">
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-plus-circle"></i> Create Share Link
                    </button>
                </form>
            </div>

            <!-- Active Share Links -->
            <div class="card">
                <h2><i class="fas fa-list"></i> Active Share Links</h2>
                
                <?php if($shares_result->num_rows > 0): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Type</th>
                                    <th>Views</th>
                                    <th>Expires</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($share = $shares_result->fetch_assoc()): 
                                    $is_expired = $share['expiration_date'] && strtotime($share['expiration_date']) < time();
                                    $is_limited = $share['max_views'] > 0 && $share['view_count'] >= $share['max_views'];
                                    $is_active = $share['is_active'] && !$is_expired && !$is_limited;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($share['original_name']) ?></strong><br>
                                            <small class="text-muted">Created: <?= date('M d, Y', strtotime($share['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= $share['share_type'] == 'public' ? 'success' : 'warning' ?>">
                                                <?= ucfirst($share['share_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $share['view_count'] ?> / 
                                            <?= $share['max_views'] > 0 ? $share['max_views'] : '∞' ?>
                                            <?php if($share['download_count'] > 0): ?>
                                                <br><small>(<?= $share['download_count'] ?> downloads)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($share['expiration_date']): ?>
                                                <?= date('M d, Y', strtotime($share['expiration_date'])) ?>
                                                <?php if($is_expired): ?>
                                                    <br><small class="status-expired">Expired</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($is_active): ?>
                                                <span class="status-active"><i class="fas fa-circle"></i> Active</span>
                                            <?php elseif($is_expired): ?>
                                                <span class="status-expired"><i class="fas fa-circle"></i> Expired</span>
                                            <?php elseif($is_limited): ?>
                                                <span class="status-limited"><i class="fas fa-circle"></i> Limit Reached</span>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="fas fa-circle"></i> Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                <button class="btn btn-primary btn-sm" 
                                                        onclick="showShareModal('<?= $share['share_code'] ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-success btn-sm" 
                                                        onclick="showEditModal(<?= $share['id'] ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="delete_share">
                                                    <input type="hidden" name="share_id" value="<?= $share['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" 
                                                            onclick="return confirm('Are you sure you want to delete this share link?')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px;">
                        <i class="fas fa-share-alt" style="font-size: 3rem; color: var(--border-color); margin-bottom: 20px;"></i>
                        <h3 style="color: var(--text-secondary); margin-bottom: 10px;">No Share Links Yet</h3>
                        <p style="color: var(--text-secondary);">Create your first share link by selecting a document</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="card">
            <h2><i class="fas fa-chart-bar"></i> Sharing Statistics</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div style="text-align: center; padding: 20px; background: rgba(102, 126, 234, 0.05); border-radius: var(--border-radius);">
                    <h3 style="font-size: 2rem; color: var(--primary-color); margin-bottom: 10px;">
                        <?= $shares_result->num_rows ?>
                    </h3>
                    <p style="color: var(--text-secondary);">Total Shares</p>
                </div>
                <div style="text-align: center; padding: 20px; background: rgba(67, 233, 123, 0.05); border-radius: var(--border-radius);">
                    <h3 style="font-size: 2rem; color: var(--success-color); margin-bottom: 10px;">
                        <?php
                        $active_stmt = $conn->prepare("SELECT COUNT(*) as active FROM shared_links WHERE user_id = ? AND is_active = 1 AND (expiration_date IS NULL OR expiration_date > NOW())");
                        $active_stmt->bind_param("i", $user_id);
                        $active_stmt->execute();
                        $active_count = $active_stmt->get_result()->fetch_assoc()['active'];
                        echo $active_count;
                        ?>
                    </h3>
                    <p style="color: var(--text-secondary);">Active Shares</p>
                </div>
                <div style="text-align: center; padding: 20px; background: rgba(255, 209, 102, 0.05); border-radius: var(--border-radius);">
                    <h3 style="font-size: 2rem; color: var(--warning-color); margin-bottom: 10px;">
                        <?php
                        $views_stmt = $conn->prepare("SELECT SUM(view_count) as total_views FROM shared_links WHERE user_id = ?");
                        $views_stmt->bind_param("i", $user_id);
                        $views_stmt->execute();
                        $total_views = $views_stmt->get_result()->fetch_assoc()['total_views'] ?? 0;
                        echo $total_views;
                        ?>
                    </h3>
                    <p style="color: var(--text-secondary);">Total Views</p>
                </div>
                <div style="text-align: center; padding: 20px; background: rgba(245, 87, 108, 0.05); border-radius: var(--border-radius);">
                    <h3 style="font-size: 2rem; color: var(--danger-color); margin-bottom: 10px;">
                        <?php
                        $expired_stmt = $conn->prepare("SELECT COUNT(*) as expired FROM shared_links WHERE user_id = ? AND expiration_date < NOW()");
                        $expired_stmt->bind_param("i", $user_id);
                        $expired_stmt->execute();
                        $expired_count = $expired_stmt->get_result()->fetch_assoc()['expired'];
                        echo $expired_count;
                        ?>
                    </h3>
                    <p style="color: var(--text-secondary);">Expired</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Share Modal -->
    <div id="shareModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-share-alt"></i> Share Options</h2>
                <button class="modal-close" onclick="closeShareModal()">&times;</button>
            </div>
            <div id="shareModalContent">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Share Link</h2>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div id="editModalContent">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>

    <script>
        // Show/hide password field based on share type
        document.getElementById('share_type').addEventListener('change', function() {
            const passwordField = document.getElementById('password_field');
            if(this.value === 'private' || this.value === 'password') {
                passwordField.style.display = 'block';
                document.getElementById('password').required = true;
            } else {
                passwordField.style.display = 'none';
                document.getElementById('password').required = false;
            }
        });

        // Share modal functions - FIXED VERSION
        function showShareModal(shareCode) {
            // Get the current protocol (http:// or https://)
            const protocol = window.location.protocol;
            // Get the current host (domain and port)
            const host = window.location.host;
            // Get the base path (everything before the current file)
            const path = window.location.pathname;
            const pathParts = path.split('/');
            // Remove the current filename (shared_links.php)
            pathParts.pop();
            // Reconstruct the base URL
            const basePath = pathParts.join('/');
            
            // Construct the share URL - IMPORTANT: Make sure share_view.php is in the same directory
            const shareUrl = `${protocol}//${host}${basePath}/share_view.php?code=${shareCode}`;
            
            console.log('Generated share URL:', shareUrl); // Debug log
            
            const modalContent = `
                <div class="share-link-box">
                    <h3>Share Link</h3>
                    <div class="share-url" id="shareUrl">${shareUrl}</div>
                    <div class="share-actions">
                        <button class="btn btn-primary" onclick="copyToClipboard('${shareUrl}')">
                            <i class="fas fa-copy"></i> Copy Link
                        </button>
                        <button class="btn btn-success" onclick="testShareLink('${shareUrl}')">
                            <i class="fas fa-external-link-alt"></i> Test Link
                        </button>
                        <button class="btn btn-success" onclick="generateQRCode('${shareUrl}')">
                            <i class="fas fa-qrcode"></i> Generate QR
                        </button>
                    </div>
                    
                    <div id="qrCodeContainer" class="qr-code-container" style="display: none;"></div>
                    
                    <h3 style="margin-top: 30px;">Share via</h3>
                    <div class="social-sharing">
                        <a href="https://wa.me/?text=${encodeURIComponent('Check out this document: ' + shareUrl)}" 
                           target="_blank" class="social-btn social-whatsapp">
                            <i class="fab fa-whatsapp"></i> WhatsApp
                        </a>
                        <a href="https://t.me/share/url?url=${encodeURIComponent(shareUrl)}" 
                           target="_blank" class="social-btn social-telegram">
                            <i class="fab fa-telegram"></i> Telegram
                        </a>
                        <a href="mailto:?subject=Shared Document&body=${encodeURIComponent('Check out this document: ' + shareUrl)}" 
                           class="social-btn social-email">
                            <i class="fas fa-envelope"></i> Email
                        </a>
                    </div>
                    
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                        <h4>Additional Options</h4>
                        <div class="share-actions">
                            <button class="btn btn-primary" onclick="downloadAsText('${shareCode}', '${shareUrl}')">
                                <i class="fas fa-file-alt"></i> Download as Text
                            </button>
                            <button class="btn btn-primary" onclick="openShareLink('${shareUrl}')">
                                <i class="fas fa-external-link-alt"></i> Open in New Tab
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('shareModalContent').innerHTML = modalContent;
            document.getElementById('shareModal').style.display = 'flex';
        }

        function closeShareModal() {
            document.getElementById('shareModal').style.display = 'none';
        }

        // Edit modal functions
        function showEditModal(shareId) {
            // Load share data via AJAX
            fetch(`../api/documents/get_share.php?id=${shareId}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        const share = data.share;
                        const expires = share.expiration_date ? share.expiration_date.split(' ')[0] + 'T' + share.expiration_date.split(' ')[1].substr(0,5) : '';
                        
                        const modalContent = `
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="update_share">
                                <input type="hidden" name="share_id" value="${share.id}">
                                <input type="hidden" name="current_password_hash" value="${share.password_hash || ''}">
                                
                                <div class="form-group">
                                    <label class="form-label">Document</label>
                                    <input type="text" class="form-control" value="${share.original_name}" disabled>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="edit_share_type">Share Type</label>
                                    <select class="form-control form-select" id="edit_share_type" name="share_type">
                                        <option value="public" ${share.share_type === 'public' ? 'selected' : ''}>Public</option>
                                        <option value="private" ${share.share_type === 'private' ? 'selected' : ''}>Private</option>
                                        <option value="password" ${share.share_type === 'password' ? 'selected' : ''}>Password Protected</option>
                                    </select>
                                </div>
                                
                                <div class="form-group" id="edit_password_field" style="${share.share_type === 'public' ? 'display: none;' : ''}">
                                    <label class="form-label" for="edit_password">Password</label>
                                    <input type="password" class="form-control" id="edit_password" name="password" 
                                           placeholder="Leave blank to keep current password">
                                    <div style="margin-top: 5px;">
                                        <label>
                                            <input type="checkbox" name="keep_password" checked> Keep current password
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="edit_expiration_date">Expiration Date</label>
                                    <input type="datetime-local" class="form-control" id="edit_expiration_date" 
                                           name="expiration_date" value="${expires}">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="edit_max_views">Max Views</label>
                                    <input type="number" class="form-control" id="edit_max_views" name="max_views" 
                                           value="${share.max_views}" min="0">
                                </div>
                                
                                <div class="form-group">
                                    <label style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" name="is_active" ${share.is_active ? 'checked' : ''}>
                                        Active
                                    </label>
                                </div>
                                
                                <div style="display: flex; gap: 10px; margin-top: 20px;">
                                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                    <button type="button" class="btn btn-danger" onclick="closeEditModal()" style="flex: 1;">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                </div>
                            </form>
                        `;
                        
                        document.getElementById('editModalContent').innerHTML = modalContent;
                        document.getElementById('editModal').style.display = 'flex';
                        
                        // Add event listener for share type change
                        document.getElementById('edit_share_type').addEventListener('change', function() {
                            const passwordField = document.getElementById('edit_password_field');
                            if(this.value === 'public') {
                                passwordField.style.display = 'none';
                            } else {
                                passwordField.style.display = 'block';
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading share data:', error);
                });
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Utility functions
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Link copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        }

        function generateQRCode(url) {
            const container = document.getElementById('qrCodeContainer');
            container.style.display = 'block';
            container.innerHTML = `
                <h4>QR Code</h4>
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(url)}" 
                     alt="QR Code" style="max-width: 200px; margin: 10px auto;">
                <p><small>Scan to open the share link</small></p>
            `;
        }

        function downloadAsText(shareCode, shareUrl) {
            const content = `Share Link: ${shareUrl}\nCode: ${shareCode}\n\nGenerated on: ${new Date().toLocaleString()}`;
            const blob = new Blob([content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `share-link-${shareCode}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        function openShareLink(url) {
            window.open(url, '_blank', 'noopener,noreferrer');
        }

        function testShareLink(url) {
            // Open in new tab
            window.open(url, '_blank', 'noopener,noreferrer');
            
            // Also show a message
            alert('Opening share link in new tab. If it doesn\'t work, make sure share_view.php exists in the same directory as this page.');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const shareModal = document.getElementById('shareModal');
            const editModal = document.getElementById('editModal');
            
            if(event.target === shareModal) {
                closeShareModal();
            }
            if(event.target === editModal) {
                closeEditModal();
            }
        }

        // Auto-select document from URL parameter
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const documentId = urlParams.get('document_id');
            
            if(documentId) {
                const documentSelect = document.getElementById('document_id');
                if(documentSelect) {
                    documentSelect.value = documentId;
                    
                    // Scroll to the create share form for better UX
                    document.querySelector('.card').scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });
    </script>
</body>
</html>