<?php
// location_search.php - Location-based lost items search with maps
session_start();
require_once('../config/db.php');

// Initialize variables
$latitude = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$longitude = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$radius = isset($_GET['radius']) ? intval($_GET['radius']) : 10; // Default 10km
$address = isset($_GET['address']) ? trim($_GET['address']) : '';
$city = isset($_GET['city']) ? trim($_GET['city']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : '30';

// Get user's location from session or geolocation
$user_location = [];
if(isset($_SESSION['user_location'])) {
    $user_location = $_SESSION['user_location'];
} elseif(isset($_COOKIE['user_location'])) {
    $user_location = json_decode($_COOKIE['user_location'], true);
}

// If coordinates provided via GET, use them
if($latitude && $longitude) {
    $user_location = ['lat' => $latitude, 'lng' => $longitude];
}

// Get available cities from database
$cities_stmt = $conn->prepare("
    SELECT DISTINCT 
        CASE 
            WHEN incident_location LIKE '%,%' THEN TRIM(SUBSTRING_INDEX(incident_location, ',', -1))
            ELSE incident_location 
        END as city,
        COUNT(*) as item_count
    FROM items 
    WHERE is_public = 1 
    AND status IN ('lost', 'stolen') 
    AND incident_location IS NOT NULL 
    AND incident_location != ''
    GROUP BY city 
    HAVING city IS NOT NULL AND city != ''
    ORDER BY item_count DESC 
    LIMIT 20
");
$cities = [];
if($cities_stmt) {
    $cities_stmt->execute();
    $cities_result = $cities_stmt->get_result();
    while($row = $cities_result->fetch_assoc()) {
        $cities[] = $row;
    }
    $cities_stmt->close();
}

// Get categories with counts
$categories_stmt = $conn->prepare("
    SELECT category, COUNT(*) as item_count 
    FROM items 
    WHERE is_public = 1 AND status IN ('lost', 'stolen') AND category IS NOT NULL AND category != ''
    GROUP BY category 
    ORDER BY item_count DESC
");
$categories_with_counts = [];
if($categories_stmt) {
    $categories_stmt->execute();
    $cats_result = $categories_stmt->get_result();
    while($row = $cats_result->fetch_assoc()) {
        $categories_with_counts[] = $row;
    }
    $categories_stmt->close();
}

// Process search
$items = [];
$map_items = [];

if(!empty($address) || !empty($city) || ($latitude && $longitude)) {
    // Build base query
    $sql = "SELECT 
        i.*,
        DATE_FORMAT(i.reported_date, '%M %d, %Y') as formatted_date,
        (6371 * ACOS(
            COS(RADIANS(?)) * 
            COS(RADIANS(i.latitude)) * 
            COS(RADIANS(i.longitude) - RADIANS(?)) + 
            SIN(RADIANS(?)) * 
            SIN(RADIANS(i.latitude))
        )) AS distance
    FROM items i 
    WHERE i.is_public = 1 
    AND i.status IN ('lost', 'stolen')";
    
    $params = [];
    if($latitude && $longitude) {
        $params = [$latitude, $longitude, $latitude];
    } else {
        $params = [0, 0, 0];
    }
    $types = 'ddd';
    
    // Add filters
    if(!empty($address)) {
        $sql .= " AND (i.incident_location LIKE ? OR i.address LIKE ?)";
        $address_term = "%$address%";
        $params[] = $address_term;
        $params[] = $address_term;
        $types .= 'ss';
    }
    
    if(!empty($city)) {
        $sql .= " AND i.incident_location LIKE ?";
        $params[] = "%$city%";
        $types .= 's';
    }
    
    if(!empty($category)) {
        $sql .= " AND i.category = ?";
        $params[] = $category;
        $types .= 's';
    }
    
    // Add date range filter
    if(is_numeric($date_range) && $date_range > 0) {
        $sql .= " AND i.reported_date >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params[] = $date_range;
        $types .= 'i';
    }
    
    // Add distance filter if coordinates provided
    if($latitude && $longitude) {
        $sql .= " HAVING distance <= ?";
        $params[] = $radius;
        $types .= 'd';
    }
    
    $sql .= " ORDER BY ";
    if($latitude && $longitude) {
        $sql .= "distance ASC, ";
    }
    $sql .= "i.reward_amount DESC, i.reported_date DESC 
            LIMIT 100";
    
    // Execute query
    $stmt = $conn->prepare($sql);
    if($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Prepare items for map
        foreach($items as $item) {
            if(!empty($item['latitude']) && !empty($item['longitude'])) {
                $map_items[] = [
                    'id' => $item['id'],
                    'name' => $item['item_name'],
                    'lat' => floatval($item['latitude']),
                    'lng' => floatval($item['longitude']),
                    'category' => $item['category'],
                    'status' => $item['status'],
                    'reward' => $item['reward_amount'],
                    'location' => $item['incident_location'],
                    'date' => $item['formatted_date']
                ];
            }
        }
    }
}

// Get statistics for top locations
$top_locations_sql = "
    SELECT 
        incident_location as location,
        COUNT(*) as item_count,
        COALESCE(SUM(reward_amount), 0) as total_reward
    FROM items 
    WHERE is_public = 1 AND status IN ('lost', 'stolen') AND incident_location IS NOT NULL
    GROUP BY incident_location 
    HAVING item_count > 1
    ORDER BY item_count DESC 
    LIMIT 10
";
$top_locations_result = $conn->query($top_locations_sql);
$top_locations = $top_locations_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Location Search - Find Lost Items by Location | Vigilo</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header */
        .main-header {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 1.5rem;
        }

        .logo-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
        }

        .logo-text {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .tagline {
            color: var(--gray);
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }

        /* Navigation */
        .main-nav {
            background: white;
            border-radius: 15px;
            padding: 1rem 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
        }

        .nav-links a {
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-links a.active {
            color: var(--primary);
            font-weight: 600;
        }

        /* Main Content */
        .main-content {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .main-content {
                grid-template-columns: 1fr;
            }
        }

        /* Sidebar */
        .sidebar {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .sidebar-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .sidebar-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .sidebar-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Map Container */
        .map-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            height: 600px;
            position: relative;
        }

        #map {
            width: 100%;
            height: 100%;
            border-radius: 15px;
        }

        .map-controls {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 1000;
            background: white;
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            gap: 10px;
        }

        .map-controls .btn {
            padding: 8px 15px;
            font-size: 0.9rem;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
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
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        /* Results Section */
        .results-section {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .results-header h2 {
            color: var(--dark);
            font-size: 1.5rem;
        }

        .results-count {
            background: var(--primary);
            color: white;
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .items-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        /* Item Card */
        .item-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            cursor: pointer;
        }

        .item-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
            border-color: var(--primary);
        }

        .item-card.selected {
            border: 2px solid var(--primary);
            background: #f8f9ff;
        }

        .item-header {
            margin-bottom: 1rem;
        }

        .item-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .item-distance {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .item-meta {
            display: flex;
            gap: 1rem;
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .item-description {
            color: #666;
            line-height: 1.6;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .item-details {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .detail-label {
            color: var(--gray);
        }

        .detail-value {
            color: var(--dark);
            font-weight: 500;
        }

        .reward-badge {
            background: linear-gradient(135deg, rgba(6, 214, 160, 0.1), rgba(6, 214, 160, 0.2));
            color: var(--success);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        /* Top Locations */
        .top-locations {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .top-locations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .location-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
        }

        .location-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            background: white;
        }

        .location-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .location-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
        }

        .stat {
            text-align: center;
        }

        .stat-number {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Footer */
        .site-footer {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .copyright {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Loading Spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(67, 97, 238, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .main-nav {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
                gap: 1rem;
            }
            
            .items-list {
                grid-template-columns: 1fr;
            }
            
            .top-locations-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Navigation -->
        <nav class="main-nav">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <span class="logo-text">Vigilo</span>
            </div>
            
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-home"></i> Home</a>
                <a href="public_search.php"><i class="fas fa-search"></i> Search</a>
                <a href="location_search.php" class="active"><i class="fas fa-map-marker-alt"></i> Location Search</a>
                <a href="how_it_works.php"><i class="fas fa-question-circle"></i> How It Works</a>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="dashboard.php"><i class="fas fa-user"></i> Dashboard</a>
                <?php endif; ?>
            </div>
            
            <div class="user-auth">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="dashboard.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-user"></i> Dashboard
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </a>
                <?php endif; ?>
            </div>
        </nav>

        <!-- Header -->
        <header class="main-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-map-marked-alt"></i>
                </div>
                <span class="logo-text">Location Search</span>
            </div>
            <p class="tagline">Find lost items near you or search by location on the interactive map</p>
        </header>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Sidebar Filters -->
            <aside class="sidebar">
                <form method="GET" action="" id="searchForm">
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">
                            <i class="fas fa-filter"></i> Search Filters
                        </h3>
                        
                        <div class="form-group">
                            <label class="form-label">Address or Place</label>
                            <input type="text" class="form-control" name="address" 
                                   placeholder="Enter address, place, or landmark"
                                   value="<?= htmlspecialchars($address) ?>"
                                   id="addressInput">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">City</label>
                            <select class="form-control" name="city" id="citySelect">
                                <option value="">All Cities</option>
                                <?php foreach($cities as $city_data): ?>
                                <option value="<?= htmlspecialchars($city_data['city']) ?>" 
                                    <?= $city === $city_data['city'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($city_data['city']) ?> (<?= $city_data['item_count'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if($latitude && $longitude): ?>
                        <div class="form-group">
                            <label class="form-label">Search Radius</label>
                            <select class="form-control" name="radius" id="radiusSelect">
                                <option value="5" <?= $radius == 5 ? 'selected' : '' ?>>5 km</option>
                                <option value="10" <?= $radius == 10 ? 'selected' : '' ?>>10 km</option>
                                <option value="25" <?= $radius == 25 ? 'selected' : '' ?>>25 km</option>
                                <option value="50" <?= $radius == 50 ? 'selected' : '' ?>>50 km</option>
                                <option value="100" <?= $radius == 100 ? 'selected' : '' ?>>100 km</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select class="form-control" name="category">
                                <option value="">All Categories</option>
                                <?php foreach($categories_with_counts as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['category']) ?>" 
                                    <?= $category === $cat['category'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category']) ?> (<?= $cat['item_count'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Reported Within</label>
                            <select class="form-control" name="date_range">
                                <option value="7" <?= $date_range == 7 ? 'selected' : '' ?>>Last 7 days</option>
                                <option value="30" <?= $date_range == 30 ? 'selected' : '' ?>>Last 30 days</option>
                                <option value="90" <?= $date_range == 90 ? 'selected' : '' ?>>Last 90 days</option>
                                <option value="365" <?= $date_range == 365 ? 'selected' : '' ?>>Last year</option>
                                <option value="0" <?= $date_range == 0 ? 'selected' : '' ?>>All time</option>
                            </select>
                        </div>
                        
                        <input type="hidden" name="lat" id="latInput" value="<?= $latitude ?>">
                        <input type="hidden" name="lng" id="lngInput" value="<?= $longitude ?>">
                        
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-search"></i> Search Items
                        </button>
                        
                        <button type="button" class="btn btn-success btn-block" onclick="getUserLocation()" style="margin-top: 10px;">
                            <i class="fas fa-location-arrow"></i> Use My Location
                        </button>
                        
                        <button type="button" class="btn btn-warning btn-block" onclick="resetSearch()" style="margin-top: 10px;">
                            <i class="fas fa-redo"></i> Reset Search
                        </button>
                    </div>
                    
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">
                            <i class="fas fa-info-circle"></i> Search Tips
                        </h3>
                        <ul style="color: var(--gray); font-size: 0.9rem; line-height: 1.6; padding-left: 1.5rem;">
                            <li>Click on the map to search that location</li>
                            <li>Use "My Location" to search near you</li>
                            <li>Select a city from the dropdown for specific areas</li>
                            <li>Items with rewards are highlighted in green</li>
                            <li>Click any item on the map for details</li>
                        </ul>
                    </div>
                </form>
            </aside>

            <!-- Map Section -->
            <div class="map-container">
                <div id="map"></div>
                <div class="map-controls">
                    <button type="button" class="btn btn-primary" onclick="zoomToUser()" id="zoomToUserBtn">
                        <i class="fas fa-location-arrow"></i> My Location
                    </button>
                    <button type="button" class="btn btn-success" onclick="zoomToAll()" id="zoomToAllBtn">
                        <i class="fas fa-globe"></i> Show All
                    </button>
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <section class="results-section">
            <div class="results-header">
                <h2>Found Items</h2>
                <span class="results-count"><?= count($items) ?> Items Found</span>
            </div>
            
            <?php if(!empty($items)): ?>
                <div class="items-list" id="itemsList">
                    <?php foreach($items as $item): ?>
                    <div class="item-card" 
                         data-id="<?= $item['id'] ?>" 
                         data-lat="<?= $item['latitude'] ?? '' ?>" 
                         data-lng="<?= $item['longitude'] ?? '' ?>"
                         onclick="highlightItem(<?= $item['id'] ?>)">
                        
                        <div class="item-header">
                            <div class="item-name">
                                <?= htmlspecialchars($item['item_name']) ?>
                                <?php if(isset($item['distance'])): ?>
                                <span class="item-distance"><?= number_format($item['distance'], 1) ?> km</span>
                                <?php endif; ?>
                            </div>
                            <div class="item-meta">
                                <span><i class="fas fa-tag"></i> <?= htmlspecialchars($item['category'] ?? 'General') ?></span>
                                <span><i class="fas fa-calendar"></i> <?= $item['formatted_date'] ?></span>
                            </div>
                        </div>
                        
                        <p class="item-description">
                            <?= htmlspecialchars(substr($item['description'] ?? 'No description available', 0, 100)) ?>...
                        </p>
                        
                        <div class="item-details">
                            <div class="detail-row">
                                <span class="detail-label">Location:</span>
                                <span class="detail-value"><?= htmlspecialchars($item['incident_location'] ?? 'Unknown') ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Brand:</span>
                                <span class="detail-value"><?= htmlspecialchars($item['brand'] ?? 'N/A') ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Status:</span>
                                <span class="detail-value" style="color: <?= $item['status'] == 'lost' ? '#ffd166' : '#ef476f' ?>;">
                                    <?= strtoupper($item['status']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if($item['reward_amount'] > 0): ?>
                        <div class="reward-badge">
                            <i class="fas fa-award"></i>
                            Reward: $<?= number_format($item['reward_amount'], 2) ?>
                        </div>
                        <?php endif; ?>
                        
                        <div style="display: flex; gap: 10px;">
                            <a href="item_detail.php?id=<?= $item['id'] ?>" class="btn btn-primary btn-sm" style="flex: 1;">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                            <?php if($item['reward_amount'] > 0): ?>
                            <a href="claim_reward.php?item=<?= $item['id'] ?>" class="btn btn-success btn-sm" style="flex: 1;">
                                <i class="fas fa-award"></i> Claim
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-map-marked-alt"></i>
                    <h3>No Items Found in This Area</h3>
                    <p>Try searching a different location or adjust your filters.</p>
                    <?php if($address || $city || $category): ?>
                        <button onclick="resetSearch()" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-redo"></i> Clear Filters
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Top Locations Section -->
        <?php if(!empty($top_locations)): ?>
        <section class="top-locations">
            <div class="results-header">
                <h2>Hotspot Locations</h2>
                <span class="results-count">Areas with most lost items</span>
            </div>
            
            <div class="top-locations-grid">
                <?php foreach($top_locations as $location): ?>
                <div class="location-card" onclick="searchLocation('<?= htmlspecialchars($location['location']) ?>')">
                    <div class="location-name">
                        <i class="fas fa-map-pin"></i> <?= htmlspecialchars($location['location']) ?>
                    </div>
                    <div class="location-stats">
                        <div class="stat">
                            <div class="stat-number"><?= $location['item_count'] ?></div>
                            <div class="stat-label">Items</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number">$<?= number_format($location['total_reward'], 0) ?></div>
                            <div class="stat-label">Rewards</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="site-footer">
            <div class="copyright">
                &copy; <?= date('Y') ?> Vigilo Lost & Found System. All rights reserved.
            </div>
        </footer>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    
    <script>
        // Initialize map
        let map;
        let markers;
        let userMarker;
        let searchMarker;
        const mapItems = <?= json_encode($map_items) ?>;
        const userLocation = <?= json_encode($user_location) ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize map
            const defaultLat = <?= $latitude ?: ($user_location['lat'] ?? 0) ?>;
            const defaultLng = <?= $longitude ?: ($user_location['lng'] ?? 0) ?>;
            const hasUserLocation = defaultLat !== 0 && defaultLng !== 0;
            
            const initialLat = hasUserLocation ? defaultLat : 20.0;
            const initialLng = hasUserLocation ? defaultLng : 0;
            const initialZoom = hasUserLocation ? 12 : 2;
            
            map = L.map('map').setView([initialLat, initialLng], initialZoom);
            
            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(map);
            
            // Initialize marker cluster
            markers = L.markerClusterGroup({
                maxClusterRadius: 50,
                spiderfyOnMaxZoom: true,
                showCoverageOnHover: false,
                zoomToBoundsOnClick: true
            });
            
            // Add user location marker if available
            if(hasUserLocation) {
                userMarker = L.marker([defaultLat, defaultLng], {
                    icon: L.divIcon({
                        className: 'user-location-marker',
                        html: '<div style="background: #4361ee; width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>',
                        iconSize: [20, 20],
                        iconAnchor: [10, 10]
                    })
                }).addTo(map);
                
                userMarker.bindPopup('<b>Your Location</b>').openPopup();
                
                // Add search radius circle
                L.circle([defaultLat, defaultLng], {
                    color: '#4361ee',
                    fillColor: '#4361ee',
                    fillOpacity: 0.1,
                    radius: <?= $radius * 1000 ?> // Convert km to meters
                }).addTo(map);
            }
            
            // Add item markers
            mapItems.forEach(item => {
                const marker = L.marker([item.lat, item.lng], {
                    icon: L.divIcon({
                        className: 'item-marker',
                        html: `
                            <div style="background: ${item.reward > 0 ? '#06d6a0' : (item.status === 'lost' ? '#ffd166' : '#ef476f')}; 
                                 width: 16px; height: 16px; border-radius: 50%; 
                                 border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);
                                 cursor: pointer;"></div>`,
                        iconSize: [16, 16],
                        iconAnchor: [8, 8]
                    })
                });
                
                const popupContent = `
                    <div style="max-width: 250px;">
                        <h4 style="margin: 0 0 10px 0; color: #333;">${escapeHtml(item.name)}</h4>
                        <p style="margin: 0 0 5px 0; color: #666;">
                            <i class="fas fa-map-marker-alt"></i> ${escapeHtml(item.location || 'Unknown')}
                        </p>
                        <p style="margin: 0 0 5px 0; color: #666;">
                            <i class="fas fa-calendar"></i> ${escapeHtml(item.date)}
                        </p>
                        <p style="margin: 0 0 5px 0; color: #666;">
                            <i class="fas fa-tag"></i> ${escapeHtml(item.category || 'General')}
                        </p>
                        ${item.reward > 0 ? 
                            `<p style="margin: 0 0 10px 0; color: #06d6a0; font-weight: bold;">
                                <i class="fas fa-award"></i> Reward: $${item.reward.toFixed(2)}
                            </p>` : ''
                        }
                        <div style="display: flex; gap: 5px;">
                            <a href="item_detail.php?id=${item.id}" 
                               style="background: #4361ee; color: white; padding: 5px 10px; 
                                      border-radius: 5px; text-decoration: none; font-size: 12px;">
                                View Details
                            </a>
                            ${item.reward > 0 ? 
                                `<a href="claim_reward.php?item=${item.id}" 
                                   style="background: #06d6a0; color: white; padding: 5px 10px; 
                                          border-radius: 5px; text-decoration: none; font-size: 12px;">
                                    Claim Reward
                                </a>` : ''
                            }
                        </div>
                    </div>
                `;
                
                marker.bindPopup(popupContent);
                marker.on('click', function() {
                    highlightItem(item.id);
                });
                
                markers.addLayer(marker);
            });
            
            map.addLayer(markers);
            
            // Add click handler for map
            map.on('click', function(e) {
                setSearchLocation(e.latlng.lat, e.latlng.lng);
            });
            
            // Auto-complete for address input
            initAutocomplete();
            
            // Show loading indicator during geolocation
            document.getElementById('zoomToUserBtn').addEventListener('click', function() {
                this.innerHTML = '<span class="spinner"></span> Getting location...';
            });
        });
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initialize address autocomplete
        function initAutocomplete() {
            const addressInput = document.getElementById('addressInput');
            
            // Simple debounce function
            let debounceTimer;
            addressInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    if (this.value.length > 2) {
                        searchAddress(this.value);
                    }
                }, 300);
            });
        }
        
        // Search address using Nominatim (OpenStreetMap)
        function searchAddress(query) {
            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=5`)
                .then(response => response.json())
                .then(data => {
                    if (data.length > 0) {
                        // For now, just use the first result
                        const result = data[0];
                        setSearchLocation(parseFloat(result.lat), parseFloat(result.lon));
                        
                        // Update address input with formatted address
                        document.getElementById('addressInput').value = result.display_name;
                    }
                })
                .catch(error => console.error('Error searching address:', error));
        }
        
        // Set search location from map click or geolocation
        function setSearchLocation(lat, lng) {
            document.getElementById('latInput').value = lat;
            document.getElementById('lngInput').value = lng;
            
            // Remove existing search marker
            if (searchMarker) {
                map.removeLayer(searchMarker);
            }
            
            // Add new search marker
            searchMarker = L.marker([lat, lng], {
                icon: L.divIcon({
                    className: 'search-location-marker',
                    html: '<div style="background: #7209b7; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>',
                    iconSize: [24, 24],
                    iconAnchor: [12, 12]
                })
            }).addTo(map);
            
            searchMarker.bindPopup('<b>Search Location</b><br>Click "Search Items" to find items near this location').openPopup();
            
            // Pan to location
            map.panTo([lat, lng]);
            map.setZoom(14);
            
            // Show radius selector
            const radiusSelect = document.getElementById('radiusSelect');
            if (radiusSelect) {
                radiusSelect.parentElement.style.display = 'block';
            } else {
                // Create radius selector if it doesn't exist
                const formGroup = document.createElement('div');
                formGroup.className = 'form-group';
                formGroup.innerHTML = `
                    <label class="form-label">Search Radius</label>
                    <select class="form-control" name="radius" id="radiusSelect">
                        <option value="5">5 km</option>
                        <option value="10" selected>10 km</option>
                        <option value="25">25 km</option>
                        <option value="50">50 km</option>
                        <option value="100">100 km</option>
                    </select>
                `;
                
                const sidebarSection = document.querySelector('.sidebar-section');
                const firstFormGroup = sidebarSection.querySelector('.form-group');
                sidebarSection.insertBefore(formGroup, firstFormGroup.nextSibling);
            }
        }
        
        // Get user's current location
        function getUserLocation() {
            const zoomBtn = document.getElementById('zoomToUserBtn');
            zoomBtn.innerHTML = '<span class="spinner"></span> Getting location...';
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        
                        // Save location to session
                        fetch('save_location.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ lat: lat, lng: lng })
                        });
                        
                        setSearchLocation(lat, lng);
                        zoomBtn.innerHTML = '<i class="fas fa-location-arrow"></i> My Location';
                    },
                    function(error) {
                        alert('Unable to get your location. Please enable location services or enter an address manually.');
                        zoomBtn.innerHTML = '<i class="fas fa-location-arrow"></i> My Location';
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 5000,
                        maximumAge: 0
                    }
                );
            } else {
                alert('Geolocation is not supported by your browser.');
                zoomBtn.innerHTML = '<i class="fas fa-location-arrow"></i> My Location';
            }
        }
        
        // Zoom to user location
        function zoomToUser() {
            if (userLocation && userLocation.lat && userLocation.lng) {
                map.setView([userLocation.lat, userLocation.lng], 14);
            } else {
                getUserLocation();
            }
        }
        
        // Zoom to show all markers
        function zoomToAll() {
            if (markers.getLayers().length > 0) {
                map.fitBounds(markers.getBounds().pad(0.1));
            } else if (userLocation && userLocation.lat && userLocation.lng) {
                map.setView([userLocation.lat, userLocation.lng], 12);
            }
        }
        
        // Highlight item on map and in list
        function highlightItem(itemId) {
            // Remove previous highlights
            document.querySelectorAll('.item-card.selected').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Highlight clicked card
            const card = document.querySelector(`.item-card[data-id="${itemId}"]`);
            if (card) {
                card.classList.add('selected');
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            
            // Find and open marker popup
            markers.getLayers().forEach(layer => {
                if (layer.options.itemId === itemId) {
                    layer.openPopup();
                    map.setView(layer.getLatLng(), 15);
                }
            });
        }
        
        // Search by location name
        function searchLocation(locationName) {
            document.getElementById('addressInput').value = locationName;
            document.getElementById('searchForm').submit();
        }
        
        // Reset search form
        function resetSearch() {
            window.location.href = 'location_search.php';
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