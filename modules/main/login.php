<?php
// ============================================
// ADMIN-FLAGGED PASSWORD RESET — LOGIN FLOW
// ============================================
$showResetForm      = false;
$pendingStudentId   = '';
$pendingStudentName = '';
$resetFlowError     = '';

// ---------- STEP 1: student submits ID → check for pending reset ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_pending_reset']) && !empty($_POST['username'])) {
    $sid = sanitize($_POST['username']);
    $sid_esc = $conn->real_escape_string($sid);
    
    // Check if this student is flagged for reset
    $pending = $conn->query("
        SELECT pr.id, s.chmsu_full_name
        FROM chmsu_password_resets pr
        JOIN chmsu_students_master s ON s.chmsu_student_id = pr.student_id
        WHERE pr.student_id = '$sid_esc' AND pr.is_used = 0
        ORDER BY pr.id DESC LIMIT 1
    ")->fetch_assoc();

    if ($pending) {
        // FLAG FOUND! Stop login, show reset form.
        unset($_POST['action']); // Disable normal login processing
        unset($_POST['login']);  // Disable normal login processing
        $showResetForm      = true;
        $pendingStudentId   = $sid;
        $pendingStudentName = $pending['chmsu_full_name'];
    }
}

// ---------- STEP 2: student submits new password ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_password_reset'])) {
    $sid     = sanitize($_POST['pending_student_id'] ?? '');
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $sid_esc = $conn->real_escape_string($sid);

    $pending = $conn->query("SELECT id FROM chmsu_password_resets
                             WHERE student_id = '$sid_esc' AND is_used = 0
                             ORDER BY id DESC LIMIT 1")->fetch_assoc();

    if (!$pending) {
        $resetFlowError = "Password reset session expired. Please log in again.";
    } elseif (strlen($newPass) < 8) {
        $resetFlowError     = "Password must be at least 8 characters.";
        $showResetForm      = true;
        $pendingStudentId   = $sid;
    } elseif (!preg_match('/[A-Za-z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
        $resetFlowError     = "Password must contain both letters and numbers.";
        $showResetForm      = true;
        $pendingStudentId   = $sid;
    } elseif ($newPass !== $confirm) {
        $resetFlowError     = "Passwords do not match.";
        $showResetForm      = true;
        $pendingStudentId   = $sid;
    } else {
        $hashed = password_hash($newPass, PASSWORD_DEFAULT);
        $h_esc  = $conn->real_escape_string($hashed);

        $conn->query("UPDATE chmsu_user_accounts
                      SET chmsu_password = '$h_esc', is_active = 1, last_login = NOW()
                      WHERE chmsu_student_id = '$sid_esc'");
        $conn->query("UPDATE chmsu_password_resets
                      SET is_used = 1
                      WHERE student_id = '$sid_esc' AND is_used = 0");

        logActivity($conn, $sid, 'student', 'Logged in (after admin password reset)');
        $_SESSION['student'] = $sid;

        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            header("Location: index.php?view=home");
        }
        echo "<script>window.location.href = 'index.php?view=home';</script>";
        exit;
    }
}

// Re-fetch name if validation error needs to display it again
if ($showResetForm && $pendingStudentId !== '' && $pendingStudentName === '') {
    $sid_esc = $conn->real_escape_string($pendingStudentId);
    $nm = $conn->query("SELECT chmsu_full_name FROM chmsu_students_master
                        WHERE chmsu_student_id = '$sid_esc'")->fetch_assoc();
    if ($nm) $pendingStudentName = $nm['chmsu_full_name'];
}
?>

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
    <title>CHMSU E-Clearance System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            background: #e8f5e9;
            font-size: 14px;
        }
        
        .split-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* LEFT SIDE - DARK GREEN BACKGROUND */
        .left-side {
            flex: 1;
            background: #1b4d3e;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 30px;
        }
        
        .logo-container {
            text-align: center;
        }
        
        /* LOGO - ROUND, NO WHITE BACKGROUND, SMALLER */
        .logo-img {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }
        
        .university-name {
            font-size: 24px;
            font-weight: normal;
            color: white;
            text-align: center;
            margin-top: 25px;
            font-family: 'Times New Roman', Times, serif;
            letter-spacing: 1px;
        }
        
        .tagline {
            font-size: 14px;
            color: rgba(255,255,255,0.8);
            text-align: center;
            margin-top: 8px;
            font-family: 'Times New Roman', Times, serif;
        }
        
        /* RIGHT SIDE - PURE WHITE */
        .right-side {
            flex: 1;
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 30px;
        }
        
        .form-container {
            width: 100%;
            max-width: 380px;
        }
        
        .form-title {
            text-align: center;
            font-size: 32px;
            color: #1b4d3e;
            margin-bottom: 30px;
            font-family: 'Times New Roman', Times, serif;
            font-weight: normal;
            letter-spacing: 1px;
        }
        
        input, select {
            width: 100%;
            padding: 12px 0;
            margin: 15px 0;
            border: none;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
            font-family: 'Times New Roman', Times, serif;
            background: transparent;
            transition: all 0.3s ease;
        }
        
        select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23999' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>");
            background-repeat: no-repeat;
            background-position: right 0 center;
        }
        
        input:focus, select:focus {
            outline: none;
            border-bottom-color: #1b4d3e;
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: #1b4d3e;
            border: none;
            border-radius: 4px;
            color: white;
            font-size: 14px;
            font-weight: normal;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            margin-top: 15px;
            transition: all 0.3s ease;
        }
        
        button:hover {
            background: #2d6a4f;
        }
        
        .register-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }
        
        .register-link a {
            color: #1b4d3e;
            text-decoration: none;
            font-size: 13px;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }
        
        .register-link p {
            color: #666;
            font-size: 13px;
            margin-bottom: 5px;
        }
        
        /* Registration Form Styles */
        .register-container {
            width: 100%;
            max-width: 380px;
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-link a {
            color: #1b4d3e;
            text-decoration: none;
            font-size: 13px;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
        
        /* User Type Radio Styles */
        .user-type-selector {
            margin: 20px 0 10px 0;
        }
        
        .user-type-selector label {
            display: block;
            margin-bottom: 10px;
            color: #333;
            font-size: 13px;
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
            margin-bottom: 0;
        }
        
        .user-type-options input[type="radio"] {
            width: 16px;
            height: 16px;
            margin: 0;
            cursor: pointer;
        }
        
        .office-select-container {
            display: none;
        }
        
        .office-select-container.show {
            display: block;
        }
        
        .student-field, .office-field {
            transition: all 0.3s ease;
        }
        
        .password-wrapper {
            position: relative;
        }
        
        .password-wrapper input {
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
        
        /* Notification Styles */
        .notification {
            padding: 12px 15px;
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid;
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
            border-left-color: #e74c3c;
        }
        
        .notification-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #27ae60;
        }
        
        .notification i {
            font-size: 16px;
        }
        
        .close-notification {
            margin-left: auto;
            cursor: pointer;
            font-size: 18px;
            opacity: 0.7;
        }
        
        .close-notification:hover {
            opacity: 1;
        }
        
        .hidden {
            display: none;
        }
        
        .hint-text {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
            display: block;
        }
        
        @media (max-width: 800px) {
            .split-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<?php if(isset($_GET['register'])): ?>
    <!-- REGISTRATION VIEW (unchanged) -->
    <div class="split-container">
        <div class="left-side">
            <div class="logo-container">
                <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo" class="logo-img">
            </div>
            <div class="university-name">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>
            <div class="tagline">E-Clearance System</div>
        </div>
        
        <div class="right-side">
            <div class="register-container">
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
                        <input type="text" name="student_id" id="studentId" placeholder="Student ID">
                        <small class="hint-text">Enter the student ID provided by the registrar</small>
                    </div>
                    
                    <!-- Office Fields (hidden by default) -->
                    <div id="office-fields" style="display: none;">
                        <select name="office_name" id="officeName">
                            <option value="">Select an office</option>
                            <?php while($office = $availableOffices->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($office['office_name']); ?>">
                                    <?php echo htmlspecialchars($office['office_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="hint-text">Only offices that are not yet registered will appear here</small>
                        
                        <input type="text" name="office_username" id="officeUsername" placeholder="Choose a username">
                        <small class="hint-text">Choose any username you prefer for logging in</small>
                    </div>
                    
                    <!-- Common Fields -->
                    <input type="text" name="last_name" id="regLastName" placeholder="Last Name" required>
                    <input type="text" name="first_name" id="regFirstName" placeholder="First Name" required>
                    
                    <div class="password-wrapper">
                        <input type="password" name="password" id="regPassword" placeholder="Password" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword('regPassword', this)"></i>
                    </div>
                    <small class="hint-text">6+ characters, 1 uppercase letter, 1 number</small>
                    
                    <div class="password-wrapper">
                        <input type="password" name="confirm_password" id="regConfirmPassword" placeholder="Confirm Password" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword('regConfirmPassword', this)"></i>
                    </div>
                    
                    <div id="registerMatchError" style="color: #721c24; font-size: 12px; text-align: center; margin-top: 10px; display: none;">Passwords do not match!</div>
                    <button type="submit" name="register">Create Account</button>
                </form>
                
                <div class="back-link">
                    <a href="?page=login">Back to Login</a>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- LOGIN VIEW -->
    <div class="split-container">
        <div class="left-side">
            <div class="logo-container">
                <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo" class="logo-img">
            </div>
            <div class="university-name">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>
            <div class="tagline">E-Clearance System</div>
        </div>
        
        <div class="right-side">
            <div class="form-container">

                <?php if ($showResetForm): ?>
                    <!-- ================================================= -->
                    <!-- STEP 2: CREATE NEW PASSWORD (replaces login form) -->
                    <!-- ================================================= -->
                    <div class="form-title">Create New Password</div>

                    <p style="text-align:center; color:#666; font-size:13px; margin-bottom:16px;">
                        <?php echo htmlspecialchars($pendingStudentName ?: 'Student'); ?>
                    </p>

                    <?php if ($resetFlowError): ?>
                        <div class="notification notification-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?php echo htmlspecialchars($resetFlowError); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" onsubmit="return validateResetForm()">
                        <input type="hidden" name="complete_password_reset" value="1">
                        <input type="hidden" name="pending_student_id" value="<?php echo htmlspecialchars($pendingStudentId); ?>">

                        <div class="password-wrapper">
                            <input type="text"
                                   value="<?php echo htmlspecialchars($pendingStudentId); ?>"
                                   readonly
                                   style="background:#f0f2f5; cursor:not-allowed; color:#333;"
                                   placeholder="Student ID">
                        </div>

                        <div class="password-wrapper">
                            <input type="password" name="new_password" id="resetNewPassword"
                                   minlength="8" required
                                   placeholder="New Password (min 8 chars, letters &amp; numbers)"
                                   autocomplete="new-password">
                            <i class="fas fa-eye toggle-password" onclick="togglePassword('resetNewPassword', this)"></i>
                        </div>

                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" id="resetConfirmPassword"
                                   minlength="8" required
                                   placeholder="Confirm New Password"
                                   autocomplete="new-password">
                            <i class="fas fa-eye toggle-password" onclick="togglePassword('resetConfirmPassword', this)"></i>
                        </div>

                        <small class="hint-text">Must be at least 8 characters with both letters and numbers.</small>

                        <button type="submit">Set Password &amp; Log In</button>
                    </form>

                <?php else: ?>
                    <!-- ================================================= -->
                    <!-- STEP 1: NORMAL LOGIN FORM (existing, unchanged)    -->
                    <!-- ================================================= -->
                    <div class="form-title">Login</div>
                    
                    <!-- Notifications -->
                    <?php if(isset($error) && $error != '' && $error != 'password_mismatch' && $error != 'registration_success'): ?>
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
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="unified_login">
                        <!-- NEW: triggers the pending-reset detection -->
                        <input type="hidden" name="check_pending_reset" value="1">

                        <div class="password-wrapper">
                            <input type="text" name="username" placeholder="Username / Student ID" required>
                        </div>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="loginPassword" placeholder="Password" required>
                            <i class="fas fa-eye toggle-password" onclick="togglePassword('loginPassword', this)"></i>
                        </div>
                        <button type="submit" name="login">Login</button>
                    </form>
                    
                    <div class="register-link">
                        <p>No account yet?</p>
                        <a href="?page=login&register=1">Create Account First</a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
<?php endif; ?>

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
            if (studentIdInput) studentIdInput.required = true;
            if (officeNameSelect) officeNameSelect.required = false;
            if (officeUsernameInput) officeUsernameInput.required = false;
        } else {
            studentFields.style.display = 'none';
            officeFields.style.display = 'block';
            if (studentIdInput) studentIdInput.required = false;
            if (officeNameSelect) officeNameSelect.required = true;
            if (officeUsernameInput) officeUsernameInput.required = true;
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
            errorDiv.style.display = 'block';
            return false;
        }
        errorDiv.style.display = 'none';
        
        // Create hidden username field
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
            
            // Add selected office name
            const officeNameField = document.createElement('input');
            officeNameField.type = 'hidden';
            officeNameField.name = 'selected_office';
            officeNameField.value = officeName;
            document.getElementById('registerForm').appendChild(officeNameField);
        }
        
        document.getElementById('registerForm').appendChild(usernameField);
        return true;
    }

    function validateResetForm() {
        var p1 = document.getElementById('resetNewPassword').value;
        var p2 = document.getElementById('resetConfirmPassword').value;
        if (p1.length < 8) {
            alert('Password must be at least 8 characters.');
            return false;
        }
        if (!/[A-Za-z]/.test(p1) || !/[0-9]/.test(p1)) {
            alert('Password must contain both letters and numbers.');
            return false;
        }
        if (p1 !== p2) {
            alert('Passwords do not match.');
            return false;
        }
        return true;
    }
    
    // Auto-hide notifications after 5 seconds
    setTimeout(function() {
        const notifications = document.querySelectorAll('.notification');
        notifications.forEach(function(notification) {
            notification.style.opacity = '0';
            setTimeout(function() {
                notification.style.display = 'none';
            }, 300);
        });
    }, 5000);
</script>

</body>
</html>