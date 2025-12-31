<?php
// qr_scanner.php - QR Code Scanner Interface
session_start();
require_once('../config/db.php');

if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle QR code scan
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_data'])) {
    $qr_data = json_decode($_POST['qr_data'], true);
    
    if(isset($qr_data['verification_code'])) {
        // Search for item by verification code
        $stmt = $conn->prepare("SELECT * FROM items WHERE verification_code = ?");
        $stmt->bind_param("s", $qr_data['verification_code']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if($result->num_rows > 0) {
            $item = $result->fetch_assoc();
            header("Location: item_detail.php?id=" . $item['id']);
            exit;
        } else {
            $_SESSION['error'] = "Item not found in database.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Scanner - Vigilo</title>
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
        .scanner-container {
            width: 100%;
            max-width: 500px;
            margin: 20px auto;
            border: 2px dashed #ccc;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        #qr-video {
            width: 100%;
            height: auto;
        }
        .scanner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
        }
        .scan-line {
            position: absolute;
            top: 50%;
            left: 10%;
            right: 10%;
            height: 2px;
            background: #4CAF50;
            animation: scan 2s infinite linear;
        }
        @keyframes scan {
            0% { top: 10%; }
            50% { top: 90%; }
            100% { top: 10%; }
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            display: none;
        }
        .manual-input {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        .btn {
            padding: 10px 20px;
            background: #4361ee;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-qrcode"></i> QR Code Scanner</h1>
        
        <?php if(isset($_SESSION['error'])): ?>
            <div style="background: #ffebee; color: #c62828; padding: 10px; border-radius: 5px; margin: 10px 0;">
                <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <div class="scanner-container">
            <video id="qr-video"></video>
            <div class="scanner-overlay">
                <div class="scan-line"></div>
            </div>
        </div>
        
        <div id="result" class="result"></div>
        
        <div class="manual-input">
            <h3>Or Enter QR Code Data Manually:</h3>
            <form method="POST" action="">
                <textarea id="manual-qr-data" name="qr_data" 
                          placeholder='Paste QR code data here (JSON format)' 
                          rows="4" style="width: 100%; padding: 10px;"></textarea><br>
                <button type="submit" class="btn">
                    <i class="fas fa-search"></i> Lookup Item
                </button>
            </form>
        </div>
        
        <div style="margin-top: 20px;">
            <a href="items.php" class="btn">
                <i class="fas fa-arrow-left"></i> Back to Items
            </a>
        </div>
    </div>
    
    <script src="https://rawgit.com/schmich/instascan-builds/master/instascan.min.js"></script>
    <script>
        // QR Code Scanner
        let scanner = null;
        
        function startScanner() {
            Instascan.Camera.getCameras().then(function(cameras) {
                if (cameras.length > 0) {
                    scanner = new Instascan.Scanner({
                        video: document.getElementById('qr-video'),
                        mirror: false
                    });
                    
                    scanner.addListener('scan', function(content) {
                        try {
                            // Try to parse as JSON
                            const qrData = JSON.parse(content);
                            document.getElementById('result').innerHTML = 
                                '<h3>Item Found!</h3>' +
                                '<p><strong>Item:</strong> ' + (qrData.item_name || 'Unknown') + '</p>' +
                                '<p><strong>Brand:</strong> ' + (qrData.brand || 'N/A') + '</p>' +
                                '<button onclick="viewItem(\'' + qrData.verification_code + '\')" class="btn">View Details</button>';
                            document.getElementById('result').style.display = 'block';
                            
                            // Stop scanner after successful scan
                            scanner.stop();
                        } catch (e) {
                            // If not JSON, treat as direct verification code
                            document.getElementById('result').innerHTML = 
                                '<h3>Verification Code Scanned</h3>' +
                                '<p>Code: ' + content + '</p>' +
                                '<button onclick="lookupCode(\'' + content + '\')" class="btn">Lookup Item</button>';
                            document.getElementById('result').style.display = 'block';
                        }
                    });
                    
                    scanner.start(cameras[0]);
                } else {
                    alert('No cameras found');
                }
            }).catch(function(e) {
                console.error(e);
                alert('Camera access error: ' + e);
            });
        }
        
        function viewItem(verificationCode) {
            // Submit form with verification code
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="qr_data" value='{"verification_code":"${verificationCode}"}'>
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        function lookupCode(code) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="qr_data" value='{"verification_code":"${code}"}'>
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        // Start scanner on page load
        window.addEventListener('DOMContentLoaded', startScanner);
        
        // Clean up scanner on page unload
        window.addEventListener('beforeunload', function() {
            if (scanner) {
                scanner.stop();
            }
        });
    </script>
</body>
</html>