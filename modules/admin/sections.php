<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin'])) {
    header("Location: ../index.php");
    exit;
}

$admin = $_SESSION['admin'];
$adminSection = 'sections';
$error = '';
$success = '';

// Handle Section actions
if(isset($_POST['action'])) {
    if($_POST['action'] == 'add') {
        $course = sanitize($_POST['section_course']);
        $year = sanitize($_POST['section_year']);
        $section_name = sanitize($_POST['section_name']);
        
        $check = $conn->query("SELECT * FROM chmsu_course_sections WHERE course='$course' AND year='$year' AND section_name='$section_name'");
        if($check && $check->num_rows > 0) {
            $error = "Section already exists for this course and year!";
        } else {
            $stmt = $conn->prepare("INSERT INTO chmsu_course_sections (course, year, section_name) VALUES (?, ?, ?)");
            $stmt->bind_param("sis", $course, $year, $section_name);
            if($stmt->execute()) {
                logActivity($conn, $admin, 'admin', "Added section: $course-$year-$section_name");
                $success = "Section added successfully!";
            } else {
                $error = "Failed to add section: " . $conn->error;
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Manage Sections</title>
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
        
        .admin-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .admin-sidebar::-webkit-scrollbar { width: 5px; }
        .admin-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .admin-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .admin-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .admin-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        .admin-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; padding-bottom: 20px; }
        .admin-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-menu li.dropdown { border-bottom: none; }
        .admin-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
        }
        .admin-sidebar .sidebar-menu a:hover,
        .admin-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        .admin-sidebar .sidebar-menu .dropdown-menu {
            list-style: none;
            padding: 0;
            margin: 0;
            background: #0f3b2f;
            display: block;
        }
        .admin-sidebar .sidebar-menu .dropdown-menu li { border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-menu .dropdown-menu a { padding: 10px 20px 10px 35px; font-size: 12px; }
        .admin-sidebar .sidebar-menu .dropdown-menu a:hover,
        .admin-sidebar .sidebar-menu .dropdown-menu a.active { background: #f1c40f; color: #000000; }
        .dropdown-toggle::after { display: none; }
        
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
        input, select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            font-size: 13px;
        }
        input:focus, select:focus { outline: none; border-color: #1b4d3e; }
        
        .btn {
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-primary:hover { background: #2d6a4f; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
        
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .badge-section {
            background: #1b4d3e;
            color: white;
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 11px;
            display: inline-block;
        }
        
        .item-list {
            list-style: none;
            max-height: 500px;
            overflow-y: auto;
        }
        .item-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }
        .item-list li:hover {
            background: #f5f5f5;
        }
        
        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
        
        /* ============================================ */
        /* OTP MODAL - WIDER, SHORTER HEIGHT */
        /* ============================================ */
        .otp-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        .otp-modal-overlay.active {
            display: flex;
        }
        .otp-modal {
            background: #ffffff;
            max-width: 420px;
            width: 92%;
            padding: 16px 20px 14px 20px;
            border-radius: 10px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            animation: modalFadeIn 0.25s ease;
            font-family: 'Times New Roman', Times, serif;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-15px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .otp-modal .close-btn {
            position: absolute;
            top: 6px;
            right: 10px;
            cursor: pointer;
            font-size: 18px;
            color: #aaa;
            background: none;
            border: none;
            transition: color 0.2s;
            font-family: 'Times New Roman', Times, serif;
            line-height: 1;
        }
        .otp-modal .close-btn:hover { color: #333; }
        .otp-modal h3 {
            color: #1b4d3e;
            margin-bottom: 2px;
            font-weight: 600;
            font-size: 15px;
            font-family: 'Times New Roman', Times, serif;
            text-align: center;
        }
        .otp-modal .modal-subtitle {
            font-size: 11px;
            color: #888;
            margin-bottom: 10px;
            text-align: center;
            font-family: 'Times New Roman', Times, serif;
            line-height: 1.3;
        }
        .otp-modal .otp-status {
            padding: 5px 8px;
            margin-bottom: 8px;
            border-radius: 4px;
            display: none;
            font-size: 11px;
            font-family: 'Times New Roman', Times, serif;
            text-align: center;
        }
        .otp-modal .otp-status.success { background: #d4edda; color: #155724; display: block; border-left: 3px solid #27ae60; }
        .otp-modal .otp-status.error { background: #f8d7da; color: #721c24; display: block; border-left: 3px solid #e74c3c; }
        .otp-modal .otp-status.info { background: #f0f8ff; color: #1b4d3e; display: block; border-left: 3px solid #1b4d3e; }
        .otp-modal .otp-item-label {
            display: block;
            font-size: 10px;
            color: #666;
            margin-bottom: 2px;
            font-family: 'Times New Roman', Times, serif;
            text-align: center;
        }
        .otp-modal .otp-item-name {
            background: #f5f5f5;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            color: #1b4d3e;
            margin-bottom: 10px;
            border: 1px solid #e8e8e8;
            font-family: 'Times New Roman', Times, serif;
            text-align: center;
        }
        /* 6 OTP Boxes */
        .otp-boxes-container {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 10px;
        }
        .otp-box {
            width: 38px;
            height: 40px;
            border: 2px solid #d0d0d0;
            border-radius: 6px;
            text-align: center;
            font-size: 18px;
            font-weight: 600;
            font-family: 'Times New Roman', Times, serif;
            background: #fafafa;
            transition: all 0.2s;
            color: #1b4d3e;
            outline: none;
        }
        .otp-box:focus {
            border-color: #1b4d3e;
            background: #ffffff;
            box-shadow: 0 0 0 2px rgba(27, 77, 62, 0.12);
        }
        .otp-box.filled {
            border-color: #1b4d3e;
            background: #f0f7f5;
        }
        .otp-box.has-value {
            border-color: #1b4d3e;
            background: #eaf3ef;
        }
        .otp-box.loading {
            border-color: #f1c40f;
            background: #fef9e7;
            animation: pulse-border 1s infinite;
        }
        @keyframes pulse-border {
            0% { border-color: #f1c40f; }
            50% { border-color: #d4a800; }
            100% { border-color: #f1c40f; }
        }
        .otp-box.error-shake {
            animation: shake 0.35s ease;
            border-color: #e74c3c;
            background: #fde8e8;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }
        /* OTP Actions Row */
        .otp-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 8px;
        }
        .otp-timer {
            font-size: 11px;
            color: #e74c3c;
            font-family: 'Times New Roman', Times, serif;
        }
        .otp-timer .countdown {
            font-weight: 700;
            font-size: 13px;
        }
        .btn-resend {
            background: transparent;
            color: #1b4d3e;
            border: 1px solid #1b4d3e;
            padding: 2px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 10px;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.3s;
        }
        .btn-resend:hover { background: #1b4d3e; color: white; }
        .btn-resend:disabled { opacity: 0.4; cursor: not-allowed; }
        
        /* Verify Row - Button with green circle status */
        .otp-verify-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .btn-verify {
            padding: 5px 18px;
            background: #1b4d3e;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.3s;
            opacity: 0.5;
            pointer-events: none;
        }
        .btn-verify.active {
            opacity: 1;
            pointer-events: auto;
        }
        .btn-verify.active:hover { background: #2d6a4f; }
        .btn-verify:disabled { opacity: 0.5; pointer-events: none; }
        .btn-verify .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Status - Green Circle with "Sent" text (no button/square) */
        .otp-status-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 55px;
        }
        .otp-status-indicator .circle {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            color: white;
            font-weight: 700;
            flex-shrink: 0;
        }
        .otp-status-indicator .circle.show {
            display: flex;
        }
        .otp-status-indicator .circle.success {
            background: #27ae60;
            animation: popIn 0.3s ease;
        }
        .otp-status-indicator .circle.error {
            background: #e74c3c;
            animation: popIn 0.3s ease;
        }
        .otp-status-indicator .circle.loading {
            background: #f1c40f;
            animation: spin 0.8s linear infinite;
            border: 2px solid transparent;
            border-top-color: #fff;
        }
        @keyframes popIn {
            0% { transform: scale(0); }
            70% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        .otp-status-indicator .status-label {
            font-size: 11px;
            color: #666;
            font-family: 'Times New Roman', Times, serif;
            display: none;
        }
        .otp-status-indicator .status-label.show {
            display: inline;
        }
        .otp-status-indicator .status-label.success {
            color: #27ae60;
        }
        .otp-status-indicator .status-label.error {
            color: #e74c3c;
        }
        
        .modal-footer {
            margin-top: 6px;
            padding-top: 8px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .btn-cancel {
            background: #f0f0f0;
            color: #555;
            border: none;
            padding: 3px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            font-family: 'Times New Roman', Times, serif;
            transition: background 0.3s;
        }
        .btn-cancel:hover { background: #e0e0e0; }
        
        /* Dark Mode */
        body.dark-mode .otp-modal {
            background: #1a1a1a;
            color: #fff;
        }
        body.dark-mode .otp-modal h3 { color: #f1c40f; }
        body.dark-mode .otp-modal .modal-subtitle { color: #bbb; }
        body.dark-mode .otp-modal .otp-item-name {
            background: #2c2c2c;
            color: #f1c40f;
            border-color: #444;
        }
        body.dark-mode .otp-box {
            background: #2c2c2c;
            border-color: #555;
            color: #fff;
        }
        body.dark-mode .otp-box:focus {
            border-color: #f1c40f;
            background: #333;
            box-shadow: 0 0 0 2px rgba(241, 196, 15, 0.2);
        }
        body.dark-mode .otp-box.filled {
            border-color: #f1c40f;
            background: #2a3a2a;
        }
        body.dark-mode .otp-box.has-value {
            border-color: #f1c40f;
            background: #2a3a2a;
        }
        body.dark-mode .modal-footer { border-top-color: #333; }
        body.dark-mode .btn-cancel { background: #333; color: #ccc; }
        body.dark-mode .btn-cancel:hover { background: #444; }
        body.dark-mode .btn-resend { color: #f1c40f; border-color: #f1c40f; }
        body.dark-mode .btn-resend:hover { background: #f1c40f; color: #000; }
        body.dark-mode .otp-modal .otp-status.info { background: #1a3a2a; color: #8fdfb0; }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .item-list li { border-bottom-color: #333; color: #fff; }
        body.dark-mode .item-list li:hover { background: #2c2c2c; }
        body.dark-mode input, body.dark-mode select { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .badge-section { background: #f1c40f; color: #000; }
        
        @media (max-width: 768px) {
            .admin-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .otp-modal { padding: 14px 14px 10px 14px; max-width: 380px; }
            .otp-box { width: 32px; height: 34px; font-size: 15px; }
            .otp-boxes-container { gap: 5px; }
        }
        @media (max-width: 480px) {
            .otp-box { width: 28px; height: 30px; font-size: 13px; }
            .otp-boxes-container { gap: 3px; }
            .otp-modal { max-width: 340px; }
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
    <a href="?logout=1" class="logout-btn" style="text-decoration:none;">Logout</a>
</div>

<div class="dashboard-wrapper">
    <div class="admin-sidebar">
        <div class="sidebar-header">
            <h3>Admin: <?php echo htmlspecialchars($admin); ?></h3>
            <p>System Administrator</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?adminsection=dashboard" class="<?php echo $adminSection == 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a></li>
            
            <li class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class="fas fa-cogs"></i> Maintenance
                </a>
                <ul class="dropdown-menu">
                    <li><a href="?adminsection=courses" class="<?php echo $adminSection == 'courses' ? 'active' : ''; ?>">
                        <i class="fas fa-book"></i> Courses
                    </a></li>
                    <li><a href="?adminsection=offices" class="<?php echo $adminSection == 'offices' ? 'active' : ''; ?>">
                        <i class="fas fa-building"></i> Offices
                    </a></li>
                    <li><a href="?adminsection=sections" class="<?php echo $adminSection == 'sections' ? 'active' : ''; ?>">
                        <i class="fas fa-layer-group"></i> Sections
                    </a></li>
                    <li><a href="?adminsection=school_years" class="<?php echo $adminSection == 'school_years' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar"></i> School Years
                    </a></li>
                    <li><a href="?adminsection=semesters" class="<?php echo $adminSection == 'semesters' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Semesters
                    </a></li>
                </ul>
            </li>
            
            <li><a href="?adminsection=reports" class="<?php echo $adminSection == 'reports' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            
            <li><a href="?adminsection=activity" class="<?php echo $adminSection == 'activity' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Activity Log
            </a></li>
            
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="?logout=1">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Manage Sections</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="content-card">
            <div class="content-card-header">Add New Section</div>
            <div class="content-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="filter-row">
                        <div class="form-group" style="flex:1;">
                            <label>Course</label>
                            <select name="section_course" required>
                                <option value="">Select Course</option>
                                <?php 
                                $courses = $conn->query("SELECT * FROM chmsu_courses ORDER BY course_code");
                                while($c = $courses->fetch_assoc()): 
                                ?>
                                <option value="<?php echo $c['course_code']; ?>"><?php echo $c['course_code']; ?> - <?php echo $c['course_name']; ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0 0 120px;">
                            <label>Year Level</label>
                            <select name="section_year" required>
                                <option value="">Select Year</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0 0 120px;">
                            <label>Section Name</label>
                            <input type="text" name="section_name" placeholder="A" required>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">Add Section</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="content-card">
            <div class="content-card-header">Sections List</div>
            <div class="content-card-body">
                <ul class="item-list">
                    <?php 
                    $sections = $conn->query("SELECT * FROM chmsu_course_sections ORDER BY course, year, section_name");
                    if($sections && $sections->num_rows > 0):
                        while($s = $sections->fetch_assoc()): 
                    ?>
                    <li>
                        <span>
                            <strong><?php echo htmlspecialchars($s['course']); ?></strong> - 
                            <?php echo $s['year']; ?>th Year - 
                            <span class="badge-section"><?php echo htmlspecialchars($s['section_name']); ?></span>
                        </span>
                        <button type="button" class="btn btn-danger btn-sm" onclick="openOTPModal('sections', <?php echo $s['id']; ?>, '<?php echo addslashes($s['course'] . ' - ' . $s['year'] . 'th Year - Section ' . $s['section_name']); ?>')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </li>
                    <?php 
                        endwhile;
                    else:
                    ?>
                    <li style="text-align:center;">No sections found. Add your first section above.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- OTP VERIFICATION MODAL - WIDER, SHORTER -->
<!-- ============================================ -->
<div class="otp-modal-overlay" id="otpModal">
    <div class="otp-modal">
        <button class="close-btn" onclick="closeOTPModal()">&times;</button>
        <h3><i class="fas fa-shield-alt" style="color:#f1c40f;"></i> Verify Delete</h3>
        <p class="modal-subtitle">Enter the 6-digit OTP sent to your email.</p>
        
        <div id="otpStatus" class="otp-status"></div>
        
        <label class="otp-item-label">Item:</label>
        <div class="otp-item-name" id="otpItemName">-</div>
        
        <!-- 6 OTP Boxes -->
        <div class="otp-boxes-container" id="otpBoxesContainer">
            <input type="text" class="otp-box" id="otpBox1" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox2" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox3" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox4" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox5" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox6" maxlength="1" autocomplete="off">
        </div>
        
        <div class="otp-actions-row">
            <span class="otp-timer">
                <i class="fas fa-clock"></i> <span class="countdown" id="otpCountdown">2:00</span>
            </span>
            <button class="btn-resend" id="resendBtn" onclick="resendOTP()">
                <i class="fas fa-redo"></i> Resend
            </button>
        </div>
        
        <!-- Verify Row with Green Circle Status -->
        <div class="otp-verify-row">
            <button class="btn-verify" id="verifyBtn" onclick="verifyOTP()">
                Verify
            </button>
            <div class="otp-status-indicator" id="statusIndicator">
                <span class="circle" id="statusCircle"></span>
                <span class="status-label" id="statusLabel"></span>
            </div>
        </div>
        
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeOTPModal()">Cancel</button>
        </div>
        
        <!-- Hidden field to store combined OTP -->
        <input type="hidden" id="otpCodeHidden">
    </div>
</div>

<!-- Hidden form for OTP verification -->
<form id="otpForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="verify_delete_otp">
    <input type="hidden" name="table" id="otpTable">
    <input type="hidden" name="item_id" id="otpItemId">
    <input type="hidden" name="otp_code" id="otpCodeForm">
</form>

<script>
    // ============================================
    // DARK MODE
    // ============================================
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

    // ============================================
    // OTP VERIFICATION FUNCTIONS
    // ============================================
    
    let otpTimerInterval = null;
    let otpCountdownSeconds = 120;
    let currentDeleteData = { table: '', item_id: 0, item_name: '' };
    let isVerifying = false;

    function getOtpBoxes() {
        return [
            document.getElementById('otpBox1'),
            document.getElementById('otpBox2'),
            document.getElementById('otpBox3'),
            document.getElementById('otpBox4'),
            document.getElementById('otpBox5'),
            document.getElementById('otpBox6')
        ];
    }

    function getOTPValue() {
        const boxes = getOtpBoxes();
        return boxes.map(box => box.value).join('');
    }

    function isOTPComplete() {
        const boxes = getOtpBoxes();
        return boxes.every(box => box.value.length === 1 && /^\d$/.test(box.value));
    }

    function updateVerifyButton() {
        const btn = document.getElementById('verifyBtn');
        if (isOTPComplete() && !isVerifying && otpCountdownSeconds > 0) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    }

    function setStatusIndicator(circleClass, labelText, labelClass) {
        const circle = document.getElementById('statusCircle');
        const label = document.getElementById('statusLabel');
        
        circle.className = 'circle';
        label.className = 'status-label';
        
        if (circleClass) {
            circle.classList.add('show', circleClass);
        } else {
            circle.classList.remove('show');
        }
        
        if (labelText) {
            label.textContent = labelText;
            label.classList.add('show');
            if (labelClass) {
                label.classList.add(labelClass);
            }
        } else {
            label.classList.remove('show');
            label.textContent = '';
        }
    }

    function clearStatusIndicator() {
        setStatusIndicator(null, null, null);
    }

    function handleOtpInput(e, currentIndex) {
        const boxes = getOtpBoxes();
        const box = boxes[currentIndex];
        const val = e.target.value;
        
        if (val && !/^\d$/.test(val)) {
            box.value = '';
            return;
        }
        
        if (val) {
            box.classList.add('has-value');
            box.classList.remove('error-shake');
        } else {
            box.classList.remove('has-value');
        }
        
        if (val && currentIndex < 5) {
            boxes[currentIndex + 1].focus();
        }
        
        document.getElementById('otpCodeHidden').value = getOTPValue();
        updateVerifyButton();
        
        if (val && currentIndex === 5 && isOTPComplete()) {
            setTimeout(() => verifyOTP(), 300);
        }
    }

    function handleOtpKeydown(e, currentIndex) {
        const boxes = getOtpBoxes();
        const box = boxes[currentIndex];
        
        if (e.key === 'Backspace' && !box.value && currentIndex > 0) {
            boxes[currentIndex - 1].focus();
            boxes[currentIndex - 1].value = '';
            boxes[currentIndex - 1].classList.remove('has-value');
            updateVerifyButton();
        }
        
        if (e.key === 'Enter') {
            e.preventDefault();
            verifyOTP();
        }
    }

    function handleOtpPaste(e) {
        e.preventDefault();
        const paste = (e.clipboardData || window.clipboardData).getData('text');
        const digits = paste.replace(/\D/g, '').slice(0, 6);
        const boxes = getOtpBoxes();
        
        for (let i = 0; i < digits.length && i < 6; i++) {
            boxes[i].value = digits[i];
            boxes[i].classList.add('has-value');
        }
        
        const nextIndex = Math.min(digits.length, 5);
        if (nextIndex < 6) {
            boxes[nextIndex].focus();
        }
        
        document.getElementById('otpCodeHidden').value = getOTPValue();
        updateVerifyButton();
        
        if (digits.length === 6 && isOTPComplete()) {
            setTimeout(() => verifyOTP(), 300);
        }
    }

    function focusFirstBox() {
        const boxes = getOtpBoxes();
        setTimeout(() => boxes[0].focus(), 350);
    }

    function openOTPModal(table, itemId, itemName) {
        currentDeleteData = { table: table, item_id: itemId, item_name: itemName };
        
        document.getElementById('otpItemName').textContent = itemName;
        document.getElementById('otpTable').value = table;
        document.getElementById('otpItemId').value = itemId;
        
        const boxes = getOtpBoxes();
        boxes.forEach(box => {
            box.value = '';
            box.className = 'otp-box';
            box.disabled = false;
        });
        
        document.getElementById('otpCodeHidden').value = '';
        document.getElementById('otpStatus').className = 'otp-status';
        document.getElementById('otpStatus').style.display = 'none';
        
        const verifyBtn = document.getElementById('verifyBtn');
        verifyBtn.classList.remove('active');
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = 'Verify';
        isVerifying = false;
        
        document.getElementById('resendBtn').disabled = false;
        clearStatusIndicator();
        
        document.getElementById('otpModal').classList.add('active');
        
        focusFirstBox();
        sendOTP(table, itemId, itemName);
    }

    function closeOTPModal() {
        document.getElementById('otpModal').classList.remove('active');
        if (otpTimerInterval) {
            clearInterval(otpTimerInterval);
            otpTimerInterval = null;
        }
        document.getElementById('otpStatus').className = 'otp-status';
        document.getElementById('otpStatus').style.display = 'none';
        isVerifying = false;
        clearStatusIndicator();
    }

    function sendOTP(table, itemId, itemName) {
        const statusDiv = document.getElementById('otpStatus');
        statusDiv.className = 'otp-status info';
        statusDiv.style.display = 'block';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending OTP...';
        setStatusIndicator('loading', 'Sending', '');
        
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=send_delete_otp&table=' + encodeURIComponent(table) + '&item_id=' + itemId + '&item_name=' + encodeURIComponent(itemName)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                statusDiv.className = 'otp-status success';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                setStatusIndicator('success', 'Sent', 'success');
                setTimeout(() => clearStatusIndicator(), 2500);
                startOTPTimer();
                const boxes = getOtpBoxes();
                boxes.forEach(box => box.disabled = false);
                updateVerifyButton();
                focusFirstBox();
            } else {
                statusDiv.className = 'otp-status error';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                setStatusIndicator('error', 'Failed', 'error');
                const boxes = getOtpBoxes();
                boxes.forEach(box => box.disabled = true);
            }
        })
        .catch(error => {
            statusDiv.className = 'otp-status error';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Failed to send OTP.';
            setStatusIndicator('error', 'Error', 'error');
        });
    }

    function startOTPTimer() {
        if (otpTimerInterval) {
            clearInterval(otpTimerInterval);
        }
        otpCountdownSeconds = 120;
        updateOTPTimerDisplay();
        
        otpTimerInterval = setInterval(function() {
            otpCountdownSeconds--;
            updateOTPTimerDisplay();
            updateVerifyButton();
            
            if (otpCountdownSeconds <= 0) {
                clearInterval(otpTimerInterval);
                otpTimerInterval = null;
                const statusDiv = document.getElementById('otpStatus');
                statusDiv.className = 'otp-status error';
                statusDiv.style.display = 'block';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> OTP expired. Resend.';
                const boxes = getOtpBoxes();
                boxes.forEach(box => box.disabled = true);
                document.getElementById('resendBtn').disabled = false;
                updateVerifyButton();
            }
        }, 1000);
    }

    function updateOTPTimerDisplay() {
        const minutes = Math.floor(otpCountdownSeconds / 60);
        const seconds = otpCountdownSeconds % 60;
        document.getElementById('otpCountdown').textContent = 
            String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        
        if (otpCountdownSeconds < 30) {
            document.getElementById('otpCountdown').style.color = '#e74c3c';
        } else {
            document.getElementById('otpCountdown').style.color = '#27ae60';
        }
    }

    function verifyOTP() {
        if (isVerifying) return;
        
        const otpCode = getOTPValue();
        const statusDiv = document.getElementById('otpStatus');
        const boxes = getOtpBoxes();
        
        if (otpCode.length !== 6 || !/^\d{6}$/.test(otpCode)) {
            boxes.forEach(box => {
                if (!box.value) {
                    box.classList.add('error-shake');
                    setTimeout(() => box.classList.remove('error-shake'), 500);
                }
            });
            statusDiv.className = 'otp-status error';
            statusDiv.style.display = 'block';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Enter all 6 digits.';
            return;
        }
        
        isVerifying = true;
        const verifyBtn = document.getElementById('verifyBtn');
        verifyBtn.classList.remove('active');
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<span class="spinner"></span>';
        setStatusIndicator('loading', 'Verifying', '');
        
        boxes.forEach(box => box.classList.add('loading'));
        
        statusDiv.className = 'otp-status info';
        statusDiv.style.display = 'block';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
        
        const formData = new FormData();
        formData.append('action', 'verify_delete_otp');
        formData.append('otp_code', otpCode);
        formData.append('table', currentDeleteData.table);
        formData.append('item_id', currentDeleteData.item_id);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            boxes.forEach(box => box.classList.remove('loading'));
            
            if (data.success) {
                statusDiv.className = 'otp-status success';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                setStatusIndicator('success', 'Verified!', 'success');
                
                boxes.forEach(box => {
                    box.style.borderColor = '#27ae60';
                    box.style.background = '#d4edda';
                });
                
                if (otpTimerInterval) {
                    clearInterval(otpTimerInterval);
                    otpTimerInterval = null;
                }
                
                setTimeout(function() {
                    window.location.reload();
                }, 1200);
            } else {
                statusDiv.className = 'otp-status error';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                setStatusIndicator('error', 'Invalid', 'error');
                
                boxes.forEach(box => {
                    box.classList.add('error-shake');
                    setTimeout(() => box.classList.remove('error-shake'), 500);
                });
                
                verifyBtn.innerHTML = 'Verify';
                verifyBtn.disabled = false;
                updateVerifyButton();
                isVerifying = false;
            }
        })
        .catch(error => {
            boxes.forEach(box => box.classList.remove('loading'));
            statusDiv.className = 'otp-status error';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Verification failed.';
            setStatusIndicator('error', 'Error', 'error');
            verifyBtn.innerHTML = 'Verify';
            verifyBtn.disabled = false;
            updateVerifyButton();
            isVerifying = false;
        });
    }

    function resendOTP() {
        const statusDiv = document.getElementById('otpStatus');
        statusDiv.className = 'otp-status info';
        statusDiv.style.display = 'block';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending new OTP...';
        setStatusIndicator('loading', 'Resending', '');
        
        const boxes = getOtpBoxes();
        boxes.forEach(box => {
            box.value = '';
            box.className = 'otp-box';
            box.disabled = false;
        });
        document.getElementById('otpCodeHidden').value = '';
        document.getElementById('resendBtn').disabled = true;
        
        const verifyBtn = document.getElementById('verifyBtn');
        verifyBtn.classList.remove('active');
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = 'Verify';
        isVerifying = false;
        clearStatusIndicator();
        
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=resend_delete_otp&table=' + encodeURIComponent(currentDeleteData.table) + '&item_id=' + currentDeleteData.item_id + '&item_name=' + encodeURIComponent(currentDeleteData.item_name)
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById('resendBtn').disabled = false;
            if (data.success) {
                statusDiv.className = 'otp-status success';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                setStatusIndicator('success', 'Sent!', 'success');
                setTimeout(() => clearStatusIndicator(), 2000);
                startOTPTimer();
                updateVerifyButton();
                focusFirstBox();
            } else {
                statusDiv.className = 'otp-status error';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                setStatusIndicator('error', 'Failed', 'error');
                boxes.forEach(box => box.disabled = true);
            }
        })
        .catch(error => {
            document.getElementById('resendBtn').disabled = false;
            statusDiv.className = 'otp-status error';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Failed to resend.';
            setStatusIndicator('error', 'Error', 'error');
        });
    }

    // Setup OTP box event listeners
    document.addEventListener('DOMContentLoaded', function() {
        const boxes = getOtpBoxes();
        boxes.forEach((box, index) => {
            box.addEventListener('input', function(e) {
                handleOtpInput(e, index);
            });
            box.addEventListener('keydown', function(e) {
                handleOtpKeydown(e, index);
            });
            box.addEventListener('paste', function(e) {
                handleOtpPaste(e);
            });
            box.addEventListener('keypress', function(e) {
                if (!/^\d$/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete' && e.key !== 'Tab') {
                    e.preventDefault();
                }
            });
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeOTPModal();
            }
        });
    });

    window.onclick = function(e) {
        const modal = document.getElementById('otpModal');
        if (e.target === modal) {
            closeOTPModal();
        }
    };
</script>

</body>
</html>