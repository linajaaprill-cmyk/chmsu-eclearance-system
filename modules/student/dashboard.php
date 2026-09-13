<?php
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

// Get student's current year from database (not from session)
$current_year_result = $conn->query("SELECT chmsu_year FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'");
$current_student_year = '1';
if ($current_year_result && $current_year_result->num_rows > 0) {
    $current_student_year = $current_year_result->fetch_assoc()['chmsu_year'];
}

// Get all offices with their statuses
$offices_result = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
$offices = [];
$officeStatuses = [];
$officeCounts = [];
$officeUnreadComments = [];
$totalOffices = 0;
$approvedCount = 0;

while ($office_row = $offices_result->fetch_assoc()) {
    $office = $office_row['office_name'];
    $offices[] = $office;
    $totalOffices++;
    
    // Count requirements including NULL year/section (All Years/All Sections)
    $countQuery = $conn->query("SELECT COUNT(*) as c FROM chmsu_requirements 
                                WHERE chmsu_office='$office' 
                                AND chmsu_course='{$student['chmsu_course']}' 
                                AND (chmsu_year='$current_student_year' OR chmsu_year IS NULL OR chmsu_year = '')
                                AND (chmsu_section='{$student['chmsu_section']}' OR chmsu_section IS NULL OR chmsu_section = '')");
    $officeCounts[$office] = $countQuery->fetch_assoc()['c'];
    
    // Get status for this office
    $statusQuery = $conn->query("SELECT s.chmsu_status 
                                 FROM chmsu_submissions s
                                 JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id
                                 WHERE r.chmsu_office = '$office'
                                 AND s.chmsu_student_id = '{$_SESSION['student']}'
                                 ORDER BY s.chmsu_id DESC LIMIT 1");
    if ($statusQuery->num_rows > 0) {
        $statusRow = $statusQuery->fetch_assoc();
        $officeStatuses[$office] = $statusRow['chmsu_status'];
        if ($statusRow['chmsu_status'] == 'Approved') {
            $approvedCount++;
        }
    } else {
        $officeStatuses[$office] = 'Pending';
    }
    
    // Count unread comments
    $unreadQuery = $conn->query("SELECT COUNT(*) as c FROM chmsu_comment_notifications 
                                 WHERE student_id='{$_SESSION['student']}'
                                 AND office_name='$office'
                                 AND is_read=0");
    $officeUnreadComments[$office] = $unreadQuery->fetch_assoc()['c'];
}

// Check if all offices are approved
$allApproved = ($approvedCount == $totalOffices && $totalOffices > 0);
$totalRequirements = array_sum($officeCounts);
$completedRequirements = 0;

// Count completed requirements
foreach ($offices as $office) {
    if (isset($officeStatuses[$office]) && $officeStatuses[$office] == 'Approved') {
        $completedRequirements += $officeCounts[$office];
    }
}

$progressPercent = ($totalRequirements > 0) ? round(($completedRequirements / $totalRequirements) * 100) : 0;

// Check if student already has email
$student_email = $student['email'] ?? null;
$has_email = !empty($student_email);

// Check if Gmail was just connected
if (isset($_GET['gmail_connected']) && $_GET['gmail_connected'] == 1 && $has_email) {
    $success = "✅ Gmail connected successfully!";
}

// ============================================
// NOTIFICATIONS
// ============================================
$unread_notifications = $conn->query("
    SELECT COUNT(*) as c FROM chmsu_notifications 
    WHERE user_type='student' 
    AND user_id='{$_SESSION['student']}' 
    AND is_read=0
")->fetch_assoc()['c'];

$latest_notifications = $conn->query("
    SELECT * FROM chmsu_notifications 
    WHERE user_type='student' 
    AND user_id='{$_SESSION['student']}' 
    ORDER BY created_at DESC 
    LIMIT 5
");

// Get latest feed posts
$feed_posts = $conn->query("
    SELECT f.*, 
           (SELECT COUNT(*) FROM chmsu_feed_views WHERE feed_id = f.id) as view_count,
           (SELECT COUNT(*) FROM chmsu_feed_views WHERE feed_id = f.id AND student_id = '{$_SESSION['student']}') as user_viewed
    FROM chmsu_feed f
    ORDER BY f.created_at DESC
    LIMIT 5
");

// Count unread feed notifications for sidebar
$unreadFeed = $conn->query("SELECT COUNT(*) as c FROM chmsu_notifications 
                            WHERE user_type='student' 
                            AND user_id='{$_SESSION['student']}' 
                            AND type='feed' 
                            AND is_read=0")->fetch_assoc()['c'];

// Get chat unread count for header badge and sidebar badge
$chat_unread_count = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='{$_SESSION['student']}'")->fetch_assoc()['total'] ?: 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Student Dashboard</title>
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
        
        /* ===== CHAT ICON IN HEADER ===== */
        .chat-header-icon {
            position: relative;
            color: white;
            font-size: 20px;
            cursor: pointer;
            background: transparent;
            border: none;
            padding: 5px;
            margin-right: 10px;
            text-decoration: none;
            transition: all 0.3s;
        }
        .chat-header-icon:hover {
            color: #f1c40f;
            transform: scale(1.1);
        }
        .chat-header-icon .chat-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 9px;
            min-width: 18px;
            text-align: center;
            animation: pulse 1.5s infinite;
        }
        
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
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .dark-sidebar::-webkit-scrollbar { width: 5px; }
        .dark-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .dark-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .dark-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .dark-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .dark-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
        }
        .dark-sidebar .sidebar-menu a:hover,
        .dark-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
        /* Sidebar badge styles */
        .sidebar-badge {
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 1px 6px;
            font-size: 9px;
            margin-left: 5px;
            animation: pulse 1.5s infinite;
        }
        
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px;
            background: #f5f5f5;
            min-height: calc(100vh - 73px);
        }
        .info-bar {
            background: white;
            padding: 12px 15px;
            border: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        .info-bar-item { font-size: 12px; }
        .info-bar-item strong { color: #1b4d3e; }
        
        .office-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .office-card {
            background: white;
            border-radius: 6px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .office-card.status-pending { border-color: #f39c12; }
        .office-card.status-approved { border-color: #27ae60; }
        .office-card.status-declined { border-color: #e74c3c; color: #e74c3c; }
        .office-card h4 { color: #1b4d3e; font-size: 13px; margin-bottom: 5px; }
        .office-card .count { font-size: 10px; color: #666; }
        .office-card .status {
            display: inline-block;
            margin-top: 6px;
            font-size: 9px;
            font-weight: 600;
            padding: 3px 6px;
            border-radius: 3px;
        }
        .status-text-pending { background: #f39c12; color: white; }
        .status-text-approved { background: #27ae60; color: white; }
        .status-text-declined { background: #e74c3c; color: white; }
        
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .content-card-body { padding: 15px; }
        
        .office-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 8px;
            display: inline-block;
        }
        .status-dot-pending { background: #f39c12; }
        .status-dot-approved { background: #27ae60; }
        .status-dot-declined { background: #e74c3c; }
        
        .office-count {
            margin-left: auto;
            font-size: 10px;
            color: #dddddd;
        }
        
        .notification-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #e74c3c;
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }
        
        .storage-info {
            padding: 12px 20px;
            font-size: 10px;
            color: #dddddd;
            border-top: 1px solid #2d6a4f;
            margin-top: 20px;
        }
        
        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
        .hidden { display: none; }
        
        .rejected-name { color: #e74c3c; }
        
        .btn {
            padding: 6px 10px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        
        /* ===== EMAIL CONNECTION UI ===== */
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
            font-family: 'Times New Roman', Times, serif;
        }
        .email-connection-title i { color: #1b4d3e; }
        .status-badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Times New Roman', Times, serif;
        }
        .status-connected { background: #e8f5e9; color: #2e7d32; }
        .status-disconnected { background: #fce4ec; color: #c62828; }
        .email-connection-body { padding: 16px 20px; }
        .email-connected-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .email-avatar { flex-shrink: 0; }
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
        .email-details { flex: 1; min-width: 150px; }
        .email-address {
            font-size: 15px;
            font-weight: 500;
            color: #333;
            font-family: 'Times New Roman', Times, serif;
        }
        .email-status {
            font-size: 12px;
            color: #666;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
            font-family: 'Times New Roman', Times, serif;
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
        .btn-email-change:hover { background: #2d6a4f; }
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
            font-family: 'Times New Roman', Times, serif;
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
        .btn-connect-gmail i { font-size: 18px; }

        /* ===== EMAIL MODAL ===== */
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
        .email-modal-overlay.active { display: flex; }
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
        .email-modal-close:hover { opacity: 1; }
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
        .btn-google-signin:active { background: #f1f3f4; }
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
        .btn-cancel-modal:hover { background: #f1f3f4; }

        /* ===== NOTIFICATION DROPDOWN ===== */
        #notificationDropdown::-webkit-scrollbar {
            width: 4px;
        }
        #notificationDropdown::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        #notificationDropdown::-webkit-scrollbar-thumb {
            background: #1b4d3e;
            border-radius: 4px;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        /* ===== DARK MODE ===== */
        body.dark-mode .email-modal { background: #202124; }
        body.dark-mode .email-modal-header { background: #202124; }
        body.dark-mode .email-modal-header h3 { color: #e8eaed; }
        body.dark-mode .email-modal-close { color: #9aa0a6; }
        body.dark-mode .email-modal-body { background: #202124; }
        body.dark-mode .email-modal-subtitle { color: #9aa0a6; }
        body.dark-mode .btn-google-signin {
            background: #2d2e30;
            border-color: #3c4043;
            color: #e8eaed;
        }
        body.dark-mode .btn-google-signin:hover {
            background: #3c4043;
            border-color: #5f6368;
        }
        body.dark-mode .btn-google-signin .btn-text { color: #e8eaed; }
        body.dark-mode .btn-cancel-modal {
            background: #2d2e30;
            color: #e8eaed;
        }
        body.dark-mode .btn-cancel-modal:hover { background: #3c4043; }
        body.dark-mode .email-modal-footer { border-top-color: #3c4043; }
        body.dark-mode .email-connection-card {
            background: #1a1a1a;
            border-color: #333;
        }
        body.dark-mode .email-connection-header {
            background: #2c2c2c;
            border-color: #333;
        }
        body.dark-mode .email-connection-title { color: #f1c40f; }
        body.dark-mode .email-address { color: #fff; }
        body.dark-mode .email-status { color: #ccc; }
        body.dark-mode .email-disconnected-icon {
            background: #2c2c2c;
            color: #f1c40f;
        }
        body.dark-mode .email-disconnected-text h4 { color: #fff; }
        body.dark-mode .email-disconnected-text p { color: #ccc; }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .info-bar,
        body.dark-mode .office-card,
        body.dark-mode .content-card,
        body.dark-mode .email-connection-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .office-card h4 { color: #f1c40f; }
        body.dark-mode .office-card .count { color: #ccc; }
        
        @media (max-width: 768px) {
            .dark-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .office-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .office-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .email-connected-info { flex-direction: column; align-items: flex-start; }
            .email-actions { margin-left: 0; width: 100%; }
            .email-actions form,
            .email-actions button { width: 100%; justify-content: center; }
            .btn-connect-gmail { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo" style="display: none;"></div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Student Portal</p>
    </div>
    
    <!-- NOTIFICATION BELL -->
    <div style="position: relative; margin-right: 10px;">
        <button onclick="toggleNotifications()" style="background: transparent; border: none; color: white; font-size: 20px; cursor: pointer; position: relative; padding: 5px;">
            <i class="fas fa-bell"></i>
            <?php if ($unread_notifications > 0): ?>
                <span style="position: absolute; top: -5px; right: -5px; background: #e74c3c; color: white; border-radius: 50%; padding: 2px 6px; font-size: 9px; min-width: 18px; text-align: center; animation: pulse 1.5s infinite;">
                    <?php echo $unread_notifications > 9 ? '9+' : $unread_notifications; ?>
                </span>
            <?php endif; ?>
        </button>
        
        <!-- NOTIFICATION DROPDOWN -->
        <div id="notificationDropdown" style="display: none; position: absolute; right: 0; top: 40px; background: white; min-width: 320px; max-width: 400px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); z-index: 1000; max-height: 400px; overflow-y: auto; padding: 0;">
            <div style="padding: 12px 15px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; border-radius: 8px 8px 0 0;">
                <strong style="color: #1b4d3e; font-size: 14px;"><i class="fas fa-bell"></i> Notifications</strong>
                <?php if ($unread_notifications > 0): ?>
                    <button onclick="markAllRead()" style="background: transparent; border: none; color: #1b4d3e; font-size: 11px; cursor: pointer; text-decoration: underline;">Mark all read</button>
                <?php endif; ?>
            </div>
            
            <?php if ($latest_notifications && $latest_notifications->num_rows > 0): ?>
                <?php while ($notif = $latest_notifications->fetch_assoc()): ?>
                    <div style="padding: 10px 15px; border-bottom: 1px solid #f5f5f5; <?php echo $notif['is_read'] ? 'opacity: 0.7;' : 'background: #f0f8ff;'; ?> cursor: pointer;" onclick="window.location.href='<?php echo $notif['link'] ?: '?view=home'; ?>'">
                        <div style="font-size: 12px; color: #333;">
                            <?php echo htmlspecialchars($notif['message']); ?>
                        </div>
                        <div style="font-size: 10px; color: #999; margin-top: 3px; display: flex; justify-content: space-between;">
                            <span><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                            <?php if (!$notif['is_read']): ?>
                                <span style="background: #e74c3c; color: white; padding: 1px 8px; border-radius: 8px; font-size: 8px;">NEW</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 30px 20px; color: #999; font-size: 13px;">
                    <i class="fas fa-bell-slash" style="font-size: 30px; display: block; margin-bottom: 10px; color: #ddd;"></i>
                    No new notifications
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ===== CHAT ICON IN HEADER ===== -->
    <a href="?view=chat" class="chat-header-icon" title="Messages">
        <i class="fas fa-comment-dots"></i>
        <?php if ($chat_unread_count > 0): ?>
            <span class="chat-badge"><?php echo $chat_unread_count; ?></span>
        <?php endif; ?>
    </a>
    
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="dark-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($master['chmsu_full_name']); ?></h3>
            <p><?php echo htmlspecialchars($student['chmsu_course']); ?> <?php echo $student['chmsu_year']; ?><?php echo htmlspecialchars($student['chmsu_section']); ?></p>
        </div>
        
        <ul class="sidebar-menu">
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=home" class="active">
                    <i class="fas fa-home"></i> Home
                </a>
            </li>
            <!-- FEED LINK -->
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=feed">
                    <i class="fas fa-rss"></i> Updates Feed
                    <?php if ($unreadFeed > 0): ?>
                        <span class="sidebar-badge"><?php echo $unreadFeed; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <!-- ===== CHAT LINK - UNIFORM WITH OTHERS ===== -->
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=chat">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php if ($chat_unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $chat_unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <!-- ===== END CHAT LINK ===== -->
            <li style="border-bottom: none; padding: 8px 12px; color: #dddddd; font-size: 10px;">OFFICES</li>
            <?php foreach ($offices as $office): 
                $statusClass = '';
                $nameClass = '';
                $isApproved = false;
                $isDeclined = false;
                
                if (isset($officeStatuses[$office])) {
                    if ($officeStatuses[$office] == 'Approved') {
                        $statusClass = 'status-dot-approved';
                        $isApproved = true;
                    } elseif ($officeStatuses[$office] == 'Declined') {
                        $statusClass = 'status-dot-declined';
                        $nameClass = 'rejected-name';
                        $isDeclined = true;
                    } else {
                        $statusClass = 'status-dot-pending';
                    }
                }
                $hasUnread = isset($officeUnreadComments[$office]) && $officeUnreadComments[$office] > 0;
            ?>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=office&office=<?php echo urlencode($office); ?>" 
                   class="<?php echo $nameClass; ?>" style="position: relative;">
                    <span class="office-status-dot <?php echo $statusClass; ?>"></span>
                    <span><?php echo htmlspecialchars($office); ?></span>
                    <span class="office-count"><?php echo $officeCounts[$office]; ?></span>
                    <?php if ($isApproved): ?>
                        <i class="fas fa-check-circle" style="color: #27ae60; margin-left: 5px; font-size: 10px;"></i>
                    <?php elseif ($isDeclined): ?>
                        <i class="fas fa-times-circle" style="color: #e74c3c; margin-left: 5px; font-size: 10px;"></i>
                    <?php endif; ?>
                    <?php if ($hasUnread): ?>
                        <span class="notification-dot" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%);"></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <li style="margin-top: 15px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout(); return false;">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
        
        <div class="storage-info">
            <i class="fas fa-database"></i> Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width: 100%; height: 3px; background: #2d6a4f; border-radius: 2px; margin-top: 4px;">
                <div style="width: <?php echo $storage_percent; ?>%; height: 100%; background: #f1c40f; border-radius: 2px;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="info-bar">
            <div class="info-bar-item"><strong>ID:</strong> <?php echo htmlspecialchars($student['chmsu_student_id']); ?></div>
            <div class="info-bar-item"><strong>Name:</strong> <?php echo htmlspecialchars($master['chmsu_full_name']); ?></div>
            <div class="info-bar-item"><strong>Course/Year/Section:</strong> <?php echo htmlspecialchars($student['chmsu_course']); ?> <?php echo $student['chmsu_year']; ?><?php echo htmlspecialchars($student['chmsu_section']); ?></div>
            <div class="info-bar-item"><strong>Storage:</strong> <?php echo formatFileSize($storage_used); ?> / 100MB</div>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- ============================================ -->
        <!-- EMAIL CONNECTION UI -->
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
                <?php if ($has_email && $student_email): ?>
                    <div class="email-connected-info">
                        <div class="email-avatar">
                            <?php
                            $initial = strtoupper(substr($student_email, 0, 1));
                            $bg_color = ['#1b4d3e', '#2d6a4f', '#f39c12', '#e74c3c', '#3498db', '#9b59b6', '#1abc9c'][rand(0, 6)];
                            ?>
                            <div class="avatar-circle" style="background: <?php echo $bg_color; ?>;">
                                <?php echo $initial; ?>
                            </div>
                        </div>
                        <div class="email-details">
                            <div class="email-address"><?php echo htmlspecialchars($student_email); ?></div>
                            <div class="email-status">
                                <span class="dot-connected"></span>
                                <span>Connected • Notifications Enabled</span>
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
                            <p>Connect your Gmail to receive notifications about your clearance status.</p>
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
        
        <!-- Clearance Progress Bar -->
        <div class="content-card" style="margin-bottom: 20px;">
            <div class="content-card-header">
                <span><i class="fas fa-chart-line"></i> Clearance Progress</span>
                <span><?php echo $approvedCount; ?> / <?php echo $totalOffices; ?> Offices Approved</span>
            </div>
            <div class="content-card-body">
                <div style="margin-bottom: 10px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span>Overall Progress</span>
                        <span><?php echo $progressPercent; ?>%</span>
                    </div>
                    <div style="width: 100%; height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden;">
                        <div style="width: <?php echo $progressPercent; ?>%; height: 100%; background: #27ae60; border-radius: 5px;"></div>
                    </div>
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                    <?php foreach ($offices as $office): 
                        $status = isset($officeStatuses[$office]) ? $officeStatuses[$office] : 'Pending';
                        $statusColor = '';
                        if ($status == 'Approved') $statusColor = '#27ae60';
                        elseif ($status == 'Declined') $statusColor = '#e74c3c';
                        else $statusColor = '#f39c12';
                    ?>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 10px; height: 10px; background: <?php echo $statusColor; ?>; border-radius: 50%;"></div>
                        <span style="font-size: 10px;"><?php echo htmlspecialchars($office); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <h2 style="margin-bottom: 15px; font-size: 16px;">My Clearance</h2>
        <div class="office-grid">
            <?php foreach ($offices as $office): 
                $statusClass = '';
                $statusTextClass = 'status-text-pending';
                $nameClass = '';
                $isClickable = true;
                
                if (isset($officeStatuses[$office])) {
                    if ($officeStatuses[$office] == 'Approved') {
                        $statusClass = 'status-approved';
                        $statusTextClass = 'status-text-approved';
                    } elseif ($officeStatuses[$office] == 'Declined') {
                        $statusClass = 'status-declined';
                        $statusTextClass = 'status-text-declined';
                        $nameClass = 'rejected-name';
                    }
                }
                $hasUnread = isset($officeUnreadComments[$office]) && $officeUnreadComments[$office] > 0;
            ?>
            <div class="office-card <?php echo $statusClass; ?> <?php echo $nameClass; ?>" 
                 onclick="window.location.href='?view=office&office=<?php echo urlencode($office); ?>'" 
                 style="position: relative; cursor: pointer;">
                <h4><?php echo htmlspecialchars($office); ?></h4>
                <span class="count"><?php echo $officeCounts[$office]; ?> Requirements</span>
                <span class="status <?php echo $statusTextClass; ?>"><?php echo $officeStatuses[$office]; ?></span>
                <?php if ($hasUnread): ?>
                    <span class="notification-dot" style="position: absolute; top: 10px; right: 10px;"></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- CERTIFICATE SECTION - Only shows when ALL offices are approved -->
        <?php if ($allApproved): ?>
        <div class="content-card" style="margin-top: 20px; border: 2px solid #27ae60;">
            <div class="content-card-header" style="background: #27ae60; color: white;">
                <span><i class="fas fa-certificate"></i> Clearance Certificate</span>
                <span><i class="fas fa-check-circle"></i> COMPLETED</span>
            </div>
            <div class="content-card-body" style="text-align: center; padding: 20px;">
                <i class="fas fa-trophy" style="font-size: 48px; color: #f1c40f; margin-bottom: 10px;"></i>
                <h3 style="color: #27ae60; margin-bottom: 10px;">Congratulations!</h3>
                <p>You have successfully completed all clearance requirements for all offices.</p>
                <p style="margin-bottom: 15px;">You are now eligible to receive your Certificate of Completion.</p>
                <a href="?print_certificate=<?php echo $_SESSION['student']; ?>" target="_blank" class="btn btn-success" style="padding: 10px 20px; font-size: 14px;">
                    <i class="fas fa-print"></i> Print Certificate
                </a>
            </div>
        </div>
        <?php elseif ($approvedCount > 0): ?>
        <div class="content-card" style="margin-top: 20px; background: #f8f9fa;">
            <div class="content-card-header">
                <span><i class="fas fa-info-circle"></i> Certificate Status</span>
            </div>
            <div class="content-card-body" style="text-align: center; padding: 15px;">
                <i class="fas fa-lock" style="font-size: 36px; color: #f39c12; margin-bottom: 10px;"></i>
                <p>Certificate will be available when <strong>ALL offices</strong> have approved your requirements.</p>
                <p>Currently approved: <strong><?php echo $approvedCount; ?> / <?php echo $totalOffices; ?></strong> offices</p>
                <div style="width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; margin-top: 10px;">
                    <div style="width: <?php echo ($approvedCount / $totalOffices) * 100; ?>%; height: 100%; background: #f39c12; border-radius: 4px;"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- ============================================ -->
        <!-- LATEST UPDATES FEED -->
        <!-- ============================================ -->
        <div class="content-card" style="margin-top: 20px;">
            <div class="content-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <span><i class="fas fa-rss"></i> <strong>Latest Updates</strong></span>
                <a href="?view=feed" style="font-size: 12px; color: #1b4d3e;">View All →</a>
            </div>
            <div class="content-card-body">
                <?php if ($feed_posts && $feed_posts->num_rows > 0): ?>
                    <?php while ($post = $feed_posts->fetch_assoc()): 
                        $priorityColor = $post['priority'] == 'urgent' ? '#8e44ad' : ($post['priority'] == 'high' ? '#e74c3c' : ($post['priority'] == 'medium' ? '#f39c12' : '#3498db'));
                    ?>
                    <div style="padding: 12px; border-bottom: 1px solid #eee; border-left: 3px solid <?php echo $priorityColor; ?>; margin-bottom: 5px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <div style="font-size: 12px; color: #666;">
                                    <strong style="color: #1b4d3e;">
                                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($post['office_name']); ?>
                                    </strong>
                                    <?php if ($post['priority'] == 'urgent'): ?>
                                        <span style="background: #8e44ad; color: white; padding: 1px 8px; border-radius: 10px; font-size: 8px; margin-left: 5px;">URGENT</span>
                                    <?php elseif ($post['priority'] == 'high'): ?>
                                        <span style="background: #e74c3c; color: white; padding: 1px 8px; border-radius: 10px; font-size: 8px; margin-left: 5px;">HIGH</span>
                                    <?php endif; ?>
                                    <span style="font-size: 10px; color: #999; margin-left: 8px;"><?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                                </div>
                                <div style="font-size: 14px; font-weight: bold; color: #1b4d3e; margin-top: 2px;">
                                    <?php echo htmlspecialchars($post['title']); ?>
                                </div>
                                <div style="font-size: 12px; color: #555; margin-top: 3px; line-height: 1.4;">
                                    <?php echo substr(htmlspecialchars($post['content']), 0, 100); ?>...
                                </div>
                            </div>
                            <div style="font-size: 10px; color: #999; text-align: right; min-width: 60px;">
                                <span><i class="fas fa-eye"></i> <?php echo $post['view_count']; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 20px; color: #999;">
                        <i class="fas fa-rss" style="font-size: 32px; display: block; margin-bottom: 5px; color: #ddd;"></i>
                        No updates from offices yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- EMAIL MODAL - GOOGLE SIGN IN -->
<!-- ============================================ -->
<div class="email-modal-overlay" id="emailModal">
    <div class="email-modal">
        <div class="email-modal-header">
            <h3>Connect Your Email</h3>
            <button class="email-modal-close" onclick="closeEmailModal()">&times;</button>
        </div>
        <div class="email-modal-body">
            <p class="email-modal-subtitle">
                Sign in with your Google account to connect your Gmail and receive notifications about your clearance status.
            </p>

            <button onclick="window.location.href='?google_login=1'" class="btn-google-signin">
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
<!-- OTP VERIFICATION MODAL -->
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

// Close modal when clicking outside
window.onclick = function(e) {
    const modal = document.getElementById('emailModal');
    if (e.target === modal) {
        closeEmailModal();
    }
};

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEmailModal();
    }
});

// ============================================
// NOTIFICATION FUNCTIONS
// ============================================
function toggleNotifications() {
    var dropdown = document.getElementById('notificationDropdown');
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        dropdown.style.display = 'block';
    } else {
        dropdown.style.display = 'none';
    }
}

function markAllRead() {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_notifications_read'
    }).then(() => {
        location.reload();
    });
}

// Close notification dropdown when clicking outside
document.addEventListener('click', function(e) {
    var dropdown = document.getElementById('notificationDropdown');
    var bell = document.querySelector('[onclick="toggleNotifications()"]');
    if (dropdown && bell) {
        if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    }
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
</script>
</body>
</html>