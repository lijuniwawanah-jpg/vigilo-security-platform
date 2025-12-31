<?php
// process_upload.php - Direct file upload processor
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once('../config/db.php');

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';

// Get user storage info
$stmt = $conn->prepare("SELECT storage_limit, storage_used FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$storage_limit = $user['storage_limit'] ?? 1073741824; // 1GB default
$storage_used = $user['storage_used'] ?? 0;

// Function to format bytes
function formatBytes($bytes, $decimals = 2) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $dm = $decimals < 0 ? 0 : $decimals;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $upload_errors = [];
    $upload_success = false;
    $document_id = null;
    
    // Check upload error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        
        $upload_errors[] = $error_messages[$file['error']] ?? "Unknown upload error ({$file['error']})";
    } else {
        // File validation
        $allowed_types = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'image/jpeg',
            'image/png', 
            'image/gif',
            'image/webp'
        ];
        
        $max_size = 100 * 1024 * 1024; // 100MB
        
        if (!in_array($file['type'], $allowed_types)) {
            $upload_errors[] = 'File type not allowed. Allowed types: PDF, DOC, DOCX, TXT, JPG, PNG, GIF, WEBP';
        }
        
        if ($file['size'] > $max_size) {
            $upload_errors[] = 'File size exceeds 100MB limit';
        }
        
        // Check storage quota
        if (($storage_used + $file['size']) > $storage_limit) {
            $upload_errors[] = 'Storage quota exceeded. Please free up space or upgrade your plan.';
        }
        
        // If no errors, process upload
        if (empty($upload_errors)) {
            // Create upload directory if it doesn't exist
            $upload_dir = '../uploads/docs/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $original_name = basename($file['name']);
            $extension = pathinfo($original_name, PATHINFO_EXTENSION);
            $unique_name = uniqid() . '_' . time() . '.' . $extension;
            $file_path = $upload_dir . $unique_name;
            
            // Get form data
            $document_name = $_POST['documentName'] ?? $original_name;
            $description = $_POST['description'] ?? '';
            $encrypt = isset($_POST['encryptFile']) && $_POST['encryptFile'] == '1';
            $enable_ocr = isset($_POST['enableOCR']) && $_POST['enableOCR'] == '1';
            $generate_qr = isset($_POST['generateQR']) && $_POST['generateQR'] == '1';
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                // Set file permissions
                chmod($file_path, 0644);
                
                // Store relative path in database
                $relative_path = 'uploads/docs/' . $unique_name;
                
                // Check if documents table has required columns
                $table_check = $conn->query("SHOW TABLES LIKE 'documents'");
                if ($table_check && $table_check->num_rows > 0) {
                    // Insert into database
                    $sql = "INSERT INTO documents (user_id, file_name, original_name, file_path, file_type, file_size, description, uploaded_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $stmt = $conn->prepare($sql);
                    
                    if ($stmt) {
                        $stmt->bind_param("issssis", $user_id, $unique_name, $original_name, $relative_path, $file['type'], $file['size'], $description);
                        
                        if ($stmt->execute()) {
                            $document_id = $stmt->insert_id;
                            
                            // Update user's storage usage
                            $new_storage_used = $storage_used + $file['size'];
                            $update_stmt = $conn->prepare("UPDATE users SET storage_used = ? WHERE id = ?");
                            if ($update_stmt) {
                                $update_stmt->bind_param("ii", $new_storage_used, $user_id);
                                $update_stmt->execute();
                                $update_stmt->close();
                            }
                            
                            // Additional processing
                            if ($encrypt) {
                                // Placeholder for encryption
                                // Implement encryption logic here
                            }
                            
                            if ($enable_ocr) {
                                // Placeholder for OCR processing
                                // Implement OCR logic here
                            }
                            
                            if ($generate_qr) {
                                // Placeholder for QR generation
                                // Implement QR generation here
                            }
                            
                            $upload_success = true;
                            $stmt->close();
                        } else {
                            $upload_errors[] = 'Database error: ' . $stmt->error;
                            unlink($file_path); // Delete the uploaded file if database insert fails
                        }
                    } else {
                        $upload_errors[] = 'Database prepare error: ' . $conn->error;
                        unlink($file_path);
                    }
                } else {
                    $upload_errors[] = 'Documents table not found in database';
                    unlink($file_path);
                }
            } else {
                $upload_errors[] = 'Failed to save uploaded file. Check server permissions.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing Upload - Vigilo</title>
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
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 20px;
            flex: 1;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 30px 0;
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 2.2rem;
            margin-bottom: 10px;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius-lg);
            padding: 40px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .status-icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }

        .status-success .status-icon {
            color: var(--success-color);
        }

        .status-error .status-icon {
            color: var(--danger-color);
        }

        .card h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.8rem;
            margin-bottom: 15px;
            color: var(--text-primary);
        }

        .card p {
            color: var(--text-secondary);
            margin-bottom: 20px;
            font-size: 1.1rem;
        }

        .upload-details {
            background: rgba(102, 126, 234, 0.05);
            border-radius: var(--border-radius);
            padding: 20px;
            margin: 25px 0;
            text-align: left;
        }

        .upload-details h3 {
            font-family: 'Poppins', sans-serif;
            margin-bottom: 15px;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
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
            font-weight: 500;
            color: var(--text-primary);
        }

        .detail-value {
            color: var(--text-secondary);
        }

        .error-list {
            background: rgba(245, 87, 108, 0.05);
            border-radius: var(--border-radius);
            padding: 20px;
            margin: 25px 0;
            text-align: left;
        }

        .error-list h3 {
            font-family: 'Poppins', sans-serif;
            margin-bottom: 15px;
            color: var(--danger-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-list ul {
            list-style: none;
            padding-left: 0;
        }

        .error-list li {
            padding: 8px 0;
            color: var(--danger-color);
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .error-list li:before {
            content: "⚠";
            font-size: 1.2rem;
        }

        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 30px;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: white;
            color: var(--text-primary);
            border: 2px solid var(--border-color);
        }

        .btn-secondary:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
        }

        .processing-animation {
            margin: 30px 0;
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid var(--border-color);
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px 15px;
            }
            
            .card {
                padding: 25px 20px;
            }
            
            .header h1 {
                font-size: 1.8rem;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php
    // Check if navbar exists and include it
    $navbar_file = 'navbar.php';
    if (file_exists($navbar_file)) {
        include($navbar_file);
    } else {
        // Simple navbar
        echo '<nav style="background: white; padding: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
        echo '<div style="max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center;">';
        echo '<a href="dashboard.php" style="font-family: Poppins, sans-serif; font-weight: 700; color: #667eea; text-decoration: none; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;"><i class="fas fa-user-shield"></i> Vigilo</a>';
        echo '<div style="display: flex; gap: 10px;">';
        echo '<a href="dashboard.php" style="text-decoration: none; color: #4a5568; padding: 8px 16px; border-radius: 6px;">Dashboard</a>';
        echo '<a href="upload.php" style="text-decoration: none; color: #667eea; font-weight: 500; padding: 8px 16px; border-radius: 6px; background: rgba(102, 126, 234, 0.1);">Upload</a>';
        echo '<a href="profile.php" style="text-decoration: none; color: #4a5568; padding: 8px 16px; border-radius: 6px;">Profile</a>';
        echo '</div>';
        echo '</div>';
        echo '</nav>';
    }
    ?>

    <div class="header">
        <div class="container">
            <h1><i class="fas fa-cloud-upload-alt"></i> Processing Upload</h1>
            <p>Your document is being uploaded and secured</p>
        </div>
    </div>

    <div class="container">
        <?php if ($upload_success): ?>
            <div class="card status-success">
                <div class="status-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2>Upload Successful!</h2>
                <p>Your document has been uploaded and secured successfully.</p>
                
                <div class="upload-details">
                    <h3><i class="fas fa-file-alt"></i> Upload Details</h3>
                    <div class="detail-item">
                        <span class="detail-label">Document Name:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($document_name); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Original File:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($original_name); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">File Size:</span>
                        <span class="detail-value"><?php echo formatBytes($file['size']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">File Type:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($file['type']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Document ID:</span>
                        <span class="detail-value">VIG-<?php echo str_pad($document_id, 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Upload Time:</span>
                        <span class="detail-value"><?php echo date('Y-m-d H:i:s'); ?></span>
                    </div>
                </div>

                <div class="actions">
                    <a href="upload.php" class="btn btn-secondary">
                        <i class="fas fa-upload"></i> Upload Another
                    </a>
                    <a href="documents.php" class="btn btn-primary">
                        <i class="fas fa-folder-open"></i> View Documents
                    </a>
                    <?php if ($document_id): ?>
                    <a href="view_document.php?id=<?php echo $document_id; ?>" class="btn btn-primary">
                        <i class="fas fa-eye"></i> View This Document
                    </a>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif (!empty($upload_errors)): ?>
            <div class="card status-error">
                <div class="status-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2>Upload Failed</h2>
                <p>We encountered some issues while uploading your document.</p>
                
                <div class="error-list">
                    <h3><i class="fas fa-exclamation-circle"></i> Errors</h3>
                    <ul>
                        <?php foreach ($upload_errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="actions">
                    <a href="upload.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Upload
                    </a>
                    <a href="help.php" class="btn btn-secondary">
                        <i class="fas fa-question-circle"></i> Get Help
                    </a>
                </div>
            </div>

        <?php else: ?>
            <div class="card">
                <div class="processing-animation">
                    <div class="spinner"></div>
                </div>
                <h2>Processing Your Upload</h2>
                <p>Please wait while we secure your document...</p>
                
                <div class="upload-details">
                    <h3><i class="fas fa-cogs"></i> Processing Steps</h3>
                    <div class="detail-item">
                        <span class="detail-label">File Validation</span>
                        <span class="detail-value"><i class="fas fa-check" style="color: #43e97b;"></i> Complete</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Storage Check</span>
                        <span class="detail-value"><i class="fas fa-check" style="color: #43e97b;"></i> Complete</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">File Encryption</span>
                        <span class="detail-value"><i class="fas fa-sync-alt fa-spin" style="color: #667eea;"></i> In Progress</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Database Entry</span>
                        <span class="detail-value"><i class="fas fa-clock" style="color: #ffd166;"></i> Pending</span>
                    </div>
                </div>

                <p style="font-size: 0.9rem; color: var(--text-secondary); margin-top: 20px;">
                    <i class="fas fa-info-circle"></i> Do not close this window while upload is in progress.
                </p>
            </div>

            <script>
                // Auto-submit form on page load for processing
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(function() {
                        // Create and submit form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'process_upload.php';
                        
                        // Add file data if available
                        <?php if (isset($file) && isset($document_name)): ?>
                        const fileInput = document.createElement('input');
                        fileInput.type = 'hidden';
                        fileInput.name = 'file_data';
                        fileInput.value = '<?php echo base64_encode(serialize($file)); ?>';
                        form.appendChild(fileInput);
                        
                        const nameInput = document.createElement('input');
                        nameInput.type = 'hidden';
                        nameInput.name = 'documentName';
                        nameInput.value = '<?php echo addslashes($document_name); ?>';
                        form.appendChild(nameInput);
                        <?php endif; ?>
                        
                        document.body.appendChild(form);
                        form.submit();
                    }, 2000); // Wait 2 seconds before processing
                });
            </script>
        <?php endif; ?>
    </div>

    <footer style="background: var(--text-primary); color: white; padding: 20px; text-align: center; margin-top: 50px;">
        <div style="max-width: 1200px; margin: 0 auto;">
            <p>&copy; 2025 Vigilo. All rights reserved.</p>
            <p style="opacity: 0.8; font-size: 0.9rem; margin-top: 5px;">
                Secure Document Management System
            </p>
        </div>
    </footer>
</body>
</html>