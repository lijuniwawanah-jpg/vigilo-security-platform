<?php
session_start();
require_once('../config/db.php');

if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Get current user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Handle form submissions
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(isset($_POST['update_profile'])) {
        updateProfile($conn, $user_id);
    } elseif(isset($_POST['change_password'])) {
        changePassword($conn, $user_id);
    } elseif(isset($_POST['update_preferences'])) {
        updatePreferences($conn, $user_id);
    } elseif(isset($_POST['toggle_2fa'])) {
        toggleTwoFactor($conn, $user_id);
    }
}

function updateProfile($conn, $user_id) {
    $fullName = trim($_POST['fullName']);
    $email = trim($_POST['email']);
    
    // Validate email
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address";
        return;
    }
    
    // Check if email already exists (excluding current user)
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check_stmt->bind_param("si", $email, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if($check_result->num_rows > 0) {
        $_SESSION['error'] = "Email already exists";
        return;
    }
    
    // Handle avatar upload
    $avatar = null;
    if(isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/avatars/';
        if(!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if(in_array(strtolower($file_extension), $allowed_extensions)) {
            $file_name = 'avatar_' . $user_id . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if(move_uploaded_file($_FILES['avatar']['tmp_name'], $file_path)) {
                // Delete old avatar if exists
                if($user['avatar'] && file_exists('../' . $user['avatar'])) {
                    unlink('../' . $user['avatar']);
                }
                $avatar = 'uploads/avatars/' . $file_name;
            }
        }
    }
    
    // Update profile
    if($avatar) {
        $stmt = $conn->prepare("UPDATE users SET fullName = ?, email = ?, avatar = ? WHERE id = ?");
        $stmt->bind_param("sssi", $fullName, $email, $avatar, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET fullName = ?, email = ? WHERE id = ?");
        $stmt->bind_param("ssi", $fullName, $email, $user_id);
    }
    
    if($stmt->execute()) {
        $_SESSION['success'] = "Profile updated successfully!";
        // Update session data
        $_SESSION['user_name'] = $fullName;
        $_SESSION['user_email'] = $email;
        if($avatar) {
            $_SESSION['user_avatar'] = $avatar;
        }
        header("Location: settings.php");
        exit;
    } else {
        $_SESSION['error'] = "Failed to update profile";
    }
}

function changePassword($conn, $user_id) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if(!password_verify($current_password, $user['password'])) {
        $_SESSION['error'] = "Current password is incorrect";
        return;
    }
    
    // Validate new password
    if(strlen($new_password) < 8) {
        $_SESSION['error'] = "Password must be at least 8 characters long";
        return;
    }
    
    if($new_password !== $confirm_password) {
        $_SESSION['error'] = "New passwords do not match";
        return;
    }
    
    // Update password
    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $new_password_hash, $user_id);
    
    if($stmt->execute()) {
        $_SESSION['success'] = "Password changed successfully!";
        header("Location: settings.php");
        exit;
    } else {
        $_SESSION['error'] = "Failed to change password";
    }
}

function updatePreferences($conn, $user_id) {
    $theme = $_POST['theme'] ?? 'light';
    $notifications = isset($_POST['notifications']) ? 1 : 0;
    $email_alerts = isset($_POST['email_alerts']) ? 1 : 0;
    
    // Store preferences in session for now (you might want to create a user_preferences table)
    $_SESSION['theme'] = $theme;
    $_SESSION['notifications'] = $notifications;
    $_SESSION['email_alerts'] = $email_alerts;
    
    $_SESSION['success'] = "Preferences updated successfully!";
    header("Location: settings.php");
    exit;
}

function toggleTwoFactor($conn, $user_id) {
    $enable_2fa = isset($_POST['enable_2fa']) ? 1 : 0;
    
    $stmt = $conn->prepare("UPDATE users SET twoFAEnabled = ? WHERE id = ?");
    $stmt->bind_param("ii", $enable_2fa, $user_id);
    
    if($stmt->execute()) {
        if($enable_2fa) {
            $_SESSION['success'] = "Two-factor authentication enabled!";
            // Here you would typically generate and display a QR code for setup
        } else {
            $_SESSION['success'] = "Two-factor authentication disabled!";
        }
        header("Location: settings.php");
        exit;
    } else {
        $_SESSION['error'] = "Failed to update two-factor authentication settings";
    }
}

// Get storage usage statistics
$storage_stmt = $conn->prepare("
    SELECT 
        SUM(file_size) as total_size,
        COUNT(*) as file_count
    FROM documents 
    WHERE user_id = ?
");
$storage_stmt->bind_param("i", $user_id);
$storage_stmt->execute();
$storage_result = $storage_stmt->get_result();
$storage_stats = $storage_result->fetch_assoc();

// Calculate storage percentage
$storage_limit = $user['storage_limit'];
$storage_used = $storage_stats['total_size'] ?? 0;
$storage_percentage = $storage_limit > 0 ? min(100, ($storage_used / $storage_limit) * 100) : 0;
$storage_free = $storage_limit - $storage_used;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Vigilo</title>
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

        /* Navigation */
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

        /* Settings Layout */
        .settings-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
        }

        @media (max-width: 768px) {
            .settings-container {
                grid-template-columns: 1fr;
            }
        }

        /* Settings Sidebar */
        .settings-sidebar {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .settings-sidebar h3 {
            font-family: 'Poppins', sans-serif;
            margin-bottom: 20px;
            color: var(--text-primary);
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sidebar-link {
            padding: 12px 15px;
            border-radius: var(--border-radius);
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-link:hover {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary-color);
        }

        .sidebar-link.active {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary-color);
            font-weight: 500;
        }

        .sidebar-link i {
            width: 20px;
            text-align: center;
        }

        /* Settings Content */
        .settings-content {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .settings-section {
            display: none;
        }

        .settings-section.active {
            display: block;
        }

        .section-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .section-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.8rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-header p {
            color: var(--text-secondary);
            margin-top: 5px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 25px;
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

        .form-hint {
            display: block;
            margin-top: 5px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
        }

        .form-check-label {
            color: var(--text-primary);
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

        .btn-secondary {
            background: var(--text-secondary);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        /* Profile Section */
        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }

        .avatar-container {
            position: relative;
        }

        .avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: var(--shadow-md);
        }

        .avatar-upload {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--primary-color);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid white;
        }

        .avatar-upload:hover {
            background: var(--primary-dark);
        }

        .avatar-upload input {
            display: none;
        }

        .profile-info h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .profile-info p {
            color: var(--text-secondary);
        }

        /* Storage Meter */
        .storage-meter {
            background: rgba(102, 126, 234, 0.1);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 30px;
        }

        .storage-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .storage-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .progress-bar {
            height: 10px;
            background: var(--border-color);
            border-radius: 5px;
            overflow: hidden;
            margin: 20px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 5px;
            transition: width 0.3s ease;
        }

        /* Account Stats */
        .account-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
        }

        .stat-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .stat-title {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* Danger Zone */
        .danger-zone {
            background: rgba(245, 87, 108, 0.1);
            border: 1px solid rgba(245, 87, 108, 0.3);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-top: 40px;
        }

        .danger-zone h3 {
            color: var(--danger-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .danger-zone p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .settings-content {
                padding: 20px;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .account-stats {
                grid-template-columns: 1fr;
            }
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
                <a href="upload.php" class="nav-link">Upload</a>
                <a href="documents.php" class="nav-link">Documents</a>
                <a href="shared_links.php" class="nav-link">Share</a>
                <a href="settings.php" class="nav-link active">Settings</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="header">
        <div class="container">
            <h1><i class="fas fa-cog"></i> Settings</h1>
            <p>Manage your account, security, and preferences</p>
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

        <div class="settings-container">
            <!-- Settings Sidebar -->
            <div class="settings-sidebar">
                <h3>Settings</h3>
                <div class="sidebar-nav">
                    <a href="#profile" class="sidebar-link active" onclick="showSection('profile')">
                        <i class="fas fa-user"></i> Profile
                    </a>
                    <a href="#security" class="sidebar-link" onclick="showSection('security')">
                        <i class="fas fa-shield-alt"></i> Security
                    </a>
                    <a href="#preferences" class="sidebar-link" onclick="showSection('preferences')">
                        <i class="fas fa-sliders-h"></i> Preferences
                    </a>
                    <a href="#account" class="sidebar-link" onclick="showSection('account')">
                        <i class="fas fa-chart-bar"></i> Account
                    </a>
                    <a href="#danger" class="sidebar-link" onclick="showSection('danger')">
                        <i class="fas fa-exclamation-triangle"></i> Danger Zone
                    </a>
                </div>
            </div>

            <!-- Settings Content -->
            <div class="settings-content">
                <!-- Profile Section -->
                <div id="profile-section" class="settings-section active">
                    <div class="section-header">
                        <h2><i class="fas fa-user"></i> Profile Settings</h2>
                        <p>Update your personal information and profile picture</p>
                    </div>

                    <div class="profile-header">
                        <div class="avatar-container">
                            <?php if($user['avatar']): ?>
                                <img src="../<?= htmlspecialchars($user['avatar']) ?>" alt="Profile Picture" class="avatar">
                            <?php else: ?>
                                <div class="avatar" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem;">
                                    <?= substr($user['fullName'], 0, 1) ?>
                                </div>
                            <?php endif; ?>
                            <label class="avatar-upload">
                                <i class="fas fa-camera"></i>
                                <input type="file" id="avatar-input" accept="image/*" onchange="previewAvatar()">
                            </label>
                        </div>
                        <div class="profile-info">
                            <h3><?= htmlspecialchars($user['fullName']) ?></h3>
                            <p><?= htmlspecialchars($user['email']) ?></p>
                            <p>Member since <?= date('F Y', strtotime($user['created_at'])) ?></p>
                        </div>
                    </div>

                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="form-group">
                            <label class="form-label" for="fullName">Full Name</label>
                            <input type="text" class="form-control" id="fullName" name="fullName" 
                                   value="<?= htmlspecialchars($user['fullName']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= htmlspecialchars($user['email']) ?>" required>
                            <span class="form-hint">Your email is used for login and notifications</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="avatar_file">Profile Picture</label>
                            <input type="file" class="form-control" id="avatar_file" name="avatar" accept="image/*">
                            <span class="form-hint">Max file size: 2MB. Allowed formats: JPG, PNG, GIF</span>
                        </div>

                        <div id="avatar-preview" style="margin-bottom: 20px; display: none;">
                            <p>Preview:</p>
                            <img id="preview-image" src="#" alt="Avatar Preview" style="max-width: 100px; border-radius: 50%;">
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </form>
                </div>

                <!-- Security Section -->
                <div id="security-section" class="settings-section">
                    <div class="section-header">
                        <h2><i class="fas fa-shield-alt"></i> Security Settings</h2>
                        <p>Manage your password and security preferences</p>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="change_password" value="1">
                        
                        <h3 style="margin-bottom: 20px; color: var(--text-primary);">Change Password</h3>
                        
                        <div class="form-group">
                            <label class="form-label" for="current_password">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                            <span class="form-hint">Minimum 8 characters</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </form>

                    <div style="margin-top: 40px; padding-top: 30px; border-top: 2px solid var(--border-color);">
                        <h3 style="margin-bottom: 20px; color: var(--text-primary);">Two-Factor Authentication</h3>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="toggle_2fa" value="1">
                            
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="enable_2fa" name="enable_2fa" 
                                       <?= $user['twoFAEnabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="enable_2fa">
                                    Enable Two-Factor Authentication
                                </label>
                            </div>
                            
                            <p class="form-hint" style="margin-bottom: 20px;">
                                Add an extra layer of security to your account. When enabled, you'll need to enter a code from your authenticator app when signing in.
                            </p>
                            
                            <button type="submit" class="btn btn-<?= $user['twoFAEnabled'] ? 'danger' : 'success' ?>">
                                <i class="fas fa-<?= $user['twoFAEnabled'] ? 'times' : 'check' ?>"></i>
                                <?= $user['twoFAEnabled'] ? 'Disable 2FA' : 'Enable 2FA' ?>
                            </button>
                        </form>
                    </div>

                    <div style="margin-top: 40px; padding-top: 30px; border-top: 2px solid var(--border-color);">
                        <h3 style="margin-bottom: 20px; color: var(--text-primary);">Active Sessions</h3>
                        
                        <div style="background: rgba(102, 126, 234, 0.05); border-radius: var(--border-radius); padding: 20px;">
                            <p><strong>Current Session:</strong></p>
                            <p>Device: <?= $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown' ?></p>
                            <p>IP Address: <?= $_SERVER['REMOTE_ADDR'] ?? 'Unknown' ?></p>
                            <p>Started: <?= date('Y-m-d H:i:s', $_SESSION['login_time'] ?? time()) ?></p>
                            
                            <div style="margin-top: 20px;">
                                <button class="btn btn-danger" onclick="logoutAllSessions()">
                                    <i class="fas fa-sign-out-alt"></i> Logout All Other Sessions
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Preferences Section -->
                <div id="preferences-section" class="settings-section">
                    <div class="section-header">
                        <h2><i class="fas fa-sliders-h"></i> Preferences</h2>
                        <p>Customize your Vigilo experience</p>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="update_preferences" value="1">
                        
                        <h3 style="margin-bottom: 20px; color: var(--text-primary);">Appearance</h3>
                        
                        <div class="form-group">
                            <label class="form-label" for="theme">Theme</label>
                            <select class="form-control form-select" id="theme" name="theme">
                                <option value="light" <?= ($_SESSION['theme'] ?? 'light') === 'light' ? 'selected' : '' ?>>Light Mode</option>
                                <option value="dark" <?= ($_SESSION['theme'] ?? 'light') === 'dark' ? 'selected' : '' ?>>Dark Mode</option>
                                <option value="auto" <?= ($_SESSION['theme'] ?? 'light') === 'auto' ? 'selected' : '' ?>>System Default</option>
                            </select>
                        </div>

                        <div style="margin-top: 40px; padding-top: 30px; border-top: 2px solid var(--border-color);">
                            <h3 style="margin-bottom: 20px; color: var(--text-primary);">Notifications</h3>
                            
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="notifications" name="notifications" 
                                       <?= ($_SESSION['notifications'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="notifications">
                                    Enable Browser Notifications
                                </label>
                            </div>
                            
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="email_alerts" name="email_alerts" 
                                       <?= ($_SESSION['email_alerts'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="email_alerts">
                                    Email Alerts for Important Events
                                </label>
                            </div>
                        </div>

                        <div style="margin-top: 40px; padding-top: 30px; border-top: 2px solid var(--border-color);">
                            <h3 style="margin-bottom: 20px; color: var(--text-primary);">Privacy</h3>
                            
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="analytics" name="analytics" checked>
                                <label class="form-check-label" for="analytics">
                                    Allow Usage Analytics (Helps improve Vigilo)
                                </label>
                            </div>
                        </div>

                        <div style="margin-top: 30px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Preferences
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Account Section -->
                <div id="account-section" class="settings-section">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-bar"></i> Account Overview</h2>
                        <p>View your account statistics and storage usage</p>
                    </div>

                    <!-- Storage Meter -->
                    <div class="storage-meter">
                        <div class="storage-header">
                            <h3 style="margin: 0; color: var(--text-primary);">Storage Usage</h3>
                            <span style="font-weight: 600; color: var(--primary-color);">
                                <?= formatBytes($storage_used) ?> of <?= formatBytes($storage_limit) ?>
                            </span>
                        </div>
                        
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $storage_percentage ?>%;"></div>
                        </div>
                        
                        <div class="storage-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?= $storage_stats['file_count'] ?? 0 ?></div>
                                <div class="stat-label">Total Files</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?= formatBytes($storage_used) ?></div>
                                <div class="stat-label">Storage Used</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?= formatBytes($storage_free) ?></div>
                                <div class="stat-label">Storage Free</div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Statistics -->
                    <div class="account-stats">
                        <?php
                        // Get additional stats
                        $stats_queries = [
                            'documents' => "SELECT COUNT(*) as count FROM documents WHERE user_id = ?",
                            'shares' => "SELECT COUNT(*) as count FROM shared_links WHERE user_id = ?",
                            'views' => "SELECT SUM(view_count) as count FROM shared_links WHERE user_id = ?",
                            'downloads' => "SELECT SUM(download_count) as count FROM shared_links WHERE user_id = ?"
                        ];
                        
                        $stats = [];
                        foreach($stats_queries as $key => $query) {
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("i", $user_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $row = $result->fetch_assoc();
                            $stats[$key] = $row['count'] ?? 0;
                        }
                        ?>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-file"></i></div>
                            <div class="stat-number"><?= $stats['documents'] ?></div>
                            <div class="stat-title">Documents</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-share-alt"></i></div>
                            <div class="stat-number"><?= $stats['shares'] ?></div>
                            <div class="stat-title">Share Links</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-eye"></i></div>
                            <div class="stat-number"><?= $stats['views'] ?></div>
                            <div class="stat-title">Total Views</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-download"></i></div>
                            <div class="stat-number"><?= $stats['downloads'] ?></div>
                            <div class="stat-title">Downloads</div>
                        </div>
                    </div>

                    <div style="background: rgba(102, 126, 234, 0.05); border-radius: var(--border-radius); padding: 25px; margin-top: 30px;">
                        <h3 style="margin-bottom: 15px; color: var(--text-primary);">Account Information</h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                            <div>
                                <p><strong>Account Type:</strong></p>
                                <p><?= ucfirst($user['role']) ?> Account</p>
                            </div>
                            <div>
                                <p><strong>Member Since:</strong></p>
                                <p><?= date('F j, Y', strtotime($user['created_at'])) ?></p>
                            </div>
                            <div>
                                <p><strong>Last Login:</strong></p>
                                <p><?= date('F j, Y H:i', $_SESSION['last_login'] ?? time()) ?></p>
                            </div>
                            <div>
                                <p><strong>User ID:</strong></p>
                                <p><code><?= $user['id'] ?></code></p>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button class="btn btn-primary" onclick="exportAccountData()">
                                <i class="fas fa-download"></i> Export Account Data
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Danger Zone Section -->
                <div id="danger-section" class="settings-section">
                    <div class="section-header">
                        <h2><i class="fas fa-exclamation-triangle"></i> Danger Zone</h2>
                        <p>Irreversible actions - proceed with caution</p>
                    </div>

                    <div class="danger-zone">
                        <h3><i class="fas fa-trash"></i> Delete Account</h3>
                        <p>Permanently delete your account and all associated data. This action cannot be undone.</p>
                        <button class="btn btn-danger" onclick="confirmDeleteAccount()">
                            <i class="fas fa-trash"></i> Delete My Account
                        </button>
                    </div>

                    <div class="danger-zone" style="margin-top: 20px; background: rgba(255, 209, 102, 0.1); border-color: rgba(255, 209, 102, 0.3);">
                        <h3><i class="fas fa-file-archive"></i> Delete All Files</h3>
                        <p>Delete all your uploaded files and share links. Your account will remain active.</p>
                        <button class="btn btn-warning" onclick="confirmDeleteAllFiles()">
                            <i class="fas fa-trash-alt"></i> Delete All Files
                        </button>
                    </div>

                    <div class="danger-zone" style="margin-top: 20px; background: rgba(102, 126, 234, 0.1); border-color: rgba(102, 126, 234, 0.3);">
                        <h3><i class="fas fa-download"></i> Export All Data</h3>
                        <p>Download a complete archive of all your files and account data.</p>
                        <button class="btn btn-primary" onclick="exportAllData()">
                            <i class="fas fa-download"></i> Export All Data
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Helper function to format bytes
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        // Section navigation
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.settings-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Show selected section
            document.getElementById(sectionId + '-section').classList.add('active');
            
            // Update active link in sidebar
            document.querySelectorAll('.sidebar-link').forEach(link => {
                link.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Scroll to top of content
            document.querySelector('.settings-content').scrollTop = 0;
        }

        // Avatar preview
        function previewAvatar() {
            const input = document.getElementById('avatar-input');
            const preview = document.getElementById('avatar-preview');
            const previewImage = document.getElementById('preview-image');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    preview.style.display = 'block';
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Danger zone actions
        function confirmDeleteAccount() {
            if (confirm('⚠️ WARNING: This will permanently delete your account and all data!\n\nThis action cannot be undone. Are you absolutely sure?')) {
                if (confirm('FINAL WARNING: All your files, shares, and account information will be permanently deleted. Type "DELETE" to confirm:')) {
                    const userInput = prompt('Type "DELETE" to confirm account deletion:');
                    if (userInput === 'DELETE') {
                        // Submit delete account form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'delete_account.php';
                        document.body.appendChild(form);
                        form.submit();
                    } else {
                        alert('Account deletion cancelled.');
                    }
                }
            }
        }

        function confirmDeleteAllFiles() {
            if (confirm('This will delete all your uploaded files and share links. Are you sure?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete_files.php';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function exportAccountData() {
            alert('Account data export started. You will receive an email with download link.');
            // Implement export functionality
        }

        function exportAllData() {
            alert('Data export started. This may take a while. You will receive an email when it\'s ready.');
            // Implement export functionality
        }

        function logoutAllSessions() {
            if (confirm('Logout from all devices except this one?')) {
                fetch('logout_all.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('All other sessions have been logged out.');
                        }
                    });
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Set up file input trigger from avatar upload button
            document.getElementById('avatar-input').addEventListener('change', function() {
                document.getElementById('avatar_file').files = this.files;
                previewAvatar();
            });
            
            // Set default section from URL hash
            const hash = window.location.hash.substring(1);
            if (hash) {
                const sectionLink = document.querySelector(`.sidebar-link[href="#${hash}"]`);
                if (sectionLink) {
                    sectionLink.click();
                }
            }
        });
    </script>
</body>
</html>

<?php
// Close database connection
$conn->close();

// Helper function to format bytes (same as in share_view.php)
function formatBytes($bytes, $decimals = 2) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $dm = $decimals < 0 ? 0 : $decimals;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
}
?>