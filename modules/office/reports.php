<?php
// modules/office/reports.php
// Office Reports & Analytics — VIEW FIRST (like Registrar), Analytics as separate tab
// Tables used: chmsu_students_master + chmsu_clearance_status (office_name = current office)

$office = $_SESSION['office'];
$currentOfficeSection = 'reports';
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

// ============================================
// DETECT CURRENT SEMESTER (Fixes duplicates/old status)
// ============================================
$current_sem_row = $conn->query("SELECT id FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
$current_sem_id  = $current_sem_row ? intval($current_sem_row['id']) : 0;
$sem_clause      = ($current_sem_id > 0) ? " AND cs.semester_id = $current_sem_id " : "";

// Active tab — VIEW is the default (mirrors Registrar's Reports-first flow)
$report_tab = isset($_GET['tab']) ? $_GET['tab'] : 'reports';
if (!in_array($report_tab, ['reports', 'analytics', 'export'], true)) $report_tab = 'reports';

// ============================================
// HELPERS
// ============================================
if (!function_exists('rpt_e')) {
    function rpt_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('rpt_ordinal')) {
    function rpt_ordinal($n) {
        $n = (int)$n;
        if ($n <= 0) return '-';
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]) . ' Year';
    }
}
if (!function_exists('rpt_status_label')) {
    function rpt_status_label($raw) {
        $raw = strtolower((string)$raw);
        if ($raw === 'clear') return 'Approved';
        if ($raw === 'unclear') return 'Declined';
        return 'Pending';
    }
}
if (!function_exists('rpt_fetch_rows')) {
    function rpt_fetch_rows($conn, $office, $type, $f, $sem_clause) {
        $office_esc = $conn->real_escape_string($office);
        
        // Standard LEFT JOIN strictly filtered by semester
        $where = "m.is_archived = 0";

        if ($f['course'] !== '')  $where .= " AND UPPER(TRIM(m.chmsu_course)) = UPPER('" . $conn->real_escape_string($f['course']) . "')";
        if ($f['year'] !== '')    $where .= " AND m.chmsu_year = '" . $conn->real_escape_string($f['year']) . "'";
        if ($f['section'] !== '') $where .= " AND UPPER(TRIM(m.chmsu_section)) = UPPER('" . $conn->real_escape_string($f['section']) . "')";
        
        if ($f['from'] !== '')    $where .= " AND cs.updated_at >= '" . $f['from'] . " 00:00:00'";
        if ($f['to'] !== '')      $where .= " AND cs.updated_at <= '" . $f['to'] . " 23:59:59'";

        if ($type === 'approved')      $where .= " AND cs.status = 'clear'";
        elseif ($type === 'declined')  $where .= " AND cs.status = 'unclear'";
        elseif ($type === 'pending')   $where .= " AND (cs.status IS NULL OR cs.status = 'pending')";

        $sql = "SELECT m.chmsu_student_id AS sid, m.chmsu_last_name AS ln, m.chmsu_first_name AS fn,
                       m.chmsu_middle_name AS mn, m.chmsu_full_name AS full_name,
                       m.chmsu_course AS course, m.chmsu_year AS yr, m.chmsu_section AS sec,
                       cs.status AS st, cs.note AS note, cs.updated_at AS upd, cs.updated_by AS upd_by
                FROM chmsu_students_master m
                LEFT JOIN chmsu_clearance_status cs
                       ON cs.student_id = m.chmsu_student_id 
                       AND LOWER(cs.office_name) = LOWER('$office_esc')
                       $sem_clause
                WHERE $where
                ORDER BY m.chmsu_course ASC, CAST(m.chmsu_year AS UNSIGNED) ASC, m.chmsu_section ASC,
                         m.chmsu_last_name ASC, m.chmsu_first_name ASC";
        $rows = [];
        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $name = trim((string)$r['full_name']);
                if ($name === '') $name = trim($r['ln'] . ', ' . $r['fn'] . ' ' . $r['mn']);
                $r['name'] = $name;
                $r['label'] = rpt_status_label($r['st']);
                $rows[] = $r;
            }
            $res->free();
        }
        return $rows;
    }
}

// ============================================
// FILTERS
// ============================================
$f = ['course' => '', 'year' => '', 'section' => '', 'from' => '', 'to' => ''];
if (!empty($_GET['course']))  $f['course']  = trim((string)$_GET['course']);
if (!empty($_GET['year']) && in_array((string)$_GET['year'], ['1', '2', '3', '4'], true)) $f['year'] = (string)$_GET['year'];
if (!empty($_GET['section'])) $f['section'] = trim((string)$_GET['section']);
$range_raw = isset($_GET['daterange']) ? trim((string)$_GET['daterange']) : '';
if ($range_raw !== '') {
    $parts = preg_split('/\s+to\s+/i', $range_raw);
    $d1 = $parts[0] ?? '';
    $d2 = $parts[1] ?? $d1;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) $f['from'] = $d1;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) $f['to'] = $d2;
}
$filterParams = [];
if ($f['course'] !== '')  $filterParams['course'] = $f['course'];
if ($f['year'] !== '')    $filterParams['year'] = $f['year'];
if ($f['section'] !== '') $filterParams['section'] = $f['section'];
if ($f['from'] !== '')    $filterParams['daterange'] = ($f['to'] !== '' && $f['to'] !== $f['from']) ? $f['from'] . ' to ' . $f['to'] : $f['from'];
$filterQS = http_build_query($filterParams);
$hasFilters = !empty($filterParams);

// Report status filter for the VIEW tab
$view_status = isset($_GET['view_status']) ? trim((string)$_GET['view_status']) : 'all';
if (!in_array($view_status, ['all','pending','approved','declined'], true)) $view_status = 'all';

// ============================================
// DATA
// ============================================
$allRows = rpt_fetch_rows($conn, $office, 'submissions', $f, $sem_clause);
$total_submissions = count($allRows);
$pending_count = 0; $approved_count = 0; $declined_count = 0;
$byCourse = [];
$byGroup = [];
foreach ($allRows as $r) {
    $c = $r['course'];
    if (!isset($byCourse[$c])) $byCourse[$c] = ['Approved' => 0, 'Pending' => 0, 'Declined' => 0];
    $byCourse[$c][$r['label']]++;
    $gk = $r['course'] . '|' . $r['yr'] . '|' . $r['sec'];
    if (!isset($byGroup[$gk])) $byGroup[$gk] = ['course' => $r['course'], 'yr' => $r['yr'], 'sec' => $r['sec'], 'total' => 0, 'Approved' => 0, 'Pending' => 0, 'Declined' => 0];
    $byGroup[$gk]['total']++;
    $byGroup[$gk][$r['label']]++;
    if ($r['label'] === 'Approved') $approved_count++;
    elseif ($r['label'] === 'Declined') $declined_count++;
    else $pending_count++;
}
$approval_rate = ($total_submissions > 0) ? round(($approved_count / $total_submissions) * 100, 1) : 0;

// VIEW tab data: filter by selected status
$viewRows = $allRows;
if ($view_status !== 'all') {
    $want = ucfirst($view_status);
    $viewRows = array_values(array_filter($allRows, function ($r) use ($want) { return $r['label'] === $want; }));
}
$viewCount = count($viewRows);

// Dropdown sources
$courseList = [];
$cq = $conn->query("SELECT course_code FROM chmsu_courses ORDER BY course_code");
if ($cq) while ($x = $cq->fetch_assoc()) $courseList[] = $x['course_code'];
$sectionList = [];
$sq = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections ORDER BY section_name");
if ($sq) while ($x = $sq->fetch_assoc()) $sectionList[] = $x['section_name'];

// Filter summary (for report header)
$filterSummaryParts = [];
if ($f['course'] !== '')  $filterSummaryParts[] = 'Course: ' . $f['course'];
if ($f['year'] !== '')    $filterSummaryParts[] = 'Year: ' . rpt_ordinal($f['year']);
if ($f['section'] !== '') $filterSummaryParts[] = 'Section: ' . $f['section'];
if ($f['from'] !== '')    $filterSummaryParts[] = 'Date Range: ' . $f['from'] . (($f['to'] !== '' && $f['to'] !== $f['from']) ? ' to ' . $f['to'] : '');
$filterSummaryText = !empty($filterSummaryParts) ? implode('  |  ', $filterSummaryParts) : 'All Records';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Reports & Analytics</title>

    <script>try { if (localStorage.getItem('darkMode') === 'true') { document.documentElement.classList.add('dark'); } } catch (e) {}</script>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { colors: {
                brand: { 50: '#eef7f2', 100: '#d6ebdf', 200: '#aed7c1', 300: '#7bbd9d', 400: '#4d9f79', 500: '#2d8260', 600: '#1b4d3e', 700: '#164032', 800: '#123327', 900: '#0e271d' },
                gold: { 400: '#f6d45a', 500: '#f1c40f', 600: '#d4a800' }
            } } }
        };
    </script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>

    <!-- CRITICAL LAYOUT: plain CSS so header + sidebar NEVER blink and keep the SAME SIZE -->
    <style>
        :root { --sidebar-width: 260px; --header-height: 73px; }
        html, body { height: 100%; }
        body { font-family: 'Times New Roman', Times, serif; background: #f5f5f5; margin: 0; }
        *, *::before, *::after { box-sizing: border-box; }
        html.dark body { background: #0a0a0a; }

        /* FIXED HEADER (exactly like clearance.php) */
        .app-header { position: fixed; top: 0; left: 0; right: 0; z-index: 1000; height: var(--header-height); background: #1b4d3e; color: #fff; display: flex; align-items: center; gap: 20px; padding: 15px 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
        .app-header .logo { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; }
        .app-header h1 { margin: 0; font-size: 20px; font-weight: normal; line-height: 1.2; }
        .app-header p { margin: 0; font-size: 11px; opacity: .8; }
        .hdr-dark-btn { margin-left: auto; background: transparent; border: 1px solid rgba(255,255,255,.3); color: #fff; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .hdr-logout { margin-left: 8px; background: #e74c3c; color: #fff; border: 0; border-radius: 4px; padding: 6px 15px; font-size: 14px; cursor: pointer; font-family: inherit; }
        .hdr-logout:hover { background: #c0392b; }

        /* FIXED SIDEBAR (exactly like clearance.php) */
        .app-sidebar { position: fixed; top: var(--header-height); left: 0; width: var(--sidebar-width); height: calc(100vh - var(--header-height)); background: #1b4d3e; color: #fff; overflow-y: auto; overflow-x: hidden; z-index: 900; transition: transform .25s ease; }
        .app-sidebar::-webkit-scrollbar { width: 5px; }
        .app-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .app-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .sb-head { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .sb-head h3 { margin: 0; font-size: 16px; font-weight: normal; }
        .sb-head p { margin: 4px 0 0; font-size: 11px; opacity: .7; }
        .sidebar-link { display: flex; align-items: center; gap: 10px; padding: 12px 20px; color: #fff; text-decoration: none; font-size: 13px; border-bottom: 1px solid #2d6a4f; transition: background .2s, color .2s; }
        .sidebar-link:hover, .sidebar-link.active { background: #f1c40f; color: #000; }
        .sidebar-link i { width: 18px; text-align: center; }
        .sidebar-link .chat-badge { background: #e74c3c; color: white; border-radius: 12px; padding: 2px 8px; font-size: 10px; min-width: 20px; text-align: center; margin-left: auto; }
        .sb-storage { padding: 12px 20px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px; }
        .sb-storage .bar { width: 100%; height: 3px; background: #2d6a4f; margin-top: 4px; border-radius: 2px; }
        .sb-storage .bar > div { height: 100%; background: #f1c40f; border-radius: 2px; }

        /* MAIN CONTENT (pushed down to account for fixed header) */
        .app-main { margin-left: var(--sidebar-width); margin-top: var(--header-height); min-height: calc(100vh - var(--header-height)); padding: 20px; visibility: hidden; }
        .app-main.ready { visibility: visible; }

        .mobile-menu-btn { display: none; background: transparent; border: 1px solid rgba(255,255,255,.3); color: #fff; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 899; }
        .sidebar-backdrop.show { display: block; }

        @media (max-width: 768px) {
            .app-sidebar { transform: translateX(-100%); box-shadow: 4px 0 20px rgba(0,0,0,.3); }
            .app-sidebar.open { transform: translateX(0); }
            .app-main { margin-left: 0; padding: 12px; }
            .mobile-menu-btn { display: inline-flex; }
            .app-header { padding: 12px 12px; gap: 10px; }
            .app-header .logo { display: none; }
            .app-header h1 { font-size: 15px; }
        }
    </style>

    <style>
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 1px 2px rgba(0,0,0,.04); overflow: hidden; }
        .card-header { padding: 14px 18px; border-bottom: 1px solid #eef0f2; background: #f9fafb; font-size: 13px; font-weight: 600; color: #1b4d3e; }
        .card-body { padding: 16px 18px; }

        .tab-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-bottom: 3px solid transparent; font-size: 14px; font-weight: 600; color: #6b7280; text-decoration: none; transition: all .2s; white-space: nowrap; }
        .tab-btn:hover { color: #1b4d3e; background: #f9fafb; }
        .tab-btn.active { color: #1b4d3e; border-bottom-color: #1b4d3e; }

        .stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; position: relative; overflow: hidden; transition: transform .2s, box-shadow .2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.08); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
        .stat-card.total::before { background: linear-gradient(90deg,#1b4d3e,#2d8260); }
        .stat-card.pending::before { background: linear-gradient(90deg,#f39c12,#f6d45a); }
        .stat-card.approved::before { background: linear-gradient(90deg,#27ae60,#4d9f79); }
        .stat-card.declined::before { background: linear-gradient(90deg,#e74c3c,#c0392b); }
        .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .stat-icon.total { background: #eef7f2; color: #1b4d3e; }
        .stat-icon.pending { background: #fff7e6; color: #d4a800; }
        .stat-icon.approved { background: #e8f5e9; color: #27ae60; }
        .stat-icon.declined { background: #fdecea; color: #e74c3c; }

        .field-label { display: block; font-size: 11px; font-weight: 600; color: #4b5563; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .03em; }
        .field-input { width: 100%; padding: 9px 12px; font-size: 13px; font-family: inherit; color: #111827; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; outline: none; transition: border-color .2s, box-shadow .2s; }
        .field-input:focus { border-color: #1b4d3e; box-shadow: 0 0 0 3px rgba(27,77,62,.15); }
        .btn-p { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid transparent; font-family: inherit; transition: all .2s; }
        .btn-p.primary { background: #1b4d3e; color: #fff; }
        .btn-p.primary:hover { background: #164032; }
        .btn-p.light { background: #fff; color: #374151; border-color: #d1d5db; }
        .btn-p.light:hover { background: #f3f4f6; }

        /* Report paper */
        .report-paper { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; }
        .report-letterhead { text-align: center; padding-bottom: 12px; margin-bottom: 14px; border-bottom: 3px double #1b4d3e; }
        .report-letterhead .univ { font-size: 18px; font-weight: 700; color: #1b4d3e; letter-spacing: 1px; }
        .report-letterhead .addr { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .report-letterhead .title { font-size: 15px; font-weight: 700; color: #1b4d3e; margin-top: 10px; text-transform: uppercase; letter-spacing: 1.5px; }
        .report-letterhead .subtitle { font-size: 11px; color: #6b7280; font-style: italic; }
        .meta-table { width: 100%; border-collapse: collapse; font-size: 11.5px; margin-bottom: 16px; }
        .meta-table td { border: 1px solid #e5e7eb; padding: 6px 10px; }
        .meta-table td:first-child { background: #f8fafc; font-weight: 700; color: #1b4d3e; width: 25%; }

        .rpt-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .rpt-table th { background: #1b4d3e; color: #fff; padding: 8px 9px; text-align: left; font-weight: 600; white-space: nowrap; border: 1px solid #164032; }
        .rpt-table td { padding: 6px 9px; border: 1px solid #e5e7eb; vertical-align: top; }
        .rpt-table tbody tr:hover { background: #f9fafb; }
        .rpt-table .group-header { background: #1b4d3e; color: #fff; font-weight: 700; letter-spacing: .5px; }

        .pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .pill.a { background: #e8f5e9; color: #1e8449; }
        .pill.p { background: #fff7e6; color: #b9770e; }
        .pill.d { background: #fdecea; color: #c0392b; }

        .report-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; transition: transform .2s, box-shadow .2s, border-color .2s; position: relative; display: flex; flex-direction: column; height: 100%; }
        .report-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(0,0,0,.08); border-color: #1b4d3e; }
        .report-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; }
        .report-card.submissions::before { background: linear-gradient(90deg,#1b4d3e,#2d8260); }
        .report-card.pending::before { background: linear-gradient(90deg,#f39c12,#f6d45a); }
        .report-card.approved::before { background: linear-gradient(90deg,#27ae60,#4d9f79); }
        .report-card.declined::before { background: linear-gradient(90deg,#e74c3c,#c0392b); }
        .report-card-head { padding: 18px 20px 14px; display: flex; align-items: flex-start; gap: 12px; }
        .report-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .report-icon.submissions { background: #eef7f2; color: #1b4d3e; }
        .report-icon.pending { background: #fff7e6; color: #d4a800; }
        .report-icon.approved { background: #e8f5e9; color: #27ae60; }
        .report-icon.declined { background: #fdecea; color: #e74c3c; }
        .report-title { font-size: 15px; font-weight: 700; color: #1b4d3e; line-height: 1.3; }
        .report-subtitle { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .report-count-badge { margin-left: auto; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .report-count-badge.submissions { background: #eef7f2; color: #1b4d3e; }
        .report-count-badge.pending { background: #fff7e6; color: #b9770e; }
        .report-count-badge.approved { background: #e8f5e9; color: #1e8449; }
        .report-count-badge.declined { background: #fdecea; color: #c0392b; }
        .report-card-body { padding: 0 20px 16px; flex: 1; display: flex; flex-direction: column; }
        .report-description { font-size: 12.5px; color: #4b5563; line-height: 1.55; margin-bottom: 14px; flex: 1; }
        .report-actions { display: flex; gap: 8px; padding-top: 14px; border-top: 1px solid #f0f1f3; }
        .btn-export { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: all .2s; }
        .btn-export-excel { background: #27ae60; color: #fff; }
        .btn-export-excel:hover { background: #219a52; box-shadow: 0 4px 12px rgba(39,174,96,.3); }
        .btn-export-word { background: #1b4d3e; color: #fff; }
        .btn-export-word:hover { background: #164032; box-shadow: 0 4px 12px rgba(27,77,62,.3); }
        .btn-export-pdf { background: #e74c3c; color: #fff; }
        .btn-export-pdf:hover { background: #c0392b; box-shadow: 0 4px 12px rgba(231,76,60,.3); }

        .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange { background: #1b4d3e; border-color: #1b4d3e; }
        .flatpickr-day.inRange { background: #d6ebdf; border-color: #d6ebdf; box-shadow: -5px 0 0 #d6ebdf, 5px 0 0 #d6ebdf; }
        .flatpickr-day.today { border-color: #f1c40f; }

        /* Dark mode overrides */
        html.dark .card, html.dark .stat-card, html.dark .report-card, html.dark .report-paper { background: #1a1a1a; border-color: #333; color: #fff; }
        html.dark .card-header { background: #0f172a; color: #f1c40f; border-color: #333; }
        html.dark .report-title { color: #f1c40f; }
        html.dark .report-subtitle, html.dark .report-description { color: #bbb; }
        html.dark .report-actions { border-top-color: #333; }
        html.dark .tab-btn { color: #aaa; }
        html.dark .tab-btn:hover { color: #fff; background: #1a1a1a; }
        html.dark .tab-btn.active { color: #f1c40f; border-bottom-color: #f1c40f; }
        html.dark .field-label { color: #bbb; }
        html.dark .field-input { background: #2c2c2c; border-color: #444; color: #fff; }
        html.dark .btn-p.light { background: #2c2c2c; color: #fff; border-color: #444; }
        html.dark .rpt-table td { border-color: #333; color: #eee; }
        html.dark .rpt-table tbody tr:hover { background: #2c2c2c; }
        html.dark .meta-table td { border-color: #333; color: #eee; }
        html.dark .meta-table td:first-child { background: #2c2c2c; color: #f1c40f; }
        html.dark .report-letterhead .univ, html.dark .report-letterhead .title { color: #f1c40f; }
        html.dark .text-slate-500, html.dark .text-slate-600 { color: #bbb !important; }
        html.dark .border-gray-200 { border-color: #333 !important; }
    </style>
</head>
<body>

<header class="app-header">
    <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Menu"><i class="fas fa-bars"></i></button>
    <img class="logo" src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU">
    <div class="header-title">
        <h1>CHMSU E-CLEARANCE</h1>
        <p><?php echo strtoupper(rpt_e($office)); ?> Portal - Reports</p>
    </div>
    <button class="hdr-dark-btn" onclick="toggleDarkMode()" title="Dark mode"><i class="fas fa-moon" id="darkModeIcon"></i></button>
    <button onclick="confirmLogout()" class="hdr-logout">Logout</button>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<?php
$chat_unread_count = 0;
$chat_res = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='" . $conn->real_escape_string($office) . "'");
if ($chat_res) {
    $chat_row = $chat_res->fetch_assoc();
    $chat_unread_count = intval($chat_row['total']);
}
?>
<aside class="app-sidebar">
    <div class="sb-head">
        <h3><?php echo rpt_e($office); ?> Portal</h3>
        <p>Clearance Management</p>
    </div>
    <nav>
        <a href="?officesection=dashboard" class="sidebar-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="?officesection=note" class="sidebar-link"><i class="fas fa-rss"></i> Feed Updates</a>
        <a href="?officesection=clearance" class="sidebar-link"><i class="fas fa-clipboard-check"></i> Clearance</a>
        <a href="?officesection=history" class="sidebar-link"><i class="fas fa-history"></i> Clearance History</a>
        <a href="?officesection=reports" class="sidebar-link active"><i class="fas fa-chart-bar"></i> Reports</a>
        <a href="?officesection=chat" class="sidebar-link chat-link">
            <i class="fas fa-comment"></i> Messages
            <?php if ($chat_unread_count > 0): ?>
                <span class="chat-badge"><?php echo $chat_unread_count; ?></span>
            <?php endif; ?>
        </a>
        <a href="#" onclick="confirmLogout(); return false;" class="sidebar-link" style="margin-top:15px;border-top:1px solid #2d6a4f;"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
    <div class="sb-storage">
        <i class="fas fa-database"></i> Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
        <div class="bar"><div style="width: <?php echo $storage_percent; ?>%"></div></div>
    </div>
</aside>

<main class="app-main" id="appMain">

    <!-- Title bar -->
    <div class="card px-4 py-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between mb-4">
        <div>
            <nav class="text-xs text-slate-500 mb-0.5">
                <a href="?officesection=dashboard" class="hover:text-brand-600"><?php echo rpt_e($office); ?></a>
                <span class="mx-1">/</span><span class="text-slate-600">Reports &amp; Analytics</span>
            </nav>
            <h2 class="text-lg font-semibold text-brand-600">Clearance Reports</h2>
        </div>
        <span class="text-sm text-slate-500"><i class="far fa-calendar-alt mr-1"></i><?php echo date('F d, Y'); ?></span>
    </div>

    <!-- Tabs: VIEW FIRST (like Registrar), then Analytics, then Export -->
    <div class="flex items-center border-b border-gray-200 mb-5 overflow-x-auto">
        <a href="?officesection=reports&tab=reports<?php echo $filterQS ? '&' . $filterQS : ''; ?>" class="tab-btn <?php echo $report_tab === 'reports' ? 'active' : ''; ?>">
            <i class="fas fa-file-alt"></i> Reports View
        </a>
        <a href="?officesection=reports&tab=analytics<?php echo $filterQS ? '&' . $filterQS : ''; ?>" class="tab-btn <?php echo $report_tab === 'analytics' ? 'active' : ''; ?>">
            <i class="fas fa-chart-pie"></i> Analytics
        </a>
        <a href="?officesection=reports&tab=export<?php echo $filterQS ? '&' . $filterQS : ''; ?>" class="tab-btn <?php echo $report_tab === 'export' ? 'active' : ''; ?>">
            <i class="fas fa-file-export"></i> Export Reports
        </a>
    </div>

    <!-- Filters (shared) -->
    <div class="card mb-5">
        <div class="card-header flex items-center justify-between cursor-pointer" onclick="toggleFilters()">
            <span><i class="fas fa-filter"></i> Filters
                <?php if ($hasFilters): ?><span class="ml-2 pill a">Active</span><?php endif; ?>
            </span>
            <i class="fas fa-chevron-down text-xs" id="filterChevron"></i>
        </div>
        <div class="card-body <?php echo $hasFilters ? '' : 'hidden'; ?>" id="filterBody">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                <input type="hidden" name="officesection" value="reports">
                <input type="hidden" name="tab" value="<?php echo rpt_e($report_tab); ?>">
                <div>
                    <label class="field-label">Course</label>
                    <select name="course" class="field-input">
                        <option value="">All Courses</option>
                        <?php foreach ($courseList as $cc): ?>
                            <option value="<?php echo rpt_e($cc); ?>" <?php echo strcasecmp($f['course'], $cc) === 0 ? 'selected' : ''; ?>><?php echo rpt_e($cc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label">Year Level</label>
                    <select name="year" class="field-input">
                        <option value="">All Years</option>
                        <?php for ($y = 1; $y <= 4; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $f['year'] === (string)$y ? 'selected' : ''; ?>><?php echo rpt_ordinal($y); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label">Section</label>
                    <select name="section" class="field-input">
                        <option value="">All Sections</option>
                        <?php foreach ($sectionList as $sn): ?>
                            <option value="<?php echo rpt_e($sn); ?>" <?php echo strcasecmp($f['section'], $sn) === 0 ? 'selected' : ''; ?>><?php echo rpt_e($sn); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label">Date Range (Last Updated)</label>
                    <input type="text" name="daterange" id="dateRange" class="field-input" placeholder="Select dates" value="<?php echo rpt_e($filterParams['daterange'] ?? ''); ?>" readonly>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn-p primary flex-1"><i class="fas fa-search"></i> Apply</button>
                    <a href="?officesection=reports&tab=<?php echo rpt_e($report_tab); ?>" class="btn-p light"><i class="fas fa-times"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================= -->
    <!-- TAB 1: REPORTS VIEW (shown first) -->
    <!-- ================================================= -->
    <?php if ($report_tab === 'reports'): ?>
        <!-- Status filter buttons + exports -->
        <div class="flex flex-wrap items-center gap-2 mb-3 justify-between">
            <div class="flex flex-wrap gap-1">
                <a href="?officesection=reports&tab=reports&view_status=all<?php echo $filterQS ? '&' . $filterQS : ''; ?>" class="btn-p <?php echo $view_status === 'all' ? 'primary' : 'light'; ?>">All (<?php echo $total_submissions; ?>)</a>
                <a href="?officesection=reports&tab=reports&view_status=pending<?php echo $filterQS ? '&' . $filterQS : ''; ?>" class="btn-p <?php echo $view_status === 'pending' ? 'primary' : 'light'; ?>">Pending (<?php echo $pending_count; ?>)</a>
                <a href="?officesection=reports&tab=reports&view_status=approved<?php echo $filterQS ? '&' . $filterQS : ''; ?>" class="btn-p <?php echo $view_status === 'approved' ? 'primary' : 'light'; ?>">Approved (<?php echo $approved_count; ?>)</a>
                <a href="?officesection=reports&tab=reports&view_status=declined<?php echo $filterQS ? '&' . $filterQS : ''; ?>" class="btn-p <?php echo $view_status === 'declined' ? 'primary' : 'light'; ?>">Declined (<?php echo $declined_count; ?>)</a>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="modules/office/reports_export.php?export_report=excel&type=<?php echo $view_status === 'all' ? 'submissions' : $view_status; ?>&<?php echo $filterQS; ?>" class="btn-p" style="background:#27ae60;color:#fff;"><i class="fas fa-file-excel"></i> Excel</a>
                <a href="modules/office/reports_export.php?export_report=word&type=<?php echo $view_status === 'all' ? 'submissions' : $view_status; ?>&<?php echo $filterQS; ?>" class="btn-p primary"><i class="fas fa-file-word"></i> Word</a>
                <a href="modules/office/reports_export.php?export_report=pdf&type=<?php echo $view_status === 'all' ? 'submissions' : $view_status; ?>&<?php echo $filterQS; ?>" class="btn-p" style="background:#e74c3c;color:#fff;"><i class="fas fa-file-pdf"></i> PDF</a>
            </div>
        </div>

        <!-- REPORT PAPER (portrait) -->
        <div class="report-paper">
            <div class="report-letterhead">
                <div class="univ">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>
                <div class="addr">Talisay City, Negros Occidental, Philippines</div>
                <div class="title">Clearance Completion Report</div>
                <div class="subtitle"><?php echo rpt_e($office); ?> — Student Clearance Monitoring</div>
            </div>

            <table class="meta-table">
                <tr><td>Report Title</td><td><?php echo rpt_e(($view_status === 'all' ? 'All Students' : ucfirst($view_status)) . ' Clearance Report'); ?></td></tr>
                <tr><td>Generated On</td><td><?php echo date('F d, Y h:i A'); ?></td></tr>
                <tr><td>Generated By</td><td><?php echo rpt_e($office); ?></td></tr>
                <tr><td>Filters Applied</td><td><?php echo rpt_e($filterSummaryText); ?></td></tr>
            </table>

            <!-- Quick figures -->
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2 mb-5">
                <div class="border border-gray-200 rounded px-3 py-2 text-center bg-gray-50"><div class="text-[10px] text-slate-500">Total Students</div><div class="text-lg font-bold text-brand-600"><?php echo $total_submissions; ?></div></div>
                <div class="border border-gray-200 rounded px-3 py-2 text-center bg-gray-50"><div class="text-[10px] text-slate-500">Approved</div><div class="text-lg font-bold text-green-700"><?php echo $approved_count; ?></div></div>
                <div class="border border-gray-200 rounded px-3 py-2 text-center bg-gray-50"><div class="text-[10px] text-slate-500">Pending</div><div class="text-lg font-bold text-amber-600"><?php echo $pending_count; ?></div></div>
                <div class="border border-gray-200 rounded px-3 py-2 text-center bg-gray-50"><div class="text-[10px] text-slate-500">Declined</div><div class="text-lg font-bold text-red-600"><?php echo $declined_count; ?></div></div>
                <div class="border border-gray-200 rounded px-3 py-2 text-center bg-gray-50"><div class="text-[10px] text-slate-500">Approval Rate</div><div class="text-lg font-bold text-brand-600"><?php echo $approval_rate; ?>%</div></div>
                <div class="border border-gray-200 rounded px-3 py-2 text-center bg-gray-50"><div class="text-[10px] text-slate-500">Shown</div><div class="text-lg font-bold text-brand-600"><?php echo $viewCount; ?></div></div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th style="width:4%;text-align:center;">No.</th>
                            <th style="width:14%;">School ID</th>
                            <th style="width:28%;">Student Name</th>
                            <th style="width:7%;">Year</th>
                            <th style="width:8%;">Section</th>
                            <th style="width:9%;">Course</th>
                            <th style="width:9%;text-align:center;">Status</th>
                            <th style="width:14%;">Remarks</th>
                            <th style="width:7%;text-align:center;">Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($viewRows)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:18px;color:#9ca3af;">No records match the selected filters.</td></tr>
                    <?php else: ?>
                        <?php
                        $num = 1; $lastGroup = '';
                        foreach ($viewRows as $row):
                            $groupKey = $row['course'] . '|' . $row['yr'] . '|' . $row['sec'];
                            if ($groupKey !== $lastGroup):
                                $num = 1;
                                $lastGroup = $groupKey;
                                $groupTitle = strtoupper($row['course']) . ' — ' . rpt_ordinal($row['yr']) . ' — SECTION ' . strtoupper($row['sec']);
                        ?>
                            <tr><td colspan="9" class="group-header"><?php echo rpt_e($groupTitle); ?></td></tr>
                        <?php endif; ?>
                            <tr>
                                <td style="text-align:center;"><?php echo $num++; ?></td>
                                <td style="font-family:monospace;"><?php echo rpt_e($row['sid']); ?></td>
                                <td><strong><?php echo rpt_e($row['name']); ?></strong></td>
                                <td><?php echo rpt_e(rpt_ordinal($row['yr'])); ?></td>
                                <td><?php echo rpt_e($row['sec']); ?></td>
                                <td><?php echo rpt_e($row['course']); ?></td>
                                <td style="text-align:center;">
                                    <?php
                                    $pillCls = $row['label'] === 'Approved' ? 'a' : ($row['label'] === 'Declined' ? 'd' : 'p');
                                    ?>
                                    <span class="pill <?php echo $pillCls; ?>"><?php echo rpt_e($row['label']); ?></span>
                                </td>
                                <td><?php echo rpt_e($row['note'] ?? ''); ?></td>
                                <td style="text-align:center;"><?php echo $row['label'] === 'Approved' ? '1 / 1' : '0 / 1'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-6 pt-3 border-t border-gray-200 text-center text-[10px] text-slate-500 leading-relaxed">
                This is an official computer-generated report from the CHMSU E-Clearance System.<br>
                No signature is required. For verification, please contact <?php echo rpt_e($office); ?>.
            </div>
        </div>
    <?php endif; ?>

    <!-- ================================================= -->
    <!-- TAB 2: ANALYTICS -->
    <!-- ================================================= -->
    <?php if ($report_tab === 'analytics'): ?>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">
            <div class="stat-card total">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-slate-500 font-semibold">Total Students</div>
                        <div class="text-2xl sm:text-3xl font-bold text-brand-600 mt-1"><?php echo $total_submissions; ?></div>
                    </div>
                    <div class="stat-icon total"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="stat-card pending">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-slate-500 font-semibold">Pending</div>
                        <div class="text-2xl sm:text-3xl font-bold text-amber-600 mt-1"><?php echo $pending_count; ?></div>
                    </div>
                    <div class="stat-icon pending"><i class="fas fa-clock"></i></div>
                </div>
            </div>
            <div class="stat-card approved">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-slate-500 font-semibold">Approved</div>
                        <div class="text-2xl sm:text-3xl font-bold text-green-600 mt-1"><?php echo $approved_count; ?></div>
                    </div>
                    <div class="stat-icon approved"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="stat-card declined">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-slate-500 font-semibold">Declined</div>
                        <div class="text-2xl sm:text-3xl font-bold text-red-600 mt-1"><?php echo $declined_count; ?></div>
                    </div>
                    <div class="stat-icon declined"><i class="fas fa-times-circle"></i></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-chart-pie"></i> Status Distribution</div>
                <div class="card-body"><div class="relative h-64"><canvas id="reportsChart"></canvas></div></div>
            </div>
            <div class="card lg:col-span-2">
                <div class="card-header"><i class="fas fa-chart-column"></i> Status by Course</div>
                <div class="card-body"><div class="relative h-64"><canvas id="courseChart"></canvas></div></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-percentage"></i> Approval Rate</div>
                <div class="card-body flex flex-col items-center justify-center py-6">
                    <div class="relative w-40 h-40 mb-4">
                        <svg class="w-full h-full transform -rotate-90" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="52" fill="none" stroke="#e5e7eb" stroke-width="12"/>
                            <circle cx="60" cy="60" r="52" fill="none" stroke="#27ae60" stroke-width="12"
                                    stroke-linecap="round" stroke-dasharray="<?php echo 2 * M_PI * 52; ?>"
                                    stroke-dashoffset="<?php echo 2 * M_PI * 52 * (1 - $approval_rate / 100); ?>"/>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <div class="text-3xl font-bold text-brand-600"><?php echo $approval_rate; ?>%</div>
                            <div class="text-xs text-slate-500 font-medium mt-1">Approved</div>
                        </div>
                    </div>
                    <div class="text-sm text-slate-600 text-center">
                        <strong class="text-green-600"><?php echo $approved_count; ?></strong> approved out of <strong class="text-brand-600"><?php echo $total_submissions; ?></strong> students
                    </div>
                </div>
            </div>

            <div class="card lg:col-span-2">
                <div class="card-header"><i class="fas fa-table"></i> Summary by Course, Year &amp; Section</div>
                <div class="overflow-x-auto" style="max-height: 360px; overflow-y: auto;">
                    <table class="rpt-table">
                        <thead><tr><th>Course</th><th>Year</th><th>Section</th><th>Total</th><th>Approved</th><th>Pending</th><th>Declined</th><th>Rate</th></tr></thead>
                        <tbody>
                        <?php if ($byGroup): foreach ($byGroup as $g):
                            $rate = $g['total'] > 0 ? round(($g['Approved'] / $g['total']) * 100) : 0; ?>
                            <tr>
                                <td><strong><?php echo rpt_e($g['course']); ?></strong></td>
                                <td><?php echo rpt_e(rpt_ordinal($g['yr'])); ?></td>
                                <td><?php echo rpt_e($g['sec']); ?></td>
                                <td><?php echo $g['total']; ?></td>
                                <td><span class="pill a"><?php echo $g['Approved']; ?></span></td>
                                <td><span class="pill p"><?php echo $g['Pending']; ?></span></td>
                                <td><span class="pill d"><?php echo $g['Declined']; ?></span></td>
                                <td><?php echo $rate; ?>%</td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="8" style="text-align:center;padding:28px;color:#9ca3af;">No records match the selected filters.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ================================================= -->
    <!-- TAB 3: EXPORT -->
    <!-- ================================================= -->
    <?php if ($report_tab === 'export'):
        $exp = $filterQS ? '&' . $filterQS : ''; ?>
        <div class="mb-5">
            <h3 class="text-lg font-semibold text-brand-600 mb-1"><i class="fas fa-file-export"></i> Download Reports</h3>
            <p class="text-sm text-slate-500">Official reports for <strong><?php echo rpt_e($office); ?></strong>. Each file has a separate table for every Course, Year and Section, sorted by last name. Exports are <strong>portrait A4</strong>.
            <?php if ($hasFilters): ?> <span class="pill a">Filters applied</span><?php endif; ?></p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
            <div class="report-card submissions">
                <div class="report-card-head">
                    <div class="report-icon submissions"><i class="fas fa-file-alt"></i></div>
                    <div class="flex-1 min-w-0"><div class="report-title">All Students</div><div class="report-subtitle">Complete clearance list</div></div>
                    <span class="report-count-badge submissions"><?php echo $total_submissions; ?></span>
                </div>
                <div class="report-card-body">
                    <div class="report-description">Every student with this office's clearance status, grouped in separate tables by course, year level and section.</div>
                    <div class="report-actions">
                        <a href="modules/office/reports_export.php?export_report=excel&type=submissions<?php echo $exp; ?>" class="btn-export btn-export-excel"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="modules/office/reports_export.php?export_report=word&type=submissions<?php echo $exp; ?>" class="btn-export btn-export-word"><i class="fas fa-file-word"></i> Word</a>
                        <a href="modules/office/reports_export.php?export_report=pdf&type=submissions<?php echo $exp; ?>" class="btn-export btn-export-pdf"><i class="fas fa-file-pdf"></i> PDF</a>
                    </div>
                </div>
            </div>

            <div class="report-card pending">
                <div class="report-card-head">
                    <div class="report-icon pending"><i class="fas fa-clock"></i></div>
                    <div class="flex-1 min-w-0"><div class="report-title">Pending</div><div class="report-subtitle">Awaiting office action</div></div>
                    <span class="report-count-badge pending"><?php echo $pending_count; ?></span>
                </div>
                <div class="report-card-body">
                    <div class="report-description">Students the office has not yet cleared or declined, organized per course, year and section.</div>
                    <div class="report-actions">
                        <a href="modules/office/reports_export.php?export_report=excel&type=pending<?php echo $exp; ?>" class="btn-export btn-export-excel"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="modules/office/reports_export.php?export_report=word&type=pending<?php echo $exp; ?>" class="btn-export btn-export-word"><i class="fas fa-file-word"></i> Word</a>
                        <a href="modules/office/reports_export.php?export_report=pdf&type=pending<?php echo $exp; ?>" class="btn-export btn-export-pdf"><i class="fas fa-file-pdf"></i> PDF</a>
                    </div>
                </div>
            </div>

            <div class="report-card approved">
                <div class="report-card-head">
                    <div class="report-icon approved"><i class="fas fa-check-circle"></i></div>
                    <div class="flex-1 min-w-0"><div class="report-title">Approved</div><div class="report-subtitle">Cleared students</div></div>
                    <span class="report-count-badge approved"><?php echo $approved_count; ?></span>
                </div>
                <div class="report-card-body">
                    <div class="report-description">Students cleared by this office, with remarks and the date and person who updated the record.</div>
                    <div class="report-actions">
                        <a href="modules/office/reports_export.php?export_report=excel&type=approved<?php echo $exp; ?>" class="btn-export btn-export-excel"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="modules/office/reports_export.php?export_report=word&type=approved<?php echo $exp; ?>" class="btn-export btn-export-word"><i class="fas fa-file-word"></i> Word</a>
                        <a href="modules/office/reports_export.php?export_report=pdf&type=approved<?php echo $exp; ?>" class="btn-export btn-export-pdf"><i class="fas fa-file-pdf"></i> PDF</a>
                    </div>
                </div>
            </div>

            <div class="report-card declined">
                <div class="report-card-head">
                    <div class="report-icon declined"><i class="fas fa-times-circle"></i></div>
                    <div class="flex-1 min-w-0"><div class="report-title">Declined</div><div class="report-subtitle">Not cleared</div></div>
                    <span class="report-count-badge declined"><?php echo $declined_count; ?></span>
                </div>
                <div class="report-card-body">
                    <div class="report-description">Students not cleared by this office, including the recorded reason, grouped per course, year and section.</div>
                    <div class="report-actions">
                        <a href="modules/office/reports_export.php?export_report=excel&type=declined<?php echo $exp; ?>" class="btn-export btn-export-excel"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="modules/office/reports_export.php?export_report=word&type=declined<?php echo $exp; ?>" class="btn-export btn-export-word"><i class="fas fa-file-word"></i> Word</a>
                        <a href="modules/office/reports_export.php?export_report=pdf&type=declined<?php echo $exp; ?>" class="btn-export btn-export-pdf"><i class="fas fa-file-pdf"></i> PDF</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<script>
// Show content only after Tailwind has styled the page (header + sidebar are plain CSS, so they never blink)
(function () {
    var main = document.getElementById('appMain');
    var show = function () { if (main) main.classList.add('ready'); };
    window.addEventListener('load', function () { requestAnimationFrame(function () { requestAnimationFrame(show); }); });
    setTimeout(show, 1200);
})();

function toggleSidebar() {
    document.querySelector('.app-sidebar').classList.toggle('open');
    document.getElementById('sidebarBackdrop').classList.toggle('show');
}
function toggleFilters() {
    var b = document.getElementById('filterBody');
    var c = document.getElementById('filterChevron');
    b.classList.toggle('hidden');
    c.style.transform = b.classList.contains('hidden') ? '' : 'rotate(180deg)';
}
function toggleDarkMode() {
    document.documentElement.classList.toggle('dark');
    var isDark = document.documentElement.classList.contains('dark');
    localStorage.setItem('darkMode', isDark);
    document.body.classList.toggle('dark-mode', isDark);
    var icon = document.getElementById('darkModeIcon');
    if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    if (window.buildCharts) window.buildCharts();
}
if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
    document.addEventListener('DOMContentLoaded', function () {
        var icon = document.getElementById('darkModeIcon');
        if (icon) icon.className = 'fas fa-sun';
    });
}
function confirmLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = '?logout=1';
}

document.addEventListener('DOMContentLoaded', function () {
    var fb = document.getElementById('filterBody');
    if (fb && !fb.classList.contains('hidden')) {
        document.getElementById('filterChevron').style.transform = 'rotate(180deg)';
    }

    // Flatpickr date range
    if (typeof flatpickr !== 'undefined' && document.getElementById('dateRange')) {
        flatpickr('#dateRange', { mode: 'range', dateFormat: 'Y-m-d', allowInput: false, maxDate: 'today' });
    }

    // ---------- Charts (Analytics tab) ----------
    window.buildCharts = function () {
        if (typeof Chart === 'undefined') return;

        var isDark = document.documentElement.classList.contains('dark');
        var fontCfg = { family: 'Times New Roman', size: 12 };
        var textCol = isDark ? '#cfcfcf' : '#444';
        var gridCol = isDark ? '#333' : '#e5e7eb';
        var border  = isDark ? '#1a1a1a' : '#ffffff';

        // destroy previous instances if rebuilding
        ['reportsChart','courseChart'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                var existing = Chart.getChart(el);
                if (existing) existing.destroy();
            }
        });

        var c1 = document.getElementById('reportsChart');
        if (c1) {
            new Chart(c1, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Approved', 'Declined'],
                    datasets: [{
                        data: [<?php echo (int)$pending_count; ?>, <?php echo (int)$approved_count; ?>, <?php echo (int)$declined_count; ?>],
                        backgroundColor: ['#f39c12', '#27ae60', '#e74c3c'],
                        borderColor: border, borderWidth: 3, hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '65%',
                    plugins: { legend: { position: 'bottom', labels: { font: fontCfg, padding: 16, usePointStyle: true, color: textCol } } }
                }
            });
        }

        var c2 = document.getElementById('courseChart');
        if (c2) {
            var labels = <?php echo json_encode(array_keys($byCourse)); ?>;
            var vals = <?php echo json_encode(array_values($byCourse)); ?>;
            new Chart(c2, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Approved', data: vals.map(function (v) { return v.Approved; }), backgroundColor: '#27ae60', borderRadius: 4 },
                        { label: 'Pending',  data: vals.map(function (v) { return v.Pending; }),  backgroundColor: '#f39c12', borderRadius: 4 },
                        { label: 'Declined', data: vals.map(function (v) { return v.Declined; }), backgroundColor: '#e74c3c', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { font: fontCfg, usePointStyle: true, color: textCol } } },
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { font: fontCfg, color: textCol } },
                        y: { stacked: true, beginAtZero: true, ticks: { precision: 0, font: fontCfg, color: textCol }, grid: { color: gridCol } }
                    }
                }
            });
        }
    };

    // Build only if the charts exist (Analytics tab is active)
    if (document.getElementById('reportsChart') || document.getElementById('courseChart')) {
        window.buildCharts();
    }
});
</script>
</body>
</html>