<?php
$admin = $_SESSION['admin'];
$adminSection = 'activity';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Activity Log</title>
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
        
        .search-box {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            margin-bottom: 15px;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .btn-excel {
            background: #27ae60;
            color: white;
            padding: 6px 12px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .btn-word {
            background: #1b4d3e;
            color: white;
            padding: 6px 12px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .plain-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        .plain-table th,
        .plain-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .plain-table th {
            background: #f8f9fa;
            font-weight: normal;
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
            <li><a href="?adminsection=school_years">School Years</a></li>
            <li><a href="?adminsection=semesters">Semesters</a></li>
            <li><a href="?adminsection=activity" class="active">Activity Log</a></li>
            <li><a href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>System Activity Log</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <div class="content-card">
            <div class="content-card-header">Complete Activity Log</div>
            <div class="content-card-body">
                <input type="text" class="search-box" id="activitySearch" placeholder="Search by user or action..." onkeyup="searchActivity()">
                
                <div class="export-buttons">
                    <button class="btn-excel" onclick="exportActivity('excel')">Export Excel</button>
                    <button class="btn-word" onclick="exportActivity('word')">Export Word</button>
                </div>
                
                <div class="table-responsive">
                    <table class="plain-table" id="activityTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Type</th>
                                <th>Action</th>
                                <th>IP Address</th>
                                <th>Date/Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $logs = $conn->query("SELECT * FROM chmsu_activity_log ORDER BY created_at DESC");
                            if ($logs->num_rows > 0):
                                while ($log = $logs->fetch_assoc()):
                            ?>
                            <tr data-search="<?php echo strtolower($log['user_id'].' '.$log['action']); ?>">
                                <td><?php echo htmlspecialchars($log['user_id']); ?></td>
                                <td><?php echo $log['user_type']; ?></td>
                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                <td><?php echo $log['ip_address']; ?></td>
                                <td><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="5" style="text-align:center;">No activity records found.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
    
    function searchActivity() {
        const search = document.getElementById('activitySearch')?.value.toLowerCase() || '';
        const rows = document.querySelectorAll('#activityTable tbody tr');
        rows.forEach(row => {
            const searchData = row.dataset.search;
            if (searchData && searchData.includes(search)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    function exportActivity(type) {
        window.location.href = '?export_activity=' + type;
    }
</script>

</body>
</html>