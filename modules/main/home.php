<div class="header">
    <div class="header-logo" style="display: none;"></div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
</div>

<div class="container">
    <div class="card">
        <h2>System Access</h2>
        <?php if($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <a href="?page=student" class="btn btn-primary btn-block">
            <i class="fas fa-user-graduate"></i> Student Portal
        </a>
        <a href="?page=personnel" class="btn btn-secondary btn-block">
            <i class="fas fa-building"></i> Personnel Portal
        </a>
        <a href="?admin=1" class="btn btn-warning btn-block">
            <i class="fas fa-user-cog"></i> Admin Portal
        </a>
    </div>
</div>