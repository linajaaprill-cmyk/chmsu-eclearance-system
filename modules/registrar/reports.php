<?php
$office = $_SESSION['office'];
$currentSection = 'reports';

// Handle report exports
if (isset($_GET['export_report']) && isset($_GET['type'])) {
    $export_type = $_GET['export_report'];
    $report_type = $_GET['type'];
    
    if ($report_type == 'masterlist') {
        $students = $conn->query("SELECT chmsu_student_id as 'Student ID', chmsu_last_name as 'Last Name', 
                                   chmsu_first_name as 'First Name', chmsu_middle_name as 'Middle Name',
                                   chmsu_course as 'Course', chmsu_year as 'Year', chmsu_section as 'Section',
                                   DATE_FORMAT(chmsu_birthdate, '%M %d, %Y') as 'Birthdate'
                                  FROM chmsu_students_master ORDER BY chmsu_course, chmsu_year, chmsu_section, chmsu_last_name");
        $data = [];
        while ($row = $students->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['Student ID', 'Last Name', 'First Name', 'Middle Name', 'Course', 'Year', 'Section', 'Birthdate'];
        $filename = 'Student_Master_List_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers);
        }
    }
    elseif ($report_type == 'clearance_status') {
        $totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];
        $students = $conn->query("SELECT s.chmsu_student_id, s.chmsu_name, s.chmsu_course, s.chmsu_year, s.chmsu_section,
                                  (SELECT COUNT(DISTINCT r.chmsu_office) FROM chmsu_submissions sub 
                                   JOIN chmsu_requirements r ON sub.chmsu_requirement_id = r.chmsu_id 
                                   WHERE sub.chmsu_student_id = s.chmsu_student_id AND sub.chmsu_status = 'Approved') as approved_count
                                  FROM chmsu_user_accounts s ORDER BY s.chmsu_course, s.chmsu_year, s.chmsu_section");
        $data = [];
        while ($row = $students->fetch_assoc()) {
            $status = ($row['approved_count'] >= $totalOffices) ? 'Complete' : 'Incomplete';
            $data[] = [
                $row['chmsu_student_id'],
                $row['chmsu_name'],
                $row['chmsu_course'],
                $row['chmsu_year'],
                $row['chmsu_section'],
                $row['approved_count'] . '/' . $totalOffices,
                $status
            ];
        }
        $headers = ['Student ID', 'Name', 'Course', 'Year', 'Section', 'Progress', 'Status'];
        $filename = 'Clearance_Status_Report_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers);
        }
    }
    elseif ($report_type == 'enrollment') {
        $enrollment = $conn->query("SELECT chmsu_course as 'Course', chmsu_year as 'Year', chmsu_section as 'Section', COUNT(*) as 'Count'
                                    FROM chmsu_students_master 
                                    GROUP BY chmsu_course, chmsu_year, chmsu_section 
                                    ORDER BY chmsu_course, chmsu_year, chmsu_section");
        $data = [];
        while ($row = $enrollment->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['Course', 'Year', 'Section', 'Number of Students'];
        $filename = 'Enrollment_Summary_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Reports</title>
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
        
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .report-card {
            background: white;
            border: 1px solid #ddd;
            overflow: hidden;
        }
        .report-card-header {
            background: #1b4d3e;
            color: white;
            padding: 15px;
            font-size: 16px;
        }
        .report-card-body { padding: 20px; }
        .report-description { color: #666; font-size: 12px; margin-bottom: 15px; line-height: 1.5; }
        .report-actions { display: flex; gap: 10px; }
        .btn-excel {
            background: #27ae60;
            color: white;
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-word {
            background: #1b4d3e;
            color: white;
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-excel:hover { background: #219a52; }
        .btn-word:hover { background: #2d6a4f; }
        
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
        <p>CLEARANCE SYSTEM | Registrar Portal - Reports</p>
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
            <li><a href="?section=reports" class="active">Reports</a></li>
            <li><a href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
        <div style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f;">
            Storage: <?php echo formatFileSize(getTotalUploadSize()); ?> / 100MB
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Reports</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <div class="reports-grid">
            <!-- Student Master List -->
            <div class="report-card">
                <div class="report-card-header">Student Master List</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Complete list of all students with their personal and academic information.
                    </div>
                    <div class="report-actions">
                        <a href="?section=reports&export_report=excel&type=masterlist" class="btn-excel">Export Excel</a>
                        <a href="?section=reports&export_report=word&type=masterlist" class="btn-word">Export Word</a>
                    </div>
                </div>
            </div>
            
            <!-- Clearance Status Report -->
            <div class="report-card">
                <div class="report-card-header">Clearance Status Report</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Students clearance completion status with progress tracking per office.
                    </div>
                    <div class="report-actions">
                        <a href="?section=reports&export_report=excel&type=clearance_status" class="btn-excel">Export Excel</a>
                        <a href="?section=reports&export_report=word&type=clearance_status" class="btn-word">Export Word</a>
                    </div>
                </div>
            </div>
            
            <!-- Enrollment Summary -->
            <div class="report-card">
                <div class="report-card-header">Enrollment Summary</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Number of students per course, year level, and section.
                    </div>
                    <div class="report-actions">
                        <a href="?section=reports&export_report=excel&type=enrollment" class="btn-excel">Export Excel</a>
                        <a href="?section=reports&export_report=word&type=enrollment" class="btn-word">Export Word</a>
                    </div>
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