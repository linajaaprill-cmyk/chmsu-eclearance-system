<?php
// File: modules/registrar/archived.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['office']) || $_SESSION['office'] != 'Registrar') {
    header("Location: ../index.php");
    exit;
}

$office = $_SESSION['office'];
$error = '';
$success = '';
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

if (isset($_GET['restore']) && isset($_GET['id'])) {
    $student_id = sanitize($_GET['id']);
    $conn->query("UPDATE chmsu_students_master SET is_archived = 0, archived_at = NULL, graduation_date = NULL WHERE chmsu_student_id='$student_id'");
    $success = "Student restored!";
}

if (isset($_GET['delete']) && isset($_GET['id'])) {
    $student_id = sanitize($_GET['id']);
    $check = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'");
    if ($check->num_rows > 0) {
        $error = "Cannot delete - has active account";
    } else {
        $conn->query("DELETE FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
        $success = "Student deleted!";
    }
}

$school_years = $conn->query("SELECT DISTINCT YEAR(graduation_date) as graduation_year, 
                              COUNT(*) as student_count
                              FROM chmsu_students_master 
                              WHERE is_archived = 1 
                              AND chmsu_year = '4' 
                              AND is_irregular = 0
                              AND graduation_date IS NOT NULL
                              GROUP BY YEAR(graduation_date)
                              ORDER BY graduation_year DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU - Graduated Students Archive</title>
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
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .registrar-sidebar::-webkit-scrollbar { width: 5px; }
        .registrar-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .registrar-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .registrar-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .registrar-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .registrar-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
        }
        .registrar-sidebar .sidebar-menu a:hover,
        .registrar-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
        .main-content {
            flex: 1;
            margin-left: 260px;
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
        
        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: rgba(27, 77, 62, 0.08);
            border: 1px solid rgba(27, 77, 62, 0.2);
            padding: 15px 25px;
            text-align: center;
            min-width: 180px;
        }
        .stat-card .number { font-size: 28px; font-weight: bold; color: #1b4d3e; }
        .stat-card .label { font-size: 12px; color: #666; margin-top: 5px; }
        
        /* SMALL TRANSPARENT RECTANGLE CARDS - NO SQUARES */
        .school-years-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .school-year-item {
            background: transparent;
            border: 1px solid #ddd;
            padding: 10px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .school-year-item:hover {
            background: rgba(27, 77, 62, 0.05);
            border-left: 3px solid #f1c40f;
            transform: translateX(3px);
        }
        .school-year-name {
            font-size: 14px;
            color: #1b4d3e;
        }
        .school-year-count {
            background: #1b4d3e;
            color: white;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        
        .error { background: #f8d7da; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .stat-card { background: rgba(241, 196, 15, 0.1); border-color: rgba(241, 196, 15, 0.3); }
        body.dark-mode .stat-card .number { color: #f1c40f; }
        body.dark-mode .stat-card .label { color: #ccc; }
        body.dark-mode .school-year-item { border-color: #444; }
        body.dark-mode .school-year-item:hover { background: rgba(241, 196, 15, 0.1); }
        body.dark-mode .school-year-name { color: #f1c40f; }
        
        @media (max-width: 768px) {
            .registrar-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
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
    <!-- ============================================ -->
    <!-- REGISTRAR SIDEBAR - UPDATED TO MATCH DASHBOARD -->
    <!-- ============================================ -->
    <div class="registrar-sidebar">
        <div class="sidebar-header">
            <h3>Registrar Portal</h3>
            <p>Student Records Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?section=masterlist"><i class="fas fa-users"></i> Master List</a></li>
            <li><a href="?section=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?section=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?section=archived" class="active"><i class="fas fa-archive"></i> Archive</a></li>
            <li><a href="?section=promotion"><i class="fas fa-arrow-up"></i> Student Promotion</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
        <div style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f;"></div>
            </div>
        </div>
    </div>
    <!-- ============================================ -->
    <!-- END OF SIDEBAR                               -->
    <!-- ============================================ -->
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Graduated Students Archive</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php
        $totalGraduated = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 1 AND chmsu_year = '4' AND is_irregular = 0")->fetch_assoc()['c'];
        ?>
        <div class="stats-bar">
            <div class="stat-card">
                <div class="number"><?php echo $totalGraduated; ?></div>
                <div class="label">Total Graduated</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $school_years->num_rows; ?></div>
                <div class="label">School Years</div>
            </div>
        </div>
        
        <?php if($school_years && $school_years->num_rows > 0): ?>
            <div class="school-years-list">
                <?php while($year = $school_years->fetch_assoc()): 
                    $grad_year = $year['graduation_year'];
                    $student_count = $year['student_count'];
                ?>
                <div class="school-year-item" onclick="window.location.href='?section=archived&view_year=<?php echo $grad_year; ?>'">
                    <span class="school-year-name">School Year <?php echo $grad_year; ?> - <?php echo $grad_year + 1; ?></span>
                    <span class="school-year-count"><?php echo $student_count; ?> graduates</span>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="stat-card" style="width:100%; text-align:center; padding:30px;">
                <div class="number">0</div>
                <div class="label">No graduates yet</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
}
if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
function confirmLogout() { if(confirm('Logout?')) window.location.href = '?logout=1'; }
</script>
</body>
</html>