<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// satellite_view.php - High-resolution satellite imagery location tracking
session_start();
require_once('../config/db.php');

if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Initialize satellite preferences
initSatellitePreferences($conn, $user_id);

// Get parameters
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$zoom = isset($_GET['zoom']) ? intval($_GET['zoom']) : 15;
$source = $_GET['source'] ?? 'google';
$date = $_GET['date'] ?? date('Y-m-d');

// Get user's satellite preferences
$preferences = getSatellitePreferences($conn, $user_id);

// Get user's saved satellite locations
$saved_locations = getSatelliteLocations($conn, $user_id);

// Get available satellite sources
$satellite_sources = [
    'google' => ['name' => 'Google Maps', 'max_zoom' => 20, 'has_street' => true],
    'bing' => ['name' => 'Bing Maps', 'max_zoom' => 19, 'has_street' => true],
    'mapbox' => ['name' => 'Mapbox Satellite', 'max_zoom' => 22, 'has_street' => false],
    'esri' => ['name' => 'ESRI World Imagery', 'max_zoom' => 19, 'has_street' => false],
    'nasa' => ['name' => 'NASA Worldview', 'max_zoom' => 10, 'has_street' => false],
    'custom' => ['name' => 'Custom Source', 'max_zoom' => 20, 'has_street' => false]
];

// Get cached imagery if available
$cached_imagery = null;
if($lat && $lng) {
    $cached_imagery = getCachedImagery($conn, $lat, $lng, $zoom, $source, $date);
}

// Handle form submissions
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(isset($_POST['save_location'])) {
        saveSatelliteLocation($conn, $user_id);
    } elseif(isset($_POST['update_preferences'])) {
        updateSatellitePreferences($conn, $user_id);
    } elseif(isset($_POST['analyze_location'])) {
        analyzeLocation($conn, $user_id);
    } elseif(isset($_POST['capture_screenshot'])) {
        captureSatelliteScreenshot($conn, $user_id);
    }
}

function initSatellitePreferences($conn, $user_id) {
    $check_stmt = $conn->prepare("SELECT id FROM satellite_preferences WHERE user_id = ?");
    $check_stmt->bind_param("i", $user_id);
    $check_stmt->execute();
    
    if($check_stmt->get_result()->num_rows === 0) {
        $default_analysis = json_encode(['land_use', 'building_detection', 'vegetation_analysis']);
        $insert_stmt = $conn->prepare("INSERT INTO satellite_preferences (user_id, analysis_types) VALUES (?, ?)");
        $insert_stmt->bind_param("is", $user_id, $default_analysis);
        $insert_stmt->execute();
    }
}

function getSatellitePreferences($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM satellite_preferences WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $prefs = $result->fetch_assoc();
    
    if($prefs) {
        $prefs['analysis_types'] = json_decode($prefs['analysis_types'] ?? '[]', true);
    }
    
    return $prefs;
}

function getSatelliteLocations($conn, $user_id) {
    $locations = [];
    $stmt = $conn->prepare("SELECT * FROM satellite_locations WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $locations = $result->fetch_all(MYSQLI_ASSOC);
    return $locations;
}

function getCachedImagery($conn, $lat, $lng, $zoom, $source, $date) {
    $stmt = $conn->prepare("SELECT * FROM satellite_imagery_cache WHERE latitude = ? AND longitude = ? AND zoom_level = ? AND satellite_source = ? AND image_date = ?");
    $stmt->bind_param("ddiss", $lat, $lng, $zoom, $source, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Satellite View - Vigilo High-Resolution Location Tracking</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.fullscreen@2.0.0/Control.FullScreen.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet-minimap@3.6.1/dist/Control.MiniMap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet-measure@3.1.0/dist/leaflet-measure.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.locatecontrol@0.79.0/dist/L.Control.Locate.min.css" />
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
            --satellite-bg: #0a1929;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--satellite-bg);
            color: white;
            height: 100vh;
            overflow: hidden;
        }

        /* Satellite Interface */
        .satellite-interface {
            display: flex;
            height: 100vh;
            position: relative;
        }

        /* Left Sidebar */
        .satellite-sidebar {
            width: 400px;
            background: rgba(10, 25, 41, 0.95);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        @media (max-width: 1200px) {
            .satellite-sidebar {
                position: fixed;
                left: -400px;
                top: 0;
                bottom: 0;
                z-index: 2000;
            }
            
            .satellite-sidebar.active {
                left: 0;
            }
        }

        /* Sidebar Header */
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.3);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .logo-icon {
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

        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #00ffaa, #00aaff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .current-coordinates {
            font-family: 'Monaco', monospace;
            font-size: 0.9rem;
            color: #00ffaa;
            background: rgba(0, 0, 0, 0.5);
            padding: 10px;
            border-radius: 5px;
            border: 1px solid rgba(0, 255, 170, 0.3);
        }

        /* Sidebar Content */
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }

        .sidebar-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #00ffaa;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Satellite Sources */
        .source-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .source-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .source-card:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .source-card.active {
            background: rgba(67, 97, 238, 0.2);
            border-color: var(--primary);
            box-shadow: 0 0 20px rgba(67, 97, 238, 0.3);
        }

        .source-icon {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #00ffaa;
        }

        .source-name {
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Date Slider */
        .date-slider-container {
            margin-top: 20px;
        }

        .date-slider {
            width: 100%;
            height: 6px;
            -webkit-appearance: none;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            outline: none;
            margin: 15px 0;
        }

        .date-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: 3px solid white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        }

        .date-display {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.7);
        }

        /* Controls */
        .controls-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .control-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 12px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .control-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary);
        }

        .control-btn i {
            font-size: 1.2rem;
            color: #00ffaa;
        }

        .control-label {
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Saved Locations */
        .locations-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .location-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .location-item:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary);
        }

        .location-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: white;
        }

        .location-coords {
            font-family: 'Monaco', monospace;
            font-size: 0.8rem;
            color: #00ffaa;
            margin-bottom: 5px;
        }

        .location-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.6);
        }

        /* Main Map Container */
        .map-container {
            flex: 1;
            position: relative;
        }

        #satelliteMap {
            width: 100%;
            height: 100%;
            background: var(--satellite-bg);
        }

        /* Map Controls Overlay */
        .map-controls-overlay {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .map-control-btn {
            width: 50px;
            height: 50px;
            background: rgba(10, 25, 41, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .map-control-btn:hover {
            background: rgba(67, 97, 238, 0.9);
            border-color: var(--primary);
            transform: scale(1.1);
        }

        .map-control-btn.active {
            background: var(--primary);
            border-color: white;
            box-shadow: 0 0 20px rgba(67, 97, 238, 0.5);
        }

        /* Zoom Controls */
        .zoom-controls {
            position: absolute;
            right: 20px;
            bottom: 100px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .zoom-btn {
            width: 50px;
            height: 50px;
            background: rgba(10, 25, 41, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .zoom-btn:hover {
            background: rgba(67, 97, 238, 0.9);
            border-color: var(--primary);
        }

        /* Coordinates Display */
        .coordinates-display {
            position: absolute;
            bottom: 20px;
            left: 20px;
            z-index: 1000;
            background: rgba(10, 25, 41, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            backdrop-filter: blur(10px);
            min-width: 300px;
        }

        .coord-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-family: 'Monaco', monospace;
        }

        .coord-label {
            color: rgba(255, 255, 255, 0.7);
        }

        .coord-value {
            color: #00ffaa;
            font-weight: 500;
        }

        /* Analysis Panel */
        .analysis-panel {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 1000;
            background: rgba(10, 25, 41, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 20px;
            width: 350px;
            backdrop-filter: blur(10px);
            display: none;
        }

        .analysis-panel.active {
            display: block;
        }

        .analysis-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .analysis-title {
            color: #00ffaa;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .analysis-close {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.2rem;
            opacity: 0.7;
        }

        .analysis-close:hover {
            opacity: 1;
        }

        .analysis-metrics {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .metric-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #00ffaa;
            margin-bottom: 5px;
        }

        .metric-label {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.7);
        }

        /* Capture Preview */
        .capture-preview {
            position: absolute;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            background: rgba(10, 25, 41, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            width: 300px;
            backdrop-filter: blur(10px);
            display: none;
        }

        .capture-preview.active {
            display: block;
        }

        .capture-image {
            width: 100%;
            height: 150px;
            background: #000;
            border-radius: 5px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .capture-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Mobile Toggle */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            width: 50px;
            height: 50px;
            background: rgba(10, 25, 41, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }

        @media (max-width: 1200px) {
            .mobile-toggle {
                display: flex;
            }
            
            .coordinates-display {
                left: 80px;
                right: 20px;
                min-width: auto;
            }
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(10, 25, 41, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(10px);
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-spinner {
            width: 80px;
            height: 80px;
            border: 5px solid rgba(255, 255, 255, 0.1);
            border-top-color: #00ffaa;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            background: rgba(10, 25, 41, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            min-width: 300px;
            backdrop-filter: blur(10px);
            animation: slideIn 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .toast.success {
            border-left-color: var(--success);
        }

        .toast.error {
            border-left-color: var(--danger);
        }

        .toast.warning {
            border-left-color: var(--warning);
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Custom Leaflet Styles */
        .leaflet-control-zoom {
            border: none !important;
            background: transparent !important;
        }

        .leaflet-control-zoom a {
            background: rgba(10, 25, 41, 0.9) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: white !important;
            backdrop-filter: blur(10px);
        }

        .leaflet-control-zoom a:hover {
            background: rgba(67, 97, 238, 0.9) !important;
        }

        .leaflet-control-attribution {
            background: rgba(10, 25, 41, 0.8) !important;
            color: rgba(255, 255, 255, 0.7) !important;
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle -->
    <div class="mobile-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </div>

    <div class="satellite-interface">
        <!-- Left Sidebar -->
        <aside class="satellite-sidebar" id="sidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-satellite"></i>
                    </div>
                    <span class="logo-text">Vigilo Satellite</span>
                </div>
                <div class="current-coordinates" id="currentCoords">
                    Lat: 0.0000°, Lng: 0.0000°
                </div>
            </div>

            <!-- Sidebar Content -->
            <div class="sidebar-content">
                <!-- Satellite Sources -->
                <div class="sidebar-section">
                    <h3 class="section-title">
                        <i class="fas fa-satellite-dish"></i> Satellite Sources
                    </h3>
                    <div class="source-grid" id="sourceGrid">
                        <?php foreach($satellite_sources as $key => $source): ?>
                        <div class="source-card <?= $key === $source ? 'active' : '' ?>" 
                             onclick="changeSatelliteSource('<?= $key ?>')"
                             data-source="<?= $key ?>"
                             data-max-zoom="<?= $source['max_zoom'] ?>">
                            <div class="source-icon">
                                <?php switch($key):
                                    case 'google': ?>
                                        <i class="fab fa-google"></i>
                                    <?php break; ?>
                                    <?php case 'bing': ?>
                                        <i class="fab fa-microsoft"></i>
                                    <?php break; ?>
                                    <?php case 'mapbox': ?>
                                        <i class="fas fa-map"></i>
                                    <?php break; ?>
                                    <?php case 'esri': ?>
                                        <i class="fas fa-globe-americas"></i>
                                    <?php break; ?>
                                    <?php case 'nasa': ?>
                                        <i class="fas fa-rocket"></i>
                                    <?php break; ?>
                                    <?php default: ?>
                                        <i class="fas fa-cog"></i>
                                <?php endswitch; ?>
                            </div>
                            <div class="source-name"><?= $source['name'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Date Selection -->
                <div class="sidebar-section">
                    <h3 class="section-title">
                        <i class="fas fa-calendar-alt"></i> Imagery Date
                    </h3>
                    <div class="date-slider-container">
                        <input type="range" min="1" max="365" value="1" class="date-slider" 
                               id="dateSlider" oninput="updateDateDisplay(this.value)">
                        <div class="date-display">
                            <span id="minDate"><?= date('Y-m-d', strtotime('-365 days')) ?></span>
                            <span id="currentDate"><?= date('Y-m-d') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Map Controls -->
                <div class="sidebar-section">
                    <h3 class="section-title">
                        <i class="fas fa-sliders-h"></i> Map Controls
                    </h3>
                    <div class="controls-grid">
                        <div class="control-btn" onclick="toggleLabels()" id="labelsBtn">
                            <i class="fas fa-tag"></i>
                            <span class="control-label">Labels</span>
                        </div>
                        <div class="control-btn" onclick="toggle3D()" id="3dBtn">
                            <i class="fas fa-cube"></i>
                            <span class="control-label">3D View</span>
                        </div>
                        <div class="control-btn" onclick="toggleTerrain()" id="terrainBtn">
                            <i class="fas fa-mountain"></i>
                            <span class="control-label">Terrain</span>
                        </div>
                        <div class="control-btn" onclick="toggleWeather()" id="weatherBtn">
                            <i class="fas fa-cloud"></i>
                            <span class="control-label">Weather</span>
                        </div>
                        <div class="control-btn" onclick="measureDistance()" id="measureBtn">
                            <i class="fas fa-ruler"></i>
                            <span class="control-label">Measure</span>
                        </div>
                        <div class="control-btn" onclick="analyzeArea()" id="analyzeBtn">
                            <i class="fas fa-chart-bar"></i>
                            <span class="control-label">Analyze</span>
                        </div>
                        <div class="control-btn" onclick="captureScreenshot()" id="captureBtn">
                            <i class="fas fa-camera"></i>
                            <span class="control-label">Capture</span>
                        </div>
                        <div class="control-btn" onclick="saveCurrentView()" id="saveBtn">
                            <i class="fas fa-save"></i>
                            <span class="control-label">Save</span>
                        </div>
                    </div>
                </div>

                <!-- Saved Locations -->
                <div class="sidebar-section">
                    <h3 class="section-title">
                        <i class="fas fa-bookmark"></i> Saved Locations
                    </h3>
                    <div class="locations-list" id="locationsList">
                        <?php foreach($saved_locations as $location): ?>
                        <div class="location-item" onclick="flyToLocation(<?= $location['latitude'] ?>, <?= $location['longitude'] ?>)">
                            <div class="location-name"><?= htmlspecialchars($location['location_name']) ?></div>
                            <div class="location-coords">
                                <?= number_format($location['latitude'], 6) ?>, <?= number_format($location['longitude'], 6) ?>
                            </div>
                            <div class="location-meta">
                                <span><?= ucfirst($location['location_type']) ?></span>
                                <span><?= date('M d, Y', strtotime($location['created_at'])) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Map Container -->
        <div class="map-container">
            <div id="satelliteMap"></div>

            <!-- Map Controls Overlay -->
            <div class="map-controls-overlay">
                <div class="map-control-btn" onclick="toggleFullscreen()" title="Fullscreen">
                    <i class="fas fa-expand"></i>
                </div>
                <div class="map-control-btn" onclick="locateUser()" title="My Location">
                    <i class="fas fa-location-arrow"></i>
                </div>
                <div class="map-control-btn" onclick="toggleMiniMap()" title="Mini Map">
                    <i class="fas fa-map"></i>
                </div>
                <div class="map-control-btn" onclick="resetView()" title="Reset View">
                    <i class="fas fa-home"></i>
                </div>
            </div>

            <!-- Zoom Controls -->
            <div class="zoom-controls">
                <div class="zoom-btn" onclick="zoomIn()" title="Zoom In">
                    <i class="fas fa-plus"></i>
                </div>
                <div class="zoom-btn" onclick="zoomOut()" title="Zoom Out">
                    <i class="fas fa-minus"></i>
                </div>
            </div>

            <!-- Coordinates Display -->
            <div class="coordinates-display">
                <div class="coord-row">
                    <span class="coord-label">Latitude:</span>
                    <span class="coord-value" id="displayLat">0.0000°</span>
                </div>
                <div class="coord-row">
                    <span class="coord-label">Longitude:</span>
                    <span class="coord-value" id="displayLng">0.0000°</span>
                </div>
                <div class="coord-row">
                    <span class="coord-label">Zoom:</span>
                    <span class="coord-value" id="displayZoom">15</span>
                </div>
                <div class="coord-row">
                    <span class="coord-label">Altitude:</span>
                    <span class="coord-value" id="displayAlt">0 m</span>
                </div>
                <div class="coord-row">
                    <span class="coord-label">Accuracy:</span>
                    <span class="coord-value" id="displayAcc">±0 m</span>
                </div>
            </div>

            <!-- Analysis Panel -->
            <div class="analysis-panel" id="analysisPanel">
                <div class="analysis-header">
                    <div class="analysis-title">Area Analysis</div>
                    <button class="analysis-close" onclick="closeAnalysis()">&times;</button>
                </div>
                <div class="analysis-metrics" id="analysisMetrics">
                    <!-- Will be populated by JavaScript -->
                </div>
                <div id="analysisDetails"></div>
            </div>

            <!-- Capture Preview -->
            <div class="capture-preview" id="capturePreview">
                <div class="capture-image" id="captureImage">
                    <!-- Screenshot will appear here -->
                </div>
                <div style="text-align: center;">
                    <button onclick="saveScreenshot()" class="btn" style="background: var(--primary); color: white; padding: 8px 16px; border-radius: 5px; border: none; cursor: pointer;">
                        <i class="fas fa-save"></i> Save Screenshot
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- JavaScript Libraries -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet.fullscreen@2.0.0/Control.FullScreen.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet-minimap@3.6.1/dist/Control.MiniMap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet-measure@3.1.0/dist/leaflet-measure.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet.locatecontrol@0.79.0/dist/L.Control.Locate.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    
    <!-- Enhanced Satellite Tile Providers -->
    <script>
        // Satellite tile providers configuration
        const satelliteProviders = {
            google: {
                name: 'Google Satellite',
                url: 'https://mt1.google.com/vt/lyrs=s&x={x}&y={y}&z={z}',
                attribution: 'Google',
                maxZoom: 20
            },
            bing: {
                name: 'Bing Satellite',
                url: 'https://t0.ssl.ak.dynamic.tiles.virtualearth.net/comp/ch/{quadkey}?mkt=en-US&it=G,L&shading=hill&og=1884&n=z',
                attribution: 'Bing',
                maxZoom: 19
            },
            mapbox: {
                name: 'Mapbox Satellite',
                url: 'https://api.mapbox.com/styles/v1/mapbox/satellite-v9/tiles/{z}/{x}/{y}?access_token=pk.eyJ1IjoibWFwYm94IiwiYSI6ImNpejY4NXVycTA2emYycXBndHRqcmZ3N3gifQ.rJcFIG214AriISLbB6B5aw',
                attribution: 'Mapbox',
                maxZoom: 22
            },
            esri: {
                name: 'ESRI World Imagery',
                url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                attribution: 'ESRI',
                maxZoom: 19
            },
            nasa: {
                name: 'NASA Worldview',
                url: 'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/MODIS_Terra_CorrectedReflectance_TrueColor/default/{time}/{tileMatrixSet}/{z}/{y}/{x}.jpg',
                attribution: 'NASA',
                maxZoom: 10,
                time: '2023-01-01' // You can make this dynamic
            },
            custom: {
                name: 'Custom Source',
                url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', // Default to OSM
                attribution: 'OpenStreetMap',
                maxZoom: 19
            }
        };

        // Initialize variables
        let map;
        let currentSource = '<?= $source ?>';
        let currentZoom = <?= $zoom ?>;
        let currentLat = <?= $lat ?: 'null' ?>;
        let currentLng = <?= $lng ?: 'null' ?>;
        let currentLayer;
        let miniMap;
        let measureControl;
        let locateControl;
        let is3DEnabled = false;
        let isLabelsEnabled = true;
        let isTerrainEnabled = true;
        let isWeatherEnabled = false;

        // Initialize map when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            initSatelliteMap();
            initEventListeners();
            updateUI();
            
            // If we have cached imagery, load it
            <?php if($cached_imagery): ?>
            loadCachedImagery(<?= json_encode($cached_imagery) ?>);
            <?php endif; ?>
        });

        // Initialize satellite map
        function initSatelliteMap() {
            // Default coordinates (New York City)
            const defaultLat = currentLat || 40.7128;
            const defaultLng = currentLng || -74.0060;
            
            // Create map instance
            map = L.map('satelliteMap', {
                center: [defaultLat, defaultLng],
                zoom: currentZoom,
                zoomControl: false, // We'll use custom controls
                attributionControl: true,
                maxZoom: satelliteProviders[currentSource].maxZoom,
                minZoom: 1,
                worldCopyJump: true,
                fadeAnimation: true,
                markerZoomAnimation: true
            });

            // Add the selected satellite layer
            updateSatelliteLayer();

            // Add fullscreen control
            map.addControl(new L.Control.FullScreen({
                title: {
                    'false': 'View Fullscreen',
                    'true': 'Exit Fullscreen'
                }
            }));

            // Add locate control
            locateControl = L.control.locate({
                position: 'topleft',
                strings: {
                    title: "Show my location"
                },
                locateOptions: {
                    maxZoom: 16,
                    enableHighAccuracy: true
                }
            }).addTo(map);

            // Initialize measure control (but don't add yet)
            measureControl = new L.Control.Measure({
                position: 'topleft',
                primaryLengthUnit: 'meters',
                secondaryLengthUnit: 'kilometers',
                primaryAreaUnit: 'sqmeters',
                secondaryAreaUnit: 'hectares'
            });

            // Add event listeners
            map.on('moveend', updateCoordinates);
            map.on('zoomend', updateZoom);
            map.on('click', onMapClick);
            
            // Initial coordinate update
            updateCoordinates();
        }

        // Update satellite layer based on current source
        function updateSatelliteLayer() {
            if (currentLayer) {
                map.removeLayer(currentLayer);
            }

            const provider = satelliteProviders[currentSource];
            
            currentLayer = L.tileLayer(provider.url, {
                attribution: '&copy; ' + provider.attribution,
                maxZoom: provider.maxZoom,
                minZoom: 1,
                detectRetina: true,
                crossOrigin: true
            }).addTo(map);

            // Update max zoom
            map.setMaxZoom(provider.maxZoom);
            
            // Show toast notification
            showToast(`Switched to ${provider.name}`, 'success');
        }

        // Change satellite source
        function changeSatelliteSource(source) {
            if (source === currentSource) return;
            
            currentSource = source;
            
            // Update active button
            document.querySelectorAll('.source-card').forEach(card => {
                card.classList.remove('active');
            });
            document.querySelector(`.source-card[data-source="${source}"]`).classList.add('active');
            
            // Show loading
            showLoading();
            
            // Update layer
            updateSatelliteLayer();
            
            // Update URL
            updateURL();
            
            // Hide loading
            hideLoading();
        }

        // Update coordinates display
        function updateCoordinates() {
            const center = map.getCenter();
            const zoom = map.getZoom();
            const bounds = map.getBounds();
            
            // Update displays
            document.getElementById('currentCoords').textContent = 
                `Lat: ${center.lat.toFixed(4)}°, Lng: ${center.lng.toFixed(4)}°`;
            
            document.getElementById('displayLat').textContent = `${center.lat.toFixed(4)}°`;
            document.getElementById('displayLng').textContent = `${center.lng.toFixed(4)}°`;
            document.getElementById('displayZoom').textContent = zoom;
            
            // Update URL if coordinates changed significantly
            if (currentLat !== center.lat || currentLng !== center.lng) {
                currentLat = center.lat;
                currentLng = center.lng;
                updateURL();
            }
        }

        // Update zoom display
        function updateZoom() {
            const zoom = map.getZoom();
            document.getElementById('displayZoom').textContent = zoom;
            currentZoom = zoom;
            updateURL();
        }

        // Update URL parameters
        function updateURL() {
            const params = new URLSearchParams();
            params.set('lat', currentLat.toFixed(6));
            params.set('lng', currentLng.toFixed(6));
            params.set('zoom', currentZoom);
            params.set('source', currentSource);
            
            const newURL = window.location.pathname + '?' + params.toString();
            window.history.replaceState({}, '', newURL);
        }

        // Map click handler
        function onMapClick(e) {
            const { lat, lng } = e.latlng;
            
            // Create marker
            const marker = L.marker([lat, lng], {
                icon: L.divIcon({
                    className: 'satellite-marker',
                    html: '<div style="background: #00ffaa; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 20px rgba(0, 255, 170, 0.5);"></div>',
                    iconSize: [24, 24],
                    iconAnchor: [12, 12]
                })
            }).addTo(map);
            
            // Create popup with location info
            const popupContent = `
                <div style="max-width: 250px;">
                    <h4 style="margin: 0 0 10px 0; color: #00ffaa;">Location Details</h4>
                    <p style="margin: 0 0 5px 0; color: white;">
                        <strong>Coordinates:</strong><br>
                        ${lat.toFixed(6)}°, ${lng.toFixed(6)}°
                    </p>
                    <div style="margin-top: 15px; display: flex; gap: 5px;">
                        <button onclick="saveLocation(${lat}, ${lng})" 
                                style="background: #4361ee; color: white; padding: 5px 10px; border-radius: 5px; border: none; cursor: pointer;">
                            Save Location
                        </button>
                        <button onclick="analyzePoint(${lat}, ${lng})" 
                                style="background: #06d6a0; color: white; padding: 5px 10px; border-radius: 5px; border: none; cursor: pointer;">
                            Analyze
                        </button>
                    </div>
                </div>
            `;
            
            marker.bindPopup(popupContent).openPopup();
            
            // Auto-remove marker after 30 seconds
            setTimeout(() => {
                if (map.hasLayer(marker)) {
                    map.removeLayer(marker);
                }
            }, 30000);
        }

        // Save current location
        function saveLocation(lat, lng) {
            const name = prompt('Enter a name for this location:', 'My Location');
            if (!name) return;
            
            showLoading();
            
            // In a real implementation, this would be an AJAX call
            setTimeout(() => {
                showToast(`Location "${name}" saved successfully!`, 'success');
                hideLoading();
                
                // Add to saved locations list
                const locationList = document.getElementById('locationsList');
                const locationItem = document.createElement('div');
                locationItem.className = 'location-item';
                locationItem.innerHTML = `
                    <div class="location-name">${name}</div>
                    <div class="location-coords">${lat.toFixed(6)}, ${lng.toFixed(6)}</div>
                    <div class="location-meta">
                        <span>Custom</span>
                        <span>Just now</span>
                    </div>
                `;
                locationItem.onclick = () => flyToLocation(lat, lng);
                locationList.prepend(locationItem);
            }, 1000);
        }

        // Fly to saved location
        function flyToLocation(lat, lng) {
            map.flyTo([lat, lng], 18, {
                duration: 2,
                easeLinearity: 0.25
            });
        }

        // Toggle map controls
        function toggleLabels() {
            isLabelsEnabled = !isLabelsEnabled;
            const btn = document.getElementById('labelsBtn');
            btn.style.background = isLabelsEnabled ? 'rgba(67, 97, 238, 0.2)' : '';
            btn.style.borderColor = isLabelsEnabled ? 'var(--primary)' : '';
            showToast(isLabelsEnabled ? 'Labels enabled' : 'Labels disabled', 'info');
        }

        function toggle3D() {
            is3DEnabled = !is3DEnabled;
            const btn = document.getElementById('3dBtn');
            btn.style.background = is3DEnabled ? 'rgba(67, 97, 238, 0.2)' : '';
            btn.style.borderColor = is3DEnabled ? 'var(--primary)' : '';
            
            // In a real implementation, this would enable 3D view
            showToast(is3DEnabled ? '3D view enabled' : '3D view disabled', 'info');
        }

        function toggleTerrain() {
            isTerrainEnabled = !isTerrainEnabled;
            const btn = document.getElementById('terrainBtn');
            btn.style.background = isTerrainEnabled ? 'rgba(67, 97, 238, 0.2)' : '';
            btn.style.borderColor = isTerrainEnabled ? 'var(--primary)' : '';
            
            // In a real implementation, this would toggle terrain layer
            showToast(isTerrainEnabled ? 'Terrain enabled' : 'Terrain disabled', 'info');
        }

        function toggleWeather() {
            isWeatherEnabled = !isWeatherEnabled;
            const btn = document.getElementById('weatherBtn');
            btn.style.background = isWeatherEnabled ? 'rgba(67, 97, 238, 0.2)' : '';
            btn.style.borderColor = isWeatherEnabled ? 'var(--primary)' : '';
            
            // In a real implementation, this would toggle weather overlay
            showToast(isWeatherEnabled ? 'Weather overlay enabled' : 'Weather overlay disabled', 'info');
        }

        // Measure distance
        function measureDistance() {
            if (map.hasControl(measureControl)) {
                map.removeControl(measureControl);
                document.getElementById('measureBtn').classList.remove('active');
            } else {
                map.addControl(measureControl);
                document.getElementById('measureBtn').classList.add('active');
                showToast('Click on map to start measuring', 'info');
            }
        }

        // Analyze area
        function analyzeArea() {
            const panel = document.getElementById('analysisPanel');
            panel.classList.toggle('active');
            
            if (panel.classList.contains('active')) {
                // Simulate analysis
                const metrics = document.getElementById('analysisMetrics');
                metrics.innerHTML = `
                    <div class="metric-card">
                        <div class="metric-value">85%</div>
                        <div class="metric-label">Urban Area</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">42</div>
                        <div class="metric-label">Buildings</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">15%</div>
                        <div class="metric-label">Vegetation</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">2.3km</div>
                        <div class="metric-label">Roads</div>
                    </div>
                `;
                
                document.getElementById('analysisDetails').innerHTML = `
                    <div style="color: rgba(255, 255, 255, 0.8); font-size: 0.9rem; margin-top: 15px;">
                        <p><strong>Analysis Summary:</strong></p>
                        <p>This area appears to be primarily urban with moderate building density. 
                        Vegetation is limited to scattered trees and small green spaces.</p>
                    </div>
                `;
            }
        }

        // Close analysis panel
        function closeAnalysis() {
            document.getElementById('analysisPanel').classList.remove('active');
        }

        // Analyze specific point
        function analyzePoint(lat, lng) {
            // Show loading
            showLoading();
            
            // Simulate API call
            setTimeout(() => {
                showToast(`Analysis complete for ${lat.toFixed(4)}°, ${lng.toFixed(4)}°`, 'success');
                
                // Open analysis panel with results
                analyzeArea();
                
                hideLoading();
            }, 1500);
        }

        // Capture screenshot
        function captureScreenshot() {
            showLoading();
            
            html2canvas(document.getElementById('satelliteMap'), {
                useCORS: true,
                allowTaint: true,
                backgroundColor: null,
                scale: 0.8,
                logging: false
            }).then(canvas => {
                const preview = document.getElementById('capturePreview');
                const imageDiv = document.getElementById('captureImage');
                
                imageDiv.innerHTML = '';
                imageDiv.appendChild(canvas);
                
                preview.classList.add('active');
                hideLoading();
                showToast('Screenshot captured!', 'success');
            }).catch(error => {
                hideLoading();
                showToast('Failed to capture screenshot', 'error');
                console.error('Screenshot error:', error);
            });
        }

        // Save screenshot
        function saveScreenshot() {
            const canvas = document.querySelector('#captureImage canvas');
            if (!canvas) return;
            
            const link = document.createElement('a');
            link.download = `satellite-view-${Date.now()}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
            
            showToast('Screenshot saved!', 'success');
        }

        // Save current view
        function saveCurrentView() {
            const center = map.getCenter();
            saveLocation(center.lat, center.lng);
        }

        // Map control functions
        function toggleFullscreen() {
            const elem = document.documentElement;
            if (!document.fullscreenElement) {
                elem.requestFullscreen().catch(err => {
                    showToast(`Error entering fullscreen: ${err.message}`, 'error');
                });
            } else {
                document.exitFullscreen();
            }
        }

        function locateUser() {
            locateControl.start();
        }

        function toggleMiniMap() {
            if (miniMap) {
                map.removeControl(miniMap);
                miniMap = null;
            } else {
                miniMap = new L.Control.MiniMap(L.tileLayer(satelliteProviders[currentSource].url), {
                    toggleDisplay: true,
                    position: 'bottomright',
                    width: 150,
                    height: 150,
                    zoomLevelOffset: -5
                }).addTo(map);
            }
        }

        function resetView() {
            map.setView([40.7128, -74.0060], 12, {
                animate: true,
                duration: 1
            });
        }

        function zoomIn() {
            map.zoomIn();
        }

        function zoomOut() {
            map.zoomOut();
        }

        // UI update functions
        function updateUI() {
            // Set active source button
            document.querySelector(`.source-card[data-source="${currentSource}"]`).classList.add('active');
            
            // Update date slider
            updateDateSlider();
        }

        function updateDateSlider() {
            const slider = document.getElementById('dateSlider');
            const currentDate = document.getElementById('currentDate');
            const minDate = document.getElementById('minDate');
            
            // Set dates
            const today = new Date();
            const minDateObj = new Date();
            minDateObj.setDate(today.getDate() - 365);
            
            currentDate.textContent = formatDate(today);
            minDate.textContent = formatDate(minDateObj);
        }

        function updateDateDisplay(daysAgo) {
            const date = new Date();
            date.setDate(date.getDate() - daysAgo);
            document.getElementById('currentDate').textContent = formatDate(date);
            
            // In a real implementation, this would update the satellite imagery date
            showToast(`Showing imagery from ${formatDate(date)}`, 'info');
        }

        function formatDate(date) {
            return date.toISOString().split('T')[0];
        }

        // Toggle sidebar on mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
        }

        // Show/hide loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').classList.add('active');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('active');
        }

        // Toast notifications
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            
            container.appendChild(toast);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }, 5000);
        }

        // Initialize event listeners
        function initEventListeners() {
            // Fullscreen change
            document.addEventListener('fullscreenchange', updateFullscreenButton);
            
            // Resize map on window resize
            window.addEventListener('resize', () => {
                setTimeout(() => map.invalidateSize(), 100);
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                // Space bar to capture
                if (e.code === 'Space' && !e.target.matches('input, textarea')) {
                    e.preventDefault();
                    captureScreenshot();
                }
                
                // Escape to close panels
                if (e.code === 'Escape') {
                    closeAnalysis();
                    document.getElementById('capturePreview').classList.remove('active');
                }
                
                // +/- for zoom
                if (e.code === 'Equal' && e.ctrlKey) { // Ctrl+=
                    e.preventDefault();
                    zoomIn();
                }
                if (e.code === 'Minus' && e.ctrlKey) { // Ctrl+-
                    e.preventDefault();
                    zoomOut();
                }
            });
        }

        function updateFullscreenButton() {
            const btn = document.querySelector('.map-control-btn:nth-child(1)');
            const icon = btn.querySelector('i');
            if (document.fullscreenElement) {
                icon.className = 'fas fa-compress';
                btn.title = 'Exit Fullscreen';
            } else {
                icon.className = 'fas fa-expand';
                btn.title = 'Fullscreen';
            }
        }

        // Load cached imagery (placeholder for actual implementation)
        function loadCachedImagery(cachedData) {
            // This would load cached satellite imagery
            console.log('Loading cached imagery:', cachedData);
        }

        // Initialize with user's last known location if available
        function initUserLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const { latitude, longitude, accuracy, altitude } = position.coords;
                        
                        // Update displays
                        document.getElementById('displayAlt').textContent = altitude ? `${Math.round(altitude)} m` : 'N/A';
                        document.getElementById('displayAcc').textContent = `±${Math.round(accuracy)} m`;
                        
                        // Center map on user
                        map.setView([latitude, longitude], 16);
                    },
                    (error) => {
                        console.warn('Geolocation error:', error);
                        showToast('Unable to get your location', 'warning');
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 5000,
                        maximumAge: 0
                    }
                );
            }
        }

        // Call initialization
        setTimeout(initUserLocation, 1000);
    </script>
</body>
</html>

<?php
// Close database connection
if(isset($conn)) {
    $conn->close();
}
?>