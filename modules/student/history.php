<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/clearance_history.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['student'])) {
    header("Location: ../index.php");
    exit;
}

$student_id = $_SESSION['student'];
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
$master  = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$chat_unread_count = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='$student_id'")->fetch_assoc()['total'] ?: 0;

$currentSem = getCurrentSemester($conn);
$semesters = getHistorySemesters($conn);
$totalOffices = getTotalOfficesCount($conn);

// ============================================
// VIEW MODE (archived / feed / list / cleared-list)
// ============================================
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'history';
$selected_sem_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : 0;
$selectedSem = $selected_sem_id > 0 ? getSemesterById($conn, $selected_sem_id) : null;

// ============================================
// Helper: fetch feed posts for a specific semester
// Falls back gracefully if chmsu_feed.semester_id doesn't exist
// ============================================
if (!function_exists('getFeedPostsForSemester')) {
    function getFeedPostsForSemester($conn, $semesterId) {
        $semesterId = intval($semesterId);
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
                               ORDER BY created_at DESC");
        } else {
            $q = $conn->query("SELECT * FROM chmsu_feed
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

// Build per-semester summaries (cleared offices, feed count, statuses)
$semesterSummaries = [];
foreach ($archivedSemesters as $sem) {
    $cleared = countStudentClearedOffices($conn, $student_id, $sem['id']);
    $is_fully_cleared = ($cleared >= $totalOffices && $totalOffices > 0);
    $pct = ($totalOffices > 0) ? round(($cleared / $totalOffices) * 100) : 0;
    $statuses = getStudentSemesterStatus($conn, $student_id, $sem['id']);

    $sem['cleared'] = $cleared;
    $sem['is_fully_cleared'] = $is_fully_cleared;
    $sem['pct'] = $pct;
    $sem['statuses'] = $statuses;
    $sem['feed_posts'] = getFeedPostsForSemester($conn, $sem['id']);
    $sem['feed_count'] = count($sem['feed_posts']);

    $semesterSummaries[] = $sem;
}

// ============================================
// GROUP BY SCHOOL YEAR (newest first)
// ============================================
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
    <title>CHMSU E-Clearance System - My Clearance History</title>
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

        .dark-sidebar { width: 260px; background: #1b4d3e; color: white; position: fixed; height: calc(100vh - 73px); overflow-y: auto; }
        .dark-sidebar::-webkit-scrollbar { width: 5px; }
        .dark-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .dark-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .dark-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .dark-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        .dark-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .dark-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-menu a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 13px; transition: all 0.3s; }
        .dark-sidebar .sidebar-menu a:hover, .dark-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        .dark-sidebar .sidebar-badge { background: #e74c3c; color: white; border-radius: 50%; padding: 1px 6px; font-size: 9px; margin-left: 5px; }

        .main-content { flex: 1; margin-left: 260px; padding: 20px; background: #f5f5f5; min-height: calc(100vh - 73px); }
        .info-bar { background: white; padding: 12px 15px; border: 1px solid #ddd; margin-bottom: 20px; display: flex; gap: 20px; flex-wrap: wrap; font-size: 12px; }
        .info-bar-item strong { color: #1b4d3e; }

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
        .search-bar .result-count {
            font-size: 12px; color: #666; font-style: italic;
        }

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
            border-left: 5px solid #1b4d3e;
            margin-bottom: 12px; overflow: hidden;
            transition: box-shadow 0.2s;
        }
        .semester-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .semester-card:last-child { margin-bottom: 0; }
        .semester-card.cleared { border-left-color: #27ae60; }
        .semester-card.incomplete { border-left-color: #f39c12; }

        .semester-card-header { background: #f8f9fa; padding: 14px 18px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .semester-title { font-size: 15px; font-weight: 600; color: #1b4d3e; }
        .semester-title small { display: block; font-size: 11px; font-weight: normal; color: #888; margin-top: 3px; }
        .semester-badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-cleared { background: #d4efdf; color: #1e8449; }
        .badge-incomplete { background: #fdecea; color: #c0392b; }
        .badge-active { background: #d4efdf; color: #1e8449; }

        .semester-card-body { padding: 16px 18px; }

        .progress-line { margin-bottom: 14px; }
        .progress-line-label { display: flex; justify-content: space-between; font-size: 12px; color: #555; margin-bottom: 6px; }
        .progress-bar { width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: #27ae60; border-radius: 4px; transition: width 0.4s ease; }

        .office-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
        .office-pill { padding: 4px 10px; border-radius: 14px; font-size: 11px; display: inline-flex; align-items: center; gap: 5px; }
        .office-pill.approved { background: #d4efdf; color: #1e8449; }
        .office-pill.declined { background: #fdecea; color: #c0392b; }
        .office-pill.pending  { background: #f0f0f0; color: #666; }

        /* ============================================ */
        /* ACTION BUTTONS ROW                           */
        /* ============================================ */
        .card-actions {
            display: flex; gap: 8px; flex-wrap: wrap;
            padding-top: 14px; border-top: 1px dashed #ddd;
            margin-top: 4px;
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
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #219a52; }
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
        .feed-screen-subtitle {
            font-size: 12px; color: #777; margin-top: 6px;
        }
        .feed-screen-stats {
            display: flex; gap: 20px; flex-wrap: wrap;
            margin-top: 14px; padding-top: 14px;
            border-top: 1px dashed #ddd;
        }
        .feed-screen-stats .item { display: flex; flex-direction: column; gap: 2px; }
        .feed-screen-stats .num { font-size: 18px; font-weight: 700; color: #1b4d3e; }
        .feed-screen-stats .lbl { font-size: 10px; color: #777; text-transform: uppercase; letter-spacing: 0.4px; }

        .feed-post-box {
            background: #fafbfc;
            border: 1px solid #e5e7ea;
            border-left: 4px solid #f39c12;
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 14px;
            font-size: 13px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
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
            word-wrap: break-word;
            padding: 10px 0 0 0;
            border-top: 1px dashed #eee;
            margin-top: 6px;
        }

        .section-separator-box { margin: 12px 0; padding: 12px 14px; background: #f0f7ff; border: 1px solid #d0e3f7; border-radius: 8px; }
        .section-separator-box .sec-label { font-weight: 700; color: #1b4d3e; font-size: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .section-separator-box .sec-names { display: flex; flex-wrap: wrap; gap: 6px; }
        .sec-name { display: inline-flex; align-items: center; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 3px 10px; font-size: 11.5px; color: #334155; line-height: 1.3; font-weight: 500; }
        .sec-name i { font-size: 10px; margin-right: 4px; color: #1b4d3e; }

        .course-separator-box { margin: 12px 0; padding: 12px 16px; background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 8px; }
        .course-separator-box .course-label { font-weight: 700; color: #7e22ce; font-size: 13px; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #e9d5ff; display: flex; align-items: center; gap: 6px; }
        .course-separator-box .course-subgroup { margin: 8px 0; padding: 8px 12px; background: #ffffff; border: 1px solid #f3e8ff; border-left: 3px solid #9333ea; border-radius: 6px; }
        .course-separator-box .course-sub-label { font-weight: 700; color: #6b21a8; font-size: 11px; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .3px; }
        .course-separator-box .course-sub-names { display: flex; flex-wrap: wrap; gap: 6px; }

        .mention-section { margin-top: 10px; padding: 10px 14px; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; font-size: 12px; }
        .mention-section .mention-title { font-weight: 700; color: #92400e; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
        .mention-section .mention-names-grid { display: flex; flex-wrap: wrap; gap: 6px; }
        .mention-section .mention-pill { display: inline-flex; align-items: center; background: #ffffff; border: 1px solid #fcd34d; border-radius: 12px; padding: 2px 8px; font-size: 11px; color: #78350f; font-weight: 600; }
        .mention-section .mention-pill i { font-size: 9px; margin-right: 4px; color: #d97706; }

        body.dark-mode .section-separator-box { background: #1a2c3a; border-color: #2c4a6a; }
        body.dark-mode .section-separator-box .sec-label { color: #f1c40f; }
        body.dark-mode .section-separator-box .sec-names { color: #ddd; }
        body.dark-mode .sec-name { background: #1e293b; border-color: #475569; color: #e2e8f0; }
        body.dark-mode .sec-name i { color: #f1c40f; }
        body.dark-mode .course-separator-box { background: #241a3a; border-color: #4a3a6a; }
        body.dark-mode .course-separator-box .course-label { color: #d5b3ff; border-bottom-color: #4a3a6a; }
        body.dark-mode .course-separator-box .course-subgroup { background: #1e1b2e; border-color: #3b2d54; }
        body.dark-mode .course-separator-box .course-sub-label { color: #c39bd3; }
        body.dark-mode .mention-section { background: #2a2113; border-color: #4d3810; }
        body.dark-mode .mention-section .mention-title { color: #fde68a; }
        body.dark-mode .mention-section .mention-pill { background: #1e1a12; border-color: #634812; color: #fef3c7; }

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
        /* FULL LIST SCREEN (view=list)                 */
        /* ============================================ */
        .list-screen-header {
            background: white; padding: 18px 22px;
            border: 1px solid #ddd;
            border-left: 5px solid #1b4d3e;
            margin-bottom: 20px;
        }

        .empty-state { text-align: center; padding: 40px; color: #999; background: white; border: 1px solid #ddd; }
        .empty-state i { font-size: 40px; display: block; margin-bottom: 10px; color: #ccc; }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .info-bar,
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .semester-card,
        body.dark-mode .empty-state,
        body.dark-mode .search-bar,
        body.dark-mode .feed-screen-header,
        body.dark-mode .list-screen-header { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .semester-card-header { background: #2c2c2c; border-color: #333; }
        body.dark-mode .semester-title { color: #f1c40f; }
        body.dark-mode .progress-bar { background: #333; }
        body.dark-mode .card-actions { border-top-color: #333; }
        body.dark-mode .search-bar input[type="text"] { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .feed-screen-title { color: #f1c40f; }
        body.dark-mode .feed-screen-stats .num { color: #f1c40f; }
        body.dark-mode .feed-post-box { background: #242526; border-color: #3a3a3a; color: #ddd; }
        body.dark-mode .feed-post-box.priority-urgent { background: #2a1f33; }
        body.dark-mode .feed-post-box.priority-high   { background: #331f1e; }
        body.dark-mode .feed-post-box-title { color: #f1c40f; }
        body.dark-mode .feed-post-box-meta { color: #aaa; }
        body.dark-mode .feed-post-box-meta strong { color: #f1c40f; }
        body.dark-mode .feed-post-box-content { color: #ccc; border-top-color: #3a3a3a; }
        body.dark-mode .no-feed-msg-box { background: #1a1a1a; border-color: #3a3a3a; color: #888; }

        @media (max-width: 768px) {
            .dark-sidebar { width: 100%; position: relative; height: auto; }
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
        <p>CLEARANCE SYSTEM | Student Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="dark-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($master['chmsu_full_name']); ?></h3>
            <p><?php echo htmlspecialchars($student['chmsu_course']); ?> <?php echo $student['chmsu_year']; ?><?php echo htmlspecialchars($student['chmsu_section']); ?></p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?view=home"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="?view=office"><i class="fas fa-clipboard-check"></i> My Clearance</a></li>
            <li><a href="?view=history" class="active"><i class="fas fa-history"></i> Clearance History</a></li>
            <li><a href="?view=feed"><i class="fas fa-rss"></i> Updates Feed</a></li>
            <li>
                <a href="?view=chat">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php if ($chat_unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $chat_unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li style="margin-top: 15px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout(); return false;"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="info-bar">
            <div class="info-bar-item"><strong>ID:</strong> <?php echo $student['chmsu_student_id']; ?></div>
            <div class="info-bar-item"><strong>Name:</strong> <?php echo $master['chmsu_full_name']; ?></div>
            <div class="info-bar-item"><strong>Course/Year/Section:</strong> <?php echo $student['chmsu_course']; ?> <?php echo $student['chmsu_year']; ?><?php echo $student['chmsu_section']; ?></div>
        </div>

        <?php if ($view_mode === 'feed' && $selectedSem && intval($selectedSem['is_current']) !== 1): ?>
            <!-- ============================================ -->
            <!-- FEED ARCHIVE — FULL SEPARATE SCREEN           -->
            <!-- ============================================ -->
            <?php
            $feedPosts = getFeedPostsForSemester($conn, $selectedSem['id']);
            $feedCount = count($feedPosts);
            ?>

            <div class="dashboard-header-bar">
                <h2><i class="fas fa-folder-open"></i> Feed Archive</h2>
                <a href="?view=history" class="back-btn">
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
                    All advisories and announcements from offices during this semester.
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
                    $officeName = isset($post['office_name']) ? $post['office_name'] : '—';
                    $createdAt = isset($post['created_at']) ? $post['created_at'] : '';

                    // Parse mentions
                    $mentionedNames = array();
                    if (preg_match_all('/(?:👥|ðŸ‘¥)?\s*Mentioned:\s*(.*?)(?:\n|$)/u', $content, $matches)) {
                        $namesStr = trim($matches[1][0] ?? '');
                        if (!empty($namesStr)) {
                            if (strpos($namesStr, '|||') !== false) {
                                $mentionedNames = array_map('trim', explode('|||', $namesStr));
                            } else {
                                $mentionedNames = array(trim($namesStr));
                            }
                        }
                    }
                    $mentionedNames = array_values(array_unique(array_filter($mentionedNames)));

                    $sectionBlocks = array();
                    if (preg_match('/=== SECTION MENTION ===(.*?)=== END SECTION ===/s', $content, $smatch)) {
                        $inner = trim($smatch[1]);
                        $inner = str_replace(["\r\n", "\r"], "\n", $inner);
                        $inner = str_replace("\n---SEP---", "", $inner);
                        $lines = explode("\n", $inner);
                        $currentLabel = '';
                        foreach ($lines as $line) {
                            $line = rtrim($line);
                            if (preg_match('/^\[(.+)\]$/', trim($line), $lm)) {
                                $currentLabel = $lm[1];
                                $sectionBlocks[$currentLabel] = array();
                            } elseif (substr($line, 0, 2) === '- ' && $currentLabel !== '') {
                                $sectionBlocks[$currentLabel][] = substr($line, 2);
                            }
                        }
                    }

                    $courseBlocks = array();
                    if (preg_match('/=== COURSE MENTION ===(.*?)=== END COURSE ===/s', $content, $cmatch)) {
                        $cInner = trim($cmatch[1]);
                        $cInner = str_replace(["\r\n", "\r"], "\n", $cInner);
                        $cInner = str_replace("\n---SEP---", "", $cInner);
                        $cInner = str_replace("\n---COURSE-SEP---", "", $cInner);
                        $cLines = explode("\n", $cInner);
                        $currentCourse = '';
                        $currentSub = '';
                        foreach ($cLines as $line) {
                            $line = rtrim($line);
                            if (preg_match('/^\{(.+)\}$/', trim($line), $cm)) {
                                $currentCourse = $cm[1];
                                $courseBlocks[$currentCourse] = array();
                                $currentSub = '';
                            } elseif (preg_match('/^<(.+)>$/', trim($line), $sm)) {
                                $currentSub = $sm[1];
                                if ($currentCourse !== '') $courseBlocks[$currentCourse][$currentSub] = array();
                            } elseif (substr($line, 0, 2) === '- ' && $currentCourse !== '' && $currentSub !== '') {
                                $courseBlocks[$currentCourse][$currentSub][] = substr($line, 2);
                            }
                        }
                    }

                    $cleanContent = preg_replace('/[\r\n]*=== SECTION MENTION ===.*?=== END SECTION ===[\r\n]*/s', '', $content);
                    $cleanContent = preg_replace('/[\r\n]*=== COURSE MENTION ===.*?=== END COURSE ===[\r\n]*/s', '', $cleanContent);
                    $cleanContent = preg_replace('/[\r\n]*(?:👥|ðŸ‘¥)?\s*Mentioned: .*/u', '', $cleanContent);
                    $cleanContent = preg_replace('/---(?:COURSE-)?SEP---/', '', $cleanContent);
                    $cleanContent = preg_replace('/===\s*(?:END\s+)?(?:COURSE|SECTION)\s*===/', '', $cleanContent);
                    $cleanContent = trim($cleanContent);
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
                        <span><strong><i class="fas fa-building"></i> <?php echo htmlspecialchars($officeName); ?></strong></span>
                        <?php if ($createdAt): ?>
                            <span><i class="fas fa-clock"></i> <?php echo date('M d, Y h:i A', strtotime($createdAt)); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($mentionedNames)): ?>
                            <span style="color:#d97706; font-weight:600;"><i class="fas fa-user-tag"></i> <?php echo count($mentionedNames); ?> mentioned</span>
                        <?php endif; ?>
                    </div>
                    <div class="feed-post-box-content">
                        <?php if (!empty($cleanContent)): ?>
                            <div style="margin-bottom: 10px;"><?php echo nl2br(htmlspecialchars($cleanContent)); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($courseBlocks)): ?>
                            <?php foreach ($courseBlocks as $courseName => $subgroups): ?>
                                <div class="course-separator-box">
                                    <div class="course-label"><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($courseName); ?></div>
                                    <?php foreach ($subgroups as $subLabel => $names): ?>
                                        <div class="course-subgroup">
                                            <div class="course-sub-label">
                                                <span><?php echo htmlspecialchars($subLabel); ?></span>
                                                <span style="font-size: 10px; font-weight: normal; opacity: 0.8;">(<?php echo count($names); ?> students)</span>
                                            </div>
                                            <div class="course-sub-names">
                                                <?php foreach ($names as $n): ?>
                                                    <span class="sec-name"><i class="fas fa-user-graduate"></i><?php echo htmlspecialchars($n); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($sectionBlocks)): ?>
                            <?php foreach ($sectionBlocks as $label => $names): ?>
                                <div class="section-separator-box">
                                    <div class="sec-label">
                                        <i class="fas fa-users"></i>
                                        <span><?php echo htmlspecialchars($label); ?></span>
                                        <span style="font-size: 10px; font-weight: normal; opacity: 0.8;">(<?php echo count($names); ?> students)</span>
                                    </div>
                                    <div class="sec-names">
                                        <?php foreach ($names as $n): ?>
                                            <span class="sec-name"><i class="fas fa-user-graduate"></i><?php echo htmlspecialchars($n); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($mentionedNames)): ?>
                            <div class="mention-section">
                                <div class="mention-title"><i class="fas fa-user-tag"></i> Individually Mentioned Students (<?php echo count($mentionedNames); ?>):</div>
                                <div class="mention-names-grid">
                                    <?php foreach ($mentionedNames as $name): ?>
                                        <span class="mention-pill"><i class="fas fa-user"></i><?php echo htmlspecialchars($name); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
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
            <!-- FULL OFFICE-BY-OFFICE LIST — SEPARATE SCREEN  -->
            <!-- ============================================ -->
            <?php
            $statuses = getStudentSemesterStatus($conn, $student_id, $selectedSem['id']);
            $cleared = countStudentClearedOffices($conn, $student_id, $selectedSem['id']);
            $pct = ($totalOffices > 0) ? round(($cleared / $totalOffices) * 100) : 0;
            ?>

            <div class="dashboard-header-bar">
                <h2><i class="fas fa-list"></i> Office-by-Office Breakdown</h2>
                <a href="?view=history" class="back-btn">
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
                    Your full clearance status for each office during this semester.
                    <strong><?php echo $cleared; ?> / <?php echo $totalOffices; ?> offices (<?php echo $pct; ?>%)</strong>
                </div>
            </div>

            <div class="semester-card" style="border-left-color: #1b4d3e;">
                <div class="semester-card-body" style="padding: 0;">
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#1b4d3e; color:white;">
                                <th style="padding:12px 10px; text-align:left;">Office</th>
                                <th style="padding:12px 10px; text-align:left;">Status</th>
                                <th style="padding:12px 10px; text-align:left;">Note</th>
                                <th style="padding:12px 10px; text-align:left;">Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $allOffices = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
                            $hasAny = false;
                            while ($off = $allOffices->fetch_assoc()):
                                $hasAny = true;
                                $on = $off['office_name'];
                                $st = isset($statuses[$on]) ? $statuses[$on]['status'] : 'pending';
                                $note = isset($statuses[$on]) ? $statuses[$on]['note'] : '';
                                $updated = isset($statuses[$on]) ? $statuses[$on]['updated_at'] : '';

                                $badge_style = 'background:#f0f0f0; color:#666;';
                                $badge_text = 'Not Processed';
                                if ($st === 'clear') { $badge_style = 'background:#d4efdf; color:#1e8449;'; $badge_text = 'Approved'; }
                                elseif ($st === 'unclear') { $badge_style = 'background:#fdecea; color:#c0392b;'; $badge_text = 'Declined'; }
                            ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:10px;"><?php echo htmlspecialchars($on); ?></td>
                                <td style="padding:10px;"><span style="padding:3px 10px; border-radius:12px; font-size:11px; <?php echo $badge_style; ?>"><?php echo $badge_text; ?></span></td>
                                <td style="padding:10px; color: #666;"><?php echo $note ? htmlspecialchars($note) : '—'; ?></td>
                                <td style="padding:10px; color: #666;"><?php echo $updated ? date('M d, Y', strtotime($updated)) : '—'; ?></td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if (!$hasAny): ?>
                                <tr><td colspan="4" style="padding:30px; text-align:center; color:#999;">No offices configured.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($view_mode === 'certificate' && $selectedSem && intval($selectedSem['is_current']) !== 1): ?>
            <!-- ============================================ -->
            <!-- CERTIFICATE REDIRECT (same behaviour)         -->
            <!-- ============================================ -->
            <?php
            // Preserve original behaviour: certificate view opens the office module
            // with semester_id + show_certificate=1. We redirect here.
            header("Location: ?view=office&semester_id=" . intval($selectedSem['id']) . "&show_certificate=1");
            exit;
            ?>

        <?php else: ?>
            <!-- ============================================ -->
            <!-- DEFAULT: LIST OF SEMESTERS (Active + Archived)-->
            <!-- ============================================ -->

            <div class="dashboard-header-bar">
                <h2><i class="fas fa-archive"></i> My Clearance History — Semesters</h2>
                <span><?php echo date('F d, Y'); ?></span>
            </div>

            <div class="search-bar">
                <i class="fas fa-search" style="color: #999;"></i>
                <input type="text" id="searchArchived" placeholder="Search semester or school year..." onkeyup="filterArchived()">
                <span class="result-count" id="searchResultCount"></span>
            </div>

            <?php if (empty($semesterSummaries)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p style="font-size: 15px; margin-bottom: 6px;">No semesters found.</p>
                    <p style="font-size: 12px; color: #aaa;">Semesters appear here once the admin creates them.</p>
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
                            $semCardClass = 'semester-card' . ($sem['is_fully_cleared'] ? ' cleared' : ' incomplete');
                            
                            // Badge Logic: Check if active first, then check if cleared
                            if (intval($sem['is_current']) === 1) {
                                $badge = '<span class="semester-badge badge-active">Currently Active</span>';
                            } else {
                                $badge = $sem['is_fully_cleared']
                                    ? '<span class="semester-badge badge-cleared">Fully Cleared</span>'
                                    : '<span class="semester-badge badge-incomplete">Not Cleared</span>';
                            }
                        ?>
                        <div class="<?php echo $semCardClass; ?>" data-search="<?php echo strtolower(htmlspecialchars($sem['semester_name'] . ' ' . (!empty($sem['school_year']) ? $sem['school_year'] : ''))); ?>">
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
                                <?php echo $badge; ?>
                            </div>
                            <div class="semester-card-body">
                                <div class="progress-line">
                                    <div class="progress-line-label">
                                        <span>Overall Progress</span>
                                        <span><?php echo $sem['cleared']; ?> / <?php echo $totalOffices; ?> offices (<?php echo $sem['pct']; ?>%)</span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-bar-fill" style="width: <?php echo $sem['pct']; ?>%;"></div>
                                    </div>
                                </div>

                                <div class="office-pills">
                                    <?php
                                    $allOffices = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
                                    while ($off = $allOffices->fetch_assoc()):
                                        $on = $off['office_name'];
                                        $st = isset($sem['statuses'][$on]) ? $sem['statuses'][$on]['status'] : 'pending';
                                        $cls = 'pending'; $icon = 'clock';
                                        if ($st === 'clear') { $cls = 'approved'; $icon = 'check-circle'; }
                                        elseif ($st === 'unclear') { $cls = 'declined'; $icon = 'times-circle'; }
                                    ?>
                                    <span class="office-pill <?php echo $cls; ?>">
                                        <i class="fas fa-<?php echo $icon; ?>"></i>
                                        <?php echo htmlspecialchars($on); ?>
                                    </span>
                                    <?php endwhile; ?>
                                </div>

                                <div class="card-actions">
                                    <a href="?view=history&semester_id=<?php echo intval($sem['id']); ?>&view=feed" class="btn btn-feed btn-sm">
                                        <i class="fas fa-folder-open"></i>
                                        Feed Archive
                                        <span class="count-badge"><?php echo $sem['feed_count']; ?></span>
                                    </a>

                                    <a href="?view=history&semester_id=<?php echo intval($sem['id']); ?>&view=list" class="btn btn-primary btn-sm">
                                        <i class="fas fa-list"></i> View Breakdown
                                    </a>

                                    <?php if ($sem['is_fully_cleared']): ?>
                                        <a href="?view=office&semester_id=<?php echo intval($sem['id']); ?>&show_certificate=1" class="btn btn-success btn-sm">
                                            <i class="fas fa-certificate"></i> View Certificate
                                        </a>
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

    // ============================================
    // SEARCH FILTER (only on archived list screen)
    // ============================================
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
</script>

</body>
</html>