<?php
// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$loggedIn = isset($_SESSION['user_id']);
$user_name = $_SESSION['user_name'] ?? 'User';
$user_role = $_SESSION['user_role'] ?? 'user';
?>

<nav class="navbar">
    <div class="nav-container">
        <!-- Logo -->
        <div class="nav-logo">
            <a href="dashboard.php" class="logo-link">
                <i class="fas fa-user-shield"></i>
                <span>Vigilo</span>
            </a>
        </div>

        <!-- Mobile Menu Toggle -->
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Navigation Links -->
        <div class="nav-links" id="navLinks">
            <ul>
                <li><a href="dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a></li>
                
                <li><a href="upload.php" class="nav-link">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span>Upload</span>
                </a></li>
                
                <li><a href="documents.php" class="nav-link">
                    <i class="fas fa-file-alt"></i>
                    <span>Documents</span>
                </a></li>
                
                <li><a href="shared_links.php" class="nav-link">
                    <i class="fas fa-link"></i>
                    <span>Shared Links</span>
                </a></li>
                
                <li><a href="locations.php" class="nav-link">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>Locations</span>
                </a></li>
                
                <li><a href="verifier.php" class="nav-link">
                    <i class="fas fa-check-circle"></i>
                    <span>Verifier</span>
                </a></li>
                
                <?php if($user_role === 'admin'): ?>
                <li><a href="admin.php" class="nav-link admin-link">
                    <i class="fas fa-crown"></i>
                    <span>Admin</span>
                </a></li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- User Menu -->
        <div class="user-menu">
            <div class="user-dropdown" id="userDropdown">
                <button class="user-btn">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                    <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                
                <div class="dropdown-menu">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user-circle"></i>
                        <span>Profile</span>
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="help.php" class="dropdown-item">
                        <i class="fas fa-question-circle"></i>
                        <span>Help & Support</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../auth/logout.php" class="dropdown-item logout">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

<style>
    /* Navbar Styles */
    .navbar {
        background: white;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        position: sticky;
        top: 0;
        z-index: 1000;
        padding: 0 20px;
    }

    .nav-container {
        max-width: 1200px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 15px 0;
    }

    /* Logo */
    .nav-logo .logo-link {
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        color: #667eea;
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        font-size: 1.5rem;
    }

    .nav-logo .logo-link i {
        font-size: 1.3rem;
    }

    /* Navigation Links */
    .nav-links ul {
        display: flex;
        list-style: none;
        gap: 5px;
        margin: 0;
        padding: 0;
    }

    .nav-link {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 15px;
        text-decoration: none;
        color: #4a5568;
        border-radius: 8px;
        transition: all 0.3s ease;
        font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
    }

    .nav-link.active {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
    }

    .nav-link i {
        font-size: 1rem;
    }

    .admin-link {
        color: #f56565;
    }

    .admin-link:hover {
        background: rgba(245, 101, 101, 0.1);
        color: #f56565;
    }

    /* User Menu */
    .user-menu {
        position: relative;
    }

    .user-btn {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 15px;
        background: white;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-family: 'Roboto', sans-serif;
        color: #4a5568;
    }

    .user-btn:hover {
        border-color: #667eea;
        background: rgba(102, 126, 234, 0.05);
    }

    .user-avatar {
        width: 32px;
        height: 32px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.9rem;
    }

    .user-name {
        font-weight: 500;
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Dropdown Menu */
    .dropdown-menu {
        position: absolute;
        top: 100%;
        right: 0;
        width: 220px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        padding: 10px 0;
        margin-top: 10px;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: all 0.3s ease;
        border: 1px solid #e2e8f0;
    }

    .user-dropdown:hover .dropdown-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 20px;
        text-decoration: none;
        color: #4a5568;
        transition: all 0.3s ease;
    }

    .dropdown-item:hover {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
    }

    .dropdown-item.logout {
        color: #f56565;
    }

    .dropdown-item.logout:hover {
        background: rgba(245, 101, 101, 0.1);
    }

    .dropdown-divider {
        height: 1px;
        background: #e2e8f0;
        margin: 5px 0;
    }

    /* Mobile Menu Toggle */
    .mobile-menu-toggle {
        display: none;
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #4a5568;
        cursor: pointer;
        padding: 5px;
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .nav-links ul {
            gap: 3px;
        }
        
        .nav-link {
            padding: 8px 12px;
            font-size: 0.9rem;
        }
        
        .user-name {
            max-width: 100px;
        }
    }

    @media (max-width: 768px) {
        .mobile-menu-toggle {
            display: block;
        }
        
        .nav-links {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            padding: 20px;
            display: none;
        }
        
        .nav-links.active {
            display: block;
        }
        
        .nav-links ul {
            flex-direction: column;
            gap: 10px;
        }
        
        .nav-link {
            padding: 12px 15px;
            justify-content: flex-start;
        }
        
        .dropdown-menu {
            position: fixed;
            top: auto;
            bottom: 20px;
            left: 20px;
            right: 20px;
            width: auto;
        }
    }
</style>

<script>
    // Mobile menu toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const navLinks = document.getElementById('navLinks');
    
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', () => {
            navLinks.classList.toggle('active');
        });
        
        // Close mobile menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.nav-container')) {
                navLinks.classList.remove('active');
            }
        });
    }
    
    // Close mobile menu on window resize
    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
            navLinks.classList.remove('active');
        }
    });
    
    // Set active link based on current page
    document.addEventListener('DOMContentLoaded', () => {
        const currentPage = window.location.pathname.split('/').pop();
        const navLinks = document.querySelectorAll('.nav-link');
        
        navLinks.forEach(link => {
            const linkHref = link.getAttribute('href');
            if (linkHref === currentPage) {
                link.classList.add('active');
            }
        });
    });
</script>