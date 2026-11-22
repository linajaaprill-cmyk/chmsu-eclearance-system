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
$currentSection = isset($_GET['section']) ? $_GET['section'] : 'dashboard';
$error = '';
$success = '';
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

// ============================================
// REGISTRAR STATISTICS & ANALYTICS
// ============================================

$totalStudents = $conn->query("SELECT COUNT(*) as c FROM chmsu_user_accounts")->fetch_assoc()['c'];
$totalStudentsMaster = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0")->fetch_assoc()['c'];
$totalArchived = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 1")->fetch_assoc()['c'];
$totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];
$totalRequirements = $conn->query("SELECT COUNT(*) as c FROM chmsu_requirements WHERE chmsu_office='Registrar'")->fetch_assoc()['c'];
$totalSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='Registrar'")->fetch_assoc()['c'];
$pendingSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='Registrar' AND s.chmsu_status='Submitted'")->fetch_assoc()['c'];
$approvedSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='Registrar' AND s.chmsu_status='Approved'")->fetch_assoc()['c'];
$declinedSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE r.chmsu_office='Registrar' AND s.chmsu_status='Declined'")->fetch_assoc()['c'];

$regularStudents = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0 AND is_irregular = 0")->fetch_assoc()['c'];
$irregularStudents = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0 AND is_irregular = 1")->fetch_assoc()['c'];

$totalOfficesCount = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];

// ---- Clearance status (connected to the real Clearance / Clearance Status pages, current semester) ----
$dashSemId = 0;
$current_sy_label = 'N/A';
$current_sem_name = 'N/A';
$dashSemRow = $conn->query("SELECT s.*, sy.school_year FROM chmsu_semesters s LEFT JOIN chmsu_school_years sy ON s.school_year_id = sy.id WHERE s.is_current = 1 LIMIT 1");
if ($dashSemRow && $dashSemRow->num_rows > 0) { 
    $semData = $dashSemRow->fetch_assoc();
    $dashSemId = intval($semData['id']); 
    $current_sy_label = $semData['school_year'] ?? 'N/A';
    $current_sem_name = $semData['semester_name'] ?? 'N/A';
    // Ensure school year is_current is synced
    if (!empty($semData['school_year_id'])) {
        $syId = intval($semData['school_year_id']);
        $conn->query("UPDATE chmsu_school_years SET is_current = (id = $syId)");
    }
}
$dashHasSemCol = false;
$dashSemChk = $conn->query("SHOW COLUMNS FROM chmsu_clearance_status LIKE 'semester_id'");
if ($dashSemChk && $dashSemChk->num_rows > 0) { $dashHasSemCol = true; }
$dashSemClause = ($dashHasSemCol && $dashSemId > 0) ? " AND cs.semester_id = $dashSemId " : "";
$dashOtherOffices = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_offices WHERE office_name != 'Registrar'")->fetch_assoc()['c']);

// Registrar decisions (active students only)
$registrarApproved = intval($conn->query("SELECT COUNT(DISTINCT cs.student_id) as c FROM chmsu_clearance_status cs JOIN chmsu_students_master m ON m.chmsu_student_id = cs.student_id WHERE m.is_archived = 0 AND cs.office_name = 'Registrar' AND cs.status = 'clear' $dashSemClause")->fetch_assoc()['c']);
$registrarDeclined = intval($conn->query("SELECT COUNT(DISTINCT cs.student_id) as c FROM chmsu_clearance_status cs JOIN chmsu_students_master m ON m.chmsu_student_id = cs.student_id WHERE m.is_archived = 0 AND cs.office_name = 'Registrar' AND cs.status = 'unclear' $dashSemClause")->fetch_assoc()['c']);
$registrarNotProcessed = max(0, $totalStudentsMaster - $registrarApproved - $registrarDeclined);

// Ready for Registrar = cleared by ALL other offices
$readyForRegistrar = 0;
$studentsWithCompleteClearance = 0;
if ($dashOtherOffices > 0) {
    $readyRes = $conn->query("SELECT cs.student_id, SUM(cs.office_name != 'Registrar' AND cs.status = 'clear') AS others_clear
                              FROM chmsu_clearance_status cs
                              JOIN chmsu_students_master m ON m.chmsu_student_id = cs.student_id
                              WHERE m.is_archived = 0 $dashSemClause
                              GROUP BY cs.student_id");
    $readyIds = [];
    if ($readyRes) {
        while ($rr = $readyRes->fetch_assoc()) {
            if (intval($rr['others_clear']) >= $dashOtherOffices) { $readyIds[$rr['student_id']] = true; }
        }
    }
    $readyForRegistrar = count($readyIds);
    $fullRes = $conn->query("SELECT DISTINCT cs.student_id FROM chmsu_clearance_status cs JOIN chmsu_students_master m ON m.chmsu_student_id = cs.student_id WHERE m.is_archived = 0 AND cs.office_name = 'Registrar' AND cs.status = 'clear' $dashSemClause");
    if ($fullRes) {
        while ($fr = $fullRes->fetch_assoc()) {
            if (isset($readyIds[$fr['student_id']])) { $studentsWithCompleteClearance++; }
        }
    }
}
$studentsWithIncompleteClearance = max(0, $totalStudentsMaster - $studentsWithCompleteClearance);

$year1 = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0 AND chmsu_year = '1'")->fetch_assoc()['c'];
$year2 = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0 AND chmsu_year = '2'")->fetch_assoc()['c'];
$year3 = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0 AND chmsu_year = '3'")->fetch_assoc()['c'];
$year4 = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0 AND chmsu_year = '4'")->fetch_assoc()['c'];

$courses = $conn->query("SELECT course_code, (SELECT COUNT(*) FROM chmsu_students_master WHERE chmsu_course = course_code AND is_archived = 0) as student_count FROM chmsu_courses ORDER BY course_code");

$recentActivities = $conn->query("SELECT * FROM chmsu_activity_log WHERE user_type='office' AND user_id='Registrar' ORDER BY created_at DESC LIMIT 8");

// ============================================
// GET REGISTRAR EMAIL INFO
// ============================================
$registrar_info = null;
$has_email = false;

$check_column = $conn->query("SHOW COLUMNS FROM chmsu_auth_roles LIKE 'email'");
if ($check_column && $check_column->num_rows > 0) {
    $registrar_result = $conn->query("SELECT chmsu_role as office_name, email, app_password FROM chmsu_auth_roles WHERE chmsu_role='Registrar'");
    if ($registrar_result && $registrar_result->num_rows > 0) {
        $registrar_info = $registrar_result->fetch_assoc();
        $has_email = !empty($registrar_info['email']);
    }
}

if (isset($_GET['gmail_connected']) && $_GET['gmail_connected'] == 1 && $has_email && $registrar_info) {
    $to = $registrar_info['email'];
    $subject = "Gmail Connected to CHMSU E Clearance System";
    $message = "Your Gmail account has been successfully connected to the CHMSU E Clearance System.\n\n";
    $message .= "You will now receive OTP codes and notifications for secure registrar actions.\n\n";
    $message .= "Thank you,\nCHMSU E Clearance System";
    $headers = "From: CHMSU E Clearance <noreply@chmsu.edu.ph>\r\n";
    $headers .= "Reply-To: noreply@chmsu.edu.ph\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    @mail($to, $subject, $message, $headers);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Registrar Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

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
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Times New Roman', Times, serif;
        }
        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 15px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Times New Roman', Times, serif;
        }

        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }

        /* ============================================ */
        /* REGISTRAR SIDEBAR - NOW IDENTICAL TO CLEARANCE SIDEBAR */
        /* ============================================ */
        .registrar-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .registrar-sidebar::-webkit-scrollbar { width: 5px; }
        .registrar-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .registrar-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .registrar-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; font-family: 'Times New Roman', Times, serif; }
        .registrar-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        .registrar-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; padding-bottom: 20px; }
        .registrar-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
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
        .dashboard-header-bar h2 {
            font-size: 18px;
            font-weight: normal;
            color: #1b4d3e;
            font-family: 'Times New Roman', Times, serif;
        }

        .section-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #888;
            margin: 20px 0 8px 0;
            font-family: 'Times New Roman', Times, serif;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 10px;
        }
        .stat-card {
            background: white;
            border: 1px solid #e0e0e0;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: box-shadow 0.2s;
        }
        .stat-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .stat-card .icon-wrap {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8f5e9;
            color: #1b4d3e;
            flex-shrink: 0;
        }
        .stat-card .icon-wrap i { width: 18px; height: 18px; }
        .stat-card .number { font-size: 20px; font-weight: 600; color: #1b4d3e; line-height: 1.1; }
        .stat-card .label {
            font-size: 10px;
            color: #777;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .charts-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 15px;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        .chart-card {
            background: white;
            border: 1px solid #e0e0e0;
            padding: 16px;
        }
        .chart-card-header {
            font-size: 13px;
            font-weight: 600;
            color: #1b4d3e;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        canvas { max-height: 220px; width: 100%; }

        .content-card {
            background: white;
            border: 1px solid #e0e0e0;
            margin-bottom: 20px;
        }
        .content-card-header {
            background: #f8f9fa;
            padding: 12px 16px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
            font-weight: 600;
            color: #1b4d3e;
        }
        .content-card-body { padding: 8px; }
        .activity-log { max-height: 320px; overflow-y: auto; }
        .activity-item { padding: 10px 14px; border-bottom: 1px solid #f0f0f0; font-size: 12px; }
        .activity-item .user { font-weight: 600; color: #1b4d3e; }
        .activity-item .time { color: #888; font-size: 10px; margin-top: 3px; }
        .activity-item:last-child { border-bottom: none; }

        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }

        /* EMAIL CONNECTION UI (UNCHANGED LOGIC) */
        .email-connection-card {
            background: white;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            margin-bottom: 15px;
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
        .email-connection-title { font-size: 15px; font-weight: 600; color: #1b4d3e; }
        .status-badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-connected { background: #e8f5e9; color: #2e7d32; }
        .status-disconnected { background: #fce4ec; color: #c62828; }
        .email-connection-body { padding: 16px 20px; }
        .email-connected-info { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
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
        .email-address { font-size: 15px; font-weight: 500; color: #333; }
        .email-status { font-size: 12px; color: #666; display: flex; align-items: center; gap: 6px; margin-top: 2px; }
        .dot-connected {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4caf50;
            display: inline-block;
        }
        .email-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; }
        .btn-email-change {
            padding: 6px 14px;
            background: #1b4d3e;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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
            color: #1b4d3e;
        }
        .email-disconnected-text h4 { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 4px; }
        .email-disconnected-text p { font-size: 13px; color: #666; margin: 0; }
        .btn-connect-gmail {
            padding: 10px 28px;
            background: #4285F4;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-family: 'Times New Roman', Times, serif;
        }
        .btn-connect-gmail:hover { background: #3367D6; }

        /* EMAIL MODAL (UNCHANGED) */
        .email-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
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
        }
        .email-modal-header {
            padding: 16px 22px 10px 22px;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }
        .email-modal-header h3 { font-size: 17px; font-weight: 500; color: #202124; }
        .email-modal-close {
            position: absolute;
            right: 16px; top: 12px;
            background: none;
            border: none;
            color: #5f6368;
            font-size: 24px;
            cursor: pointer;
            opacity: 0.6;
        }
        .email-modal-close:hover { opacity: 1; }
        .email-modal-body { padding: 4px 24px 22px 24px; text-align: center; }
        .email-modal-subtitle { font-size: 13px; color: #5f6368; margin-bottom: 18px; line-height: 1.5; }
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
            font-family: 'Times New Roman', Times, serif;
        }
        .btn-google-signin:hover { background: #f8f9fa; border-color: #c6c8ca; }
        .email-modal-footer {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #f1f3f4;
            display: flex;
            justify-content: center;
        }
        .btn-cancel-modal {
            padding: 8px 24px;
            background: #f8f9fa;
            color: #3c4043;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            font-family: 'Times New Roman', Times, serif;
        }
        .btn-cancel-modal:hover { background: #f1f3f4; }

        /* DARK MODE */
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .stat-card,
        body.dark-mode .chart-card,
        body.dark-mode .content-card,
        body.dark-mode .email-connection-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .stat-card .number { color: #f1c40f; }
        body.dark-mode .stat-card .label { color: #aaa; }
        body.dark-mode .stat-card .icon-wrap { background: #1a3a2a; color: #f1c40f; }
        body.dark-mode .chart-card-header { color: #f1c40f; border-bottom-color: #333; }
        body.dark-mode .content-card-header { background: #2c2c2c; color: #f1c40f; border-color: #333; }
        body.dark-mode .activity-item { border-bottom-color: #333; }
        body.dark-mode .activity-item .user { color: #f1c40f; }
        body.dark-mode .activity-item .time { color: #aaa; }
        body.dark-mode .email-connection-header { background: #2c2c2c; border-color: #333; }
        body.dark-mode .email-connection-title { color: #f1c40f; }
        body.dark-mode .email-address { color: #fff; }
        body.dark-mode .email-status { color: #ccc; }
        body.dark-mode .email-disconnected-icon { background: #2c2c2c; color: #f1c40f; }
        body.dark-mode .email-disconnected-text h4 { color: #fff; }
        body.dark-mode .email-disconnected-text p { color: #ccc; }
        body.dark-mode .email-modal { background: #202124; }
        body.dark-mode .email-modal-header h3 { color: #e8eaed; }
        body.dark-mode .email-modal-subtitle { color: #9aa0a6; }
        body.dark-mode .btn-google-signin { background: #2d2e30; border-color: #3c4043; color: #e8eaed; }
        body.dark-mode .btn-cancel-modal { background: #2d2e30; color: #e8eaed; }
        body.dark-mode .section-label { color: #888; }

        @media (max-width: 900px) {
            .charts-row { grid-template-columns: 1fr; }
        }
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
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i data-lucide="moon" style="width:14px;height:14px;"></i> Dark Mode
    </button>
    <button onclick="confirmLogout()" class="logout-btn">
        <i data-lucide="log-out" style="width:14px;height:14px;"></i> Logout
    </button>
</div>

<div class="dashboard-wrapper">
    <!-- SIDEBAR - NOW IDENTICAL TO CLEARANCE SIDEBAR -->
    <div class="registrar-sidebar">
        <div class="sidebar-header">
            <h3>Registrar Portal</h3>
            <p>Student Records Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=dashboard" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?section=masterlist"><i class="fas fa-users"></i> Master List</a></li>
            <li><a href="?section=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?section=clearance_status"><i class="fas fa-user-check"></i> Clearance Status</a></li>
            <li><a href="?section=history"><i class="fas fa-history"></i> Clearance History</a></li>
            <li><a href="?section=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?section=archived"><i class="fas fa-archive"></i> Archive</a></li>
            <li><a href="?section=promotion"><i class="fas fa-arrow-up"></i> Student Promotion</a></li>
            <li><a href="?section=note"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li><a href="?section=chat"><i class="fas fa-comment-dots"></i> Messages</a></li>
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

    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2><i data-lucide="layout-dashboard" style="width:18px;height:18px;"></i> Registrar Dashboard</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>

        <!-- Active semester banner -->
        <div style="background: linear-gradient(135deg, #1b4d3e, #2d6a4f); color: white; padding: 14px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <i data-lucide="calendar-check" style="width:24px;height:24px;opacity:0.9;"></i>
                <div>
                    <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.75; font-weight: bold;">Currently Active</div>
                    <div style="font-size: 16px; font-weight: bold; margin-top: 2px;">
                        <?php echo htmlspecialchars($current_sy_label); ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($current_sem_name); ?>
                    </div>
                </div>
            </div>
            <div style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: bold; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="info" style="width:14px;height:14px;opacity:0.9;"></i>
                <span>All statistics and student clearances reflect this active semester.</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- EMAIL CONNECTION CARD -->
        <div class="email-connection-card">
            <div class="email-connection-header">
                <div class="email-connection-title">
                    <i data-lucide="mail" style="width:16px;height:16px;"></i>
                    <span>Email Connection</span>
                </div>
                <div class="email-connection-status">
                    <?php if ($has_email): ?>
                        <span class="status-badge status-connected">
                            <i data-lucide="check-circle" style="width:12px;height:12px;"></i> Connected
                        </span>
                    <?php else: ?>
                        <span class="status-badge status-disconnected">
                            <i data-lucide="alert-circle" style="width:12px;height:12px;"></i> Not Connected
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="email-connection-body">
                <?php if ($has_email && $registrar_info): ?>
                    <div class="email-connected-info">
                        <div class="email-avatar">
                            <?php $initial = strtoupper(substr($registrar_info['email'], 0, 1)); ?>
                            <div class="avatar-circle" style="background: #1b4d3e;"><?php echo $initial; ?></div>
                        </div>
                        <div class="email-details">
                            <div class="email-address"><?php echo htmlspecialchars($registrar_info['email']); ?></div>
                            <div class="email-status">
                                <span class="dot-connected"></span>
                                <span>Connected • OTP Enabled</span>
                            </div>
                        </div>
                        <div class="email-actions">
                            <button onclick="openEmailModal()" class="btn-email-change">
                                <i data-lucide="edit-3" style="width:12px;height:12px;"></i> Change
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="email-disconnected">
                        <div class="email-disconnected-icon">
                            <i data-lucide="mail-open" style="width:28px;height:28px;"></i>
                        </div>
                        <div class="email-disconnected-text">
                            <h4>Connect Your Gmail Account</h4>
                            <p>Connect your Gmail to receive OTP codes and notifications for secure registrar actions.</p>
                        </div>
                        <div>
                            <button onclick="openEmailModal()" class="btn-connect-gmail">
                                <i data-lucide="log-in" style="width:16px;height:16px;"></i> Connect Gmail
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon-wrap" style="background:#fff8e1; color:#f39c12;"><i data-lucide="clock"></i></div>
                <div>
                    <div class="number" style="color:#f39c12;"><?php echo $pendingSubmissions; ?></div>
                    <div class="label">Pending Submissions</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon-wrap" style="background:#e8f5e9; color:#27ae60;"><i data-lucide="check-circle"></i></div>
                <div>
                    <div class="number" style="color:#27ae60;"><?php echo $approvedSubmissions; ?></div>
                    <div class="label">Approved Submissions</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon-wrap" style="background:#fdecea; color:#e74c3c;"><i data-lucide="x-circle"></i></div>
                <div>
                    <div class="number" style="color:#e74c3c;"><?php echo $declinedSubmissions; ?></div>
                    <div class="label">Declined Submissions</div>
                </div>
            </div>
        </div>

        <div class="section-label">Clearance Status</div>

        <div class="stats-grid">
            <a href="?section=clearance_status&view_mode=processed&filter_status=clear" class="stat-card" style="text-decoration:none; color:inherit; cursor:pointer;" title="View Approved in Clearance Status">
                <div class="icon-wrap" style="background:#e8f5e9; color:#27ae60;"><i data-lucide="user-check"></i></div>
                <div>
                    <div class="number" style="color:#27ae60;"><?php echo $registrarApproved; ?></div>
                    <div class="label">Registrar Approved</div>
                </div>
            </a>
            <a href="?section=clearance_status&view_mode=processed&filter_status=unclear" class="stat-card" style="text-decoration:none; color:inherit; cursor:pointer;" title="View Declined in Clearance Status">
                <div class="icon-wrap" style="background:#fdecea; color:#e74c3c;"><i data-lucide="user-x"></i></div>
                <div>
                    <div class="number" style="color:#e74c3c;"><?php echo $registrarDeclined; ?></div>
                    <div class="label">Registrar Declined</div>
                </div>
            </a>
            <a href="?section=clearance_status&view_mode=pending" class="stat-card" style="text-decoration:none; color:inherit; cursor:pointer;" title="View Pending in Clearance Status">
                <div class="icon-wrap" style="background:#fff8e1; color:#f39c12;"><i data-lucide="hourglass"></i></div>
                <div>
                    <div class="number" style="color:#f39c12;"><?php echo $registrarNotProcessed; ?></div>
                    <div class="label">Not Yet Processed</div>
                </div>
            </a>
        </div>

        <div class="stats-grid">
            <a href="?section=clearance_status" class="stat-card" style="text-decoration:none; color:inherit; cursor:pointer;" title="View Clearance Status">
                <div class="icon-wrap" style="background:#e8f5e9; color:#27ae60;"><i data-lucide="shield-check"></i></div>
                <div>
                    <div class="number" style="color:#27ae60;"><?php echo $studentsWithCompleteClearance; ?></div>
                    <div class="label">Students Fully Cleared</div>
                </div>
            </a>
            <a href="?section=clearance_status" class="stat-card" style="text-decoration:none; color:inherit; cursor:pointer;" title="View Clearance Status">
                <div class="icon-wrap" style="background:#fff8e1; color:#f39c12;"><i data-lucide="shield-alert"></i></div>
                <div>
                    <div class="number" style="color:#f39c12;"><?php echo $studentsWithIncompleteClearance; ?></div>
                    <div class="label">Students Pending Clearance</div>
                </div>
            </a>
            <a href="?section=clearance_status&view_mode=pending" class="stat-card" style="text-decoration:none; color:inherit; cursor:pointer;" title="View Students Ready in Clearance Status">
                <div class="icon-wrap" style="background:#e0f2fe; color:#0284c7;"><i data-lucide="clipboard-check"></i></div>
                <div>
                    <div class="number" style="color:#0284c7;"><?php echo $readyForRegistrar; ?></div>
                    <div class="label">Ready for Registrar</div>
                </div>
            </a>
        </div>

        <!-- ANALYTICS - 2 CHARTS -->
        <div class="section-label">Analytics</div>

        <div class="charts-row">
            <div class="chart-card">
                <div class="chart-card-header"><i data-lucide="pie-chart" style="width:14px;height:14px;"></i> Submission Status</div>
                <canvas id="submissionStatusChart"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-card-header"><i data-lucide="bar-chart-3" style="width:14px;height:14px;"></i> Students by Year Level</div>
                <canvas id="yearLevelChart"></canvas>
            </div>
        </div>

        <!-- RECENT ACTIVITY -->
        <div class="content-card">
            <div class="content-card-header"><i data-lucide="history" style="width:14px;height:14px;"></i> Recent Activity Log</div>
            <div class="content-card-body">
                <div class="activity-log">
                    <?php if ($recentActivities && $recentActivities->num_rows > 0): ?>
                        <?php while ($log = $recentActivities->fetch_assoc()): ?>
                            <div class="activity-item">
                                <div><span class="user"><?php echo htmlspecialchars($log['user_id']); ?></span> — <?php echo htmlspecialchars($log['action']); ?></div>
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

<!-- EMAIL MODAL (UNCHANGED) -->
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

            <button onclick="window.location.href='?google_login=1'" class="btn-google-signin">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="20" height="20">
                    <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/>
                    <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/>
                    <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/>
                    <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z"/>
                </svg>
                <span>Sign in with Google</span>
            </button>

            <div class="email-modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeEmailModal()">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- OTP MODAL (UNCHANGED) -->
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
    lucide.createIcons();

    flatpickr.localize(flatpickr.l10ns.en);
    document.querySelectorAll('input[type="date"], .flatpickr-input').forEach(el => flatpickr(el));

    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        const isDarkMode = document.body.classList.contains('dark-mode');
        localStorage.setItem('darkMode', isDarkMode);
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) {
            btn.innerHTML = isDarkMode
                ? '<i data-lucide="sun" style="width:14px;height:14px;"></i> Light Mode'
                : '<i data-lucide="moon" style="width:14px;height:14px;"></i> Dark Mode';
            lucide.createIcons();
        }
    }
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) {
            btn.innerHTML = '<i data-lucide="sun" style="width:14px;height:14px;"></i> Light Mode';
            lucide.createIcons();
        }
    }

    function confirmLogout() {
        if(confirm('Are you sure you want to logout?')) {
            window.location.href = '?logout=1';
        }
    }

    function openEmailModal() { document.getElementById('emailModal').classList.add('active'); }
    function closeEmailModal() { document.getElementById('emailModal').classList.remove('active'); }

    // CHART 1: Submission Status
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
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12, padding: 12 } } }
        }
    });

    // CHART 2: Students by Year
    new Chart(document.getElementById('yearLevelChart'), {
        type: 'bar',
        data: {
            labels: ['1st Year', '2nd Year', '3rd Year', '4th Year'],
            datasets: [{
                label: 'Students',
                data: [<?php echo $year1; ?>, <?php echo $year2; ?>, <?php echo $year3; ?>, <?php echo $year4; ?>],
                backgroundColor: '#1b4d3e',
                borderRadius: 4,
                barThickness: 40
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { font: { size: 11 } }, grid: { color: '#f0f0f0' } },
                x: { ticks: { font: { size: 11 } }, grid: { display: false } }
            }
        }
    });

    // OTP FUNCTIONS (UNCHANGED - 100% SAME)
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
        if (otpTimerInterval) { clearInterval(otpTimerInterval); otpTimerInterval = null; }
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
        if (otpTimerInterval) clearInterval(otpTimerInterval);
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
        document.getElementById('otpCountdown').style.color = otpCountdownSeconds < 60 ? '#e74c3c' : '#27ae60';
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

        fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                statusDiv.style.background = '#d4edda';
                statusDiv.style.color = '#155724';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message + ' Refreshing page...';
                if (otpTimerInterval) { clearInterval(otpTimerInterval); otpTimerInterval = null; }
                setTimeout(function() { window.location.reload(); }, 1500);
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
                if (e.key === 'Enter') verifyOTP();
            });
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeEmailModal(); closeOTPModal(); }
    });

    window.onclick = function(e) {
        const emailModal = document.getElementById('emailModal');
        const otpModal = document.getElementById('otpModal');
        if (e.target === emailModal) closeEmailModal();
        if (e.target === otpModal) closeOTPModal();
    };
</script>

</body>
</html>