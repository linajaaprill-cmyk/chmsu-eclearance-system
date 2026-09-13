<?php
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
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

// Get filter values
$filter_course = isset($_GET['filter_course']) ? $_GET['filter_course'] : '';
$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';
$filter_section = isset($_GET['filter_section']) ? $_GET['filter_section'] : '';
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';

// Get data
$totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];

$where = array();
$where[] = "m.is_archived = 0";
if (!empty($filter_course)) $where[] = "m.chmsu_course = '" . $conn->real_escape_string($filter_course) . "'";
if (!empty($filter_year)) $where[] = "m.chmsu_year = '" . $conn->real_escape_string($filter_year) . "'";
if (!empty($filter_section)) $where[] = "m.chmsu_section = '" . $conn->real_escape_string($filter_section) . "'";
if ($filter_type == 'regular') $where[] = "m.is_irregular = 0";
if ($filter_type == 'irregular') $where[] = "m.is_irregular = 1";

$where_sql = "WHERE " . implode(" AND ", $where);

$query = "SELECT 
            m.chmsu_student_id,
            CONCAT(m.chmsu_last_name, ', ', m.chmsu_first_name, ' ', m.chmsu_middle_name) as student_name,
            m.chmsu_year as year,
            m.chmsu_section as section,
            m.chmsu_course as course,
            m.is_irregular,
            (SELECT COUNT(DISTINCT r.chmsu_office) 
             FROM chmsu_submissions s 
             JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id 
             WHERE s.chmsu_student_id = m.chmsu_student_id 
             AND s.chmsu_status = 'Approved') as offices_completed
          FROM chmsu_students_master m
          $where_sql
          ORDER BY 
            m.chmsu_course ASC,
            CAST(m.chmsu_year AS UNSIGNED) ASC,
            m.chmsu_section ASC,
            m.chmsu_last_name ASC";

$result = $conn->query($query);
$students = array();
$totalApproved = 0;
$totalPending = 0;
$totalRegular = 0;
$totalIrregular = 0;

while ($row = $result->fetch_assoc()) {
    $officesCompleted = $row['offices_completed'] ?: 0;
    
    if ($officesCompleted >= $totalOffices) {
        $clearanceStatus = 'Approved';
        $totalApproved++;
    } else {
        $clearanceStatus = 'Pending';
        $totalPending++;
    }
    
    if ($filter_status == 'approved' && $clearanceStatus != 'Approved') continue;
    if ($filter_status == 'pending' && $clearanceStatus != 'Pending') continue;
    
    $studentType = ($row['is_irregular'] == 1) ? 'Irregular' : 'Regular';
    if ($studentType == 'Regular') {
        $totalRegular++;
    } else {
        $totalIrregular++;
    }
    
    $progress = $officesCompleted . ' / ' . $totalOffices;
    
    $yearDisplay = '';
    switch($row['year']) {
        case '1': $yearDisplay = '1st'; break;
        case '2': $yearDisplay = '2nd'; break;
        case '3': $yearDisplay = '3rd'; break;
        case '4': $yearDisplay = '4th'; break;
        default: $yearDisplay = $row['year'] . 'th';
    }
    
    $students[] = array(
        'name' => $row['student_name'],
        'year' => $yearDisplay,
        'section' => $row['section'],
        'course' => $row['course'],
        'type' => $studentType,
        'status' => $clearanceStatus,
        'progress' => $progress
    );
}

$totalStudents = count($students);

// ============================================
// EXPORT TO EXCEL
// ============================================
if (isset($_GET['export_report']) && $_GET['export_report'] == 'excel') {
    while (ob_get_level()) { ob_end_clean(); }
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Clearance_Completion_Report_' . date('Ymd') . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><title>Clearance Completion Report</title>';
    echo '<style>';
    echo 'body { font-family: "Times New Roman", Times, serif; margin: 20px; }';
    echo '.header { text-align: center; margin-bottom: 20px; }';
    echo '.university { font-size: 18pt; font-weight: bold; color: #1b4d3e; }';
    echo '.address { font-size: 10pt; color: #555; }';
    echo '.title { font-size: 16pt; font-weight: bold; margin-top: 15px; }';
    echo 'th { background: #1b4d3e; color: white; padding: 10px; border: 1px solid #2d6a4f; }';
    echo 'td { padding: 8px; border: 1px solid #ddd; }';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo '</style></head><body>';
    
    echo '<div class="header">';
    echo '<div class="university">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>';
    echo '<div class="address">Talisay City, Negros Occidental, Philippines</div>';
    echo '<div class="title">CLEARANCE COMPLETION REPORT</div>';
    echo '<div>Generated on: ' . date('F d, Y h:i A') . '</div>';
    echo '</div>';
    
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
    echo '<tr>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Student Name</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Year</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Section</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Course</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Student Type</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Clearance Status</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Clearance Progress</th>';
    echo '</tr>';
    
    foreach ($students as $row) {
        $statusStyle = ($row['status'] == 'Approved') ? 'color:green;font-weight:bold' : 'color:red;font-weight:bold';
        echo '<tr>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['name']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['year']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['section']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['course']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['type']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px; ' . $statusStyle . '">' . htmlspecialchars($row['status']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><strong>' . htmlspecialchars($row['progress']) . '</strong></td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '<br><br><strong>SUMMARY REPORT</strong><br>';
    echo 'Total Students: ' . $totalStudents . '<br>';
    echo 'Approved (Completed Clearance): ' . $totalApproved . '<br>';
    echo 'Pending (In Progress): ' . $totalPending . '<br>';
    echo 'Regular Students: ' . $totalRegular . '<br>';
    echo 'Irregular Students: ' . $totalIrregular . '<br>';
    echo 'Clearance Completion Rate: ' . ($totalStudents > 0 ? round(($totalApproved / $totalStudents) * 100, 2) : 0) . '%';
    echo '</body></html>';
    exit;
}

// ============================================
// EXPORT TO WORD
// ============================================
if (isset($_GET['export_report']) && $_GET['export_report'] == 'word') {
    while (ob_get_level()) { ob_end_clean(); }
    
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="Clearance_Completion_Report_' . date('Ymd') . '.doc"');
    
    echo '<html><head><meta charset="UTF-8"><title>Clearance Completion Report</title>';
    echo '<style>';
    echo 'body { font-family: "Times New Roman", Times, serif; margin: 1.5cm; }';
    echo '.header { text-align: center; margin-bottom: 20px; }';
    echo '.university { font-size: 18pt; font-weight: bold; color: #1b4d3e; }';
    echo '.address { font-size: 10pt; color: #555; }';
    echo '.title { font-size: 16pt; font-weight: bold; margin-top: 15px; }';
    echo 'th { background: #1b4d3e; color: white; padding: 10px; border: 1px solid #2d6a4f; }';
    echo 'td { padding: 8px; border: 1px solid #ddd; }';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo '</style></head><body>';
    
    echo '<div class="header">';
    echo '<div class="university">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>';
    echo '<div class="address">Talisay City, Negros Occidental, Philippines</div>';
    echo '<div class="title">CLEARANCE COMPLETION REPORT</div>';
    echo '<div>Generated on: ' . date('F d, Y h:i A') . '</div>';
    echo '</div>';
    
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
    echo '<tr>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Student Name</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Year</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Section</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Course</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Student Type</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Clearance Status</th>';
    echo '<th style="border: 1px solid #000; padding: 10px;">Clearance Progress</th>';
    echo '</tr>';
    
    foreach ($students as $row) {
        $statusStyle = ($row['status'] == 'Approved') ? 'color:green;font-weight:bold' : 'color:red;font-weight:bold';
        echo '<tr>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['name']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['year']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['section']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['course']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['type']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px; ' . $statusStyle . '">' . htmlspecialchars($row['status']) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><strong>' . htmlspecialchars($row['progress']) . '</strong></td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '<br><br><strong>SUMMARY REPORT</strong><br>';
    echo 'Total Students: ' . $totalStudents . '<br>';
    echo 'Approved (Completed Clearance): ' . $totalApproved . '<br>';
    echo 'Pending (In Progress): ' . $totalPending . '<br>';
    echo 'Regular Students: ' . $totalRegular . '<br>';
    echo 'Irregular Students: ' . $totalIrregular . '<br>';
    echo 'Clearance Completion Rate: ' . ($totalStudents > 0 ? round(($totalApproved / $totalStudents) * 100, 2) : 0) . '%';
    echo '</body></html>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Clearance Completion Report</title>
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
        
        .filter-card {
            background: white;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }
        .filter-card-header {
            background: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
        }
        .filter-card-body { padding: 15px; }
        
        .report-container {
            background: white;
            border: 1px solid #ddd;
            overflow-x: auto;
        }
        .report-header {
            background: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 1px solid #ddd;
        }
        .report-body { padding: 15px; }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            justify-content: flex-end;
        }
        .btn-excel { background: #27ae60; color: white; padding: 8px 15px; border: none; cursor: pointer; border-radius: 3px; text-decoration: none; display: inline-block; }
        .btn-word { background: #1b4d3e; color: white; padding: 8px 15px; border: none; cursor: pointer; border-radius: 3px; text-decoration: none; display: inline-block; }
        .btn-excel:hover { background: #219a52; }
        .btn-word:hover { background: #2d6a4f; }
        
        .filter-group { display: inline-block; margin-right: 15px; margin-bottom: 10px; }
        .filter-group label { display: block; font-size: 11px; font-weight: bold; margin-bottom: 3px; color: #333; }
        select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 3px; font-size: 12px; min-width: 120px; }
        .btn-filter { background: #1b4d3e; color: white; padding: 6px 15px; border: none; cursor: pointer; border-radius: 3px; margin-top: 18px; }
        .btn-reset { background: #7f8c8d; color: white; padding: 6px 15px; border: none; cursor: pointer; text-decoration: none; display: inline-block; border-radius: 3px; margin-top: 18px; }
        
        .clearance-table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 12px;
            border: 1px solid #ddd;
        }
        .clearance-table th, .clearance-table td { 
            border: 1px solid #ddd; 
            padding: 12px 10px; 
            text-align: left; 
            vertical-align: middle; 
        }
        .clearance-table th { 
            background: #1b4d3e; 
            color: white; 
            font-weight: normal;
            white-space: nowrap;
        }
        .clearance-table tr:nth-child(even) { background: #fafafa; }
        
        .status-approved { color: #27ae60; font-weight: bold; }
        .status-pending { color: #e74c3c; font-weight: bold; }
        
        .progress-badge {
            display: inline-block;
            background: #1b4d3e;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            text-align: center;
            min-width: 60px;
        }
        
        .summary-box { 
            margin-top: 20px; 
            padding: 15px; 
            background: #e8f5e9; 
            border-left: 4px solid #27ae60; 
            display: flex; 
            justify-content: space-between; 
            flex-wrap: wrap; 
        }
        .summary-item { text-align: center; padding: 5px 15px; }
        .summary-number { font-size: 24px; font-weight: bold; color: #1b4d3e; }
        .summary-label { font-size: 11px; color: #666; }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .filter-card,
        body.dark-mode .report-container { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .clearance-table th { background: #2c2c2c; color: #fff; border-color: #444; }
        body.dark-mode .clearance-table td { border-color: #333; color: #fff; }
        body.dark-mode .summary-box { background: #1a3a2a; }
        
        @media (max-width: 768px) {
            .registrar-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .filter-group { display: block; margin-bottom: 10px; }
            select { width: 100%; }
            .clearance-table th, .clearance-table td { padding: 6px; font-size: 10px; }
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
        <p>CLEARANCE SYSTEM | Registrar Portal - Clearance Completion Report</p>
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
            <li><a href="?section=reports" class="active"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?section=archived"><i class="fas fa-archive"></i> Archive</a></li>
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
            <h2>Clearance Completion Report (Student-Level Detail)</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <!-- FILTER CARD -->
        <div class="filter-card">
            <div class="filter-card-header"><i class="fas fa-filter"></i> Filter Report</div>
            <div class="filter-card-body">
                <form method="GET" action="">
                    <input type="hidden" name="section" value="reports">
                    
                    <div class="filter-group">
                        <label>Course</label>
                        <select name="filter_course">
                            <option value="">All Courses</option>
                            <?php 
                            $courses = $conn->query("SELECT * FROM chmsu_courses ORDER BY course_code");
                            while($c = $courses->fetch_assoc()): 
                            ?>
                            <option value="<?php echo $c['course_code']; ?>" <?php echo $filter_course == $c['course_code'] ? 'selected' : ''; ?>><?php echo $c['course_code']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Year Level</label>
                        <select name="filter_year">
                            <option value="">All Years</option>
                            <option value="1" <?php echo $filter_year == '1' ? 'selected' : ''; ?>>1st Year</option>
                            <option value="2" <?php echo $filter_year == '2' ? 'selected' : ''; ?>>2nd Year</option>
                            <option value="3" <?php echo $filter_year == '3' ? 'selected' : ''; ?>>3rd Year</option>
                            <option value="4" <?php echo $filter_year == '4' ? 'selected' : ''; ?>>4th Year</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Section</label>
                        <select name="filter_section">
                            <option value="">All Sections</option>
                            <?php 
                            $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections ORDER BY section_name");
                            while($s = $sections->fetch_assoc()): 
                            ?>
                            <option value="<?php echo $s['section_name']; ?>" <?php echo $filter_section == $s['section_name'] ? 'selected' : ''; ?>><?php echo $s['section_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Student Type</label>
                        <select name="filter_type">
                            <option value="">All Types</option>
                            <option value="regular" <?php echo $filter_type == 'regular' ? 'selected' : ''; ?>>Regular Only</option>
                            <option value="irregular" <?php echo $filter_type == 'irregular' ? 'selected' : ''; ?>>Irregular Only</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Clearance Status</label>
                        <select name="filter_status">
                            <option value="">All Status</option>
                            <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>Approved Only</option>
                            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending Only</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Apply Filters</button>
                        <a href="?section=reports" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- EXPORT BUTTONS -->
        <div class="export-buttons">
            <a href="?section=reports&export_report=excel&filter_course=<?php echo urlencode($filter_course); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_section=<?php echo urlencode($filter_section); ?>&filter_type=<?php echo urlencode($filter_type); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="btn-excel">
                <i class="fas fa-file-excel"></i> Export to Excel
            </a>
            <a href="?section=reports&export_report=word&filter_course=<?php echo urlencode($filter_course); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_section=<?php echo urlencode($filter_section); ?>&filter_type=<?php echo urlencode($filter_type); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="btn-word">
                <i class="fas fa-file-word"></i> Export to Word
            </a>
        </div>
        
        <!-- REPORT TABLE -->
        <div class="report-container">
            <div class="report-header"><i class="fas fa-clipboard-list"></i> Student Clearance Completion Details</div>
            <div class="report-body">
                <div style="overflow-x: auto;">
                    <table class="clearance-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Year</th>
                                <th>Section</th>
                                <th>Course</th>
                                <th>Student Type</th>
                                <th>Clearance Status</th>
                                <th>Clearance Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr><td colspan="7" style="text-align: center;">No students found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['year']); ?></td>
                                    <td><?php echo htmlspecialchars($student['section']); ?></td>
                                    <td><?php echo htmlspecialchars($student['course']); ?></td>
                                    <td><?php echo htmlspecialchars($student['type']); ?></td>
                                    <td class="status-<?php echo strtolower($student['status']); ?>"><?php echo htmlspecialchars($student['status']); ?></td>
                                    <td style="text-align: center;"><span class="progress-badge"><?php echo htmlspecialchars($student['progress']); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- SUMMARY SECTION -->
                <div class="summary-box">
                    <div class="summary-item"><div class="summary-number"><?php echo $totalStudents; ?></div><div class="summary-label">Total Students</div></div>
                    <div class="summary-item"><div class="summary-number" style="color:#27ae60;"><?php echo $totalApproved; ?></div><div class="summary-label">Approved (Completed)</div></div>
                    <div class="summary-item"><div class="summary-number" style="color:#e74c3c;"><?php echo $totalPending; ?></div><div class="summary-label">Pending (In Progress)</div></div>
                    <div class="summary-item"><div class="summary-number"><?php echo $totalRegular; ?></div><div class="summary-label">Regular Students</div></div>
                    <div class="summary-item"><div class="summary-number"><?php echo $totalIrregular; ?></div><div class="summary-label">Irregular Students</div></div>
                </div>
            </div>
        </div>
        
        <!-- FOOTER NOTE -->
        <div style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-left: 4px solid #1b4d3e; font-size: 10px; color: #666;">
            <i class="fas fa-info-circle"></i> 
            <strong>Report Information:</strong> This report shows student-level clearance completion details. 
            "Clearance Progress" shows the number of offices that have approved divided by the total number of offices (e.g., 3/5 means 3 out of 5 offices have approved).
        </div>
    </div>
</div>

<script>
    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) btn.innerHTML = document.body.classList.contains('dark-mode') ? '<i class="fas fa-sun"></i> Light Mode' : '<i class="fas fa-moon"></i> Dark Mode';
    }
    if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
    function confirmLogout() { if(confirm('Logout?')) window.location.href = '?logout=1'; }
</script>

</body>
</html>