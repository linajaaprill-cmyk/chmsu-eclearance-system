<?php
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();

$my_full_name = $master['chmsu_full_name'] ?? '';
$my_course    = strtoupper(trim($master['chmsu_course'] ?? ''));
$my_year      = (string)($master['chmsu_year'] ?? '');
$my_section   = strtoupper(trim($master['chmsu_section'] ?? ''));

// ============================================
// HANDLE HIDE POST
// ============================================
if (isset($_POST['action']) && $_POST['action'] == 'hide_post' && isset($_SESSION['student'])) {
    $post_id = intval($_POST['post_id']);
    $conn->query("CREATE TABLE IF NOT EXISTS chmsu_hidden_posts (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        post_id INT(11) NOT NULL,
        hidden_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_hidden (student_id, post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("INSERT INTO chmsu_hidden_posts (student_id, post_id) VALUES ('{$_SESSION['student']}', $post_id)");
    header("Location: ?view=feed");
    exit;
}

// ============================================
// HANDLE MARK SINGLE NOTIFICATION AS READ (AJAX)
// ============================================
if (isset($_POST['action']) && $_POST['action'] == 'mark_one_notification_read' && isset($_SESSION['student'])) {
    header('Content-Type: application/json');
    $notif_id = intval($_POST['notification_id']);
    $conn->query("UPDATE chmsu_notifications SET is_read = 1 
                  WHERE id = $notif_id 
                  AND user_type='student' 
                  AND user_id='{$_SESSION['student']}'");
    echo json_encode(['success' => true]);
    exit;
}

// ============================================
// HANDLE MARK ALL NOTIFICATIONS READ (AJAX)
// ============================================
if (isset($_POST['action']) && $_POST['action'] == 'mark_notifications_read' && isset($_SESSION['student'])) {
    header('Content-Type: application/json');
    $conn->query("UPDATE chmsu_notifications SET is_read = 1 
                  WHERE user_type='student' 
                  AND user_id='{$_SESSION['student']}'");
    echo json_encode(['success' => true]);
    exit;
}

// ============================================
// GET NOTIFICATIONS
// ============================================
$unread_notifications = $conn->query("
    SELECT COUNT(*) as c FROM chmsu_notifications 
    WHERE user_type='student' 
    AND user_id='{$_SESSION['student']}' 
    AND is_read=0
")->fetch_assoc()['c'];

$mentioned_notifications = $conn->query("
    SELECT COUNT(*) as c FROM chmsu_notifications 
    WHERE user_type='student' 
    AND user_id='{$_SESSION['student']}' 
    AND is_read=0
    AND title LIKE '%mentioned%'
")->fetch_assoc()['c'];

$latest_notifications = $conn->query("
    SELECT * FROM chmsu_notifications 
    WHERE user_type='student' 
    AND user_id='{$_SESSION['student']}' 
    ORDER BY created_at DESC 
    LIMIT 10
");

// ============================================
// Feed posts — scoped to CURRENT SEMESTER
// + Auto-revive on restore/rollback:
//   any feed posts tagged to the current semester
//   are un-archived automatically, so restoring
//   an old semester brings its posts back to
//   the feed. Posts from the newly-archived
//   semester disappear because their semester_id
//   no longer matches the current one.
// ============================================
$current_sem_row = $conn->query("SELECT * FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
$current_sem_id = $current_sem_row ? intval($current_sem_row['id']) : 0;

$feed_sem_col_chk = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
$feed_has_sem_col = ($feed_sem_col_chk && $feed_sem_col_chk->num_rows > 0);

// Auto-revive: un-archive ALL posts belonging to the current semester
if ($feed_has_sem_col && $current_sem_id > 0) {
    $conn->query("UPDATE chmsu_feed 
                  SET is_archived = 0 
                  WHERE semester_id = $current_sem_id 
                  AND is_archived = 1");
}

$sem_filter_sql = '';
if ($feed_has_sem_col && $current_sem_id > 0) {
    $sem_filter_sql = " AND f.semester_id = $current_sem_id ";
}

$feed_posts = $conn->query("
    SELECT f.*, 
           (SELECT COUNT(*) FROM chmsu_feed_views WHERE feed_id = f.id) as view_count,
           (SELECT COUNT(*) FROM chmsu_feed_views WHERE feed_id = f.id AND student_id = '{$_SESSION['student']}') as user_viewed,
           (SELECT chmsu_full_name FROM chmsu_students_master WHERE chmsu_student_id = f.student_id) as mentioned_student_name,
           (SELECT COUNT(*) FROM chmsu_hidden_posts WHERE student_id = '{$_SESSION['student']}' AND post_id = f.id) as is_hidden
    FROM chmsu_feed f
    WHERE f.is_archived = 0 
    AND (SELECT COUNT(*) FROM chmsu_hidden_posts WHERE student_id = '{$_SESSION['student']}' AND post_id = f.id) = 0
    $sem_filter_sql
    ORDER BY f.created_at DESC
");

while ($post = $feed_posts->fetch_assoc()) {
    if ($post['user_viewed'] == 0) {
        $conn->query("INSERT INTO chmsu_feed_views (feed_id, student_id) VALUES ({$post['id']}, '{$_SESSION['student']}')");
    }
}
$feed_posts->data_seek(0);

$conn->query("UPDATE chmsu_notifications SET is_read = 1 WHERE user_type='student' AND user_id='{$_SESSION['student']}' AND type='feed'");

$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

$chat_unread_count = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='{$_SESSION['student']}'")->fetch_assoc()['total'] ?: 0;

$current_year_result = $conn->query("SELECT chmsu_year FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'");
$current_student_year = '1';
if ($current_year_result && $current_year_result->num_rows > 0) {
    $current_student_year = $current_year_result->fetch_assoc()['chmsu_year'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance - Updates Feed</title>
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
        .dark-mode-toggle { background: transparent; border: 1px solid rgba(255,255,255,0.3); color: white; padding: 6px 12px; cursor: pointer; margin-left: auto; }
        .logout-btn { background: #e74c3c; color: white; padding: 6px 15px; border: none; cursor: pointer; }

        .notification-container { position: relative; margin-right: 10px; }
        .notification-bell {
            background: transparent;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            position: relative;
            padding: 5px 8px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .notification-bell:hover { background: rgba(255,255,255,0.12); }
        .notification-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 9px;
            min-width: 18px;
            text-align: center;
            font-weight: bold;
            border: 2px solid #1b4d3e;
            animation: pulse 1.5s infinite;
        }
        .notification-badge.mentioned {
            background: #e74c3c;
            animation: pulse-alarm 0.8s infinite;
        }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.2); } 100% { transform: scale(1); } }
        @keyframes pulse-alarm { 0% { transform: scale(1); background: #e74c3c; } 50% { transform: scale(1.3); background: #c0392b; } 100% { transform: scale(1); background: #e74c3c; } }

        .notification-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 48px;
            background: white;
            min-width: 340px;
            max-width: 420px;
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
            z-index: 1000;
            max-height: 450px;
            overflow-y: auto;
            padding: 0;
            border: 1px solid #e0e0e0;
        }
        .notification-dropdown::-webkit-scrollbar { width: 5px; }
        .notification-dropdown::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .notification-dropdown::-webkit-scrollbar-thumb { background: #1b4d3e; border-radius: 4px; }
        .notification-header {
            padding: 14px 16px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            border-radius: 10px 10px 0 0;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .notification-header strong { color: #1b4d3e; font-size: 14px; }
        .notification-header .mark-read {
            background: transparent;
            border: none;
            color: #1b4d3e;
            font-size: 11px;
            cursor: pointer;
            text-decoration: underline;
        }
        .notification-header .mark-read:hover { color: #2d6a4f; }
        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f5f5f5;
            cursor: pointer;
            transition: background 0.2s;
            position: relative;
        }
        .notification-item:hover { background: #f8f9fa; }
        .notification-item.unread { background: #f0f8ff; }
        .notification-item.mentioned { background: #fce8e6; border-left: 4px solid #e74c3c; }
        .notification-item.unread.mentioned { background: #fce8e6; }
        .notification-item .message { font-size: 12px; color: #333; line-height: 1.4; }
        .notification-item .message .mention-icon { color: #e74c3c; font-weight: bold; margin-right: 3px; }
        .notification-item .meta { font-size: 10px; color: #999; margin-top: 5px; display: flex; justify-content: space-between; align-items: center; }
        .notification-item .new-badge {
            background: #e74c3c; color: white; padding: 1px 8px;
            border-radius: 8px; font-size: 8px; font-weight: bold;
        }
        .notification-item .mentioned-badge {
            background: #e74c3c; color: white; padding: 1px 8px;
            border-radius: 8px; font-size: 8px; font-weight: bold;
            animation: pulse-alarm 1s infinite;
        }
        .notification-empty { text-align: center; padding: 30px 20px; color: #999; font-size: 13px; }
        .notification-empty i { font-size: 32px; display: block; margin-bottom: 10px; color: #ddd; }
        .notification-item .arrow-hint {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #ccc;
            font-size: 11px;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .notification-item:hover .arrow-hint { opacity: 1; }

        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }

        .dark-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .dark-sidebar::-webkit-scrollbar { width: 5px; }
        .dark-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .dark-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .dark-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .dark-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; padding-bottom: 20px; }
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

        .storage-info { padding: 12px 20px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px; }

        .feed-post {
            background: white;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            margin-bottom: 16px;
            padding: 16px 20px;
            transition: all 0.3s;
            position: relative;
            scroll-margin-top: 90px;
        }
        .feed-post:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .feed-post.highlight-flash {
            animation: highlightFlash 1.6s ease-out;
        }
        @keyframes highlightFlash {
            0% { background: #fff8dc; box-shadow: 0 0 0 3px #f1c40f; }
            100% { background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        }
        .feed-post .post-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; flex-wrap: wrap; gap: 5px; }
        .feed-post .post-office { font-weight: bold; color: #1b4d3e; font-size: 14px; }
        .feed-post .post-office i { color: #1b4d3e; margin-right: 5px; }
        .feed-post .post-datetime {
            font-size: 11px;
            color: #888;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .feed-post .post-subject {
            font-size: 20px;
            font-weight: bold;
            color: #1b4d3e;
            margin-bottom: 6px;
            padding: 8px 12px;
            background: #f0f8ff;
            border-radius: 4px;
            border-left: 4px solid #1b4d3e;
        }

        .feed-post .post-content {
            font-size: 13px;
            color: #333;
            line-height: 1.6;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            word-wrap: break-word;
            max-height: 420px;
            overflow-y: auto;
            overflow-x: hidden;
            position: relative;
            scrollbar-width: thin;
            scrollbar-color: #1b4d3e #e0e0e0;
        }
        .feed-post .post-content::-webkit-scrollbar { width: 8px; }
        .feed-post .post-content::-webkit-scrollbar-track { background: #e0e0e0; border-radius: 4px; }
        .feed-post .post-content::-webkit-scrollbar-thumb { background: #1b4d3e; border-radius: 4px; }
        .feed-post .post-content::-webkit-scrollbar-thumb:hover { background: #2d6a4f; }
        .feed-post .post-content.expanded { max-height: none; }

        .feed-post .post-content .mention-section {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px dashed #ddd;
            font-size: 12px;
            color: #555;
        }
        .feed-post .post-content .mention-section .mention-name {
            font-weight: bold;
            color: #1b4d3e;
            display: block;
            padding: 3px 0;
        }
        .feed-post .post-content .mention-section .mention-name.you {
            color: #e74c3c;
        }
        .feed-post .post-meta {
            font-size: 10px;
            color: #999;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            border-top: 1px solid #eee;
            padding-top: 8px;
            margin-top: 8px;
            align-items: center;
        }

        .section-separator-box {
            margin: 10px 0;
            padding: 10px 14px;
            background: #eaf4ff;
            border: 1px solid #cfe4f7;
            border-radius: 6px;
        }
        .section-separator-box .sec-label {
            font-weight: bold;
            color: #1b4d3e;
            font-size: 12px;
            margin-bottom: 6px;
        }
        .section-separator-box .sec-names {
            font-size: 12px;
            color: #333;
            line-height: 1.5;
        }
        .section-separator-box .sec-names .sec-name {
            display: block;
            padding: 2px 0 2px 10px;
        }

        .section-mention-name {
            display: block;
            padding: 3px 10px;
            font-size: 12.5px;
            font-weight: bold;
            color: #e74c3c;
            background: #fff0f0;
            border-left: 3px solid #e74c3c;
            border-radius: 3px;
            margin: 3px 0;
            animation: sectionMentionPulse 1.8s ease-in-out infinite;
        }
        .section-mention-name.you {
            color: #ffffff;
            background: #e74c3c;
            border-left-color: #c0392b;
            box-shadow: 0 0 8px rgba(231, 76, 60, 0.5);
            animation: sectionMentionPulseYou 1.5s ease-in-out infinite;
        }
        .section-mention-name::before {
            content: "👥";
            margin-right: 5px;
            font-size: 11px;
        }
        @keyframes sectionMentionPulse {
            0%, 100% { transform: translateX(0); box-shadow: 0 0 0 rgba(231, 76, 60, 0); background: #fff0f0; }
            50% { transform: translateX(4px); box-shadow: 0 0 12px rgba(231, 76, 60, 0.35); background: #ffe0e0; }
        }
        @keyframes sectionMentionPulseYou {
            0%, 100% { transform: translateX(0); box-shadow: 0 0 6px rgba(231, 76, 60, 0.4); background: #e74c3c; }
            50% { transform: translateX(4px); box-shadow: 0 0 18px rgba(231, 76, 60, 0.8); background: #c0392b; }
        }
        body.dark-mode .section-mention-name { color: #ff8f8f; background: #2c1010; border-left-color: #e74c3c; }
        body.dark-mode .section-mention-name.you { color: #ffffff; background: #c0392b; border-left-color: #e74c3c; }

        .course-separator-box {
            margin: 12px 0;
            padding: 12px 16px;
            background: #f5eafc;
            border: 1px solid #ddc8ea;
            border-radius: 6px;
        }
        .course-separator-box .course-label {
            font-weight: bold;
            color: #8e44ad;
            font-size: 13px;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #ddc8ea;
        }
        .course-separator-box .course-subgroup {
            margin: 8px 0 8px 12px;
            padding: 6px 10px;
            background: #fbf6ff;
            border-left: 3px solid #8e44ad;
            border-radius: 4px;
        }
        .course-separator-box .course-sub-label {
            font-weight: bold;
            color: #6c3483;
            font-size: 11px;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .course-separator-box .course-sub-names .sec-name {
            display: block;
            padding: 2px 0 2px 8px;
            font-size: 12px;
            color: #333;
        }

        .course-mention-chip {
            display: inline-block;
            padding: 6px 14px;
            margin: 4px 6px 4px 0;
            font-size: 13px;
            font-weight: bold;
            color: #ffffff;
            background: #e74c3c;
            border-radius: 6px;
            letter-spacing: 1px;
            text-transform: uppercase;
            box-shadow: 0 0 8px rgba(231, 76, 60, 0.5);
            animation: courseChipPulse 1.6s ease-in-out infinite;
        }
        .course-mention-chip::before {
            content: "🔔";
            margin-right: 6px;
            font-size: 12px;
        }
        @keyframes courseChipPulse {
            0%, 100% {
                transform: translateX(0) scale(1);
                box-shadow: 0 0 8px rgba(231, 76, 60, 0.5);
                background: #e74c3c;
            }
            50% {
                transform: translateX(4px) scale(1.04);
                box-shadow: 0 0 18px rgba(231, 76, 60, 0.85);
                background: #c0392b;
            }
        }
        body.dark-mode .course-mention-chip {
            background: #c0392b;
            color: #ffffff;
        }

        .mention-tag { background: #f1c40f; color: #000; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; }
        .mention-tag.you { background: #e74c3c; color: white; }
        .badge-priority { padding: 2px 10px; border-radius: 12px; font-size: 9px; font-weight: bold; }
        .badge-urgent { background: #8e44ad; color: white; }
        .badge-high { background: #e74c3c; color: white; }
        .badge-medium { background: #f39c12; color: white; }
        .badge-low { background: #3498db; color: white; }

        .see-more-btn {
            display: block;
            text-align: center;
            padding: 5px;
            background: #f0f0f0;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            color: #1b4d3e;
            margin-top: 5px;
        }
        .see-more-btn:hover { background: #e0e0e0; }

        .post-actions { display: flex; gap: 8px; margin-top: 8px; }
        .btn-hide { background: #95a5a6; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 10px; }
        .btn-hide:hover { background: #7f8f8d; }

        .no-posts { text-align: center; padding: 50px; background: white; border: 1px solid #ddd; border-radius: 8px; }
        .no-posts i { font-size: 64px; color: #ddd; margin-bottom: 15px; }
        .no-posts h3 { color: #1b4d3e; }
        .no-posts p { color: #666; }

        .mentioned-name-in-content {
            font-weight: bold;
            color: #1b4d3e;
            background: #f1c40f30;
            padding: 1px 6px;
            border-radius: 4px;
        }
        .mentioned-name-in-content.you {
            color: #e74c3c;
            background: #f1c40f60;
        }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .info-bar,
        body.dark-mode .feed-post,
        body.dark-mode .no-posts { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .feed-post .post-subject { background: #1a3a2a; color: #f1c40f; border-left-color: #f1c40f; }
        body.dark-mode .feed-post .post-content { background: #2c2c2c; color: #fff; scrollbar-color: #f1c40f #1a1a1a; }
        body.dark-mode .feed-post .post-content::-webkit-scrollbar-track { background: #1a1a1a; }
        body.dark-mode .feed-post .post-content::-webkit-scrollbar-thumb { background: #f1c40f; }
        body.dark-mode .feed-post .post-content::-webkit-scrollbar-thumb:hover { background: #ffd93d; }
        body.dark-mode .feed-post .post-meta { color: #aaa; border-top-color: #333; }
        body.dark-mode .feed-post .post-office { color: #f1c40f; }
        body.dark-mode .feed-post .post-datetime { color: #aaa; }
        body.dark-mode .info-bar-item { color: #ccc; }
        body.dark-mode .info-bar-item strong { color: #f1c40f; }
        body.dark-mode .notification-dropdown { background: #1a1a1a; border-color: #333; }
        body.dark-mode .notification-header { background: #2c2c2c; border-color: #333; }
        body.dark-mode .notification-header strong { color: #f1c40f; }
        body.dark-mode .notification-header .mark-read { color: #f1c40f; }
        body.dark-mode .notification-item { border-bottom-color: #333; }
        body.dark-mode .notification-item .message { color: #fff; }
        body.dark-mode .notification-item.unread { background: #1a3a2a; }
        body.dark-mode .notification-item.mentioned { background: #3a1a1a; border-left-color: #e74c3c; }
        body.dark-mode .notification-item:hover { background: #2c2c2c; }
        body.dark-mode .notification-empty { color: #aaa; }
        body.dark-mode .notification-empty i { color: #333; }
        body.dark-mode .feed-post .post-content .mention-section { border-top-color: #444; }
        body.dark-mode .see-more-btn { background: #2c2c2c; color: #f1c40f; }
        body.dark-mode .see-more-btn:hover { background: #3c3c3c; }
        body.dark-mode .mentioned-name-in-content { background: #1a3a2a; color: #f1c40f; }
        body.dark-mode .mentioned-name-in-content.you { color: #e74c3c; background: #3a1a1a; }
        body.dark-mode .section-separator-box { background: #1a2c3a; border-color: #2c4a6a; }
        body.dark-mode .section-separator-box .sec-label { color: #f1c40f; }
        body.dark-mode .section-separator-box .sec-names { color: #ddd; }
        body.dark-mode .course-separator-box { background: #241a3a; border-color: #4a3a6a; }
        body.dark-mode .course-separator-box .course-label { color: #d5b3ff; border-bottom-color: #4a3a6a; }
        body.dark-mode .course-separator-box .course-subgroup { background: #1a1a2a; border-left-color: #8e44ad; }
        body.dark-mode .course-separator-box .course-sub-label { color: #c39bd3; }
        body.dark-mode .course-separator-box .course-sub-names .sec-name { color: #ddd; }
        body.dark-mode .feed-post.highlight-flash { animation: highlightFlashDark 1.6s ease-out; }
        @keyframes highlightFlashDark {
            0% { background: #3a3a1a; box-shadow: 0 0 0 3px #f1c40f; }
            100% { background: #1a1a1a; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        }

        @media (max-width: 768px) {
            .dark-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .notification-dropdown { min-width: 280px; right: -60px; }
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

    <div class="notification-container">
        <button class="notification-bell" onclick="toggleNotifications()">
            <i class="fas fa-bell"></i>
            <?php if ($unread_notifications > 0): ?>
                <span class="notification-badge <?php echo $mentioned_notifications > 0 ? 'mentioned' : ''; ?>" id="notifBadge">
                    <?php echo $unread_notifications > 9 ? '9+' : $unread_notifications; ?>
                </span>
            <?php else: ?>
                <span class="notification-badge" id="notifBadge" style="display:none;">0</span>
            <?php endif; ?>
        </button>

        <div class="notification-dropdown" id="notificationDropdown">
            <div class="notification-header">
                <strong><i class="fas fa-bell"></i> Notifications</strong>
                <?php if ($unread_notifications > 0): ?>
                    <button class="mark-read" onclick="markAllRead(event)">Mark all read</button>
                <?php else: ?>
                    <button class="mark-read" onclick="markAllRead(event)" style="display:none;">Mark all read</button>
                <?php endif; ?>
            </div>

            <?php if ($latest_notifications && $latest_notifications->num_rows > 0): ?>
                <?php while ($notif = $latest_notifications->fetch_assoc()):
                    $isMentioned = strpos($notif['title'], 'mentioned') !== false;
                    $target_feed_id = intval($notif['feed_id'] ?? 0);
                    $target_link = $notif['link'] ?: '?view=feed';
                    if ($target_feed_id > 0) {
                        $target_link = '?view=feed#post_' . $target_feed_id;
                    }
                ?>
                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?> <?php echo $isMentioned && !$notif['is_read'] ? 'mentioned' : ''; ?>"
                         data-notif-id="<?php echo $notif['id']; ?>"
                         data-target="<?php echo htmlspecialchars($target_link); ?>"
                         onclick="openNotification(this, event)">
                        <div class="message">
                            <?php if ($isMentioned): ?>
                                <span class="mention-icon">🔔</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($notif['message']); ?>
                        </div>
                        <div class="meta">
                            <span><i class="fas fa-clock"></i> <?php echo date('M d, Y · h:i A', strtotime($notif['created_at'])); ?></span>
                            <?php if (!$notif['is_read']): ?>
                                <?php if ($isMentioned): ?>
                                    <span class="mentioned-badge">🔔 MENTIONED</span>
                                <?php else: ?>
                                    <span class="new-badge">NEW</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <i class="fas fa-chevron-right arrow-hint"></i>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="notification-empty">
                    <i class="fas fa-bell-slash"></i>
                    No new notifications
                </div>
            <?php endif; ?>
        </div>
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
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=home"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=feed" class="active"><i class="fas fa-rss"></i> Updates Feed</a></li>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=chat">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php if ($chat_unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $chat_unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=office"><i class="fas fa-clipboard-check"></i> OFFICES</a></li>
            <li style="margin-top: 15px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout(); return false;"><i class="fas fa-sign-out-alt"></i> Logout</a>
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
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-size: 16px;"><i class="fas fa-rss"></i> Updates Feed</h2>
            <span style="font-size: 11px; color: #666;">Latest advisories</span>
        </div>

        <?php if ($feed_posts && $feed_posts->num_rows > 0): ?>
            <?php while ($post = $feed_posts->fetch_assoc()):
                $content = $post['content'];

                // ============================================================
                // 1) INDIVIDUAL mentions — show ONLY my own name
                // ============================================================
                $mentionedNamesRaw = array();
                if (preg_match_all('/👥 Mentioned: (.*?)(?:\n|$)/', $content, $matches)) {
                    $namesStr = trim($matches[1][0]);
                    if (!empty($namesStr)) {
                        if (strpos($namesStr, '|||') !== false) {
                            $mentionedNamesRaw = array_map('trim', explode('|||', $namesStr));
                        } else {
                            $mentionedNamesRaw = array_map('trim', explode(',', $namesStr));
                        }
                    }
                }
                $mentionedNamesRaw = array_values(array_unique(array_filter($mentionedNamesRaw)));

                $myMentionOnly = array();
                foreach ($mentionedNamesRaw as $n) {
                    if ($n === $my_full_name) { $myMentionOnly[] = $n; break; }
                }

                // ============================================================
                // 2) SECTION blocks — SECURITY FIX: Show ONLY my section
                // ============================================================
                $allSectionBlocks = array();
                if (preg_match('/=== SECTION MENTION ===(.*?)=== END SECTION ===/s', $content, $smatch)) {
                    $inner = trim($smatch[1]);
                    $inner = str_replace("\n---SEP---", "", $inner);
                    $lines = explode("\n", $inner);
                    $currentLabel = '';
                    foreach ($lines as $line) {
                        $line = rtrim($line);
                        if (preg_match('/^\[(.+)\]$/', trim($line), $lm)) {
                            $currentLabel = $lm[1];
                            $allSectionBlocks[$currentLabel] = array();
                        } elseif (substr($line, 0, 2) === '- ' && $currentLabel !== '') {
                            $allSectionBlocks[$currentLabel][] = substr($line, 2);
                        }
                    }
                }

                // Filter to keep ONLY the section block where I am listed
                $myVisibleSectionBlocks = array();
                foreach ($allSectionBlocks as $label => $names) {
                    if (in_array($my_full_name, $names)) {
                        $myVisibleSectionBlocks[$label] = $names;
                    }
                }

                // ============================================================
                // 3) COURSE blocks — SECURITY FIX: Show ONLY my course
                // ============================================================
                $allCourseCodes = array();
                if (preg_match('/=== COURSE MENTION ===(.*?)=== END COURSE ===/s', $content, $cmatch)) {
                    $cInner = trim($cmatch[1]);
                    $cLines = explode("\n", $cInner);
                    foreach ($cLines as $line) {
                        $line = rtrim($line);
                        if (preg_match('/^\{(.+)\}$/', trim($line), $cm)) {
                            $allCourseCodes[] = strtoupper(trim($cm[1]));
                        }
                    }
                }
                $allCourseCodes = array_values(array_unique(array_filter($allCourseCodes)));

                // Filter to keep ONLY my course
                $myVisibleCourseCodes = array();
                if (in_array($my_course, $allCourseCodes)) {
                    $myVisibleCourseCodes[] = $my_course;
                }

                // Sort the visible section blocks
                uksort($myVisibleSectionBlocks, function($a, $b) {
                    preg_match('/(\d+)/', $a, $ma);
                    preg_match('/(\d+)/', $b, $mb);
                    $ya = isset($ma[1]) ? intval($ma[1]) : 0;
                    $yb = isset($mb[1]) ? intval($mb[1]) : 0;
                    if ($ya !== $yb) return $ya - $yb;
                    return strcmp($a, $b);
                });

                $cleanContent = preg_replace('/\n\n=== SECTION MENTION ===.*?=== END SECTION ===/s', '', $content);
                $cleanContent = preg_replace('/\n\n=== COURSE MENTION ===.*?=== END COURSE ===/s', '', $cleanContent);
                $cleanContent = preg_replace('/\n\n👥 Mentioned: .*/', '', $cleanContent);
                $cleanContent = trim($cleanContent);

                // Is this student mentioned?
                $userIsMentioned = false;

                if (in_array($my_full_name, $mentionedNamesRaw)) $userIsMentioned = true;
                if (!empty($myVisibleSectionBlocks)) $userIsMentioned = true;
                if (!empty($myVisibleCourseCodes)) $userIsMentioned = true;

                $isLong = strlen($cleanContent) > 500 || substr_count($cleanContent, "\n") > 10;

                $priorityColor = $post['priority'] == 'urgent' ? '#8e44ad' : ($post['priority'] == 'high' ? '#e74c3c' : ($post['priority'] == 'medium' ? '#f39c12' : '#3498db'));
                $priorityClass = 'badge-' . $post['priority'];
            ?>
            <div class="feed-post" id="post_<?php echo $post['id']; ?>" style="border-left: 4px solid <?php echo $userIsMentioned ? '#e74c3c' : $priorityColor; ?>;">
                <div class="post-header">
                    <div>
                        <span class="post-office">
                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($post['office_name']); ?>
                        </span>
                        <?php if ($userIsMentioned): ?>
                            <span class="mention-tag you">
                                <i class="fas fa-star"></i> You are mentioned
                            </span>
                        <?php endif; ?>
                        <span class="badge-priority <?php echo $priorityClass; ?>" style="margin-left: 8px;">
                            <?php echo strtoupper($post['priority']); ?>
                        </span>
                    </div>
                    <span class="post-datetime">
                        <i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($post['created_at'])); ?>
                        &nbsp;·&nbsp;
                        <i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($post['created_at'])); ?>
                    </span>
                </div>

                <div class="post-subject"><?php echo htmlspecialchars($post['title']); ?></div>

                <div class="post-content" id="postContent_<?php echo $post['id']; ?>">
                    <?php echo nl2br(htmlspecialchars($cleanContent)); ?>

                    <?php if (!empty($myVisibleSectionBlocks)): ?>
                        <?php foreach ($myVisibleSectionBlocks as $label => $names): ?>
                            <div class="section-separator-box">
                                <div class="sec-label"><i class="fas fa-users"></i> <?php echo htmlspecialchars($label); ?></div>
                                <div class="sec-names">
                                    <?php foreach ($names as $n):
                                        $isYou = ($n === $my_full_name);
                                    ?>
                                        <span class="section-mention-name<?php echo $isYou ? ' you' : ''; ?>">
                                            <?php echo htmlspecialchars($n); ?><?php echo $isYou ? ' (You)' : ''; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($myVisibleCourseCodes)): ?>
                        <div class="course-separator-box">
                            <div class="course-label">
                                <i class="fas fa-graduation-cap"></i> Whole Course Mentioned
                            </div>
                            <div style="margin-top:6px;">
                                <?php foreach ($myVisibleCourseCodes as $code): ?>
                                    <span class="course-mention-chip">
                                        <?php echo htmlspecialchars($code); ?> (Your Course)
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($myMentionOnly)): ?>
                        <div class="mention-section">
                            <div style="font-weight: bold; margin-bottom: 5px;">👥 You were individually mentioned:</div>
                            <?php foreach ($myMentionOnly as $name): ?>
                                <div class="mention-name you"><?php echo htmlspecialchars($name); ?> (You)</div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($isLong): ?>
                    <div class="see-more-btn" onclick="toggleContent(<?php echo $post['id']; ?>)">
                        <span id="seeMoreText_<?php echo $post['id']; ?>">See More</span>
                    </div>
                <?php endif; ?>

                <div class="post-meta">
                    <span><i class="fas fa-eye"></i> <?php echo $post['view_count']; ?> views</span>
                    <?php if ($post['user_viewed'] > 0): ?>
                        <span style="color: #27ae60;"><i class="fas fa-check-circle"></i> You have seen this</span>
                    <?php else: ?>
                        <span style="color: #999;"><i class="fas fa-clock"></i> New</span>
                    <?php endif; ?>
                    <?php if ($userIsMentioned): ?>
                        <span style="color: #e74c3c;"><i class="fas fa-bell"></i> You were mentioned</span>
                    <?php endif; ?>
                </div>

                <div class="post-actions">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="hide_post">
                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                        <button type="submit" class="btn-hide" onclick="return confirm('Hide this post from your feed?')">
                            <i class="fas fa-eye-slash"></i> Hide this post
                        </button>
                    </form>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-posts">
                <i class="fas fa-rss"></i>
                <h3>No Updates Yet</h3>
                <p>No advisories have been posted yet.</p>
                <p style="color: #999; font-size: 12px; margin-top: 10px;">
                    <i class="fas fa-info-circle"></i> When offices post advisories, they will appear here.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleContent(postId) {
    const content = document.getElementById('postContent_' + postId);
    const seeMoreText = document.getElementById('seeMoreText_' + postId);
    if (content.classList.contains('expanded')) {
        content.classList.remove('expanded');
        seeMoreText.textContent = 'See More';
    } else {
        content.classList.add('expanded');
        seeMoreText.textContent = 'See Less';
    }
}

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

function toggleNotifications() {
    var dropdown = document.getElementById('notificationDropdown');
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        dropdown.style.display = 'block';
    } else {
        dropdown.style.display = 'none';
    }
}

function openNotification(el, ev) {
    if (ev) ev.stopPropagation();

    var notifId = el.getAttribute('data-notif-id');
    var target = el.getAttribute('data-target') || '?view=feed';

    el.classList.remove('unread');
    el.classList.remove('mentioned');

    var badge = document.getElementById('notifBadge');
    if (badge) {
        var current = parseInt(badge.textContent.replace('+', '')) || 0;
        var newCount = Math.max(0, current - 1);
        if (newCount === 0) {
            badge.style.display = 'none';
        } else {
            badge.textContent = newCount > 9 ? '9+' : newCount;
        }
    }

    if (badge && badge.style.display === 'none') {
        var mr = document.querySelector('.notification-header .mark-read');
        if (mr) mr.style.display = 'none';
    }

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_one_notification_read&notification_id=' + encodeURIComponent(notifId)
    }).catch(function() {});

    window.location.href = target;
}

function markAllRead(ev) {
    if (ev) ev.stopPropagation();

    document.querySelectorAll('.notification-item').forEach(function(item) {
        item.classList.remove('unread');
        item.classList.remove('mentioned');
    });
    var badge = document.getElementById('notifBadge');
    if (badge) {
        badge.style.display = 'none';
        badge.textContent = '0';
    }

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_notifications_read'
    }).catch(function() {});
}

document.addEventListener('click', function(e) {
    var dropdown = document.getElementById('notificationDropdown');
    var bell = document.querySelector('.notification-bell');
    if (dropdown && bell) {
        if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    }
});

window.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash && window.location.hash.indexOf('#post_') === 0) {
        var target = document.querySelector(window.location.hash);
        if (target) {
            setTimeout(function() {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                target.classList.add('highlight-flash');
                setTimeout(function() { target.classList.remove('highlight-flash'); }, 1800);
            }, 150);
        }
    }
});
</script>

</body>
</html>