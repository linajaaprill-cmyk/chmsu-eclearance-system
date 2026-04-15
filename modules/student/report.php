<?php
$student_id = $_SESSION['student'];
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
$view = 'reports';

// Handle report exports
if (isset($_GET['export_report']) && isset($_GET['type'])) {
    $export_type = $_GET['export_report'];
    $report_type = $_GET['type'];
    
    if ($report_type == 'clearance') {
        $offices = $conn->query("SELECT o.office_name,
                                 (SELECT COUNT(*) FROM chmsu_requirements r WHERE r.chmsu_office = o.office_name 
                                  AND r.chmsu_course = '{$student['chmsu_course']}' AND r.chmsu_year = '{$student['chmsu_year']}'
                                  AND r.chmsu_section = '{$student['chmsu_section']}') as total_req,
                                 (SELECT s.chmsu_status FROM chmsu_submissions s 
                                  JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id 
                                  WHERE r.chmsu_office = o.office_name AND s.chmsu_student_id = '$student_id'
                                  ORDER BY s.chmsu_id DESC LIMIT 1) as status
                                 FROM chmsu_offices o ORDER BY o.office_name");
        $data = [];
        while ($row = $offices->fetch_assoc()) {
            $data[] = [
                $row['office_name'],
                $row['total_req'],
                $row['status'] ?: 'Pending'
            ];
        }
        $headers = ['Office', 'Requirements', 'Status'];
        $filename = 'My_Clearance_Status_' . date('Ymd');
        
        if ($export_type == 'excel') {
            exportToExcelReport($data, $filename, $headers);
        } elseif ($export_type == 'word') {
            exportToWordReport($data, $filename, $headers);
        }
    }
    elseif ($report_type == 'submissions') {
        $submissions = $conn->query("SELECT r.chmsu_title as 'Requirement', r.chmsu_office as 'Office',
                                      s.chmsu_status as 'Status', s.submitted_at as 'Submitted Date',
                                      s.chmsu_reject_reason as 'Reason'
                                     FROM chmsu_submissions s
                                     JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id
                                     WHERE s.chmsu_student_id = '$student_id'
                                     ORDER BY s.submitted_at DESC");
        $data = [];
        while ($row = $submissions->fetch_assoc()) {
            $data[] = $row;
        }
        $headers = ['Requirement', 'Office', 'Status', 'Submitted Date', 'Reason'];
        $filename = 'My_Submissions_Report_' . date('Ymd');
        
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
    <title>CHMSU E-Clearance System - My Reports</title>
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
        .dark-sidebar {
            width: 240px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .dark-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .dark-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        .dark-sidebar .sidebar-menu { list-style: none; padding: 0; }
        .dark-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
        }
        .dark-sidebar .sidebar-menu a:hover,
        .dark-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
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
            .dark-sidebar { width: 100%; position: relative; height: auto; }
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
        <p>CLEARANCE SYSTEM | Student Portal - Reports</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="dark-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($master['chmsu_full_name']); ?></h3>
            <p><?php echo htmlspecialchars($student['chmsu_course']); ?> <?php echo $student['chmsu_year']; ?><?php echo htmlspecialchars($student['chmsu_section']); ?></p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?view=home">Home</a></li>
            <li style="border-bottom: none; padding: 8px 12px; color: #dddddd; font-size: 10px;">OFFICES</li>
            <?php
            $offices_result = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
            while ($office_row = $offices_result->fetch_assoc()):
                $office_name = $office_row['office_name'];
            ?>
            <li><a href="?view=office&office=<?php echo urlencode($office_name); ?>"><?php echo htmlspecialchars($office_name); ?></a></li>
            <?php endwhile; ?>
            <li><a href="?view=reports" class="active">Reports</a></li>
            <li><a href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
        <div class="storage-info" style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize(getTotalUploadSize()); ?> / 100MB
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>My Reports</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <div class="reports-grid">
            <!-- My Clearance Status Report -->
            <div class="report-card">
                <div class="report-card-header">My Clearance Status</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Your clearance progress across all offices with current status.
                    </div>
                    <div class="report-actions">
                        <a href="?view=reports&export_report=excel&type=clearance" class="btn-excel">Export Excel</a>
                        <a href="?view=reports&export_report=word&type=clearance" class="btn-word">Export Word</a>
                    </div>
                </div>
            </div>
            
            <!-- My Submissions Report -->
            <div class="report-card">
                <div class="report-card-header">My Submissions</div>
                <div class="report-card-body">
                    <div class="report-description">
                        Complete list of all your requirement submissions with status.
                    </div>
                    <div class="report-actions">
                        <a href="?view=reports&export_report=excel&type=submissions" class="btn-excel">Export Excel</a>
                        <a href="?view=reports&export_report=word&type=submissions" class="btn-word">Export Word</a>
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