<?php
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

$current_year_result = $conn->query("SELECT chmsu_year FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'");
$current_student_year = '1';
if ($current_year_result && $current_year_result->num_rows > 0) {
    $current_student_year = $current_year_result->fetch_assoc()['chmsu_year'];
}

// ============================================
// NEW: GET CURRENT SEMESTER
// Dashboard is now semester-aware — every clearance
// read is scoped to the currently active semester.
// When the admin changes semester, all stats reset
// automatically here.
// ============================================
$currentSem = null;
$currentSemId = 0;
$currentSemName = 'No Active Semester';
$currentSemSY = '';
$hasSemCols = false;

$semCheckCol = $conn->query("SHOW COLUMNS FROM chmsu_clearance_status LIKE 'semester_id'");
if ($semCheckCol && $semCheckCol->num_rows > 0) $hasSemCols = true;

$currentSemRow = $conn->query("SELECT * FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
if ($currentSemRow) {
    $currentSem = $currentSemRow;
    $currentSemId = intval($currentSemRow['id']);
    $currentSemName = $currentSemRow['semester_name'];
    $currentSemSY = $currentSemRow['school_year'] ?? '';
}

// Get all offices with clearance status scoped to CURRENT semester (sorted: Library, Accounting, OSA, Dean, Registrar)
$offices_result = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY FIELD(LOWER(TRIM(office_name)), 'library', 'accounting', 'osa', 'dean', 'registrar'), office_name ASC");
$offices = [];
$officeStatuses = [];
$totalOffices = 0;
$approvedCount = 0;
$declinedCount = 0;
$pendingCount = 0;

$semFilter = $hasSemCols ? " AND semester_id = $currentSemId " : "";

while ($office_row = $offices_result->fetch_assoc()) {
    $office = $office_row['office_name'];
    $offices[] = $office;
    $totalOffices++;

    $office_esc = $conn->real_escape_string($office);
    $student_esc = $conn->real_escape_string($_SESSION['student']);

    $clearanceQ = $conn->query("SELECT status FROM chmsu_clearance_status
                                WHERE student_id='$student_esc'
                                AND office_name='$office_esc'
                                $semFilter
                                LIMIT 1");

    if ($clearanceQ && $clearanceQ->num_rows > 0) {
        $row = $clearanceQ->fetch_assoc();
        if ($row['status'] == 'clear') {
            $officeStatuses[$office] = 'Approved';
            $approvedCount++;
        } elseif ($row['status'] == 'unclear') {
            $officeStatuses[$office] = 'Declined';
            $declinedCount++;
        } else {
            $officeStatuses[$office] = 'Pending';
            $pendingCount++;
        }
    } else {
        $officeStatuses[$office] = 'Pending';
        $pendingCount++;
    }
}

$allApproved = ($approvedCount == $totalOffices && $totalOffices > 0);
$progressPercent = ($totalOffices > 0) ? round(($approvedCount / $totalOffices) * 100) : 0;

$student_email = !empty($student['email']) ? $student['email'] : (!empty($master['email']) ? $master['email'] : null);
$has_email = !empty($student_email);

if (isset($_GET['gmail_connected']) && $_GET['gmail_connected'] == 1 && $has_email) {
    $success = "✅ Gmail connected successfully!";
}

$student_esc = $conn->real_escape_string($_SESSION['student']);

$unread_notifications = $conn->query("
    SELECT COUNT(*) as c FROM chmsu_notifications
    WHERE user_type='student'
    AND user_id='$student_esc'
    AND is_read=0
")->fetch_assoc()['c'];

$latest_notifications = $conn->query("
    SELECT * FROM chmsu_notifications
    WHERE user_type='student'
    AND user_id='$student_esc'
    ORDER BY created_at DESC
    LIMIT 5
");

$feed_posts = $conn->query("
    SELECT f.*,
           (SELECT COUNT(*) FROM chmsu_feed_views WHERE feed_id = f.id) as view_count,
           (SELECT COUNT(*) FROM chmsu_feed_views WHERE feed_id = f.id AND student_id = '$student_esc') as user_viewed
    FROM chmsu_feed f
    ORDER BY f.created_at DESC
    LIMIT 5
");

$unreadFeed = $conn->query("SELECT COUNT(*) as c FROM chmsu_notifications
                            WHERE user_type='student'
                            AND user_id='$student_esc'
                            AND type='feed'
                            AND is_read=0")->fetch_assoc()['c'];

$chat_unread_count = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='$student_esc'")->fetch_assoc()['total'] ?: 0;

// ============================================
// ADDITIONAL USEFUL DASHBOARD DATA
// ============================================
$recent_chats = $conn->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM chmsu_chat_messages
            WHERE ((sender_type='office' AND receiver_id='$student_esc' AND sender_id=c.office_name)
                OR (sender_type='student' AND sender_id='$student_esc' AND receiver_id=c.office_name))) as total_msgs
    FROM chmsu_chat_conversations c
    WHERE c.student_id='$student_esc'
    ORDER BY c.last_message_time DESC
    LIMIT 3
");

$attention_offices = [];
foreach ($officeStatuses as $office => $status) {
    if ($status === 'Declined') {
        $attention_offices[] = ['office' => $office, 'status' => 'Declined', 'type' => 'danger'];
    }
}
foreach ($officeStatuses as $office => $status) {
    if ($status === 'Pending') {
        $attention_offices[] = ['office' => $office, 'status' => 'Pending', 'type' => 'warning'];
    }
}

$latest_feed = null;
$feed_posts_temp = $conn->query("
    SELECT f.*,
           (SELECT COUNT(*) FROM chmsu_feed_views WHERE feed_id = f.id) as view_count
    FROM chmsu_feed f
    ORDER BY f.created_at DESC
    LIMIT 1
");
if ($feed_posts_temp && $feed_posts_temp->num_rows > 0) {
    $latest_feed = $feed_posts_temp->fetch_assoc();
}
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

        /* ============================================ */
        /* NEW: CURRENT SEMESTER BANNER                 */
        /* ============================================ */
        .semester-banner {
            background: linear-gradient(135deg, #1b4d3e 0%, #2d6a4f 100%);
            color: white;
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: 0 4px 14px rgba(27, 77, 62, 0.25);
            flex-wrap: wrap;
        }
        .semester-banner .banner-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(241, 196, 15, 0.2);
            color: #f1c40f;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }
        .semester-banner .banner-body { flex: 1; min-width: 200px; }
        .semester-banner .banner-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #f1c40f;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .semester-banner .banner-title {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
        }
        .semester-banner .banner-sub {
            font-size: 12px;
            opacity: 0.85;
            margin-top: 4px;
        }
        .semester-banner .banner-badge {
            background: rgba(241, 196, 15, 0.2);
            color: #f1c40f;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .semester-banner.no-semester {
            background: linear-gradient(135deg, #7f8c8d 0%, #95a5a6 100%);
            box-shadow: 0 4px 14px rgba(0,0,0,0.15);
        }
        .semester-banner.no-semester .banner-icon {
            background: rgba(255,255,255,0.2);
            color: #fff;
        }
        .semester-banner.no-semester .banner-label { color: #fff3cd; }

        body.dark-mode .semester-banner {
            background: linear-gradient(135deg, #0f3b2f 0%, #1b4d3e 100%);
        }
        body.dark-mode .semester-banner.no-semester {
            background: linear-gradient(135deg, #2c2c2c 0%, #3c3c3c 100%);
        }

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

        /* ============================================ */
        /* QUICK STATS GRID                             */
        /* ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        .stat-icon.green  { background: #e8f5e9; color: #2e7d32; }
        .stat-icon.orange { background: #fff3e0; color: #ef6c00; }
        .stat-icon.red    { background: #fce4ec; color: #c62828; }
        .stat-icon.blue   { background: #e3f2fd; color: #1565c0; }
        .stat-info { flex: 1; min-width: 0; }
        .stat-value { font-size: 22px; font-weight: 700; color: #1b4d3e; line-height: 1; }
        .stat-label { font-size: 11px; color: #666; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px; }

        /* ============================================ */
        /* OFFICES STATUS LIST                          */
        /* ============================================ */
        .office-list-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .office-list-item:last-child { border-bottom: none; }
        .office-name-text {
            flex: 1;
            font-size: 13px;
            font-weight: 500;
            color: #333;
        }
        .status-pill {
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-pill.approved { background: #d4edda; color: #155724; }
        .status-pill.pending  { background: #fff3cd; color: #856404; }
        .status-pill.declined { background: #f8d7da; color: #721c24; }

        .attention-item {
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }
        .attention-item.danger  { background: #fdecea; color: #721c24; border-left: 3px solid #dc3545; }
        .attention-item.warning { background: #fff8e1; color: #856404; border-left: 3px solid #f0ad4e; }

        .chat-preview {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
            align-items: center;
        }
        .chat-preview:last-child { border-bottom: none; }
        .chat-preview-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #1b4d3e;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 13px;
            flex-shrink: 0;
        }
        .chat-preview-body { flex: 1; min-width: 0; }
        .chat-preview-name {
            font-size: 13px;
            font-weight: 600;
            color: #1b4d3e;
            margin-bottom: 2px;
        }
        .chat-preview-msg {
            font-size: 12px;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .chat-preview-badge {
            background: #e74c3c;
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
            flex-shrink: 0;
        }

        .empty-state {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 12px;
        }
        .empty-state i {
            font-size: 32px;
            display: block;
            margin-bottom: 5px;
            color: #ddd;
        }

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

        #notificationDropdown::-webkit-scrollbar { width: 4px; }
        #notificationDropdown::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        #notificationDropdown::-webkit-scrollbar-thumb { background: #1b4d3e; border-radius: 4px; }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

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
        body.dark-mode .email-connection-card { background: #1a1a1a; border-color: #333; }
        body.dark-mode .email-connection-header { background: #2c2c2c; border-color: #333; }
        body.dark-mode .email-connection-title { color: #f1c40f; }
        body.dark-mode .email-address { color: #fff; }
        body.dark-mode .email-status { color: #ccc; }
        body.dark-mode .email-disconnected-icon { background: #2c2c2c; color: #f1c40f; }
        body.dark-mode .email-disconnected-text h4 { color: #fff; }
        body.dark-mode .email-disconnected-text p { color: #ccc; }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .info-bar,
        body.dark-mode .content-card,
        body.dark-mode .email-connection-card,
        body.dark-mode .stat-box { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .stat-value { color: #f1c40f; }
        body.dark-mode .stat-label { color: #b0b3b8; }
        body.dark-mode .stat-icon.green  { background: #1b3a24; color: #6fcf97; }
        body.dark-mode .stat-icon.orange { background: #3a2c1a; color: #f0ad4e; }
        body.dark-mode .stat-icon.red    { background: #3a1a1a; color: #e57373; }
        body.dark-mode .stat-icon.blue   { background: #1a2c3a; color: #64b5f6; }
        body.dark-mode .office-name-text { color: #e4e6eb; }
        body.dark-mode .office-list-item { border-color: #3e4042; }
        body.dark-mode .chat-preview { border-color: #3e4042; }
        body.dark-mode .chat-preview-name { color: #f1c40f; }
        body.dark-mode .chat-preview-msg { color: #b0b3b8; }
        body.dark-mode .attention-item.danger  { background: #3a1a1a; color: #ffb3b3; }
        body.dark-mode .attention-item.warning { background: #3a2c1a; color: #f0ad4e; }
        body.dark-mode .empty-state { color: #777; }

        @media (max-width: 768px) {
            .dark-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .semester-banner { flex-direction: column; align-items: flex-start; }
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

    <div style="position: relative; margin-right: 10px;">
        <button onclick="toggleNotifications()" style="background: transparent; border: none; color: white; font-size: 20px; cursor: pointer; position: relative; padding: 5px;">
            <i class="fas fa-bell"></i>
            <?php if ($unread_notifications > 0): ?>
                <span style="position: absolute; top: -5px; right: -5px; background: #e74c3c; color: white; border-radius: 50%; padding: 2px 6px; font-size: 9px; min-width: 18px; text-align: center; animation: pulse 1.5s infinite;">
                    <?php echo $unread_notifications > 9 ? '9+' : $unread_notifications; ?>
                </span>
            <?php endif; ?>
        </button>

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
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=feed">
                    <i class="fas fa-rss"></i> Updates Feed
                    <?php if ($unreadFeed > 0): ?>
                        <span class="sidebar-badge"><?php echo $unreadFeed; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=chat">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php if ($chat_unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $chat_unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=office">
                    <i class="fas fa-clipboard-check"></i> OFFICES
                </a>
            </li>
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

        <!-- ============================================ -->
        <!-- NEW: CURRENT SEMESTER BANNER                 -->
        <!-- Always shows what semester is now            -->
        <!-- ============================================ -->
        <div class="semester-banner <?php echo $currentSem ? '' : 'no-semester'; ?>">
            <div class="banner-icon">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="banner-body">
                <div class="banner-label"><i class="fas fa-circle" style="font-size: 6px; vertical-align: middle;"></i> Current Semester</div>
                <div class="banner-title"><?php echo htmlspecialchars($currentSemName); ?></div>
                <div class="banner-sub">
                    <?php if ($currentSem): ?>
                        <?php if (!empty($currentSemSY)): ?>
                            <i class="fas fa-graduation-cap"></i> School Year <?php echo htmlspecialchars($currentSemSY); ?>
                            &nbsp;·&nbsp;
                        <?php endif; ?>
                        <i class="fas fa-check-circle"></i> Active clearance period
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle"></i> No semester is currently active. Please contact the administrator.
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($currentSem): ?>
                <span class="banner-badge">
                    <i class="fas fa-play-circle"></i> ACTIVE NOW
                </span>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- QUICK STATS (scoped to current semester)     -->
        <!-- ============================================ -->
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo $approvedCount; ?></div>
                    <div class="stat-label">Approved Offices</div>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo $pendingCount; ?></div>
                    <div class="stat-label">Pending Offices</div>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo $declinedCount; ?></div>
                    <div class="stat-label">Declined Offices</div>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon blue"><i class="fas fa-comment-dots"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo intval($chat_unread_count); ?></div>
                    <div class="stat-label">Unread Messages</div>
                </div>
            </div>
        </div>

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
                                <i class="fas fa-sync-alt"></i> Switch Gmail
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

        <!-- ============================================ -->
        <!-- PROGRESS + ATTENTION                         -->
        <!-- ============================================ -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div class="content-card" style="margin-bottom: 0;">
                <div class="content-card-header">
                    <span><i class="fas fa-chart-line"></i> Clearance Progress</span>
                    <span><?php echo $approvedCount; ?> / <?php echo $totalOffices; ?> Approved</span>
                </div>
                <div class="content-card-body">
                    <div style="margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px;">
                            <span>Overall Progress</span>
                            <strong style="color: #1b4d3e;"><?php echo $progressPercent; ?>%</strong>
                        </div>
                        <div style="width: 100%; height: 12px; background: #e0e0e0; border-radius: 6px; overflow: hidden;">
                            <div style="width: <?php echo $progressPercent; ?>%; height: 100%; background: #27ae60; border-radius: 6px; transition: width 0.5s;"></div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 15px; font-size: 12px; flex-wrap: wrap;">
                        <div><span style="color: #27ae60;">●</span> Approved: <strong><?php echo $approvedCount; ?></strong></div>
                        <div><span style="color: #f39c12;">●</span> Pending: <strong><?php echo $pendingCount; ?></strong></div>
                        <div><span style="color: #e74c3c;">●</span> Declined: <strong><?php echo $declinedCount; ?></strong></div>
                    </div>
                </div>
            </div>

            <div class="content-card" style="margin-bottom: 0;">
                <div class="content-card-header">
                    <span><i class="fas fa-exclamation-triangle"></i> Needs Your Attention</span>
                    <span style="font-size: 12px;"><?php echo count($attention_offices); ?> item<?php echo count($attention_offices) !== 1 ? 's' : ''; ?></span>
                </div>
                <div class="content-card-body" style="max-height: 200px; overflow-y: auto;">
                    <?php if (empty($attention_offices)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            All clear! No pending items.
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($attention_offices, 0, 5) as $item): ?>
                            <div class="attention-item <?php echo $item['type']; ?>">
                                <?php if ($item['type'] === 'danger'): ?>
                                    <i class="fas fa-times-circle"></i>
                                    <strong><?php echo htmlspecialchars($item['office']); ?></strong> — <?php echo $item['status']; ?>
                                <?php else: ?>
                                    <i class="fas fa-clock"></i>
                                    <strong><?php echo htmlspecialchars($item['office']); ?></strong> — <?php echo $item['status']; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- FULLY CLEARED BANNER                         -->
        <!-- ============================================ -->
        <?php if ($allApproved): ?>
        <div class="content-card" style="margin-bottom: 20px; border: 2px solid #27ae60; background: #27ae60;">
            <div class="content-card-body" style="text-align: center; padding: 30px 20px; color: white;">
                <div style="font-size: 48px; margin-bottom: 10px;">🎉</div>
                <h3 style="margin-bottom: 10px; font-size: 22px;">You are fully cleared!</h3>
                <p>All offices have approved your clearance for <strong><?php echo htmlspecialchars($currentSemName); ?></strong>.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- OFFICE STATUS LIST (scoped to current sem)   -->
        <!-- ============================================ -->
        <div class="content-card" style="margin-bottom: 20px;">
            <div class="content-card-header">
                <span><i class="fas fa-building"></i> Office Clearance Status — <?php echo htmlspecialchars($currentSemName); ?></span>
                <span style="font-size: 12px;"><?php echo $totalOffices; ?> office<?php echo $totalOffices !== 1 ? 's' : ''; ?></span>
            </div>
            <div class="content-card-body">
                <?php if (empty($officeStatuses)): ?>
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        No offices configured yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($officeStatuses as $office => $status):
                        $pillClass = $status === 'Approved' ? 'approved' : ($status === 'Declined' ? 'declined' : 'pending');
                    ?>
                        <div class="office-list-item">
                            <div class="office-name-text"><?php echo htmlspecialchars($office); ?></div>
                            <span class="status-pill <?php echo $pillClass; ?>"><?php echo $status; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- RECENT CHATS                                 -->
        <!-- ============================================ -->
        <?php if ($recent_chats && $recent_chats->num_rows > 0): ?>
        <div class="content-card" style="margin-bottom: 20px;">
            <div class="content-card-header">
                <span><i class="fas fa-comments"></i> Recent Messages</span>
                <a href="?view=chat" style="font-size: 12px; color: #1b4d3e;">View All →</a>
            </div>
            <div class="content-card-body">
                <?php while ($chat = $recent_chats->fetch_assoc()):
                    $officeName = $chat['office_name'];
                    $initial = strtoupper(substr($officeName, 0, 1));
                    $lastMsg = $chat['last_message'] ?: 'No message yet';
                ?>
                    <div class="chat-preview">
                        <div class="chat-preview-avatar"><?php echo $initial; ?></div>
                        <div class="chat-preview-body">
                            <div class="chat-preview-name"><?php echo htmlspecialchars($officeName); ?></div>
                            <div class="chat-preview-msg"><?php echo htmlspecialchars(substr($lastMsg, 0, 60)); ?></div>
                        </div>
                        <?php if (intval($chat['student_unread']) > 0): ?>
                            <span class="chat-preview-badge"><?php echo intval($chat['student_unread']); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>

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
                                    <span style="font-size: 10px; color: #999; margin-left: 8px;"><?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                                </div>
                                <div style="font-size: 14px; font-weight: bold; color: #1b4d3e; margin-top: 2px;">
                                    <?php echo htmlspecialchars($post['title']); ?>
                                </div>
                                <div style="font-size: 12px; color: #555; margin-top: 3px; line-height: 1.4;">
                                    <?php echo substr(htmlspecialchars($post['content']), 0, 100); ?>...
                                </div>
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

window.onclick = function(e) {
    const modal = document.getElementById('emailModal');
    if (e.target === modal) {
        closeEmailModal();
    }
};

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEmailModal();
    }
});

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

document.addEventListener('click', function(e) {
    var dropdown = document.getElementById('notificationDropdown');
    var bell = document.querySelector('[onclick="toggleNotifications()"]');
    if (dropdown && bell) {
        if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    }
});
</script>
</body>
</html>