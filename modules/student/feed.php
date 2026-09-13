<?php
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();

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

// Get all feed posts - EXCLUDE hidden
$feed_posts = $conn->query("
    SELECT f.*, 
           (SELECT COUNT(*) FROM chmsu_feed_views WHERE feed_id = f.id) as view_count,
           (SELECT COUNT(*) FROM chmsu_feed_views WHERE feed_id = f.id AND student_id = '{$_SESSION['student']}') as user_viewed,
           (SELECT chmsu_full_name FROM chmsu_students_master WHERE chmsu_student_id = f.student_id) as mentioned_student_name,
           (SELECT COUNT(*) FROM chmsu_hidden_posts WHERE student_id = '{$_SESSION['student']}' AND post_id = f.id) as is_hidden
    FROM chmsu_feed f
    WHERE f.is_archived = 0 
    AND (SELECT COUNT(*) FROM chmsu_hidden_posts WHERE student_id = '{$_SESSION['student']}' AND post_id = f.id) = 0
    ORDER BY f.created_at DESC
");

// Mark feed posts as viewed
while ($post = $feed_posts->fetch_assoc()) {
    if ($post['user_viewed'] == 0) {
        $conn->query("INSERT INTO chmsu_feed_views (feed_id, student_id) VALUES ({$post['id']}, '{$_SESSION['student']}')");
    }
}
$feed_posts->data_seek(0);

// Mark notifications as read
$conn->query("UPDATE chmsu_notifications SET is_read = 1 WHERE user_type='student' AND user_id='{$_SESSION['student']}' AND type='feed'");

$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
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
            padding: 5px;
        }
        .notification-badge {
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
            top: 40px;
            background: white;
            min-width: 320px;
            max-width: 400px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            z-index: 1000;
            max-height: 400px;
            overflow-y: auto;
            padding: 0;
        }
        .notification-dropdown::-webkit-scrollbar { width: 4px; }
        .notification-dropdown::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .notification-dropdown::-webkit-scrollbar-thumb { background: #1b4d3e; border-radius: 4px; }
        .notification-header {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }
        .notification-header strong { color: #1b4d3e; font-size: 14px; }
        .notification-header .mark-read { background: transparent; border: none; color: #1b4d3e; font-size: 11px; cursor: pointer; text-decoration: underline; }
        .notification-item {
            padding: 10px 15px;
            border-bottom: 1px solid #f5f5f5;
            cursor: pointer;
            transition: background 0.2s;
        }
        .notification-item:hover { background: #f8f9fa; }
        .notification-item.unread { background: #f0f8ff; }
        .notification-item.mentioned { background: #fce8e6; border-left: 4px solid #e74c3c; }
        .notification-item .message { font-size: 12px; color: #333; }
        .notification-item .message .mention-icon { color: #e74c3c; font-weight: bold; }
        .notification-item .meta { font-size: 10px; color: #999; margin-top: 3px; display: flex; justify-content: space-between; }
        .notification-item .new-badge { background: #e74c3c; color: white; padding: 1px 8px; border-radius: 8px; font-size: 8px; }
        .notification-item .mentioned-badge { background: #e74c3c; color: white; padding: 1px 8px; border-radius: 8px; font-size: 8px; animation: pulse-alarm 1s infinite; }
        .notification-empty { text-align: center; padding: 30px 20px; color: #999; font-size: 13px; }
        .notification-empty i { font-size: 30px; display: block; margin-bottom: 10px; color: #ddd; }
        
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.3s;
            position: relative;
        }
        .feed-post:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .feed-post .post-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; flex-wrap: wrap; gap: 5px; }
        .feed-post .post-office { font-weight: bold; color: #1b4d3e; font-size: 14px; }
        .feed-post .post-subject {
            font-size: 20px;
            font-weight: bold;
            color: #1b4d3e;
            margin-bottom: 8px;
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
            max-height: 300px;
            overflow: hidden;
            position: relative;
        }
        .feed-post .post-content.expanded {
            max-height: none;
        }
        .feed-post .post-content .mention-section {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px dashed #ddd;
            font-size: 12px;
            color: #555;
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
        body.dark-mode .feed-post .post-content { background: #2c2c2c; color: #fff; }
        body.dark-mode .feed-post .post-meta { color: #aaa; }
        body.dark-mode .feed-post .post-office { color: #f1c40f; }
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
        body.dark-mode .feed-post .post-content .mention-section { border-top-color: #444; }
        body.dark-mode .see-more-btn { background: #2c2c2c; color: #f1c40f; }
        body.dark-mode .see-more-btn:hover { background: #3c3c3c; }
        body.dark-mode .mentioned-name-in-content { background: #1a3a2a; color: #f1c40f; }
        body.dark-mode .mentioned-name-in-content.you { color: #e74c3c; background: #3a1a1a; }
        
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
                <span class="notification-badge <?php echo $mentioned_notifications > 0 ? 'mentioned' : ''; ?>">
                    <?php echo $unread_notifications > 9 ? '9+' : $unread_notifications; ?>
                </span>
            <?php endif; ?>
        </button>
        
        <div class="notification-dropdown" id="notificationDropdown">
            <div class="notification-header">
                <strong><i class="fas fa-bell"></i> Notifications</strong>
                <?php if ($unread_notifications > 0): ?>
                    <button class="mark-read" onclick="markAllRead()">Mark all read</button>
                <?php endif; ?>
            </div>
            
            <?php if ($latest_notifications && $latest_notifications->num_rows > 0): ?>
                <?php while ($notif = $latest_notifications->fetch_assoc()): 
                    $isMentioned = strpos($notif['title'], 'mentioned') !== false;
                ?>
                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?> <?php echo $isMentioned && !$notif['is_read'] ? 'mentioned' : ''; ?>" onclick="window.location.href='<?php echo $notif['link'] ?: '?view=home'; ?>'">
                        <div class="message">
                            <?php if ($isMentioned): ?>
                                <span class="mention-icon">🔔</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($notif['message']); ?>
                        </div>
                        <div class="meta">
                            <span><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                            <?php if (!$notif['is_read']): ?>
                                <?php if ($isMentioned): ?>
                                    <span class="mentioned-badge">🔔 MENTIONED</span>
                                <?php else: ?>
                                    <span class="new-badge">NEW</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
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
            <li style="border-bottom: none; padding: 8px 12px; color: #dddddd; font-size: 10px;">OFFICES</li>
            <?php
            $offices = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
            while ($office = $offices->fetch_assoc()):
            ?>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=office&office=<?php echo urlencode($office['office_name']); ?>">
                    <span class="office-status-dot status-dot-pending"></span>
                    <span><?php echo htmlspecialchars($office['office_name']); ?></span>
                </a>
            </li>
            <?php endwhile; ?>
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
                // ============================================
                // EXTRACT MENTIONED NAMES FROM CONTENT
                // Now using ||| delimiter (since names contain commas)
                // ============================================
                $mentionedNames = array();
                $content = $post['content'];
                
                // Look for "👥 Mentioned:" pattern
                if (strpos($content, '👥 Mentioned:') !== false) {
                    $parts = explode('👥 Mentioned:', $content);
                    if (isset($parts[1])) {
                        $mentionLine = explode("\n", trim($parts[1]));
                        $mentionLine = $mentionLine[0];
                        $mentionLine = trim($mentionLine);
                        
                        if (!empty($mentionLine)) {
                            // Split by ||| delimiter to get each full student name
                            if (strpos($mentionLine, '|||') !== false) {
                                $mentionedNames = array_map('trim', explode('|||', $mentionLine));
                            } else {
                                // Fallback: split by comma but keep full names
                                $temp = array_map('trim', explode(',', $mentionLine));
                                $mentionedNames = array();
                                for ($i = 0; $i < count($temp); $i += 2) {
                                    if (isset($temp[$i+1])) {
                                        $mentionedNames[] = $temp[$i] . ', ' . $temp[$i+1];
                                    } else {
                                        $mentionedNames[] = $temp[$i];
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Remove duplicates
                $mentionedNames = array_values(array_unique($mentionedNames));
                
                // Clean content - remove the mention line and any dashes
                $cleanContent = $content;
                if (strpos($content, '👥 Mentioned:') !== false) {
                    $parts = explode('👥 Mentioned:', $content);
                    $cleanContent = trim($parts[0]);
                    $cleanContent = preg_replace('/---\s*$/', '', $cleanContent);
                }
                $cleanContent = preg_replace("/\n\s*\n/", "\n\n", $cleanContent);
                
                $userIsMentioned = in_array($master['chmsu_full_name'], $mentionedNames);
                $isLong = strlen($cleanContent) > 500 || substr_count($cleanContent, "\n") > 10;
                
                $priorityColor = $post['priority'] == 'urgent' ? '#8e44ad' : ($post['priority'] == 'high' ? '#e74c3c' : ($post['priority'] == 'medium' ? '#f39c12' : '#3498db'));
                $priorityClass = 'badge-' . $post['priority'];
            ?>
            <div class="feed-post" style="border-left: 4px solid <?php echo $userIsMentioned ? '#e74c3c' : $priorityColor; ?>;">
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
                    <span style="font-size: 10px; color: #999;">
                        <i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y H:i', strtotime($post['created_at'])); ?>
                    </span>
                </div>
                
                <div class="post-subject"><?php echo htmlspecialchars($post['title']); ?></div>
                
                <div class="post-content" id="postContent_<?php echo $post['id']; ?>">
                    <?php 
                    $displayContent = nl2br(htmlspecialchars(trim($cleanContent)));
                    
                    foreach ($mentionedNames as $name) {
                        $isYou = $name == $master['chmsu_full_name'];
                        $highlightClass = $isYou ? 'mentioned-name-in-content you' : 'mentioned-name-in-content';
                        $escapedName = htmlspecialchars($name);
                        if (strpos($displayContent, $escapedName) !== false) {
                            $displayContent = str_replace(
                                $escapedName, 
                                '<span class="' . $highlightClass . '">' . $escapedName . '</span>', 
                                $displayContent
                            );
                        }
                    }
                    
                    echo $displayContent;
                    ?>
                    
                    <?php if (!empty($mentionedNames)): ?>
                        <div class="mention-section">
                            <div style="font-weight: bold; margin-bottom: 5px;">👥 Mentioned:</div>
                            <?php foreach ($mentionedNames as $name): ?>
                                <div style="display: block; font-size: 12px; color: #555; padding: 2px 0;">
                                    <?php 
                                    if ($name == $master['chmsu_full_name']) {
                                        echo '<span style="font-weight: bold; color: #e74c3c; background: #fce8e6; padding: 1px 6px; border-radius: 4px;">' . htmlspecialchars($name) . ' (You)</span>';
                                    } else {
                                        echo '<span style="font-weight: bold; color: #1b4d3e; background: #e8f0fe; padding: 1px 6px; border-radius: 4px;">' . htmlspecialchars($name) . '</span>';
                                    }
                                    ?>
                                </div>
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
    var bell = document.querySelector('.notification-bell');
    if (dropdown && bell) {
        if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    }
});
</script>

</body>
</html>