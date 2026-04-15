<div class="header">
    <div class="header-logo" style="display: none;"></div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Student Registration</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
</div>

<div class="container">
    <div class="card">
        <h2>Student Registration</h2>
        <?php if($error && $error != 'password_mismatch'): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST" id="regForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="register_student">
            <div class="form-group">
                <label>Student ID</label>
                <input type="text" name="student_id" placeholder="Enter your student ID" required>
                <small>Enter the student ID provided by the registrar</small>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="regPass" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('regPass',this)"></i>
                </div>
                <small>6+ characters, 1 uppercase letter, 1 number</small>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="regConfirm" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('regConfirm',this)"></i>
                </div>
            </div>
            <div id="matchError" class="error hidden">Passwords do not match!</div>
            <button type="submit" class="btn btn-primary btn-block">Register</button>
        </form>
        <a href="?page=student" class="btn btn-back btn-block">Back</a>
    </div>
</div>