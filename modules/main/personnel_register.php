<div class="header">
    <div class="header-logo" style="display: none;"></div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Office Registration</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
</div>

<div class="container">
    <div class="card">
        <h2>Register Office</h2>
        <?php if($error && $error != 'password_mismatch'): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST" id="officeRegForm" onsubmit="return validateOfficeForm()">
            <input type="hidden" name="action" value="register_office">
            <div class="form-group">
                <label>Office</label>
                <select name="role" required>
                    <option value="">Select Office</option>
                    <?php 
                    $offices = $conn->query("SELECT * FROM chmsu_offices ORDER BY office_name");
                    while($o = $offices->fetch_assoc()): 
                    ?>
                    <option value="<?php echo $o['office_name']; ?>"><?php echo $o['office_name']; ?></option>
                    <?php endwhile; ?>
                </select>
                <small>Select the office you are registering for</small>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="officeRegPass" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('officeRegPass',this)"></i>
                </div>
                <small>6+ characters, 1 uppercase letter, 1 number</small>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="officeRegConfirm" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('officeRegConfirm',this)"></i>
                </div>
            </div>
            <div id="officeMatchError" class="error hidden">Passwords do not match!</div>
            <button type="submit" class="btn btn-primary btn-block">Register Office</button>
        </form>
        <a href="?page=personnel" class="btn btn-back btn-block">Back</a>
    </div>
</div>