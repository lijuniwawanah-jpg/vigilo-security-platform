<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// share_view.php - Public document access page
session_start();
require_once('../config/db.php');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get share code from URL
$share_code = $_GET['code'] ?? '';

if(empty($share_code)) {
    header('Location: index.php');
    exit;
}

// Get share link details with document and owner info
$stmt = $conn->prepare("
    SELECT 
        sl.*, 
        d.original_name,                    -- documents table has original_name column
        d.file_path, 
        d.file_size, 
        d.file_type, 
        d.description,
        d.uploaded_at as doc_uploaded,
        u.fullName as owner_name,           -- users table has fullName column (note: camelCase)
        u.email as owner_email
    FROM shared_links sl
    JOIN documents d ON sl.document_id = d.id
    JOIN users u ON sl.user_id = u.id
    WHERE sl.share_code = ? AND sl.is_active = 1
");
$stmt->bind_param("s", $share_code);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) {
    // Share not found or inactive
    $page_title = "Link Not Found";
    $error_message = "This share link is invalid or has been disabled.";
    $show_error = true;
} else {
    $share = $result->fetch_assoc();
    $page_title = htmlspecialchars($share['original_name']);
    
    // Check expiration
    if($share['expiration_date'] && strtotime($share['expiration_date']) < time()) {
        $error_message = "This share link has expired.";
        $show_error = true;
        $expired = true;
    }
    
    // Check view limit
    if($share['max_views'] > 0 && $share['view_count'] >= $share['max_views']) {
        $error_message = "Maximum view limit reached for this share link.";
        $show_error = true;
        $limit_reached = true;
    }
    
    // Check if password is required
    $requires_password = ($share['share_type'] === 'private' || $share['share_type'] === 'password') && $share['password_hash'];
    
    // Handle password verification
    if($requires_password && !isset($_SESSION['share_verified'][$share_code])) {
        if(isset($_POST['password'])) {
            if(password_verify($_POST['password'], $share['password_hash'])) {
                $_SESSION['share_verified'][$share_code] = true;
                $password_verified = true;
            } else {
                $password_error = "Incorrect password. Please try again.";
            }
        }
        $show_password_form = true;
    } else {
        $show_password_form = false;
    }
    
    // Increment view count if accessing for first time in this session
    if(!isset($_SESSION['share_viewed'][$share_code]) && !isset($show_error)) {
        if(!$requires_password || isset($_SESSION['share_verified'][$share_code])) {
            // Increment view count
            $update_stmt = $conn->prepare("UPDATE shared_links SET view_count = view_count + 1, last_accessed = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $share['id']);
            $update_stmt->execute();
            $update_stmt->close();
            
            $_SESSION['share_viewed'][$share_code] = true;
        }
    }
    
    // Handle download request
    if(isset($_POST['download']) && !isset($show_error)) {
        if(!$requires_password || isset($_SESSION['share_verified'][$share_code])) {
            $file_path = '../' . $share['file_path'];
            
            if(file_exists($file_path)) {
                // Increment download count
                $download_stmt = $conn->prepare("UPDATE shared_links SET download_count = download_count + 1 WHERE id = ?");
                $download_stmt->bind_param("i", $share['id']);
                $download_stmt->execute();
                $download_stmt->close();
                
                // Send file for download
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
                $error_message = "File not found on server.";
                $show_error = true;
            }
        }
    }
    
    // Handle preview request
    if(isset($_GET['preview']) && !isset($show_error)) {
        if(!$requires_password || isset($_SESSION['share_verified'][$share_code])) {
            $file_path = '../' . $share['file_path'];
            
            if(file_exists($file_path)) {
                // Check if file type is previewable
                $previewable_types = ['image/', 'application/pdf', 'text/'];
                $can_preview = false;
                
                foreach($previewable_types as $type) {
                    if(strpos($share['file_type'], $type) === 0) {
                        $can_preview = true;
                        break;
                    }
                }
                
                if($can_preview) {
                    if(strpos($share['file_type'], 'pdf') !== false) {
                        // For PDFs, use PDF.js or browser's built-in viewer
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: inline; filename="' . basename($share['original_name']) . '"');
                        readfile($file_path);
                        exit;
                    } else {
                        // For images and text files
                        header('Content-Type: ' . $share['file_type']);
                        readfile($file_path);
                        exit;
                    }
                }
            }
        }
    }
}

$conn->close();

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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? $page_title . ' - Vigilo' : 'Document - Vigilo' ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.10.5/viewer.min.css">
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
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 40px 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: var(--border-radius-lg);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }

        .header h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 2.5rem;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }

        .alert {
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            text-align: center;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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

        .alert-info {
            background: rgba(102, 126, 234, 0.1);
            border: 1px solid rgba(102, 126, 234, 0.3);
            color: var(--primary-dark);
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius-lg);
            padding: 40px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 30px;
        }

        .document-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 2px solid var(--border-color);
        }

        .document-icon {
            font-size: 5rem;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .document-name {
            font-family: 'Poppins', sans-serif;
            font-size: 2rem;
            margin-bottom: 15px;
            color: var(--text-primary);
            word-break: break-word;
        }

        .document-meta {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
        }

        .meta-item i {
            color: var(--primary-color);
        }

        .badge {
            display: inline-block;
            padding: 6px 15px;
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

        .badge-info {
            background: rgba(102, 126, 234, 0.2);
            color: var(--primary-dark);
        }

        .actions {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 40px 0;
            flex-wrap: wrap;
        }

        .btn {
            padding: 15px 35px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1.1rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            text-decoration: none;
            min-width: 200px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-secondary {
            background: var(--text-secondary);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
        }

        .share-info {
            background: rgba(102, 126, 234, 0.05);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-top: 30px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .info-item {
            padding: 15px;
            background: white;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }

        .info-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }

        .info-value {
            font-weight: 500;
            font-size: 1.1rem;
            color: var(--text-primary);
        }

        .password-form {
            max-width: 400px;
            margin: 0 auto;
            padding: 40px;
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 500;
            color: var(--text-primary);
            font-size: 1.1rem;
        }

        .form-control {
            width: 100%;
            padding: 15px;
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

        .preview-container {
            margin-top: 40px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .preview-header {
            background: var(--light-bg);
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .preview-content {
            padding: 20px;
            max-height: 600px;
            overflow-y: auto;
        }

        .preview-image {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
            cursor: zoom-in;
        }

        .preview-pdf {
            width: 100%;
            height: 600px;
            border: none;
        }

        .preview-text {
            font-family: monospace;
            white-space: pre-wrap;
            word-wrap: break-word;
            padding: 20px;
            background: #f8f9fa;
            border-radius: var(--border-radius);
        }

        .footer {
            text-align: center;
            margin-top: 50px;
            padding-top: 30px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .footer a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        .security-note {
            text-align: center;
            padding: 20px;
            background: rgba(102, 126, 234, 0.1);
            border-radius: var(--border-radius);
            margin-top: 30px;
            color: var(--primary-dark);
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .document-name {
                font-size: 1.5rem;
            }
            
            .actions {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
            
            .card {
                padding: 20px;
            }
        }

        /* QR Code Display */
        .qr-container {
            text-align: center;
            padding: 30px;
            background: white;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            margin-top: 20px;
        }

        /* Download confirmation */
        .download-confirm {
            background: rgba(102, 126, 234, 0.1);
            padding: 15px;
            border-radius: var(--border-radius);
            margin: 20px 0;
            text-align: center;
            border-left: 4px solid var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-shield"></i> Vigilo Secure Share</h1>
            <p>Securely access shared documents</p>
        </div>

        <?php if(isset($show_error) && $show_error): ?>
            <!-- Error Display -->
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom: 15px;"></i>
                <h2 style="margin-bottom: 10px;">Access Restricted</h2>
                <p><?= $error_message ?></p>
                
                <div style="margin-top: 20px;">
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-home"></i> Return to Home
                    </a>
                </div>
            </div>
            
        <?php elseif(isset($show_password_form) && $show_password_form): ?>
            <!-- Password Form -->
            <div class="card">
                <div class="document-header">
                    <div class="document-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h2 class="document-name">Protected Document</h2>
                    <p>This document requires a password to access</p>
                    
                    <?php if(isset($password_error)): ?>
                        <div class="alert alert-warning" style="max-width: 400px; margin: 20px auto;">
                            <i class="fas fa-exclamation-circle"></i> <?= $password_error ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <form method="POST" action="" class="password-form">
                    <div class="form-group">
                        <label class="form-label" for="password">
                            <i class="fas fa-key"></i> Enter Password
                        </label>
                        <input type="password" class="form-control" id="password" name="password" required 
                               placeholder="Enter the password provided by the sender" autofocus>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-unlock"></i> Access Document
                    </button>
                    
                    <div style="text-align: center; margin-top: 20px; color: var(--text-secondary);">
                        <i class="fas fa-info-circle"></i> Contact the sender if you don't have the password
                    </div>
                </form>
            </div>
            
        <?php elseif(isset($share)): ?>
            <!-- Document Access Interface -->
            <div class="card">
                <div class="document-header">
                    <?php
                    // Determine document icon based on file type
                    $file_icon = 'fa-file';
                    $file_type = strtolower($share['file_type']);
                    $file_extension = pathinfo($share['original_name'], PATHINFO_EXTENSION);
                    
                    if(strpos($file_type, 'pdf') !== false) {
                        $file_icon = 'fa-file-pdf';
                    } elseif(strpos($file_type, 'word') !== false || strpos($file_type, 'doc') !== false) {
                        $file_icon = 'fa-file-word';
                    } elseif(strpos($file_type, 'excel') !== false || strpos($file_type, 'sheet') !== false) {
                        $file_icon = 'fa-file-excel';
                    } elseif(strpos($file_type, 'image') !== false) {
                        $file_icon = 'fa-file-image';
                    } elseif(strpos($file_type, 'text') !== false || $file_extension === 'txt') {
                        $file_icon = 'fa-file-alt';
                    } elseif(strpos($file_type, 'zip') !== false || strpos($file_type, 'compressed') !== false) {
                        $file_icon = 'fa-file-archive';
                    } elseif(strpos($file_type, 'audio') !== false) {
                        $file_icon = 'fa-file-audio';
                    } elseif(strpos($file_type, 'video') !== false) {
                        $file_icon = 'fa-file-video';
                    }
                    ?>
                    
                    <div class="document-icon">
                        <i class="fas <?= $file_icon ?>"></i>
                    </div>
                    
                    <h1 class="document-name"><?= htmlspecialchars($share['original_name']) ?></h1>
                    
                    <div class="document-meta">
                        <span class="meta-item">
                            <i class="fas fa-user"></i> 
                            <span>Shared by: <?= htmlspecialchars($share['owner_name']) ?></span>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-database"></i> 
                            <span>Size: <?= formatBytes($share['file_size']) ?></span>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-calendar"></i> 
                            <span>Shared: <?= date('M d, Y', strtotime($share['created_at'])) ?></span>
                        </span>
                    </div>
                    
                    <!-- Security badges -->
                    <div style="margin-top: 20px;">
                        <span class="badge badge-<?= $share['share_type'] === 'public' ? 'success' : 'warning' ?>">
                            <i class="fas fa-<?= $share['share_type'] === 'public' ? 'globe' : 'lock' ?>"></i>
                            <?= ucfirst($share['share_type']) ?> Access
                        </span>
                        
                        <?php if($share['expiration_date']): ?>
                            <span class="badge badge-<?= strtotime($share['expiration_date']) < time() ? 'danger' : 'warning' ?>">
                                <i class="fas fa-clock"></i>
                                <?php if(strtotime($share['expiration_date']) < time()): ?>
                                    Expired
                                <?php else: ?>
                                    Expires: <?= date('M d, Y', strtotime($share['expiration_date'])) ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if($share['max_views'] > 0): ?>
                            <span class="badge badge-<?= $share['view_count'] >= $share['max_views'] ? 'danger' : 'warning' ?>">
                                <i class="fas fa-eye"></i>
                                Views: <?= $share['view_count'] ?>/<?= $share['max_views'] ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if($share['password_hash']): ?>
                            <span class="badge badge-info">
                                <i class="fas fa-key"></i> Password Protected
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if(!empty($share['description'])): ?>
                    <div style="text-align: center; margin: 30px 0; padding: 25px; background: rgba(102, 126, 234, 0.05); border-radius: var(--border-radius);">
                        <h3 style="margin-bottom: 15px; color: var(--text-primary);">
                            <i class="fas fa-info-circle"></i> Description
                        </h3>
                        <p style="color: var(--text-secondary); font-size: 1.1rem; line-height: 1.6;">
                            <?= nl2br(htmlspecialchars($share['description'])) ?>
                        </p>
                    </div>
                <?php endif; ?>
                
                <!-- Download Confirmation for Large Files -->
                <?php if($share['file_size'] > 10 * 1024 * 1024): // 10MB ?>
                    <div class="download-confirm">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> This file is <?= formatBytes($share['file_size']) ?> in size.
                        Download may take a while depending on your connection speed.
                    </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div class="actions">
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="download" value="1">
                        <button type="submit" class="btn btn-primary" onclick="return confirmDownload(<?= $share['file_size'] ?>)">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </form>
                    
                    <?php 
                    // Check if file can be previewed
                    $can_preview = false;
                    $preview_types = ['image/', 'application/pdf', 'text/'];
                    foreach($preview_types as $type) {
                        if(strpos($share['file_type'], $type) === 0) {
                            $can_preview = true;
                            break;
                        }
                    }
                    
                    if($can_preview): ?>
                        <a href="?code=<?= $share_code ?>&preview=1" class="btn btn-success" target="_blank">
                            <i class="fas fa-eye"></i> Preview
                        </a>
                    <?php endif; ?>
                    
                    <!-- QR Code Generation -->
                    <button type="button" class="btn btn-outline" onclick="generateQRCode()">
                        <i class="fas fa-qrcode"></i> Show QR Code
                    </button>
                </div>
                
                <!-- QR Code Container -->
                <div id="qrCodeContainer" class="qr-container" style="display: none;">
                    <h3><i class="fas fa-qrcode"></i> QR Code</h3>
                    <div id="qrcode" style="margin: 20px 0;"></div>
                    <p style="color: var(--text-secondary);">
                        Scan this QR code to access this document on mobile devices
                    </p>
                </div>
                
                <!-- Share Information -->
                <div class="share-info">
                    <h3 style="margin-bottom: 20px; color: var(--text-primary);">
                        <i class="fas fa-chart-bar"></i> Share Statistics
                    </h3>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Total Views</div>
                            <div class="info-value"><?= $share['view_count'] ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Downloads</div>
                            <div class="info-value"><?= $share['download_count'] ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Last Accessed</div>
                            <div class="info-value">
                                <?= $share['last_accessed'] ? date('M d, Y H:i', strtotime($share['last_accessed'])) : 'Never' ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Created On</div>
                            <div class="info-value"><?= date('M d, Y H:i', strtotime($share['created_at'])) ?></div>
                        </div>
                        
                        <?php if($share['expiration_date']): ?>
                            <div class="info-item">
                                <div class="info-label">Expiration</div>
                                <div class="info-value">
                                    <?= date('M d, Y H:i', strtotime($share['expiration_date'])) ?>
                                    <?php if(strtotime($share['expiration_date']) < time()): ?>
                                        <span style="color: var(--danger-color); font-size: 0.9rem;"> (Expired)</span>
                                    <?php else: ?>
                                        <span style="color: var(--success-color); font-size: 0.9rem;"> (Active)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="info-item">
                            <div class="info-label">Access Type</div>
                            <div class="info-value">
                                <span class="badge badge-<?= $share['share_type'] === 'public' ? 'success' : 'warning' ?>" style="font-size: 0.9rem;">
                                    <?= ucfirst($share['share_type']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Security Note -->
                <div class="security-note">
                    <i class="fas fa-shield-alt"></i>
                    <strong>Secure Access:</strong> This document is shared securely via Vigilo.
                    All access is logged and monitored for security purposes.
                </div>
            </div>
            
            <!-- Preview Modal (for images) -->
            <?php if(strpos($share['file_type'], 'image/') === 0): ?>
                <div id="imagePreviewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 1000; justify-content: center; align-items: center;">
                    <div style="position: relative; max-width: 90%; max-height: 90%;">
                        <img id="previewImage" src="../<?= $share['file_path'] ?>" style="max-width: 100%; max-height: 90vh;">
                        <button onclick="closePreview()" style="position: absolute; top: 20px; right: 20px; background: rgba(0,0,0,0.7); color: white; border: none; border-radius: 50%; width: 40px; height: 40px; font-size: 1.5rem; cursor: pointer;">&times;</button>
                    </div>
                </div>
            <?php endif; ?>
            
        <?php endif; ?>
        
        <div class="footer">
            <p>
                <i class="fas fa-user-shield"></i> Vigilo - Secure Document Sharing Platform<br>
                © <?= date('Y') ?> Vigilo. All rights reserved. | 
                <a href="index.php">Share your own documents</a>
            </p>
        </div>
    </div>

    <!-- QR Code Library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <!-- Viewer.js for image preview -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.10.5/viewer.min.js"></script>
    
    <script>
        // Format bytes for JavaScript
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }
        
        // Download confirmation for large files
        function confirmDownload(fileSize) {
            if (fileSize > 10 * 1024 * 1024) { // 10MB
                return confirm('This file is ' + formatBytes(fileSize) + '. Do you want to continue downloading?');
            }
            return true;
        }
        
        // Generate QR Code
        function generateQRCode() {
            const container = document.getElementById('qrCodeContainer');
            const qrcodeDiv = document.getElementById('qrcode');
            const currentUrl = window.location.href;
            
            if (container.style.display === 'none') {
                // Clear previous QR code
                qrcodeDiv.innerHTML = '';
                
                // Generate new QR code
                QRCode.toCanvas(qrcodeDiv, currentUrl, {
                    width: 200,
                    height: 200,
                    margin: 1,
                    color: {
                        dark: '#667eea',
                        light: '#ffffff'
                    }
                }, function(error) {
                    if (error) {
                        console.error('QR Code generation error:', error);
                        qrcodeDiv.innerHTML = '<p style="color: var(--danger-color);">Failed to generate QR code</p>';
                    }
                });
                
                
                container.style.display = 'block';
                container.scrollIntoView({ behavior: 'smooth' });
            } else {
                container.style.display = 'none';
            }
        }
        
        // Image preview functionality
        <?php if(isset($share) && strpos($share['file_type'], 'image/') === 0): ?>
        function openImagePreview() {
            const modal = document.getElementById('imagePreviewModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closePreview() {
            const modal = document.getElementById('imagePreviewModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePreview();
            }
        });
        
        // Close modal when clicking outside image
        document.getElementById('imagePreviewModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closePreview();
            }
        });
        <?php endif; ?>
        
        // Auto-focus password field
        document.addEventListener('DOMContentLoaded', function() {
            const passwordField = document.getElementById('password');
            if (passwordField) {
                passwordField.focus();
            }
            
            // Check if we should show QR code from URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('showqr') === '1') {
                generateQRCode();
            }
        });
        
        // Copy current URL to clipboard
        function copyShareLink() {
            const currentUrl = window.location.href;
            navigator.clipboard.writeText(currentUrl).then(() => {
                alert('Share link copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        }
        
        // Share via social media
        function shareOnWhatsApp() {
            const url = encodeURIComponent(window.location.href);
            const text = encodeURIComponent('Check out this document shared via Vigilo');
            window.open(`https://wa.me/?text=${text}%20${url}`, '_blank');
        }
        
        function shareViaEmail() {
            const url = encodeURIComponent(window.location.href);
            const subject = encodeURIComponent('Shared Document via Vigilo');
            const body = encodeURIComponent(`Check out this document: ${window.location.href}`);
            window.location.href = `mailto:?subject=${subject}&body=${body}`;
        }
    </script>
</body>
</html>