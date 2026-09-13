<?php
$office = $_SESSION['office'];
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentOfficeSection = 'dashboard';

// ============================================
// OFFICE STATISTICS & ANALYTICS
// ============================================

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_clearance_status (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(50) NOT NULL,
    office_name VARCHAR(100) NOT NULL,
    status ENUM('clear', 'unclear', 'pending') DEFAULT 'pending',
    note TEXT,
    updated_by VARCHAR(100),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_status (student_id, office_name)
)");

// Count students with status 'clear' for this office
$clearCount = $conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$office' AND status='clear'")->fetch_assoc()['c'];

// Count students with status 'unclear' for this office
$unclearCount = $conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$office' AND status='unclear'")->fetch_assoc()['c'];

// Total active students (not archived)
$totalActiveStudents = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0")->fetch_assoc()['c'];

// Pending = total active - (clear + unclear)
$pendingCount = $totalActiveStudents - ($clearCount + $unclearCount);
if ($pendingCount < 0) $pendingCount = 0;

// Clearance percentage
$clearancePercentage = ($totalActiveStudents > 0) ? round(($clearCount / $totalActiveStudents) * 100, 1) : 0;

// Get recent activities
$recentActivities = $conn->query("SELECT * FROM chmsu_activity_log WHERE user_type='office' AND user_id='$office' ORDER BY created_at DESC LIMIT 10");

// ============================================
// GET OFFICE EMAIL INFO - SAME AS ADMIN
// ============================================
$office_info = null;
$has_email = false;

// Check if email column exists in chmsu_auth_roles
$check_column = $conn->query("SHOW COLUMNS FROM chmsu_auth_roles LIKE 'email'");
if ($check_column && $check_column->num_rows > 0) {
    $office_result = $conn->query("SELECT chmsu_role as office_name, email, app_password FROM chmsu_auth_roles WHERE chmsu_role='$office'");
    if ($office_result && $office_result->num_rows > 0) {
        $office_info = $office_result->fetch_assoc();
        $has_email = !empty($office_info['email']);
    }
}

// Get chat unread count for sidebar badge
$chat_unread_count = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$office'")->fetch_assoc()['total'] ?: 0;

// ============================================
// CHECK GMAIL CONNECTED - SAME AS ADMIN
// ============================================
if (isset($_GET['gmail_connected']) && $_GET['gmail_connected'] == 1 && $has_email && $office_info) {
    $to = $office_info['email'];
    $subject = "Gmail Connected to CHMSU E Clearance System";
    $message = "Your Gmail account has been successfully connected to the CHMSU E Clearance System.\n\n";
    $message .= "You will now receive OTP codes and notifications for secure office actions.\n\n";
    $message .= "Thank you,\nCHMSU E Clearance System";
    $headers = "From: CHMSU E Clearance <noreply@chmsu.edu.ph>\r\n";
    $headers .= "Reply-To: noreply@chmsu.edu.ph\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    @mail($to, $subject, $message, $headers);
}

// Office-specific stats for charts
$totalStudents = $conn->query("SELECT COUNT(*) as c FROM chmsu_user_accounts")->fetch_assoc()['c'];
$totalRequirements = $conn->query("SELECT COUNT(*) as c FROM chmsu_requirements WHERE chmsu_office='$office'")->fetch_assoc()['c'];
$totalSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='$office'")->fetch_assoc()['c'];
$pendingSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='$office' AND s.chmsu_status='Submitted'")->fetch_assoc()['c'];
$approvedSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='$office' AND s.chmsu_status='Approved'")->fetch_assoc()['c'];
$declinedSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='$office' AND s.chmsu_status='Declined'")->fetch_assoc()['c'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - <?php echo strtoupper($office); ?> Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            border-radius: 0;
        }

        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 15px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            border-radius: 0;
        }

        .dashboard-wrapper {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        .office-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            overflow-x: hidden;
        }

        .office-sidebar::-webkit-scrollbar {
            width: 5px;
        }

        .office-sidebar::-webkit-scrollbar-track {
            background: #2d6a4f;
        }

        .office-sidebar::-webkit-scrollbar-thumb {
            background: #f1c40f;
            border-radius: 5px;
        }

        .office-sidebar .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #2d6a4f;
        }

        .office-sidebar .sidebar-header h3 {
            font-size: 16px;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
        }

        .office-sidebar .sidebar-header p {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
        }

        .office-sidebar .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
            padding-bottom: 20px;
        }

        .office-sidebar .sidebar-menu li {
            border-bottom: 1px solid #2d6a4f;
        }

        .office-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.3s;
        }

        .office-sidebar .sidebar-menu a:hover,
        .office-sidebar .sidebar-menu a.active {
            background: #f1c40f;
            color: #000000;
        }

        /* Uniform Chat Link Style - matches other sidebar links */
        .office-sidebar .sidebar-menu a.chat-link {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.3s;
            position: relative;
        }

        .office-sidebar .sidebar-menu a.chat-link:hover {
            background: #f1c40f;
            color: #000000;
        }

        .office-sidebar .sidebar-menu a.chat-link .chat-badge {
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 10px;
            min-width: 20px;
            text-align: center;
            float: right;
            margin-top: 2px;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.15); }
            100% { transform: scale(1); }
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px;
            background: #f5f5f5;
            min-height: calc(100vh - 73px);
        }

        .dashboard-header-bar {
            background: white;
            padding: 12px 20px;
            border: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .dashboard-header-bar h2 {
            font-size: 18px;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
            color: #1b4d3e;
        }

        .stats-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1px;
            background: #ddd;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        .stat-card {
            flex: 1;
            background: white;
            padding: 8px 5px;
            text-align: center;
            min-width: 100px;
        }
        .stat-card .number {
            font-size: 20px;
            font-weight: bold;
            color: #1b4d3e;
            line-height: 1.2;
        }
        .stat-card .label {
            font-size: 10px;
            color: #666;
            margin-top: 2px;
        }

        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1px;
            background: #ddd;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        .stats-row .stat-card {
            flex: 1;
            background: white;
            padding: 6px 5px;
            text-align: center;
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
            font-size: 12px;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
        }
        .content-card-body { padding: 15px; }

        .activity-log { max-height: 300px; overflow-y: auto; }
        .activity-item { 
            padding: 8px 12px; 
            border-bottom: 1px solid #eee; 
            font-size: 12px; 
        }
        .activity-item .time { color: #666; font-size: 10px; margin-top: 3px; }
        .activity-item .user { font-weight: bold; color: #1b4d3e; }
        .activity-item:last-child { border-bottom: none; }

        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .chart-card {
            background: white;
            border: 1px solid #ddd;
            padding: 10px;
        }
        .chart-card-header {
            font-size: 12px;
            font-weight: bold;
            color: #1b4d3e;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }
        canvas { max-height: 150px; width: 100%; }

        .merged-legend {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 8px;
            font-size: 10px;
            flex-wrap: wrap;
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .legend-color {
            width: 10px;
            height: 10px;
            border-radius: 2px;
        }

        /* ============================================ */
        /* EMAIL CONNECTION UI - SAME AS ADMIN */
        /* ============================================ */
        .email-connection-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
        }
        .email-connection-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .email-connection-header {
            padding: 14px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .email-connection-title {
            font-size: 15px;
            font-weight: 600;
            color: #1b4d3e;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .email-connection-title i {
            color: #1b4d3e;
        }
        .status-badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-connected {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .status-disconnected {
            background: #fce4ec;
            color: #c62828;
        }
        .email-connection-body {
            padding: 16px 20px;
        }
        .email-connected-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .email-avatar {
            flex-shrink: 0;
        }
        .avatar-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 600;
            color: white;
        }
        .email-details {
            flex: 1;
            min-width: 150px;
        }
        .email-address {
            font-size: 15px;
            font-weight: 500;
            color: #333;
        }
        .email-status {
            font-size: 12px;
            color: #666;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
        }
        .dot-connected {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4caf50;
            display: inline-block;
            animation: pulse-green 2s infinite;
        }
        @keyframes pulse-green {
            0% { opacity: 1; }
            50% { opacity: 0.4; }
            100% { opacity: 1; }
        }
        .email-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }
        .btn-email-change {
            padding: 5px 14px;
            background: #1b4d3e;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            transition: background 0.3s;
            font-family: 'Times New Roman', Times, serif;
        }
        .btn-email-change:hover {
            background: #2d6a4f;
        }
        .email-disconnected {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 16px;
            padding: 10px 0;
        }
        .email-disconnected-icon {
            width: 64px;
            height: 64px;
            background: #f0f4f3;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #1b4d3e;
        }
        .email-disconnected-text h4 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
            font-family: 'Times New Roman', Times, serif;
        }
        .email-disconnected-text p {
            font-size: 13px;
            color: #666;
            margin: 0;
        }
        .btn-connect-gmail {
            padding: 10px 28px;
            background: #4285F4;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s;
            font-family: 'Times New Roman', Times, serif;
            box-shadow: 0 2px 8px rgba(66, 133, 244, 0.3);
        }
        .btn-connect-gmail:hover {
            background: #3367D6;
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(66, 133, 244, 0.4);
        }
        .btn-connect-gmail i {
            font-size: 18px;
        }

        /* ============================================ */
        /* EMAIL MODAL - COMPACT, ONLY SIGN IN BUTTON */
        /* ============================================ */
        .email-modal-overlay {
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
            backdrop-filter: blur(4px);
        }
        .email-modal-overlay.active {
            display: flex;
        }
        .email-modal {
            background: #ffffff;
            max-width: 380px;
            width: 92%;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideUp 0.3s ease;
        }
        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .email-modal-header {
            padding: 16px 22px 10px 22px;
            background: #ffffff;
            color: #202124;
            display: flex;
            justify-content: center;
            align-items: center;
            border-bottom: none;
            position: relative;
        }
        .email-modal-header h3 {
            font-size: 17px;
            font-weight: 500;
            font-family: 'Times New Roman', Times, serif;
            color: #202124;
        }
        .email-modal-close {
            position: absolute;
            right: 16px;
            top: 12px;
            background: none;
            border: none;
            color: #5f6368;
            font-size: 24px;
            cursor: pointer;
            opacity: 0.6;
            transition: opacity 0.3s;
            padding: 4px 8px;
        }
        .email-modal-close:hover {
            opacity: 1;
        }
        .email-modal-body {
            padding: 4px 24px 22px 24px;
            text-align: center;
            background: #ffffff;
        }
        .email-modal-subtitle {
            font-size: 13px;
            color: #5f6368;
            margin-bottom: 18px;
            line-height: 1.5;
            font-family: 'Times New Roman', Times, serif;
        }

        .btn-google-signin {
            width: 100%;
            padding: 12px 18px;
            background: #ffffff;
            color: #3c4043;
            border: 1px solid #dadce0;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.2s;
            font-family: 'Times New Roman', Times, serif;
            box-shadow: none;
        }
        .btn-google-signin:hover {
            background: #f8f9fa;
            border-color: #c6c8ca;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            transform: none;
        }
        .btn-google-signin:active {
            background: #f1f3f4;
        }
        .btn-google-signin svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        .btn-google-signin .btn-text {
            color: #3c4043;
            font-family: 'Times New Roman', Times, serif;
        }

        .email-modal-footer {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #f1f3f4;
            display: flex;
            justify-content: center;
            gap: 12px;
        }
        .btn-cancel-modal {
            padding: 8px 24px;
            background: #f8f9fa;
            color: #3c4043;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
            transition: background 0.2s;
            font-weight: 500;
        }
        .btn-cancel-modal:hover {
            background: #f1f3f4;
        }

        /* Dark Mode */
        body.dark-mode .email-modal {
            background: #202124;
        }
        body.dark-mode .email-modal-header {
            background: #202124;
        }
        body.dark-mode .email-modal-header h3 {
            color: #e8eaed;
        }
        body.dark-mode .email-modal-close {
            color: #9aa0a6;
        }
        body.dark-mode .email-modal-body {
            background: #202124;
        }
        body.dark-mode .email-modal-subtitle {
            color: #9aa0a6;
        }
        body.dark-mode .btn-google-signin {
            background: #2d2e30;
            border-color: #3c4043;
            color: #e8eaed;
        }
        body.dark-mode .btn-google-signin:hover {
            background: #3c4043;
            border-color: #5f6368;
        }
        body.dark-mode .btn-google-signin .btn-text {
            color: #e8eaed;
        }
        body.dark-mode .btn-cancel-modal {
            background: #2d2e30;
            color: #e8eaed;
        }
        body.dark-mode .btn-cancel-modal:hover {
            background: #3c4043;
        }
        body.dark-mode .email-modal-footer {
            border-top-color: #3c4043;
        }
        body.dark-mode .email-connection-card {
            background: #1a1a1a;
            border-color: #333;
        }
        body.dark-mode .email-connection-header {
            background: #2c2c2c;
            border-color: #333;
        }
        body.dark-mode .email-connection-title {
            color: #f1c40f;
        }
        body.dark-mode .email-address {
            color: #fff;
        }
        body.dark-mode .email-status {
            color: #ccc;
        }
        body.dark-mode .email-disconnected-icon {
            background: #2c2c2c;
            color: #f1c40f;
        }
        body.dark-mode .email-disconnected-text h4 {
            color: #fff;
        }
        body.dark-mode .email-disconnected-text p {
            color: #ccc;
        }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .stat-card,
        body.dark-mode .chart-card,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .stat-card .number { color: #f1c40f; }
        body.dark-mode .stat-card .label { color: #ccc; }
        body.dark-mode .activity-item { border-bottom-color: #333; color: #fff; }
        body.dark-mode .activity-item .time { color: #aaa; }
        body.dark-mode .activity-item .user { color: #f1c40f; }
        body.dark-mode .office-sidebar::-webkit-scrollbar-track { background: #0f3b2f; }

        @media (max-width: 600px) {
            .email-connected-info {
                flex-direction: column;
                align-items: flex-start;
            }
            .email-actions {
                margin-left: 0;
                width: 100%;
            }
            .email-actions form,
            .email-actions button {
                width: 100%;
                justify-content: center;
            }
            .btn-connect-gmail {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .office-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .stats-container { flex-wrap: wrap; }
            .stats-row { flex-wrap: wrap; }
            .charts-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 480px) {
            .charts-grid { grid-template-columns: 1fr; }
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
        <p>CLEARANCE SYSTEM | <?php echo strtoupper($office); ?> Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <!-- ============================================ -->
    <!-- OFFICE SIDEBAR - UNIFORM WITH OTHER PAGES    -->
    <!-- ============================================ -->
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
            <p>Clearance Management</p>
        </div>
        <ul class="sidebar-menu">
            <!-- Dashboard -->
            <li>
                <a href="?officesection=dashboard" class="active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <!-- Feed Updates -->
            <li>
                <a href="?officesection=note">
                    <i class="fas fa-rss"></i> Feed Updates
                </a>
            </li>
            <!-- Clearance -->
            <li>
                <a href="?officesection=clearance">
                    <i class="fas fa-clipboard-check"></i> Clearance
                </a>
            </li>
            <!-- Reports -->
            <li>
                <a href="?officesection=reports">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </li>
            <!-- ===== CHAT LINK - UNIFORM WITH OTHERS ===== -->
            <li>
                <a href="?officesection=chat" class="chat-link">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php if ($chat_unread_count > 0): ?>
                        <span class="chat-badge"><?php echo $chat_unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <!-- ===== END CHAT LINK ===== -->
            <!-- Logout -->
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
        <div style="padding: 12px 20px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px; border-radius: 2px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f; border-radius: 2px;"></div>
            </div>
        </div>
    </div>
    <!-- ============================================ -->
    <!-- END OF SIDEBAR                               -->
    <!-- ============================================ -->
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2><?php echo htmlspecialchars($office); ?> - Dashboard</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- EMAIL CONNECTION UI - SAME AS ADMIN -->
        <!-- ============================================ -->
        <div class="email-connection-card">
            <div class="email-connection-header">
                <div class="email-connection-title">
                    <i class="fas fa-envelope"></i>
                    <span>Email Connection</span>
                </div>
                <div class="email-connection-status">
                    <?php if ($has_email): ?>
                        <span class="status-badge status-connected">
                            <i class="fas fa-check-circle"></i> Connected
                        </span>
                    <?php else: ?>
                        <span class="status-badge status-disconnected">
                            <i class="fas fa-exclamation-circle"></i> Not Connected
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="email-connection-body">
                <?php if ($has_email && $office_info): ?>
                    <div class="email-connected-info">
                        <div class="email-avatar">
                            <?php
                            $initial = strtoupper(substr($office_info['email'], 0, 1));
                            $bg_color = ['#1b4d3e', '#2d6a4f', '#f39c12', '#e74c3c', '#3498db', '#9b59b6', '#1abc9c'][rand(0, 6)];
                            ?>
                            <div class="avatar-circle" style="background: <?php echo $bg_color; ?>;">
                                <?php echo $initial; ?>
                            </div>
                        </div>
                        <div class="email-details">
                            <div class="email-address"><?php echo htmlspecialchars($office_info['email']); ?></div>
                            <div class="email-status">
                                <span class="dot-connected"></span>
                                <span>Connected • OTP Enabled</span>
                            </div>
                        </div>
                        <div class="email-actions">
                            <button onclick="openEmailModal()" class="btn-email-change">
                                <i class="fas fa-edit"></i> Change
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="email-disconnected">
                        <div class="email-disconnected-icon">
                            <i class="fas fa-envelope-open-text"></i>
                        </div>
                        <div class="email-disconnected-text">
                            <h4>Connect Your Gmail Account</h4>
                            <p>Connect your Gmail to receive OTP codes and notifications for secure office actions.</p>
                        </div>
                        <div>
                            <button onclick="openEmailModal()" class="btn-connect-gmail">
                                <i class="fab fa-google"></i> Connect Gmail
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ROW 1 STATS -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="number" style="color: #27ae60;"><?php echo $clearCount; ?></div>
                <div class="label"><i class="fas fa-check-circle"></i> CLEARED</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #e74c3c;"><?php echo $unclearCount; ?></div>
                <div class="label"><i class="fas fa-times-circle"></i> NOT CLEARED</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #f39c12;"><?php echo $pendingCount; ?></div>
                <div class="label"><i class="fas fa-clock"></i> PENDING</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $clearancePercentage; ?>%</div>
                <div class="label"><i class="fas fa-chart-line"></i> Clearance Rate</div>
            </div>
        </div>

        <!-- ROW 2 STATS -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="number"><?php echo $totalStudents; ?></div>
                <div class="label"><i class="fas fa-users"></i> Total Students</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $totalRequirements; ?></div>
                <div class="label"><i class="fas fa-tasks"></i> Requirements</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $totalSubmissions; ?></div>
                <div class="label"><i class="fas fa-file-upload"></i> Submissions</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $pendingSubmissions; ?></div>
                <div class="label"><i class="fas fa-hourglass-half"></i> Pending</div>
            </div>
        </div>

        <!-- CHARTS SECTION -->
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-card-header">Clearance Status</div>
                <canvas id="clearanceStatusChart"></canvas>
                <div class="merged-legend">
                    <span class="legend-item"><span class="legend-color" style="background: #27ae60;"></span> Cleared <?php echo $clearCount; ?></span>
                    <span class="legend-item"><span class="legend-color" style="background: #e74c3c;"></span> Unclear <?php echo $unclearCount; ?></span>
                    <span class="legend-item"><span class="legend-color" style="background: #f39c12;"></span> Pending <?php echo $pendingCount; ?></span>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-card-header">Submission Status</div>
                <canvas id="submissionStatusChart"></canvas>
                <div class="merged-legend">
                    <span class="legend-item"><span class="legend-color" style="background: #f39c12;"></span> Pending <?php echo $pendingSubmissions; ?></span>
                    <span class="legend-item"><span class="legend-color" style="background: #27ae60;"></span> Approved <?php echo $approvedSubmissions; ?></span>
                    <span class="legend-item"><span class="legend-color" style="background: #e74c3c;"></span> Declined <?php echo $declinedSubmissions; ?></span>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-card-header">Clearance Progress</div>
                <canvas id="progressChart"></canvas>
                <div class="merged-legend">
                    <span class="legend-item"><span class="legend-color" style="background: #27ae60;"></span> Completed <?php echo $clearCount; ?></span>
                    <span class="legend-item"><span class="legend-color" style="background: #e74c3c;"></span> Remaining <?php echo max(0, $totalActiveStudents - $clearCount); ?></span>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-card-header">Activity Overview</div>
                <canvas id="activityOverviewChart"></canvas>
                <div class="merged-legend">
                    <span class="legend-item"><span class="legend-color" style="background: #3498db;"></span> Submissions <?php echo $totalSubmissions; ?></span>
                    <span class="legend-item"><span class="legend-color" style="background: #e67e22;"></span> Requirements <?php echo $totalRequirements; ?></span>
                </div>
            </div>
        </div>

        <!-- RECENT ACTIVITY -->
        <div class="content-card">
            <div class="content-card-header"><i class="fas fa-history"></i> Recent Activity</div>
            <div class="content-card-body">
                <div class="activity-log">
                    <?php if ($recentActivities && $recentActivities->num_rows > 0): ?>
                        <?php while ($log = $recentActivities->fetch_assoc()): ?>
                            <div class="activity-item">
                                <div><span class="user"><?php echo htmlspecialchars($log['user_id']); ?></span> - <?php echo htmlspecialchars($log['action']); ?></div>
                                <div class="time"><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="activity-item" style="text-align:center; color:#999;">No activity records found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- EMAIL MODAL - COMPACT, ONLY SIGN IN BUTTON -->
<!-- ============================================ -->
<div class="email-modal-overlay" id="emailModal">
    <div class="email-modal">
        <div class="email-modal-header">
            <h3>Connect Your Email</h3>
            <button class="email-modal-close" onclick="closeEmailModal()">&times;</button>
        </div>
        <div class="email-modal-body">
            <p class="email-modal-subtitle">
                Sign in with your Google account to connect your Gmail and receive OTP codes and notifications.
            </p>

            <button onclick="window.location.href='?officesection=dashboard&google_login=1'" class="btn-google-signin">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="20" height="20">
                    <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/>
                    <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/>
                    <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/>
                    <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z"/>
                </svg>
                <span class="btn-text">Sign in with Google</span>
            </button>

            <div class="email-modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeEmailModal()">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- OTP VERIFICATION MODAL FOR DELETE OPERATIONS -->
<!-- ============================================ -->
<div id="otpModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center; font-family:'Times New Roman', Times, serif;">
    <div style="background:white; max-width:420px; width:90%; padding:30px; border-radius:6px; box-shadow:0 10px 40px rgba(0,0,0,0.3); position:relative;">
        <div style="position:absolute; top:10px; right:15px; cursor:pointer; font-size:20px; color:#999;" onclick="closeOTPModal()">&times;</div>
        <h3 style="color:#1b4d3e; margin-bottom:8px; font-weight:normal;">
            <i class="fas fa-shield-alt" style="color:#f1c40f;"></i> Verify Delete Action
        </h3>
        <p style="font-size:12px; color:#666; margin-bottom:15px;">
            An OTP has been sent to your configured email. Enter it below to confirm deletion.
        </p>

        <div id="otpStatus" style="padding:8px; margin-bottom:12px; border-radius:4px; display:none;"></div>

        <div style="margin-bottom:15px;">
            <label style="display:block; font-size:12px; color:#333; margin-bottom:4px;">Item to Delete:</label>
            <div style="background:#f5f5f5; padding:8px 12px; border-radius:4px; font-size:13px; font-weight:bold; color:#1b4d3e;" id="otpItemName">-</div>
        </div>

        <div style="margin-bottom:15px;">
            <label style="display:block; font-size:12px; color:#333; margin-bottom:4px;">Enter OTP Code:</label>
            <div style="display:flex; gap:8px;">
                <input type="text" id="otpCodeInput" placeholder="Enter 6-digit OTP" style="flex:1; padding:10px; border:1px solid #ddd; border-radius:4px; font-size:18px; letter-spacing:3px; text-align:center; font-family:'Courier New', monospace;" maxlength="6" autocomplete="off">
                <button onclick="verifyOTP()" style="background:#1b4d3e; color:white; border:none; padding:10px 20px; border-radius:4px; cursor:pointer; font-family:'Times New Roman', Times, serif;">
                    <i class="fas fa-check"></i> Verify
                </button>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <span id="otpTimer" style="font-size:11px; color:#e74c3c;">
                <i class="fas fa-clock"></i> Expires in: <span id="otpCountdown">5:00</span>
            </span>
            <button onclick="resendOTP()" style="background:transparent; color:#1b4d3e; border:1px solid #1b4d3e; padding:6px 15px; border-radius:4px; cursor:pointer; font-size:11px; font-family:'Times New Roman', Times, serif;">
                <i class="fas fa-redo"></i> Resend OTP
            </button>
        </div>

        <div style="margin-top:15px; padding-top:12px; border-top:1px solid #eee; display:flex; gap:8px; justify-content:flex-end;">
            <button onclick="closeOTPModal()" style="background:#95a5a6; color:white; border:none; padding:6px 15px; border-radius:4px; cursor:pointer; font-family:'Times New Roman', Times, serif; font-size:12px;">
                Cancel
            </button>
        </div>
    </div>
</div>

<form id="otpForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="verify_delete_otp">
    <input type="hidden" name="table" id="otpTable">
    <input type="hidden" name="item_id" id="otpItemId">
    <input type="hidden" name="otp_code" id="otpCodeHidden">
</form>

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

    function openEmailModal() {
        document.getElementById('emailModal').classList.add('active');
    }

    function closeEmailModal() {
        document.getElementById('emailModal').classList.remove('active');
    }

    // Charts
    new Chart(document.getElementById('clearanceStatusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Cleared', 'Unclear', 'Pending'],
            datasets: [{ 
                data: [<?php echo $clearCount; ?>, <?php echo $unclearCount; ?>, <?php echo $pendingCount; ?>], 
                backgroundColor: ['#27ae60', '#e74c3c', '#f39c12'], 
                borderWidth: 0 
            }]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } } }
    });

    new Chart(document.getElementById('submissionStatusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Pending', 'Approved', 'Declined'],
            datasets: [{ 
                data: [<?php echo $pendingSubmissions; ?>, <?php echo $approvedSubmissions; ?>, <?php echo $declinedSubmissions; ?>], 
                backgroundColor: ['#f39c12', '#27ae60', '#e74c3c'], 
                borderWidth: 0 
            }]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } } }
    });

    new Chart(document.getElementById('progressChart'), {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'Remaining'],
            datasets: [{ 
                data: [<?php echo $clearCount; ?>, <?php echo max(0, $totalActiveStudents - $clearCount); ?>], 
                backgroundColor: ['#27ae60', '#e74c3c'], 
                borderWidth: 0 
            }]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } } }
    });

    new Chart(document.getElementById('activityOverviewChart'), {
        type: 'doughnut',
        data: {
            labels: ['Submissions', 'Requirements'],
            datasets: [{ 
                data: [<?php echo $totalSubmissions; ?>, <?php echo $totalRequirements; ?>], 
                backgroundColor: ['#3498db', '#e67e22'], 
                borderWidth: 0 
            }]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } } }
    });

    let otpTimerInterval = null;
    let otpCountdownSeconds = 300;
    let currentDeleteData = { table: '', item_id: 0, item_name: '' };

    function openOTPModal(table, itemId, itemName) {
        currentDeleteData = { table: table, item_id: itemId, item_name: itemName };
        document.getElementById('otpItemName').textContent = itemName;
        document.getElementById('otpTable').value = table;
        document.getElementById('otpItemId').value = itemId;
        document.getElementById('otpCodeInput').value = '';
        document.getElementById('otpCodeHidden').value = '';
        document.getElementById('otpCodeInput').disabled = false;
        document.getElementById('otpModal').style.display = 'flex';
        sendOTP(table, itemId, itemName);
    }

    function closeOTPModal() {
        document.getElementById('otpModal').style.display = 'none';
        if (otpTimerInterval) {
            clearInterval(otpTimerInterval);
            otpTimerInterval = null;
        }
        document.getElementById('otpStatus').style.display = 'none';
    }

    function sendOTP(table, itemId, itemName) {
        const statusDiv = document.getElementById('otpStatus');
        statusDiv.style.display = 'block';
        statusDiv.style.background = '#f0f8ff';
        statusDiv.style.color = '#1b4d3e';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending OTP to your email...';

        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=send_delete_otp&table=' + encodeURIComponent(table) + '&item_id=' + itemId + '&item_name=' + encodeURIComponent(itemName)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                statusDiv.style.background = '#d4edda';
                statusDiv.style.color = '#155724';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                startOTPTimer();
            } else {
                statusDiv.style.background = '#f8d7da';
                statusDiv.style.color = '#721c24';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
            }
        })
        .catch(error => {
            statusDiv.style.background = '#f8d7da';
            statusDiv.style.color = '#721c24';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Failed to send OTP. Please try again.';
        });
    }

    function startOTPTimer() {
        if (otpTimerInterval) {
            clearInterval(otpTimerInterval);
        }
        otpCountdownSeconds = 300;
        updateOTPTimerDisplay();

        otpTimerInterval = setInterval(function() {
            otpCountdownSeconds--;
            updateOTPTimerDisplay();

            if (otpCountdownSeconds <= 0) {
                clearInterval(otpTimerInterval);
                otpTimerInterval = null;
                const statusDiv = document.getElementById('otpStatus');
                statusDiv.style.display = 'block';
                statusDiv.style.background = '#f8d7da';
                statusDiv.style.color = '#721c24';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> OTP has expired. Please click "Resend OTP" to get a new code.';
                document.getElementById('otpCodeInput').disabled = true;
            }
        }, 1000);
    }

    function updateOTPTimerDisplay() {
        const minutes = Math.floor(otpCountdownSeconds / 60);
        const seconds = otpCountdownSeconds % 60;
        document.getElementById('otpCountdown').textContent =
            String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

        if (otpCountdownSeconds < 60) {
            document.getElementById('otpCountdown').style.color = '#e74c3c';
        } else {
            document.getElementById('otpCountdown').style.color = '#27ae60';
        }
    }

    function verifyOTP() {
        const otpCode = document.getElementById('otpCodeInput').value.trim();
        if (otpCode.length !== 6 || !/^\d{6}$/.test(otpCode)) {
            const statusDiv = document.getElementById('otpStatus');
            statusDiv.style.display = 'block';
            statusDiv.style.background = '#f8d7da';
            statusDiv.style.color = '#721c24';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a valid 6-digit OTP code.';
            return;
        }

        const statusDiv = document.getElementById('otpStatus');
        statusDiv.style.display = 'block';
        statusDiv.style.background = '#f0f8ff';
        statusDiv.style.color = '#1b4d3e';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying OTP...';

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
            if (data.success) {
                statusDiv.style.background = '#d4edda';
                statusDiv.style.color = '#155724';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message + ' Refreshing page...';

                if (otpTimerInterval) {
                    clearInterval(otpTimerInterval);
                    otpTimerInterval = null;
                }

                setTimeout(function() {
                    window.location.reload();
                }, 1500);
            } else {
                statusDiv.style.background = '#f8d7da';
                statusDiv.style.color = '#721c24';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
            }
        })
        .catch(error => {
            statusDiv.style.background = '#f8d7da';
            statusDiv.style.color = '#721c24';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Verification failed. Please try again.';
        });
    }

    function resendOTP() {
        const statusDiv = document.getElementById('otpStatus');
        statusDiv.style.display = 'block';
        statusDiv.style.background = '#f0f8ff';
        statusDiv.style.color = '#1b4d3e';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending new OTP...';

        document.getElementById('otpCodeInput').disabled = false;
        document.getElementById('otpCodeInput').value = '';

        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=resend_delete_otp&table=' + encodeURIComponent(currentDeleteData.table) + '&item_id=' + currentDeleteData.item_id + '&item_name=' + encodeURIComponent(currentDeleteData.item_name)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                statusDiv.style.background = '#d4edda';
                statusDiv.style.color = '#155724';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                startOTPTimer();
            } else {
                statusDiv.style.background = '#f8d7da';
                statusDiv.style.color = '#721c24';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
            }
        })
        .catch(error => {
            statusDiv.style.background = '#f8d7da';
            statusDiv.style.color = '#721c24';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Failed to resend OTP. Please try again.';
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const otpInput = document.getElementById('otpCodeInput');
        if (otpInput) {
            otpInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    verifyOTP();
                }
            });
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEmailModal();
            closeOTPModal();
        }
    });

    window.onclick = function(e) {
        const emailModal = document.getElementById('emailModal');
        const otpModal = document.getElementById('otpModal');
        if (e.target === emailModal) {
            closeEmailModal();
        }
        if (e.target === otpModal) {
            closeOTPModal();
        }
    };
</script>

</body>
</html>