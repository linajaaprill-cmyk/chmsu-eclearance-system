<?php
$admin = $_SESSION['admin'];
$adminSection = 'school_years';

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_school_years (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_year VARCHAR(20) NOT NULL,
    is_current TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Handle School Year actions
if(isset($_POST['action'])) {
    if($_POST['action'] == 'add') {
        $school_year = sanitize($_POST['school_year']);
        $conn->query("INSERT INTO chmsu_school_years (school_year) VALUES ('$school_year')");
        logActivity($conn, $admin, 'admin', "Added school year: $school_year");
        $success = "School year added successfully!";
    }
    elseif($_POST['action'] == 'delete') {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM chmsu_school_years WHERE id = $id");
        $success = "School year deleted successfully!";
    }
    elseif($_POST['action'] == 'set_current') {
        $id = intval($_POST['id']);
        $conn->query("UPDATE chmsu_school_years SET is_current = 0");
        $conn->query("UPDATE chmsu_school_years SET is_current = 1 WHERE id = $id");
        logActivity($conn, $admin, 'admin', "Set current school year");
        $success = "Current school year updated!";
    }
    elseif($_POST['action'] == 'promote_students') {
        // Promote all students to next year level
        $conn->query("UPDATE chmsu_students_master SET chmsu_year = chmsu_year + 1 WHERE chmsu_year < 4");
        logActivity($conn, $admin, 'admin', 'Promoted all students to next year level');
        $success = "All students promoted to next year level!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Manage School Years</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            background: #f5f5f5;
            font-size: 14px;
        }
        
        .header {
            background: #1b4d3e;
            color: white;
            padding: 15px 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-logo img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .header-title h1 {
            font-size: 20px;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .header-title p {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 3px;
        }
        
        .dark-mode-toggle {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 6px 12px;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            margin-left: auto;
        }
        
        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 15px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .dashboard-wrapper {
            display: flex;
            min-height: calc(100vh - 73px);
        }
        
        .admin-sidebar {
            width: 240px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        
        .admin-sidebar .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #2d6a4f;
        }
        
        .admin-sidebar .sidebar-header h3 {
            font-size: 16px;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .admin-sidebar .sidebar-header p {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
        }
        
        .admin-sidebar .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .admin-sidebar .sidebar-menu li {
            border-bottom: 1px solid #2d6a4f;
        }
        
        .admin-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .admin-sidebar .sidebar-menu a:hover,
        .admin-sidebar .sidebar-menu a.active {
            background: #f1c40f;
            color: #000000;
        }
        
        .main-content {
            flex: 1;
            margin-left: 240px;
            padding: 20px;
            background: #f5f5f5;
            min-height: calc(100vh - 73px);
        }
        
        .dashboard-header-bar {
            background: white;
            padding: 15px 20px;
            border: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .dashboard-header-bar h2 {
            font-size: 18px;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
            color: #1b4d3e;
        }
        
        .content-card {
            background: white;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }
        
        .content-card-header {
            background: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
            font-weight: normal;
        }
        
        .content-card-body {
            padding: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-size: 12px;
        }
        
        input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }
        
        input:focus {
            outline: none;
            border-color: #1b4d3e;
        }
        
        .btn {
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
        }
        
        .btn-primary {
            background: #1b4d3e;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2d6a4f;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-warning:hover {
            background: #e67e22;
        }
        
        .item-list {
            list-style: none;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .item-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            margin-bottom: 15px;
            border-left: 3px solid #e74c3c;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            margin-bottom: 15px;
            border-left: 3px solid #27ae60;
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .promote-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        @media (max-width: 768px) {
            .admin-sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Admin Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="admin-sidebar">
        <div class="sidebar-header">
            <h3>Admin: <?php echo htmlspecialchars($admin); ?></h3>
            <p>System Administrator</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?adminsection=dashboard">Dashboard</a></li>
            <li><a href="?adminsection=courses">Courses</a></li>
            <li><a href="?adminsection=offices">Offices</a></li>
            <li><a href="?adminsection=sections">Sections</a></li>
            <li><a href="?adminsection=school_years" class="active">School Years</a></li>
            <li><a href="?adminsection=semesters">Semesters</a></li>
            <li><a href="?adminsection=activity">Activity Log</a></li>
            <li><a href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Manage School Years</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="content-card">
            <div class="content-card-header">Add New School Year</div>
            <div class="content-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="filter-row">
                        <div class="form-group" style="flex:1;">
                            <label>School Year</label>
                            <input type="text" name="school_year" placeholder="2024-2025" required>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">Add School Year</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="content-card">
            <div class="content-card-header">Student Promotion</div>
            <div class="content-card-body">
                <form method="POST" onsubmit="return confirm('This will promote ALL students to the next year level. This action cannot be undone. Continue?')">
                    <input type="hidden" name="action" value="promote_students">
                    <button type="submit" class="btn btn-warning">Promote All Students to Next Year</button>
                </form>
                <p style="font-size: 11px; color: #666; margin-top: 10px;">Note: 4th year students will remain as 4th year.</p>
            </div>
        </div>
        
        <div class="content-card">
            <div class="content-card-header">School Year List</div>
            <div class="content-card-body">
                <ul class="item-list">
                    <li style="background: #f0f0f0; font-weight: bold;">
                        <span>School Year</span>
                        <span>Status</span>
                        <span>Action</span>
                    </li>
                    <?php 
                    $school_years = $conn->query("SELECT * FROM chmsu_school_years ORDER BY id DESC");
                    while($sy = $school_years->fetch_assoc()):
                    ?>
                    <li>
                        <span><?php echo htmlspecialchars($sy['school_year']); ?></span>
                        <span><?php echo $sy['is_current'] ? 'Current' : ''; ?></span>
                        <span>
                            <?php if(!$sy['is_current']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="set_current">
                                <input type="hidden" name="id" value="<?php echo $sy['id']; ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Set Current</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $sy['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this school year?')">Delete</button>
                            </form>
                        </span>
                    </li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        const isDarkMode = document.body.classList.contains('dark-mode');
        localStorage.setItem('darkMode', isDarkMode);
    }
    
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
    }
    
    function confirmLogout() {
        if(confirm('Are you sure you want to logout?')) {
            window.location.href = '?logout=1';
        }
    }
</script>

</body>
</html>