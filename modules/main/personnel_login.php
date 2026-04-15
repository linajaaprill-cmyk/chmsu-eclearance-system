<div class="header">
    <div class="header-logo" style="display: none;"></div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Personnel Login</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
</div>

<div class="container">
    <div class="card">
        <h2>Personnel Login</h2>
        <?php if($error && $error != 'password_mismatch'): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="login_office">
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
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="officePass" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('officePass',this)"></i>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>
        <a href="?page=personnel" class="btn btn-back btn-block">Back</a>
    </div>
</div>