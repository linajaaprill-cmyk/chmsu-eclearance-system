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
$adminSection = 'dashboard';
$error = '';
$success = '';

// Safe count helper
function safeCount($conn, $sql) {
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

$totalStudents      = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_user_accounts");
$totalOffices       = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_offices");
$totalCourses       = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_courses");
$totalSections      = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_sections");
$totalRequirements  = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_requirements");
$totalSubmissions   = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_submissions");
$totalAdminAccounts  = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_admin_users");
$totalOfficeAccounts = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_auth_roles");
$totalSchoolYears    = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_school_years");
$totalSemesters      = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_semesters");
$activityLast7Days   = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$totalStudentsMaster = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_students_master");
$totalArchived       = safeCount($conn, "SELECT COUNT(*) AS c FROM chmsu_students_master WHERE is_archived=1");

// Current school year
$currentSchoolYear = ['school_year' => 'N/A'];
$r = $conn->query("SELECT school_year FROM chmsu_school_years WHERE is_current=1 LIMIT 1");
if ($r && $row = $r->fetch_assoc()) $currentSchoolYear = $row;

// Current semester
$currentSemester = ['semester_name' => 'N/A'];
$r = $conn->query("SELECT semester_name FROM chmsu_semesters WHERE is_current=1 LIMIT 1");
if ($r && $row = $r->fetch_assoc()) $currentSemester = $row;

// Admin info + has_email
$has_email = false;
$admin_info = ['email' => ''];
$r = $conn->query("SELECT email, app_password FROM chmsu_admin_users WHERE username='" . $conn->real_escape_string($admin) . "' LIMIT 1");
if ($r && $row = $r->fetch_assoc()) {
    $admin_info = $row;
    if (!empty($row['email']) && !empty($row['app_password'])) $has_email = true;
}

// Data for charts: clearance decisions per office (current semester)
$officeRequirements = [];
$chartSemId = 0;
$r = $conn->query("SELECT id FROM chmsu_semesters WHERE is_current = 1 LIMIT 1");
if ($r && $row = $r->fetch_assoc()) $chartSemId = (int)$row['id'];
$chartHasSem = false;
$r = $conn->query("SHOW COLUMNS FROM chmsu_clearance_status LIKE 'semester_id'");
if ($r && $r->num_rows > 0) $chartHasSem = true;
$chartSemJoin = ($chartHasSem && $chartSemId > 0) ? " AND cs.semester_id = $chartSemId" : "";
$r = $conn->query("SELECT o.office_name AS office,
                          SUM(cs.status = 'clear') AS approved,
                          SUM(cs.status = 'unclear') AS declined
                   FROM chmsu_offices o
                   LEFT JOIN chmsu_clearance_status cs ON cs.office_name = o.office_name $chartSemJoin
                   GROUP BY o.office_name
                   ORDER BY o.office_name ASC");
if ($r) while ($row = $r->fetch_assoc()) $officeRequirements[] = $row;

// Students by year level (donut) - active students from the master list
$studentsByYear = [];
$r = $conn->query("SELECT chmsu_year AS yr, COUNT(*) AS count FROM chmsu_students_master WHERE is_archived = 0 GROUP BY chmsu_year ORDER BY chmsu_year ASC");
if ($r) while ($row = $r->fetch_assoc()) $studentsByYear[] = $row;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        }
        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 15px;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }

        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }

        /* ============================================ */
        /* SIDEBAR - NOW IDENTICAL TO COURSES SIDEBAR   */
        /* ============================================ */
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

        /* STATS CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 16px 14px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            transition: transform 0.15s, box-shadow 0.15s;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .stat-card .stat-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 4px;
        }
        .stat-card .number {
            font-size: 24px;
            font-weight: 700;
            color: #1b4d3e;
            line-height: 1.1;
        }
        .stat-card .label {
            font-size: 10px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
        }

        /* CURRENT SY / SEM BAR */
        .current-setup-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        .current-setup-item {
            flex: 1;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .current-setup-item .setup-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #f0fdf4;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1b4d3e;
            flex-shrink: 0;
        }
        .current-setup-item .setup-label {
            font-size: 10px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
        }
        .current-setup-item .setup-value {
            font-size: 15px;
            font-weight: 600;
            color: #1b4d3e;
        }

        /* CHARTS - 2 charts */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .chart-card {
            background: white;
            border: 1px solid #e2e8f0;
            padding: 18px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .chart-card-header {
            font-size: 13px;
            font-weight: 600;
            color: #1b4d3e;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
            text-align: center;
            letter-spacing: 0.3px;
        }
        canvas { max-height: 200px; width: 100%; }

        .merged-legend {
            display: flex;
            justify-content: center;
            gap: 18px;
            margin-top: 12px;
            font-size: 11px;
            flex-wrap: wrap;
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #475569;
        }
        .legend-color {
            width: 10px;
            height: 10px;
            border-radius: 3px;
        }

        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }

        /* EMAIL CONNECTION CARD */
        .email-connection-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s;
        }
        .email-connection-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .email-connection-header {
            padding: 14px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
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
        .status-badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-connected { background: #dcfce7; color: #166534; }
        .status-disconnected { background: #fee2e2; color: #991b1b; }
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
        .email-address { font-size: 15px; font-weight: 500; color: #1e293b; }
        .email-status {
            font-size: 12px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
        }
        .dot-connected {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4ade80;
            display: inline-block;
            animation: pulse-green 2s infinite;
        }
        @keyframes pulse-green {
            0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; }
        }
        .email-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; }
        .btn-email-change {
            padding: 6px 16px;
            background: #1b4d3e;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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
            background: #f1f5f9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #1b4d3e;
        }
        .email-disconnected-text h4 { font-size: 16px; font-weight: 600; color: #1e293b; margin-bottom: 4px; }
        .email-disconnected-text p { font-size: 13px; color: #64748b; margin: 0; }
        .btn-connect-gmail {
            padding: 10px 28px;
            background: #4285F4;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }
        .btn-connect-gmail:hover { background: #3367D6; }

        /* EMAIL MODAL */
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
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .email-modal-header {
            padding: 16px 22px 10px 22px;
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }
        .email-modal-header h3 {
            font-size: 17px;
            font-weight: 600;
            color: #1e293b;
        }
        .email-modal-close {
            position: absolute;
            right: 16px;
            top: 12px;
            background: none;
            border: none;
            color: #64748b;
            font-size: 24px;
            cursor: pointer;
            opacity: 0.6;
        }
        .email-modal-close:hover { opacity: 1; }
        .email-modal-body {
            padding: 4px 24px 22px 24px;
            text-align: center;
            background: #ffffff;
        }
        .email-modal-subtitle {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 18px;
            line-height: 1.5;
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
        }
        .btn-google-signin:hover { background: #f8f9fa; border-color: #c6c8ca; }
        .btn-google-signin svg { width: 20px; height: 20px; }
        .email-modal-footer {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: center;
        }
        .btn-cancel-modal {
            padding: 8px 24px;
            background: #f8fafc;
            color: #3c4043;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        .btn-cancel-modal:hover { background: #f1f5f9; }

        /* DARK MODE */
        body.dark-mode { background: #0f172a; color: #e2e8f0; }
        body.dark-mode .main-content { background: #0f172a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .stat-card,
        body.dark-mode .chart-card,
        body.dark-mode .current-setup-item,
        body.dark-mode .email-connection-card { background: #1e293b; border-color: #334155; color: #e2e8f0; }
        body.dark-mode .dashboard-header-bar h2 { color: #facc15; }
        body.dark-mode .stat-card .number { color: #facc15; }
        body.dark-mode .stat-card .label { color: #94a3b8; }
        body.dark-mode .current-setup-item .setup-icon { background: #334155; color: #facc15; }
        body.dark-mode .current-setup-item .setup-value { color: #facc15; }
        body.dark-mode .current-setup-item .setup-label { color: #94a3b8; }
        body.dark-mode .chart-card-header { color: #facc15; border-bottom-color: #334155; }
        body.dark-mode .legend-item { color: #94a3b8; }
        body.dark-mode .email-connection-header { background: #1e293b; border-color: #334155; }
        body.dark-mode .email-connection-title { color: #facc15; }
        body.dark-mode .email-address { color: #e2e8f0; }
        body.dark-mode .email-status { color: #94a3b8; }
        body.dark-mode .email-disconnected-icon { background: #334155; color: #facc15; }
        body.dark-mode .email-disconnected-text h4 { color: #e2e8f0; }
        body.dark-mode .email-disconnected-text p { color: #94a3b8; }
        body.dark-mode .email-modal { background: #1e293b; }
        body.dark-mode .email-modal-header { background: #1e293b; }
        body.dark-mode .email-modal-header h3 { color: #e2e8f0; }
        body.dark-mode .email-modal-close { color: #94a3b8; }
        body.dark-mode .email-modal-body { background: #1e293b; }
        body.dark-mode .email-modal-subtitle { color: #94a3b8; }
        body.dark-mode .btn-google-signin { background: #2d2e30; border-color: #3c4043; color: #e8eaed; }
        body.dark-mode .btn-cancel-modal { background: #2d2e30; color: #e8eaed; }

        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(4, 1fr); }
        }
        @media (max-width: 768px) {
            .admin-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .current-setup-bar { flex-direction: column; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .email-connected-info { flex-direction: column; align-items: flex-start; }
            .email-actions { margin-left: 0; width: 100%; }
            .btn-connect-gmail { width: 100%; justify-content: center; }
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
    <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?');">Logout</a>
</div>

<div class="dashboard-wrapper">
    <!-- SIDEBAR - NOW IDENTICAL TO COURSES SIDEBAR -->
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
                <a href="?logout=1" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Admin Dashboard</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- EMAIL CONNECTION UI -->
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
                <?php if ($has_email): ?>
                    <div class="email-connected-info">
                        <div class="email-avatar">
                            <?php
                            $initial = strtoupper(substr($admin_info['email'], 0, 1));
                            $bg_color = ['#1b4d3e', '#2d6a4f', '#f39c12', '#e74c3c', '#3498db', '#9b59b6', '#1abc9c'][rand(0, 6)];
                            ?>
                            <div class="avatar-circle" style="background: <?php echo $bg_color; ?>;">
                                <?php echo $initial; ?>
                            </div>
                        </div>
                        <div class="email-details">
                            <div class="email-address"><?php echo htmlspecialchars($admin_info['email']); ?></div>
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
                            <p>Connect your Gmail to receive OTP codes and notifications for secure admin actions.</p>
                        </div>
                        <div>
                            <button onclick="openEmailModal()" class="btn-connect-gmail">
                                <i class="fas fa-envelope"></i> Connect Gmail
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 8 STAT CARDS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#eff6ff;color:#2563eb;">
                    <i class="fas fa-users" style="font-size:16px;"></i>
                </div>
                <div class="number"><?php echo $totalStudents; ?></div>
                <div class="label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#f0fdf4;color:#16a34a;">
                    <i class="fas fa-building" style="font-size:16px;"></i>
                </div>
                <div class="number"><?php echo $totalOffices; ?></div>
                <div class="label">Total Offices</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#e0e7ff;color:#4f46e5;">
                    <i class="fas fa-chart-line" style="font-size:16px;"></i>
                </div>
                <div class="number"><?php echo $activityLast7Days; ?></div>
                <div class="label">Activity (7d)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#f3e8ff;color:#9333ea;">
                    <i class="fas fa-shield-alt" style="font-size:16px;"></i>
                </div>
                <div class="number"><?php echo $totalAdminAccounts + $totalOfficeAccounts; ?></div>
                <div class="label">Total Accounts</div>
            </div>
        </div>

        <!-- CURRENT SY / SEM BAR -->
        <div class="current-setup-bar">
            <div class="current-setup-item">
                <div class="setup-icon">
                    <i class="fas fa-calendar" style="font-size:16px;"></i>
                </div>
                <div>
                    <div class="setup-label">Current School Year</div>
                    <div class="setup-value"><?php echo $currentSchoolYear ? $currentSchoolYear['school_year'] : 'N/A'; ?></div>
                </div>
            </div>
            <div class="current-setup-item">
                <div class="setup-icon">
                    <i class="fas fa-clock" style="font-size:16px;"></i>
                </div>
                <div>
                    <div class="setup-label">Current Semester</div>
                    <div class="setup-value"><?php echo $currentSemester ? $currentSemester['semester_name'] : 'N/A'; ?></div>
                </div>
            </div>
        </div>

        <!-- 2 CHARTS USING CHART.JS -->
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-chart-bar" style="margin-right:6px;color:#1b4d3e;"></i>
                    Clearance per Office
                </div>
                <canvas id="clearanceBarChart"></canvas>
            </div>

            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="fas fa-chart-pie" style="margin-right:6px;color:#1b4d3e;"></i>
                    Students by Year Level
                </div>
                <canvas id="studentsDonutChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- EMAIL MODAL -->
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
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
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

<!-- OTP VERIFICATION MODAL -->
<div id="otpModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:white; max-width:420px; width:90%; padding:30px; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3); position:relative;">
        <div style="position:absolute; top:10px; right:15px; cursor:pointer; font-size:20px; color:#999;" onclick="closeOTPModal()">&times;</div>
        <h3 style="color:#1b4d3e; margin-bottom:8px; font-weight:600; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-shield-alt" style="color:#f1c40f;"></i> Verify Delete Action
        </h3>
        <p style="font-size:12px; color:#64748b; margin-bottom:15px;">
            An OTP has been sent to your configured email. Enter it below to confirm deletion.
        </p>

        <div id="otpStatus" style="padding:8px; margin-bottom:12px; border-radius:8px; display:none;"></div>

        <div style="margin-bottom:15px;">
            <label style="display:block; font-size:12px; color:#1e293b; margin-bottom:4px;">Item to Delete:</label>
            <div style="background:#f1f5f9; padding:8px 12px; border-radius:8px; font-size:13px; font-weight:600; color:#1b4d3e;" id="otpItemName">-</div>
        </div>

        <div style="margin-bottom:15px;">
            <label style="display:block; font-size:12px; color:#1e293b; margin-bottom:4px;">Enter OTP Code:</label>
            <div style="display:flex; gap:8px;">
                <input type="text" id="otpCodeInput" placeholder="Enter 6-digit OTP" style="flex:1; padding:10px; border:1px solid #e2e8f0; border-radius:8px; font-size:18px; letter-spacing:3px; text-align:center; font-family:'Courier New', monospace;" maxlength="6" autocomplete="off">
                <button onclick="verifyOTP()" style="background:#1b4d3e; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-check"></i> Verify
                </button>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <span id="otpTimer" style="font-size:11px; color:#e74c3c;">
                <i class="fas fa-clock"></i> Expires in: <span id="otpCountdown">5:00</span>
            </span>
            <button onclick="resendOTP()" style="background:transparent; color:#1b4d3e; border:1px solid #1b4d3e; padding:6px 15px; border-radius:8px; cursor:pointer; font-size:11px; display:flex; align-items:center; gap:4px;">
                <i class="fas fa-redo"></i> Resend OTP
            </button>
        </div>

        <div style="margin-top:15px; padding-top:12px; border-top:1px solid #f1f5f9; display:flex; gap:8px; justify-content:flex-end;">
            <button onclick="closeOTPModal()" style="background:#94a3b8; color:white; border:none; padding:6px 15px; border-radius:8px; cursor:pointer; font-size:12px;">
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
    // ===== DARK MODE =====
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
        if (btn) btn.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
    }

    // ===== CHARTS (CHART.JS) =====
    // Clearance per Office (Bar)
    const officeLabels = <?php echo json_encode(array_column($officeRequirements, 'office')); ?>;
    const officeApproved = <?php echo json_encode(array_map('intval', array_column($officeRequirements, 'approved'))); ?>;
    const officeDeclined = <?php echo json_encode(array_map('intval', array_column($officeRequirements, 'declined'))); ?>;
    new Chart(document.getElementById('clearanceBarChart'), {
        type: 'bar',
        data: {
            labels: officeLabels,
            datasets: [
                { label: 'Approved', data: officeApproved, backgroundColor: '#27ae60', borderRadius: 4, maxBarThickness: 32, minBarLength: 3 },
                { label: 'Declined', data: officeDeclined, backgroundColor: '#e74c3c', borderRadius: 4, maxBarThickness: 32, minBarLength: 3 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12, padding: 12 } } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });

    // Students by Year Level (Donut)
    const yearLabels = <?php echo json_encode(array_map(function($y){ return 'Year ' . $y['yr']; }, $studentsByYear)); ?>;
    const yearCounts = <?php echo json_encode(array_map(function($y){ return (int)$y['count']; }, $studentsByYear)); ?>;
    new Chart(document.getElementById('studentsDonutChart'), {
        type: 'doughnut',
        data: {
            labels: yearLabels,
            datasets: [{
                data: yearCounts,
                backgroundColor: ['#1b4d3e', '#f1c40f', '#3498db', '#e67e22', '#9b59b6', '#95a5a6'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12, padding: 12 } } }
        }
    });

    // ===== EMAIL MODAL =====
    function openEmailModal() { document.getElementById('emailModal').classList.add('active'); }
    function closeEmailModal() { document.getElementById('emailModal').classList.remove('active'); }

    // ===== OTP =====
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
        statusDiv.style.background = '#eff6ff';
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
                statusDiv.style.background = '#dcfce7';
                statusDiv.style.color = '#166534';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                startOTPTimer();
            } else {
                statusDiv.style.background = '#fee2e2';
                statusDiv.style.color = '#991b1b';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
            }
        })
        .catch(() => {
            statusDiv.style.background = '#fee2e2';
            statusDiv.style.color = '#991b1b';
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
                statusDiv.style.background = '#fee2e2';
                statusDiv.style.color = '#991b1b';
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
            statusDiv.style.background = '#fee2e2';
            statusDiv.style.color = '#991b1b';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a valid 6-digit OTP code.';
            return;
        }

        const statusDiv = document.getElementById('otpStatus');
        statusDiv.style.display = 'block';
        statusDiv.style.background = '#eff6ff';
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
                statusDiv.style.background = '#dcfce7';
                statusDiv.style.color = '#166534';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message + ' Refreshing page...';
                if (otpTimerInterval) { clearInterval(otpTimerInterval); otpTimerInterval = null; }
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                statusDiv.style.background = '#fee2e2';
                statusDiv.style.color = '#991b1b';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
            }
        })
        .catch(() => {
            statusDiv.style.background = '#fee2e2';
            statusDiv.style.color = '#991b1b';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Verification failed. Please try again.';
        });
    }

    function resendOTP() {
        const statusDiv = document.getElementById('otpStatus');
        statusDiv.style.display = 'block';
        statusDiv.style.background = '#eff6ff';
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
                statusDiv.style.background = '#dcfce7';
                statusDiv.style.color = '#166534';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                startOTPTimer();
            } else {
                statusDiv.style.background = '#fee2e2';
                statusDiv.style.color = '#991b1b';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
            }
        })
        .catch(() => {
            statusDiv.style.background = '#fee2e2';
            statusDiv.style.color = '#991b1b';
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