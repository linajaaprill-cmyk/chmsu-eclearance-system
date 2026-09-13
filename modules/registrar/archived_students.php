<?php
// File: modules/registrar/archived_students.php
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

$view_year = isset($_GET['view_year']) ? intval($_GET['view_year']) : 0;
if (!$view_year) {
    header("Location: ?section=archived");
    exit;
}

$filter_course = isset($_GET['filter_course']) ? $_GET['filter_course'] : '';
$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';
$filter_section = isset($_GET['filter_section']) ? $_GET['filter_section'] : '';

if (isset($_GET['restore']) && isset($_GET['id'])) {
    $student_id = sanitize($_GET['id']);
    $conn->query("UPDATE chmsu_students_master SET is_archived = 0, archived_at = NULL, graduation_date = NULL WHERE chmsu_student_id='$student_id'");
    $success = "Student restored from archive!";
}

if (isset($_GET['delete']) && isset($_GET['id'])) {
    $student_id = sanitize($_GET['id']);
    $check = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'");
    if ($check->num_rows > 0) {
        $error = "Cannot delete student with active account. Delete account first.";
    } else {
        $conn->query("DELETE FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
        $success = "Student permanently deleted!";
    }
}

$where = "is_archived = 1 AND chmsu_year = '4' AND is_irregular = 0 AND YEAR(graduation_date) = '$view_year'";
if ($filter_course) $where .= " AND chmsu_course = '$filter_course'";
if ($filter_year) $where .= " AND chmsu_year = '$filter_year'";
if ($filter_section) $where .= " AND chmsu_section = '$filter_section'";

$graduates = $conn->query("SELECT * FROM chmsu_students_master WHERE $where ORDER BY chmsu_course, chmsu_year, chmsu_section, chmsu_last_name");
$total_count = $graduates->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU - Graduates <?php echo $view_year; ?>-<?php echo $view_year+1; ?></title>
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
            flex-wrap: wrap;
            gap: 10px;
        }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }
        
        .back-button {
            background: #7f8c8d;
            color: white;
            padding: 6px 12px;
            text-decoration: none;
            font-size: 12px;
        }
        
        .content-card {
            background: white;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }
        .content-card-header {
            background: #f8f9fa;
            padding: 10px 15px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
        }
        .content-card-body { padding: 15px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #333; font-size: 12px; }
        select, input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ddd;
            font-size: 13px;
        }
        
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        
        .btn { padding: 6px 12px; border: none; cursor: pointer; font-size: 12px; }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
        
        .plain-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .plain-table th, .plain-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .plain-table th { background: #f8f9fa; }
        
        .badge-graduated { background: #27ae60; color: white; padding: 3px 8px; border-radius: 3px; font-size: 10px; display: inline-block; }
        
        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .plain-table th { background: #2c2c2c; color: #fff; border-color: #444; }
        body.dark-mode .plain-table td { border-color: #333; color: #fff; }
        body.dark-mode select, body.dark-mode input { background: #2c2c2c; border-color: #444; color: #fff; }
        
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
    <div class="registrar-sidebar">
        <div class="sidebar-header">
            <h3>Registrar Portal</h3>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=dashboard">Dashboard</a></li>
            <li><a href="?section=masterlist">Master List</a></li>
            <li><a href="?section=clearance">Clearance</a></li>
            <li><a href="?section=requirements">Requirements</a></li>
            <li><a href="?section=submissions">Submissions</a></li>
            <li><a href="?section=reports">Reports</a></li>
            <li><a href="?section=archived">Archive</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()">Logout</a>
            </li>
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
            <h2>School Year <?php echo $view_year; ?> - <?php echo $view_year + 1; ?> Graduates</h2>
            <a href="?section=archived" class="back-button"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="content-card">
            <div class="content-card-header">Filter</div>
            <div class="content-card-body">
                <form method="GET" action="">
                    <input type="hidden" name="section" value="archived">
                    <input type="hidden" name="view_year" value="<?php echo $view_year; ?>">
                    <div class="filter-row">
                        <div class="form-group" style="flex:1;">
                            <label>Course</label>
                            <select name="filter_course" onchange="this.form.submit()">
                                <option value="">All</option>
                                <?php 
                                $courses = $conn->query("SELECT DISTINCT chmsu_course FROM chmsu_students_master WHERE is_archived=1 ORDER BY chmsu_course");
                                while($c = $courses->fetch_assoc()): 
                                ?>
                                <option value="<?php echo $c['chmsu_course']; ?>" <?php echo $filter_course==$c['chmsu_course']?'selected':''; ?>><?php echo $c['chmsu_course']; ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0 0 100px;">
                            <label>Year</label>
                            <select name="filter_year" onchange="this.form.submit()">
                                <option value="">All</option>
                                <option value="1" <?php echo $filter_year=='1'?'selected':''; ?>>1st</option>
                                <option value="2" <?php echo $filter_year=='2'?'selected':''; ?>>2nd</option>
                                <option value="3" <?php echo $filter_year=='3'?'selected':''; ?>>3rd</option>
                                <option value="4" <?php echo $filter_year=='4'?'selected':''; ?>>4th</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0 0 100px;">
                            <label>Section</label>
                            <input type="text" name="filter_section" placeholder="Section" value="<?php echo htmlspecialchars($filter_section); ?>" onchange="this.form.submit()">
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <a href="?section=archived&view_year=<?php echo $view_year; ?>" class="btn btn-primary">Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="content-card">
            <div class="content-card-header">Graduates (<?php echo $total_count; ?>)</div>
            <div class="content-card-body">
                <div class="table-responsive">
                    <table class="plain-table">
                        <thead>
                            <tr><th>ID</th><th>Last Name</th><th>First Name</th><th>Course</th><th>Year</th><th>Section</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if($graduates && $graduates->num_rows > 0): ?>
                                <?php while($student = $graduates->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $student['chmsu_student_id']; ?></a></td>
                                    <td><?php echo htmlspecialchars($student['chmsu_last_name']); ?></a></td>
                                    <td><?php echo htmlspecialchars($student['chmsu_first_name']); ?></a></td>
                                    <td><?php echo $student['chmsu_course']; ?></a></td>
                                    <td><?php echo $student['chmsu_year']; ?>th</a></td>
                                    <td><?php echo htmlspecialchars($student['chmsu_section']); ?></a></td>
                                    <td>
                                        <a href="?section=archived&view_year=<?php echo $view_year; ?>&restore=1&id=<?php echo $student['chmsu_student_id']; ?>&filter_course=<?php echo urlencode($filter_course); ?>&filter_year=<?php echo $filter_year; ?>&filter_section=<?php echo urlencode($filter_section); ?>" class="btn btn-primary btn-sm" onclick="return confirm('Restore this student?')">Restore</a>
                                        <a href="?section=archived&view_year=<?php echo $view_year; ?>&delete=1&id=<?php echo $student['chmsu_student_id']; ?>&filter_course=<?php echo urlencode($filter_course); ?>&filter_year=<?php echo $filter_year; ?>&filter_section=<?php echo urlencode($filter_section); ?>" class="btn btn-danger btn-sm" onclick="return confirm('Permanently delete this student?')">Delete</a>
                                    </a>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align:center;">No graduates found.</a></td><?php endif; ?>
                        </tbody>
                    </div>
                </div>
            </div>
        </div>
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