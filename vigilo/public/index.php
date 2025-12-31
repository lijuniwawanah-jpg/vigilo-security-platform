<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vigilo - Comprehensive Security & Verification Platform</title>
  <link rel="stylesheet" href="css/style.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <style>
    :root {
      --primary-color: #667eea;
      --primary-dark: #5a67d8;
      --secondary-color: #764ba2;
      --accent-color: #f093fb;
      --success-color: #43e97b;
      --warning-color: #ffd166;
      --danger-color: #f5576c;
      --info-color: #4facfe;
      --text-primary: #2d3748;
      --text-secondary: #718096;
      --light-bg: #f7fafc;
      --card-bg: #ffffff;
      --border-color: #e2e8f0;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.12);
      --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
      --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
      --shadow-xl: 0 20px 40px rgba(0,0,0,0.15);
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
      color: var(--text-primary);
      background: var(--light-bg);
      line-height: 1.6;
      overflow-x: hidden;
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

    /* Navbar */
    .navbar {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      padding: 1.2rem 5%;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: var(--shadow-sm);
      position: fixed;
      width: 100%;
      top: 0;
      z-index: 1000;
      transition: all 0.3s ease;
    }

    .navbar.scrolled {
      padding: 0.8rem 5%;
      background: rgba(255, 255, 255, 0.98);
      box-shadow: var(--shadow-md);
    }

    .logo {
      font-family: 'Poppins', sans-serif;
      font-size: 1.8rem;
      font-weight: 700;
      color: var(--primary-color);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .logo i {
      font-size: 1.5rem;
    }

    .navbar ul {
      display: flex;
      list-style: none;
      align-items: center;
      gap: 2rem;
    }

    .navbar a {
      text-decoration: none;
      color: var(--text-primary);
      font-weight: 500;
      transition: color 0.3s ease;
      padding: 0.5rem 1rem;
      border-radius: var(--border-radius);
    }

    .navbar a:hover {
      color: var(--primary-color);
      background: rgba(102, 126, 234, 0.1);
    }

    .navbar a.btn-outline {
      border: 2px solid var(--primary-color);
      color: var(--primary-color);
      padding: 0.5rem 1.5rem;
    }

    .navbar a.btn-outline:hover {
      background: var(--primary-color);
      color: white;
    }

    /* Hero Section */
    .hero {
      padding: 10rem 5% 5rem;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      min-height: 100vh;
      display: flex;
      align-items: center;
      position: relative;
      overflow: hidden;
    }

    .hero::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
      background-size: cover;
    }

    .hero-content {
      max-width: 600px;
      z-index: 1;
    }

    .hero h1 {
      font-size: 3.5rem;
      margin-bottom: 1.5rem;
      line-height: 1.2;
    }

    .hero h1 span {
      background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .hero-subtitle {
      font-size: 1.5rem;
      margin-bottom: 2rem;
      opacity: 0.9;
    }

    .hero-description {
      font-size: 1.2rem;
      margin-bottom: 3rem;
      opacity: 0.8;
    }

    #welcome-quote {
      font-size: 1.3rem;
      margin-bottom: 2.5rem;
      opacity: 0.9;
      min-height: 2rem;
      font-style: italic;
    }

    .hero-buttons {
      display: flex;
      gap: 1.5rem;
      margin-top: 2rem;
      flex-wrap: wrap;
    }

    .btn {
      padding: 1rem 2rem;
      border-radius: var(--border-radius);
      text-decoration: none;
      font-weight: 600;
      font-size: 1rem;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      border: none;
      cursor: pointer;
      font-family: 'Poppins', sans-serif;
    }

    .btn-primary {
      background: white;
      color: var(--primary-color);
    }

    .btn-primary:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-lg);
    }

    .btn-secondary {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .btn-secondary:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: translateY(-3px);
    }

    .hero-image {
      position: absolute;
      right: 5%;
      bottom: 0;
      max-width: 50%;
      z-index: 1;
    }

    .hero-image img {
      max-width: 100%;
      height: auto;
      filter: drop-shadow(0 20px 40px rgba(0,0,0,0.3));
    }

    /* Platform Overview */
    .platform-overview {
      padding: 6rem 5%;
      background: white;
    }

    .section-title {
      text-align: center;
      font-size: 2.5rem;
      margin-bottom: 1rem;
      color: var(--text-primary);
    }

    .section-subtitle {
      text-align: center;
      font-size: 1.2rem;
      color: var(--text-secondary);
      max-width: 600px;
      margin: 0 auto 3rem;
    }

    /* Sprint Cards */
    .sprint-timeline {
      display: flex;
      flex-direction: column;
      gap: 2rem;
      max-width: 1000px;
      margin: 0 auto;
    }

    .sprint-card {
      background: var(--card-bg);
      border-radius: var(--border-radius-lg);
      padding: 2.5rem;
      box-shadow: var(--shadow-md);
      border: 1px solid var(--border-color);
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .sprint-card:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-xl);
      border-color: var(--primary-color);
    }

    .sprint-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 6px;
      height: 100%;
      background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
    }

    .sprint-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }

    .sprint-title {
      font-size: 1.5rem;
      color: var(--text-primary);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .sprint-badge {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: white;
      padding: 0.5rem 1.5rem;
      border-radius: 20px;
      font-size: 0.9rem;
      font-weight: 600;
    }

    .sprint-features {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1.5rem;
      margin-top: 1.5rem;
    }

    .feature-item {
      display: flex;
      align-items: flex-start;
      gap: 15px;
      padding: 1rem;
      background: rgba(102, 126, 234, 0.05);
      border-radius: var(--border-radius);
    }

    .feature-icon {
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1.2rem;
      flex-shrink: 0;
    }

    /* Modules Showcase */
    .modules-showcase {
      padding: 6rem 5%;
      background: var(--light-bg);
    }

    .modules-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 2rem;
      margin-top: 3rem;
    }

    .module-card {
      background: white;
      border-radius: var(--border-radius-lg);
      padding: 2.5rem;
      text-align: center;
      box-shadow: var(--shadow-md);
      transition: all 0.3s ease;
      border: 1px solid var(--border-color);
    }

    .module-card:hover {
      transform: translateY(-10px);
      box-shadow: var(--shadow-xl);
    }

    .module-icon {
      width: 80px;
      height: 80px;
      margin: 0 auto 1.5rem;
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 2rem;
    }

    .module-card h3 {
      font-size: 1.5rem;
      margin-bottom: 1rem;
      color: var(--text-primary);
    }

    .module-card p {
      color: var(--text-secondary);
      margin-bottom: 1.5rem;
    }

    /* Features Grid */
    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 2rem;
      margin-top: 3rem;
    }

    .feature-box {
      background: white;
      border-radius: var(--border-radius);
      padding: 2rem;
      text-align: center;
      box-shadow: var(--shadow-sm);
      transition: all 0.3s ease;
    }

    .feature-box:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-lg);
    }

    .feature-box i {
      font-size: 2.5rem;
      margin-bottom: 1rem;
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    /* CTA Section */
    .cta {
      padding: 6rem 5%;
      text-align: center;
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: white;
      position: relative;
      overflow: hidden;
    }

    .cta::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,100 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
      background-size: cover;
    }

    .cta-content {
      position: relative;
      z-index: 1;
      max-width: 800px;
      margin: 0 auto;
    }

    .cta h2 {
      font-size: 2.5rem;
      margin-bottom: 1.5rem;
    }

    .cta p {
      font-size: 1.2rem;
      margin-bottom: 3rem;
      opacity: 0.9;
    }

    /* Footer */
    footer {
      background: var(--text-primary);
      color: white;
      padding: 4rem 5% 2rem;
    }

    .footer-content {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 3rem;
      max-width: 1200px;
      margin: 0 auto;
    }

    .footer-section h3 {
      font-size: 1.2rem;
      margin-bottom: 1.5rem;
      color: white;
    }

    .footer-section ul {
      list-style: none;
    }

    .footer-section ul li {
      margin-bottom: 0.8rem;
    }

    .footer-section a {
      color: rgba(255, 255, 255, 0.8);
      text-decoration: none;
      transition: color 0.3s ease;
    }

    .footer-section a:hover {
      color: white;
    }

    .footer-bottom {
      text-align: center;
      margin-top: 3rem;
      padding-top: 2rem;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      color: rgba(255, 255, 255, 0.6);
    }

    /* Responsive */
    @media (max-width: 1024px) {
      .hero {
        flex-direction: column;
        text-align: center;
        padding: 8rem 5% 3rem;
      }

      .hero-image {
        position: relative;
        right: auto;
        max-width: 100%;
        margin-top: 3rem;
      }

      .hero h1 {
        font-size: 2.8rem;
      }

      .navbar ul {
        display: none;
      }
    }

    @media (max-width: 768px) {
      .hero h1 {
        font-size: 2.2rem;
      }

      .section-title {
        font-size: 2rem;
      }

      .sprint-features {
        grid-template-columns: 1fr;
      }

      .modules-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <!-- Navbar -->
  <header>
    <nav class="navbar" id="navbar">
      <div class="logo">
        <i class="fas fa-user-shield"></i>
        Vigilo
      </div>
      <ul>
        <li><a href="#hero">Home</a></li>
        <li><a href="#platform">Platform</a></li>
        <li><a href="#modules">Modules</a></li>
        <li><a href="#features">Features</a></li>
        <li><a href="#contact">Contact</a></li>
        <?php if(!$loggedIn): ?>
          <li><a href="login.php" class="btn-primary">
            <i class="fas fa-sign-in-alt"></i> Login
          </a></li>
          <li><a href="signup.php" class="btn-secondary">
            <i class="fas fa-user-plus"></i> Sign Up
          </a></li>
        <?php else: ?>
          <li><a href="dashboard.php" class="btn-primary">
            <i class="fas fa-tachometer-alt"></i> Dashboard
          </a></li>
        <?php endif; ?>
      </ul>
    </nav>
  </header>

  <!-- Hero Section -->
  <section id="hero" class="hero">
    <div class="container">
      <div class="hero-content">
        <h1>Vigilo: <span>All-in-One Security Platform</span></h1>
        <div class="hero-subtitle">Identity • Documents • Locations • Items • Verification</div>
        <p class="hero-description">
          A comprehensive security ecosystem combining digital identity verification, 
          document authentication, location tracking, item registration, and liveness detection 
          in one unified platform.
        </p>
        <p id="welcome-quote">Securing identities, verifying documents, tracking assets — all in one platform.</p>
        <div class="hero-buttons">
          <a href="<?php echo $loggedIn ? 'dashboard.php' : 'signup.php'; ?>" class="btn btn-primary">
            <i class="fas fa-rocket"></i> Launch Platform
          </a>
          <a href="#platform" class="btn btn-secondary">
            <i class="fas fa-play-circle"></i> Explore Features
          </a>
          <a href="#modules" class="btn btn-secondary">
            <i class="fas fa-layer-group"></i> View Modules
          </a>
        </div>
      </div>
    </div>
    <div class="hero-image">
      <img src="https://cdn.prod.website-files.com/6001ed6c5d2f7b0e08b8e8c2/6119b9853298c32d3bc6a1c9_dashboard-p-1080.png" alt="Vigilo Dashboard Preview">
    </div>
  </section>

  <!-- Platform Overview -->
  <section id="platform" class="platform-overview">
    <div class="container">
      <h2 class="section-title">Complete 12-Week Development Roadmap</h2>
      <p class="section-subtitle">Our platform is built through 6 intensive sprints, each delivering critical security modules</p>
      
      <div class="sprint-timeline">
        <!-- Sprint 1 -->
        <div class="sprint-card">
          <div class="sprint-header">
            <h3 class="sprint-title"><i class="fas fa-user-lock"></i> Sprint 1: Core Auth & Identity</h3>
            <span class="sprint-badge">Weeks 1-2</span>
          </div>
          <p>Foundational identity and authentication system</p>
          <div class="sprint-features">
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-mobile-alt"></i></div>
              <div>
                <h4>Phone OTP Auth</h4>
                <p>Secure mobile verification system</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-id-card"></i></div>
              <div>
                <h4>W-ID Generation</h4>
                <p>Unique digital identity with QR codes</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-user-edit"></i></div>
              <div>
                <h4>Profile CRUD</h4>
                <p>Complete user profile management</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-mobile"></i></div>
              <div>
                <h4>PWA Shell</h4>
                <p>Progressive Web App with offline support</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Sprint 2 -->
        <div class="sprint-card">
          <div class="sprint-header">
            <h3 class="sprint-title"><i class="fas fa-file-contract"></i> Sprint 2: Document Verification</h3>
            <span class="sprint-badge">Weeks 3-4</span>
          </div>
          <p>Advanced document processing and authentication</p>
          <div class="sprint-features">
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-cloud-upload-alt"></i></div>
              <div>
                <h4>Document Upload</h4>
                <p>Secure file upload with encryption</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-eye"></i></div>
              <div>
                <h4>Tesseract OCR</h4>
                <p>Optical character recognition</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
              <div>
                <h4>Secure Storage</h4>
                <p>Encrypted document repository</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-tasks"></i></div>
              <div>
                <h4>Verification Queue</h4>
                <p>Admin document review system</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Sprint 3 -->
        <div class="sprint-card">
          <div class="sprint-header">
            <h3 class="sprint-title"><i class="fas fa-map-marked-alt"></i> Sprint 3: Location Services</h3>
            <span class="sprint-badge">Weeks 5-6</span>
          </div>
          <p>Geolocation tracking and management</p>
          <div class="sprint-features">
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-map-pin"></i></div>
              <div>
                <h4>W-LOC Creation</h4>
                <p>Create and manage location points</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-map"></i></div>
              <div>
                <h4>Interactive Map UI</h4>
                <p>Visual location interface</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-database"></i></div>
              <div>
                <h4>Location Persistence</h4>
                <p>Store and search locations</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-wifi-slash"></i></div>
              <div>
                <h4>Offline Caching</h4>
                <p>Work without internet connection</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Sprint 4 -->
        <div class="sprint-card">
          <div class="sprint-header">
            <h3 class="sprint-title"><i class="fas fa-boxes"></i> Sprint 4: Item Security</h3>
            <span class="sprint-badge">Weeks 7-8</span>
          </div>
          <p>Asset registration and theft prevention</p>
          <div class="sprint-features">
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-cube"></i></div>
              <div>
                <h4>Item Registration</h4>
                <p>Register items with photos</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-exclamation-triangle"></i></div>
              <div>
                <h4>Stolen Marking</h4>
                <p>Report stolen items</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-search"></i></div>
              <div>
                <h4>Public Search</h4>
                <p>Search stolen items database</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-store"></i></div>
              <div>
                <h4>Marketplace Safety</h4>
                <p>Verify items before purchase</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Sprint 5 -->
        <div class="sprint-card">
          <div class="sprint-header">
            <h3 class="sprint-title"><i class="fas fa-user-check"></i> Sprint 5: Biometric Verification</h3>
            <span class="sprint-badge">Weeks 9-10</span>
          </div>
          <p>Advanced identity verification using biometrics</p>
          <div class="sprint-features">
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-video"></i></div>
              <div>
                <h4>Liveness Detection</h4>
                <p>Ensure real person presence</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-user-friends"></i></div>
              <div>
                <h4>Face Matching</h4>
                <p>Compare faces for identity</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-user-tie"></i></div>
              <div>
                <h4>Human Review Flow</h4>
                <p>Manual verification when needed</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
              <div>
                <h4>Trust Scoring</h4>
                <p>Calculate user trust levels</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Sprint 6 -->
        <div class="sprint-card">
          <div class="sprint-header">
            <h3 class="sprint-title"><i class="fas fa-cogs"></i> Sprint 6: Administration & Security</h3>
            <span class="sprint-badge">Weeks 11-12</span>
          </div>
          <p>Platform administration and security hardening</p>
          <div class="sprint-features">
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-tachometer-alt"></i></div>
              <div>
                <h4>Admin Dashboard</h4>
                <p>Complete system administration</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-clipboard-list"></i></div>
              <div>
                <h4>Audit Logs</h4>
                <p>Complete activity tracking</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-chart-bar"></i></div>
              <div>
                <h4>Reports System</h4>
                <p>Generate detailed reports</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon"><i class="fas fa-shield-virus"></i></div>
              <div>
                <h4>Security Hardening</h4>
                <p>Pentest planning & execution</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Modules Showcase -->
  <section id="modules" class="modules-showcase">
    <div class="container">
      <h2 class="section-title">Integrated Security Modules</h2>
      <p class="section-subtitle">Each module works together to provide comprehensive security coverage</p>
      
      <div class="modules-grid">
        <div class="module-card">
          <div class="module-icon">
            <i class="fas fa-fingerprint"></i>
          </div>
          <h3>Identity Management</h3>
          <p>Digital identity creation with W-ID, biometric verification, and secure authentication</p>
          <div class="feature-tags">
            <span class="tag">OTP Auth</span>
            <span class="tag">W-ID</span>
            <span class="tag">Biometrics</span>
          </div>
        </div>
        
        <div class="module-card">
          <div class="module-icon">
            <i class="fas fa-file-signature"></i>
          </div>
          <h3>Document Verification</h3>
          <p>Upload, OCR processing, verification queue, and secure document storage</p>
          <div class="feature-tags">
            <span class="tag">OCR</span>
            <span class="tag">Encryption</span>
            <span class="tag">Verification</span>
          </div>
        </div>
        
        <div class="module-card">
          <div class="module-icon">
            <i class="fas fa-map-marked"></i>
          </div>
          <h3>Location Services</h3>
          <p>Geolocation tracking, W-LOC creation, map visualization, and offline support</p>
          <div class="feature-tags">
            <span class="tag">Maps</span>
            <span class="tag">Geolocation</span>
            <span class="tag">Offline</span>
          </div>
        </div>
        
        <div class="module-card">
          <div class="module-icon">
            <i class="fas fa-box-open"></i>
          </div>
          <h3>Item Security</h3>
          <p>Item registration, stolen database, public search, and marketplace safety</p>
          <div class="feature-tags">
            <span class="tag">Registration</span>
            <span class="tag">Stolen DB</span>
            <span class="tag">Search</span>
          </div>
        </div>
        
        <div class="module-card">
          <div class="module-icon">
            <i class="fas fa-user-shield"></i>
          </div>
          <h3>Biometric Verification</h3>
          <p>Liveness detection, face matching, trust scoring, and human review workflows</p>
          <div class="feature-tags">
            <span class="tag">Liveness</span>
            <span class="tag">Face Match</span>
            <span class="tag">Trust Score</span>
          </div>
        </div>
        
        <div class="module-card">
          <div class="module-icon">
            <i class="fas fa-tachometer-alt"></i>
          </div>
          <h3>Admin & Security</h3>
          <p>Administration dashboard, audit logs, reporting, and security hardening</p>
          <div class="feature-tags">
            <span class="tag">Admin Panel</span>
            <span class="tag">Audit Logs</span>
            <span class="tag">Security</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Key Features -->
  <section id="features" class="platform-overview">
    <div class="container">
      <h2 class="section-title">Platform Key Features</h2>
      <p class="section-subtitle">Everything you need for comprehensive digital security management</p>
      
      <div class="features-grid">
        <div class="feature-box">
          <i class="fas fa-mobile-alt"></i>
          <h3>Mobile-First PWA</h3>
          <p>Works offline, installable on any device, native app experience</p>
        </div>
        
        <div class="feature-box">
          <i class="fas fa-shield-alt"></i>
          <h3>End-to-End Encryption</h3>
          <p>Military-grade encryption for all data and documents</p>
        </div>
        
        <div class="feature-box">
          <i class="fas fa-sync-alt"></i>
          <h3>Real-time Updates</h3>
          <p>Live updates across all modules and user devices</p>
        </div>
        
        <div class="feature-box">
          <i class="fas fa-chart-pie"></i>
          <h3>Analytics Dashboard</h3>
          <p>Comprehensive insights and reporting tools</p>
        </div>
        
        <div class="feature-box">
          <i class="fas fa-robot"></i>
          <h3>AI-Powered OCR</h3>
          <p>Advanced document processing with Tesseract</p>
        </div>
        
        <div class="feature-box">
          <i class="fas fa-globe"></i>
          <h3>Global Location Support</h3>
          <p>Worldwide maps and location services</p>
        </div>
        
        <div class="feature-box">
          <i class="fas fa-bolt"></i>
          <h3>Instant Verification</h3>
          <p>Quick identity and document verification</p>
        </div>
        
        <div class="feature-box">
          <i class="fas fa-users-cog"></i>
          <h3>Multi-role System</h3>
          <p>User, admin, and reviewer roles with permissions</p>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="cta">
    <div class="container">
      <div class="cta-content">
        <h2>Ready to Secure Your Digital World?</h2>
        <p>Join thousands of users and organizations who trust Vigilo with their identity, documents, and assets.</p>
        <div style="display: flex; gap: 1.5rem; justify-content: center; flex-wrap: wrap;">
          <a href="<?php echo $loggedIn ? 'login.php' : 'signup.php'; ?>" class="btn btn-primary" style="padding: 1.2rem 3rem;">
            <i class="fas fa-user-plus"></i> Start Free Trial
          </a>
          <a href="#platform" class="btn btn-secondary" style="padding: 1.2rem 3rem;">
            <i class="fas fa-book-open"></i> View Documentation
          </a>
        </div>
        <p style="margin-top: 1.5rem; font-size: 0.9rem; opacity: 0.8;">
          No credit card required • 30-day free trial • Enterprise plans available
        </p>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer id="contact">
    <div class="container">
      <div class="footer-content">
        <div class="footer-section">
          <h3>Vigilo Platform</h3>
          <p style="opacity: 0.8; margin-bottom: 1.5rem;">
            Comprehensive security platform for identity, documents, locations, and assets.
          </p>
          <div style="display: flex; gap: 1rem;">
            <a href="#" style="color: white; opacity: 0.8; font-size: 1.2rem;">
              <i class="fab fa-twitter"></i>
            </a>
            <a href="#" style="color: white; opacity: 0.8; font-size: 1.2rem;">
              <i class="fab fa-linkedin"></i>
            </a>
            <a href="#" style="color: white; opacity: 0.8; font-size: 1.2rem;">
              <i class="fab fa-github"></i>
            </a>
          </div>
        </div>
        
        <div class="footer-section">
          <h3>Modules</h3>
          <ul>
            <li><a href="#">Identity Management</a></li>
            <li><a href="#">Document Verification</a></li>
            <li><a href="#">Location Services</a></li>
            <li><a href="#">Item Security</a></li>
            <li><a href="#">Biometric Verification</a></li>
            <li><a href="#">Admin Dashboard</a></li>
          </ul>
        </div>
        
        <div class="footer-section">
          <h3>Resources</h3>
          <ul>
            <li><a href="#">Documentation</a></li>
            <li><a href="#">API Reference</a></li>
            <li><a href="#">Developer Guide</a></li>
            <li><a href="#">Security Center</a></li>
            <li><a href="#">Compliance</a></li>
            <li><a href="#">Support</a></li>
          </ul>
        </div>
        
        <div class="footer-section">
          <h3>Contact</h3>
          <ul>
            <li><i class="fas fa-envelope" style="margin-right: 10px;"></i> info@vigilo.com</li>
            <li><i class="fas fa-phone" style="margin-right: 10px;"></i> +1 (555) 123-4567</li>
            <li><i class="fas fa-map-marker-alt" style="margin-right: 10px;"></i> San Francisco, CA</li>
          </ul>
          <div style="margin-top: 1.5rem;">
            <img src="https://img.shields.io/badge/SOC_2-Type_II-blue" alt="SOC 2" style="height: 20px; margin-right: 10px;">
            <img src="https://img.shields.io/badge/GDPR-Compliant-green" alt="GDPR" style="height: 20px;">
          </div>
        </div>
      </div>
      
      <div class="footer-bottom">
        <p>&copy; 2025 Vigilo Security Platform. All rights reserved.</p>
        <p style="margin-top: 0.5rem; font-size: 0.9rem;">
          <a href="#" style="color: rgba(255, 255, 255, 0.6); margin: 0 10px;">Privacy Policy</a> • 
          <a href="#" style="color: rgba(255, 255, 255, 0.6); margin: 0 10px;">Terms of Service</a> • 
          <a href="#" style="color: rgba(255, 255, 255, 0.6); margin: 0 10px;">Cookie Policy</a>
        </p>
      </div>
    </div>
  </footer>

  <!-- Scripts -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    // Navbar scroll effect
    const navbar = document.getElementById('navbar');
    window.addEventListener('scroll', function() {
      if (window.scrollY > 50) {
        navbar.classList.add('scrolled');
      } else {
        navbar.classList.remove('scrolled');
      }
    });

    // Rotating quotes
    const quotes = [
      "Securing identities, verifying documents, tracking assets — all in one platform.",
      "From biometric verification to location tracking — complete security ecosystem.",
      "Trusted by organizations worldwide for comprehensive security management.",
      "Identity • Documents • Locations • Items • Verification — Unified Platform.",
      "Advanced OCR, liveness detection, and real-time verification in one solution.",
      "Your complete security platform for the digital world."
    ];
    let i = 0;
    const quoteEl = document.getElementById('welcome-quote');
    
    quoteEl.style.transition = 'opacity 0.5s ease';
    
    setInterval(() => {
      quoteEl.style.opacity = '0';
      setTimeout(() => {
        quoteEl.textContent = quotes[i];
        quoteEl.style.opacity = '1';
        i = (i + 1) % quotes.length;
      }, 500);
    }, 4000);

    // Smooth scroll
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const targetId = this.getAttribute('href');
        if(targetId === '#') return;
        
        const targetElement = document.querySelector(targetId);
        if(targetElement) {
          window.scrollTo({
            top: targetElement.offsetTop - 80,
            behavior: 'smooth'
          });
        }
      });
    });

    // Initialize map if needed
    function initMap() {
      if (document.getElementById('map')) {
        const map = L.map('map').setView([51.505, -0.09], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '© OpenStreetMap contributors'
        }).addTo(map);
      }
    }

    // Mobile menu
    if (window.innerWidth < 1024) {
      const navbar = document.querySelector('.navbar');
      const logo = document.querySelector('.logo');
      
      const menuToggle = document.createElement('button');
      menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
      menuToggle.style.background = 'none';
      menuToggle.style.border = 'none';
      menuToggle.style.color = 'var(--primary-color)';
      menuToggle.style.fontSize = '1.5rem';
      menuToggle.style.cursor = 'pointer';
      menuToggle.style.marginLeft = 'auto';
      
      menuToggle.addEventListener('click', () => {
        const navList = document.querySelector('.navbar ul');
        navList.style.display = navList.style.display === 'flex' ? 'none' : 'flex';
        navList.style.flexDirection = 'column';
        navList.style.position = 'absolute';
        navList.style.top = '100%';
        navList.style.left = '0';
        navList.style.right = '0';
        navList.style.background = 'rgba(255, 255, 255, 0.98)';
        navList.style.padding = '1rem';
        navList.style.boxShadow = 'var(--shadow-md)';
        navList.style.backdropFilter = 'blur(10px)';
      });
      
      navbar.appendChild(menuToggle);
    }

    // Initialize on load
    document.addEventListener('DOMContentLoaded', function() {
      initMap();
    });
  </script>
</body>
</html>