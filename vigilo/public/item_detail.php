<?php
// item_detail.php - Display item details from QR code scan
session_start();
require_once('../config/db.php');

$item_id = intval($_GET['id'] ?? 0);

// Get item details - Simplified query without user join
$stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) {
    die("Item not found.");
}

$item = $result->fetch_assoc();

// If we need user info, get it separately
$owner_email = '';
$owner_name = '';
if (!empty($item['user_id'])) {
    $user_stmt = $conn->prepare("SELECT email, fullname FROM users WHERE id = ?");
    if ($user_stmt) {
        $user_stmt->bind_param("i", $item['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_result->num_rows > 0) {
            $user = $user_result->fetch_assoc();
            $owner_email = $user['email'] ?? '';
            $owner_name = $user['username'] ?? '';
        }
        $user_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Details - Vigilo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #4361ee;
        }
        .item-photos {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 20px 0;
        }
        .item-photo {
            width: 200px;
            height: 200px;
            object-fit: cover;
            border-radius: 5px;
        }
        .detail-row {
            display: flex;
            margin: 10px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .detail-label {
            font-weight: bold;
            min-width: 150px;
            color: #495057;
        }
        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
        }
        .status-lost { background: #fff3cd; color: #856404; }
        .status-stolen { background: #f8d7da; color: #721c24; }
        .status-found { background: #d1ecf1; color: #0c5460; }
        .status-active { background: #d4edda; color: #155724; }
        .status-damaged { background: #e2e3e5; color: #383d41; }
        .status-sold { background: #cce5ff; color: #004085; }
        .status-archived { background: #d6d8d9; color: #1b1e21; }
        
        .report-found-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
        .qr-code {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
        }
        .qr-code img {
            max-width: 300px;
            height: auto;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #4361ee;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 5px;
            border: none;
            cursor: pointer;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-info {
            background: #17a2b8;
        }
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="item-header">
            <h1><?= htmlspecialchars($item['item_name']) ?></h1>
            <span class="status-badge status-<?= $item['status'] ?>">
                <?= strtoupper($item['status']) ?>
            </span>
        </div>
        
        <?php 
        // Display message if item is lost/stolen
        if(in_array($item['status'], ['lost', 'stolen'])): 
        ?>
            <div class="alert alert-warning">
                <h3><i class="fas fa-exclamation-triangle"></i> IMPORTANT</h3>
                <p>This item has been reported as <strong><?= $item['status'] ?></strong>. If found, please contact the owner.</p>
                
                <?php if($item['reward_amount'] > 0): ?>
                <p><strong>Reward Offered:</strong> $<?= number_format($item['reward_amount'], 2) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($item['qr_code_path'])): ?>
        <div class="qr-code">
            <h3>Item QR Code</h3>
            <img src="../<?= htmlspecialchars($item['qr_code_path']) ?>" 
                 alt="QR Code" 
                 onerror="this.style.display='none';">
            <p>Scan this code to view item details</p>
        </div>
        <?php endif; ?>
        
        <!-- Item Photos -->
        <?php 
        if(!empty($item['photos'])): 
            $photos = json_decode($item['photos'], true);
            if($photos && is_array($photos) && count($photos) > 0): 
        ?>
            <div class="item-photos">
                <?php foreach($photos as $index => $photo): ?>
                    <?php if(isset($photo['path'])): ?>
                    <img src="../<?= htmlspecialchars($photo['path']) ?>" 
                         alt="Item photo <?= $index + 1 ?>" 
                         class="item-photo"
                         onerror="this.style.display='none'">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; endif; ?>
        
        <!-- Item Details -->
        <h3>Item Information</h3>
        
        <div class="detail-row">
            <span class="detail-label">Brand:</span>
            <span><?= htmlspecialchars($item['brand'] ?? 'N/A') ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Model:</span>
            <span><?= htmlspecialchars($item['model'] ?? 'N/A') ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Serial Number:</span>
            <span><?= !empty($item['serial_number']) ? htmlspecialchars($item['serial_number']) : 'N/A' ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Category:</span>
            <span><?= htmlspecialchars($item['category'] ?? 'N/A') ?></span>
        </div>
        
        <?php if(!empty($item['description'])): ?>
        <div class="detail-row">
            <span class="detail-label">Description:</span>
            <span><?= htmlspecialchars($item['description']) ?></span>
        </div>
        <?php endif; ?>
        
        <?php if(!empty($item['purchase_price'])): ?>
        <div class="detail-row">
            <span class="detail-label">Purchase Price:</span>
            <span>$<?= number_format($item['purchase_price'], 2) ?></span>
        </div>
        <?php endif; ?>
        
        <?php if(!empty($item['current_value'])): ?>
        <div class="detail-row">
            <span class="detail-label">Current Value:</span>
            <span>$<?= number_format($item['current_value'], 2) ?></span>
        </div>
        <?php endif; ?>
        
        <?php if(!empty($item['location'])): ?>
        <div class="detail-row">
            <span class="detail-label">Stored Location:</span>
            <span><?= htmlspecialchars($item['location']) ?></span>
        </div>
        <?php endif; ?>
        
        <?php if(!empty($item['condition_rating'])): ?>
        <div class="detail-row">
            <span class="detail-label">Condition:</span>
            <span>
                <?= $item['condition_rating'] ?>/10
                <?php 
                $rating = intval($item['condition_rating']);
                if($rating >= 9) echo '<i class="fas fa-gem" style="color: #06d6a0; margin-left: 5px;"></i>';
                elseif($rating >= 7) echo '<i class="fas fa-thumbs-up" style="color: #118ab2; margin-left: 5px;"></i>';
                elseif($rating >= 5) echo '<i class="fas fa-check" style="color: #4361ee; margin-left: 5px;"></i>';
                elseif($rating >= 3) echo '<i class="fas fa-exclamation-triangle" style="color: #ffd166; margin-left: 5px;"></i>';
                else echo '<i class="fas fa-times" style="color: #ef476f; margin-left: 5px;"></i>';
                ?>
            </span>
        </div>
        <?php endif; ?>
        
        <!-- Lost/Stolen Details -->
        <?php if(in_array($item['status'], ['lost', 'stolen', 'damaged']) && !empty($item['reported_date'])): ?>
            <h3 style="margin-top: 30px;">Incident Details</h3>
            
            <?php if(!empty($item['incident_location'])): ?>
            <div class="detail-row">
                <span class="detail-label">Incident Location:</span>
                <span><?= htmlspecialchars($item['incident_location']) ?></span>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($item['incident_description'])): ?>
            <div class="detail-row">
                <span class="detail-label">Description:</span>
                <span><?= htmlspecialchars($item['incident_description']) ?></span>
            </div>
            <?php endif; ?>
            
            <div class="detail-row">
                <span class="detail-label">Reported Date:</span>
                <span><?= date('F j, Y, g:i a', strtotime($item['reported_date'])) ?></span>
            </div>
            
            <?php if($item['reward_amount'] > 0): ?>
            <div class="detail-row">
                <span class="detail-label">Reward Offered:</span>
                <span style="color: #06d6a0; font-weight: bold;">
                    <i class="fas fa-award"></i> $<?= number_format($item['reward_amount'], 2) ?>
                </span>
            </div>
            <?php endif; ?>
            
            <!-- Contact information for public reports -->
            <?php if($item['is_public'] == 1): ?>
                <div class="alert alert-info">
                    <h4><i class="fas fa-phone-alt"></i> Contact Information</h4>
                    <?php if(!empty($item['contact_phone'])): ?>
                    <p><strong>Phone:</strong> <?= htmlspecialchars($item['contact_phone']) ?></p>
                    <?php endif; ?>
                    
                    <?php if(!empty($item['contact_email'])): ?>
                    <p><strong>Email:</strong> <?= htmlspecialchars($item['contact_email']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Found Details -->
        <?php if($item['status'] == 'found' && !empty($item['found_date'])): ?>
            <div class="alert alert-info">
                <h3><i class="fas fa-check-circle"></i> Item Found</h3>
                <?php if(!empty($item['found_by_name'])): ?>
                <p><strong>Found By:</strong> <?= htmlspecialchars($item['found_by_name']) ?></p>
                <?php endif; ?>
                <p><strong>Found Date:</strong> <?= date('F j, Y', strtotime($item['found_date'])) ?></p>
            </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <h3>Actions</h3>
            
            <?php if(isset($_SESSION['user_id']) && $_SESSION['user_id'] == $item['user_id']): ?>
                <!-- Owner Actions -->
                <a href="items.php" class="btn">
                    <i class="fas fa-arrow-left"></i> Back to Items
                </a>
                
                <?php if(in_array($item['status'], ['lost', 'stolen', 'damaged'])): ?>
                    <a href="javascript:void(0)" onclick="markAsFound(<?= $item['id'] ?>)" class="btn btn-success">
                        <i class="fas fa-check"></i> Mark as Found
                    </a>
                <?php endif; ?>
                
                <a href="edit_item.php?id=<?= $item['id'] ?>" class="btn btn-info">
                    <i class="fas fa-edit"></i> Edit Item
                </a>
                
                <?php if($item['reward_amount'] > 0): ?>
                <a href="javascript:void(0)" onclick="shareRewardLink(<?= $item['id'] ?>)" class="btn btn-warning">
                    <i class="fas fa-share-alt"></i> Share Reward
                </a>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- Non-Owner Actions -->
                <?php if(in_array($item['status'], ['lost', 'stolen'])): ?>
                    <a href="report_found.php?item=<?= $item['id'] ?>" class="btn btn-success">
                        <i class="fas fa-flag"></i> Report Item Found
                    </a>
                    
                    <?php if($item['reward_amount'] > 0): ?>
                    <a href="claim_reward.php?item=<?= $item['id'] ?>" class="btn btn-warning">
                        <i class="fas fa-award"></i> Claim Reward
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
                
                <a href="index.php" class="btn">
                    <i class="fas fa-home"></i> Home
                </a>
                
                <?php if(isset($_SESSION['user_id'])): ?>
                <a href="items.php" class="btn">
                    <i class="fas fa-box"></i> My Items
                </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Owner info section -->
        <?php if(isset($_SESSION['user_id']) && $_SESSION['user_id'] == $item['user_id']): ?>
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h3>Owner Information</h3>
                <?php if(!empty($owner_name)): ?>
                <p><strong>Username:</strong> <?= htmlspecialchars($owner_name) ?></p>
                <?php endif; ?>
                <?php if(!empty($owner_email)): ?>
                <p><strong>Email:</strong> <?= htmlspecialchars($owner_email) ?></p>
                <?php endif; ?>
                <p><strong>Item ID:</strong> <?= $item['id'] ?></p>
                <p><strong>Verification Code:</strong> <code><?= htmlspecialchars($item['verification_code'] ?? 'N/A') ?></code></p>
            </div>
        <?php endif; ?>
        
        <!-- System info for non-owners -->
        <?php if(!isset($_SESSION['user_id']) || $_SESSION['user_id'] != $item['user_id']): ?>
            <div style="margin-top: 30px; padding: 15px; background: #e9ecef; border-radius: 5px;">
                <p><i class="fas fa-info-circle"></i> This item is registered with <strong>Vigilo Lost & Found System</strong>.</p>
                <p><small>If you find lost items, please scan their QR code or contact the owner through this system.</small></p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function markAsFound(itemId) {
            if (confirm('Mark this item as found?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'items.php';
                form.innerHTML = `
                    <input type="hidden" name="mark_found" value="1">
                    <input type="hidden" name="item_id" value="${itemId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
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
        
        // Auto-expand images on click
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('.item-photo');
            images.forEach(img => {
                img.addEventListener('click', function() {
                    const overlay = document.createElement('div');
                    overlay.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0,0,0,0.9);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        z-index: 1000;
                        cursor: zoom-out;
                    `;
                    
                    const expandedImg = document.createElement('img');
                    expandedImg.src = this.src;
                    expandedImg.style.cssText = `
                        max-width: 90%;
                        max-height: 90%;
                        object-fit: contain;
                    `;
                    
                    overlay.appendChild(expandedImg);
                    overlay.addEventListener('click', function() {
                        document.body.removeChild(this);
                    });
                    
                    document.body.appendChild(overlay);
                });
            });
        });
    </script>
</body>
</html>
<?php
// Close database connection
$stmt->close();
if(isset($conn)) {
    $conn->close();
}
?>