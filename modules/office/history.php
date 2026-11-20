<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/clearance_history.php';
require_once __DIR__ . '/../../includes/semester_archive.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['office'])) {
    header("Location: ../index.php");
    exit;
}

$office = $_SESSION['office'];
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

$currentSem = getCurrentSemester($conn);
$semesters = getHistorySemesters($conn);

// Most recently archived semester (for Restore button)
$mostRecentArchived = sa_getMostRecentArchived($conn);
$mostRecentArchivedId = $mostRecentArchived ? intval($mostRecentArchived['id']) : 0;

// Selected semester (defaults to current)
$selected_sem_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : ($currentSem['id'] ?? 0);
$selectedSem = getSemesterById($conn, $selected_sem_id);

// ============================================
// VIEW MODE (default = archived, view=feed = full feed, view=list = full student list)
// ============================================
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'archived';

// Helper: fetch feed posts for a semester
if (!function_exists('getOfficeFeedPostsForSemester')) {
    function getOfficeFeedPostsForSemester($conn, $semesterId, $officeName) {
        $semesterId = intval($semesterId);
        $office_esc = $conn->real_escape_string($officeName);
        if ($semesterId <= 0) return [];

        static $hasSemCol = null;
        if ($hasSemCol === null) {
            $hasSemCol = false;
            $chk = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
            if ($chk && $chk->num_rows > 0) $hasSemCol = true;
        }

        $posts = [];
        if ($hasSemCol) {
            $q = $conn->query("SELECT * FROM chmsu_feed
                               WHERE semester_id = $semesterId
                               AND office_name = '$office_esc'
                               ORDER BY created_at DESC");
        } else {
            $q = $conn->query("SELECT * FROM chmsu_feed
                               WHERE office_name = '$office_esc'
                               ORDER BY created_at DESC");
        }

        if ($q && $q->num_rows > 0) {
            while ($r = $q->fetch_assoc()) $posts[] = $r;
        }
        return $posts;
    }
}

// ============================================
// SHOW ALL SEMESTERS (Active + Archived)
// ============================================
$archivedSemesters = $semesters; 

// Summary per semester
$semesterSummaries = [];
foreach ($archivedSemesters as $sem) {
    $sid = $sem['id'];
    $sid_esc = intval($sid);
    $office_esc = $conn->real_escape_string($office);

    $approved = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$office_esc' AND semester_id=$sid_esc AND status='clear'")->fetch_assoc()['c']);
    $declined = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$office_esc' AND semester_id=$sid_esc AND status='unclear'")->fetch_assoc()['c']);
    $pending  = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_students_master s LEFT JOIN chmsu_clearance_status cs ON s.chmsu_student_id=cs.student_id AND cs.office_name='$office_esc' AND cs.semester_id=$sid_esc WHERE s.is_archived=0 AND (cs.status IS NULL OR cs.status='pending')")->fetch_assoc()['c']);

    $sem['approved'] = $approved;
    $sem['declined'] = $declined;
    $sem['pending']  = $pending;
    $sem['feed_count'] = count(getOfficeFeedPostsForSemester($conn, $sid, $office));
    $semesterSummaries[] = $sem;
}

// Group semesters by school year
$groupedBySY = [];
foreach ($semesterSummaries as $sem) {
    $syKey = !empty($sem['school_year']) ? $sem['school_year'] : 'No School Year';
    if (!isset($groupedBySY[$syKey])) $groupedBySY[$syKey] = [];
    $groupedBySY[$syKey][] = $sem;
}
uksort($groupedBySY, function($a, $b) {
    if ($a === 'No School Year') return 1;
    if ($b === 'No School Year') return -1;
    return strcmp($b, $a);
});
foreach ($groupedBySY as $syKey => $list) {
    usort($groupedBySY[$syKey], function($a, $b) {
        $ta = !empty($a['archived_at']) ? strtotime($a['archived_at']) : 0;
        $tb = !empty($b['archived_at']) ? strtotime($b['archived_at']) : 0;
        if ($ta !== $tb) return $tb - $ta;
        return intval($b['id']) - intval($a['id']);
    });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Clearance History</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; background: #f5f5f5; font-size: 14px; }

        .header { background: #1b4d3e; color: white; padding: 15px 25px; display: flex; align-items: center; gap: 20px; position: sticky; top: 0; z-index: 1000; }
        .header-logo img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 20px; font-weight: normal; }
        .header-title p { font-size: 11px; opacity: 0.8; margin-top: 3px; }
        .dark-mode-toggle { background: transparent; border: 1px solid rgba(255,255,255,0.3); color: white; padding: 6px 12px; cursor: pointer; margin-left: auto; }
        .logout-btn { background: #e74c3c; color: white; padding: 6px 15px; border: none; cursor: pointer; }

        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }

        .office-sidebar { width: 260px; background: #1b4d3e; color: white; position: fixed; height: calc(100vh - 73px); overflow-y: auto; }
        .office-sidebar::-webkit-scrollbar { width: 5px; }
        .office-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .office-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .office-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .office-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .office-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-menu a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 13px; transition: all 0.3s; }
        .office-sidebar .sidebar-menu a:hover, .office-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }

        .main-content { flex: 1; margin-left: 260px; padding: 20px; background: #f5f5f5; min-height: calc(100vh - 73px); }
        .dashboard-header-bar { background: white; padding: 15px 20px; border: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }
        .dashboard-header-bar .back-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: #7f8c8d; color: white; padding: 6px 14px;
            border: none; border-radius: 4px; cursor: pointer;
            font-size: 12px; text-decoration: none;
            font-family: 'Times New Roman', Times, serif;
        }
        .dashboard-header-bar .back-btn:hover { background: #6c7a7d; }

        /* ============================================ */
        /* SEARCH BAR                                   */
        /* ============================================ */
        .search-bar {
            background: white; border: 1px solid #ddd;
            padding: 14px 18px; margin-bottom: 20px;
            display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
        }
        .search-bar input[type="text"] {
            flex: 1; min-width: 220px;
            padding: 8px 12px; border: 1px solid #ddd;
            font-size: 13px; font-family: 'Times New Roman', Times, serif;
        }
        .search-bar input[type="text"]:focus { outline: none; border-color: #1b4d3e; }
        .search-bar .result-count { font-size: 12px; color: #666; font-style: italic; }

        /* ============================================ */
        /* SY GROUP HEADER                              */
        /* ============================================ */
        .sy-group { margin-bottom: 25px; }
        .sy-group-title {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 16px;
            background: #1b4d3e; color: white;
            font-size: 14px; font-weight: bold;
            border-radius: 6px 6px 0 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .sy-group-title i { color: #f1c40f; }
        .sy-group-title .sy-count {
            background: rgba(255,255,255,0.25);
            padding: 1px 8px; border-radius: 10px;
            font-size: 10px; font-weight: 700;
        }

        /* ============================================ */
        /* SEMESTER CARD                                */
        /* ============================================ */
        .semester-card {
            background: white; border: 1px solid #ddd;
            border-left: 5px solid #1967d2;
            margin-bottom: 12px; overflow: hidden;
            transition: box-shadow 0.2s;
        }
        .semester-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .semester-card:last-child { margin-bottom: 0; }

        .semester-card-header { background: #f8f9fa; padding: 14px 18px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .semester-title { font-size: 15px; font-weight: 600; color: #1b4d3e; }
        .semester-title small { display: block; font-size: 11px; font-weight: normal; color: #888; margin-top: 3px; }
        .semester-badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-archived { background: #e8f0fe; color: #1967d2; }
        .badge-active { background: #d4efdf; color: #1e8449; }

        .semester-card-body { padding: 16px 18px; }
        .stat-row { display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 14px; }
        .stat-item { display: flex; flex-direction: column; }
        .stat-num { font-size: 22px; font-weight: 700; color: #1b4d3e; }
        .stat-num.approved { color: #27ae60; }
        .stat-num.declined { color: #e74c3c; }
        .stat-num.pending { color: #f39c12; }
        .stat-lbl { font-size: 11px; color: #777; text-transform: uppercase; letter-spacing: 0.4px; }

        /* ============================================ */
        /* ACTION BUTTONS ROW                           */
        /* ============================================ */
        .card-actions-row {
            display: flex; gap: 8px; flex-wrap: wrap;
            padding-top: 14px; border-top: 1px dashed #ddd; margin-top: 4px;
        }

        .btn {
            padding: 8px 16px; border: none; border-radius: 4px;
            cursor: pointer; font-size: 12px; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.2s;
        }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-primary:hover { background: #2d6a4f; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        .btn-feed { background: #3498db; color: white; }
        .btn-feed:hover { background: #2980b9; }
        .btn-sm { padding: 6px 12px; font-size: 11px; }

        .btn-feed .count-badge {
            background: rgba(255,255,255,0.3);
            padding: 1px 7px; border-radius: 10px;
            font-size: 10px; font-weight: 700;
        }

        /* ============================================ */
        /* FULL FEED SCREEN (view=feed)                 */
        /* ============================================ */
        .feed-screen-header {
            background: white; padding: 18px 22px;
            border: 1px solid #ddd;
            border-left: 5px solid #3498db;
            margin-bottom: 20px;
        }
        .feed-screen-title {
            font-size: 18px; font-weight: 700; color: #1b4d3e;
            display: flex; align-items: center; gap: 10px;
        }
        .feed-screen-title i { color: #3498db; }
        .feed-screen-subtitle { font-size: 12px; color: #777; margin-top: 6px; }
        .feed-screen-stats {
            display: flex; gap: 20px; flex-wrap: wrap;
            margin-top: 14px; padding-top: 14px;
            border-top: 1px dashed #ddd;
        }
        .feed-screen-stats .item { display: flex; flex-direction: column; gap: 2px; }
        .feed-screen-stats .num { font-size: 18px; font-weight: 700; color: #1b4d3e; }
        .feed-screen-stats .lbl { font-size: 10px; color: #777; text-transform: uppercase; letter-spacing: 0.4px; }

        .feed-post-box {
            background: #fafbfc; border: 1px solid #e5e7ea;
            border-left: 4px solid #f39c12; border-radius: 6px;
            padding: 14px 18px; margin-bottom: 14px;
            font-size: 13px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .feed-post-box:last-child { margin-bottom: 0; }
        .feed-post-box.priority-urgent { border-left-color: #8e44ad; background: #faf5fc; }
        .feed-post-box.priority-high   { border-left-color: #e74c3c; background: #fdf3f2; }
        .feed-post-box.priority-medium { border-left-color: #f39c12; }
        .feed-post-box.priority-low    { border-left-color: #3498db; }

        .feed-post-box-header {
            display: flex; justify-content: space-between;
            align-items: flex-start; gap: 10px; flex-wrap: wrap;
            margin-bottom: 6px;
        }
        .feed-post-box-title { font-size: 15px; font-weight: 700; color: #1b4d3e; line-height: 1.3; }
        .feed-post-box-meta {
            font-size: 11px; color: #888;
            display: inline-flex; align-items: center;
            gap: 12px; flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .feed-post-box-meta strong { color: #1b4d3e; font-weight: 600; }

        .feed-post-box-content {
            font-size: 13px; color: #444; line-height: 1.65;
            white-space: pre-wrap; word-wrap: break-word;
            padding: 10px 0 0 0; border-top: 1px dashed #eee; margin-top: 6px;
        }

        .priority-badge {
            font-size: 9px; padding: 2px 8px; border-radius: 8px;
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.4px; flex-shrink: 0;
        }
        .priority-badge.low      { background: #d6eaf8; color: #1b4d3e; }
        .priority-badge.medium   { background: #fdebd0; color: #935116; }
        .priority-badge.high     { background: #fadbd8; color: #922b21; }
        .priority-badge.urgent   { background: #e8daef; color: #6c3483; }

        .no-feed-msg-box {
            text-align: center; color: #999; font-size: 13px;
            padding: 40px 20px; font-style: italic;
            background: white; border: 1px dashed #ddd; border-radius: 6px;
        }
        .no-feed-msg-box i { font-size: 36px; display: block; margin-bottom: 10px; color: #ccc; }

        /* ============================================ */
        /* FULL LIST SCREEN (view=list) — SIMPLE        */
        /* ============================================ */
        .list-screen-header {
            background: white; padding: 18px 22px;
            border: 1px solid #ddd;
            border-left: 5px solid #1b4d3e;
            margin-bottom: 20px;
        }

        /* Simple grouped table */
        .roster-simple {
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-bottom: 18px;
            overflow: hidden;
        }
        .roster-simple-title {
            background: #1b4d3e;
            color: white;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.4px;
        }
        .roster-simple-title i { color: #f1c40f; margin-right: 8px; }
        .roster-simple-title .group-count {
            float: right;
            background: rgba(255,255,255,0.2);
            padding: 1px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
        }

        .roster-simple-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .roster-simple-table thead th {
            background: #f8f9fa;
            padding: 10px 14px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555;
            font-weight: 700;
            border-bottom: 1px solid #ddd;
        }
        .roster-simple-table tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid #f0f0f0;
            color: #333;
        }
        .roster-simple-table tbody tr:last-child td { border-bottom: none; }
        .roster-simple-table tbody tr:hover { background: #fafbfc; }

        .roster-simple-table .id-cell code {
            background: #f0f4f3;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            color: #1b4d3e;
        }
        .roster-simple-table .name-cell { color: #1b4d3e; font-weight: 600; }

        .status-badge-tbl {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-badge-tbl.approved { background: #d4efdf; color: #1e8449; }
        .status-badge-tbl.declined { background: #fdecea; color: #c0392b; }
        .status-badge-tbl.pending  { background: #f0f0f0; color: #666; }

        .empty-state { text-align: center; padding: 40px; color: #999; background: white; border: 1px solid #ddd; }
        .empty-state i { font-size: 40px; display: block; margin-bottom: 10px; color: #ccc; }

        /* ============================================ */
        /* CONFIRMATION MODAL (Restore)                 */
        /* ============================================ */
        .confirm-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 99999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        .confirm-modal-overlay.active { display: flex; }
        .confirm-modal {
            background: #fff;
            max-width: 480px;
            width: 92%;
            padding: 24px 26px 20px 26px;
            border-radius: 10px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.25);
            font-family: 'Times New Roman', Times, serif;
            animation: modalFadeIn 0.25s ease;
            text-align: center;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-15px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .confirm-modal-icon {
            width: 60px; height: 60px;
            border-radius: 50%;
            background: #fff3cd; color: #b9770e;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; margin: 0 auto 14px auto;
        }
        .confirm-modal h3 { color: #1b4d3e; font-size: 17px; font-weight: 600; margin-bottom: 10px; }
        .confirm-modal p { font-size: 13px; color: #555; line-height: 1.5; margin-bottom: 6px; }
        .confirm-modal .confirm-item {
            background: #f8f9fa; border: 1px solid #e0e0e0;
            border-radius: 6px; padding: 10px 14px;
            font-size: 13px; font-weight: bold; color: #1b4d3e;
            margin: 12px 0 14px 0;
        }
        .confirm-modal .confirm-warning {
            font-size: 11px; color: #e74c3c;
            font-style: italic; margin-bottom: 16px;
        }
        .confirm-modal-buttons { display: flex; gap: 10px; justify-content: center; }
        .btn-confirm-cancel {
            padding: 8px 22px; background: #f0f0f0; color: #555;
            border: none; border-radius: 5px; cursor: pointer;
            font-size: 13px; font-family: 'Times New Roman', Times, serif;
            font-weight: 500;
        }
        .btn-confirm-cancel:hover { background: #e0e0e0; }
        .btn-confirm-ok {
            padding: 8px 22px; background: #f39c12; color: white;
            border: none; border-radius: 5px; cursor: pointer;
            font-size: 13px; font-family: 'Times New Roman', Times, serif;
            font-weight: 500;
        }
        .btn-confirm-ok:hover { background: #e67e22; }

        body.dark-mode .confirm-modal { background: #1a1a1a; color: #fff; }
        body.dark-mode .confirm-modal h3 { color: #f1c40f; }
        body.dark-mode .confirm-modal p { color: #bbb; }
        body.dark-mode .confirm-modal .confirm-item { background: #2c2c2c; border-color: #444; color: #f1c40f; }
        body.dark-mode .btn-confirm-cancel { background: #333; color: #ccc; }
        body.dark-mode .btn-confirm-cancel:hover { background: #444; }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .semester-card,
        body.dark-mode .search-bar,
        body.dark-mode .feed-screen-header,
        body.dark-mode .list-screen-header,
        body.dark-mode .empty-state,
        body.dark-mode .roster-simple { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .semester-card-header { background: #2c2c2c; border-color: #333; }
        body.dark-mode .semester-title { color: #f1c40f; }
        body.dark-mode .stat-num { color: #f1c40f; }
        body.dark-mode .search-bar input[type="text"] { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .feed-post-box { background: #242526; border-color: #3a3a3a; color: #ddd; }
        body.dark-mode .feed-post-box.priority-urgent { background: #2a1f33; }
        body.dark-mode .feed-post-box.priority-high   { background: #331f1e; }
        body.dark-mode .feed-post-box-title { color: #f1c40f; }
        body.dark-mode .feed-post-box-meta { color: #aaa; }
        body.dark-mode .feed-post-box-meta strong { color: #f1c40f; }
        body.dark-mode .feed-post-box-content { color: #ccc; border-top-color: #3a3a3a; }
        body.dark-mode .no-feed-msg-box { background: #1a1a1a; border-color: #3a3a3a; color: #888; }
        body.dark-mode .feed-screen-title { color: #f1c40f; }
        body.dark-mode .feed-screen-stats .num { color: #f1c40f; }
        body.dark-mode .roster-simple-table thead th { background: #2c2c2c; color: #aaa; border-color: #333; }
        body.dark-mode .roster-simple-table tbody td { color: #ddd; border-color: #333; }
        body.dark-mode .roster-simple-table tbody tr:hover { background: #242526; }
        body.dark-mode .roster-simple-table .id-cell code { background: #2c2c2c; color: #f1c40f; }
        body.dark-mode .roster-simple-table .name-cell { color: #f1c40f; }

        @media (max-width: 768px) {
            .office-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .roster-simple-table thead th,
            .roster-simple-table tbody td { padding: 8px 10px; font-size: 12px; }
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
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
            <p>Clearance Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?officesection=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?officesection=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?officesection=history" class="active"><i class="fas fa-history"></i> Clearance History</a></li>
            <li><a href="?officesection=note"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li><a href="?officesection=chat"><i class="fas fa-comment-dots"></i> Messages</a></li>
            <li><a href="?officesection=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
        <div style="padding: 12px 20px; font-size: 10px; color: #ddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px; border-radius: 2px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f; border-radius: 2px;"></div>
            </div>
        </div>
    </div>

    <div class="main-content">

        <?php if ($view_mode === 'feed' && $selectedSem && intval($selectedSem['is_current']) !== 1): ?>
            <!-- ============================================ -->
            <!-- FEED ARCHIVE — FULL SEPARATE SCREEN           -->
            <!-- ============================================ -->
            <?php
            $feedPosts = getOfficeFeedPostsForSemester($conn, $selectedSem['id'], $office);
            $feedCount = count($feedPosts);
            ?>

            <div class="dashboard-header-bar">
                <h2><i class="fas fa-folder-open"></i> Feed Archive</h2>
                <a href="?officesection=history" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Archived Semesters
                </a>
            </div>

            <div class="feed-screen-header">
                <div class="feed-screen-title">
                    <i class="fas fa-folder-open"></i>
                    <?php echo htmlspecialchars($selectedSem['semester_name']); ?>
                    <?php if (!empty($selectedSem['school_year'])): ?>
                        · <?php echo htmlspecialchars($selectedSem['school_year']); ?>
                    <?php endif; ?>
                </div>
                <div class="feed-screen-subtitle">
                    <i class="fas fa-info-circle"></i>
                    All feed posts published by <strong><?php echo htmlspecialchars($office); ?></strong> during this semester.
                </div>
                <div class="feed-screen-stats">
                    <div class="item">
                        <span class="num"><?php echo $feedCount; ?></span>
                        <span class="lbl">Feed Posts</span>
                    </div>
                    <?php if (!empty($selectedSem['archived_at'])): ?>
                        <div class="item">
                            <span class="num" style="font-size: 14px;">
                                <?php echo date('M d, Y', strtotime($selectedSem['archived_at'])); ?>
                            </span>
                            <span class="lbl">Archived On</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($feedCount > 0): ?>
                <?php foreach ($feedPosts as $post):
                    $priority = isset($post['priority']) ? strtolower($post['priority']) : 'low';
                    if (!in_array($priority, ['low','medium','high','urgent'])) $priority = 'low';
                    $title   = isset($post['title']) ? $post['title'] : '(No Title)';
                    $content = isset($post['content']) ? $post['content'] : '';
                    $createdAt = isset($post['created_at']) ? $post['created_at'] : '';
                ?>
                <div class="feed-post-box priority-<?php echo $priority; ?>">
                    <div class="feed-post-box-header">
                        <div class="feed-post-box-title">
                            <?php echo htmlspecialchars($title); ?>
                        </div>
                        <span class="priority-badge <?php echo $priority; ?>">
                            <?php echo htmlspecialchars(strtoupper($priority)); ?>
                        </span>
                    </div>
                    <div class="feed-post-box-meta">
                        <span><strong><i class="fas fa-building"></i> <?php echo htmlspecialchars($office); ?></strong></span>
                        <?php if ($createdAt): ?>
                            <span><i class="fas fa-clock"></i> <?php echo date('M d, Y h:i A', strtotime($createdAt)); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($content)): ?>
                        <div class="feed-post-box-content">
                            <?php echo nl2br(htmlspecialchars($content)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-feed-msg-box">
                    <i class="fas fa-inbox"></i>
                    No feed posts were published in this semester.
                </div>
            <?php endif; ?>

        <?php elseif ($view_mode === 'list' && $selectedSem && intval($selectedSem['is_current']) !== 1): ?>
            <!-- ============================================ -->
            <!-- FULL STUDENT LIST — SIMPLE GROUPED VIEW       -->
            <!-- Grouped by Course → Year → Section            -->
            <!-- Sorted alphabetically inside each group       -->
            <!-- ============================================ -->
            <?php
            $sid = intval($selectedSem['id']);
            $office_esc = $conn->real_escape_string($office);

            $details = $conn->query("
                SELECT s.chmsu_student_id, s.chmsu_last_name, s.chmsu_first_name,
                       s.chmsu_middle_name, s.chmsu_course, s.chmsu_year, s.chmsu_section,
                       cs.status, cs.note, cs.updated_at
                FROM chmsu_students_master s
                LEFT JOIN chmsu_clearance_status cs
                    ON s.chmsu_student_id = cs.student_id
                    AND cs.office_name = '$office_esc'
                    AND cs.semester_id = $sid
                WHERE s.is_archived = 0
                ORDER BY
                    s.chmsu_course ASC,
                    CAST(s.chmsu_year AS UNSIGNED) ASC,
                    s.chmsu_section ASC,
                    s.chmsu_last_name ASC,
                    s.chmsu_first_name ASC
            ");

            // Group by (Course, Year, Section)
            $groups = [];
            $approvedCount = 0;
            $declinedCount = 0;
            $pendingCount  = 0;
            $totalStudentCount = 0;

            if ($details && $details->num_rows > 0) {
                while ($row = $details->fetch_assoc()) {
                    $st = $row['status'] ?: 'pending';
                    if ($st === 'clear')        $approvedCount++;
                    elseif ($st === 'unclear')  $declinedCount++;
                    else                        $pendingCount++;
                    $totalStudentCount++;

                    $row['_status'] = $st;

                    $course  = $row['chmsu_course'] !== '' ? strtoupper($row['chmsu_course']) : 'NO COURSE';
                    $year    = intval($row['chmsu_year']);
                    $section = trim($row['chmsu_section']) !== '' ? strtoupper(trim($row['chmsu_section'])) : '—';

                    $key = $course . '||' . $year . '||' . $section;
                    if (!isset($groups[$key])) {
                        $groups[$key] = [
                            'course' => $course,
                            'year' => $year,
                            'section' => $section,
                            'students' => []
                        ];
                    }
                    $groups[$key]['students'][] = $row;
                }
            }

            // Sort groups: Course ASC, Year ASC, Section ASC
            usort($groups, function($a, $b) {
                $c = strcasecmp($a['course'], $b['course']);
                if ($c !== 0) return $c;
                if ($a['year'] !== $b['year']) return $a['year'] - $b['year'];
                return strcasecmp($a['section'], $b['section']);
            });

            // Sort students inside each group by Last, First
            foreach ($groups as &$g) {
                usort($g['students'], function($a, $b) {
                    $c = strcasecmp($a['chmsu_last_name'], $b['chmsu_last_name']);
                    if ($c !== 0) return $c;
                    return strcasecmp($a['chmsu_first_name'], $b['chmsu_first_name']);
                });
            }
            unset($g);

            // Pretty year label
            $ordinalYear = function($y) {
                $y = intval($y);
                if ($y <= 0) return 'Unassigned Year';
                $suffix = 'th';
                if ($y === 1) $suffix = 'st';
                elseif ($y === 2) $suffix = 'nd';
                elseif ($y === 3) $suffix = 'rd';
                return $y . $suffix . ' Year';
            };
            ?>

            <div class="dashboard-header-bar">
                <h2><i class="fas fa-list"></i> Full Student List</h2>
                <a href="?officesection=history" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Archived Semesters
                </a>
            </div>

            <div class="list-screen-header">
                <div style="font-size: 16px; font-weight: 700; color: #1b4d3e; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-list"></i>
                    <?php echo htmlspecialchars($selectedSem['semester_name']); ?>
                    <?php if (!empty($selectedSem['school_year'])): ?>
                        · <?php echo htmlspecialchars($selectedSem['school_year']); ?>
                    <?php endif; ?>
                </div>
                <div style="font-size: 12px; color: #777; margin-top: 6px;">
                    Grouped by Course, Year &amp; Section — sorted alphabetically within each group.
                </div>

                <div class="stat-row" style="margin-top: 14px; padding-top: 14px; border-top: 1px dashed #ddd;">
                    <div class="stat-item">
                        <span class="stat-num"><?php echo $totalStudentCount; ?></span>
                        <span class="stat-lbl">Total Students</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-num approved"><?php echo $approvedCount; ?></span>
                        <span class="stat-lbl">Approved</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-num declined"><?php echo $declinedCount; ?></span>
                        <span class="stat-lbl">Declined</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-num pending"><?php echo $pendingCount; ?></span>
                        <span class="stat-lbl">Not Processed</span>
                    </div>
                </div>
            </div>

            <?php if (empty($groups)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <p>No students found for this semester.</p>
                </div>
            <?php else: ?>
                <?php foreach ($groups as $g):
                    $groupTitle = $g['course'] . ' — ' . $ordinalYear($g['year']) . ' — Section ' . $g['section'];
                    $studentCount = count($g['students']);
                ?>
                <div class="roster-simple">
                    <div class="roster-simple-title">
                        <i class="fas fa-users"></i>
                        <?php echo htmlspecialchars($groupTitle); ?>
                        <span class="group-count"><?php echo $studentCount; ?> student<?php echo $studentCount !== 1 ? 's' : ''; ?></span>
                    </div>
                    <table class="roster-simple-table">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Full Name</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($g['students'] as $row):
                                $st = $row['_status'];
                                if ($st === 'clear') {
                                    $badgeClass = 'approved';
                                    $badgeText = 'Approved';
                                } elseif ($st === 'unclear') {
                                    $badgeClass = 'declined';
                                    $badgeText = 'Declined';
                                } else {
                                    $badgeClass = 'pending';
                                    $badgeText = 'Not Processed';
                                }
                            ?>
                            <tr>
                                <td class="id-cell"><code><?php echo htmlspecialchars($row['chmsu_student_id']); ?></code></td>
                                <td class="name-cell"><?php echo htmlspecialchars($row['chmsu_last_name'] . ', ' . $row['chmsu_first_name']); ?></td>
                                <td><span class="status-badge-tbl <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span></td>
                                <td><?php echo $row['updated_at'] ? date('M d, Y', strtotime($row['updated_at'])) : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php else: ?>
            <!-- ============================================ -->
            <!-- DEFAULT: LIST OF ARCHIVED SEMESTERS ONLY      -->
            <!-- ============================================ -->

            <div class="dashboard-header-bar">
                <h2><i class="fas fa-archive"></i> Clearance History — Archived Semesters</h2>
                <span><?php echo date('F d, Y'); ?></span>
            </div>

            <div class="search-bar">
                <i class="fas fa-search" style="color: #999;"></i>
                <input type="text" id="searchArchived" placeholder="Search semester or school year..." onkeyup="filterArchived()">
                <span class="result-count" id="searchResultCount"></span>
            </div>

            <?php if (empty($semesterSummaries)): ?>
                <div class="semester-card" style="border-left-color: #7f8c8d;">
                    <div class="semester-card-body" style="text-align:center; padding:50px; color:#999;">
                        <i class="fas fa-inbox" style="font-size:48px; display:block; margin-bottom:14px; color:#ccc;"></i>
                        <p style="font-size: 15px; margin-bottom: 6px;">No archived semesters yet.</p>
                        <p style="font-size: 12px; color: #aaa;">Semesters appear here once the admin archives them.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($groupedBySY as $syLabel => $listBySY): ?>
                    <div class="sy-group" data-sy-label="<?php echo strtolower(htmlspecialchars($syLabel)); ?>">
                        <div class="sy-group-title">
                            <i class="fas fa-calendar-alt"></i>
                            School Year <?php echo htmlspecialchars($syLabel); ?>
                            <span class="sy-count"><?php echo count($listBySY); ?> sem</span>
                        </div>

                        <?php foreach ($listBySY as $sem):
                            $is_restorable = ($mostRecentArchivedId === intval($sem['id']));
                            $semName = $sem['semester_name'] . (!empty($sem['school_year']) ? ' · ' . $sem['school_year'] : '');
                        ?>
                        <div class="semester-card" data-search="<?php echo strtolower(htmlspecialchars($sem['semester_name'] . ' ' . (!empty($sem['school_year']) ? $sem['school_year'] : ''))); ?>">
                            <div class="semester-card-header">
                                <div class="semester-title">
                                    <?php echo htmlspecialchars($sem['semester_name']); ?>
                                    <?php if (!empty($sem['school_year'])): ?>
                                        · <?php echo htmlspecialchars($sem['school_year']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($sem['archived_at'])): ?>
                                        <small><i class="fas fa-archive"></i> Archived on <?php echo date('M d, Y h:i A', strtotime($sem['archived_at'])); ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php if (intval($sem['is_current']) === 1): ?>
                                    <span class="semester-badge badge-active">Currently Active</span>
                                <?php else: ?>
                                    <span class="semester-badge badge-archived">Archived</span>
                                <?php endif; ?>
                            </div>
                            <div class="semester-card-body">
                                <div class="stat-row">
                                    <div class="stat-item">
                                        <span class="stat-num approved"><?php echo $sem['approved']; ?></span>
                                        <span class="stat-lbl">Approved</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-num declined"><?php echo $sem['declined']; ?></span>
                                        <span class="stat-lbl">Declined</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-num pending"><?php echo $sem['pending']; ?></span>
                                        <span class="stat-lbl">Not Processed</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-num" style="color: #3498db;"><?php echo $sem['feed_count']; ?></span>
                                        <span class="stat-lbl">Feed Posts</span>
                                    </div>
                                </div>

                                <div class="card-actions-row">
                                    <a href="?officesection=history&semester_id=<?php echo intval($sem['id']); ?>&view=feed" class="btn btn-feed btn-sm">
                                        <i class="fas fa-folder-open"></i>
                                        Feed Archive
                                        <span class="count-badge"><?php echo $sem['feed_count']; ?></span>
                                    </a>

                                    <a href="?officesection=history&semester_id=<?php echo intval($sem['id']); ?>&view=list" class="btn btn-primary btn-sm">
                                        <i class="fas fa-list"></i> View Full List
                                    </a>

                                    <?php if ($is_restorable): ?>
                                        <button type="button" class="btn btn-warning btn-sm"
                                            onclick="openRestoreConfirm(<?php echo intval($sem['id']); ?>, '<?php echo addslashes($semName); ?>')">
                                            <i class="fas fa-undo"></i> Restore
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<!-- ============================================ -->
<!-- CONFIRMATION MODAL (Restore)                 -->
<!-- ============================================ -->
<div class="confirm-modal-overlay" id="restoreConfirmModal">
    <div class="confirm-modal">
        <div class="confirm-modal-icon">
            <i class="fas fa-undo"></i>
        </div>
        <h3>Restore Archived Semester?</h3>
        <p>You are about to restore the following semester back to <strong>active</strong>:</p>
        <div class="confirm-item" id="restoreSemesterName">—</div>
        <p style="font-size: 12px; color: #777;">
            The current semester will be <strong>deactivated</strong> and this one will become the currently active semester.
        </p>
        <p class="confirm-warning">
            <i class="fas fa-exclamation-triangle"></i>
            You will still need to verify with an OTP.
        </p>
        <div class="confirm-modal-buttons">
            <button class="btn-confirm-cancel" onclick="closeRestoreConfirm()">Cancel</button>
            <button class="btn-confirm-ok" id="confirmRestoreBtn" onclick="proceedToRestore()">
                <i class="fas fa-check"></i> Yes, Restore
            </button>
        </div>
    </div>
</div>

<!-- HIDDEN FORM for semester restore POST -->
<form id="semesterRestoreForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="verify_semester_action">
    <input type="hidden" name="semester_action" value="rollback">
    <input type="hidden" name="target_semester_id" id="restoreTargetId">
</form>

<script>
    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        const isDarkMode = document.body.classList.contains('dark-mode');
        localStorage.setItem('darkMode', isDarkMode);
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) btn.innerHTML = isDarkMode ? '<i class="fas fa-sun"></i> Light Mode' : '<i class="fas fa-moon"></i> Dark Mode';
    }
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) btn.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
    }
    function confirmLogout() {
        if (confirm('Are you sure you want to logout?')) window.location.href = '?logout=1';
    }

    function filterArchived() {
        var q = document.getElementById('searchArchived');
        if (!q) return;
        var term = q.value.toLowerCase().trim();
        var cards = document.querySelectorAll('.semester-card[data-search]');
        var syGroups = document.querySelectorAll('.sy-group');
        var visibleCount = 0;

        cards.forEach(function(card) {
            var haystack = card.getAttribute('data-search') || '';
            var show = (term === '' || haystack.indexOf(term) !== -1);
            card.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        syGroups.forEach(function(group) {
            var anyVisible = false;
            group.querySelectorAll('.semester-card[data-search]').forEach(function(card) {
                if (card.style.display !== 'none') anyVisible = true;
            });
            group.style.display = anyVisible ? '' : 'none';
        });

        var counter = document.getElementById('searchResultCount');
        if (counter) {
            if (term === '') counter.textContent = '';
            else counter.textContent = visibleCount + ' match' + (visibleCount === 1 ? '' : 'es');
        }
    }

    // ============================================
    // Restore confirmation modal
    // ============================================
    var pendingRestoreId = 0;
    var pendingRestoreName = '';

    function openRestoreConfirm(semesterId, semesterName) {
        pendingRestoreId = semesterId;
        pendingRestoreName = semesterName;
        document.getElementById('restoreSemesterName').textContent = semesterName;
        document.getElementById('restoreTargetId').value = semesterId;
        document.getElementById('restoreConfirmModal').classList.add('active');
    }

    function closeRestoreConfirm() {
        document.getElementById('restoreConfirmModal').classList.remove('active');
        pendingRestoreId = 0;
        pendingRestoreName = '';
    }

    function proceedToRestore() {
        if (pendingRestoreId <= 0) return;
        document.getElementById('restoreConfirmModal').classList.remove('active');
        window.location.href = '?officesection=history&semester_id=' + pendingRestoreId + '&do_restore=1';
    }

    window.addEventListener('click', function(e) {
        var modal = document.getElementById('restoreConfirmModal');
        if (e.target === modal) closeRestoreConfirm();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeRestoreConfirm();
    });
</script>

</body>
</html>