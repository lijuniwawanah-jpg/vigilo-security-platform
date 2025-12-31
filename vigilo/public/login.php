
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Vigilo</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #667eea;
            --primary-dark: #5a67d8;
            --secondary-color: #764ba2;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            animation: fadeIn 0.6s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius-lg);
            padding: 40px;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .logo-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            font-family: 'Poppins', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 10px;
        }

        .logo i {
            font-size: 2rem;
        }

        .logo-subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        .form-control {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-family: 'Roboto', sans-serif;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn:active {
            transform: translateY(0);
        }

        .alternate-login {
            margin: 25px 0;
            text-align: center;
            position: relative;
        }

        .alternate-login::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--border-color);
        }

        .alternate-login span {
            background: white;
            padding: 0 15px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            position: relative;
        }

        .phone-login-btn {
            width: 100%;
            padding: 14px;
            background: white;
            color: var(--text-primary);
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .phone-login-btn:hover {
            border-color: var(--primary-color);
            background: rgba(102, 126, 234, 0.05);
        }

        .links {
            margin-top: 25px;
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .links a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .links a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        .alert {
            padding: 12px 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: none;
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

        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
            }
            
            .logo {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo-header">
                <div class="logo">
                    <i class="fas fa-user-shield"></i>
                    Vigilo
                </div>
                <div class="logo-subtitle">Secure Identity & Document Platform</div>
            </div>

            <div id="message" class="alert" style="display: none;"></div>

            <form id="login-form">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-with-icon">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" class="form-control" placeholder="you@example.com" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" class="form-control" placeholder="Enter your password" required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-with-icon">
                        <button type="submit" class="btn">
                            <i class="fas fa-sign-in-alt"></i> Login to Dashboard
                        </button>
                    </div>
                </div>
            </form>

            <div class="alternate-login">
                <span>Or continue with</span>
            </div>

            <div class="form-group">
                <button type="button" class="phone-login-btn" onclick="window.location.href='login-phone.php'">
                    <i class="fas fa-mobile-alt"></i> Phone OTP Login
                </button>
            </div>

            <div class="links">
                <p>Don't have an account? <a href="signup.php">Create Account</a></p>
                <p><a href="forgot-password.php">Forgot your password?</a></p>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('login-form');
        const message = document.getElementById('message');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            if (!email || !password) {
                showMessage('Please fill in all fields', 'error');
                return;
            }

            const data = {
                email: email,
                password: password
            };

            try {
                // Show loading state
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Authenticating...';
                submitBtn.disabled = true;

                const res = await fetch('../api/auth/login.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(data)
                });

                const result = await res.json();

                if(result.success){
                    showMessage(result.message, 'success');
                    
                    // Store user data if provided
                    if(result.user){
                        localStorage.setItem('user', JSON.stringify(result.user));
                        localStorage.setItem('token', result.token || '');
                    }
                    
                    // Redirect to dashboard after 1.5 seconds
                    setTimeout(() => {
                        window.location.href = result.redirect || 'dashboard.php';
                    }, 1500);
                } else {
                    showMessage(result.message || 'Login failed. Please check your credentials.', 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            } catch (err) {
                console.error('Login error:', err);
                showMessage('Network error. Please check your connection.', 'error');
                
                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Login to Dashboard';
                submitBtn.disabled = false;
            }
        });

        function showMessage(text, type) {
            message.textContent = text;
            message.className = `alert alert-${type}`;
            message.style.display = 'block';
            
            // Auto-hide success messages
            if (type === 'success') {
                setTimeout(() => {
                    message.style.display = 'none';
                }, 3000);
            }
        }

        // Enter key to submit
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.target.matches('button')) {
                if (form.checkValidity()) {
                    form.requestSubmit();
                }
            }
        });

        // Focus first input on load
        document.getElementById('email').focus();
    </script>
</body>
</html>
