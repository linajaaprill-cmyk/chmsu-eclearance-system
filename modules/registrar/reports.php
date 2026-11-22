<?php
/**
 * CHMSU E-Clearance System — Registrar Reports & Analytics
 */
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
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

// Active Tab
$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'analytics') ? 'analytics' : 'reports';

// Filters
$filter_course  = isset($_GET['filter_course'])  ? trim($_GET['filter_course'])  : '';
$filter_year    = isset($_GET['filter_year'])    ? trim($_GET['filter_year'])    : '';
$filter_section = isset($_GET['filter_section']) ? trim($_GET['filter_section']) : '';
$filter_type    = isset($_GET['filter_type'])    ? trim($_GET['filter_type'])    : '';
$filter_status  = isset($_GET['filter_status'])  ? trim($_GET['filter_status'])  : '';

$totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];

$where = array();
$where[] = "m.is_archived = 0";

if ($filter_course !== '')  $where[] = "m.chmsu_course = '" . $conn->real_escape_string($filter_course) . "'";
if ($filter_year !== '') {
    $yearInt = intval($filter_year);
    if ($yearInt >= 1 && $yearInt <= 4) $where[] = "m.chmsu_year = '$yearInt'";
}
if ($filter_section !== '') $where[] = "m.chmsu_section = '" . $conn->real_escape_string($filter_section) . "'";
if ($filter_type === 'regular') $where[] = "m.is_irregular = 0";
elseif ($filter_type === 'irregular') $where[] = "m.is_irregular = 1";

$where_sql = "WHERE " . implode(" AND ", $where);

// Cleanly sorted by Course, Year, Section, Last Name
$query = "SELECT 
            m.chmsu_student_id,
            CONCAT(m.chmsu_last_name, ', ', m.chmsu_first_name, ' ', m.chmsu_middle_name) as student_name,
            m.chmsu_last_name, m.chmsu_first_name, m.chmsu_year as year,
            m.chmsu_section as section, m.chmsu_course as course, m.is_irregular,
            (SELECT COUNT(DISTINCT cs.office_name) FROM chmsu_clearance_status cs WHERE cs.student_id = m.chmsu_student_id AND cs.status = 'clear') as offices_completed
          FROM chmsu_students_master m
          $where_sql
          ORDER BY m.chmsu_course ASC, CAST(m.chmsu_year AS UNSIGNED) ASC, m.chmsu_section ASC, m.chmsu_last_name ASC, m.chmsu_first_name ASC";

$result = $conn->query($query);
$students = array();
$totalApproved = 0; $totalPending = 0; $totalRegular = 0; $totalIrregular = 0;

while ($row = $result->fetch_assoc()) {
    $officesCompleted = $row['offices_completed'] ?: 0;
    $clearanceStatus = ($officesCompleted >= $totalOffices) ? 'Approved' : 'Pending';
    
    if ($clearanceStatus === 'Approved') $totalApproved++;
    else $totalPending++;
    
    if ($filter_status === 'approved' && $clearanceStatus !== 'Approved') continue;
    if ($filter_status === 'pending'  && $clearanceStatus !== 'Pending')  continue;
    
    $studentType = ($row['is_irregular'] == 1) ? 'Irregular' : 'Regular';
    if ($studentType === 'Regular') $totalRegular++;
    else $totalIrregular++;
    
    $progress = $officesCompleted . ' / ' . $totalOffices;
    
    $yearDisplay = '';
    switch($row['year']) {
        case '1': $yearDisplay = '1st'; break;
        case '2': $yearDisplay = '2nd'; break;
        case '3': $yearDisplay = '3rd'; break;
        case '4': $yearDisplay = '4th'; break;
        default: $yearDisplay = $row['year'] . 'th';
    }
    
    $students[] = array(
        'id' => $row['chmsu_student_id'],
        'name' => $row['student_name'],
        'year' => $yearDisplay,
        'year_raw' => intval($row['year']),
        'section' => $row['section'],
        'course' => $row['course'],
        'type' => $studentType,
        'status' => $clearanceStatus,
        'progress' => $progress,
        'progress_raw' => $officesCompleted
    );
}

$totalStudents = count($students);
$completionRate = ($totalStudents > 0) ? round(($totalApproved / $totalStudents) * 100, 2) : 0;

$filterSummaryParts = array();
if ($filter_course !== '')  $filterSummaryParts[] = 'Course: ' . $filter_course;
if ($filter_year !== '')    $filterSummaryParts[] = 'Year: ' . $filter_year . 'th Year';
if ($filter_section !== '') $filterSummaryParts[] = 'Section: ' . $filter_section;
if ($filter_type !== '')    $filterSummaryParts[] = 'Type: ' . ucfirst($filter_type);
if ($filter_status !== '')  $filterSummaryParts[] = 'Status: ' . ucfirst($filter_status);
$filterSummaryText = !empty($filterSummaryParts) ? implode('  |  ', $filterSummaryParts) : 'All Records';

// Chart data
$yearChartLabels   = array('1st Year', '2nd Year', '3rd Year', '4th Year');
$yearChartApproved = array(0, 0, 0, 0);
$yearChartPending  = array(0, 0, 0, 0);
foreach ($students as $st) {
    $yi = intval($st['year_raw']) - 1;
    if ($yi >= 0 && $yi <= 3) {
        if ($st['status'] === 'Approved') $yearChartApproved[$yi]++;
        else $yearChartPending[$yi]++;
    }
}

$cardCls = 'bg-white border border-gray-300 rounded-lg shadow-sm dark:bg-[#1a1a1a] dark:border-[#333]';
$thCls   = 'px-3 py-2 text-start text-xs font-bold uppercase text-white bg-[#1b4d3e] border border-[#16402f] dark:bg-[#2c2c2c] dark:border-[#444]';
$tdCls   = 'px-3 py-1.5 text-sm border border-gray-300 dark:border-[#333]';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Registrar Reports</title>
    <script>try { if (localStorage.getItem('darkMode') === 'true') { document.documentElement.classList.add('dark'); } } catch (e) {}</script>

    <!-- SHARED SHELL STYLES (Identical to Dashboard) -->
    <style>
        :root { --hdr-h: 73px; --sb-w: 260px; --brand: #1b4d3e; --brand-2: #2d6a4f; --gold: #f1c40f; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'Times New Roman', Times, serif; background: #f5f5f5; font-size: 14px; }
        html.dark body { background: #0a0a0a; }
        
        /* Header (Identical to Dashboard) */
        .header { position: fixed; top: 0; left: 0; right: 0; height: var(--hdr-h); background: var(--brand); color: white; z-index: 60; display: flex; align-items: center; gap: 20px; padding: 15px 25px; }
        .header-logo img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 20px; font-weight: normal; margin: 0; }
        .header-title p { font-size: 11px; opacity: 0.8; margin-top: 3px; }
        .dark-mode-toggle { background: transparent; border: 1px solid rgba(255,255,255,0.3); color: white; padding: 6px 12px; cursor: pointer; margin-left: auto; display: inline-flex; align-items: center; gap: 6px; font-family: 'Times New Roman', Times, serif; }
        .logout-btn { background: #e74c3c; color: white; padding: 6px 15px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-family: 'Times New Roman', Times, serif; }
        
        /* Sidebar (Identical to Dashboard) */
        .registrar-sidebar { position: fixed; top: var(--hdr-h); left: 0; width: var(--sb-w); height: calc(100vh - var(--hdr-h)); background: var(--brand); color: white; z-index: 50; overflow-y: auto; overflow-x: hidden; }
        .registrar-sidebar::-webkit-scrollbar { width: 5px; }
        .registrar-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .registrar-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .registrar-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; font-family: 'Times New Roman', Times, serif; margin: 0; }
        .registrar-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        .registrar-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; padding-bottom: 20px; }
        .registrar-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-menu a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 13px; font-family: 'Times New Roman', Times, serif; transition: all 0.3s; }
        .registrar-sidebar .sidebar-menu a:hover, .registrar-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
        #appMain { margin-left: var(--sb-w); padding-top: var(--hdr-h); min-height: 100vh; }
        @media (max-width: 1023px) {
            .registrar-sidebar { transform: translateX(-100%); transition: transform .25s ease; }
            .registrar-sidebar.open { transform: translateX(0); }
            #appMain { margin-left: 0; }
        }
        
        /* Tabs */
        .ec-main-tabs { display: flex; gap: 4px; border-bottom: 2px solid #e5e7eb; margin-bottom: 16px; }
        html.dark .ec-main-tabs { border-bottom-color: #374151; }
        .ec-main-tab { cursor: pointer; border: 0; background: transparent; padding: 10px 20px; font-weight: 600; font-size: 14px; color: #6b7280; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all .15s; text-decoration: none; }
        .ec-main-tab:hover { color: #1b4d3e; }
        html.dark .ec-main-tab:hover { color: #f1c40f; }
        .ec-main-tab.active { color: #1b4d3e; border-bottom-color: #1b4d3e; }
        html.dark .ec-main-tab.active { color: #f1c40f; border-bottom-color: #f1c40f; }

        .ec-tab-panel { display: none; }
        .ec-tab-panel.active { display: block; }

        /* Report specific */
        .report-section-title { font-size: 13px; font-weight: bold; color: #1b4d3e; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 2px solid #1b4d3e; text-transform: uppercase; letter-spacing: 1px; }
        html.dark .report-section-title { color: #f1c40f; border-bottom-color: #f1c40f; }
        .badge { display: inline-block; padding: 1px 10px; border-radius: 999px; font-size: 10px; font-weight: bold; }
        .badge-approved { background: #d4efdf; color: #1e8449; }
        .badge-pending  { background: #fdebd0; color: #b9770e; }
        html.dark .badge-approved { background: #12351f; color: #6fdc9b; }
        html.dark .badge-pending { background: #3a2a0f; color: #f0b866; }
    </style>

    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { colors: { brand: { DEFAULT: '#1b4d3e', 600: '#2d6a4f', 50: '#eef6f2' }, gold: '#f1c40f' }, fontFamily: { serif: ['"Times New Roman"', 'Times', 'serif'] } } } };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="font-serif text-gray-800 dark:text-gray-100">

<!-- HEADER (Identical to Dashboard) -->
<header class="header">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Registrar Portal - Reports</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i data-lucide="moon" style="width:14px;height:14px;"></i> Dark Mode
    </button>
    <button onclick="confirmLogout()" class="logout-btn">
        <i data-lucide="log-out" style="width:14px;height:14px;"></i> Logout
    </button>
</header>

<!-- SIDEBAR (Identical to Dashboard) -->
<div class="registrar-sidebar" id="appSidebar">
    <div class="sidebar-header">
        <h3>Registrar Portal</h3>
        <p>Student Records Management</p>
    </div>
    <ul class="sidebar-menu">
        <li><a href="?section=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
        <li><a href="?section=masterlist"><i class="fas fa-users"></i> Master List</a></li>
        <li><a href="?section=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
        <li><a href="?section=clearance_status"><i class="fas fa-user-check"></i> Clearance Status</a></li>
        <li><a href="?section=history"><i class="fas fa-history"></i> Clearance History</a></li>
        <li><a href="?section=reports" class="active"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <li><a href="?section=archived"><i class="fas fa-archive"></i> Archive</a></li>
        <li><a href="?section=promotion"><i class="fas fa-arrow-up"></i> Student Promotion</a></li>
        <li><a href="?section=note"><i class="fas fa-rss"></i> Feed Updates</a></li>
        <li><a href="?section=chat"><i class="fas fa-comment-dots"></i> Messages</a></li>
        <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
            <a href="#" onclick="confirmLogout(); return false;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </li>
    </ul>
    <div style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
        Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
        <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px;">
            <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f;"></div>
        </div>
    </div>
</div>

<!-- MAIN CONTENT -->
<main id="appMain" class="bg-[#f5f5f5] dark:bg-[#0a0a0a]">
<div class="p-3 sm:p-5 max-w-[1400px] mx-auto">

    <!-- Title bar -->
    <div class="<?php echo $cardCls; ?> px-4 py-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between mb-4">
        <div>
            <nav class="text-xs text-gray-500 dark:text-neutral-400 mb-0.5">
                <a href="?section=dashboard" class="hover:text-brand dark:hover:text-brand-gold">Registrar</a>
                <span class="mx-1">/</span><span class="text-gray-700 dark:text-neutral-200">Reports & Analytics</span>
            </nav>
            <h2 class="text-lg font-semibold text-brand dark:text-brand-gold">Clearance Completion Report</h2>
        </div>
        <span class="text-sm text-gray-600 dark:text-neutral-300"><i class="far fa-calendar-alt mr-1"></i><?php echo date('F d, Y'); ?></span>
    </div>

    <!-- Tabs -->
    <div class="ec-main-tabs" role="tablist">
        <a href="?section=reports&tab=reports" class="ec-main-tab <?php echo $activeTab === 'reports' ? 'active' : ''; ?>"><i class="fas fa-file-alt mr-1"></i> Reports</a>
        <a href="?section=reports&tab=analytics" class="ec-main-tab <?php echo $activeTab === 'analytics' ? 'active' : ''; ?>"><i class="fas fa-chart-pie mr-1"></i> Analytics</a>
    </div>

    <!-- TAB 1: REPORTS -->
    <div class="ec-tab-panel <?php echo $activeTab === 'reports' ? 'active' : ''; ?>">
        <!-- Filters -->
        <div class="<?php echo $cardCls; ?> mb-4 overflow-hidden">
            <div class="bg-gray-50 dark:bg-[#222] px-4 py-2.5 border-b border-gray-200 dark:border-[#333] text-[13px]"><i class="fas fa-filter"></i> Filter Report <span class="block sm:inline text-[11px] text-gray-500 dark:text-gray-400 sm:ml-2">— Each filter works on its own.</span></div>
            <div class="p-4">
                <form method="GET" action="">
                    <input type="hidden" name="section" value="reports">
                    <input type="hidden" name="tab" value="reports">
                    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 items-end">
                        <div>
                            <label class="block text-[11px] font-bold mb-1 text-gray-700 dark:text-gray-300">Course</label>
                            <select name="filter_course" class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-[#444] rounded bg-white dark:bg-[#2c2c2c]">
                                <option value="">All Courses</option>
                                <?php
                                $courses = $conn->query("SELECT * FROM chmsu_courses ORDER BY course_code");
                                while($c = $courses->fetch_assoc()):
                                ?>
                                <option value="<?php echo $c['course_code']; ?>" <?php echo $filter_course == $c['course_code'] ? 'selected' : ''; ?>><?php echo $c['course_code']; ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold mb-1 text-gray-700 dark:text-gray-300">Year Level</label>
                            <select name="filter_year" class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-[#444] rounded bg-white dark:bg-[#2c2c2c]">
                                <option value="">All Years</option>
                                <option value="1" <?php echo $filter_year == '1' ? 'selected' : ''; ?>>1st Year</option>
                                <option value="2" <?php echo $filter_year == '2' ? 'selected' : ''; ?>>2nd Year</option>
                                <option value="3" <?php echo $filter_year == '3' ? 'selected' : ''; ?>>3rd Year</option>
                                <option value="4" <?php echo $filter_year == '4' ? 'selected' : ''; ?>>4th Year</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold mb-1 text-gray-700 dark:text-gray-300">Section</label>
                            <select name="filter_section" class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-[#444] rounded bg-white dark:bg-[#2c2c2c]">
                                <option value="">All Sections</option>
                                <?php
                                $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections ORDER BY section_name");
                                while($s = $sections->fetch_assoc()):
                                ?>
                                <option value="<?php echo $s['section_name']; ?>" <?php echo $filter_section == $s['section_name'] ? 'selected' : ''; ?>><?php echo $s['section_name']; ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold mb-1 text-gray-700 dark:text-gray-300">Student Type</label>
                            <select name="filter_type" class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-[#444] rounded bg-white dark:bg-[#2c2c2c]">
                                <option value="">All Types</option>
                                <option value="regular" <?php echo $filter_type == 'regular' ? 'selected' : ''; ?>>Regular Only</option>
                                <option value="irregular" <?php echo $filter_type == 'irregular' ? 'selected' : ''; ?>>Irregular Only</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold mb-1 text-gray-700 dark:text-gray-300">Clearance Status</label>
                            <select name="filter_status" class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-[#444] rounded bg-white dark:bg-[#2c2c2c]">
                                <option value="">All Status</option>
                                <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>Approved Only</option>
                                <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending Only</option>
                            </select>
                        </div>

                        <div class="col-span-2 md:col-span-3 xl:col-span-1 flex gap-2">
                            <button type="submit" class="flex-1 bg-brand hover:bg-brand-600 dark:bg-gold dark:text-black text-white px-3 py-1.5 text-xs rounded"><i class="fas fa-search"></i> Apply</button>
                            <a href="?section=reports&tab=reports" class="flex-1 text-center bg-gray-500 hover:bg-gray-600 text-white px-3 py-1.5 text-xs rounded no-underline"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Export buttons -->
        <div class="flex flex-wrap gap-2 mb-3 justify-end">
            <a href="modules/registrar/reports_export.php?export_report=excel&filter_course=<?php echo urlencode($filter_course); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_section=<?php echo urlencode($filter_section); ?>&filter_type=<?php echo urlencode($filter_type); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 text-xs rounded no-underline">
                <i class="fas fa-file-excel"></i> Export to Excel
            </a>
            <a href="modules/registrar/reports_export.php?export_report=word&filter_course=<?php echo urlencode($filter_course); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_section=<?php echo urlencode($filter_section); ?>&filter_type=<?php echo urlencode($filter_type); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="bg-brand hover:bg-brand-600 text-white px-4 py-2 text-xs rounded no-underline">
                <i class="fas fa-file-word"></i> Export to Word
            </a>
            <a href="modules/registrar/reports_export.php?export_report=pdf&filter_course=<?php echo urlencode($filter_course); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_section=<?php echo urlencode($filter_section); ?>&filter_type=<?php echo urlencode($filter_type); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 text-xs rounded no-underline">
                <i class="fas fa-file-pdf"></i> Export to PDF
            </a>
        </div>

        <!-- REPORT PAPER -->
        <div class="<?php echo $cardCls; ?> p-4 sm:p-8 mb-4">
            <div class="text-center pb-4 mb-4 border-b-[3px] border-double border-brand dark:border-gold">
                <div class="text-base sm:text-2xl font-bold text-brand dark:text-gold tracking-wider sm:tracking-[2px]">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>
                <div class="text-xs text-gray-600 dark:text-gray-300 mt-1">Talisay City, Negros Occidental, Philippines</div>
                <div class="text-sm sm:text-lg font-bold text-brand dark:text-gold mt-3 uppercase tracking-widest">Clearance Completion Report</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 italic">Registrar Office — Student Clearance Monitoring</div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs border-collapse mb-4">
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 w-1/4 text-brand dark:text-gold">Report Title</td><td class="border border-gray-300 dark:border-[#444] p-2">Clearance Completion Report (Student-Level Detail)</td></tr>
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Generated On</td><td class="border border-gray-300 dark:border-[#444] p-2"><?php echo date('F d, Y h:i A'); ?></td></tr>
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Generated By</td><td class="border border-gray-300 dark:border-[#444] p-2">Registrar Office</td></tr>
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Filters Applied</td><td class="border border-gray-300 dark:border-[#444] p-2"><?php echo htmlspecialchars($filterSummaryText); ?></td></tr>
                </table>
            </div>

            <!-- Quick figures -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 mb-6">
                <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Total Students</div><div class="text-lg font-bold text-brand dark:text-gold"><?php echo $totalStudents; ?></div></div>
                <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Approved</div><div class="text-lg font-bold text-green-700 dark:text-green-400"><?php echo $totalApproved; ?></div></div>
                <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Pending</div><div class="text-lg font-bold text-amber-600 dark:text-amber-400"><?php echo $totalPending; ?></div></div>
                <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Regular</div><div class="text-lg font-bold text-brand dark:text-gold"><?php echo $totalRegular; ?></div></div>
                <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Irregular</div><div class="text-lg font-bold text-brand dark:text-gold"><?php echo $totalIrregular; ?></div></div>
                <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Completion Rate</div><div class="text-lg font-bold text-brand dark:text-gold"><?php echo $completionRate; ?>%</div></div>
            </div>

            <div class="report-section-title">I. Student Clearance Details</div>
            <div class="overflow-x-auto mb-6">
                <table class="w-full table-fixed border-collapse text-xs" id="reportTable">
                    <thead>
                        <tr>
                            <th class="<?php echo $thCls; ?> w-10 text-center">No.</th>
                            <th class="<?php echo $thCls; ?> w-28">School ID</th>
                            <th class="<?php echo $thCls; ?>">Student Name</th>
                            <th class="<?php echo $thCls; ?> w-16">Year</th>
                            <th class="<?php echo $thCls; ?> w-16 text-center">Section</th>
                            <th class="<?php echo $thCls; ?> w-20">Course</th>
                            <th class="<?php echo $thCls; ?> w-20">Type</th>
                            <th class="<?php echo $thCls; ?> w-24 text-center">Status</th>
                            <th class="<?php echo $thCls; ?> w-24 text-center">Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="9" class="<?php echo $tdCls; ?> text-center py-4 text-gray-500">No students found for the selected filters.</td></tr>
                        <?php else: ?>
                            <?php $num = 1; foreach ($students as $row): 
                                $badgeClass = ($row['status'] === 'Approved') ? 'badge-approved' : 'badge-pending';
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-[#2c2c2c]">
                                <td class="<?php echo $tdCls; ?> text-center"><?php echo $num++; ?></td>
                                <td class="<?php echo $tdCls; ?> font-mono"><?php echo htmlspecialchars($row['id']); ?></td>
                                <td class="<?php echo $tdCls; ?> font-bold"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td class="<?php echo $tdCls; ?>"><?php echo htmlspecialchars($row['year']); ?></td>
                                <td class="<?php echo $tdCls; ?> text-center"><?php echo htmlspecialchars($row['section']); ?></td>
                                <td class="<?php echo $tdCls; ?>"><?php echo htmlspecialchars($row['course']); ?></td>
                                <td class="<?php echo $tdCls; ?>"><?php echo htmlspecialchars($row['type']); ?></td>
                                <td class="<?php echo $tdCls; ?> text-center"><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                <td class="<?php echo $tdCls; ?> text-center font-bold"><?php echo htmlspecialchars($row['progress']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="report-section-title">II. Summary Report</div>
            <div class="overflow-x-auto mb-6">
                <table class="w-full text-xs border-collapse">
                    <tr><th colspan="2" class="<?php echo $thCls; ?>">Clearance Statistics</th></tr>
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Total Students</td><td class="border border-gray-300 dark:border-[#444] p-2 text-right font-bold"><?php echo $totalStudents; ?></td></tr>
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Approved (Completed Clearance)</td><td class="border border-gray-300 dark:border-[#444] p-2 text-right font-bold text-green-600"><?php echo $totalApproved; ?></td></tr>
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Pending (In Progress)</td><td class="border border-gray-300 dark:border-[#444] p-2 text-right font-bold text-amber-600"><?php echo $totalPending; ?></td></tr>
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Regular Students</td><td class="border border-gray-300 dark:border-[#444] p-2 text-right font-bold"><?php echo $totalRegular; ?></td></tr>
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Irregular Students</td><td class="border border-gray-300 dark:border-[#444] p-2 text-right font-bold"><?php echo $totalIrregular; ?></td></tr>
                    <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Clearance Completion Rate</td><td class="border border-gray-300 dark:border-[#444] p-2 text-right font-bold"><?php echo $completionRate; ?>%</td></tr>
                </table>
            </div>

            <div class="mt-8 pt-3 border-t border-gray-200 dark:border-[#333] text-center text-[10px] text-gray-500 dark:text-gray-400 leading-relaxed">
                This is an official computer-generated report from the CHMSU E-Clearance System.<br>
                No signature is required. For verification, please contact the Office of the Registrar.
            </div>
        </div>
    </div>

    <!-- TAB 2: ANALYTICS -->
    <div class="ec-tab-panel <?php echo $activeTab === 'analytics' ? 'active' : ''; ?>">
        <div class="<?php echo $cardCls; ?> p-4 mb-4 text-sm text-gray-700 dark:text-gray-300">
            <i class="fas fa-info-circle text-brand dark:text-gold mr-1"></i> Charts display current data based on the filters applied.
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="<?php echo $cardCls; ?> p-4 flex flex-col">
                <div class="text-sm font-bold text-center mb-2 text-brand dark:text-gold border-b border-gray-200 dark:border-[#333] pb-2">Clearance Status</div>
                <div class="relative h-64"><canvas id="chartStatus"></canvas></div>
            </div>
            <div class="<?php echo $cardCls; ?> p-4 flex flex-col">
                <div class="text-sm font-bold text-center mb-2 text-brand dark:text-gold border-b border-gray-200 dark:border-[#333] pb-2">Student Type</div>
                <div class="relative h-64"><canvas id="chartType"></canvas></div>
            </div>
            <div class="<?php echo $cardCls; ?> p-4 flex flex-col">
                <div class="text-sm font-bold text-center mb-2 text-brand dark:text-gold border-b border-gray-200 dark:border-[#333] pb-2">Status per Year Level</div>
                <div class="relative h-64"><canvas id="chartYear"></canvas></div>
            </div>
        </div>
    </div>
</div>
</main>

<script>
    // ---------- Sidebar ----------
    function toggleSidebar() {
        document.getElementById('appSidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('hidden');
    }

    // ---------- Dark Mode ----------
    function syncDarkButton() {
        var isDark = document.documentElement.classList.contains('dark');
        var btn = document.querySelector('.dark-mode-toggle');
        if (btn) {
            btn.innerHTML = isDark ? '<i data-lucide="sun" style="width:14px;height:14px;"></i> Light Mode' : '<i data-lucide="moon" style="width:14px;height:14px;"></i> Dark Mode';
            lucide.createIcons();
        }
        document.body.classList.toggle('dark-mode', isDark);
    }
    function toggleDarkMode() {
        document.documentElement.classList.toggle('dark');
        localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
        syncDarkButton();
        buildCharts();
    }
    syncDarkButton();
    lucide.createIcons();

    function confirmLogout() { if(confirm('Are you sure you want to logout?')) window.location.href = '?logout=1'; }

    // ---------- Charts ----------
    var cApproved = <?php echo intval($totalApproved); ?>;
    var cPending  = <?php echo intval($totalPending); ?>;
    var cRegular  = <?php echo intval($totalRegular); ?>;
    var cIrregular = <?php echo intval($totalIrregular); ?>;
    var yearLabels   = <?php echo json_encode($yearChartLabels); ?>;
    var yearApproved = <?php echo json_encode($yearChartApproved); ?>;
    var yearPending  = <?php echo json_encode($yearChartPending); ?>;
    var charts = [];

    function buildCharts() {
        if (typeof Chart === 'undefined') return;
        charts.forEach(function (c) { c.destroy(); });
        charts = [];

        var dark = document.documentElement.classList.contains('dark');
        var text = dark ? '#cfcfcf' : '#444';
        var grid = dark ? '#333' : '#e5e7eb';
        var cGreen = dark ? '#6fdc9b' : '#1e8449';
        var cAmber = dark ? '#f0b866' : '#e08e0b';
        var cBrand = dark ? '#f1c40f' : '#1b4d3e';
        var cBlue  = dark ? '#7fb8e6' : '#3498db';
        var border = dark ? '#1a1a1a' : '#ffffff';

        var legend = { position: 'bottom', labels: { color: text, boxWidth: 10, font: { size: 11 } } };

        var elStatus = document.getElementById('chartStatus');
        if (elStatus) {
            charts.push(new Chart(elStatus, {
                type: 'doughnut',
                data: { labels: ['Approved', 'Pending'], datasets: [{ data: [cApproved, cPending], backgroundColor: [cGreen, cAmber], borderColor: border, borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: legend } }
            }));
        }

        var elType = document.getElementById('chartType');
        if (elType) {
            charts.push(new Chart(elType, {
                type: 'doughnut',
                data: { labels: ['Regular', 'Irregular'], datasets: [{ data: [cRegular, cIrregular], backgroundColor: [cBrand, cBlue], borderColor: border, borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: legend } }
            }));
        }

        var elYear = document.getElementById('chartYear');
        if (elYear) {
            charts.push(new Chart(elYear, {
                type: 'bar',
                data: {
                    labels: yearLabels,
                    datasets: [
                        { label: 'Approved', data: yearApproved, backgroundColor: cGreen, borderRadius: 3 },
                        { label: 'Pending',  data: yearPending,  backgroundColor: cAmber, borderRadius: 3 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: legend },
                    scales: {
                        x: { stacked: true, ticks: { color: text, font: { size: 10 } }, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, ticks: { color: text, precision: 0 }, grid: { color: grid } }
                    }
                }
            }));
        }
    }

    // Initial build if on analytics tab
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('chartStatus')) {
            buildCharts();
        }
    });
</script>

</body>
</html>