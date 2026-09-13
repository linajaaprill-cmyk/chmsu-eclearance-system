<?php
// modules/student/chat.php
// Student Chat Interface - Messenger-like UI for students to chat with offices

$student_id = $_SESSION['student'];
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

// ============================================
// CREATE CHAT TABLES IF NOT EXISTS
// ============================================
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_chat_messages (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('student', 'office') NOT NULL,
    sender_id VARCHAR(50) NOT NULL,
    receiver_id VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    file_name VARCHAR(255) DEFAULT NULL,
    file_type VARCHAR(50) DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sender (sender_type, sender_id),
    INDEX idx_receiver (receiver_id),
    INDEX idx_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS chmsu_chat_conversations (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    office_name VARCHAR(100) NOT NULL,
    last_message TEXT,
    last_message_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    student_unread INT(11) DEFAULT 0,
    office_unread INT(11) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_conversation (student_id, office_name),
    INDEX idx_student (student_id),
    INDEX idx_office (office_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ============================================
// HANDLE CHAT ACTIONS (AJAX)
// ============================================
if (isset($_POST['action'])) {
    // Send chat message (Student to Office)
    if ($_POST['action'] == 'send_chat_message' && isset($_SESSION['student'])) {
        $office_name = sanitize($_POST['office_name']);
        $message = sanitize($_POST['message']);
        $student_id = $_SESSION['student'];
        
        $file_path = '';
        $file_name = '';
        $file_type = '';
        
        if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] == 0) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
            $filename = $_FILES['chat_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed) && $_FILES['chat_file']['size'] <= 10 * 1024 * 1024) {
                $newname = UPLOAD_DIR . 'chat_' . time() . '_' . basename($filename);
                if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $newname)) {
                    $file_path = $newname;
                    $file_name = $filename;
                    $file_type = $ext;
                }
            }
        }
        
        $conn->query("INSERT INTO chmsu_chat_messages (sender_type, sender_id, receiver_id, message, file_path, file_name, file_type) 
                      VALUES ('student', '$student_id', '$office_name', '$message', '$file_path', '$file_name', '$file_type')");
        
        // Update conversation - increment office_unread so office sees notification
        $conn->query("INSERT INTO chmsu_chat_conversations (student_id, office_name, last_message, last_message_time, office_unread) 
                      VALUES ('$student_id', '$office_name', '$message', NOW(), 1)
                      ON DUPLICATE KEY UPDATE 
                      last_message = '$message', 
                      last_message_time = NOW(),
                      office_unread = office_unread + 1");
        
        logActivity($conn, $student_id, 'student', "Sent chat message to office: $office_name");
        
        // Return success response
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Get chat messages (AJAX)
    if ($_POST['action'] == 'get_chat_messages' && isset($_SESSION['student'])) {
        $office_name = sanitize($_POST['office_name']);
        $student_id = $_SESSION['student'];
        
        $messages = $conn->query("SELECT * FROM chmsu_chat_messages 
                                  WHERE (sender_type='student' AND sender_id='$student_id' AND receiver_id='$office_name')
                                  OR (sender_type='office' AND sender_id='$office_name' AND receiver_id='$student_id')
                                  ORDER BY created_at ASC");
        
        $result = [];
        while ($msg = $messages->fetch_assoc()) {
            $result[] = [
                'id' => $msg['id'],
                'sender_type' => $msg['sender_type'],
                'message' => $msg['message'],
                'file_path' => $msg['file_path'],
                'file_name' => $msg['file_name'],
                'file_type' => $msg['file_type'],
                'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
            ];
        }
        echo json_encode($result);
        exit;
    }
    
    // Mark messages as read (Student)
    if ($_POST['action'] == 'mark_chat_read' && isset($_SESSION['student'])) {
        $office_name = sanitize($_POST['office_name']);
        $student_id = $_SESSION['student'];
        
        $conn->query("UPDATE chmsu_chat_messages SET is_read = 1 
                      WHERE sender_type='office' AND sender_id='$office_name' AND receiver_id='$student_id'");
        $conn->query("UPDATE chmsu_chat_conversations SET student_unread = 0 
                      WHERE student_id='$student_id' AND office_name='$office_name'");
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Clear chat history (Student)
    if ($_POST['action'] == 'clear_chat' && isset($_SESSION['student'])) {
        $office_name = sanitize($_POST['office_name']);
        $student_id = $_SESSION['student'];
        
        $conn->query("DELETE FROM chmsu_chat_messages 
                      WHERE (sender_type='student' AND sender_id='$student_id' AND receiver_id='$office_name')
                      OR (sender_type='office' AND sender_id='$office_name' AND receiver_id='$student_id')");
        $conn->query("DELETE FROM chmsu_chat_conversations 
                      WHERE student_id='$student_id' AND office_name='$office_name'");
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Get unread count (Student)
    if ($_POST['action'] == 'get_unread_count' && isset($_SESSION['student'])) {
        $student_id = $_SESSION['student'];
        $total = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='$student_id'")->fetch_assoc()['total'];
        echo json_encode(['total' => $total ?: 0]);
        exit;
    }
}

// ============================================
// GET UNREAD CHAT COUNT FOR HEADER
// ============================================
$total_unread_chat = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='$student_id'")->fetch_assoc()['total'] ?: 0;
$chat_unread = $total_unread_chat;

// ============================================
// GET ALL OFFICES FOR CHAT LIST
// ============================================
$offices = $conn->query("SELECT DISTINCT 
                           o.office_name as name,
                           (SELECT student_unread FROM chmsu_chat_conversations 
                            WHERE student_id = '$student_id' AND office_name = o.office_name) as unread,
                           (SELECT last_message FROM chmsu_chat_conversations 
                            WHERE student_id = '$student_id' AND office_name = o.office_name) as last_message,
                           (SELECT last_message_time FROM chmsu_chat_conversations 
                            WHERE student_id = '$student_id' AND office_name = o.office_name) as last_time
                          FROM chmsu_offices o
                          ORDER BY o.office_name ASC");
$allOffices = [];
while ($o = $offices->fetch_assoc()) {
    $allOffices[] = $o;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Messages</title>
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
        .chat-header-icon:hover { color: #f1c40f; transform: scale(1.1); }
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
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
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
        
        .chat-container {
            display: flex;
            height: calc(100vh - 200px);
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .chat-sidebar {
            width: 280px;
            background: #f8f9fa;
            border-right: 1px solid #ddd;
            overflow-y: auto;
            flex-shrink: 0;
        }
        .chat-sidebar-header {
            padding: 15px;
            background: #1b4d3e;
            color: white;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #2d6a4f;
        }
        .chat-sidebar-header .badge {
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 11px;
        }
        
        .chat-search {
            padding: 10px 15px;
            background: white;
            border-bottom: 1px solid #eee;
        }
        .chat-search input {
            width: 100%;
            padding: 8px 14px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 13px;
            outline: none;
            background: #f8f9fa;
        }
        .chat-search input:focus { border-color: #1b4d3e; background: white; }
        
        .chat-office-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-office-item:hover { background: #e8f0fe; }
        .chat-office-item.active {
            background: #e8f0fe;
            border-left: 3px solid #1b4d3e;
        }
        .chat-office-item .office-name { font-weight: 500; font-size: 13px; }
        .chat-office-item .office-last {
            font-size: 11px;
            color: #888;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .chat-office-item .unread-badge {
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 10px;
            min-width: 20px;
            text-align: center;
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
        }
        .chat-area-header {
            padding: 12px 20px;
            border-bottom: 1px solid #ddd;
            background: #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-area-header .chat-with { font-weight: bold; font-size: 14px; color: #1b4d3e; }
        .chat-area-header .chat-status { font-size: 11px; color: #888; }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 15px 20px;
            background: #fafafa;
        }
        .chat-messages .message {
            margin-bottom: 10px;
            max-width: 70%;
            clear: both;
        }
        .chat-messages .message.sent { float: right; }
        .chat-messages .message.received { float: left; }
        .chat-messages .message .bubble {
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 13px;
            word-wrap: break-word;
            position: relative;
        }
        .chat-messages .message.sent .bubble {
            background: #1b4d3e;
            color: white;
            border-bottom-right-radius: 4px;
        }
        .chat-messages .message.received .bubble {
            background: white;
            color: #333;
            border-bottom-left-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .chat-messages .message .bubble .file-attachment {
            display: block;
            margin-top: 5px;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
        }
        .chat-messages .message.sent .bubble .file-attachment {
            background: rgba(255,255,255,0.15);
            color: #fff;
        }
        .chat-messages .message.received .bubble .file-attachment {
            background: #f0f0f0;
            color: #1b4d3e;
        }
        .chat-messages .message .time {
            font-size: 9px;
            opacity: 0.6;
            margin-top: 4px;
            display: block;
        }
        .chat-messages .message.received .time { color: #888; }
        .chat-messages .message.sent .time { color: #ddd; text-align: right; }
        
        .chat-messages .typing-indicator {
            display: none;
            padding: 10px 14px;
            background: white;
            border-radius: 12px;
            float: left;
            clear: both;
            margin-bottom: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .chat-messages .typing-indicator span {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #999;
            border-radius: 50%;
            margin: 0 2px;
            animation: typing 1.4s infinite both;
        }
        .chat-messages .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .chat-messages .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typing { 0%, 60%, 100% { transform: translateY(0); } 30% { transform: translateY(-6px); } }
        
        .chat-input-area {
            padding: 12px 20px;
            border-top: 1px solid #ddd;
            background: white;
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .chat-input-area input[type="text"] {
            flex: 1;
            padding: 8px 14px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 13px;
            outline: none;
        }
        .chat-input-area input[type="text"]:focus { border-color: #1b4d3e; }
        .chat-input-area .chat-btn {
            background: #1b4d3e;
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .chat-input-area .chat-btn:hover { background: #2d6a4f; }
        .chat-input-area .file-btn {
            background: #7f8c8d;
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            position: relative;
        }
        .chat-input-area .file-btn:hover { background: #6c7a7d; }
        .chat-input-area .file-btn input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .chat-input-area .clear-btn {
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .chat-input-area .clear-btn:hover { background: #c0392b; }
        
        .quick-replies {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            padding: 8px 20px;
            background: #f8f9fa;
            border-top: 1px solid #eee;
        }
        .quick-replies .qr-btn {
            background: #e8f0fe;
            color: #1b4d3e;
            border: 1px solid #1b4d3e;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .quick-replies .qr-btn:hover { background: #1b4d3e; color: white; }
        
        .no-chat-selected {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #999;
            padding: 40px;
        }
        .no-chat-selected i { font-size: 64px; margin-bottom: 15px; color: #ddd; }
        
        .storage-info { padding: 12px 20px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px; }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .chat-container { background: #1a1a1a; border-color: #333; }
        body.dark-mode .chat-sidebar { background: #1a1a1a; border-color: #333; }
        body.dark-mode .chat-sidebar-header { background: #1b4d3e; }
        body.dark-mode .chat-search { background: #1a1a1a; border-color: #333; }
        body.dark-mode .chat-search input { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .chat-office-item { border-bottom-color: #333; color: #fff; }
        body.dark-mode .chat-office-item:hover { background: #2c2c2c; }
        body.dark-mode .chat-office-item.active { background: #2c2c2c; }
        body.dark-mode .chat-area { background: #1a1a1a; }
        body.dark-mode .chat-area-header { background: #2c2c2c; border-color: #333; color: #fff; }
        body.dark-mode .chat-messages { background: #1a1a1a; }
        body.dark-mode .chat-messages .message.received .bubble { background: #2c2c2c; color: #fff; }
        body.dark-mode .chat-input-area { background: #1a1a1a; border-color: #333; }
        body.dark-mode .chat-input-area input[type="text"] { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .quick-replies { background: #2c2c2c; border-color: #333; }
        body.dark-mode .quick-replies .qr-btn { background: #2c2c2c; color: #f1c40f; border-color: #f1c40f; }
        body.dark-mode .quick-replies .qr-btn:hover { background: #f1c40f; color: #000; }
        body.dark-mode .info-bar { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .no-chat-selected { color: #aaa; }
        body.dark-mode .no-chat-selected i { color: #444; }
        
        @media (max-width: 768px) {
            .dark-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .chat-container { flex-direction: column; height: auto; }
            .chat-sidebar { width: 100%; max-height: 300px; }
            .chat-area { min-height: 400px; }
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
        <p>CLEARANCE SYSTEM | Student Portal - Messages</p>
    </div>
    
    <a href="?view=chat" class="chat-header-icon" title="Messages">
        <i class="fas fa-comment-dots"></i>
        <?php if ($chat_unread > 0): ?>
            <span class="chat-badge"><?php echo $chat_unread; ?></span>
        <?php endif; ?>
    </a>
    
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
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=home"><i class="fas fa-home"></i> Home</a></li>
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=feed"><i class="fas fa-rss"></i> Updates Feed</a></li>
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=chat" class="active"><i class="fas fa-comment-dots"></i> Messages</a></li>
            <li style="border-bottom: none; padding: 8px 12px; color: #dddddd; font-size: 10px;">OFFICES</li>
            <?php
            $offices_list = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
            while ($office_row = $offices_list->fetch_assoc()):
                $office = $office_row['office_name'];
                $statusQuery = $conn->query("SELECT s.chmsu_status FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id WHERE r.chmsu_office = '$office' AND s.chmsu_student_id = '$student_id' ORDER BY s.chmsu_id DESC LIMIT 1");
                $statusClass = 'status-dot-pending';
                if ($statusQuery->num_rows > 0) {
                    $status = $statusQuery->fetch_assoc()['chmsu_status'];
                    if ($status == 'Approved') $statusClass = 'status-dot-approved';
                    elseif ($status == 'Declined') $statusClass = 'status-dot-declined';
                }
            ?>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=office&office=<?php echo urlencode($office); ?>">
                    <span class="office-status-dot <?php echo $statusClass; ?>"></span>
                    <span><?php echo htmlspecialchars($office); ?></span>
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
        
        <div class="chat-container">
            <!-- LEFT SIDEBAR - Office List -->
            <div class="chat-sidebar">
                <div class="chat-sidebar-header">
                    <span><i class="fas fa-building"></i> Offices</span>
                    <span class="badge"><?php echo count($allOffices); ?></span>
                </div>
                <div class="chat-search">
                    <input type="text" id="officeSearchInput" placeholder="🔍 Search office..." onkeyup="filterOffices()">
                </div>
                <div id="officeList">
                    <?php if (count($allOffices) > 0): ?>
                        <?php foreach ($allOffices as $o): 
                            $unread = $o['unread'] ?: 0;
                            $lastMsg = $o['last_message'] ?: '';
                        ?>
                            <div class="chat-office-item" data-name="<?php echo strtolower($o['name']); ?>" onclick="selectOffice('<?php echo addslashes($o['name']); ?>')">
                                <div>
                                    <div class="office-name"><?php echo htmlspecialchars($o['name']); ?></div>
                                    <?php if ($lastMsg): ?>
                                        <div class="office-last"><?php echo htmlspecialchars(substr($lastMsg, 0, 40)); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($unread > 0): ?>
                                    <span class="unread-badge"><?php echo $unread; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding: 20px; text-align: center; color: #999;">No offices found</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- RIGHT - CHAT AREA -->
            <div class="chat-area" id="chatArea">
                <div class="chat-area-header">
                    <div>
                        <span class="chat-with" id="chatWith">Select an office</span>
                    </div>
                    <span class="chat-status" id="chatStatus">Click an office to start chatting</span>
                </div>
                
                <div class="chat-messages" id="chatMessages">
                    <div class="no-chat-selected">
                        <i class="fas fa-comment-dots"></i>
                        <h3>No conversation selected</h3>
                        <p>Select an office from the left to start chatting</p>
                    </div>
                </div>
                
                <div class="quick-replies" id="quickReplies">
                    <button class="qr-btn" onclick="sendQuickReply('Hello')">Hello</button>
                    <button class="qr-btn" onclick="sendQuickReply('Clearance Status')">Clearance Status</button>
                    <button class="qr-btn" onclick="sendQuickReply('Requirements')">Requirements</button>
                    <button class="qr-btn" onclick="sendQuickReply('Help')">Help</button>
                    <button class="qr-btn" onclick="sendQuickReply('Thank you')">Thank you</button>
                </div>
                
                <div class="chat-input-area">
                    <input type="text" id="chatInput" placeholder="Type a message..." onkeydown="if(event.key==='Enter') sendChatMessage()">
                    <button class="file-btn" title="Attach file">
                        <i class="fas fa-paperclip"></i>
                        <input type="file" id="chatFileInput" onchange="sendChatFile(this)">
                    </button>
                    <button class="clear-btn" onclick="clearChatHistory()" title="Clear chat">
                        <i class="fas fa-trash"></i>
                    </button>
                    <button class="chat-btn" onclick="sendChatMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// GLOBAL VARIABLES
// ============================================
let selectedOffice = null;
let currentOfficeName = '';
let isTyping = false;

// ============================================
// SEARCH FUNCTION
// ============================================
function filterOffices() {
    const search = document.getElementById('officeSearchInput').value.toLowerCase().trim();
    const items = document.querySelectorAll('.chat-office-item');
    
    items.forEach(item => {
        const name = item.dataset.name || '';
        if (name.includes(search)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// ============================================
// SELECT OFFICE
// ============================================
function selectOffice(officeName) {
    selectedOffice = officeName;
    currentOfficeName = officeName;
    
    document.getElementById('chatWith').textContent = officeName;
    document.getElementById('chatStatus').textContent = 'Online';
    
    document.querySelectorAll('.chat-office-item').forEach(item => {
        item.classList.remove('active');
        if (item.querySelector('.office-name')?.textContent === officeName) {
            item.classList.add('active');
        }
    });
    
    document.getElementById('officeSearchInput').value = '';
    filterOffices();
    
    loadChatHistory(officeName);
    markChatRead(officeName);
}

// ============================================
// LOAD CHAT HISTORY
// ============================================
function loadChatHistory(officeName) {
    const messagesDiv = document.getElementById('chatMessages');
    messagesDiv.innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">Loading messages...</div>';
    
    const formData = new FormData();
    formData.append('action', 'get_chat_messages');
    formData.append('office_name', officeName);
    
    fetch(window.location.pathname + '?view=chat', { 
        method: 'POST', 
        body: formData 
    })
    .then(response => response.json())
    .then(data => {
        messagesDiv.innerHTML = '';
        if (data.length === 0) {
            messagesDiv.innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">No messages yet. Say hello!</div>';
            return;
        }
        data.forEach(msg => {
            const isSent = msg.sender_type === 'student';
            const div = document.createElement('div');
            div.className = `message ${isSent ? 'sent' : 'received'}`;
            let content = msg.message;
            if (msg.file_path) {
                const icon = getFileIcon(msg.file_type);
                content += `<div class="file-attachment" onclick="downloadFile('${msg.file_path}')">
                    <i class="fas ${icon}"></i> ${msg.file_name}
                </div>`;
            }
            div.innerHTML = content + `<span class="time">${msg.time}</span>`;
            messagesDiv.appendChild(div);
        });
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    })
    .catch(error => {
        console.error('Error loading messages:', error);
        messagesDiv.innerHTML = '<div style="text-align:center;color:#e74c3c;font-size:12px;padding:20px;">Error loading messages. Please try again.</div>';
    });
}

function markChatRead(officeName) {
    const formData = new FormData();
    formData.append('action', 'mark_chat_read');
    formData.append('office_name', officeName);
    fetch(window.location.pathname + '?view=chat', { method: 'POST', body: formData });
    updateUnreadBadge();
}

// ============================================
// SEND MESSAGE
// ============================================
function sendChatMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message || !selectedOffice) {
        if (!selectedOffice) alert('Please select an office first.');
        return;
    }
    input.value = '';
    
    const messagesDiv = document.getElementById('chatMessages');
    const sentDiv = document.createElement('div');
    sentDiv.className = 'message sent';
    sentDiv.innerHTML = message + `<span class="time">Sending...</span>`;
    messagesDiv.appendChild(sentDiv);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
    
    showTypingIndicator();
    
    const formData = new FormData();
    formData.append('action', 'send_chat_message');
    formData.append('office_name', selectedOffice);
    formData.append('message', message);
    
    fetch(window.location.pathname + '?view=chat', { 
        method: 'POST', 
        body: formData 
    })
    .then(response => response.json())
    .then(data => {
        hideTypingIndicator();
        if (data.success) {
            loadChatHistory(selectedOffice);
            updateUnreadBadge();
        } else {
            alert('Failed to send message. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error sending message:', error);
        hideTypingIndicator();
        alert('Error sending message. Please try again.');
    });
}

function sendQuickReply(text) {
    document.getElementById('chatInput').value = text;
    sendChatMessage();
}

// ============================================
// FILE UPLOAD
// ============================================
function sendChatFile(fileInput) {
    if (!selectedOffice) {
        alert('Please select an office first.');
        fileInput.value = '';
        return;
    }
    const file = fileInput.files[0];
    if (!file) return;
    
    const formData = new FormData();
    formData.append('action', 'send_chat_message');
    formData.append('office_name', selectedOffice);
    formData.append('message', '📎 File attached');
    formData.append('chat_file', file);
    
    const messagesDiv = document.getElementById('chatMessages');
    const sentDiv = document.createElement('div');
    sentDiv.className = 'message sent';
    sentDiv.innerHTML = `📎 ${file.name}<span class="time">Sending...</span>`;
    messagesDiv.appendChild(sentDiv);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
    
    fetch(window.location.pathname + '?view=chat', { method: 'POST', body: formData })
        .then(() => {
            fileInput.value = '';
            loadChatHistory(selectedOffice);
        });
}

// ============================================
// CLEAR CHAT
// ============================================
function clearChatHistory() {
    if (!selectedOffice) return;
    if (confirm('Clear all messages with ' + selectedOffice + '?')) {
        const formData = new FormData();
        formData.append('action', 'clear_chat');
        formData.append('office_name', selectedOffice);
        
        fetch(window.location.pathname + '?view=chat', { method: 'POST', body: formData })
            .then(() => {
                loadChatHistory(selectedOffice);
            });
    }
}

// ============================================
// TYPING INDICATOR
// ============================================
function showTypingIndicator() {
    if (isTyping) return;
    isTyping = true;
    const messagesDiv = document.getElementById('chatMessages');
    const existing = messagesDiv.querySelector('.typing-indicator');
    if (existing) return;
    const typing = document.createElement('div');
    typing.className = 'typing-indicator';
    typing.id = 'typingIndicator';
    typing.innerHTML = '<span></span><span></span><span></span>';
    messagesDiv.appendChild(typing);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
    setTimeout(hideTypingIndicator, 5000);
}

function hideTypingIndicator() {
    isTyping = false;
    const typing = document.getElementById('typingIndicator');
    if (typing) typing.remove();
}

// ============================================
// FILE ICON HELPER
// ============================================
function getFileIcon(type) {
    const icons = {
        pdf: 'fa-file-pdf',
        doc: 'fa-file-word',
        docx: 'fa-file-word',
        xls: 'fa-file-excel',
        xlsx: 'fa-file-excel',
        jpg: 'fa-file-image',
        jpeg: 'fa-file-image',
        png: 'fa-file-image',
        gif: 'fa-file-image',
        txt: 'fa-file-alt'
    };
    return icons[type] || 'fa-file';
}

function downloadFile(path) {
    window.open(path, '_blank');
}

// ============================================
// UNREAD BADGE UPDATE
// ============================================
function updateUnreadBadge() {
    const formData = new FormData();
    formData.append('action', 'get_unread_count');
    
    fetch(window.location.pathname + '?view=chat', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            const chatBadges = document.querySelectorAll('.chat-badge');
            chatBadges.forEach(badge => {
                if (data.total > 0) {
                    badge.textContent = data.total;
                    badge.style.display = 'inline';
                } else {
                    badge.style.display = 'none';
                }
            });
        });
}

// ============================================
// DARK MODE
// ============================================
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

// ============================================
// AUTO-REFRESH CHAT (every 5 seconds)
// ============================================
setInterval(function() {
    if (selectedOffice) {
        loadChatHistory(selectedOffice);
        updateUnreadBadge();
    }
}, 5000);

console.log('Student Chat System loaded successfully!');
</script>

</body>
</html>