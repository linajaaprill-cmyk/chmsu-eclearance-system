<?php
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentSection = 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Registrar Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; background: #f5f5f5; font-size: 14px; }
        
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
        .header-logo img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 20px; font-weight: normal; }
        .header-title p { font-size: 11px; opacity: 0.8; margin-top: 3px; }
        .dark-mode-toggle {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 6px 12px;
            cursor: pointer;
            margin-left: auto;
        }
        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 15px;
            border: none;
            cursor: pointer;
        }
        
        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }
        .registrar-sidebar {
            width: 240px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .registrar-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .registrar-sidebar .sidebar-menu { list-style: none; padding: 0; }
        .registrar-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
        }
        .registrar-sidebar .sidebar-menu a:hover,
        .registrar-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
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
        .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }
        
        .office-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .office-card {
            background: white;
            border: 1px solid #ddd;
            padding: 20px;
            text-align: center;
        }
        .office-card h4 { color: #1b4d3e; font-size: 14px; font-weight: normal; margin-bottom: 10px; }
        .office-card .count { font-size: 28px; color: #1b4d3e; }
        
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
        .content-card-body { padding: 15px; }
        
        .activity-log { max-height: 400px; overflow-y: auto; }
        .activity-item { padding: 10px; border-bottom: 1px solid #eee; font-size: 12px; }
        .activity-item .time { color: #666; font-size: 11px; margin-top: 5px; }
        .activity-item .user { font-weight: bold; color: #1b4d3e; }
        
        @media (max-width: 768px) {
            .registrar-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .office-grid { grid-template-columns: repeat(2, 1fr); }
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
        <p>CLEARANCE SYSTEM | Registrar Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="registrar-sidebar">
        <div class="sidebar-header">
            <h3>Registrar Portal</h3>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=dashboard" class="active">Dashboard</a></li>
            <li><a href="?section=masterlist">Master List</a></li>
            <li><a href="?section=clearance">Clearance</a></li>
            <li><a href="?section=requirements">Requirements</a></li>
            <li><a href="?section=submissions">Submissions</a></li>
            <li><a href="?section=reports">Reports</a></li>
            <li><a href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
        <div style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Registrar Dashboard</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="office-grid">
            <?php
            $totalStudents = $conn->query("SELECT COUNT(*) as c FROM chmsu_user_accounts")->fetch_assoc()['c'];
            $totalRequirements = $conn->query("SELECT COUNT(*) as c FROM chmsu_requirements WHERE chmsu_office='Registrar'")->fetch_assoc()['c'];
            $totalSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='Registrar'")->fetch_assoc()['c'];
            $pendingSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='Registrar' AND s.chmsu_status='Submitted'")->fetch_assoc()['c'];
            ?>
            <div class="office-card">
                <h4>Total Students</h4>
                <div class="count"><?php echo $totalStudents; ?></div>
            </div>
            <div class="office-card">
                <h4>Requirements</h4>
                <div class="count"><?php echo $totalRequirements; ?></div>
            </div>
            <div class="office-card">
                <h4>Submissions</h4>
                <div class="count"><?php echo $totalSubmissions; ?></div>
            </div>
            <div class="office-card">
                <h4>Pending</h4>
                <div class="count"><?php echo $pendingSubmissions; ?></div>
            </div>
        </div>
        
        <div class="content-card">
            <div class="content-card-header">Recent Activity</div>
            <div class="content-card-body">
                <div class="activity-log">
                    <?php
                    $logs = $conn->query("SELECT * FROM chmsu_activity_log WHERE user_type='office' AND user_id='Registrar' ORDER BY created_at DESC LIMIT 20");
                    while ($log = $logs->fetch_assoc()):
                    ?>
                    <div class="activity-item">
                        <div><span class="user"><?php echo htmlspecialchars($log['user_id']); ?></span> - <?php echo htmlspecialchars($log['action']); ?></div>
                        <div class="time"><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></div>
                    </div>
                    <?php endwhile; ?>
                </div>
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