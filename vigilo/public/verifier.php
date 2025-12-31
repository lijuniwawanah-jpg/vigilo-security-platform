<?php
session_start();
require_once('../config/db.php');

// Get share code from URL
$share_code = $_GET['code'] ?? '';

if(empty($share_code)) {
    header('Location: index.php');
    exit;
}

// Get share link details
$stmt = $conn->prepare("
    SELECT sl.*, d.original_name, d.file_path, d.file_size, d.file_type, d.description, 
           u.name as owner_name, u.email as owner_email
    FROM shared_links sl
    JOIN documents d ON sl.document_id = d.id
    JOIN users u ON sl.user_id = u.id
    WHERE sl.share_code = ? AND sl.is_active = 1
");
$stmt->bind_param("s", $share_code);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) {
    $error = "Share link not found or has been disabled.";
} else {
    $share = $result->fetch_assoc();
    
    // Check expiration
    if($share['expiration_date'] && strtotime($share['expiration_date']) < time()) {
        $error = "This share link has expired.";
        $expired = true;
    }
    
    // Check view limit
    if($share['max_views'] > 0 && $share['view_count'] >= $share['max_views']) {
        $error = "Maximum view limit reached for this share link.";
        $limit_reached = true;
    }
    
    // Check password if required
    $requires_password = ($share['share_type'] === 'private' || $share['share_type'] === 'password') && $share['password_hash'];
    
    if($requires_password && !isset($_SESSION['share_verified'][$share_code])) {
        if(isset($_POST['password'])) {
            if(password_verify($_POST['password'], $share['password_hash'])) {
                $_SESSION['share_verified'][$share_code] = true;
            } else {
                $password_error = "Incorrect password.";
            }
        }
    }
    
    // Increment view count if accessing for first time in this session
    if(!isset($_SESSION['share_viewed'][$share_code]) && !isset($error) && !isset($expired) && !isset($limit_reached)) {
        if(!$requires_password || isset($_SESSION['share_verified'][$share_code])) {
            // Increment view count
            $update_stmt = $conn->prepare("UPDATE shared_links SET view_count = view_count + 1, last_accessed = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $share['id']);
            $update_stmt->execute();
            $update_stmt->close();
            
            $_SESSION['share_viewed'][$share_code] = true;
        }
    }
    
    // Handle download
    if(isset($_POST['download']) && !isset($error) && !isset($expired) && !isset($limit_reached)) {
        if(!$requires_password || isset($_SESSION['share_verified'][$share_code])) {
            $file_path = '../' . $share['file_path'];
            
            if(file_exists($file_path)) {
                // Increment download count
                $download_stmt = $conn->prepare("UPDATE shared_links SET download_count = download_count + 1 WHERE id = ?");
                $download_stmt->bind_param("i", $share['id']);
                $download_stmt->execute();
                $download_stmt->close();
                
                // Download file
                header('Content-Description: File Transfer');
                header('Content-Type: ' . $share['file_type']);
                header('Content-Disposition: attachment; filename="' . basename($share['original_name']) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit;
            } else {
                $error = "File not found on server.";
            }
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Verification - Vigilo</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            width: 100%;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            color: white;
        }

        .header h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius-lg);
            padding: 40px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 20px;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
        }

        .alert-error {
            background: rgba(245, 87, 108, 0.1);
            border: 1px solid rgba(245, 87, 108, 0.3);
            color: #c62828;
        }

        .alert-warning {
            background: rgba(255, 209, 102, 0.1);
            border: 1px solid rgba(255, 209, 102, 0.3);
            color: #f57c00;
        }

        .alert-success {
            background: rgba(67, 233, 123, 0.1);
            border: 1px solid rgba(67, 233, 123, 0.3);
            color: #2e7d32;
        }

        .document-info {
            text-align: center;
            margin-bottom: 30px;
        }

        .document-icon {
            font-size: 4rem;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .document-name {
            font-family: 'Poppins', sans-serif;
            font-size: 1.8rem;
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        .document-meta {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        .meta-item {
            display: inline-block;
            margin: 0 10px;
        }

        .meta-item i {
            margin-right: 5px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            margin: 5px;
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

        .btn-lg {
            padding: 15px 40px;
            font-size: 1.1rem;
        }

        .password-form {
            max-width: 400px;
            margin: 0 auto;
        }

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

        .share-details {
            background: rgba(102, 126, 234, 0.05);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 30px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
        }

        .detail-value {
            font-weight: 500;
            color: var(--text-primary);
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
        }

        .footer a {
            color: white;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        .security-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            margin: 5px;
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

        @media (max-width: 768px) {
            .card {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .document-name {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-shield"></i> Vigilo Document Verification</h1>
            <p>Securely access shared documents</p>
        </div>

        <div class="card">
            <?php if(isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?= $error ?>
                </div>
                
                <div style="text-align: center; margin-top: 30px;">
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-home"></i> Return to Home
                    </a>
                </div>
                
            <?php elseif($requires_password && !isset($_SESSION['share_verified'][$share_code])): ?>
                <!-- Password Form -->
                <div class="document-info">
                    <div class="document-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h2 class="document-name">Protected Document</h2>
                    <p class="document-meta">This document requires a password to access</p>
                </div>
                
                <?php if(isset($password_error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?= $password_error ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" class="password-form">
                    <div class="form-group">
                        <label class="form-label" for="password">Enter Password</label>
                        <input type="password" class="form-control" id="password" name="password" required 
                               placeholder="Enter the password provided by the sender">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                        <i class="fas fa-unlock"></i> Access Document
                    </button>
                </form>
                
            <?php else: ?>
                <!-- Document Access -->
                <div class="document-info">
                    <?php
                    // Determine document icon based on file type
                    $file_icon = 'fa-file';
                    $file_type = strtolower($share['file_type']);
                    
                    if(strpos($file_type, 'pdf') !== false) $file_icon = 'fa-file-pdf';
                    elseif(strpos($file_type, 'word') !== false || strpos($file_type, 'doc') !== false) $file_icon = 'fa-file-word';
                    elseif(strpos($file_type, 'image') !== false) $file_icon = 'fa-file-image';
                    elseif(strpos($file_type, 'text') !== false) $file_icon = 'fa-file-alt';
                    elseif(strpos($file_type, 'zip') !== false || strpos($file_type, 'compressed') !== false) $file_icon = 'fa-file-archive';
                    ?>
                    
                    <div class="document-icon">
                        <i class="fas <?= $file_icon ?>"></i>
                    </div>
                    
                    <h2 class="document-name"><?= htmlspecialchars($share['original_name']) ?></h2>
                    
                    <div class="document-meta">
                        <span class="meta-item">
                            <i class="fas fa-user"></i> Shared by: <?= htmlspecialchars($share['owner_name']) ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-database"></i> Size: <?= formatBytes($share['file_size']) ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-calendar"></i> Shared: <?= date('M d, Y', strtotime($share['created_at'])) ?>
                        </span>
                    </div>
                    
                    <!-- Security badges -->
                    <div>
                        <span class="security-badge badge-<?= $share['share_type'] === 'public' ? 'success' : 'warning' ?>">
                            <i class="fas fa-<?= $share['share_type'] === 'public' ? 'globe' : 'lock' ?>"></i>
                            <?= ucfirst($share['share_type']) ?> Access
                        </span>
                        
                        <?php if($share['expiration_date']): ?>
                            <span class="security-badge badge-<?= strtotime($share['expiration_date']) < time() ? 'danger' : 'warning' ?>">
                                <i class="fas fa-clock"></i>
                                <?= strtotime($share['expiration_date']) < time() ? 'Expired' : 'Expires: ' . date('M d, Y', strtotime($share['expiration_date'])) ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if($share['max_views'] > 0): ?>
                            <span class="security-badge badge-warning">
                                <i class="fas fa-eye"></i>
                                Views: <?= $share['view_count'] ?>/<?= $share['max_views'] ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if(!empty($share['description'])): ?>
                    <div style="text-align: center; margin: 20px 0; padding: 20px; background: rgba(102, 126, 234, 0.05); border-radius: var(--border-radius);">
                        <h3 style="margin-bottom: 10px; color: var(--text-primary);">
                            <i class="fas fa-info-circle"></i> Description
                        </h3>
                        <p style="color: var(--text-secondary);"><?= nl2br(htmlspecialchars($share['description'])) ?></p>
                    </div>
                <?php endif; ?>
                
                <div style="text-align: center; margin: 30px 0;">
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="download" value="1">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-download"></i> Download Document
                        </button>
                    </form>
                    
                    <?php if(strpos($share['file_type'], 'image') !== false || strpos($share['file_type'], 'pdf') !== false || strpos($share['file_type'], 'text') !== false): ?>
                        <a href="../<?= $share['file_path'] ?>" target="_blank" class="btn btn-success btn-lg">
                            <i class="fas fa-eye"></i> Preview
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="share-details">
                    <h3 style="margin-bottom: 15px; color: var(--text-primary);">
                        <i class="fas fa-chart-bar"></i> Share Statistics
                    </h3>
                    
                    <div class="detail-item">
                        <span class="detail-label">Total Views:</span>
                        <span class="detail-value"><?= $share['view_count'] ?></span>
                    </div>
                    
                    <div class="detail-item">
                        <span class="detail-label">Downloads:</span>
                        <span class="detail-value"><?= $share['download_count'] ?></span>
                    </div>
                    
                    <div class="detail-item">
                        <span class="detail-label">Last Accessed:</span>
                        <span class="detail-value">
                            <?= $share['last_accessed'] ? date('M d, Y H:i', strtotime($share['last_accessed'])) : 'Never' ?>
                        </span>
                    </div>
                    
                    <?php if($share['expiration_date']): ?>
                        <div class="detail-item">
                            <span class="detail-label">Expiration:</span>
                            <span class="detail-value">
                                <?= date('M d, Y H:i', strtotime($share['expiration_date'])) ?>
                                <?php if(strtotime($share['expiration_date']) < time()): ?>
                                    <span style="color: var(--danger-color);"> (Expired)</span>
                                <?php else: ?>
                                    <span style="color: var(--success-color);"> (Active)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                    <p style="color: var(--text-secondary); margin-bottom: 15px;">
                        <i class="fas fa-shield-alt"></i> This document is shared securely via Vigilo
                    </p>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-share-alt"></i> Share Your Own Documents
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>
                <i class="fas fa-user-shield"></i> Vigilo - Secure Document Sharing Platform<br>
                © <?= date('Y') ?> Vigilo. All rights reserved.
            </p>
        </div>
    </div>

    <script>
        // Format bytes function for frontend
        function formatBytes(bytes, decimals = 2) {
            if (bytes == 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }
        
        // Auto-focus password field
        document.addEventListener('DOMContentLoaded', function() {
            const passwordField = document.getElementById('password');
            if(passwordField) {
                passwordField.focus();
            }
        });
        
        // Add download confirmation
        const downloadForm = document.querySelector('form[action=""]');
        if(downloadForm && downloadForm.querySelector('input[name="download"]')) {
            downloadForm.addEventListener('submit', function(e) {
                const fileSize = <?= $share['file_size'] ?? 0 ?>;
                if(fileSize > 10 * 1024 * 1024) { // 10MB
                    if(!confirm('This file is ' + formatBytes(fileSize) + '. Do you want to continue downloading?')) {
                        e.preventDefault();
                    }
                }
            });
        }
    </script>
</body>
</html>

<?php
// Helper function to format bytes
function formatBytes($bytes, $decimals = 2) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $dm = $decimals < 0 ? 0 : $decimals;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
}
?>