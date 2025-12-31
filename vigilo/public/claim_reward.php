<?php
// claim_reward.php - Claim Reward for Found Item
session_start();
require_once('../config/db.php');

$item_id = intval($_GET['item'] ?? 0);
$error = '';
$success = '';
$item = null;

// Get item details
if ($item_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM items WHERE id = ? AND status IN ('lost', 'stolen') AND is_public = 1");
    if ($stmt) {
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();
        $stmt->close();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_claim'])) {
    $item_id = intval($_POST['item_id']);
    $claimer_name = trim($_POST['claimer_name'] ?? '');
    $claimer_phone = trim($_POST['claimer_phone'] ?? '');
    $claimer_email = trim($_POST['claimer_email'] ?? '');
    $claim_message = trim($_POST['claim_message'] ?? '');
    $proof_description = trim($_POST['proof_description'] ?? '');
    
    // Validate input
    if (empty($claimer_name) || empty($claimer_email)) {
        $error = "Name and email are required fields.";
    } elseif (!filter_var($claimer_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Check if item exists and has reward
        $check_stmt = $conn->prepare("SELECT id, item_name, reward_amount, user_id FROM items WHERE id = ? AND reward_amount > 0");
        $check_stmt->bind_param("i", $item_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $item_data = $check_result->fetch_assoc();
            
            // Check if claim already exists
            $existing_stmt = $conn->prepare("SELECT id FROM reward_claims WHERE item_id = ? AND claimer_email = ?");
            $existing_stmt->bind_param("is", $item_id, $claimer_email);
            $existing_stmt->execute();
            
            if ($existing_stmt->get_result()->num_rows > 0) {
                $error = "You have already submitted a claim for this item.";
            } else {
                // Insert claim
                $claim_stmt = $conn->prepare("INSERT INTO reward_claims 
                    (item_id, claimer_name, claimer_phone, claimer_email, claim_message, proof_description, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                
                $claim_stmt->bind_param("isssss", $item_id, $claimer_name, $claimer_phone, $claimer_email, $claim_message, $proof_description);
                
                if ($claim_stmt->execute()) {
                    $claim_id = $claim_stmt->insert_id;
                    
                    // Log activity
                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, activity_type, description) VALUES (?, 'reward_claim', 'Reward claim submitted for item #{$item_id}')");
                    $log_stmt->bind_param("i", $item_data['user_id']);
                    $log_stmt->execute();
                    
                    $success = "Reward claim submitted successfully! Claim ID: #{$claim_id}. The item owner will review your claim and contact you.";
                } else {
                    $error = "Failed to submit claim: " . $conn->error;
                }
                $claim_stmt->close();
            }
            $existing_stmt->close();
        } else {
            $error = "Item not found or no reward offered.";
        }
        $check_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Reward - Vigilo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --success: #06d6a0;
            --warning: #ffd166;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            width: 100%;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--warning), #ff9e00);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 2rem;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        
        .item-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid var(--warning);
        }
        
        .item-name {
            font-size: 1.2rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        
        .reward-amount {
            color: var(--success);
            font-size: 1.5rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .required::after {
            content: " *";
            color: #ef476f;
        }
        
        .btn {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(135deg, var(--warning), #ff9e00);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s;
            width: 100%;
            margin-top: 10px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .terms {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 0.9rem;
            color: #666;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: var(--primary);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <div class="header-icon">
                    <i class="fas fa-award"></i>
                </div>
                <h1>Claim Reward</h1>
                <p class="subtitle">Submit your claim to receive the reward for finding this item</p>
            </div>
            
            <?php if($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                </div>
                <div style="text-align: center;">
                    <a href="public_search.php" class="back-link">
                        <i class="fas fa-arrow-left"></i> Back to Search
                    </a>
                </div>
            <?php else: ?>
            
            <?php if($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if($item): ?>
                <div class="item-info">
                    <div class="item-name"><?= htmlspecialchars($item['item_name']) ?></div>
                    <div>Lost in: <?= htmlspecialchars($item['incident_location']) ?></div>
                    <?php if(!empty($item['reported_date'])): ?>
                    <div>Reported: <?= date('M d, Y', strtotime($item['reported_date'])) ?></div>
                    <?php endif; ?>
                    <div class="reward-amount">
                        <i class="fas fa-award"></i> Reward: $<?= number_format($item['reward_amount'], 2) ?>
                    </div>
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                    
                    <div class="form-group">
                        <label class="required">Your Full Name</label>
                        <input type="text" class="form-control" name="claimer_name" 
                               required placeholder="Enter your full name">
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Email Address</label>
                        <input type="email" class="form-control" name="claimer_email" 
                               required placeholder="Enter your email address">
                    </div>
                    
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" class="form-control" name="claimer_phone" 
                               placeholder="Enter your phone number">
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Claim Message</label>
                        <textarea class="form-control" name="claim_message" rows="4" 
                                  required placeholder="Describe how you found the item and provide any relevant details..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Proof Description</label>
                        <textarea class="form-control" name="proof_description" rows="3" 
                                  placeholder="Describe any proof you have (photos, witnesses, location details)..."></textarea>
                        <small style="color: #666; margin-top: 5px; display: block;">
                            Note: You may be asked to provide proof when the owner contacts you.
                        </small>
                    </div>
                    
                    <button type="submit" name="submit_claim" value="1" class="btn">
                        <i class="fas fa-paper-plane"></i> Submit Reward Claim
                    </button>
                </form>
                
                <div class="terms">
                    <p><strong>Terms & Conditions:</strong></p>
                    <ul style="margin-left: 20px; margin-top: 10px;">
                        <li>You must have physical possession of the item or know its exact location</li>
                        <li>The item owner will verify your claim before releasing the reward</li>
                        <li>False claims may result in legal action</li>
                        <li>The reward will be paid after the item is successfully returned to the owner</li>
                        <li>Vigilo acts only as an intermediary and is not responsible for reward payments</li>
                    </ul>
                </div>
                
            <?php else: ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> Item not found or not eligible for reward claims.
                </div>
                <div style="text-align: center;">
                    <a href="public_search.php" class="back-link">
                        <i class="fas fa-arrow-left"></i> Back to Search
                    </a>
                </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php
if(isset($conn)) {
    $conn->close();
}
?>