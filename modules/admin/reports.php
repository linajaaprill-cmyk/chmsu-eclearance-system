<?php
/**
 * CHMSU E-Clearance System — Admin > Reports
 * - View First layout (like Registrar).
 * - Removed Analytics, PDF, School Years, and Reporting Period filter.
 * - Only shows 2 Semesters max in the view.
 */
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
$adminSection = 'reports';
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

/* ---------------- Helpers ---------------- */
function adm_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function adm_year_label($y) {
    $map = ['1' => '1st Year', '2' => '2nd Year', '3' => '3rd Year', '4' => '4th Year', '5' => '5th Year'];
    return isset($map[(string)$y]) ? $map[(string)$y] : ('Year ' . $y);
}

/* ---------------- Data ---------------- */
$totalCourses        = $conn->query("SELECT COUNT(*) as c FROM chmsu_courses")->fetch_assoc()['c'];
$totalOffices        = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];
$totalSections       = $conn->query("SELECT COUNT(*) as c FROM chmsu_course_sections")->fetch_assoc()['c'];
$totalAdminAccounts  = $conn->query("SELECT COUNT(*) as c FROM chmsu_admin_users")->fetch_assoc()['c'];
$totalOfficeAccounts = $conn->query("SELECT COUNT(*) as c FROM chmsu_auth_roles")->fetch_assoc()['c'];

$courses_data = [];
$courses = $conn->query("SELECT course_code, course_name FROM chmsu_courses ORDER BY course_code");
while ($row = $courses->fetch_assoc()) $courses_data[] = $row;

$offices_data = [];
$offices = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
while ($row = $offices->fetch_assoc()) $offices_data[] = $row;

$sections_data = [];
$sections = $conn->query("SELECT course, year, section_name FROM chmsu_course_sections ORDER BY course, year, section_name");
while ($row = $sections->fetch_assoc()) $sections_data[] = $row;

// Fetch only 2 semesters max as requested
$semesters_data = [];
$semesters = $conn->query("SELECT semester_name, semester_code, is_current FROM chmsu_semesters ORDER BY id DESC LIMIT 2");
while ($row = $semesters->fetch_assoc()) $semesters_data[] = $row;

$cardCls = 'bg-white border border-gray-200 shadow-sm rounded-xl dark:bg-neutral-900 dark:border-neutral-700';
$thCls   = 'px-3 py-2 text-start text-xs font-semibold uppercase text-white bg-[#1b4d3e] border border-[#16402f]';
$tdCls   = 'px-3 py-1.5 text-sm text-gray-800 border border-gray-300 dark:text-neutral-200 dark:border-neutral-600';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Admin Reports</title>
    <script>try { if (localStorage.getItem('darkMode') === 'true') { document.documentElement.classList.add('dark'); } } catch (e) {}</script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root { --ec-hdr: 56px; --ec-sb: 260px; }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 14px; background: #f3f4f6; color: #1f2937; }
        html.dark body { background: #0a0a0a; color: #e5e7eb; }

        .ec-header { position: fixed; top: 0; left: 0; right: 0; height: var(--ec-hdr); background: #1b4d3e; color: #fff; z-index: 50; display: flex; align-items: center; gap: 12px; padding: 0 16px; }
        .ec-header img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; flex: none; }
        .ec-title { min-width: 0; flex: 1; }
        .ec-title h1 { margin: 0; font-size: 16px; font-weight: 600; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ec-title p { margin: 2px 0 0; font-size: 10px; opacity: .8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ec-btn { background: transparent; border: 1px solid rgba(255,255,255,.35); color: #fff; padding: 6px 12px; cursor: pointer; border-radius: 999px; font: inherit; font-size: 12px; white-space: nowrap; }
        .ec-btn:hover { background: rgba(255,255,255,.12); }
        .ec-btn.danger { background: #e74c3c; border-color: #e74c3c; border-radius: 6px; }
        .ec-btn.danger:hover { background: #c0392b; }
        .ec-menu-btn { display: none; background: transparent; border: 0; color: #fff; font-size: 20px; cursor: pointer; padding: 4px 6px; }

        .ec-sidebar { position: fixed; top: var(--ec-hdr); left: 0; bottom: 0; width: var(--ec-sb); background: #1b4d3e; color: #fff; overflow-y: auto; z-index: 40; transition: transform .2s ease; }
        .ec-sidebar::-webkit-scrollbar { width: 5px; }
        .ec-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .ec-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .ec-sb-head { padding: 15px; border-bottom: 1px solid #2d6a4f; }
        .ec-sb-head h3 { margin: 0; font-size: 14px; font-weight: 500; word-break: break-word; }
        .ec-sb-head p { margin: 5px 0 0; font-size: 10px; opacity: .7; }
        .ec-nav { list-style: none; margin: 0; padding: 0 0 20px; }
        .ec-nav li { border-bottom: 1px solid #2d6a4f; }
        .ec-nav a { display: block; padding: 10px 15px; color: #fff; text-decoration: none; font-size: 12px; transition: background .15s, color .15s; }
        .ec-nav a i { width: 16px; text-align: center; margin-right: 4px; }
        .ec-nav a:hover, .ec-nav a.active { background: #f1c40f; color: #000; }
        .ec-nav ul { list-style: none; margin: 0; padding: 0; background: #0f3b2f; }
        .ec-nav ul a { padding: 10px 20px 10px 35px; font-size: 11px; }
        .ec-nav-logout { margin-top: 20px; border-top: 1px solid #2d6a4f; }
        .ec-storage { padding: 10px; font-size: 10px; color: #ddd; border-top: 1px solid #2d6a4f; margin-top: 20px; }
        .ec-storage .bar { width: 100%; height: 3px; background: #2d6a4f; margin-top: 4px; border-radius: 2px; }
        .ec-storage .bar div { height: 100%; background: #f1c40f; border-radius: 2px; }
        .ec-backdrop { display: none; position: fixed; inset: var(--ec-hdr) 0 0 0; background: rgba(0,0,0,.5); z-index: 35; }
        .ec-backdrop.show { display: block; }

        .ec-main { margin-left: var(--ec-sb); padding-top: var(--ec-hdr); min-height: 100vh; }
        .ec-content { opacity: 0; }
        .ec-content.ready { opacity: 1; transition: opacity .12s ease; }

        @media (max-width: 1023px) {
            .ec-sidebar { transform: translateX(-100%); }
            .ec-sidebar.open { transform: none; box-shadow: 4px 0 18px rgba(0,0,0,.35); }
            .ec-main { margin-left: 0; }
            .ec-menu-btn { display: inline-block; }
        }
        @media (max-width: 640px) {
            .ec-header { padding: 0 10px; gap: 8px; }
            .ec-title h1 { font-size: 12px; }
            .ec-btn .lbl { display: none; }
        }

        .report-letterhead { text-align: center; padding-bottom: 12px; margin-bottom: 14px; border-bottom: 3px double #1b4d3e; }
        html.dark .report-letterhead { border-bottom-color: #f1c40f; }
        .report-letterhead .univ { font-size: 18px; font-weight: 700; color: #1b4d3e; letter-spacing: 1px; }
        html.dark .report-letterhead .univ { color: #f1c40f; }
        .report-letterhead .addr { font-size: 11px; color: #6b7280; margin-top: 2px; }
        html.dark .report-letterhead .addr { color: #9ca3af; }
        .report-letterhead .title { font-size: 15px; font-weight: 700; color: #1b4d3e; margin-top: 10px; text-transform: uppercase; letter-spacing: 1.5px; }
        html.dark .report-letterhead .title { color: #f1c40f; }
    </style>

    <noscript><style>.ec-content { opacity: 1 !important; }</style></noscript>

    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { colors: { brand: { DEFAULT: '#1b4d3e', dark: '#0f3b2f', light: '#2d6a4f', gold: '#f1c40f' } } } } };
    </script>
</head>
<body>

<header class="ec-header">
    <button type="button" class="ec-menu-btn" id="menuBtn" aria-label="Open menu"><i class="fas fa-bars"></i></button>
    <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    <div class="ec-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Admin Portal - Reports</p>
    </div>
    <button type="button" class="ec-btn dark-mode-toggle" onclick="toggleDarkMode()"><i class="fas fa-moon"></i> <span class="lbl">Dark Mode</span></button>
    <button type="button" class="ec-btn danger" onclick="logoutUser()"><i class="fas fa-sign-out-alt"></i> <span class="lbl">Logout</span></button>
</header>

<div class="ec-backdrop" id="sbBackdrop"></div>
<aside class="ec-sidebar" id="sidebar">
    <div class="ec-sb-head">
        <h3>Admin: <?php echo htmlspecialchars($admin); ?></h3>
        <p>System Administrator</p>
    </div>
    <ul class="ec-nav">
        <li><a href="?adminsection=dashboard" class="<?php echo $adminSection == 'dashboard' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a></li>
        <li>
            <a href="#" onclick="return false;" style="cursor:default;"><i class="fas fa-cogs"></i> Maintenance</a>
            <ul>
                <li><a href="?adminsection=courses" class="<?php echo $adminSection == 'courses' ? 'active' : ''; ?>"><i class="fas fa-book"></i> Courses</a></li>
                <li><a href="?adminsection=offices" class="<?php echo $adminSection == 'offices' ? 'active' : ''; ?>"><i class="fas fa-building"></i> Offices</a></li>
                <li><a href="?adminsection=sections" class="<?php echo $adminSection == 'sections' ? 'active' : ''; ?>"><i class="fas fa-layer-group"></i> Sections</a></li>
                <li><a href="?adminsection=school_years" class="<?php echo $adminSection == 'school_years' ? 'active' : ''; ?>"><i class="fas fa-calendar"></i> School Years</a></li>
                <li><a href="?adminsection=semesters" class="<?php echo $adminSection == 'semesters' ? 'active' : ''; ?>"><i class="fas fa-clock"></i> Semesters</a></li>
            </ul>
        </li>
        <li><a href="?adminsection=reports" class="active"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <li><a href="?adminsection=activity" class="<?php echo $adminSection == 'activity' ? 'active' : ''; ?>"><i class="fas fa-history"></i> Activity Log</a></li>
        <li class="ec-nav-logout"><a href="#" onclick="logoutUser(); return false;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
    <div class="ec-storage">
        Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
        <div class="bar"><div style="width:<?php echo $storage_percent; ?>%;"></div></div>
    </div>
</aside>

<main class="ec-main">
<div class="ec-content p-3 sm:p-5 max-w-[1400px] mx-auto" id="ecContent">

    <div class="<?php echo $cardCls; ?> px-4 py-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between mb-4">
        <div>
            <nav class="text-xs text-gray-500 dark:text-neutral-400 mb-0.5">
                <a href="?adminsection=dashboard" class="hover:text-brand dark:hover:text-brand-gold">Admin</a>
                <span class="mx-1">/</span><span class="text-gray-700 dark:text-neutral-200">Reports</span>
            </nav>
            <h2 class="text-lg font-semibold text-brand dark:text-brand-gold">System Management Reports</h2>
        </div>
        <span class="text-sm text-gray-600 dark:text-neutral-300"><i class="far fa-calendar-alt mr-1"></i><?php echo date('F d, Y'); ?></span>
    </div>

    <!-- Export Buttons (No Flatpickr/Period filter anymore) -->
    <div class="flex justify-end gap-2 mb-4">
        <a href="modules/admin/reports_export.php?export_type=excel" class="inline-flex justify-center items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="modules/admin/reports_export.php?export_type=word" class="inline-flex justify-center items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-brand text-white hover:bg-brand-light shadow-sm">
            <i class="fas fa-file-word"></i> Export Word
        </a>
    </div>

    <!-- REPORT PAPER (View First) -->
    <div class="<?php echo $cardCls; ?> p-4 sm:p-8 mb-4">
        <div class="report-letterhead">
            <div class="univ">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>
            <div class="addr">Talisay City, Negros Occidental, Philippines</div>
            <div class="title">Admin System Management Report</div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs border-collapse mb-6">
                <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 w-1/4 text-brand dark:text-gold">Report Title</td><td class="border border-gray-300 dark:border-[#444] p-2">System Management Report</td></tr>
                <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Generated On</td><td class="border border-gray-300 dark:border-[#444] p-2"><?php echo date('F d, Y h:i A'); ?></td></tr>
                <tr><td class="bg-gray-50 dark:bg-[#2c2c2c] font-bold border border-gray-300 dark:border-[#444] p-2 text-brand dark:text-gold">Generated By</td><td class="border border-gray-300 dark:border-[#444] p-2">System Administrator (<?php echo adm_h($admin); ?>)</td></tr>
            </table>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 mb-6">
            <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Total Courses</div><div class="text-lg font-bold text-brand dark:text-gold"><?php echo $totalCourses; ?></div></div>
            <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Total Offices</div><div class="text-lg font-bold text-brand dark:text-gold"><?php echo $totalOffices; ?></div></div>
            <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Total Sections</div><div class="text-lg font-bold text-brand dark:text-gold"><?php echo $totalSections; ?></div></div>
            <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Admin Accounts</div><div class="text-lg font-bold text-brand dark:text-gold"><?php echo $totalAdminAccounts; ?></div></div>
            <div class="border border-gray-300 dark:border-[#333] rounded px-3 py-2 text-center bg-gray-50 dark:bg-[#2c2c2c]"><div class="text-[10px] text-gray-500 dark:text-gray-400">Office Accounts</div><div class="text-lg font-bold text-brand dark:text-gold"><?php echo $totalOfficeAccounts; ?></div></div>
        </div>

        <!-- I. System Summary -->
        <div class="text-[13px] font-bold text-brand dark:text-gold uppercase tracking-wider border-b-2 border-brand dark:border-gold mb-3 pb-1">I. System Summary</div>
        <div class="overflow-x-auto mb-6">
            <table class="w-full border-collapse text-xs">
                <tr><th class="<?php echo $thCls; ?> w-2/3">Metric</th><th class="<?php echo $thCls; ?>">Value</th></tr>
                <tr><td class="<?php echo $tdCls; ?>">Total Courses</td><td class="<?php echo $tdCls; ?> text-right font-semibold"><?php echo $totalCourses; ?></td></tr>
                <tr><td class="<?php echo $tdCls; ?>">Total Offices</td><td class="<?php echo $tdCls; ?> text-right font-semibold"><?php echo $totalOffices; ?></td></tr>
                <tr><td class="<?php echo $tdCls; ?>">Total Sections</td><td class="<?php echo $tdCls; ?> text-right font-semibold"><?php echo $totalSections; ?></td></tr>
                <tr><td class="<?php echo $tdCls; ?>">Admin Accounts</td><td class="<?php echo $tdCls; ?> text-right font-semibold"><?php echo $totalAdminAccounts; ?></td></tr>
                <tr><td class="<?php echo $tdCls; ?>">Office Accounts</td><td class="<?php echo $tdCls; ?> text-right font-semibold"><?php echo $totalOfficeAccounts; ?></td></tr>
            </table>
        </div>

        <!-- II. Courses -->
        <div class="text-[13px] font-bold text-brand dark:text-gold uppercase tracking-wider border-b-2 border-brand dark:border-gold mb-3 pb-1">II. Courses List (<?php echo count($courses_data); ?>)</div>
        <div class="overflow-x-auto mb-6">
            <table class="w-full border-collapse text-xs">
                <thead><tr><th class="<?php echo $thCls; ?> w-12 text-center">#</th><th class="<?php echo $thCls; ?>">Course Code</th><th class="<?php echo $thCls; ?>">Course Name</th></tr></thead>
                <tbody>
                <?php $i = 1; foreach ($courses_data as $r): ?>
                    <tr><td class="<?php echo $tdCls; ?> text-center"><?php echo $i++; ?></td><td class="<?php echo $tdCls; ?>"><?php echo adm_h($r['course_code']); ?></td><td class="<?php echo $tdCls; ?>"><?php echo adm_h($r['course_name']); ?></td></tr>
                <?php endforeach; if (!$courses_data): ?>
                    <tr><td colspan="3" class="<?php echo $tdCls; ?> text-center">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- III. Offices -->
        <div class="text-[13px] font-bold text-brand dark:text-gold uppercase tracking-wider border-b-2 border-brand dark:border-gold mb-3 pb-1">III. Offices List (<?php echo count($offices_data); ?>)</div>
        <div class="overflow-x-auto mb-6">
            <table class="w-full border-collapse text-xs">
                <thead><tr><th class="<?php echo $thCls; ?> w-12 text-center">#</th><th class="<?php echo $thCls; ?>">Office Name</th></tr></thead>
                <tbody>
                <?php $i = 1; foreach ($offices_data as $r): ?>
                    <tr><td class="<?php echo $tdCls; ?> text-center"><?php echo $i++; ?></td><td class="<?php echo $tdCls; ?>"><?php echo adm_h($r['office_name']); ?></td></tr>
                <?php endforeach; if (!$offices_data): ?>
                    <tr><td colspan="2" class="<?php echo $tdCls; ?> text-center">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- IV. Sections -->
        <div class="text-[13px] font-bold text-brand dark:text-gold uppercase tracking-wider border-b-2 border-brand dark:border-gold mb-3 pb-1">IV. Sections List (<?php echo count($sections_data); ?>)</div>
        <div class="overflow-x-auto mb-6">
            <table class="w-full border-collapse text-xs">
                <thead><tr><th class="<?php echo $thCls; ?> w-12 text-center">#</th><th class="<?php echo $thCls; ?>">Course</th><th class="<?php echo $thCls; ?>">Year Level</th><th class="<?php echo $thCls; ?>">Section Name</th></tr></thead>
                <tbody>
                <?php $i = 1; foreach ($sections_data as $r): ?>
                    <tr><td class="<?php echo $tdCls; ?> text-center"><?php echo $i++; ?></td><td class="<?php echo $tdCls; ?>"><?php echo adm_h($r['course']); ?></td><td class="<?php echo $tdCls; ?>"><?php echo adm_h(adm_year_label($r['year'])); ?></td><td class="<?php echo $tdCls; ?>"><?php echo adm_h($r['section_name']); ?></td></tr>
                <?php endforeach; if (!$sections_data): ?>
                    <tr><td colspan="4" class="<?php echo $tdCls; ?> text-center">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- V. Semesters (Max 2) -->
        <div class="text-[13px] font-bold text-brand dark:text-gold uppercase tracking-wider border-b-2 border-brand dark:border-gold mb-3 pb-1">V. Semesters List (Max 2)</div>
        <div class="overflow-x-auto mb-6">
            <table class="w-full border-collapse text-xs">
                <thead><tr><th class="<?php echo $thCls; ?> w-12 text-center">#</th><th class="<?php echo $thCls; ?>">Semester Name</th><th class="<?php echo $thCls; ?> text-center">Code</th><th class="<?php echo $thCls; ?> text-center">Status</th></tr></thead>
                <tbody>
                <?php $i = 1; foreach ($semesters_data as $r): ?>
                    <tr><td class="<?php echo $tdCls; ?> text-center"><?php echo $i++; ?></td><td class="<?php echo $tdCls; ?>"><?php echo adm_h($r['semester_name']); ?></td><td class="<?php echo $tdCls; ?> text-center"><?php echo adm_h($r['semester_code']); ?></td>
                        <td class="<?php echo $tdCls; ?> text-center"><?php if ($r['is_current']): ?><span class="py-0.5 px-2 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">Current</span><?php endif; ?></td></tr>
                <?php endforeach; if (!$semesters_data): ?>
                    <tr><td colspan="4" class="<?php echo $tdCls; ?> text-center">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="mt-8 pt-3 border-t border-gray-200 dark:border-[#333] text-center text-[10px] text-gray-500 dark:text-gray-400 leading-relaxed">
            This is an official computer-generated report from the CHMSU E-Clearance System.<br>
            No signature is required. For verification, please contact the System Administrator.
        </div>
    </div>

</div>
</main>

<script>
    // ---------- Dark mode ----------
    function syncDarkButton() {
        const dark = document.documentElement.classList.contains('dark');
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) {
            btn.innerHTML = dark ? '<i class="fas fa-sun"></i> <span class="lbl">Light Mode</span>'
                                 : '<i class="fas fa-moon"></i> <span class="lbl">Dark Mode</span>';
        }
        document.body.classList.toggle('dark-mode', dark);
    }
    function toggleDarkMode() {
        document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('darkMode', document.documentElement.classList.contains('dark')); } catch (e) {}
        syncDarkButton();
    }
    syncDarkButton();

    // ---------- Logout ----------
    function logoutUser() {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = '?logout=1';
        }
    }

    // ---------- Mobile sidebar ----------
    (function () {
        const sb = document.getElementById('sidebar');
        const bd = document.getElementById('sbBackdrop');
        const btn = document.getElementById('menuBtn');
        if (!sb || !bd || !btn) return;
        function close() { sb.classList.remove('open'); bd.classList.remove('show'); }
        btn.addEventListener('click', function () { sb.classList.toggle('open'); bd.classList.toggle('show'); });
        bd.addEventListener('click', close);
        window.addEventListener('resize', function () { if (window.innerWidth >= 1024) close(); });
    })();

    // ---------- Reveal content ----------
    (function () {
        const c = document.getElementById('ecContent');
        if (!c) return;
        let done = false;
        function show() { if (!done) { done = true; c.classList.add('ready'); } }
        window.addEventListener('load', function () {
            requestAnimationFrame(function () { requestAnimationFrame(show); });
        });
        setTimeout(show, 1500);
    })();
</script>
</body>
</html>