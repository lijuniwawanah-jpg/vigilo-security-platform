<?php
// public_search.php - Professional Public Search for Lost Items
session_start();
require_once('../config/db.php');

// Check if tables exist, create if not
$table_check = $conn->query("SHOW TABLES LIKE 'items'");
if (!$table_check || $table_check->num_rows === 0) {
    // Redirect to setup or show message
    die("System not initialized. Please contact administrator.");
}

// Get search parameters with security filtering
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$min_reward = isset($_GET['min_reward']) ? floatval($_GET['min_reward']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Get available categories
$categories_stmt = $conn->prepare("SELECT DISTINCT category FROM items WHERE is_public = 1 AND status IN ('lost', 'stolen') AND category IS NOT NULL AND category != '' ORDER BY category");
$categories = [];
if($categories_stmt) {
    $categories_stmt->execute();
    $categories_result = $categories_stmt->get_result();
    while($row = $categories_result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    $categories_stmt->close();
}

// Get statistics for header
$stats_sql = "SELECT 
    COUNT(*) as total_items,
    COALESCE(SUM(reward_amount), 0) as total_rewards,
    COUNT(CASE WHEN reward_amount > 0 THEN 1 END) as items_with_rewards,
    MIN(reported_date) as oldest_report
FROM items 
WHERE is_public = 1 AND status IN ('lost', 'stolen')";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Build advanced search query
$sql = "SELECT 
    i.*,
    DATE_FORMAT(i.reported_date, '%M %d, %Y') as formatted_date,
    DATE_FORMAT(i.reported_date, '%Y-%m-%d') as report_date_only
FROM items i 
WHERE i.is_public = 1 AND i.status IN ('lost', 'stolen')";
$params = [];
$types = '';

if(!empty($search)) {
    $sql .= " AND (i.item_name LIKE ? OR i.brand LIKE ? OR i.model LIKE ? OR i.description LIKE ? OR i.serial_number LIKE ?)";
    $search_term = "%$search%";
    array_push($params, $search_term, $search_term, $search_term, $search_term, $search_term);
    $types .= 'sssss';
}

if(!empty($location)) {
    $sql .= " AND i.incident_location LIKE ?";
    $params[] = "%$location%";
    $types .= 's';
}

if(!empty($category)) {
    $sql .= " AND i.category = ?";
    $params[] = $category;
    $types .= 's';
}

if($min_reward > 0) {
    $sql .= " AND i.reward_amount >= ?";
    $params[] = $min_reward;
    $types .= 'd';
}

if(!empty($date_from)) {
    $sql .= " AND DATE(i.reported_date) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if(!empty($date_to)) {
    $sql .= " AND DATE(i.reported_date) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$sql .= " ORDER BY 
    CASE WHEN i.reward_amount > 0 THEN 1 ELSE 2 END,
    i.reward_amount DESC, 
    i.reported_date DESC
    LIMIT 50";

// Execute query
$items = [];
if(!empty($params)) {
    $stmt = $conn->prepare($sql);
    if($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    $result = $conn->query($sql);
    $items = $result->fetch_all(MYSQLI_ASSOC);
}

// Get recently found items for success stories
$found_items_sql = "SELECT * FROM items WHERE is_public = 1 AND status = 'found' AND found_date IS NOT NULL ORDER BY found_date DESC LIMIT 5";
$found_result = $conn->query($found_items_sql);
$found_items = $found_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Lost Items - Vigilo Lost & Found System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        .site-header {
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
            margin-bottom: 2rem;
        }

        /* Stats Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-item {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Search Section */
        .search-section {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .search-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .search-header h2 {
            color: var(--dark);
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .search-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 0;
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

        .search-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 1rem;
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
            box-shadow: 0 5px 20px rgba(67, 97, 238, 0.3);
        }

        .btn-secondary {
            background: #e9ecef;
            color: #495057;
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

        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 2rem;
        }

        /* Item Card */
        .item-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
        }

        .item-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }

        .item-image {
            height: 200px;
            width: 100%;
            overflow: hidden;
            position: relative;
            background: #f5f7fb;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .item-card:hover .item-image img {
            transform: scale(1.05);
        }

        .item-status {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            z-index: 2;
        }

        .status-lost {
            background: rgba(255, 209, 102, 0.9);
            color: #856404;
        }

        .status-stolen {
            background: rgba(239, 71, 111, 0.9);
            color: white;
        }

        .item-content {
            padding: 1.5rem;
        }

        .item-header {
            margin-bottom: 1rem;
        }

        .item-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .item-meta {
            display: flex;
            gap: 1rem;
            color: var(--gray);
            font-size: 0.9rem;
        }

        .item-description {
            color: #666;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .item-details {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
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
            padding: 0.75rem;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .item-actions {
            display: flex;
            gap: 0.5rem;
        }

        .item-actions .btn {
            flex: 1;
            padding: 0.5rem;
            font-size: 0.9rem;
            justify-content: center;
        }

        /* Success Stories */
        .success-section {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .success-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .success-header h2 {
            color: var(--dark);
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .success-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .success-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .success-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border: 2px solid rgba(6, 214, 160, 0.2);
        }

        .success-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .success-icon {
            width: 50px;
            height: 50px;
            background: var(--success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .success-content h4 {
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .success-date {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .success-message {
            color: #666;
            line-height: 1.6;
            font-style: italic;
        }

        /* Footer */
        .site-footer {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-top: 2rem;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 1.5rem;
        }

        .footer-links a {
            color: var(--gray);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--primary);
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
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .items-grid {
                grid-template-columns: 1fr;
            }
            
            .search-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-bar {
                grid-template-columns: 1fr 1fr;
            }
            
            .footer-links {
                flex-direction: column;
                gap: 1rem;
            }
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

        .user-auth {
            display: flex;
            gap: 1rem;
        }

        .user-auth .btn {
            padding: 0.5rem 1.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Navigation -->
        <nav class="main-nav">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <span class="logo-text">Vigilo</span>
            </div>
            
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-home"></i> Home</a>
                <a href="public_search.php" style="color: var(--primary);">
                    <i class="fas fa-search"></i> Find Lost Items
                </a>
                <a href="success_stories.php"><i class="fas fa-trophy"></i> Success Stories</a>

                <!-- In your existing nav-links section -->
<a href="location_search.php" class="<?= basename($_SERVER['PHP_SELF']) == 'location_search.php' ? 'active' : '' ?>">
    <i class="fas fa-map-marker-alt"></i> Location Search
</a>
            </div>
            
            <div class="user-auth">
                <?php if(isset($_SESSION['user_id'])): ?>
                    
                   
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </a>
                    <a href="register.php" class="btn btn-secondary">
                        <i class="fas fa-user-plus"></i> Register
                    </a>
                <?php endif; ?>
            </div>
        </nav>

        <!-- Site Header -->
        <header class="site-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-search"></i>
                </div>
                <span class="logo-text">Find Lost Items</span>
            </div>
            <p class="tagline">Help reunite lost items with their owners. Search our database of reported lost and stolen items.</p>
            
            <div class="stats-bar">
                <div class="stat-item">
                    <div class="stat-number"><?= $stats['total_items'] ?></div>
                    <div class="stat-label">Lost Items</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">$<?= number_format($stats['total_rewards'], 0) ?></div>
                    <div class="stat-label">Total Rewards</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $stats['items_with_rewards'] ?></div>
                    <div class="stat-label">Items With Rewards</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= !empty($stats['oldest_report']) ? date('M Y', strtotime($stats['oldest_report'])) : 'N/A' ?></div>
                    <div class="stat-label">Since</div>
                </div>
            </div>
        </header>

        <!-- Search Section -->
        <section class="search-section">
            <div class="search-header">
                <h2>Search Lost Items</h2>
                <p>Use the filters below to search for lost items in your area</p>
            </div>
            
                <div class="search-actions">
    <a href="qr_scanner.php" class="btn btn-primary">
        <i class="fas fa-search"></i> Search Items
    </a>
</div>

               
        </section>

        <!-- Results Section -->
        <section class="results-section">
            <div class="results-header">
                <h2>Search Results</h2>
                <span class="results-count"><?= count($items) ?> Items Found</span>
            </div>
            
            <?php if(!empty($items)): ?>
                <div class="items-grid">
                    <?php foreach($items as $item): 
                        // Get first photo if available
                        $item_image = 'https://via.placeholder.com/400x200?text=No+Image';
                        if(!empty($item['photos'])) {
                            $photos = json_decode($item['photos'], true);
                            if($photos && is_array($photos) && !empty($photos[0]['path'])) {
                                $item_image = '../' . $photos[0]['path'];
                            }
                        }
                    ?>
                    <div class="item-card">
                        <div class="item-image">
                            <img src="<?= htmlspecialchars($item_image) ?>" 
                                 alt="<?= htmlspecialchars($item['item_name']) ?>"
                                 onerror="this.src='https://via.placeholder.com/400x200?text=No+Image'">
                            <span class="item-status status-<?= $item['status'] ?>">
                                <?= strtoupper($item['status']) ?>
                            </span>
                        </div>
                        
                        <div class="item-content">
                            <div class="item-header">
                                <h3 class="item-name"><?= htmlspecialchars($item['item_name']) ?></h3>
                                <div class="item-meta">
                                    <span><i class="fas fa-tag"></i> <?= htmlspecialchars($item['category'] ?? 'General') ?></span>
                                    <span><i class="fas fa-calendar"></i> <?= $item['formatted_date'] ?></span>
                                </div>
                            </div>
                            
                            <p class="item-description">
                                <?= htmlspecialchars(substr($item['description'] ?? 'No description available', 0, 150)) ?>...
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
                                    <span class="detail-label">Serial:</span>
                                    <span class="detail-value"><?= !empty($item['serial_number']) ? htmlspecialchars($item['serial_number']) : 'N/A' ?></span>
                                </div>
                            </div>
                            
                            <?php if($item['reward_amount'] > 0): ?>
                            <div class="reward-badge">
                                <i class="fas fa-award"></i>
                                Reward: $<?= number_format($item['reward_amount'], 2) ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="item-actions">
                                <a href="item_detail.php?id=<?= $item['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                                <?php if($item['reward_amount'] > 0): ?>
                                <a href="claim_reward.php?item=<?= $item['id'] ?>" class="btn btn-success">
                                    <i class="fas fa-award"></i> Claim Reward
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h3>No Items Found</h3>
                    <p>Try adjusting your search criteria or browse all items.</p>
                    <?php if($search || $location || $category || $min_reward > 0): ?>
                        <button onclick="resetSearch()" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-redo"></i> Clear All Filters
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Success Stories -->
        <?php if(!empty($found_items)): ?>
        <section class="success-section">
            <div class="success-header">
                <h2>Success Stories</h2>
                <p>Items that have been successfully recovered through our system</p>
            </div>
            
            <div class="success-grid">
                <?php foreach($found_items as $found_item): ?>
                <div class="success-card">
                    <div class="success-card-header">
                        <div class="success-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="success-content">
                            <h4><?= htmlspecialchars($found_item['item_name']) ?></h4>
                            <div class="success-date">
                                Found on <?= date('M d, Y', strtotime($found_item['found_date'])) ?>
                            </div>
                        </div>
                    </div>
                    <p class="success-message">
                        "<?= !empty($found_item['found_by_name']) ? 'Found by ' . htmlspecialchars($found_item['found_by_name']) : 'Successfully recovered' ?>"
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="site-footer">
            <div class="footer-links">
                <a href="about.php">About Us</a>
                <a href="privacy.php">Privacy Policy</a>
                <a href="terms.php">Terms of Service</a>
                <a href="contact.php">Contact</a>
                <a href="help.php">Help Center</a>
            </div>
            <div class="copyright">
                &copy; <?= date('Y') ?> Vigilo Lost & Found System. All rights reserved.
            </div>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        function resetSearch() {
            window.location.href = 'public_search.php';
        }

        // Auto-submit when reward amount changes significantly
        document.querySelector('select[name="min_reward"]').addEventListener('change', function() {
            if(this.value >= 100) {
                this.form.submit();
            }
        });

        // Share functionality
        function shareItem(itemId, itemName) {
            const shareUrl = window.location.origin + '/public_search.php?item=' + itemId;
            if (navigator.share) {
                navigator.share({
                    title: 'Lost Item: ' + itemName,
                    text: 'Help find this lost item',
                    url: shareUrl
                });
            } else {
                navigator.clipboard.writeText(shareUrl);
                alert('Item link copied to clipboard! Share it to help find the item.');
            }
        }

        // Initialize tooltips
        document.querySelectorAll('.item-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
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