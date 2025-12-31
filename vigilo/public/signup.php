<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up - Vigilo</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="card">
    <h2>Sign Up</h2>
    <form id="signup-form">
        <input type="text" id="fullName" placeholder="Full Name" required>
        <input type="email" id="email" placeholder="Email" required>
        <input type="password" id="password" placeholder="Password" required>
        <button type="submit">Sign Up</button>
    </form>
    <p>Already have an account? <a href="login.php">Login</a></p>
    <p id="message" style="color:red;"></p>
</div>

<script>
const form = document.getElementById('signup-form');
const message = document.getElementById('message');

form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const data = {
        fullName: document.getElementById('fullName').value,
        email: document.getElementById('email').value,
        password: document.getElementById('password').value
    };

    try {
        const res = await fetch('../api/auth/signup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const result = await res.json();

        if(result.success){
            message.style.color = 'green';
            message.textContent = result.message;
            setTimeout(() => window.location.href = 'login.php', 1500);
        } else {
            message.style.color = 'red';
            message.textContent = result.message;
        }
    } catch (err) {
        message.style.color = 'red';
        message.textContent = 'An error occurred!';
        console.error(err);
    }
});
</script>
</body>
</html>
