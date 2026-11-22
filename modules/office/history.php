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
        /* ============================================ */
        /* FULL FEED SCREEN (view=feed) - MODERN UI     */
        /* ============================================ */
        .feed-screen-header {
            background: white; padding: 18px 22px;
            border: 1px solid #ddd;
            border-left: 5px solid #3498db;
            margin-bottom: 20px;
            border-radius: 6px;
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

        .feed-search-wrap {
            position: relative;
            margin-bottom: 18px;
        }
        .feed-search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            font-size: 14px;
        }
        .feed-search-wrap input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 13px;
            background: white;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .feed-search-wrap input:focus {
            border-color: #1b4d3e;
            box-shadow: 0 0 0 3px rgba(27,77,62,0.1);
        }

        .feed-card-modern {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 18px 22px;
            margin-bottom: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .feed-card-modern:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            transform: translateY(-1px);
        }
        .feed-card-modern.priority-urgent { border-left: 5px solid #8e44ad; }
        .feed-card-modern.priority-high   { border-left: 5px solid #e74c3c; }
        .feed-card-modern.priority-medium { border-left: 5px solid #f39c12; }
        .feed-card-modern.priority-low    { border-left: 5px solid #1b4d3e; }

        .feed-card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .feed-card-badges {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .feed-office-tag {
            background: #eef7f2;
            color: #1b4d3e;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .feed-priority-pill {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .feed-priority-pill.urgent { background: #f3e8ff; color: #6b21a8; }
        .feed-priority-pill.high   { background: #fee2e2; color: #991b1b; }
        .feed-priority-pill.medium { background: #fef3c7; color: #92400e; }
        .feed-priority-pill.low    { background: #e0f2fe; color: #075985; }

        .feed-date-tag {
            font-size: 11px;
            color: #888;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .feed-card-title {
            font-size: 16px;
            font-weight: 700;
            color: #1b4d3e;
            margin-bottom: 8px;
            line-height: 1.35;
        }
        .feed-card-body-text {
            font-size: 13px;
            color: #374151;
            line-height: 1.7;
            word-break: break-word;
            padding-top: 10px;
            border-top: 1px solid #f3f4f6;
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
            border-radius: 6px;
        }

        /* Filter Card in History List View */
        .filter-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: visible;
        }
        .filter-card-header {
            background: #f8f9fa;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 700;
            color: #1b4d3e;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-card-body {
            padding: 16px;
        }
        .filter-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .filter-grid .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
            min-width: 140px;
        }
        .filter-grid label {
            font-size: 11px;
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .filter-grid select,
        .filter-grid input {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }
        .filter-grid select:focus,
        .filter-grid input:focus {
            border-color: #1b4d3e;
        }
        .suggestions-box {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
            z-index: 1000;
            display: none;
            max-height: 220px;
            overflow-y: auto;
            margin-top: 4px;
        }
        .suggestion-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .suggestion-item:last-child { border-bottom: none; }
        .suggestion-item:hover {
            background: #eef7f2;
        }
        .suggestion-item .sugg-name {
            font-size: 13px;
            font-weight: 700;
            color: #1b4d3e;
        }
        .suggestion-item .sugg-sub {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        .suggestion-none {
            padding: 10px 12px;
            font-size: 12px;
            color: #888;
            text-align: center;
            font-style: italic;
        }
        .btn-reset {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            font-weight: 700;
            transition: background 0.2s;
            height: 35px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-reset:hover {
            background: #c0392b;
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
            <li><a href="?officesection=note"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li><a href="?officesection=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?officesection=history" class="active"><i class="fas fa-history"></i> Clearance History</a></li>
            <li><a href="?officesection=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?officesection=chat"><i class="fas fa-comment-dots"></i> Messages</a></li>
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

            <div class="feed-search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="feedSearchInput" placeholder="Search advisories by subject, content, or priority..." oninput="filterFeedHistory(this.value)">
            </div>

            <?php if ($feedCount > 0): ?>
                <div id="feedPostsContainer">
                <?php foreach ($feedPosts as $post):
                    $priority = isset($post['priority']) ? strtolower($post['priority']) : 'low';
                    if (!in_array($priority, ['low','medium','high','urgent'])) $priority = 'low';
                    $title   = isset($post['title']) ? $post['title'] : '(No Title)';
                    $content = isset($post['content']) ? $post['content'] : '';
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

                    $searchBlob = strtolower($title . ' ' . $cleanContent . ' ' . $office . ' ' . $priority);
                ?>
                <div class="feed-card-modern priority-<?php echo $priority; ?>" data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES); ?>">
                    <div class="feed-card-top">
                        <div class="feed-card-badges">
                            <span class="feed-office-tag"><i class="fas fa-building"></i> <?php echo htmlspecialchars($office); ?></span>
                            <span class="feed-priority-pill <?php echo $priority; ?>"><?php echo strtoupper($priority); ?></span>
                            <?php if (!empty($mentionedNames)): ?>
                                <span class="feed-office-tag" style="background:#fef3c7; color:#92400e;"><i class="fas fa-user-tag"></i> <?php echo count($mentionedNames); ?> mentioned</span>
                            <?php endif; ?>
                            <?php if (!empty($courseBlocks)): ?>
                                <span class="feed-office-tag" style="background:#f3e8ff; color:#6b21a8;"><i class="fas fa-graduation-cap"></i> Course Mentions</span>
                            <?php elseif (!empty($sectionBlocks)): ?>
                                <span class="feed-office-tag" style="background:#e0f2fe; color:#075985;"><i class="fas fa-users"></i> <?php echo count($sectionBlocks); ?> sections</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($createdAt): ?>
                            <span class="feed-date-tag"><i class="far fa-clock"></i> <?php echo date('M d, Y · h:i A', strtotime($createdAt)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="feed-card-title">
                        <?php echo htmlspecialchars($title); ?>
                    </div>
                    <div class="feed-card-body-text">
                        <?php if (!empty($cleanContent)): ?>
                            <div style="margin-bottom: 10px; color: #374151;"><?php echo nl2br(htmlspecialchars($cleanContent)); ?></div>
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
                </div>
                <div id="noFeedMatchBox" class="no-feed-msg-box" style="display: none;">
                    <i class="fas fa-search"></i>
                    No advisories match your search query.
                </div>
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
            $allAvailableCourses = [];
            $allAvailableSections = [];
            $studentSearchData = [];

            foreach ($groups as &$g) {
                if (!empty($g['course']) && !in_array($g['course'], $allAvailableCourses)) {
                    $allAvailableCourses[] = $g['course'];
                }
                if (!empty($g['section']) && $g['section'] !== '—' && !in_array($g['section'], $allAvailableSections)) {
                    $allAvailableSections[] = $g['section'];
                }
                usort($g['students'], function($a, $b) {
                    $c = strcasecmp($a['chmsu_last_name'], $b['chmsu_last_name']);
                    if ($c !== 0) return $c;
                    return strcasecmp($a['chmsu_first_name'], $b['chmsu_first_name']);
                });
                foreach ($g['students'] as $st) {
                    $sName = trim($st['chmsu_last_name'] . ', ' . $st['chmsu_first_name']);
                    $studentSearchData[] = [
                        'id' => $st['chmsu_student_id'],
                        'name' => $sName,
                        'course' => $g['course'],
                        'year' => $g['year'],
                        'section' => $g['section']
                    ];
                }
            }
            unset($g);
            sort($allAvailableCourses);
            sort($allAvailableSections);

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
                        <span class="stat-num" id="statTotalStudents"><?php echo $totalStudentCount; ?></span>
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

            <!-- ============================================ -->
            <!-- FILTER & SEARCH BAR WITH AUTOCOMPLETE         -->
            <!-- ============================================ -->
            <div class="filter-card">
                <div class="filter-card-header"><i class="fas fa-filter"></i> Filter &amp; Search Students</div>
                <div class="filter-card-body">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label><i class="fas fa-graduation-cap"></i> Course</label>
                            <select id="filterCourse" onchange="applyStudentFilters()">
                                <option value="">All Courses</option>
                                <?php foreach ($allAvailableCourses as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Year Level</label>
                            <select id="filterYear" onchange="applyStudentFilters()">
                                <option value="">All Years</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-th-large"></i> Section</label>
                            <select id="filterSection" onchange="applyStudentFilters()">
                                <option value="">All Sections</option>
                                <?php foreach ($allAvailableSections as $sec): ?>
                                    <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 2; min-width: 200px; position: relative;">
                            <label><i class="fas fa-search"></i> Search Student (Name or ID)</label>
                            <input type="text" id="studentSearchInput" placeholder="Type student name or ID..." autocomplete="off" oninput="onStudentSearchInput(this.value)">
                            <div class="suggestions-box" id="studentSearchSuggestions"></div>
                        </div>
                        <div>
                            <button type="button" class="btn-reset" onclick="resetStudentFilters()"><i class="fas fa-undo"></i> Reset</button>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($groups)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <p>No students found for this semester.</p>
                </div>
            <?php else: ?>
                <div id="rosterCardsContainer">
                <?php foreach ($groups as $g):
                    $groupTitle = $g['course'] . ' — ' . $ordinalYear($g['year']) . ' — Section ' . $g['section'];
                    $studentCount = count($g['students']);
                ?>
                <div class="roster-simple"
                     data-course="<?php echo htmlspecialchars($g['course'], ENT_QUOTES); ?>"
                     data-year="<?php echo intval($g['year']); ?>"
                     data-section="<?php echo htmlspecialchars($g['section'], ENT_QUOTES); ?>">
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
                                $fullName = $row['chmsu_last_name'] . ', ' . $row['chmsu_first_name'];
                            ?>
                            <tr data-name="<?php echo htmlspecialchars(strtolower($fullName), ENT_QUOTES); ?>"
                                data-id="<?php echo htmlspecialchars(strtolower($row['chmsu_student_id']), ENT_QUOTES); ?>">
                                <td class="id-cell"><code><?php echo htmlspecialchars($row['chmsu_student_id']); ?></code></td>
                                <td class="name-cell"><?php echo htmlspecialchars($fullName); ?></td>
                                <td><span class="status-badge-tbl <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span></td>
                                <td><?php echo $row['updated_at'] ? date('M d, Y', strtotime($row['updated_at'])) : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
                </div>

                <div id="noStudentMatchBox" class="empty-state" style="display: none;">
                    <i class="fas fa-search"></i>
                    <p>No students match your filter or search criteria.</p>
                </div>
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
    // ============================================
    // FEED SEARCH FILTER (view=feed)
    // ============================================
    function filterFeedHistory(query) {
        var term = (query || '').toLowerCase().trim();
        var cards = document.querySelectorAll('#feedPostsContainer .feed-card-modern');
        var visible = 0;
        cards.forEach(function(card) {
            var searchStr = card.getAttribute('data-search') || '';
            var matches = !term || searchStr.indexOf(term) !== -1;
            card.style.display = matches ? '' : 'none';
            if (matches) visible++;
        });

        var noMatch = document.getElementById('noFeedMatchBox');
        if (noMatch) {
            noMatch.style.display = (visible === 0 && cards.length > 0) ? 'block' : 'none';
        }
    }

    // ============================================
    // STUDENT FILTER & AUTOCOMPLETE (view=list)
    // ============================================
    var studentRoster = <?php echo !empty($studentSearchData) ? json_encode($studentSearchData) : '[]'; ?>;

    function onStudentSearchInput(val) {
        var q = (val || '').toLowerCase().trim();
        var box = document.getElementById('studentSearchSuggestions');
        if (!box) return;

        if (q.length < 1) {
            box.innerHTML = '';
            box.style.display = 'none';
            applyStudentFilters();
            return;
        }

        var matches = studentRoster.filter(function(st) {
            return st.name.toLowerCase().indexOf(q) !== -1 || st.id.toLowerCase().indexOf(q) !== -1;
        }).slice(0, 6);

        if (matches.length > 0) {
            var html = '';
            matches.forEach(function(m) {
                var safeName = m.name.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                html += '<div class="suggestion-item" onclick="selectStudentSuggestion(\'' + safeName + '\')">' +
                        '<div class="sugg-name"><i class="fas fa-user-graduate"></i> ' + escapeHtml(m.name) + '</div>' +
                        '<div class="sugg-sub">' + escapeHtml(m.id) + ' · ' + escapeHtml(m.course) + ' ' + m.year + '-' + escapeHtml(m.section) + '</div>' +
                        '</div>';
            });
            box.innerHTML = html;
            box.style.display = 'block';
        } else {
            box.innerHTML = '<div class="suggestion-none">No matching student found</div>';
            box.style.display = 'block';
        }

        applyStudentFilters();
    }

    function selectStudentSuggestion(name) {
        var inp = document.getElementById('studentSearchInput');
        if (inp) inp.value = name;
        var box = document.getElementById('studentSearchSuggestions');
        if (box) box.style.display = 'none';
        applyStudentFilters();
    }

    function applyStudentFilters() {
        var courseEl = document.getElementById('filterCourse');
        var yearEl = document.getElementById('filterYear');
        var secEl = document.getElementById('filterSection');
        var searchEl = document.getElementById('studentSearchInput');

        var course = courseEl ? courseEl.value.toUpperCase().trim() : '';
        var year = yearEl ? yearEl.value.trim() : '';
        var section = secEl ? secEl.value.toUpperCase().trim() : '';
        var query = searchEl ? searchEl.value.toLowerCase().trim() : '';

        var totalVisible = 0;

        document.querySelectorAll('#rosterCardsContainer .roster-simple').forEach(function(card) {
            var c = (card.getAttribute('data-course') || '').toUpperCase().trim();
            var y = (card.getAttribute('data-year') || '').trim();
            var s = (card.getAttribute('data-section') || '').toUpperCase().trim();

            var groupMatches = true;
            if (course && c !== course) groupMatches = false;
            if (year && y !== year) groupMatches = false;
            if (section && s !== section) groupMatches = false;

            var visibleInGroup = 0;
            card.querySelectorAll('tbody tr').forEach(function(row) {
                if (!groupMatches) {
                    row.style.display = 'none';
                    return;
                }

                var name = (row.getAttribute('data-name') || '').toLowerCase();
                var id = (row.getAttribute('data-id') || '').toLowerCase();

                var textMatches = !query || name.indexOf(query) !== -1 || id.indexOf(query) !== -1;
                if (textMatches) {
                    row.style.display = '';
                    visibleInGroup++;
                    totalVisible++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (groupMatches && visibleInGroup > 0) {
                card.style.display = '';
                var countBadge = card.querySelector('.group-count');
                if (countBadge) countBadge.textContent = visibleInGroup + ' student' + (visibleInGroup !== 1 ? 's' : '');
            } else {
                card.style.display = 'none';
            }
        });

        var noMatch = document.getElementById('noStudentMatchBox');
        if (noMatch) {
            noMatch.style.display = totalVisible === 0 ? 'block' : 'none';
        }

        var statTotal = document.getElementById('statTotalStudents');
        if (statTotal) {
            statTotal.textContent = totalVisible;
        }
    }

    function resetStudentFilters() {
        if (document.getElementById('filterCourse')) document.getElementById('filterCourse').value = '';
        if (document.getElementById('filterYear')) document.getElementById('filterYear').value = '';
        if (document.getElementById('filterSection')) document.getElementById('filterSection').value = '';
        if (document.getElementById('studentSearchInput')) document.getElementById('studentSearchInput').value = '';
        var box = document.getElementById('studentSearchSuggestions');
        if (box) box.style.display = 'none';
        applyStudentFilters();
    }

    function escapeHtml(text) {
        if (!text) return '';
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Close suggestions box when clicking outside
    document.addEventListener('click', function(e) {
        var box = document.getElementById('studentSearchSuggestions');
        var inp = document.getElementById('studentSearchInput');
        if (box && inp && !box.contains(e.target) && !inp.contains(e.target)) {
            box.style.display = 'none';
        }
    });
</script>

</body>
</html>