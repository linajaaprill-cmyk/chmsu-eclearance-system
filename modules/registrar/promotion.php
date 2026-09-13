<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/email.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['office']) || $_SESSION['office'] != 'Registrar') {
    header("Location: ../index.php");
    exit;
}

$office = $_SESSION['office'];
$currentSection = 'promotion';
$error = '';
$success = '';
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

// Handle Promotion
if(isset($_POST['action']) && $_POST['action'] == 'promote_students') {
    // Get all active (non-archived) students
    $students = $conn->query("SELECT * FROM chmsu_students_master WHERE is_archived = 0");
    
    $promoted = 0;
    $archived = 0;
    $irregular_4th_kept = 0;
    $promoted_students = [];
    $graduated_students = [];
    
    while($student = $students->fetch_assoc()) {
        $current_year = intval($student['chmsu_year']);
        $is_irregular = $student['is_irregular'];
        $student_id = $student['chmsu_student_id'];
        
        if($current_year == 4 && $is_irregular == 0) {
            // Regular 4th year - GRADUATE (Archive)
            $conn->query("UPDATE chmsu_students_master SET 
                          is_archived = 1, 
                          archived_at = NOW(), 
                          graduation_date = CURDATE() 
                          WHERE chmsu_student_id = '$student_id'");
            $archived++;
            $graduated_students[] = $student;
        }
        elseif($current_year == 4 && $is_irregular == 1) {
            // Irregular 4th year - CANNOT GRADUATE, stay active in 4th year
            $irregular_4th_kept++;
        }
        elseif($current_year < 4) {
            // Promote all students (regular and irregular) to next year level
            $new_year = $current_year + 1;
            $conn->query("UPDATE chmsu_students_master SET chmsu_year = '$new_year' WHERE chmsu_student_id = '$student_id'");
            $conn->query("UPDATE chmsu_user_accounts SET chmsu_year = '$new_year' WHERE chmsu_student_id = '$student_id'");
            $promoted++;
            $promoted_students[] = $student;
        }
    }
    
    // ============================================
    // NEW: SEND EMAIL NOTIFICATIONS
    // ============================================
    if (!empty($promoted_students) || !empty($graduated_students)) {
        // Send to promoted students
        foreach ($promoted_students as $student) {
            $new_year = $student['chmsu_year'] + 1;
            $student_email = $conn->query("SELECT email FROM chmsu_user_accounts WHERE chmsu_student_id='" . $student['chmsu_student_id'] . "'")->fetch_assoc();
            
            if ($student_email && $student_email['email']) {
                $subject = "🎓 Year Level Promotion - CHMSU Clearance";
                $body = "<html>
                <head>
                    <style>
                        body { font-family: 'Times New Roman', Times, serif; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; }
                        .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
                        .content { padding: 20px; }
                        .footer { text-align: center; font-size: 11px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; }
                        .highlight { background: #f1c40f; color: #1b4d3e; padding: 2px 10px; border-radius: 4px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>🎓 Year Level Promotion</h2>
                        </div>
                        <div class='content'>
                            <p>Dear <strong>" . $student['chmsu_full_name'] . "</strong>,</p>
                            <p>Congratulations! You have been <strong style='color:#1b4d3e;'>PROMOTED</strong> to the <strong style='color:#27ae60;'>" . $new_year . "th Year</strong>.</p>
                            <p><strong>Current Year:</strong> " . $student['chmsu_year'] . "th Year</p>
                            <p><strong>New Year Level:</strong> <span class='highlight'>" . $new_year . "th Year</span></p>
                            <p>Your clearance status has been updated. Please log in to the CHMSU E-Clearance System to view your updated profile.</p>
                            <p>Thank you and continue to strive for excellence!</p>
                            <br>
                            <p>Best regards,</p>
                            <p><strong>CHMSU Registrar's Office</strong></p>
                        </div>
                        <div class='footer'>
                            <p>CHMSU E-Clearance System &bull; This is an automated notification.</p>
                        </div>
                    </div>
                </body>
                </html>";
                sendEmail($student_email['email'], $student['chmsu_full_name'], $subject, $body);
            }
        }
        
        // Send to graduated students
        foreach ($graduated_students as $student) {
            $student_email = $conn->query("SELECT email FROM chmsu_user_accounts WHERE chmsu_student_id='" . $student['chmsu_student_id'] . "'")->fetch_assoc();
            if ($student_email && $student_email['email']) {
                $subject = "🎓 Graduation Confirmation - CHMSU Clearance";
                $body = "<html>
                <head>
                    <style>
                        body { font-family: 'Times New Roman', Times, serif; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; }
                        .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
                        .content { padding: 20px; }
                        .footer { text-align: center; font-size: 11px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>🎓 Graduation Confirmation</h2>
                        </div>
                        <div class='content'>
                            <p>Dear <strong>" . $student['chmsu_full_name'] . "</strong>,</p>
                            <p>Congratulations on your <strong style='color:#27ae60;'>GRADUATION</strong>!</p>
                            <p>You have successfully completed all requirements and have been officially graduated.</p>
                            <p>Your account has been archived. You can still access your clearance certificate anytime.</p>
                            <p>We wish you all the best in your future endeavors!</p>
                            <br>
                            <p>Best regards,</p>
                            <p><strong>CHMSU Registrar's Office</strong></p>
                        </div>
                        <div class='footer'>
                            <p>CHMSU E-Clearance System &bull; This is an automated notification.</p>
                        </div>
                    </div>
                </body>
                </html>";
                sendEmail($student_email['email'], $student['chmsu_full_name'], $subject, $body);
            }
        }
        
        logActivity($conn, $office, 'registrar', "Sent promotion notifications to " . count($promoted_students) . " promoted and " . count($graduated_students) . " graduated students");
    }
    
    logActivity($conn, $office, 'registrar', "Promoted $promoted students, archived $archived graduates, kept $irregular_4th_kept irregular 4th year students");
    $success = "Promotion complete! $promoted students promoted, $archived students graduated, $irregular_4th_kept irregular 4th year students remain active.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Student Promotion</title>
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
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #333; font-size: 12px; }
        input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            font-size: 13px;
        }
        input:focus { outline: none; border-color: #1b4d3e; }
        
        .btn {
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-primary:hover { background: #2d6a4f; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        
        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode input { background: #2c2c2c; border-color: #444; color: #fff; }
        
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
            <li><a href="?section=archived"><i class="fas fa-archive"></i> Archive</a></li>
            <li><a href="?section=promotion" class="active"><i class="fas fa-arrow-up"></i> Student Promotion</a></li>
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
            <h2>Student Promotion</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="content-card">
            <div class="content-card-header">Promote Students to Next Year Level</div>
            <div class="content-card-body">
                <form method="POST" onsubmit="return confirm('This will promote ALL students to the next year level. This action cannot be undone. Continue?')">
                    <input type="hidden" name="action" value="promote_students">
                    <button type="submit" class="btn btn-warning">Promote All Students to Next Year</button>
                </form>
                <p style="font-size: 11px; color: #666; margin-top: 10px;">
                    <i class="fas fa-info-circle"></i> 
                    <strong>How it works:</strong> 
                    All 1st-3rd year students will move to the next year level. 
                    4th year regular students will be marked as graduated and moved to archive. 
                    Irregular 4th year students cannot graduate and remain active.
                </p>
                <p style="font-size: 11px; color: #666; margin-top: 5px;">
                    <i class="fas fa-envelope"></i> 
                    <strong>Email Notifications:</strong> 
                    Students will receive an email notification when they are promoted or graduated.
                </p>
            </div>
        </div>
        
        <div class="content-card">
            <div class="content-card-header">Promotion Guidelines</div>
            <div class="content-card-body">
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li><strong>1st Year:</strong> Promoted to 2nd Year</li>
                    <li><strong>2nd Year:</strong> Promoted to 3rd Year</li>
                    <li><strong>3rd Year:</strong> Promoted to 4th Year</li>
                    <li><strong>4th Year (Regular):</strong> Graduated and moved to Archive</li>
                    <li><strong>Irregular (4th Year):</strong> Cannot graduate. Stay in 4th year until requirements are completed.</li>
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
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) {
            btn.innerHTML = isDarkMode ? '<i class="fas fa-sun"></i> Light Mode' : '<i class="fas fa-moon"></i> Dark Mode';
        }
    }
    
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
        }
    }
    
    function confirmLogout() {
        if(confirm('Are you sure you want to logout?')) {
            window.location.href = '?logout=1';
        }
    }
</script>

</body>
</html>