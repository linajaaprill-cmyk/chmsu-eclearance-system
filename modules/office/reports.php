<?php
$office = $_SESSION['office'];
$currentOfficeSection = 'reports';

// Handle report exports
if (isset($_GET['export_report']) && isset($_GET['type'])) {
    $export_type = $_GET['export_report'];
    $report_type = $_GET['type'];
    
    if ($report_type == 'submissions') {
        $submissions = $conn->query("SELECT s.chmsu_student_id as 'Student ID', u.chmsu_name as 'Student Name',
                                      r.chmsu_title as 'Requirement', s.chmsu_status as 'Status',
                                      s.submitted_at as 'Submitted Date', s.chmsu_reject_reason as 'Reason'
                                     FROM chmsu_submissions s
                                     JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                     JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id
                                     WHERE r.chmsu_office = '$office'
                                     ORDER BY s.submitted_at DESC");
        $data = [];
        while ($row = $submissions->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['Student ID', 'Student Name', 'Requirement', 'Status', 'Submitted Date', 'Reason'];
        $filename = $office . '_Submissions_Report_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers);
        }
    }
    elseif ($report_type == 'pending') {
        $pending = $conn->query("SELECT s.chmsu_student_id as 'Student ID', u.chmsu_name as 'Student Name',
                                  r.chmsu_title as 'Requirement', s.submitted_at as 'Submitted Date'
                                 FROM chmsu_submissions s
                                 JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                 JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id
                                 WHERE r.chmsu_office = '$office' AND s.chmsu_status = 'Submitted'
                                 ORDER BY s.submitted_at ASC");
        $data = [];
        while ($row = $pending->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['Student ID', 'Student Name', 'Requirement', 'Submitted Date'];
        $filename = $office . '_Pending_Submissions_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers);
        }
    }
    elseif ($report_type == 'approved') {
        $approved = $conn->query("SELECT s.chmsu_student_id as 'Student ID', u.chmsu_name as 'Student Name',
                                   r.chmsu_title as 'Requirement', s.chmsu_action_taken_at as 'Approved Date'
                                  FROM chmsu_submissions s
                                  JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                  JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id
                                  WHERE r.chmsu_office = '$office' AND s.chmsu_status = 'Approved'
                                  ORDER BY s.chmsu_action_taken_at DESC");
        $data = [];
        while ($row = $approved->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['Student ID', 'Student Name', 'Requirement', 'Approved Date'];
        $filename = $office . '_Approved_Submissions_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers);
        }
    }
    elseif ($report_type == 'declined') {
        $declined = $conn->query("SELECT s.chmsu_student_id as 'Student ID', u.chmsu_name as 'Student Name',
                                   r.chmsu_title as 'Requirement', s.chmsu_reject_reason as 'Reason',
                                   s.chmsu_action_taken_at as 'Declined Date'
                                  FROM chmsu_submissions s
                                  JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                  JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id
                                  WHERE r.chmsu_office = '$office' AND s.chmsu_status = 'Declined'
                                  ORDER BY s.chmsu_action_taken_at DESC");
        $data = [];
        while ($row = $declined->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['Student ID', 'Student Name', 'Requirement', 'Reason', 'Declined Date'];
        $filename = $office . '_Declined_Submissions_' . date('Ymd');
        
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
        .office-sidebar {
            width: 240px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .office-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .office-sidebar .sidebar-menu { list-style: none; padding: 0; }
        .office-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
        }
        .office-sidebar .sidebar-menu a:hover,
        .office-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
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
            .office-sidebar { width: 100%; position: relative; height: auto; }
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
        <p>CLEARANCE SYSTEM | <?php echo strtoupper($office); ?> Portal - Reports</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?officesection=dashboard">Dashboard</a></li>
            <li><a href="?officesection=requirements">Requirements</a></li>
            <li><a href="?officesection=submissions">Submissions</a></li>
            <li><a href="?officesection=reports" class="active">Reports</a></li>
            <li><a href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
        <div class="storage-info" style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f;">
            Storage: <?php echo formatFileSize(getTotalUploadSize()); ?> / 100MB
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2><?php echo htmlspecialchars($office); ?> - Reports</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <div class="reports-grid">
            <!-- All Submissions Report -->
            <div class="report-card">
                <div class="report-card-header">All Submissions Report</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Complete list of all student submissions with their current status.
                    </div>
                    <div class="report-actions">
                        <a href="?officesection=reports&export_report=excel&type=submissions" class="btn-excel">Export Excel</a>
                        <a href="?officesection=reports&export_report=word&type=submissions" class="btn-word">Export Word</a>
                    </div>
                </div>
            </div>
            
            <!-- Pending Submissions Report -->
            <div class="report-card">
                <div class="report-card-header">Pending Submissions</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Submissions waiting for your action/approval.
                    </div>
                    <div class="report-actions">
                        <a href="?officesection=reports&export_report=excel&type=pending" class="btn-excel">Export Excel</a>
                        <a href="?officesection=reports&export_report=word&type=pending" class="btn-word">Export Word</a>
                    </div>
                </div>
            </div>
            
            <!-- Approved Submissions Report -->
            <div class="report-card">
                <div class="report-card-header">Approved Submissions</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Submissions already approved by your office.
                    </div>
                    <div class="report-actions">
                        <a href="?officesection=reports&export_report=excel&type=approved" class="btn-excel">Export Excel</a>
                        <a href="?officesection=reports&export_report=word&type=approved" class="btn-word">Export Word</a>
                    </div>
                </div>
            </div>
            
            <!-- Declined Submissions Report -->
            <div class="report-card">
                <div class="report-card-header">Declined Submissions</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Submissions declined by your office with reasons.
                    </div>
                    <div class="report-actions">
                        <a href="?officesection=reports&export_report=excel&type=declined" class="btn-excel">Export Excel</a>
                        <a href="?officesection=reports&export_report=word&type=declined" class="btn-word">Export Word</a>
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