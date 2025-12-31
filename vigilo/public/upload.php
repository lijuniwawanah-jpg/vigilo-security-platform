<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// More secure session validation
if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once('../config/db.php');

// Verify database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$user_id = $_SESSION['user_id'];

// Get user's storage info
$stmt = $conn->prepare("SELECT storage_limit, storage_used FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$storage_limit = $user['storage_limit'] ?? 1073741824; // Default 1GB
$storage_used = $user['storage_used'] ?? 0;
$available_space = $storage_limit - $storage_used;

// Calculate percentages
$used_percentage = $storage_limit > 0 ? round(($storage_used / $storage_limit) * 100, 1) : 0;
$available_percentage = 100 - $used_percentage;

// Format bytes for display
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
    <title>Upload Document - Vigilo</title>
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
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
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

        .upload-container {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
        }

        @media (max-width: 900px) {
            .upload-container {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .card h2 {
            font-family: 'Poppins', sans-serif;
            margin-bottom: 20px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card h2 i {
            color: var(--primary-color);
        }

        .upload-area {
            border: 3px dashed var(--border-color);
            border-radius: var(--border-radius);
            padding: 60px 30px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            background: white;
        }

        .upload-area:hover {
            border-color: var(--primary-color);
            background: rgba(102, 126, 234, 0.05);
        }

        .upload-area.dragover {
            border-color: var(--primary-color);
            background: rgba(102, 126, 234, 0.1);
        }

        .upload-icon {
            font-size: 4rem;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .upload-text h3 {
            font-family: 'Poppins', sans-serif;
            margin-bottom: 10px;
            font-size: 1.5rem;
        }

        .upload-text p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        .browse-btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .browse-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .file-input {
            display: none;
        }

        .selected-file {
            margin-top: 20px;
            padding: 15px;
            background: rgba(102, 126, 234, 0.1);
            border-radius: var(--border-radius);
            display: none;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .file-icon {
            font-size: 2rem;
            color: var(--primary-color);
        }

        .file-details h4 {
            margin-bottom: 5px;
            word-break: break-all;
        }

        .file-details p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .remove-file {
            margin-left: auto;
            background: none;
            border: none;
            color: var(--danger-color);
            cursor: pointer;
            font-size: 1.2rem;
        }

        .upload-options {
            margin-top: 30px;
        }

        .option-group {
            margin-bottom: 20px;
        }

        .option-group label {
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

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            cursor: pointer;
            margin: 0;
        }

        .upload-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1.1rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .upload-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .upload-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .storage-info {
            margin-bottom: 25px;
        }

        .storage-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }

        .progress-bar {
            height: 10px;
            background: var(--border-color);
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 5px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 5px;
            transition: width 0.3s ease;
        }

        .storage-warning {
            color: var(--danger-color);
            font-size: 0.9rem;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .file-types {
            margin-top: 25px;
        }

        .file-type-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 15px;
        }

        .file-type-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: rgba(102, 126, 234, 0.05);
            border-radius: var(--border-radius);
        }

        .file-type-icon {
            color: var(--primary-color);
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: none;
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

        .alert-warning {
            background: rgba(255, 209, 102, 0.1);
            border: 1px solid rgba(255, 209, 102, 0.3);
            color: #f57c00;
        }

        .upload-progress {
            margin-top: 20px;
            display: none;
        }

        .progress-container {
            background: var(--border-color);
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .progress-value {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px;
            transition: width 0.3s ease;
            width: 0%;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .navbar {
            background: white;
            padding: 1rem 0;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        .nav-container {
            max-width: 1200px;
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
                <a href="upload.php" class="nav-link active">Upload</a>
                <a href="documents.php" class="nav-link">Documents</a>
                <a href="profile.php" class="nav-link">Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="header">
        <div class="container">
            <h1><i class="fas fa-cloud-upload-alt"></i> Upload Document</h1>
            <p>Securely upload and encrypt your documents with automatic OCR processing</p>
        </div>
    </div>

    <div class="container">
        <!-- Alerts -->
        <div id="alert" class="alert" style="display: none;"></div>

        <div class="upload-container">
            <!-- Main Upload Area -->
            <div>
                <div class="card">
                    <h2><i class="fas fa-file-upload"></i> Upload Your Document</h2>
                    
                    <div class="upload-area" id="uploadArea">
                        <div class="upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div class="upload-text">
                            <h3>Drag & Drop your file here</h3>
                            <p>or click to select from your computer</p>
                            <button type="button" class="browse-btn" id="browseBtn">Select File</button>
                        </div>
                        <input type="file" id="fileInput" class="file-input" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif,.webp">
                    </div>

                    <div class="selected-file" id="selectedFile">
                        <div class="file-info">
                            <div class="file-icon">
                                <i class="fas fa-file"></i>
                            </div>
                            <div class="file-details">
                                <h4 id="fileName">Document.pdf</h4>
                                <p id="fileSize">0 KB</p>
                            </div>
                            <button type="button" class="remove-file" id="removeFile">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Upload Progress -->
                    <div class="upload-progress" id="uploadProgress">
                        <div class="progress-container">
                            <div class="progress-value" id="progressValue"></div>
                        </div>
                        <div class="progress-text">
                            <span id="progressText">0%</span>
                            <span id="speedText"></span>
                        </div>
                    </div>

                    <!-- Upload Options -->
                    <div class="upload-options">
                        <div id="uploadForm">
                            <div class="option-group">
                                <label for="documentName">Document Name (Optional)</label>
                                <input type="text" id="documentName" class="form-control" 
                                       placeholder="Enter a custom name for this document">
                            </div>

                            <div class="option-group">
                                <label for="description">Description (Optional)</label>
                                <textarea id="description" class="form-control" 
                                          rows="3" placeholder="Add a description for this document"></textarea>
                            </div>

                            <div class="checkbox-group">
                                <input type="checkbox" id="encryptFile" checked>
                                <label for="encryptFile">Encrypt file with AES-256</label>
                            </div>

                            <div class="checkbox-group">
                                <input type="checkbox" id="enableOCR" checked>
                                <label for="enableOCR">Enable OCR text extraction</label>
                            </div>

                            <div class="checkbox-group">
                                <input type="checkbox" id="generateQR">
                                <label for="generateQR">Generate verification QR code</label>
                            </div>

                            <button type="button" class="upload-btn" id="uploadBtn" disabled>
                                <i class="fas fa-upload"></i> Upload Document
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div>
                <!-- Storage Info -->
                <div class="card">
                    <h2><i class="fas fa-database"></i> Storage</h2>
                    <div class="storage-info">
                        <div class="storage-stats">
                            <span>Used: <?= formatBytes($storage_used) ?></span>
                            <span>Available: <?= formatBytes($available_space) ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= min($used_percentage, 100) ?>%"></div>
                        </div>
                        <div class="storage-stats">
                            <span><?= $used_percentage ?>% used</span>
                            <span><?= $available_percentage ?>% free</span>
                        </div>
                        <?php if($available_space < 10485760): ?>
                        <div class="storage-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Low storage space</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- File Types -->
                <div class="card">
                    <h2><i class="fas fa-file-alt"></i> Supported Files</h2>
                    <div class="file-types">
                        <p style="color: var(--text-secondary); margin-bottom: 15px;">
                            Maximum file size: 100MB per file
                        </p>
                        <div class="file-type-list">
                            <div class="file-type-item">
                                <i class="fas fa-file-pdf file-type-icon"></i>
                                <span>PDF Documents</span>
                            </div>
                            <div class="file-type-item">
                                <i class="fas fa-file-word file-type-icon"></i>
                                <span>Word Docs</span>
                            </div>
                            <div class="file-type-item">
                                <i class="fas fa-file-image file-type-icon"></i>
                                <span>Images</span>
                            </div>
                            <div class="file-type-item">
                                <i class="fas fa-file-text file-type-icon"></i>
                                <span>Text Files</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Tips -->
                <div class="card">
                    <h2><i class="fas fa-lightbulb"></i> Tips</h2>
                    <ul style="padding-left: 20px; color: var(--text-secondary);">
                        <li style="margin-bottom: 10px;">Use descriptive names for easier searching</li>
                        <li style="margin-bottom: 10px;">Enable OCR for searchable text in images/PDFs</li>
                        <li style="margin-bottom: 10px;">Encryption is enabled by default for security</li>
                        <li>Keep file sizes under 100MB for faster uploads</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // DOM Elements
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        const browseBtn = document.getElementById('browseBtn');
        const selectedFile = document.getElementById('selectedFile');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const removeFile = document.getElementById('removeFile');
        const uploadBtn = document.getElementById('uploadBtn');
        const alertBox = document.getElementById('alert');
        const uploadProgress = document.getElementById('uploadProgress');
        const progressValue = document.getElementById('progressValue');
        const progressText = document.getElementById('progressText');
        const speedText = document.getElementById('speedText');

        // Available storage in bytes (from PHP)
        const availableSpace = <?= $available_space ?>;

        // Current file info
        let currentFile = null;
        let uploadInProgress = false;
        let uploadStartTime = 0;

        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            try {
                setupEventListeners();
                checkStorageWarning();
            } catch (error) {
                console.error('Initialization error:', error);
                showAlert('Page initialization failed. Please refresh.', 'error');
            }
        });

        function setupEventListeners() {
            browseBtn.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', handleFileSelect);
            uploadArea.addEventListener('click', () => fileInput.click());
            
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.classList.add('dragover');
            });
            
            uploadArea.addEventListener('dragleave', () => {
                uploadArea.classList.remove('dragover');
            });
            
            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
                
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    handleFileSelect();
                }
            });
            
            removeFile.addEventListener('click', () => {
                currentFile = null;
                fileInput.value = '';
                selectedFile.style.display = 'none';
                uploadBtn.disabled = true;
                showAlert('File removed', 'warning');
            });
            
            uploadBtn.addEventListener('click', handleUpload);
        }

        function handleFileSelect() {
            if (!fileInput.files.length) return;
            
            const file = fileInput.files[0];
            
            // Check file size (100MB limit)
            const maxSize = 100 * 1024 * 1024;
            if (file.size > maxSize) {
                showAlert('File size exceeds 100MB limit', 'error');
                fileInput.value = '';
                return;
            }
            
            // Check available storage
            if (!checkStorageAvailability(file.size)) {
                fileInput.value = '';
                return;
            }
            
            currentFile = file;
            
            // Update UI
            fileName.textContent = file.name;
            fileSize.textContent = formatBytes(file.size);
            selectedFile.style.display = 'block';
            uploadBtn.disabled = false;
            
            // Auto-fill document name with filename without extension
            const docNameInput = document.getElementById('documentName');
            if (!docNameInput.value) {
                const nameWithoutExt = file.name.replace(/\.[^/.]+$/, "");
                docNameInput.value = nameWithoutExt;
            }
            
            showAlert(`Ready to upload: ${file.name} (${formatBytes(file.size)})`, 'success');
        }

        function checkStorageAvailability(fileSize) {
            if (fileSize > availableSpace) {
                showAlert(`Not enough space. File size: ${formatBytes(fileSize)}, Available: ${formatBytes(availableSpace)}`, 'error');
                return false;
            }
            return true;
        }

        function checkStorageWarning() {
            if (availableSpace < 10485760) {
                showAlert('Warning: You have less than 10MB of storage space remaining.', 'warning');
            }
        }

        function setLoadingState(isLoading) {
            if (isLoading) {
                uploadBtn.disabled = true;
                uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
                fileInput.disabled = true;
                browseBtn.disabled = true;
            } else {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Document';
                fileInput.disabled = false;
                browseBtn.disabled = false;
            }
        }

        async function handleUpload() {
            if (!currentFile || uploadInProgress) return;
            
            // Check storage again before upload
            if (!checkStorageAvailability(currentFile.size)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('file', currentFile);
            formData.append('documentName', document.getElementById('documentName').value);
            formData.append('description', document.getElementById('description').value);
            formData.append('encryptFile', document.getElementById('encryptFile').checked ? '1' : '0');
            formData.append('enableOCR', document.getElementById('enableOCR').checked ? '1' : '0');
            formData.append('generateQR', document.getElementById('generateQR').checked ? '1' : '0');
            
            uploadInProgress = true;
            uploadStartTime = Date.now();
            setLoadingState(true);
            uploadProgress.style.display = 'block';
            progressValue.style.width = '0%';
            progressText.textContent = '0%';
            speedText.textContent = '';
            
            try {
                const xhr = new XMLHttpRequest();
                
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percentComplete = Math.round((e.loaded / e.total) * 100);
                        progressValue.style.width = percentComplete + '%';
                        progressText.textContent = percentComplete + '%';
                        
                        // Calculate upload speed
                        const elapsedTime = (Date.now() - uploadStartTime) / 1000;
                        if (elapsedTime > 0) {
                            const speed = e.loaded / elapsedTime;
                            speedText.textContent = formatBytes(speed) + '/s';
                        }
                    }
                });
                
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        uploadInProgress = false;
                        setLoadingState(false);
                        
                        if (xhr.status === 200) {
                            try {
                                console.log('Server Response:', xhr.responseText);
                                const response = JSON.parse(xhr.responseText);
                                
                                if (response.success) {
                                    showAlert(response.message || 'File uploaded successfully!', 'success');
                                    resetForm();
                                    
                                    // Redirect after 2 seconds
                                    setTimeout(() => {
                                        window.location.href = 'documents.php';
                                    }, 2000);
                                } else {
                                    showAlert(response.message || 'Upload failed', 'error');
                                }
                            } catch (e) {
                                console.error('JSON Parse Error:', e);
                                console.error('Raw Response:', xhr.responseText);
                                showAlert('Invalid server response format. Please check console.', 'error');
                            }
                        } else {
                            showAlert('Server error: ' + xhr.status, 'error');
                        }
                        uploadProgress.style.display = 'none';
                    }
                };
                
                // Use the existing API endpoint
                xhr.open('POST', '../api/documents/upload.php');
                xhr.send(formData);
                
            } catch (error) {
                console.error('Upload error:', error);
                showAlert('Upload failed. Please try again.', 'error');
                uploadInProgress = false;
                setLoadingState(false);
                uploadProgress.style.display = 'none';
            }
        }

        function resetForm() {
            currentFile = null;
            fileInput.value = '';
            selectedFile.style.display = 'none';
            document.getElementById('documentName').value = '';
            document.getElementById('description').value = '';
            document.getElementById('encryptFile').checked = true;
            document.getElementById('enableOCR').checked = true;
            document.getElementById('generateQR').checked = false;
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Document';
        }

        function showAlert(message, type) {
            alertBox.textContent = message;
            alertBox.className = `alert alert-${type}`;
            alertBox.style.display = 'block';
            
            if (type !== 'success') {
                setTimeout(() => {
                    alertBox.style.display = 'none';
                }, 5000);
            }
        }

        function formatBytes(bytes, decimals = 2) {
            if (bytes == 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>