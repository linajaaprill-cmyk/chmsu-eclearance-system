<?php
// Check if admin already exists
$adminExists = $conn->query("SELECT * FROM chmsu_admin_users")->num_rows > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Create Account</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            background: #f0f0f0;
            font-size: 14px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .register-container {
            max-width: 400px;
            width: 100%;
            margin: 20px;
        }

        .register-card {
            background: white;
            padding: 40px 35px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .university-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .university-header h1 {
            font-size: 20px;
            color: #1b4d3e;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }

        .university-header p {
            font-size: 13px;
            color: #888;
            font-style: italic;
        }

        .form-title {
            text-align: center;
            font-size: 18px;
            color: #1b4d3e;
            margin-bottom: 25px;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }

        .form-group input {
            width: 100%;
            padding: 10px 0;
            border: none;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
            font-family: 'Times New Roman', Times, serif;
            background: transparent;
        }

        .form-group input:focus {
            outline: none;
            border-bottom-color: #1b4d3e;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            width: 100%;
            padding-right: 30px;
        }

        .toggle-password {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            font-size: 14px;
        }

        .btn-register {
            width: 100%;
            padding: 10px;
            background: #1b4d3e;
            color: white;
            border: none;
            font-size: 14px;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            margin-top: 10px;
        }

        .btn-register:hover {
            background: #2d6a4f;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }

        .back-link a {
            color: #1b4d3e;
            text-decoration: none;
            font-size: 12px;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 8px;
            margin-bottom: 15px;
            font-size: 12px;
            border-left: 3px solid #e74c3c;
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 8px;
            margin-bottom: 15px;
            font-size: 12px;
            border-left: 3px solid #27ae60;
        }

        .hidden {
            display: none;
        }

        .hint-text {
            font-size: 10px;
            color: #999;
            margin-top: 4px;
            display: block;
        }
    </style>
</head>
<body>

<div class="register-container">
    <div class="register-card">
        <div class="university-header">
            <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
            <p>E-Clearance System</p>
        </div>

        <div class="form-title">Create Account</div>
        
        <?php if($error && $error != 'password_mismatch'): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" id="registerForm" onsubmit="return validateRegisterForm()">
            <input type="hidden" name="action" value="unified_register">
            <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="last_name" id="regLastName" placeholder="Enter your last name" required>
            </div>
            <div class="form-group">
                <label>First Name</label>
                <input type="text" name="first_name" id="regFirstName" placeholder="Enter your first name" required>
            </div>
            <div class="form-group">
                <label>Username / Student ID</label>
                <input type="text" name="username" id="regUsername" placeholder="Enter username or student ID" required>
                <small class="hint-text">Students: Use your Student ID | Admins/Offices: Choose a username</small>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="regPassword" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('regPassword', this)"></i>
                </div>
                <small class="hint-text">6+ characters, 1 uppercase letter, 1 number</small>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="regConfirmPassword" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('regConfirmPassword', this)"></i>
                </div>
            </div>
            <div id="registerMatchError" class="error hidden">Passwords do not match!</div>
            <button type="submit" class="btn-register">Create Account</button>
        </form>

        <div class="back-link">
            <a href="?page=login">Back to Login</a>
        </div>
    </div>
</div>

<script>
    function togglePassword(id, icon) {
        const input = document.getElementById(id);
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    function validateRegisterForm() {
        const password = document.getElementById('regPassword').value;
        const confirm = document.getElementById('regConfirmPassword').value;
        const errorDiv = document.getElementById('registerMatchError');
        
        if (password !== confirm) {
            errorDiv.classList.remove('hidden');
            return false;
        }
        errorDiv.classList.add('hidden');
        return true;
    }
</script>

</body>
</html>