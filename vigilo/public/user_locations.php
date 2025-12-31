<?php
// user_locations.php - User Location Tracker Dashboard
session_start();
require_once('../config/db.php');

if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Initialize default preferences if not exists
initUserPreferences($conn, $user_id);

// Handle form submissions
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(isset($_POST['add_location'])) {
        addUserLocation($conn, $user_id);
    } elseif(isset($_POST['update_location'])) {
        updateUserLocation($conn, $user_id);
    } elseif(isset($_POST['delete_location'])) {
        deleteUserLocation($conn, $user_id);
    } elseif(isset($_POST['add_routine'])) {
        addUserRoutine($conn, $user_id);
    } elseif(isset($_POST['save_preferences'])) {
        saveUserPreferences($conn, $user_id);
    } elseif(isset($_POST['add_category'])) {
        addLocationCategory($conn, $user_id);
    }
}

// Get date filter
$date_filter = $_GET['date'] ?? date('Y-m-d');
$view_type = $_GET['view'] ?? 'day'; // day, week, month, year
$category_filter = $_GET['category'] ?? '';

// Get user locations for the selected date
$locations = getUserLocations($conn, $user_id, $date_filter, $view_type, $category_filter);

// Get user routines
$routines = getUserRoutines($conn, $user_id);

// Get user preferences
$preferences = getUserPreferences($conn, $user_id);

// Get user categories
$categories = getUserCategories($conn, $user_id);

// Get location statistics
$stats = getLocationStatistics($conn, $user_id, $view_type, $date_filter);

// Get recent locations for quick add
$recent_locations = getRecentLocations($conn, $user_id, 10);

// Get favorite locations
$favorite_locations = getFavoriteLocations($conn, $user_id);

// Initialize functions
function initUserPreferences($conn, $user_id) {
    $check_stmt = $conn->prepare("SELECT id FROM user_preferences WHERE user_id = ?");
    $check_stmt->bind_param("i", $user_id);
    $check_stmt->execute();
    
    if($check_stmt->get_result()->num_rows === 0) {
        $default_prefs = json_encode([
            'preferred_locations' => ['home', 'work', 'restaurant', 'cafe'],
            'notification_types' => ['new_nearby', 'routine_reminder'],
            'map_style' => 'standard'
        ]);
        
        $insert_stmt = $conn->prepare("INSERT INTO user_preferences (user_id, preferred_locations, notification_types) VALUES (?, ?, ?)");
        $insert_stmt->bind_param("iss", $user_id, $default_prefs, $default_prefs);
        $insert_stmt->execute();
    }
}

function getUserLocations($conn, $user_id, $date, $view_type, $category) {
    $locations = [];
    
    switch($view_type) {
        case 'week':
            $start_date = date('Y-m-d', strtotime('monday this week', strtotime($date)));
            $end_date = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
            $sql = "SELECT * FROM user_locations WHERE user_id = ? AND visit_date BETWEEN ? AND ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $user_id, $start_date, $end_date);
            break;
            
        case 'month':
            $start_date = date('Y-m-01', strtotime($date));
            $end_date = date('Y-m-t', strtotime($date));
            $sql = "SELECT * FROM user_locations WHERE user_id = ? AND visit_date BETWEEN ? AND ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $user_id, $start_date, $end_date);
            break;
            
        case 'year':
            $year = date('Y', strtotime($date));
            $sql = "SELECT * FROM user_locations WHERE user_id = ? AND YEAR(visit_date) = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $user_id, $year);
            break;
            
        default: // day
            $sql = "SELECT * FROM user_locations WHERE user_id = ? AND visit_date = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $user_id, $date);
    }
    
    if(!empty($category)) {
        $sql .= " AND location_type = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $user_id, $date, $category);
    }
    
    $sql .= " ORDER BY arrival_time ASC";
    
    $stmt->execute();
    $result = $stmt->get_result();
    $locations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $locations;
}

function getUserRoutines($conn, $user_id) {
    $routines = [];
    $stmt = $conn->prepare("SELECT * FROM user_routines WHERE user_id = ? AND is_active = TRUE ORDER BY day_of_week, start_time");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $routines = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $routines;
}

function getUserPreferences($conn, $user_id) {
    $prefs = [];
    $stmt = $conn->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $prefs = $result->fetch_assoc();
    $stmt->close();
    
    if($prefs) {
        $prefs['preferred_locations'] = json_decode($prefs['preferred_locations'] ?? '[]', true);
        $prefs['notification_types'] = json_decode($prefs['notification_types'] ?? '[]', true);
    }
    
    return $prefs;
}

function getUserCategories($conn, $user_id) {
    $categories = [];
    
    // Get default categories (user_id = 0)
    $default_stmt = $conn->prepare("SELECT * FROM location_categories WHERE user_id = 0 ORDER BY id");
    $default_stmt->execute();
    $default_result = $default_stmt->get_result();
    $default_categories = $default_result->fetch_all(MYSQLI_ASSOC);
    
    // Get user's custom categories
    $custom_stmt = $conn->prepare("SELECT * FROM location_categories WHERE user_id = ? ORDER BY created_at");
    $custom_stmt->bind_param("i", $user_id);
    $custom_stmt->execute();
    $custom_result = $custom_stmt->get_result();
    $custom_categories = $custom_result->fetch_all(MYSQLI_ASSOC);
    
    $categories = array_merge($default_categories, $custom_categories);
    
    return $categories;
}

function getLocationStatistics($conn, $user_id, $view_type, $date) {
    $stats = [
        'total_locations' => 0,
        'total_duration' => 0,
        'most_visited' => '',
        'average_rating' => 0,
        'locations_by_type' => [],
        'timeline_data' => []
    ];
    
    // Get basic stats
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_locations,
            COALESCE(SUM(duration_minutes), 0) as total_duration,
            AVG(rating) as average_rating
        FROM user_locations 
        WHERE user_id = ? AND visit_date = ?
    ");
    $stmt->bind_param("is", $user_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    if($row = $result->fetch_assoc()) {
        $stats['total_locations'] = $row['total_locations'];
        $stats['total_duration'] = $row['total_duration'];
        $stats['average_rating'] = number_format($row['average_rating'], 1);
    }
    $stmt->close();
    
    // Get most visited location
    $most_stmt = $conn->prepare("
        SELECT location_name, COUNT(*) as visit_count 
        FROM user_locations 
        WHERE user_id = ? AND visit_date = ?
        GROUP BY location_name 
        ORDER BY visit_count DESC 
        LIMIT 1
    ");
    $most_stmt->bind_param("is", $user_id, $date);
    $most_stmt->execute();
    $most_result = $most_stmt->get_result();
    if($row = $most_result->fetch_assoc()) {
        $stats['most_visited'] = $row['location_name'];
    }
    $most_stmt->close();
    
    // Get locations by type
    $type_stmt = $conn->prepare("
        SELECT location_type, COUNT(*) as count 
        FROM user_locations 
        WHERE user_id = ? AND visit_date = ?
        GROUP BY location_type
    ");
    $type_stmt->bind_param("is", $user_id, $date);
    $type_stmt->execute();
    $type_result = $type_stmt->get_result();
    while($row = $type_result->fetch_assoc()) {
        $stats['locations_by_type'][$row['location_type']] = $row['count'];
    }
    $type_stmt->close();
    
    return $stats;
}

function getRecentLocations($conn, $user_id, $limit = 10) {
    $locations = [];
    $stmt = $conn->prepare("
        SELECT DISTINCT location_name, address, latitude, longitude, location_type
        FROM user_locations 
        WHERE user_id = ? 
        AND location_name IS NOT NULL
        ORDER BY visit_date DESC, arrival_time DESC 
        LIMIT ?
    ");
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $locations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $locations;
}

function getFavoriteLocations($conn, $user_id) {
    $locations = [];
    $stmt = $conn->prepare("
        SELECT * FROM user_locations 
        WHERE user_id = ? AND is_favorite = TRUE 
        ORDER BY visit_date DESC 
        LIMIT 20
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $locations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $locations;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Locations - Vigilo Personal Tracker</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #06d6a0;
            --warning: #ffd166;
            --danger: #ef476f;
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
            background: #f5f7fb;
            min-height: 100vh;
            color: #333;
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .app-sidebar {
            width: 280px;
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 100;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
        }

        .app-main {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }

        @media (max-width: 1024px) {
            .app-sidebar {
                width: 100%;
                position: fixed;
                top: 0;
                left: -100%;
                height: 100vh;
                transition: left 0.3s ease;
                z-index: 1000;
            }
            
            .app-sidebar.active {
                left: 0;
            }
            
            .app-main {
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

        /* Sidebar Header */
        .sidebar-header {
            padding: 30px 20px;
            border-bottom: 1px solid #e2e8f0;
            text-align: center;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 10px;
        }

        .sidebar-logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .sidebar-logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2d3748;
        }

        .sidebar-subtitle {
            color: #718096;
            font-size: 0.9rem;
        }

        /* Sidebar Navigation */
        .sidebar-nav {
            padding: 20px 0;
        }

        .nav-section {
            margin-bottom: 30px;
        }

        .nav-section-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #718096;
            padding: 0 20px 10px;
            margin-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
            letter-spacing: 1px;
        }

        .nav-links {
            list-style: none;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #2d3748;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-link:hover {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary);
        }

        .nav-link.active {
            color: var(--primary);
            background: rgba(102, 126, 234, 0.1);
            border-right: 3px solid var(--primary);
        }

        .nav-icon {
            width: 20px;
            margin-right: 15px;
            text-align: center;
            font-size: 1.1rem;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            min-width: 24px;
            text-align: center;
        }

        /* Page Header */
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

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Main Content Cards */
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .stat-icon.places { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        .stat-icon.time { background: linear-gradient(135deg, var(--success), #00c9a7); }
        .stat-icon.favorites { background: linear-gradient(135deg, var(--warning), #ff9e00); }
        .stat-icon.rating { background: linear-gradient(135deg, var(--info), #4fc3f7); }

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

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary);
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -25px;
            top: 20px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary);
            border: 2px solid white;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }

        .timeline-time {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .timeline-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .timeline-location {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .timeline-notes {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
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
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }

        /* Map Container */
        .map-container {
            height: 400px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        #locationsMap {
            width: 100%;
            height: 100%;
        }

        /* Recent Locations */
        .recent-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .location-chip {
            background: #f8f9fa;
            border-radius: 20px;
            padding: 0.5rem 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.25rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .location-chip:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }

        .location-icon {
            color: var(--primary);
        }

        /* Routines List */
        .routines-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .routine-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }

        .routine-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .routine-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .routine-time {
            font-weight: 600;
            color: var(--primary);
            min-width: 100px;
        }

        .routine-name {
            font-weight: 500;
            color: #333;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
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
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(67, 97, 238, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #00b894);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #ff9e00);
            color: #212529;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #ff3366);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
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

        /* Filters */
        .filters-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .date-picker {
            max-width: 200px;
        }

        .view-toggle {
            display: flex;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.25rem;
        }

        .view-toggle-btn {
            padding: 0.5rem 1rem;
            border: none;
            background: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .view-toggle-btn.active {
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Sidebar -->
        <aside class="app-sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <div class="sidebar-logo-icon">
                        <i class="fas fa-location-dot"></i>
                    </div>
                    <span class="sidebar-logo-text">Vigilo</span>
                </div>
                <div class="sidebar-subtitle">Personal Location Tracker</div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <h3 class="nav-section-title">Navigation</h3>
                    <ul class="nav-links">
                        <li>
                            <a href="dashboard.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li>
                            <a href="user_locations.php" class="nav-link active">
                                <span class="nav-icon"><i class="fas fa-location-dot"></i></span>
                                <span>My Locations</span>
                                <span class="nav-badge"><?= $stats['total_locations'] ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="user_routines.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
                                <span>Routines</span>
                                <span class="nav-badge"><?= count($routines) ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="location_analytics.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                                <span>Analytics</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="nav-section">
                    <h3 class="nav-section-title">Quick Access</h3>
                    <ul class="nav-links">
                        <li>
                            <a href="favorites.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-star"></i></span>
                                <span>Favorites</span>
                                <span class="nav-badge"><?= count($favorite_locations) ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="recent_locations.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-history"></i></span>
                                <span>Recent</span>
                            </a>
                        </li>
                        <li>
                            <a href="location_categories.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-tags"></i></span>
                                <span>Categories</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="nav-section">
                    <h3 class="nav-section-title">Settings</h3>
                    <ul class="nav-links">
                        <li>
                            <a href="location_preferences.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-cog"></i></span>
                                <span>Preferences</span>
                            </a>
                        </li>
                        <li>
                            <a href="privacy_settings.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-shield-alt"></i></span>
                                <span>Privacy</span>
                            </a>
                        </li>
                        <li>
                            <a href="export_data.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-download"></i></span>
                                <span>Export Data</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="nav-section">
                    <h3 class="nav-section-title">Main Site</h3>
                    <ul class="nav-links">
                        <li>
                            <a href="items.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-box"></i></span>
                                <span>Items Tracking</span>
                            </a>
                        </li>
                        <li>
                            <a href="public_search.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-search"></i></span>
                                <span>Lost & Found</span>
                            </a>
                        </li>
                        <li>
                            <a href="index.php" class="nav-link">
                                <span class="nav-icon"><i class="fas fa-home"></i></span>
                                <span>Home</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="app-main">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="header-title">
                    <i class="fas fa-location-dot"></i> My Location Tracker
                </h1>
                <p class="header-subtitle">
                    Track your daily locations, routines, and preferences. Build your personal location diary.
                </p>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <div class="action-card" onclick="showAddLocationModal()">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <h3 class="action-title">Add Location</h3>
                    <p>Log where you are or where you've been</p>
                </div>

                <div class="action-card" onclick="showAddRoutineModal()">
                    <div class="action-icon">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <h3 class="action-title">Add Routine</h3>
                    <p>Create a regular schedule location</p>
                </div>

                <div class="action-card" onclick="window.open('location_preferences.php', '_self')">
                    <div class="action-icon">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <h3 class="action-title">Preferences</h3>
                    <p>Customize your tracking settings</p>
                </div>

                <div class="action-card" onclick="showQuickLocationModal()">
                    <div class="action-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h3 class="action-title">Quick Log</h3>
                    <p>Quickly log your current location</p>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon places">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="stat-value"><?= $stats['total_locations'] ?></div>
                    <div class="stat-label">Locations Today</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon time">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?= floor($stats['total_duration'] / 60) ?>h</div>
                    <div class="stat-label">Total Time Spent</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon favorites">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-value"><?= count($favorite_locations) ?></div>
                    <div class="stat-label">Favorite Places</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon rating">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value"><?= $stats['average_rating'] ?>/5</div>
                    <div class="stat-label">Average Rating</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-bar">
                <div class="form-group date-picker">
                    <input type="date" class="form-control" id="datePicker" 
                           value="<?= $date_filter ?>" onchange="changeDate(this.value)">
                </div>

                <div class="view-toggle">
                    <button class="view-toggle-btn <?= $view_type == 'day' ? 'active' : '' ?>" 
                            onclick="changeView('day')">Day</button>
                    <button class="view-toggle-btn <?= $view_type == 'week' ? 'active' : '' ?>" 
                            onclick="changeView('week')">Week</button>
                    <button class="view-toggle-btn <?= $view_type == 'month' ? 'active' : '' ?>" 
                            onclick="changeView('month')">Month</button>
                </div>

                <select class="form-control" style="max-width: 200px;" 
                        onchange="filterByCategory(this.value)">
                    <option value="">All Categories</option>
                    <?php foreach($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat['category_name']) ?>" 
                        <?= $category_filter === $cat['category_name'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['category_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <button class="btn btn-primary" onclick="showAddLocationModal()">
                    <i class="fas fa-plus"></i> Add Location
                </button>
            </div>

            <div class="dashboard-grid">
                <!-- Left Column: Timeline & Map -->
                <div>
                    <!-- Today's Timeline -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-history"></i> Today's Timeline
                            </h2>
                            <span class="badge" style="background: var(--primary); color: white; padding: 0.25rem 0.75rem; border-radius: 20px;">
                                <?= date('F j, Y', strtotime($date_filter)) ?>
                            </span>
                        </div>

                        <?php if(!empty($locations)): ?>
                        <div class="timeline">
                            <?php foreach($locations as $location): 
                                $category = $categories[array_search($location['location_type'], array_column($categories, 'category_name'))] ?? $categories[0];
                            ?>
                            <div class="timeline-item" 
                                 data-lat="<?= $location['latitude'] ?>" 
                                 data-lng="<?= $location['longitude'] ?>"
                                 style="border-left-color: <?= $category['color'] ?>;">
                                
                                <div class="timeline-time">
                                    <?= date('g:i A', strtotime($location['arrival_time'])) ?> - 
                                    <?= date('g:i A', strtotime($location['departure_time'])) ?>
                                    <?php if($location['duration_minutes']): ?>
                                    <span style="color: var(--gray); font-size: 0.9rem;">
                                        (<?= floor($location['duration_minutes']/60) ?>h <?= $location['duration_minutes']%60 ?>m)
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <h3 class="timeline-title">
                                    <i class="fas fa-<?= $category['icon'] ?>" style="color: <?= $category['color'] ?>;"></i>
                                    <?= htmlspecialchars($location['location_name']) ?>
                                    <?php if($location['is_favorite']): ?>
                                    <i class="fas fa-star" style="color: var(--warning); margin-left: 5px;"></i>
                                    <?php endif; ?>
                                </h3>
                                
                                <div class="timeline-location">
                                    <i class="fas fa-map-pin"></i> 
                                    <?= htmlspecialchars($location['address']) ?>
                                    <?php if($location['rating']): ?>
                                    <span style="color: var(--warning); margin-left: 10px;">
                                        <i class="fas fa-star"></i> <?= $location['rating'] ?>/5
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if(!empty($location['notes'])): ?>
                                <p class="timeline-notes"><?= htmlspecialchars($location['notes']) ?></p>
                                <?php endif; ?>
                                
                                <div style="margin-top: 10px; display: flex; gap: 10px;">
                                    <button class="btn btn-sm" 
                                            onclick="editLocation(<?= $location['id'] ?>)"
                                            style="background: #e9ecef; color: #495057;">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-sm btn-danger" 
                                            onclick="deleteLocation(<?= $location['id'] ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-map-marked-alt"></i>
                            <h3>No Locations Recorded</h3>
                            <p>Add your first location to start tracking your day.</p>
                            <button class="btn btn-primary" onclick="showAddLocationModal()" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Add Location
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Map View -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-map"></i> Location Map
                            </h2>
                            <button class="btn btn-sm btn-primary" onclick="refreshMap()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                        
                        <div class="map-container">
                            <div id="locationsMap"></div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Recent & Routines -->
                <div>
                    <!-- Recent Locations -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-history"></i> Recent Locations
                            </h2>
                            <a href="recent_locations.php" class="btn btn-sm btn-primary">
                                View All
                            </a>
                        </div>
                        
                        <div class="recent-grid">
                            <?php foreach($recent_locations as $location): ?>
                            <div class="location-chip" onclick="quickAddLocation('<?= htmlspecialchars($location['location_name']) ?>')">
                                <i class="fas fa-map-marker-alt location-icon"></i>
                                <span><?= htmlspecialchars($location['location_name']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Active Routines -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-calendar-alt"></i> Active Routines
                            </h2>
                            <button class="btn btn-sm btn-primary" onclick="showAddRoutineModal()">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>
                        
                        <div class="routines-list">
                            <?php foreach($routines as $routine): ?>
                            <div class="routine-item">
                                <div class="routine-info">
                                    <div class="routine-time">
                                        <?= date('g:i A', strtotime($routine['start_time'])) ?>
                                    </div>
                                    <div>
                                        <div class="routine-name"><?= htmlspecialchars($routine['routine_name']) ?></div>
                                        <div style="font-size: 0.8rem; color: var(--gray);">
                                            <i class="fas fa-calendar"></i> <?= ucfirst($routine['day_of_week']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <button class="btn btn-sm" onclick="editRoutine(<?= $routine['id'] ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if(empty($routines)): ?>
                        <div class="empty-state" style="padding: 1.5rem;">
                            <i class="fas fa-calendar"></i>
                            <p>No routines set up</p>
                            <button class="btn btn-sm btn-primary" onclick="showAddRoutineModal()">
                                <i class="fas fa-plus"></i> Create Routine
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Categories -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-tags"></i> Location Categories
                            </h2>
                            <button class="btn btn-sm btn-primary" onclick="showCategoryModal()">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                            <?php foreach($categories as $category): ?>
                            <div class="location-chip" style="background: <?= $category['color'] ?>20; border: 1px solid <?= $category['color'] ?>;">
                                <i class="fas fa-<?= $category['icon'] ?>" style="color: <?= $category['color'] ?>;"></i>
                                <span style="color: <?= $category['color'] ?>;"><?= htmlspecialchars($category['category_name']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Location Modal -->
    <div class="modal-overlay" id="addLocationModal">
        <div class="modal" style="max-width: 800px;">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-plus-circle"></i> Add Location
                </h2>
                <button class="modal-close" onclick="closeModal('addLocationModal')">&times;</button>
            </div>
            <form method="POST" action="" id="addLocationForm">
                <input type="hidden" name="add_location" value="1">
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Location Name *</label>
                        <input type="text" class="form-control" name="location_name" required 
                               placeholder="e.g., Starbucks Downtown" id="locationNameInput">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select class="form-control" name="location_type" required id="categorySelect">
                            <option value="">Select Category</option>
                            <?php foreach($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['category_name']) ?>" 
                                    data-color="<?= $category['color'] ?>"
                                    data-icon="<?= $category['icon'] ?>">
                                <?= htmlspecialchars($category['category_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" class="form-control" name="address" 
                           placeholder="Enter full address" id="addressInput">
                    <button type="button" class="btn btn-sm" onclick="getCurrentLocation()" 
                            style="margin-top: 5px; background: #e9ecef; color: #495057;">
                        <i class="fas fa-location-arrow"></i> Use Current Location
                    </button>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Visit Date *</label>
                        <input type="date" class="form-control" name="visit_date" required 
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Time Range</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <input type="time" class="form-control" name="arrival_time" 
                                   value="<?= date('H:i') ?>">
                            <input type="time" class="form-control" name="departure_time">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes" rows="3" 
                              placeholder="Add any notes about this location..."></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Rating (1-5)</label>
                        <div class="star-rating">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                            <i class="far fa-star" data-rating="<?= $i ?>" 
                               onclick="setRating(this)" style="cursor: pointer; font-size: 1.5rem; color: #ffd166;"></i>
                            <?php endfor; ?>
                            <input type="hidden" name="rating" id="ratingInput" value="5">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; margin-top: 25px;">
                            <input type="checkbox" name="is_favorite" value="1">
                            <span>Add to Favorites</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                            <input type="checkbox" name="is_private" value="1">
                            <span>Make Private</span>
                        </label>
                    </div>
                </div>
                
                <input type="hidden" name="latitude" id="latInput">
                <input type="hidden" name="longitude" id="lngInput">
                
                <div style="display: flex; gap: 15px; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-save"></i> Save Location
                    </button>
                    <button type="button" class="btn" onclick="closeModal('addLocationModal')" 
                            style="flex: 1; background: #e9ecef; color: #495057;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Routine Modal -->
    <div class="modal-overlay" id="addRoutineModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-calendar-plus"></i> Add Routine
                </h2>
                <button class="modal-close" onclick="closeModal('addRoutineModal')">&times;</button>
            </div>
            <form method="POST" action="" id="addRoutineForm">
                <input type="hidden" name="add_routine" value="1">
                
                <div class="form-group">
                    <label class="form-label">Routine Name *</label>
                    <input type="text" class="form-control" name="routine_name" required 
                           placeholder="e.g., Morning Gym Session">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" class="form-control" name="location_name" 
                               placeholder="e.g., Fitness First Gym">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Day of Week *</label>
                        <select class="form-control" name="day_of_week" required>
                            <option value="daily">Daily</option>
                            <option value="monday">Monday</option>
                            <option value="tuesday">Tuesday</option>
                            <option value="wednesday">Wednesday</option>
                            <option value="thursday">Thursday</option>
                            <option value="friday">Friday</option>
                            <option value="saturday">Saturday</option>
                            <option value="sunday">Sunday</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Time *</label>
                        <input type="time" class="form-control" name="start_time" required 
                               value="09:00">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Time</label>
                        <input type="time" class="form-control" name="end_time" 
                               value="10:00">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes" rows="3" 
                              placeholder="Add notes about this routine..."></textarea>
                </div>
                
                <div style="display: flex; gap: 15px; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-save"></i> Save Routine
                    </button>
                    <button type="button" class="btn" onclick="closeModal('addRoutineModal')" 
                            style="flex: 1; background: #e9ecef; color: #495057;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Add Modal -->
    <div class="modal-overlay" id="quickLocationModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-bolt"></i> Quick Location Log
                </h2>
                <button class="modal-close" onclick="closeModal('quickLocationModal')">&times;</button>
            </div>
            <form method="POST" action="" id="quickLocationForm">
                <input type="hidden" name="add_location" value="1">
                <input type="hidden" name="visit_date" value="<?= date('Y-m-d') ?>">
                <input type="hidden" name="arrival_time" value="<?= date('H:i') ?>">
                
                <div class="form-group">
                    <label class="form-label">Where are you? *</label>
                    <input type="text" class="form-control" name="location_name" required 
                           placeholder="Enter location name" id="quickLocationName">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select class="form-control" name="location_type">
                        <option value="other">Other</option>
                        <?php foreach($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category['category_name']) ?>">
                            <?= htmlspecialchars($category['category_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: flex; gap: 15px; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-check"></i> Log It
                    </button>
                    <button type="button" class="btn" onclick="closeModal('quickLocationModal')" 
                            style="flex: 1; background: #e9ecef; color: #495057;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize map
        let map;
        let markers = [];
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize map
            map = L.map('locationsMap').setView([0, 0], 2);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(map);
            
            // Add location markers
            const locations = <?= json_encode($locations) ?>;
            const categories = <?= json_encode($categories) ?>;
            
            locations.forEach(location => {
                if(location.latitude && location.longitude) {
                    const category = categories.find(c => c.category_name === location.location_type) || categories[0];
                    
                    const marker = L.marker([parseFloat(location.latitude), parseFloat(location.longitude)], {
                        icon: L.divIcon({
                            className: 'custom-marker',
                            html: `<div style="background: ${category.color}; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px;">
                                      <i class="fas fa-${category.icon}"></i>
                                  </div>`,
                            iconSize: [24, 24],
                            iconAnchor: [12, 12]
                        })
                    }).addTo(map);
                    
                    marker.bindPopup(`
                        <div style="max-width: 200px;">
                            <h4 style="margin: 0 0 10px 0; color: #333;">
                                <i class="fas fa-${category.icon}" style="color: ${category.color};"></i>
                                ${escapeHtml(location.location_name)}
                            </h4>
                            <p style="margin: 0 0 5px 0; color: #666;">
                                <i class="fas fa-clock"></i> ${location.arrival_time}
                            </p>
                            <p style="margin: 0 0 10px 0; color: #666;">
                                <i class="fas fa-map-pin"></i> ${escapeHtml(location.address || 'No address')}
                            </p>
                        </div>
                    `);
                    
                    markers.push(marker);
                }
            });
            
            // Fit bounds to show all markers
            if(markers.length > 0) {
                const group = new L.featureGroup(markers);
                map.fitBounds(group.getBounds().pad(0.1));
            }
            
            // Initialize date picker
            flatpickr("#datePicker", {
                dateFormat: "Y-m-d",
                defaultDate: "<?= $date_filter ?>"
            });
            
            // Initialize time inputs
            flatpickr("input[type=time]", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i",
                time_24hr: true
            });
        });
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Modal functions
        function showModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function showAddLocationModal() {
            showModal('addLocationModal');
        }
        
        function showAddRoutineModal() {
            showModal('addRoutineModal');
        }
        
        function showQuickLocationModal() {
            document.getElementById('quickLocationName').value = '';
            showModal('quickLocationModal');
        }
        
        function showCategoryModal() {
            window.location.href = 'location_categories.php';
        }
        
        // Star rating
        function setRating(star) {
            const rating = parseInt(star.dataset.rating);
            document.getElementById('ratingInput').value = rating;
            
            // Update star display
            const stars = document.querySelectorAll('.star-rating i');
            stars.forEach((s, index) => {
                if(index < rating) {
                    s.className = 'fas fa-star';
                } else {
                    s.className = 'far fa-star';
                }
            });
        }
        
        // Get current location
        function getCurrentLocation() {
            if(navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    document.getElementById('latInput').value = position.coords.latitude;
                    document.getElementById('lngInput').value = position.coords.longitude;
                    
                    // Reverse geocode to get address
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${position.coords.latitude}&lon=${position.coords.longitude}`)
                        .then(response => response.json())
                        .then(data => {
                            if(data.display_name) {
                                document.getElementById('addressInput').value = data.display_name;
                            }
                        });
                });
            } else {
                alert('Geolocation is not supported by your browser.');
            }
        }
        
        // Quick add location from recent
        function quickAddLocation(locationName) {
            document.getElementById('locationNameInput').value = locationName;
            showModal('addLocationModal');
        }
        
        // Change date
        function changeDate(date) {
            const url = new URL(window.location.href);
            url.searchParams.set('date', date);
            window.location.href = url.toString();
        }
        
        // Change view
        function changeView(viewType) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', viewType);
            window.location.href = url.toString();
        }
        
        // Filter by category
        function filterByCategory(category) {
            const url = new URL(window.location.href);
            if(category) {
                url.searchParams.set('category', category);
            } else {
                url.searchParams.delete('category');
            }
            window.location.href = url.toString();
        }
        
        // Edit location
        function editLocation(locationId) {
            window.location.href = `edit_location.php?id=${locationId}`;
        }
        
        // Edit routine
        function editRoutine(routineId) {
            window.location.href = `edit_routine.php?id=${routineId}`;
        }
        
        // Delete location
        function deleteLocation(locationId) {
            if(confirm('Are you sure you want to delete this location?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="delete_location" value="1">
                    <input type="hidden" name="location_id" value="${locationId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Refresh map
        function refreshMap() {
            window.location.reload();
        }
        
        // Toggle sidebar on mobile
        function toggleSidebar() {
            document.querySelector('.app-sidebar').classList.toggle('active');
        }
    </script>
</body>
</html>

<?php
// Close database connection
if(isset($conn)) {
    $conn->close();
}
?>