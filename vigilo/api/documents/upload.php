<?php
session_start();
header('Content-Type: application/json');
require_once('../../config/db.php');

// Start output buffering to catch any stray output
ob_start();

$response = [
    'success' => false,
    'message' => 'Unknown error'
];

try {
    // Check if user is logged in
    if(!isset($_SESSION['user_id'])){
        $response['message'] = 'You must log in first';
        throw new Exception('Not authenticated');
    }

    $userId = $_SESSION['user_id'];
    
    // Debug logging
    error_log("Upload request for user ID: $userId");
    error_log("Files array: " . print_r($_FILES, true));
    error_log("Post data: " . print_r($_POST, true));

    // Check if a file was uploaded - look for 'file' field (from frontend)
    if(!isset($_FILES['file'])){
        // Also check for 'document' field for backward compatibility
        if(!isset($_FILES['document'])){
            $response['debug'] = [
                'files_received' => array_keys($_FILES),
                'expected_field' => 'file or document'
            ];
            $response['message'] = 'No file received. Please select a file to upload.';
            throw new Exception('No file in upload request');
        } else {
            // Use 'document' field if it exists
            $file = $_FILES['document'];
            $response['debug']['using_field'] = 'document';
        }
    } else {
        // Use 'file' field (from frontend)
        $file = $_FILES['file'];
        $response['debug']['using_field'] = 'file';
    }

    // Check for PHP upload errors
    if($file['error'] !== UPLOAD_ERR_OK){
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        
        $error_msg = $error_messages[$file['error']] ?? "Upload error ({$file['error']})";
        $response['message'] = $error_msg;
        throw new Exception($error_msg);
    }

    // Ensure upload folder exists
    $uploadDir = '../../uploads/docs/';
    if(!file_exists($uploadDir)){
        if(!mkdir($uploadDir, 0777, true)){
            $response['message'] = 'Failed to create upload directory';
            throw new Exception('Could not create upload directory');
        }
    }

    // Check if directory is writable
    if(!is_writable($uploadDir)){
        $response['message'] = 'Upload directory is not writable';
        $response['debug']['upload_dir'] = $uploadDir;
        $response['debug']['dir_perms'] = substr(sprintf('%o', fileperms($uploadDir)), -4);
        throw new Exception('Upload directory not writable');
    }

    // Generate unique file name
    $originalName = basename($file['name']);
    $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $uniqueName = uniqid() . '_' . time() . '.' . $fileExtension;
    $targetFile = $uploadDir . $uniqueName;

    $response['debug']['original_name'] = $originalName;
    $response['debug']['unique_name'] = $uniqueName;
    $response['debug']['target_file'] = $targetFile;

    // Move uploaded file
    if(move_uploaded_file($file['tmp_name'], $targetFile)){
        // Set file permissions
        chmod($targetFile, 0644);
        
        // Get additional form data from frontend
        $documentName = $_POST['documentName'] ?? $originalName;
        $description = $_POST['description'] ?? '';
        $encryptFile = isset($_POST['encryptFile']) && $_POST['encryptFile'] == '1';
        $enableOCR = isset($_POST['enableOCR']) && $_POST['enableOCR'] == '1';
        $generateQR = isset($_POST['generateQR']) && $_POST['generateQR'] == '1';
        
        // Store relative path in database
        $relativePath = 'uploads/docs/' . $uniqueName;
        
        // Check what columns exist in the documents table
        $columns_result = $conn->query("DESCRIBE documents");
        $columns = [];
        while ($col = $columns_result->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        
        // Prepare SQL based on available columns
        if (in_array('original_name', $columns) && 
            in_array('file_type', $columns) && 
            in_array('file_size', $columns) &&
            in_array('description', $columns)) {
            
            // Full insert with all columns
            $sql = "INSERT INTO documents (user_id, file_name, original_name, file_path, file_type, file_size, description, uploaded_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception('Database prepare failed: ' . $conn->error);
            }
            
            $fileType = mime_content_type($targetFile);
            $fileSize = filesize($targetFile);
            
            $stmt->bind_param("issssis", 
                $userId, 
                $uniqueName, 
                $originalName, 
                $relativePath, 
                $fileType, 
                $fileSize, 
                $description
            );
        } else {
            // Simplified insert for basic table structure
            $sql = "INSERT INTO documents (user_id, file_name, file_path, uploaded_at) 
                    VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception('Database prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("iss", 
                $userId, 
                $uniqueName, 
                $relativePath
            );
        }

        if($stmt->execute()){
            $documentId = $conn->insert_id;
            
            // Update user storage usage if the column exists
            if (in_array('storage_used', $conn->query("DESCRIBE users")->fetch_all(MYSQLI_ASSOC)[0] ?? [])) {
                $fileSize = filesize($targetFile);
                $updateStmt = $conn->prepare("UPDATE users SET storage_used = storage_used + ? WHERE id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param("ii", $fileSize, $userId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
            }
            
            $response['success'] = true;
            $response['message'] = 'File uploaded successfully';
            $response['document_id'] = $documentId;
            $response['file_name'] = $originalName;
            $response['file_path'] = $relativePath;
            unset($response['debug']); // Remove debug info on success
            
        } else {
            // Delete the uploaded file if database insert fails
            unlink($targetFile);
            $response['message'] = 'Database error: ' . $stmt->error;
            throw new Exception('Database execute failed: ' . $stmt->error);
        }

        $stmt->close();
        
    } else {
        $response['message'] = 'Failed to save uploaded file';
        $response['debug']['move_error'] = true;
        $response['debug']['tmp_exists'] = file_exists($file['tmp_name']);
        $response['debug']['tmp_readable'] = is_readable($file['tmp_name']);
        throw new Exception('move_uploaded_file failed');
    }

} catch (Exception $e) {
    error_log("Upload exception: " . $e->getMessage());
    // Response is already set
}

// Clean any output buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Send JSON response
echo json_encode($response);
exit;
?>