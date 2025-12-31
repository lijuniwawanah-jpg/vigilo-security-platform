<?php
error_reporting(E_ALL); 
ini_set('display_errors', 1);
session_start();

if(!isset($_SESSION['user_id'])){
    header('Location: login.php'); 
    exit;
}

require_once('../config/db.php');

// Define the formatBytes function if it doesn't exist
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $decimals = 2) {
        if ($bytes == 0) return '0 Bytes';
        $k = 1024;
        $dm = $decimals < 0 ? 0 : $decimals;
        $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes) / log($k));
        return number_format($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
    }
}

$user_id = $_SESSION['user_id'];

// Debug: Check if we're connected
if (!$conn) {
    die("Database connection failed: " . $conn->connect_error);
}

// Debug: Check if users table exists
$table_check = $conn->query("SHOW TABLES LIKE 'users'");
if (!$table_check || $table_check->num_rows === 0) {
    die("Error: Users table doesn't exist in the database.");
}

// Debug: Check columns in users table
$column_check = $conn->query("DESCRIBE users");
if (!$column_check) {
    die("Error: Could not describe users table. " . $conn->error);
}

$columns = [];
while ($row = $column_check->fetch_assoc()) {
    $columns[] = $row['Field'];
}

// Build SQL query based on actual columns
$select_columns = ['id', 'email']; // Always required
if (in_array('fullName', $columns)) {
    $select_columns[] = 'fullName';
} elseif (in_array('full_name', $columns)) {
    $select_columns[] = 'full_name AS fullName';
} else {
    $select_columns[] = 'email AS fullName'; // Fallback
}

if (in_array('phone', $columns)) $select_columns[] = 'phone';
if (in_array('role', $columns)) $select_columns[] = 'role';
if (in_array('avatar', $columns)) $select_columns[] = 'avatar';
if (in_array('storage_limit', $columns)) $select_columns[] = 'storage_limit';
if (in_array('storage_used', $columns)) $select_columns[] = 'storage_used';
if (in_array('created_at', $columns)) $select_columns[] = 'created_at';
if (in_array('last_login', $columns)) $select_columns[] = 'last_login';

$sql = "SELECT " . implode(', ', $select_columns) . " FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Error preparing SQL: " . $conn->error . "<br>SQL: " . htmlspecialchars($sql));
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) {
    $stmt->close();
    session_destroy();
    header('Location: login.php');
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// Get user stats with error handling
$stats = [];

// Check if documents table exists
$table_check = $conn->query("SHOW TABLES LIKE 'documents'");
if ($table_check && $table_check->num_rows > 0) {
    // Check if documents table has user_id column
    $column_check = $conn->query("SHOW COLUMNS FROM documents LIKE 'user_id'");
    if ($column_check && $column_check->num_rows > 0) {
        $doc_stmt = $conn->prepare("SELECT COUNT(*) as count FROM documents WHERE user_id = ?");
        if ($doc_stmt) {
            $doc_stmt->bind_param("i", $user_id);
            $doc_stmt->execute();
            $doc_result = $doc_stmt->get_result();
            $stats['documents'] = (int)$doc_result->fetch_assoc()['count'];
            $doc_stmt->close();
        } else {
            $stats['documents'] = 0;
        }
    } else {
        $stats['documents'] = 0;
    }
} else {
    $stats['documents'] = 0;
}

// Check if shared_links table exists
$table_check = $conn->query("SHOW TABLES LIKE 'shared_links'");
if ($table_check && $table_check->num_rows > 0) {
    $link_stmt = $conn->prepare("SELECT COUNT(*) as count FROM shared_links WHERE user_id = ?");
    if ($link_stmt) {
        $link_stmt->bind_param("i", $user_id);
        $link_stmt->execute();
        $link_result = $link_stmt->get_result();
        $stats['shared_links'] = (int)$link_result->fetch_assoc()['count'];
        $link_stmt->close();
    } else {
        $stats['shared_links'] = 0;
    }
} else {
    $stats['shared_links'] = 0;
}

// Calculate storage
$used = isset($user['storage_used']) ? intval($user['storage_used']) : 0;
$limit = isset($user['storage_limit']) ? intval($user['storage_limit']) : 1073741824; // Default 1GB
$percentage = $limit > 0 ? round(($used / $limit) * 100, 1) : 0;

// Ensure all required user fields exist
if (!isset($user['fullName'])) {
    $user['fullName'] = $user['email']; // Fallback
}
if (!isset($user['role'])) {
    $user['role'] = 'user';
}
if (!isset($user['avatar'])) {
    $user['avatar'] = '';
}
if (!isset($user['created_at'])) {
    $user['created_at'] = date('Y-m-d H:i:s');
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Profile - Vigilo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
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
            
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
            --border-radius: 20px;
            --border-radius-lg: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background: var(--dark-bg);
            color: var(--text-primary);
            line-height: 1.6;
        }

        h1, h2, h3, h4 {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 60px 0 40px;
            margin-bottom: 40px;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255, 255, 255, 0.3);
            background: var(--light-bg);
        }

        .user-info h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .user-info p {
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .user-role {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-top: 10px;
        }

        .profile-content {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
            margin-bottom: 50px;
        }

        .card1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }


        .card2 {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }


        .card3 {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .card4 {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .card h3 {
            margin-bottom: 20px;
            color: var(--text-primary);
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card h3 i {
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.95rem;
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

        .btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--text-secondary);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #f093fb);
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin: 15px 0;
            font-size: 0.95rem;
            display: none;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            text-align: center;
            border: 1px solid var(--border-color);
        }

        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .stat-card .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .progress-container {
            margin-top: 15px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .progress-bar {
            height: 10px;
            background: var(--border-color);
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 5px;
            transition: width 0.3s ease;
        }

        .avatar-upload {
            text-align: center;
            margin-top: 20px;
        }

        .avatar-preview {
            position: relative;
            display: inline-block;
        }

        .avatar-change-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease;
        }

        .avatar-change-btn:hover {
            background: rgba(0, 0, 0, 0.9);
        }

        .danger-zone {
            border: 2px solid var(--danger-color);
            background: rgba(245, 87, 108, 0.05);
            border-radius: var(--border-radius);
            padding: 20px 20px;
        }

        .danger-zone h3 {
            color: var(--danger-color);
        }

        .danger-zone h3 i {
            color: var(--danger-color);
        }

        @media (max-width: 768px) {
            .profile-content {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .user-info h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
 

    <div class="profile-header">
        <div class="container">
            <div class="header-content">
                <div class="avatar-preview">
                    <?php
                    $avatar_path = '';
                    if (!empty($user['avatar']) && file_exists('../' . $user['avatar'])) {
                        $avatar_path = '../' . $user['avatar'];
                    } else {
                        $avatar_path = 'images/default-avatar.png';
                        // Check if default exists
                        if (!file_exists($avatar_path)) {
                            // Use UI Avatars as fallback
                            $avatar_path = 'https://ui-avatars.com/api/?name=' . urlencode($user['fullName']) . '&background=667eea&color=fff&size=128';
                        }
                    }
                    ?>
                    <img src="<?= htmlspecialchars($avatar_path) ?>" 
                         class="avatar-large" 
                         alt="Profile Avatar"
                         id="avatar-preview"
                         onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($user['fullName']) ?>&background=667eea&color=fff&size=128'">
                    <button type="button" class="avatar-change-btn" id="change-avatar-btn">
                        <i class="fas fa-camera"></i>
                    </button>
                </div>
                <div class="user-info">
                    <h1><?= htmlspecialchars($user['fullName']) ?></h1>
                    <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></p>
                    <?php if(isset($user['phone']) && !empty($user['phone'])): ?>
                    <p><i class="fas fa-phone"></i> <?= htmlspecialchars($user['phone']) ?></p>
                    <?php endif; ?>
                    <p><i class="fas fa-calendar"></i> Member since: <?= date('F Y', strtotime($user['created_at'])) ?></p>
                    <div class="user-role"><?= htmlspecialchars(ucfirst($user['role'])) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="profile-content">
            <!-- Left Column - Profile Info -->
            <div>
                <div class="card1">
                    <h3><i class="fas fa-chart-bar"></i> Account Stats</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?= $stats['documents'] ?></div>
                            <div class="stat-label">Documents</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= $stats['shared_links'] ?></div>
                            <div class="stat-label">Shared Links</div>
                        </div>
                    </div>

                    <div class="progress-container">
                        <div class="progress-label">
                            <span>Storage Used</span>
                            <span><?= formatBytes($used) ?> / <?= formatBytes($limit) ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= min($percentage, 100) ?>%"></div>
                        </div>
                        <div style="text-align: center; margin-top: 5px; color: var(--text-secondary); font-size: 0.9rem;">
                            <?= $percentage ?>% used
                        </div>
                    </div>
                </div>

                <div class="card2" style="margin-top: 20px;">
                    <h3><i class="fas fa-qrcode"></i> Your W-ID</h3>
                    <div style="text-align: center; padding: 20px;">
                        <div id="qrcode" style="margin: 0 auto 20px; width: 128px; height: 128px;"></div>
                        <p style="font-family: monospace; background: #f5f5f5; padding: 10px; border-radius: var(--border-radius); word-break: break-all;">
                            WID-<?= strtoupper(substr(md5($user['id'] . $user['email']), 0, 12)) ?>
                        </p>
                        <p style="font-size: 0.9rem; color: var(--text-secondary); margin-top: 10px;">
                            Your unique verification ID
                        </p>
                    </div>
                </div>
            </div>

            <!-- Right Column - Forms -->
            <div>
                <div class="card3">
                    <h3><i class="fas fa-user-edit"></i> Profile Information</h3>
                    <div id="profile-message" class="alert" style="display: none;"></div>
                    
                    <form id="profile-form">
                        <div class="form-group">
                            <label for="fullName">Full Name</label>
                            <input type="text" id="fullName" class="form-control" 
                                   value="<?= htmlspecialchars($user['fullName']) ?>" 
                                   placeholder="Enter your full name" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" class="form-control" 
                                   value="<?= htmlspecialchars($user['email']) ?>" 
                                   placeholder="you@example.com" required>
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" class="form-control" 
                                   value="<?= htmlspecialchars($user['phone'] ?? '') ?>" 
                                   placeholder="+1234567890">
                            <small style="color: var(--text-secondary); display: block; margin-top: 5px;">
                                Used for OTP verification
                            </small>
                        </div>

                        <button type="submit" class="btn" id="save-profile">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </form>

                    <input type="file" id="avatar-input" accept="image/*" style="display: none;">
                </div>

                <div class="card4" style="margin-top: 20px;">
                    <h3><i class="fas fa-lock"></i> Change Password</h3>
                    <div id="password-message" class="alert" style="display: none;"></div>
                    
                    <form id="password-form">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" class="form-control" 
                                   placeholder="Enter current password" required>
                        </div>

                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" class="form-control" 
                                   placeholder="Enter new password" required>
                            <small style="color: var(--text-secondary); display: block; margin-top: 5px;">
                                Must be at least 8 characters with uppercase, lowercase, and number
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" class="form-control" 
                                   placeholder="Confirm new password" required>
                        </div>

                        <button type="submit" class="btn">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </form>
                </div>

                <div class="card danger-zone" style="margin-top: 20px;">
                    <h3><i class="fas fa-exclamation-triangle"></i> Danger Zone</h3>
                    <p style="margin-bottom: 20px; color: var(--text-secondary);">
                        Permanent actions that cannot be undone
                    </p>
                    
                    <button class="btn btn-danger" onclick="showDeleteModal()">
                        <i class="fas fa-trash"></i> Delete Account
                    </button>
                    
                    <button class="btn btn-secondary" style="margin-left: 10px;" onclick="exportData()">
                        <i class="fas fa-download"></i> Export My Data
                    </button>
                    
                    <small style="display: block; margin-top: 10px; color: var(--text-secondary);">
                        Account deletion removes all your data permanently. Export your data first.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Code Library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.0/build/qrcode.min.js"></script>
    <script>
        // Initialize QR Code
        const qrData = "VIGILO:USER:<?= $user['id'] ?>:WID-<?= strtoupper(substr(md5($user['id'] . $user['email']), 0, 12)) ?>";
        
        // Create canvas element for QR code
        const qrContainer = document.getElementById('qrcode');
        QRCode.toCanvas(qrContainer, qrData, {
            width: 128,
            margin: 1,
            color: {
                dark: '#667eea',
                light: '#ffffff'
            }
        }, function(error) {
            if (error) {
                console.error('QR Code generation error:', error);
                qrContainer.innerHTML = '<div style="text-align:center;color:#718096;padding:20px;"><i class="fas fa-exclamation-circle fa-2x"></i><br>QR Code could not be generated</div>';
            }
        });

        // Avatar Upload
        document.getElementById('change-avatar-btn').addEventListener('click', () => {
            document.getElementById('avatar-input').click();
        });

        document.getElementById('avatar-input').addEventListener('change', async function() {
            const file = this.files[0];
            if (!file) return;
            
            if (!file.type.startsWith('image/')) {
                showMessage('profile-message', 'Please select an image file (JPG, PNG, etc.)', 'error');
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) { // 5MB limit
                showMessage('profile-message', 'Image size should be less than 5MB', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('avatar', file);
            
            try {
                showMessage('profile-message', 'Uploading avatar...', 'success');
                const response = await fetch('../api/users/upload_avatar.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage('profile-message', 'Avatar updated successfully!', 'success');
                    // Update avatar preview with cache busting
                    document.getElementById('avatar-preview').src = '../' + result.avatar + '?t=' + Date.now();
                } else {
                    showMessage('profile-message', result.message || 'Avatar upload failed', 'error');
                }
            } catch (error) {
                showMessage('profile-message', 'Upload failed. Please try again.', 'error');
                console.error('Avatar upload error:', error);
            }
        });

        // Profile Update
        document.getElementById('profile-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = {
                fullName: document.getElementById('fullName').value.trim(),
                email: document.getElementById('email').value.trim(),
                phone: document.getElementById('phone').value.trim()
            };
            
            if (!formData.fullName || !formData.email) {
                showMessage('profile-message', 'Please fill in required fields', 'error');
                return;
            }
            
            if (formData.email && !validateEmail(formData.email)) {
                showMessage('profile-message', 'Please enter a valid email address', 'error');
                return;
            }
            
            try {
                const btn = document.getElementById('save-profile');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
                btn.disabled = true;
                
                const response = await fetch('../api/users/update_profile.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage('profile-message', result.message || 'Profile updated successfully', 'success');
                    // Update displayed name if needed
                    document.querySelector('.user-info h1').textContent = formData.fullName;
                } else {
                    showMessage('profile-message', result.message || 'Update failed', 'error');
                }
                
                btn.innerHTML = originalText;
                btn.disabled = false;
                
            } catch (error) {
                showMessage('profile-message', 'Update failed. Please try again.', 'error');
                console.error('Profile update error:', error);
                document.getElementById('save-profile').innerHTML = '<i class="fas fa-save"></i> Update Profile';
                document.getElementById('save-profile').disabled = false;
            }
        });

        // Password Change
        document.getElementById('password-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const current = document.getElementById('current_password').value;
            const newPass = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            if (newPass !== confirm) {
                showMessage('password-message', 'New passwords do not match', 'error');
                return;
            }
            
            // Basic password validation
            if (newPass.length < 8) {
                showMessage('password-message', 'Password must be at least 8 characters', 'error');
                return;
            }
            
            if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(newPass)) {
                showMessage('password-message', 'Password must contain uppercase, lowercase, and number', 'error');
                return;
            }
            
            try {
                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing...';
                btn.disabled = true;
                
                const response = await fetch('../api/users/change_password.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        old: current,
                        new: newPass
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage('password-message', result.message || 'Password changed successfully', 'success');
                    document.getElementById('password-form').reset();
                } else {
                    showMessage('password-message', result.message || 'Password change failed', 'error');
                }
                
                btn.innerHTML = originalText;
                btn.disabled = false;
                
            } catch (error) {
                showMessage('password-message', 'Password change failed. Please try again.', 'error');
                console.error('Password change error:', error);
            }
        });

        // Helper function to show messages
        function showMessage(elementId, text, type) {
            const element = document.getElementById(elementId);
            element.textContent = text;
            element.className = `alert alert-${type}`;
            element.style.display = 'block';
            
            setTimeout(() => {
                element.style.display = 'none';
            }, 5000);
        }

        // Email validation
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        // Account deletion modal
        function showDeleteModal() {
            if (confirm('⚠️ ARE YOU SURE?\n\nThis will permanently:\n• Delete your account\n• Remove all your documents\n• Delete all shared links\n• Erase all your data\n\nThis action cannot be undone.')) {
                const confirmText = prompt('Type "DELETE" to confirm:');
                if (confirmText === 'DELETE') {
                    alert('Account deletion would be processed here.\nFor now, please contact support at security@vigilo.com.');
                } else {
                    alert('Account deletion cancelled.');
                }
            }
        }

        // Export data function
        function exportData() {
            alert('Data export feature would generate a ZIP file with:\n• Your profile information\n• All your documents\n• Shared links history\n• Activity logs');
        }
    </script>
</body>
</html>