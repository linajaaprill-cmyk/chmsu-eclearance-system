<?php
// Check if admin already exists
$adminExists = $conn->query("SELECT * FROM chmsu_admin_users")->num_rows > 0;

// Get available offices (not yet registered)
$availableOffices = $conn->query("SELECT o.office_name FROM chmsu_offices o 
                                   LEFT JOIN chmsu_auth_roles ar ON o.office_name = ar.chmsu_role 
                                   WHERE ar.chmsu_role IS NULL 
                                   ORDER BY o.office_name");
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
            background: #e8ecef;
            font-size: 14px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .register-container {
            max-width: 480px;
            width: 100%;
            margin: 20px;
        }

        .register-card {
            background: white;
            padding: 45px 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .university-header {
            text-align: center;
            margin-bottom: 35px;
        }

        .university-header h1 {
            font-size: 22px;
            color: #1b4d3e;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .university-header p {
            font-size: 13px;
            color: #888;
            font-style: italic;
        }

        .form-title {
            text-align: center;
            font-size: 20px;
            color: #1b4d3e;
            margin-bottom: 30px;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 0;
            border: none;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
            font-family: 'Times New Roman', Times, serif;
            background: transparent;
        }

        .form-group select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23999' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>");
            background-repeat: no-repeat;
            background-position: right 0 center;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-bottom-color: #1b4d3e;
        }

        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 5px;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-weight: normal;
            margin-bottom: 0;
        }

        .radio-group input[type="radio"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            margin: 0;
        }

        .user-type-selector {
            margin-bottom: 22px;
        }

        .user-type-selector label {
            display: block;
            margin-bottom: 10px;
            color: #333;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }

        .user-type-options {
            display: flex;
            gap: 25px;
        }

        .user-type-options label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-weight: normal;
        }

        .office-select-container {
            display: none;
            margin-bottom: 22px;
        }

        .office-select-container.show {
            display: block;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            width: 100%;
            padding-right: 35px;
        }

        .toggle-password {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            font-size: 16px;
        }

        .toggle-password:hover {
            color: #1b4d3e;
        }

        .btn-register {
            width: 100%;
            padding: 12px;
            background: #1b4d3e;
            color: white;
            border: none;
            font-size: 14px;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            margin-top: 15px;
        }

        .btn-register:hover {
            background: #2d6a4f;
        }

        .back-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .back-link a {
            color: #1b4d3e;
            text-decoration: none;
            font-size: 13px;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .hint-text {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
            display: block;
        }

        /* Notification Styles */
        .notification {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 0;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .notification-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #e74c3c;
        }

        .notification-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #27ae60;
        }

        .notification i {
            font-size: 16px;
        }

        .close-notification {
            margin-left: auto;
            cursor: pointer;
            font-size: 14px;
            opacity: 0.7;
        }

        .close-notification:hover {
            opacity: 1;
        }

        .hidden {
            display: none;
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
        
        <!-- Notifications -->
        <?php if(isset($error) && $error != '' && $error != 'password_mismatch'): ?>
            <div class="notification notification-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
                <span class="close-notification" onclick="this.parentElement.style.display='none'">&times;</span>
            </div>
        <?php endif; ?>
        
        <?php if(isset($success) && $success != ''): ?>
            <div class="notification notification-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
                <span class="close-notification" onclick="this.parentElement.style.display='none'">&times;</span>
            </div>
        <?php endif; ?>

        <form method="POST" id="registerForm" onsubmit="return validateRegisterForm()">
            <input type="hidden" name="action" value="unified_register">
            
            <!-- User Type Selection -->
            <div class="user-type-selector">
                <label>Account Type</label>
                <div class="user-type-options">
                    <label>
                        <input type="radio" name="user_type" value="student" checked onchange="toggleUserType()"> Student
                    </label>
                    <label>
                        <input type="radio" name="user_type" value="office" onchange="toggleUserType()"> Office Personnel
                    </label>
                </div>
            </div>
            
            <!-- Student Fields (shown by default) -->
            <div id="student-fields">
                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" name="student_id" id="studentId" placeholder="Enter your student ID">
                    <small class="hint-text">Enter the student ID provided by the registrar</small>
                </div>
            </div>
            
            <!-- Office Fields (hidden by default) -->
            <div id="office-fields" style="display: none;">
                <div class="form-group">
                    <label>Select Office</label>
                    <select name="office_name" id="officeName">
                        <option value="">Select an office</option>
                        <?php while($office = $availableOffices->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($office['office_name']); ?>">
                                <?php echo htmlspecialchars($office['office_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <small class="hint-text">Only offices that are not yet registered will appear here</small>
                </div>
                <div class="form-group">
                    <label>Username (for login)</label>
                    <input type="text" name="office_username" id="officeUsername" placeholder="Choose a username">
                    <small class="hint-text">Choose any username you prefer for logging in</small>
                </div>
            </div>
            
            <!-- Common Fields -->
            <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="last_name" id="regLastName" placeholder="Enter your last name" required>
            </div>
            <div class="form-group">
                <label>First Name</label>
                <input type="text" name="first_name" id="regFirstName" placeholder="Enter your first name" required>
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
            <div id="registerMatchError" class="notification notification-error hidden">Passwords do not match!</div>
            <button type="submit" class="btn-register">Create Account</button>
        </form>

        <div class="back-link">
            <a href="?page=login">Back to Login</a>
        </div>
    </div>
</div>

<script>
    function toggleUserType() {
        const userType = document.querySelector('input[name="user_type"]:checked').value;
        const studentFields = document.getElementById('student-fields');
        const officeFields = document.getElementById('office-fields');
        const studentIdInput = document.getElementById('studentId');
        const officeNameSelect = document.getElementById('officeName');
        const officeUsernameInput = document.getElementById('officeUsername');
        
        if (userType === 'student') {
            studentFields.style.display = 'block';
            officeFields.style.display = 'none';
            studentIdInput.required = true;
            officeNameSelect.required = false;
            officeUsernameInput.required = false;
        } else {
            studentFields.style.display = 'none';
            officeFields.style.display = 'block';
            studentIdInput.required = false;
            officeNameSelect.required = true;
            officeUsernameInput.required = true;
        }
    }
    
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
        const userType = document.querySelector('input[name="user_type"]:checked').value;
        const password = document.getElementById('regPassword').value;
        const confirm = document.getElementById('regConfirmPassword').value;
        const errorDiv = document.getElementById('registerMatchError');
        
        if (password !== confirm) {
            errorDiv.classList.remove('hidden');
            return false;
        }
        errorDiv.classList.add('hidden');
        
        // Set the appropriate username field based on user type
        const usernameField = document.createElement('input');
        usernameField.type = 'hidden';
        usernameField.name = 'username';
        
        if (userType === 'student') {
            const studentId = document.getElementById('studentId').value;
            if (!studentId) {
                alert('Please enter your Student ID');
                return false;
            }
            usernameField.value = studentId;
        } else {
            const officeName = document.getElementById('officeName').value;
            const officeUsername = document.getElementById('officeUsername').value;
            if (!officeName) {
                alert('Please select an office');
                return false;
            }
            if (!officeUsername) {
                alert('Please choose a username');
                return false;
            }
            usernameField.value = officeUsername;
            
            // Also add office name for the backend
            const officeNameField = document.createElement('input');
            officeNameField.type = 'hidden';
            officeNameField.name = 'selected_office';
            officeNameField.value = officeName;
            document.getElementById('registerForm').appendChild(officeNameField);
        }
        
        document.getElementById('registerForm').appendChild(usernameField);
        return true;
    }
    
    // Auto-hide notifications after 5 seconds
    setTimeout(function() {
        const notifications = document.querySelectorAll('.notification');
        notifications.forEach(function(notification) {
            if (!notification.classList.contains('hidden')) {
                notification.style.opacity = '0';
                setTimeout(function() {
                    notification.style.display = 'none';
                }, 300);
            }
        });
    }, 5000);
</script>

</body>
</html>