<div class="header">
    <div class="header-logo" style="display: none;"></div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Student Login</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
</div>

<div class="container">
    <div class="card">
        <h2>Student Login</h2>
        <?php if($error && $error != 'password_mismatch'): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="login_student">
            <div class="form-group">
                <label>Student ID</label>
                <input type="text" name="student_id" placeholder="Enter your student ID" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="loginPass" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('loginPass',this)"></i>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>
        <a href="?page=student" class="btn btn-back btn-block">Back</a>
    </div>
</div>