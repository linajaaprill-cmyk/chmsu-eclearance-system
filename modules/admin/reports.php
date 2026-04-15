<?php
$admin = $_SESSION['admin'];
$adminSection = 'reports';

// Handle comprehensive report export
if (isset($_GET['export']) && $_GET['export'] == 'comprehensive') {
    $filename = 'CHMSU_Comprehensive_System_Report_' . date('Ymd_His');
    exportComprehensiveReport($conn, $filename, 'admin');
}

// Handle individual report exports
if (isset($_GET['export_report']) && isset($_GET['type'])) {
    $export_type = $_GET['export_report'];
    $report_type = $_GET['type'];
    $report_title = '';
    $subtitle = '';
    
    if ($report_type == 'activity') {
        $logs = $conn->query("SELECT user_id as 'User', user_type as 'Type', action as 'Action', ip_address as 'IP Address', DATE_FORMAT(created_at, '%M %d, %Y %h:%i %p') as 'Date/Time' FROM chmsu_activity_log ORDER BY created_at DESC");
        $data = [];
        while ($row = $logs->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['User', 'Type', 'Action', 'IP Address', 'Date/Time'];
        $report_title = 'SYSTEM ACTIVITY LOG REPORT';
        $subtitle = 'Complete record of all user activities in the system';
        $filename = 'System_Activity_Report_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers, $report_title, $subtitle);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers, $report_title, $subtitle);
        }
    }
    elseif ($report_type == 'users') {
        $students = $conn->query("SELECT chmsu_student_id as 'Student ID', chmsu_name as 'Student Name', chmsu_course as 'Course', chmsu_year as 'Year Level', chmsu_section as 'Section', DATE_FORMAT(chmsu_created_at, '%M %d, %Y') as 'Registered Date' FROM chmsu_user_accounts ORDER BY chmsu_course, chmsu_year, chmsu_section");
        $data = [];
        while ($row = $students->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['Student ID', 'Student Name', 'Course', 'Year Level', 'Section', 'Registered Date'];
        $report_title = 'STUDENT USER ACCOUNTS REPORT';
        $subtitle = 'List of all registered student accounts in the E-Clearance System';
        $filename = 'Student_Accounts_Report_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers, $report_title, $subtitle);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers, $report_title, $subtitle);
        }
    }
    elseif ($report_type == 'clearance') {
        $totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];
        $students = $conn->query("SELECT s.chmsu_student_id as 'Student ID', s.chmsu_name as 'Student Name', s.chmsu_course as 'Course', s.chmsu_year as 'Year', s.chmsu_section as 'Section',
                                  (SELECT COUNT(DISTINCT r.chmsu_office) FROM chmsu_submissions sub 
                                   JOIN chmsu_requirements r ON sub.chmsu_requirement_id = r.chmsu_id 
                                   WHERE sub.chmsu_student_id = s.chmsu_student_id AND sub.chmsu_status = 'Approved') as approved_count
                                  FROM chmsu_user_accounts s ORDER BY s.chmsu_course, s.chmsu_year, s.chmsu_section");
        $data = [];
        while ($row = $students->fetch_assoc()) {
            $status = ($row['approved_count'] >= $totalOffices) ? 'COMPLETE' : 'IN PROGRESS';
            $data[] = [
                $row['Student ID'],
                $row['Student Name'],
                $row['Course'],
                $row['Year'],
                $row['Section'],
                $row['approved_count'] . '/' . $totalOffices,
                $status
            ];
        }
        $headers = ['Student ID', 'Student Name', 'Course', 'Year', 'Section', 'Progress', 'Status'];
        $report_title = 'CLEARANCE STATUS REPORT';
        $subtitle = 'Student clearance completion status across all offices';
        $filename = 'Clearance_Status_Report_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers, $report_title, $subtitle);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers, $report_title, $subtitle);
        }
    }
    elseif ($report_type == 'office_performance') {
        $offices = $conn->query("SELECT o.office_name as 'Office Name', 
                                 (SELECT COUNT(*) FROM chmsu_requirements r WHERE r.chmsu_office = o.office_name) as 'Requirements',
                                 (SELECT COUNT(*) FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id WHERE r.chmsu_office = o.office_name) as 'Submissions',
                                 (SELECT COUNT(*) FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id WHERE r.chmsu_office = o.office_name AND s.chmsu_status = 'Approved') as 'Approved',
                                 (SELECT COUNT(*) FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id WHERE r.chmsu_office = o.office_name AND s.chmsu_status = 'Declined') as 'Declined'
                                 FROM chmsu_offices o ORDER BY o.office_name");
        $data = [];
        while ($row = $offices->fetch_assoc()) {
            $approval_rate = ($row['Submissions'] > 0) ? round(($row['Approved'] / $row['Submissions']) * 100, 2) . '%' : '0%';
            $data[] = [
                $row['Office Name'],
                $row['Requirements'],
                $row['Submissions'],
                $row['Approved'],
                $row['Declined'],
                $approval_rate
            ];
        }
        $headers = ['Office Name', 'Requirements', 'Submissions', 'Approved', 'Declined', 'Approval Rate'];
        $report_title = 'OFFICE PERFORMANCE REPORT';
        $subtitle = 'Performance metrics for each office in the clearance process';
        $filename = 'Office_Performance_Report_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers, $report_title, $subtitle);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers, $report_title, $subtitle);
        }
    }
    elseif ($report_type == 'requirements') {
        $requirements = $conn->query("SELECT chmsu_title as 'Requirement Title', chmsu_office as 'Office', chmsu_course as 'Course', chmsu_year as 'Year', chmsu_section as 'Section', IF(chmsu_deadline IS NOT NULL, DATE_FORMAT(chmsu_deadline, '%M %d, %Y %h:%i %p'), 'No Deadline') as 'Deadline', chmsu_created_at as 'Date Created' FROM chmsu_requirements ORDER BY chmsu_created_at DESC");
        $data = [];
        while ($row = $requirements->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['Requirement Title', 'Office', 'Course', 'Year', 'Section', 'Deadline', 'Date Created'];
        $report_title = 'REQUIREMENTS REPORT';
        $subtitle = 'List of all clearance requirements in the system';
        $filename = 'Requirements_Report_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers, $report_title, $subtitle);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers, $report_title, $subtitle);
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
        .header-title h1 { font-size: 20px; font-weight: normal; font-family: 'Times New Roman', Times, serif; }
        .header-title p { font-size: 11px; opacity: 0.8; margin-top: 3px; }
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
        
        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }
        .admin-sidebar {
            width: 240px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .admin-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .admin-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        .admin-sidebar .sidebar-menu { list-style: none; padding: 0; }
        .admin-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
        }
        .admin-sidebar .sidebar-menu a:hover,
        .admin-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
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
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
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
            font-weight: normal;
        }
        .report-card-body { padding: 20px; }
        .report-description { color: #666; font-size: 12px; margin-bottom: 15px; line-height: 1.5; }
        .report-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-excel {
            background: #27ae60;
            color: white;
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            text-decoration: none;
            display: inline-block;
            font-size: 12px;
        }
        .btn-word {
            background: #1b4d3e;
            color: white;
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            text-decoration: none;
            display: inline-block;
            font-size: 12px;
        }
        .btn-comprehensive {
            background: #f39c12;
            color: white;
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            text-decoration: none;
            display: inline-block;
            font-size: 12px;
        }
        .btn-excel:hover { background: #219a52; }
        .btn-word:hover { background: #2d6a4f; }
        .btn-comprehensive:hover { background: #e67e22; }
        
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
        
        @media (max-width: 768px) {
            .admin-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .reports-grid { grid-template-columns: 1fr; }
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
        <p>CLEARANCE SYSTEM | Admin Portal - Reports</p>
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
            <li><a href="?adminsection=reports" class="active">Reports</a></li>
            <li><a href="?adminsection=activity">Activity Log</a></li>
            <li><a href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>System Reports</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- Comprehensive System Report -->
        <div class="report-card" style="border: 2px solid #f39c12; margin-bottom: 20px;">
            <div class="report-card-header" style="background: #f39c12; color: #000;">COMPREHENSIVE SYSTEM REPORT</div>
            <div class="report-card-body">
                <div class="report-description">
                    <strong>Complete system report including:</strong><br>
                    • Executive Summary with key metrics<br>
                    • Student Master List<br>
                    • Office Performance Report<br>
                    • Student Clearance Status<br>
                    • Recent System Activities<br>
                    • Professional format with university letterhead and signature lines
                </div>
                <div class="report-actions">
                    <a href="?adminsection=reports&export=comprehensive" class="btn-comprehensive">
                        <i class="fas fa-file-alt"></i> Generate Comprehensive Report (Word)
                    </a>
                </div>
            </div>
        </div>
        
        <div class="reports-grid">
            <!-- System Activity Report -->
            <div class="report-card">
                <div class="report-card-header">System Activity Report</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Complete log of all user activities including logins, submissions, approvals, and declines.
                    </div>
                    <div class="report-actions">
                        <a href="?adminsection=reports&export_report=excel&type=activity" class="btn-excel">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                        <a href="?adminsection=reports&export_report=word&type=activity" class="btn-word">
                            <i class="fas fa-file-word"></i> Export Word
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Student User Accounts Report -->
            <div class="report-card">
                <div class="report-card-header">Student User Accounts Report</div>
                <div class="report-card-body">
                    <div class="report-description">
                        List of all registered student accounts with their complete details.
                    </div>
                    <div class="report-actions">
                        <a href="?adminsection=reports&export_report=excel&type=users" class="btn-excel">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                        <a href="?adminsection=reports&export_report=word&type=users" class="btn-word">
                            <i class="fas fa-file-word"></i> Export Word
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Clearance Status Report -->
            <div class="report-card">
                <div class="report-card-header">Clearance Status Report</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Student clearance completion status with progress tracking per office.
                    </div>
                    <div class="report-actions">
                        <a href="?adminsection=reports&export_report=excel&type=clearance" class="btn-excel">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                        <a href="?adminsection=reports&export_report=word&type=clearance" class="btn-word">
                            <i class="fas fa-file-word"></i> Export Word
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Office Performance Report -->
            <div class="report-card">
                <div class="report-card-header">Office Performance Report</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Each office's performance metrics: requirements, submissions, approvals, and approval rates.
                    </div>
                    <div class="report-actions">
                        <a href="?adminsection=reports&export_report=excel&type=office_performance" class="btn-excel">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                        <a href="?adminsection=reports&export_report=word&type=office_performance" class="btn-word">
                            <i class="fas fa-file-word"></i> Export Word
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Requirements Report -->
            <div class="report-card">
                <div class="report-card-header">Requirements Report</div>
                <div class="report-card-body">
                    <div class="report-description">
                        List of all clearance requirements created in the system.
                    </div>
                    <div class="report-actions">
                        <a href="?adminsection=reports&export_report=excel&type=requirements" class="btn-excel">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                        <a href="?adminsection=reports&export_report=word&type=requirements" class="btn-word">
                            <i class="fas fa-file-word"></i> Export Word
                        </a>
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