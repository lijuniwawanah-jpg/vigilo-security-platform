<?php
// items.php - Enhanced Items Management System with Lost/Found & Rewards
session_start();
require_once('../config/db.php');

if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if enhanced items table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'items'");
if (!$table_check || $table_check->num_rows === 0) {
    createEnhancedItemsTable($conn);
}

// Handle form submissions
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(isset($_POST['add_item'])) {
        addItem($conn, $user_id);
    } elseif(isset($_POST['update_item'])) {
        updateItem($conn, $user_id);
    } elseif(isset($_POST['delete_item'])) {
        deleteItem($conn, $user_id);
    } elseif(isset($_POST['verify_item'])) {
        verifyItem($conn, $user_id);
    } elseif(isset($_POST['report_lost'])) {
        reportLostItem($conn, $user_id);
    } elseif(isset($_POST['mark_found'])) {
        markItemAsFound($conn, $user_id);
    } elseif(isset($_POST['submit_claim'])) {
        submitRewardClaim($conn, $user_id);
    } elseif(isset($_POST['update_claim_status'])) {
        updateClaimStatus($conn, $user_id);
    }
}

// Get search parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';

// Build query with filters
$sql = "SELECT * FROM items WHERE user_id = ?";
$params = [$user_id];
$types = "i";

if(!empty($search)) {
    $sql .= " AND (item_name LIKE ? OR serial_number LIKE ? OR description LIKE ? OR brand LIKE ? OR model LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
    $types .= "sssss";
}

if(!empty($category)) {
    $sql .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}

if(!empty($status)) {
    $sql .= " AND status = ?";
    $params[] = $status;
    $types .= "s";
}

$sql .= " ORDER BY created_at DESC";

// Prepare and execute
$stmt = $conn->prepare($sql);
if ($stmt) {
    if($types === "i") {
        $stmt->bind_param($types, $params[0]);
    } else {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $items = [];
    error_log("Items query error: " . $conn->error);
}

// Get item statistics
$stats = getItemStatistics($conn, $user_id);

// Get categories for filter
$categories_stmt = $conn->prepare("SELECT DISTINCT category FROM items WHERE user_id = ? AND category IS NOT NULL AND category != '' ORDER BY category");
$categories = [];
if($categories_stmt) {
    $categories_stmt->bind_param("i", $user_id);
    $categories_stmt->execute();
    $categories_result = $categories_stmt->get_result();
    while($row = $categories_result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    $categories_stmt->close();
}

// Get lost/stolen items
$lost_items_stmt = $conn->prepare("SELECT * FROM items WHERE user_id = ? AND status IN ('lost', 'stolen', 'damaged') ORDER BY reported_date DESC LIMIT 5");
$lost_items = [];
if($lost_items_stmt) {
    $lost_items_stmt->bind_param("i", $user_id);
    $lost_items_stmt->execute();
    $lost_result = $lost_items_stmt->get_result();
    $lost_items = $lost_result->fetch_all(MYSQLI_ASSOC);
    $lost_items_stmt->close();
}

// Get pending claims (if rewards_claims table exists)
$pending_claims = [];
try {
    $claims_stmt = $conn->prepare("
        SELECT rc.*, i.item_name, i.reward_amount, i.contact_phone, i.contact_email 
        FROM reward_claims rc 
        JOIN items i ON rc.item_id = i.id 
        WHERE i.user_id = ? AND rc.status = 'pending'
        ORDER BY rc.claim_date DESC
    ");
    if($claims_stmt) {
        $claims_stmt->bind_param("i", $user_id);
        $claims_stmt->execute();
        $claims_result = $claims_stmt->get_result();
        $pending_claims = $claims_result->fetch_all(MYSQLI_ASSOC);
        $claims_stmt->close();
    }
} catch (Exception $e) {
    // Table might not exist yet
    error_log("Reward claims table error: " . $e->getMessage());
}

function createEnhancedItemsTable($conn) {
    // Drop table if exists to recreate with new structure
    $conn->query("DROP TABLE IF EXISTS items");
    
    // Enhanced items table with reward fields
    $sql = "CREATE TABLE items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        serial_number VARCHAR(100),
        category VARCHAR(100),
        brand VARCHAR(100),
        model VARCHAR(100),
        description TEXT,
        purchase_date DATE DEFAULT NULL,
        purchase_price DECIMAL(10,2) DEFAULT NULL,
        current_value DECIMAL(10,2) DEFAULT NULL,
        location VARCHAR(255),
        status ENUM('active', 'lost', 'stolen', 'sold', 'damaged', 'archived', 'found') DEFAULT 'active',
        condition_rating INT DEFAULT 5,
        warranty_expiry DATE DEFAULT NULL,
        insurance_policy VARCHAR(255),
        qr_code_path VARCHAR(500),
        photos JSON DEFAULT NULL,
        documents JSON DEFAULT NULL,
        verification_code VARCHAR(100),
        is_verified BOOLEAN DEFAULT FALSE,
        last_verified DATE DEFAULT NULL,
        
        -- Lost/Stolen reporting fields
        reported_date DATETIME DEFAULT NULL,
        incident_location VARCHAR(255),
        incident_description TEXT,
        reward_amount DECIMAL(10,2) DEFAULT 0,
        contact_phone VARCHAR(20),
        contact_email VARCHAR(255),
        is_public BOOLEAN DEFAULT FALSE,
        found_by_name VARCHAR(255),
        found_by_contact VARCHAR(255),
        found_date DATETIME DEFAULT NULL,
        
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if(!$conn->query($sql)) {
        die("Error creating items table: " . $conn->error);
    }
    
    // Create reward claims table
    $sql2 = "CREATE TABLE IF NOT EXISTS reward_claims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        claimer_name VARCHAR(255) NOT NULL,
        claimer_phone VARCHAR(20),
        claimer_email VARCHAR(255),
        claim_message TEXT,
        claim_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'approved', 'rejected', 'paid') DEFAULT 'pending',
        proof_images JSON DEFAULT NULL,
        admin_notes TEXT,
        paid_date DATETIME DEFAULT NULL,
        payment_method VARCHAR(50),
        transaction_id VARCHAR(100),
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
        INDEX idx_item (item_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->query($sql2);
    
    // Create lost_items_public table for public search
    $sql3 = "CREATE TABLE IF NOT EXISTS lost_items_public (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        item_name VARCHAR(255),
        category VARCHAR(100),
        brand VARCHAR(100),
        description TEXT,
        incident_location VARCHAR(255),
        incident_date DATETIME,
        reward_amount DECIMAL(10,2),
        contact_phone VARCHAR(20),
        contact_email VARCHAR(255),
        photos JSON DEFAULT NULL,
        status ENUM('lost', 'stolen', 'damaged') DEFAULT 'lost',
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
        INDEX idx_active (is_active),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->query($sql3);
}
function addItem($conn, $user_id) {
    $item_name = trim($_POST['item_name']);
    $serial_number = trim($_POST['serial_number'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Handle date values properly
    $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
    if ($purchase_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchase_date)) {
        $_SESSION['error'] = "Invalid purchase date format. Use YYYY-MM-DD";
        return;
    }
    
    $purchase_price = !empty($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : null;
    $current_value = !empty($_POST['current_value']) ? floatval($_POST['current_value']) : null;
    $location = trim($_POST['location'] ?? '');
    $condition_rating = intval($_POST['condition_rating'] ?? 5);
    
    // Handle warranty expiry - check if it's a valid date
    $warranty_expiry = null;
    if (!empty($_POST['warranty_expiry'])) {
        $warranty_expiry = $_POST['warranty_expiry'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $warranty_expiry)) {
            $_SESSION['error'] = "Invalid warranty expiry date format. Use YYYY-MM-DD";
            return;
        }
    }
    
    $insurance_policy = trim($_POST['insurance_policy'] ?? '');
    
    // Generate verification code
    $verification_code = bin2hex(random_bytes(16));
    
    // Handle file uploads
    $photos = [];
    if(isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
        $photos = handleFileUploads($_FILES['photos'], 'photos', $user_id);
    }
    
    $documents = [];
    if(isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
        $documents = handleFileUploads($_FILES['documents'], 'documents', $user_id);
    }
    
    // Generate QR code with item information
    $qr_data = json_encode([
        'item_name' => $item_name,
        'serial_number' => $serial_number,
        'brand' => $brand,
        'model' => $model,
        'verification_code' => $verification_code,
        'owner_id' => $user_id,
        'timestamp' => time()
    ]);
    
    $qr_code_path = generateQRCode($qr_data, $verification_code, $user_id);
    
    $stmt = $conn->prepare("INSERT INTO items (
        user_id, item_name, serial_number, category, brand, model, description, 
        purchase_date, purchase_price, current_value, location, condition_rating,
        warranty_expiry, insurance_policy, qr_code_path, photos, documents, 
        verification_code
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $photos_json = !empty($photos) ? json_encode($photos) : null;
    $documents_json = !empty($documents) ? json_encode($documents) : null;
    
    $stmt->bind_param("isssssssddssisssss", 
        $user_id, $item_name, $serial_number, $category, $brand, $model, 
        $description, $purchase_date, $purchase_price, $current_value, 
        $location, $condition_rating, $warranty_expiry, $insurance_policy,
        $qr_code_path, $photos_json, $documents_json, $verification_code
    );
    
    if($stmt->execute()) {
        $item_id = $stmt->insert_id;
        
        // Also create a public QR code with basic info for finders
        $public_qr_data = json_encode([
            'item_id' => $item_id,
            'item_name' => $item_name,
            'contact_url' => "https://" . $_SERVER['HTTP_HOST'] . "/report_found.php?item=" . $item_id,
            'verification_code' => $verification_code
        ]);
        
        $public_qr_path = generateQRCode($public_qr_data, $verification_code . '_public', $user_id);
        
        // Update item with public QR code path
        $update_qr_stmt = $conn->prepare("UPDATE items SET qr_code_public_path = ? WHERE id = ?");
        $update_qr_stmt->bind_param("si", $public_qr_path, $item_id);
        $update_qr_stmt->execute();
        
        $_SESSION['success'] = "Item added successfully! QR code generated.";
        $_SESSION['new_item_id'] = $item_id; // For QR code display
        
        header("Location: items.php");
        exit;
    } else {
        $_SESSION['error'] = "Failed to add item: " . $conn->error;
    }
}
function updateItem($conn, $user_id) {
    // Implementation similar to addItem but with UPDATE
}

function deleteItem($conn, $user_id) {
    // Implementation
}

function verifyItem($conn, $user_id) {
    // Implementation
}

function reportLostItem($conn, $user_id) {
    $item_id = intval($_POST['item_id']);
    $status = $_POST['status']; // 'lost', 'stolen', or 'damaged'
    $incident_location = trim($_POST['incident_location'] ?? '');
    $incident_description = trim($_POST['incident_description'] ?? '');
    $reward_amount = floatval($_POST['reward_amount'] ?? 0);
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $is_public = isset($_POST['is_public']) ? 1 : 0;
    
    // Check if item belongs to user - FIXED VERSION
    $check_stmt = $conn->prepare("SELECT id, item_name FROM items WHERE id = ? AND user_id = ?");
    $check_stmt->bind_param("ii", $item_id, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if($check_result->num_rows === 0) {
        // Try to get item info for better error message
        $alt_check = $conn->prepare("SELECT id FROM items WHERE id = ?");
        $alt_check->bind_param("i", $item_id);
        $alt_check->execute();
        $alt_result = $alt_check->get_result();
        
        if($alt_result->num_rows === 0) {
            $_SESSION['error'] = "Item not found.";
        } else {
            $_SESSION['error'] = "You don't have permission to report this item.";
        }
        return;
    }
    
    $stmt = $conn->prepare("UPDATE items SET 
        status = ?, 
        reported_date = NOW(),
        incident_location = ?,
        incident_description = ?,
        reward_amount = ?,
        contact_phone = ?,
        contact_email = ?,
        is_public = ?
        WHERE id = ? AND user_id = ?
    ");
    
    $stmt->bind_param("sssdssiii", 
        $status, 
        $incident_location,
        $incident_description,
        $reward_amount,
        $contact_phone,
        $contact_email,
        $is_public,
        $item_id,
        $user_id
    );
    
    if($stmt->execute()) {
        // Add to public database if public
        if($is_public) {
            addToPublicDatabase($conn, $item_id);
        }
        
        $_SESSION['success'] = "Item reported as " . $status . " successfully!" . 
                              ($reward_amount > 0 ? " Reward of $" . number_format($reward_amount, 2) . " offered." : "");
        header("Location: items.php");
        exit;
    } else {
        $_SESSION['error'] = "Failed to report item: " . $conn->error;
    }
}

function addToPublicDatabase($conn, $item_id) {
    // Get item details
    $stmt = $conn->prepare("SELECT item_name, category, brand, description, incident_location, reward_amount, contact_phone, contact_email, photos, status FROM items WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    
    // Insert into public database
    $public_stmt = $conn->prepare("INSERT INTO lost_items_public (item_id, item_name, category, brand, description, incident_location, incident_date, reward_amount, contact_phone, contact_email, photos, status) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_active = 1");
    $public_stmt->bind_param("isssssdssss", $item_id, $item['item_name'], $item['category'], $item['brand'], $item['description'], $item['incident_location'], $item['reward_amount'], $item['contact_phone'], $item['contact_email'], $item['photos'], $item['status']);
    $public_stmt->execute();
}

function markItemAsFound($conn, $user_id) {
    $item_id = intval($_POST['item_id']);
    $found_by_name = trim($_POST['found_by_name'] ?? '');
    $found_by_contact = trim($_POST['found_by_contact'] ?? '');
    
    $stmt = $conn->prepare("UPDATE items SET 
        status = 'found',
        found_by_name = ?,
        found_by_contact = ?,
        found_date = NOW()
        WHERE id = ? AND user_id = ?
    ");
    
    $stmt->bind_param("ssii", $found_by_name, $found_by_contact, $item_id, $user_id);
    
    if($stmt->execute()) {
        // Remove from public database
        $public_stmt = $conn->prepare("UPDATE lost_items_public SET is_active = 0 WHERE item_id = ?");
        $public_stmt->bind_param("i", $item_id);
        $public_stmt->execute();
        
        $_SESSION['success'] = "Item marked as found!";
        header("Location: items.php");
        exit;
    } else {
        $_SESSION['error'] = "Failed to mark item as found: " . $conn->error;
    }
}

function submitRewardClaim($conn, $user_id) {
    $item_id = intval($_POST['item_id']);
    $claimer_name = trim($_POST['claimer_name']);
    $claimer_phone = trim($_POST['claimer_phone'] ?? '');
    $claimer_email = trim($_POST['claimer_email'] ?? '');
    $claim_message = trim($_POST['claim_message'] ?? '');
    
    // Check if item exists and has reward
    $check_stmt = $conn->prepare("SELECT id, item_name, reward_amount FROM items WHERE id = ?");
    $check_stmt->bind_param("i", $item_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if($check_result->num_rows === 0) {
        $_SESSION['error'] = "Item not found";
        return;
    }
    
    $item = $check_result->fetch_assoc();
    
    if($item['reward_amount'] <= 0) {
        $_SESSION['error'] = "This item does not have a reward";
        return;
    }
    
    $stmt = $conn->prepare("INSERT INTO reward_claims 
        (item_id, claimer_name, claimer_phone, claimer_email, claim_message, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    
    $stmt->bind_param("issss", $item_id, $claimer_name, $claimer_phone, $claimer_email, $claim_message);
    
    if($stmt->execute()) {
        $_SESSION['success'] = "Reward claim submitted successfully! The owner will review your claim.";
        header("Location: items.php");
        exit;
    } else {
        $_SESSION['error'] = "Failed to submit claim: " . $conn->error;
    }
}

function updateClaimStatus($conn, $user_id) {
    $claim_id = intval($_POST['claim_id']);
    $status = $_POST['status'];
    $admin_notes = trim($_POST['admin_notes'] ?? '');
    
    // Verify user owns the item
    $check_stmt = $conn->prepare("
        SELECT i.id 
        FROM items i 
        JOIN reward_claims rc ON i.id = rc.item_id 
        WHERE rc.id = ? AND i.user_id = ?
    ");
    $check_stmt->bind_param("ii", $claim_id, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if($check_result->num_rows === 0) {
        $_SESSION['error'] = "Claim not found or access denied";
        return;
    }
    
    $stmt = $conn->prepare("UPDATE reward_claims SET 
        status = ?,
        admin_notes = ?,
        paid_date = CASE WHEN ? = 'paid' THEN NOW() ELSE NULL END
        WHERE id = ?
    ");
    
    $stmt->bind_param("sssi", $status, $admin_notes, $status, $claim_id);
    
    if($stmt->execute()) {
        $_SESSION['success'] = "Claim status updated to " . $status;
        header("Location: items.php");
        exit;
    } else {
        $_SESSION['error'] = "Failed to update claim status: " . $conn->error;
    }
}

function getItemStatistics($conn, $user_id) {
    $stats = [
        'total' => 0,
        'active' => 0,
        'lost' => 0,
        'stolen' => 0,
        'damaged' => 0,
        'total_value' => 0,
        'verified' => 0,
        'total_rewards' => 0,
        'pending_claims' => 0
    ];
    
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost,
            SUM(CASE WHEN status = 'stolen' THEN 1 ELSE 0 END) as stolen,
            SUM(CASE WHEN status = 'damaged' THEN 1 ELSE 0 END) as damaged,
            COALESCE(SUM(current_value), 0) as total_value,
            SUM(CASE WHEN is_verified = TRUE THEN 1 ELSE 0 END) as verified,
            COALESCE(SUM(reward_amount), 0) as total_rewards
        FROM items 
        WHERE user_id = ?
    ");
    
    if($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if($row = $result->fetch_assoc()) {
            $stats = array_merge($stats, $row);
        }
        $stmt->close();
    }
    
    // Get pending claims count
    try {
        $claims_stmt = $conn->prepare("
            SELECT COUNT(*) as pending_claims 
            FROM reward_claims rc 
            JOIN items i ON rc.item_id = i.id 
            WHERE i.user_id = ? AND rc.status = 'pending'
        ");
        
        if($claims_stmt) {
            $claims_stmt->bind_param("i", $user_id);
            $claims_stmt->execute();
            $claims_result = $claims_stmt->get_result();
            if($row = $claims_result->fetch_assoc()) {
                $stats['pending_claims'] = $row['pending_claims'];
            }
            $claims_stmt->close();
        }
    } catch (Exception $e) {
        $stats['pending_claims'] = 0;
    }
    
    return $stats;
}

function handleFileUploads($files, $type, $user_id) {
    $uploaded_files = [];
    $upload_dir = "../uploads/items/$type/$user_id/";
    
    if(!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    foreach($files['name'] as $key => $name) {
        if($files['error'][$key] === UPLOAD_ERR_OK) {
            $file_extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            
            if(in_array($file_extension, $allowed_types) && $files['size'][$key] <= $max_size) {
                $unique_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
                $file_path = $upload_dir . $unique_name;
                
                if(move_uploaded_file($files['tmp_name'][$key], $file_path)) {
                    $uploaded_files[] = [
                        'name' => $name,
                        'path' => str_replace('../', '', $file_path),
                        'type' => $files['type'][$key],
                        'size' => $files['size'][$key]
                    ];
                }
            }
        }
    }
    
    return $uploaded_files;
}

function generateQRCode($code, $user_id) {
    $qr_dir = "../uploads/qrcodes/items/$user_id/";
    
    if(!is_dir($qr_dir)) {
        mkdir($qr_dir, 0777, true);
    }
    
    $qr_path = $qr_dir . $code . '.png';
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($code);
    
    try {
        $qr_content = @file_get_contents($qr_url);
        if($qr_content !== false) {
            file_put_contents($qr_path, $qr_content);
            return str_replace('../', '', $qr_path);
        }
    } catch (Exception $e) {
        error_log("QR Code generation failed: " . $e->getMessage());
    }
    
    // Return a placeholder if QR generation fails
    return "uploads/qrcodes/default_qr.png";
}

function deleteItemFiles($item) {
    // Delete photos
    if(!empty($item['photos'])) {
        $photos = json_decode($item['photos'], true);
        if($photos) {
            foreach($photos as $photo) {
                if(isset($photo['path']) && file_exists('../' . $photo['path'])) {
                    unlink('../' . $photo['path']);
                }
            }
        }
    }
    
    // Delete documents
    if(!empty($item['documents'])) {
        $documents = json_decode($item['documents'], true);
        if($documents) {
            foreach($documents as $doc) {
                if(isset($doc['path']) && file_exists('../' . $doc['path'])) {
                    unlink('../' . $doc['path']);
                }
            }
        }
    }
    
    // Delete QR code
    if(!empty($item['qr_code_path']) && file_exists('../' . $item['qr_code_path'])) {
        unlink('../' . $item['qr_code_path']);
    }
}

function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}

function getStatusBadge($status) {
    $badges = [
        'active' => 'badge-success',
        'lost' => 'badge-warning',
        'stolen' => 'badge-danger',
        'sold' => 'badge-info',
        'damaged' => 'badge-secondary',
        'archived' => 'badge-dark',
        'found' => 'badge-success'
    ];
    
    return $badges[$status] ?? 'badge-secondary';
}

function getConditionIcon($rating) {
    if($rating >= 9) return '<i class="fas fa-gem text-success"></i>';
    if($rating >= 7) return '<i class="fas fa-thumbs-up text-info"></i>';
    if($rating >= 5) return '<i class="fas fa-check text-primary"></i>';
    if($rating >= 3) return '<i class="fas fa-exclamation-triangle text-warning"></i>';
    return '<i class="fas fa-times text-danger"></i>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Items Management - Vigilo</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --danger: #ef476f;
            --warning: #ffd166;
            --success: #06d6a0;
            --info: #118ab2;
            --dark: #121826;
            --light: #f8f9fa;
            --gray: #6c757d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: #333;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .admin-sidebar {
            width: 280px;
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 100;
            overflow-y: auto;
        }

        .admin-main {
            flex: 1;
            background: #f5f7fb;
            overflow-y: auto;
            padding: 20px;
        }

        /* Header */
        .page-header {
            background: white;
            padding: 2rem;
            margin-bottom: 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .header-title {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header-subtitle {
            color: var(--gray);
            font-size: 1rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: white;
        }

        .stat-icon.total { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        .stat-icon.lost { background: linear-gradient(135deg, var(--warning), #ff9e00); }
        .stat-icon.reward { background: linear-gradient(135deg, var(--success), #00c9a7); }
        .stat-icon.claims { background: linear-gradient(135deg, var(--info), #4fc3f7); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px dashed #e0e0e0;
        }

        .action-card:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.15);
        }

        .action-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .action-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .action-desc {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.4;
        }

        /* Lost Items Section */
        .lost-items-section, .claims-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .lost-items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .lost-item-card {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-left: 4px solid;
            transition: transform 0.3s ease;
        }

        .lost-item-card.lost { border-color: var(--warning); }
        .lost-item-card.stolen { border-color: var(--danger); }
        .lost-item-card.damaged { border-color: var(--gray); }

        .lost-item-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }

        .lost-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .lost-item-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }

        .lost-item-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-lost { background: rgba(255, 209, 102, 0.2); color: #e6a700; }
        .status-stolen { background: rgba(239, 71, 111, 0.2); color: #d63384; }
        .status-damaged { background: rgba(108, 117, 125, 0.2); color: var(--gray); }

        .lost-item-details {
            margin-bottom: 1rem;
        }

        .detail-row {
            display: flex;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .detail-label {
            font-weight: 500;
            color: var(--gray);
            min-width: 100px;
        }

        .detail-value {
            color: #333;
            flex: 1;
        }

        .reward-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, rgba(6, 214, 160, 0.1), rgba(6, 214, 160, 0.2));
            border-radius: 20px;
            color: var(--success);
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        /* Items Table */
        .table-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5rem;
        }

        .data-table th {
            background: #f8f9fa;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #00b894);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #ff3366);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #ff9e00);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: #f8f9fa;
            color: var(--danger);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #495057;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Claims Table */
        .claims-table {
            width: 100%;
            border-collapse: collapse;
        }

        .claims-table th {
            background: #f8f9fa;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            font-size: 0.9rem;
        }

        .claims-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
            font-size: 0.9rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
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
            background: rgba(6, 214, 160, 0.1);
            border: 1px solid rgba(6, 214, 160, 0.3);
            color: #06d6a0;
        }

        .alert-error {
            background: rgba(239, 71, 111, 0.1);
            border: 1px solid rgba(239, 71, 111, 0.3);
            color: #ef476f;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }
            
            .admin-sidebar {
                width: 100%;
                position: fixed;
                top: 0;
                left: -100%;
                height: 100vh;
                transition: left 0.3s ease;
                z-index: 1000;
            }
            
            .admin-sidebar.active {
                left: 0;
            }
            
            .admin-main {
                margin-left: 0;
            }
            
            .mobile-menu-toggle {
                display: block;
                position: fixed;
                top: 1rem;
                left: 1rem;
                z-index: 1001;
                background: white;
                border: none;
                border-radius: 10px;
                width: 40px;
                height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.2rem;
                color: var(--primary);
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                cursor: pointer;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div style="padding: 30px 20px; border-bottom: 1px solid #e2e8f0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #4361ee, #7209b7); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem;">
                        <i class="fas fa-box"></i>
                    </div>
                    <div>
                        <h2 style="font-family: 'Poppins', sans-serif; font-size: 1.5rem; color: #2d3748; margin-bottom: 5px;">Vigilo</h2>
                        <span style="font-size: 0.9rem; color: #718096;">Items Management</span>
                    </div>
                </div>
            </div>

            <nav style="padding: 20px 0;">
                <div style="margin-bottom: 30px;">
                    <h3 style="font-size: 0.8rem; text-transform: uppercase; color: #718096; padding: 0 20px 10px; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; letter-spacing: 1px;">Navigation</h3>
                    <ul style="list-style: none;">
                        <li><a href="dashboard.php" style="display: flex; align-items: center; padding: 12px 20px; color: #2d3748; text-decoration: none; transition: all 0.3s ease;">
                            <span style="width: 20px; margin-right: 15px; text-align: center;"><i class="fas fa-tachometer-alt"></i></span>
                            <span>Dashboard</span>
                        </a></li>
                        <li><a href="items.php" style="display: flex; align-items: center; padding: 12px 20px; color: #4361ee; text-decoration: none; transition: all 0.3s ease; background: rgba(102, 126, 234, 0.1); border-right: 3px solid #4361ee;">
                            <span style="width: 20px; margin-right: 15px; text-align: center;"><i class="fas fa-box"></i></span>
                            <span>Items</span>
                            <span style="margin-left: auto; background: #4361ee; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.8rem; min-width: 24px; text-align: center;"><?= $stats['total'] ?></span>
                        </a></li>
                        <li><a href="public_search.php" style="display: flex; align-items: center; padding: 12px 20px; color: #2d3748; text-decoration: none; transition: all 0.3s ease;">
                            <span style="width: 20px; margin-right: 15px; text-align: center;"><i class="fas fa-search"></i></span>
                            <span>Find Lost Items</span>
                        </a></li>
                        <li><a href="profile.php" style="display: flex; align-items: center; padding: 12px 20px; color: #2d3748; text-decoration: none; transition: all 0.3s ease;">
                            <span style="width: 20px; margin-right: 15px; text-align: center;"><i class="fas fa-user"></i></span>
                            <span>Profile</span>
                        </a></li>
                        <li><a href="logout.php" style="display: flex; align-items: center; padding: 12px 20px; color: #2d3748; text-decoration: none; transition: all 0.3s ease;">
                            <span style="width: 20px; margin-right: 15px; text-align: center;"><i class="fas fa-sign-out-alt"></i></span>
                            <span>Logout</span>
                        </a></li>
                    </ul>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="header-title">
                    <i class="fas fa-shield-alt"></i> Vigilo Protection System
                </h1>
                <p class="header-subtitle">
                    Track, protect, and recover your valuable items with our comprehensive system.
                    Report lost/stolen items and offer rewards for their safe return.
                </p>
            </div>

            <!-- Display Messages -->
            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $_SESSION['success'] ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error'] ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total Items</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon lost">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value"><?= $stats['lost'] + $stats['stolen'] + $stats['damaged'] ?></div>
                    <div class="stat-label">Lost/Stolen Items</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon reward">
                        <i class="fas fa-award"></i>
                    </div>
                    <div class="stat-value">$<?= number_format($stats['total_rewards'], 2) ?></div>
                    <div class="stat-label">Active Rewards</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon claims">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <div class="stat-value"><?= $stats['pending_claims'] ?></div>
                    <div class="stat-label">Pending Claims</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <div class="action-card" onclick="showAddItemModal()">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <h3 class="action-title">Register New Item</h3>
                    <p class="action-desc">Add item details, upload photos, generate QR code</p>
                </div>

                <div class="action-card" onclick="showReportModal()">
                    <div class="action-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="action-title">Report Lost/Stolen</h3>
                    <p class="action-desc">Report missing items and offer rewards</p>
                </div>

                <div class="action-card" onclick="window.open('public_search.php', '_blank')">
                    <div class="action-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3 class="action-title">Search Lost Items</h3>
                    <p class="action-desc">Browse public database of lost items</p>
                </div>

                <div class="action-card" onclick="showHelpModal()">
                    <div class="action-icon">
                        <i class="fas fa-hands-helping"></i>
                    </div>
                    <h3 class="action-title">Help Find Items</h3>
                    <p class="action-desc">Help others recover their lost items</p>
                </div>
            </div>

            <!-- Lost Items Section -->
            <?php if(!empty($lost_items)): ?>
            <div class="lost-items-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-exclamation-circle"></i> Lost & Stolen Items
                    </h2>
                    <span style="background: rgba(239, 71, 111, 0.1); color: #ef476f; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.9rem; font-weight: 600;">
                        <?= count($lost_items) ?> Active Reports
                    </span>
                </div>

                <div class="lost-items-grid">
                    <?php foreach($lost_items as $item): ?>
                    <div class="lost-item-card <?= $item['status'] ?>">
                        <div class="lost-item-header">
                            <h3 class="lost-item-name"><?= htmlspecialchars($item['item_name']) ?></h3>
                            <span class="lost-item-status status-<?= $item['status'] ?>">
                                <?= ucfirst($item['status']) ?>
                            </span>
                        </div>
                        
                        <div class="lost-item-details">
                            <div class="detail-row">
                                <span class="detail-label">Category:</span>
                                <span class="detail-value"><?= htmlspecialchars($item['category']) ?></span>
                            </div>
                            
                            <?php if(!empty($item['incident_location'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Location:</span>
                                <span class="detail-value"><?= htmlspecialchars($item['incident_location']) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if(!empty($item['reported_date'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Date:</span>
                                <span class="detail-value">
                                    <?= date('M d, Y', strtotime($item['reported_date'])) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($item['reward_amount'] > 0): ?>
                            <div class="detail-row">
                                <span class="detail-label">Reward:</span>
                                <span class="reward-badge">
                                    <i class="fas fa-award"></i>
                                    $<?= number_format($item['reward_amount'], 2) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 1rem;">
                            <button class="btn btn-sm btn-success" onclick="markAsFound(<?= $item['id'] ?>)">
                                <i class="fas fa-check"></i> Mark Found
                            </button>
                            <button class="btn btn-sm btn-primary" onclick="viewItemDetails(<?= $item['id'] ?>)">
                                <i class="fas fa-eye"></i> Details
                            </button>
                            <?php if($item['reward_amount'] > 0): ?>
                            <button class="btn btn-sm btn-warning" onclick="shareRewardLink(<?= $item['id'] ?>)">
                                <i class="fas fa-share-alt"></i> Share
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pending Claims Section -->
            <?php if(!empty($pending_claims)): ?>
            <div class="claims-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-handshake"></i> Pending Reward Claims
                    </h2>
                    <span style="background: rgba(255, 209, 102, 0.2); color: #e6a700; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.9rem; font-weight: 600;">
                        <?= count($pending_claims) ?> Claims
                    </span>
                </div>

                <table class="claims-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Claimer</th>
                            <th>Contact</th>
                            <th>Reward</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pending_claims as $claim): ?>
                        <tr>
                            <td><?= htmlspecialchars($claim['item_name']) ?></td>
                            <td><?= htmlspecialchars($claim['claimer_name']) ?></td>
                            <td>
                                <?php if($claim['claimer_phone']): ?>
                                <div><?= htmlspecialchars($claim['claimer_phone']) ?></div>
                                <?php endif; ?>
                                <?php if($claim['claimer_email']): ?>
                                <div style="font-size: 0.8rem; color: var(--gray);"><?= htmlspecialchars($claim['claimer_email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color: var(--success); font-weight: 600;">
                                    $<?= number_format($claim['reward_amount'], 2) ?>
                                </span>
                            </td>
                            <td><?= date('M d, Y', strtotime($claim['claim_date'])) ?></td>
                            <td>
                                <div style="display: flex; gap: 5px;">
                                    <button class="btn btn-sm btn-success" onclick="approveClaim(<?= $claim['id'] ?>)">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="rejectClaim(<?= $claim['id'] ?>)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <button class="btn btn-sm btn-primary" onclick="viewClaim(<?= $claim['id'] ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="" class="filter-grid" id="searchForm">
                    <div class="form-group">
                        <label class="form-label">Search Items</label>
                        <input type="text" class="form-control" name="search" 
                               placeholder="Item name, serial, brand..." 
                               value="<?= htmlspecialchars($search) ?>"
                               id="searchInput">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select class="form-control" name="category" id="categorySelect">
                            <option value="">All Categories</option>
                            <?php foreach($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" 
                                <?= $category === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-control" name="status" id="statusSelect">
                            <option value="">All Status</option>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="lost" <?= $status === 'lost' ? 'selected' : '' ?>>Lost</option>
                            <option value="stolen" <?= $status === 'stolen' ? 'selected' : '' ?>>Stolen</option>
                            <option value="damaged" <?= $status === 'damaged' ? 'selected' : '' ?>>Damaged</option>
                            <option value="found" <?= $status === 'found' ? 'selected' : '' ?>>Found</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <button type="button" class="btn" onclick="resetFilters()" style="background: #e9ecef; color: #495057;">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                    </div>
                </form>
            </div>

            <!-- Items Table -->
            <div class="table-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-list"></i> All Items
                    </h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="showAddItemModal()">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                        <button class="btn btn-success" onclick="exportItems()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>

                <?php if(!empty($items)): ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Serial Number</th>
                                <th>Value</th>
                                <th>Status</th>
                                <th>Reward</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($items as $item): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <?php 
                                        if(!empty($item['photos'])): 
                                            $photos = json_decode($item['photos'], true);
                                            if(!empty($photos) && is_array($photos) && !empty($photos[0]['path'])): 
                                        ?>
                                            <img src="../<?= htmlspecialchars($photos[0]['path']) ?>" 
                                                 style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover;"
                                                 onerror="this.src='https://via.placeholder.com/40?text=No+Image'">
                                        <?php else: ?>
                                            <div style="width: 40px; height: 40px; background: #f0f0f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #999;">
                                                <i class="fas fa-image"></i>
                                            </div>
                                        <?php endif; endif; ?>
                                        <div>
                                            <strong><?= htmlspecialchars($item['item_name']) ?></strong><br>
                                            <small style="color: #6c757d;">
                                                <?= htmlspecialchars(($item['brand'] ?? '') . ' ' . ($item['model'] ?? '')) ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($item['category'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if(!empty($item['serial_number'])): ?>
                                    <code style="background: #f8f9fa; padding: 2px 6px; border-radius: 4px;">
                                        <?= htmlspecialchars($item['serial_number']) ?>
                                    </code>
                                    <?php else: ?>
                                    <span style="color: #6c757d;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($item['current_value'])): ?>
                                    <strong>$<?= number_format($item['current_value'], 2) ?></strong>
                                    <?php else: ?>
                                    <span style="color: #6c757d;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'active' => '#06d6a0',
                                        'lost' => '#ffd166',
                                        'stolen' => '#ef476f',
                                        'damaged' => '#6c757d',
                                        'found' => '#118ab2',
                                        'archived' => '#495057',
                                        'sold' => '#7209b7'
                                    ];
                                    $itemStatus = $item['status'] ?? 'active';
                                    ?>
                                    <span style="
                                        padding: 4px 12px;
                                        border-radius: 20px;
                                        background: <?= $statusColors[$itemStatus] ?? '#6c757d' ?>20;
                                        color: <?= $statusColors[$itemStatus] ?? '#6c757d' ?>;
                                        font-size: 0.85rem;
                                        font-weight: 600;
                                    ">
                                        <?= ucfirst($itemStatus) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if(!empty($item['reward_amount']) && $item['reward_amount'] > 0): ?>
                                    <span style="color: #06d6a0; font-weight: 600;">
                                        <i class="fas fa-award"></i> $<?= number_format($item['reward_amount'], 2) ?>
                                    </span>
                                    <?php else: ?>
                                    <span style="color: #6c757d;">No reward</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px;">
                                        <button class="btn btn-sm" 
                                                onclick="viewItemDetails(<?= $item['id'] ?>)"
                                                style="background: #e9ecef; color: #495057;">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="editItem(<?= $item['id'] ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if(($item['status'] ?? '') === 'active'): ?>
                                        <button class="btn btn-sm btn-warning" 
                                                onclick="reportItem(<?= $item['id'] ?>)">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: #6c757d;">
                    <i class="fas fa-box-open" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <h3>No Items Found</h3>
                    <p style="margin-bottom: 1.5rem;">
                        <?php if($search || $category || $status): ?>
                        Try adjusting your filters or search criteria
                        <?php else: ?>
                        Get started by adding your first item
                        <?php endif; ?>
                    </p>
                    <?php if(!$search && !$category && !$status): ?>
                    <button class="btn btn-primary" onclick="showAddItemModal()">
                        <i class="fas fa-plus-circle"></i> Add Your First Item
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <!-- Add Item Modal -->
    <div class="modal-overlay" id="addItemModal">
        <div class="modal" style="max-width: 800px;">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-plus-circle"></i> Add New Item
                </h2>
                <button class="modal-close" onclick="closeModal('addItemModal')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="addItemForm" onsubmit="return validateAddItemForm()">
                <input type="hidden" name="add_item" value="1">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Item Name *</label>
                        <input type="text" class="form-control" name="item_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Serial Number</label>
                        <input type="text" class="form-control" name="serial_number">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select class="form-control" name="category">
                            <option value="">Select Category</option>
                            <option value="Electronics">Electronics</option>
                            <option value="Jewelry">Jewelry</option>
                            <option value="Documents">Documents</option>
                            <option value="Vehicles">Vehicles</option>
                            <option value="Furniture">Furniture</option>
                            <option value="Clothing">Clothing</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Brand</label>
                        <input type="text" class="form-control" name="brand">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Model</label>
                        <input type="text" class="form-control" name="model">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="3"></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" class="form-control" name="purchase_date">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Purchase Price ($)</label>
                        <input type="number" class="form-control" name="purchase_price" step="0.01" min="0">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Current Value ($)</label>
                        <input type="number" class="form-control" name="current_value" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Condition (1-10)</label>
                        <input type="range" class="form-control" name="condition_rating" min="1" max="10" value="5" oninput="document.getElementById('condition_value').textContent = this.value">
                        <div style="text-align: center; margin-top: 5px;">
                            <span id="condition_value">5</span>/10
                        </div>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" class="form-control" name="location" placeholder="e.g., Home Office, Safe Deposit Box">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Warranty Expiry</label>
                        <input type="date" class="form-control" name="warranty_expiry">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Insurance Policy Number</label>
                    <input type="text" class="form-control" name="insurance_policy">
                </div>
                
                <!-- Photos Upload -->
                <div class="form-group">
                    <label class="form-label">Photos</label>
                    <div class="file-upload-area" onclick="document.getElementById('photos').click()" style="border: 2px dashed #e0e0e0; border-radius: 8px; padding: 2rem; text-align: center; cursor: pointer; transition: all 0.3s ease;">
                        <i class="fas fa-camera fa-2x" style="margin-bottom: 10px; color: #6c757d;"></i>
                        <p>Click to upload photos or drag and drop</p>
                        <p style="color: #6c757d; font-size: 0.9rem;">JPG, PNG, GIF up to 5MB each</p>
                    </div>
                    <input type="file" id="photos" name="photos[]" multiple accept="image/*" style="display: none;" onchange="previewFiles(this, 'photos-preview')">
                    <div id="photos-preview" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px;"></div>
                </div>
                
                <!-- Documents Upload -->
                <div class="form-group">
                    <label class="form-label">Documents</label>
                    <div class="file-upload-area" onclick="document.getElementById('documents').click()" style="border: 2px dashed #e0e0e0; border-radius: 8px; padding: 2rem; text-align: center; cursor: pointer; transition: all 0.3s ease;">
                        <i class="fas fa-file-alt fa-2x" style="margin-bottom: 10px; color: #6c757d;"></i>
                        <p>Click to upload documents or drag and drop</p>
                        <p style="color: #6c757d; font-size: 0.9rem;">PDF, DOC, TXT up to 10MB each</p>
                    </div>
                    <input type="file" id="documents" name="documents[]" multiple accept=".pdf,.doc,.docx,.txt" style="display: none;" onchange="previewFiles(this, 'documents-preview')">
                    <div id="documents-preview" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px;"></div>
                </div>
                
                <div style="display: flex; gap: 15px; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-save"></i> Save Item
                    </button>
                    <button type="button" class="btn" onclick="closeModal('addItemModal')" style="flex: 1; background: #e9ecef; color: #495057;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Lost Item Modal -->
    <div class="modal-overlay" id="reportModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-exclamation-triangle"></i> Report Lost/Stolen Item
                </h2>
                <button class="modal-close" onclick="closeModal('reportModal')">&times;</button>
            </div>
            <form id="reportForm" method="POST">
                <input type="hidden" name="report_lost" value="1">
                <input type="hidden" id="report_item_id" name="item_id">
                
                <div class="form-group">
                    <label class="form-label">Incident Type *</label>
                    <select class="form-control" id="report_status" name="status" required>
                        <option value="">Select incident type</option>
                        <option value="lost">Lost</option>
                        <option value="stolen">Stolen</option>
                        <option value="damaged">Damaged</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Incident Location *</label>
                    <input type="text" class="form-control" name="incident_location" 
                           placeholder="Where did this happen?" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Incident Description *</label>
                    <textarea class="form-control" name="incident_description" rows="3" 
                              placeholder="Describe what happened..." required></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Reward Amount (Optional)</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 1.2rem;">$</span>
                        <input type="number" class="form-control" name="reward_amount" 
                               placeholder="0.00" step="0.01" min="0">
                    </div>
                    <small style="color: #6c757d; margin-top: 5px; display: block;">
                        Offering a reward increases the chances of recovery
                    </small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Contact Information</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <input type="tel" class="form-control" name="contact_phone" 
                               placeholder="Phone number">
                        <input type="email" class="form-control" name="contact_email" 
                               placeholder="Email address">
                    </div>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="is_public" value="1" checked>
                        <span>Make this report public (visible in search)</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 15px; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-paper-plane"></i> Submit Report
                    </button>
                    <button type="button" class="btn" 
                            onclick="closeModal('reportModal')"
                            style="flex: 1; background: #e9ecef; color: #495057;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mark as Found Modal -->
    <div class="modal-overlay" id="foundModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-check"></i> Mark Item as Found
                </h2>
                <button class="modal-close" onclick="closeModal('foundModal')">&times;</button>
            </div>
            <form id="foundForm" method="POST">
                <input type="hidden" name="mark_found" value="1">
                <input type="hidden" id="found_item_id" name="item_id">
                
                <div class="form-group">
                    <label class="form-label">Found By (Optional)</label>
                    <input type="text" class="form-control" name="found_by_name" 
                           placeholder="Name of person who found it">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Contact Information (Optional)</label>
                    <input type="text" class="form-control" name="found_by_contact" 
                           placeholder="Phone or email">
                </div>
                
                <div style="display: flex; gap: 15px; margin-top: 2rem;">
                    <button type="submit" class="btn btn-success" style="flex: 1;">
                        <i class="fas fa-check"></i> Mark as Found
                    </button>
                    <button type="button" class="btn" 
                            onclick="closeModal('foundModal')"
                            style="flex: 1; background: #e9ecef; color: #495057;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Help Modal -->
    <div class="modal-overlay" id="helpModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-question-circle"></i> How to Help Find Lost Items
                </h2>
                <button class="modal-close" onclick="closeModal('helpModal')">&times;</button>
            </div>
            <div style="line-height: 1.6;">
                <h3 style="margin-bottom: 1rem; color: #333;">How the System Works:</h3>
                <ol style="margin-left: 1.5rem; margin-bottom: 1.5rem;">
                    <li><strong>Search Lost Items:</strong> Visit the public search page to see lost items in your area</li>
                    <li><strong>Check Rewards:</strong> Some items offer cash rewards for their return</li>
                    <li><strong>Found Something?</strong> Contact the owner through the provided information</li>
                    <li><strong>Claim Reward:</strong> If you find an item with a reward, submit a claim through the system</li>
                </ol>
                
                <h3 style="margin-bottom: 1rem; color: #333;">Tips for Helping:</h3>
                <ul style="margin-left: 1.5rem; margin-bottom: 1.5rem;">
                    <li>Keep an eye out for items matching descriptions</li>
                    <li>Check local lost & found centers</li>
                    <li>Share lost item posts on social media</li>
                    <li>Report found items to local authorities</li>
                </ul>
                
                <div style="text-align: center; margin-top: 2rem;">
                    <a href="public_search.php" class="btn btn-primary" target="_blank">
                        <i class="fas fa-search"></i> Go to Public Search
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Modal functions
        function showModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function showAddItemModal() {
            showModal('addItemModal');
        }

        function showReportModal() {
            showModal('reportModal');
        }

        function showHelpModal() {
            showModal('helpModal');
        }

        function reportItem(itemId) {
            document.getElementById('report_item_id').value = itemId;
            showModal('reportModal');
        }

        function markAsFound(itemId) {
            document.getElementById('found_item_id').value = itemId;
            showModal('foundModal');
        }

        function approveClaim(claimId) {
            if (confirm('Approve this reward claim?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="claim_id" value="${claimId}">
                    <input type="hidden" name="status" value="approved">
                    <input type="hidden" name="update_claim_status" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function rejectClaim(claimId) {
            if (confirm('Reject this reward claim?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="claim_id" value="${claimId}">
                    <input type="hidden" name="status" value="rejected">
                    <input type="hidden" name="update_claim_status" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function viewClaim(claimId) {
            alert('Claim details view will be implemented in the next update.');
        }

        function viewItemDetails(itemId) {
            window.open(`item_detail.php?id=${itemId}`, '_blank');
        }

        function editItem(itemId) {
            window.open(`edit_item.php?id=${itemId}`, '_blank');
        }

        function shareRewardLink(itemId) {
            const link = window.location.origin + '/claim_reward.php?item=' + itemId;
            if (navigator.share) {
                navigator.share({
                    title: 'Lost Item Reward',
                    text: 'Help find this lost item and earn a reward!',
                    url: link
                });
            } else {
                navigator.clipboard.writeText(link);
                alert('Reward link copied to clipboard! Share it to help find the item.');
            }
        }

        function toggleSidebar() {
            document.querySelector('.admin-sidebar').classList.toggle('active');
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('categorySelect').value = '';
            document.getElementById('statusSelect').value = '';
            document.getElementById('searchForm').submit();
        }

        function previewFiles(input, previewId) {
            const preview = document.getElementById(previewId);
            preview.innerHTML = '';
            
            Array.from(input.files).forEach(file => {
                const fileItem = document.createElement('div');
                fileItem.style.cssText = 'display: flex; align-items: center; gap: 10px; background: rgba(102, 126, 234, 0.1); padding: 10px; border-radius: 8px; max-width: 300px;';
                fileItem.innerHTML = `
                    <i class="fas fa-file"></i>
                    <div style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${file.name}</div>
                    <i class="fas fa-times" style="color: #ef476f; cursor: pointer;" onclick="this.parentElement.remove()"></i>
                `;
                preview.appendChild(fileItem);
            });
        }

        function validateAddItemForm() {
            const itemName = document.querySelector('input[name="item_name"]').value;
            if (!itemName.trim()) {
                alert('Item name is required');
                return false;
            }
            
            // Validate date formats
            const purchaseDate = document.querySelector('input[name="purchase_date"]').value;
            const warrantyExpiry = document.querySelector('input[name="warranty_expiry"]').value;
            
            if (purchaseDate && !isValidDate(purchaseDate)) {
                alert('Invalid purchase date format. Use YYYY-MM-DD');
                return false;
            }
            
            if (warrantyExpiry && !isValidDate(warrantyExpiry)) {
                alert('Invalid warranty expiry date format. Use YYYY-MM-DD');
                return false;
            }
            
            return true;
        }

        function isValidDate(dateString) {
            const regex = /^\d{4}-\d{2}-\d{2}$/;
            if (!regex.test(dateString)) return false;
            
            const date = new Date(dateString);
            return date instanceof Date && !isNaN(date);
        }

        function exportItems() {
            const search = document.getElementById('searchInput').value;
            const category = document.getElementById('categorySelect').value;
            const status = document.getElementById('statusSelect').value;
            
            let url = 'export_items.php?';
            if (search) url += `search=${encodeURIComponent(search)}&`;
            if (category) url += `category=${encodeURIComponent(category)}&`;
            if (status) url += `status=${encodeURIComponent(status)}`;
            
            window.open(url, '_blank');
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                    closeModal(modal.id);
                });
            }
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                showAddItemModal();
            }
        });

        // Real-time search (optional enhancement)
        document.getElementById('searchInput').addEventListener('input', function(e) {
            // You could implement debounced AJAX search here
        });

        // Initialize tooltips
        $(document).ready(function() {
            $('[title]').tooltip();
        });
    </script>
</body>
</html>

<?php
// Close database connection
if(isset($conn)) {
    $conn->close();
}
?>