<?php
// modules/office/chat.php
// Office Chat Interface - Facebook-style Messenger

$office = $_SESSION['office'];
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentOfficeSection = 'chat';

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
    is_deleted TINYINT(1) DEFAULT 0,
    unsent_for_everyone TINYINT(1) DEFAULT 0,
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
// GET INITIALS FOR AVATAR
// ============================================
function getInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';
    $count = 0;
    foreach ($words as $word) {
        if ($count < 2 && !empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
            $count++;
        }
    }
    return $initials ?: '?';
}

function getAvatarColor($name) {
    $colors = ['#1b4d3e', '#2d6a4f', '#f39c12', '#e74c3c', '#3498db', '#9b59b6', '#1abc9c', '#e67e22', '#2ecc71', '#e84393'];
    $hash = 0;
    for ($i = 0; $i < strlen($name); $i++) {
        $hash += ord($name[$i]);
    }
    return $colors[$hash % count($colors)];
}

// ============================================
// GET UNREAD CHAT COUNT
// ============================================
$total_unread_chat = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$office'")->fetch_assoc()['total'] ?: 0;
$chat_unread = $total_unread_chat;

// ============================================
// GET ALL STUDENTS FOR CHAT LIST
// ============================================
$students = $conn->query("SELECT DISTINCT 
                           s.chmsu_student_id as id,
                           s.chmsu_full_name as name,
                           s.chmsu_course as course,
                           s.chmsu_year as year,
                           s.chmsu_section as section,
                           (SELECT office_unread FROM chmsu_chat_conversations 
                            WHERE student_id = s.chmsu_student_id AND office_name = '$office') as unread,
                           (SELECT last_message FROM chmsu_chat_conversations 
                            WHERE student_id = s.chmsu_student_id AND office_name = '$office') as last_message,
                           (SELECT last_message_time FROM chmsu_chat_conversations 
                            WHERE student_id = s.chmsu_student_id AND office_name = '$office') as last_time
                          FROM chmsu_students_master s
                          WHERE s.is_archived = 0
                          ORDER BY (SELECT last_message_time FROM chmsu_chat_conversations 
                            WHERE student_id = s.chmsu_student_id AND office_name = '$office') DESC");
$allStudents = [];
while ($s = $students->fetch_assoc()) {
    $allStudents[] = $s;
}

// ============================================
// GET ALL SECTIONS FOR GROUP CHAT
// ============================================
$sections = $conn->query("SELECT DISTINCT chmsu_year, chmsu_section 
                          FROM chmsu_students_master 
                          WHERE is_archived = 0 
                          ORDER BY chmsu_year, chmsu_section");
$allSections = [];
while ($sec = $sections->fetch_assoc()) {
    $allSections[] = $sec;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance - Messages</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f0f2f5; font-size: 14px; }
        
        /* Header */
        .header {
            background: #1b4d3e;
            color: white;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .header-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 18px; font-weight: 600; margin: 0; }
        .header-title p { font-size: 11px; opacity: 0.8; margin: 0; }
        .dark-mode-toggle, .logout-btn {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 5px 12px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 12px;
        }
        .logout-btn { background: #e74c3c; border: none; margin-left: auto; }
        .chat-header-icon {
            position: relative;
            color: white;
            font-size: 18px;
            text-decoration: none;
            margin-right: 5px;
        }
        .chat-header-icon .chat-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 1px 6px;
            font-size: 9px;
            min-width: 18px;
            text-align: center;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }
        
        /* Layout */
        .dashboard-wrapper { display: flex; min-height: calc(100vh - 64px); }
        .office-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 64px);
            overflow-y: auto;
            flex-shrink: 0;
        }
        .office-sidebar::-webkit-scrollbar { width: 4px; }
        .office-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .office-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 4px; }
        .office-sidebar .sidebar-header { padding: 18px 20px; border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-header h3 { font-size: 15px; font-weight: 600; margin: 0; }
        .office-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin: 3px 0 0; }
        .office-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .office-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-menu a {
            display: block; padding: 12px 20px; color: white; text-decoration: none;
            font-size: 13px; transition: 0.2s;
        }
        .office-sidebar .sidebar-menu a:hover,
        .office-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000; }
        
        /* Main Content */
        .main-content {
            flex: 1; margin-left: 260px; padding: 15px; background: #f0f2f5; min-height: calc(100vh - 64px);
        }
        .dashboard-header-bar {
            background: white; padding: 10px 20px; border-radius: 8px;
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: 600; color: #1b4d3e; margin: 0; }
        
        /* ===== CHAT CONTAINER - FACEBOOK STYLE ===== */
        .chat-container {
            display: flex;
            height: calc(100vh - 170px);
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        /* LEFT SIDEBAR - Chat List */
        .chat-sidebar {
            width: 340px;
            background: #fff;
            border-right: 1px solid #e4e6eb;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .chat-sidebar-header {
            padding: 14px 16px;
            border-bottom: 1px solid #e4e6eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
        }
        .chat-sidebar-header h4 {
            font-size: 16px;
            font-weight: 600;
            color: #050505;
            margin: 0;
        }
        .chat-sidebar-header .badge {
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 11px;
        }
        
        /* Search */
        .chat-search {
            padding: 10px 16px;
            border-bottom: 1px solid #e4e6eb;
        }
        .chat-search input {
            width: 100%;
            padding: 8px 14px;
            border: none;
            border-radius: 20px;
            font-size: 13px;
            outline: none;
            background: #f0f2f5;
        }
        .chat-search input:focus { background: #fff; box-shadow: 0 0 0 2px #1b4d3e30; }
        
        /* Group Chat Sections */
        .group-chat-section {
            padding: 8px 16px;
            border-bottom: 1px solid #e4e6eb;
            background: #f8f9fa;
        }
        .group-chat-section .group-title {
            font-size: 11px;
            font-weight: 600;
            color: #65676b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .group-chat-section .group-items {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .group-chat-section .group-btn {
            background: white;
            border: 1px solid #e4e6eb;
            color: #1b4d3e;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 11px;
            cursor: pointer;
            transition: 0.2s;
        }
        .group-chat-section .group-btn:hover { background: #1b4d3e; color: white; }
        .group-chat-section .group-btn.active { background: #1b4d3e; color: white; }
        
        /* Chat List Items */
        .chat-list {
            flex: 1;
            overflow-y: auto;
            padding: 4px 0;
        }
        .chat-item {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            cursor: pointer;
            transition: 0.15s;
            border-left: 3px solid transparent;
        }
        .chat-item:hover { background: #f0f2f5; }
        .chat-item.active { background: #e7f3ff; border-left-color: #1b4d3e; }
        
        /* Avatar Circle */
        .avatar-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 600;
            color: white;
            flex-shrink: 0;
            margin-right: 12px;
        }
        .avatar-circle.group {
            background: #1b4d3e;
        }
        
        .chat-item-info { flex: 1; min-width: 0; }
        .chat-item-name {
            font-size: 14px;
            font-weight: 600;
            color: #050505;
        }
        .chat-item-detail {
            font-size: 11px;
            color: #65676b;
        }
        .chat-item-preview {
            font-size: 13px;
            color: #65676b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .chat-item-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            flex-shrink: 0;
            margin-left: 8px;
        }
        .chat-item-time {
            font-size: 11px;
            color: #65676b;
        }
        .chat-item-unread {
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 10px;
            min-width: 20px;
            text-align: center;
            margin-top: 4px;
        }
        .group-icon { color: #1b4d3e; margin-right: 4px; }
        
        /* RIGHT - Chat Area */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
        }
        
        /* Chat Header */
        .chat-area-header {
            padding: 12px 20px;
            border-bottom: 1px solid #e4e6eb;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
        }
        .chat-area-header .back-btn {
            display: none;
            background: none; border: none; font-size: 20px; cursor: pointer; color: #65676b;
        }
        .chat-area-header .chat-with {
            font-size: 15px;
            font-weight: 600;
            color: #050505;
        }
        .chat-area-header .chat-type {
            font-size: 11px;
            color: #65676b;
        }
        .chat-area-header .chat-actions {
            margin-left: auto;
            display: flex;
            gap: 8px;
        }
        .chat-area-header .chat-actions button {
            background: none; border: none; cursor: pointer; font-size: 16px; color: #65676b;
            padding: 4px 8px; border-radius: 4px;
        }
        .chat-area-header .chat-actions button:hover { background: #f0f2f5; }
        
        /* Chat Messages */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px;
            background: #fafafa;
        }
        
        /* Message Bubbles */
        .message {
            display: flex;
            margin-bottom: 8px;
            max-width: 75%;
            clear: both;
        }
        .message.sent {
            float: right;
            flex-direction: row-reverse;
        }
        .message.received {
            float: left;
        }
        
        .message .msg-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            color: white;
            flex-shrink: 0;
            margin: 0 8px;
        }
        .message.sent .msg-avatar { display: none; }
        
        .message .bubble {
            padding: 8px 14px;
            border-radius: 18px;
            font-size: 14px;
            word-wrap: break-word;
            position: relative;
            max-width: 100%;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .message.sent .bubble {
            background: #1b4d3e;
            color: white;
            border-bottom-right-radius: 4px;
        }
        .message.received .bubble {
            background: white;
            color: #050505;
            border-bottom-left-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }
        
        .message .bubble .msg-sender {
            font-size: 11px;
            font-weight: 600;
            color: #1b4d3e;
            display: block;
            margin-bottom: 2px;
        }
        .message.received .bubble .msg-sender { color: #1b4d3e; }
        
        .message .bubble .msg-time {
            font-size: 10px;
            opacity: 0.6;
            display: block;
            margin-top: 3px;
            text-align: right;
        }
        .message.sent .bubble .msg-time { color: #ddd; }
        .message.received .bubble .msg-time { color: #888; }
        
        .message .bubble .file-box {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: rgba(255,255,255,0.08);
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.1);
            cursor: pointer;
            transition: 0.2s;
            margin-top: 5px;
        }
        .message.received .bubble .file-box {
            background: #f0f2f5;
            border: 1px solid #e4e6eb;
        }
        .message .bubble .file-box:hover { opacity: 0.8; }
        .message .bubble .file-box .file-icon { font-size: 28px; }
        .message .bubble .file-box .file-info { flex: 1; }
        .message .bubble .file-box .file-name { font-size: 13px; font-weight: 500; }
        .message .bubble .file-box .file-size { font-size: 11px; opacity: 0.6; }
        
        /* Unsent message */
        .message .bubble.unsent {
            background: #e4e6eb !important;
            color: #65676b !important;
            font-style: italic;
            opacity: 0.7;
        }
        
        /* Message actions (hover) */
        .message .msg-actions {
            display: none;
            align-items: center;
            gap: 4px;
            padding: 0 4px;
            opacity: 0;
            transition: 0.2s;
        }
        .message:hover .msg-actions {
            display: flex;
            opacity: 1;
        }
        .message.sent .msg-actions { margin-right: 4px; }
        .message.received .msg-actions { margin-left: 4px; }
        
        .message .msg-actions button {
            background: white;
            border: 1px solid #e4e6eb;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #65676b;
            transition: 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .message .msg-actions button:hover { background: #f0f2f5; }
        .message .msg-actions button.unsent-btn:hover { color: #e74c3c; }
        
        /* Typing Indicator */
        .typing-indicator {
            display: none;
            padding: 8px 14px;
            background: white;
            border-radius: 18px;
            float: left;
            clear: both;
            margin-bottom: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }
        .typing-indicator span {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #999;
            border-radius: 50%;
            margin: 0 2px;
            animation: typingBounce 1.4s infinite both;
        }
        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingBounce { 0%, 60%, 100% { transform: translateY(0); } 30% { transform: translateY(-6px); } }
        
        /* No chat selected */
        .no-chat-selected {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #999;
            padding: 40px;
        }
        .no-chat-selected i { font-size: 56px; margin-bottom: 12px; color: #ddd; }
        .no-chat-selected h3 { color: #050505; margin-bottom: 4px; }
        
        /* ===== QUICK REPLIES ===== */
        .quick-replies {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 6px 16px;
            background: #f8f9fa;
            border-top: 1px solid #e4e6eb;
        }
        .quick-replies .qr-btn {
            background: #e7f3ff;
            color: #1b4d3e;
            border: none;
            border-radius: 16px;
            padding: 4px 14px;
            font-size: 12px;
            cursor: pointer;
            transition: 0.2s;
        }
        .quick-replies .qr-btn:hover { background: #1b4d3e; color: white; }
        
        /* ===== INPUT AREA ===== */
        .chat-input-area {
            padding: 8px 16px 12px;
            border-top: 1px solid #e4e6eb;
            background: white;
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }
        .chat-input-area .input-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            background: #f0f2f5;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid transparent;
            transition: 0.2s;
        }
        .chat-input-area .input-wrapper:focus-within {
            border-color: #1b4d3e;
            background: white;
        }
        .chat-input-area .input-wrapper input {
            flex: 1;
            border: none;
            background: transparent;
            padding: 8px 4px;
            font-size: 14px;
            outline: none;
            font-family: inherit;
        }
        .chat-input-area .action-btn {
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: 0.2s;
            flex-shrink: 0;
        }
        .chat-input-area .file-btn {
            background: #e4e6eb;
            color: #65676b;
            position: relative;
        }
        .chat-input-area .file-btn:hover { background: #d0d2d5; }
        .chat-input-area .file-btn input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .chat-input-area .send-btn {
            background: #1b4d3e;
            color: white;
        }
        .chat-input-area .send-btn:hover { background: #2d6a4f; }
        .chat-input-area .clear-btn {
            background: #e74c3c;
            color: white;
        }
        .chat-input-area .clear-btn:hover { background: #c0392b; }
        
        /* ===== FILE UPLOAD BOX ===== */
        .file-upload-box {
            display: none;
            padding: 10px 16px;
            background: #f8f9fa;
            border-top: 1px solid #e4e6eb;
        }
        .file-upload-box.show { display: block; }
        .file-upload-box .file-preview {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e4e6eb;
        }
        .file-upload-box .file-preview .file-icon { font-size: 24px; color: #1b4d3e; }
        .file-upload-box .file-preview .file-info { flex: 1; }
        .file-upload-box .file-preview .file-name { font-size: 13px; font-weight: 500; }
        .file-upload-box .file-preview .file-size { font-size: 11px; color: #888; }
        .file-upload-box .file-preview .remove-file {
            background: none; border: none; color: #e74c3c; cursor: pointer; font-size: 16px;
        }
        
        /* ===== MODAL ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: white;
            border-radius: 12px;
            max-width: 400px;
            width: 90%;
            padding: 24px 28px;
            animation: modalFade 0.2s ease;
        }
        @keyframes modalFade { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .modal-box h3 { font-size: 18px; color: #050505; margin-bottom: 8px; }
        .modal-box p { font-size: 14px; color: #65676b; margin-bottom: 20px; }
        .modal-box .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .modal-box .modal-buttons button {
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        .modal-box .modal-buttons .btn-cancel { background: #e4e6eb; color: #050505; }
        .modal-box .modal-buttons .btn-cancel:hover { background: #d0d2d5; }
        .modal-box .modal-buttons .btn-confirm { background: #e74c3c; color: white; }
        .modal-box .modal-buttons .btn-confirm:hover { background: #c0392b; }
        .modal-box .modal-buttons .btn-confirm-success { background: #1b4d3e; color: white; }
        .modal-box .modal-buttons .btn-confirm-success:hover { background: #2d6a4f; }
        
        /* Dark Mode */
        body.dark-mode { background: #18191a; }
        body.dark-mode .main-content { background: #18191a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .chat-container,
        body.dark-mode .chat-sidebar,
        body.dark-mode .chat-area,
        body.dark-mode .chat-area-header,
        body.dark-mode .chat-input-area,
        body.dark-mode .chat-sidebar-header { background: #242526; border-color: #3e4042; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .chat-sidebar-header h4 { color: #e4e6eb; }
        body.dark-mode .chat-search input { background: #3e4042; color: #e4e6eb; }
        body.dark-mode .chat-item:hover { background: #3e4042; }
        body.dark-mode .chat-item.active { background: #2d3a4f; border-left-color: #f1c40f; }
        body.dark-mode .chat-item-name { color: #e4e6eb; }
        body.dark-mode .chat-item-preview { color: #b0b3b8; }
        body.dark-mode .chat-item-detail { color: #b0b3b8; }
        body.dark-mode .chat-item-time { color: #b0b3b8; }
        body.dark-mode .chat-area-header .chat-with { color: #e4e6eb; }
        body.dark-mode .chat-area-header .chat-type { color: #b0b3b8; }
        body.dark-mode .chat-messages { background: #18191a; }
        body.dark-mode .message.received .bubble { background: #3e4042; color: #e4e6eb; }
        body.dark-mode .message.received .bubble .msg-time { color: #b0b3b8; }
        body.dark-mode .quick-replies { background: #242526; border-color: #3e4042; }
        body.dark-mode .quick-replies .qr-btn { background: #3e4042; color: #f1c40f; }
        body.dark-mode .quick-replies .qr-btn:hover { background: #f1c40f; color: #000; }
        body.dark-mode .chat-input-area .input-wrapper { background: #3e4042; }
        body.dark-mode .chat-input-area .input-wrapper input { color: #e4e6eb; }
        body.dark-mode .chat-input-area .file-btn { background: #3e4042; color: #b0b3b8; }
        body.dark-mode .file-upload-box { background: #242526; border-color: #3e4042; }
        body.dark-mode .file-upload-box .file-preview { background: #3e4042; border-color: #4a4c4e; color: #e4e6eb; }
        body.dark-mode .modal-box { background: #242526; }
        body.dark-mode .modal-box h3 { color: #e4e6eb; }
        body.dark-mode .modal-box p { color: #b0b3b8; }
        body.dark-mode .modal-box .modal-buttons .btn-cancel { background: #3e4042; color: #e4e6eb; }
        body.dark-mode .modal-box .modal-buttons .btn-cancel:hover { background: #4a4c4e; }
        body.dark-mode .no-chat-selected h3 { color: #e4e6eb; }
        body.dark-mode .no-chat-selected i { color: #3e4042; }
        body.dark-mode .group-chat-section { background: #242526; border-color: #3e4042; }
        body.dark-mode .group-chat-section .group-title { color: #b0b3b8; }
        body.dark-mode .group-chat-section .group-btn { background: #3e4042; border-color: #4a4c4e; color: #f1c40f; }
        body.dark-mode .group-chat-section .group-btn:hover { background: #f1c40f; color: #000; }
        body.dark-mode .group-chat-section .group-btn.active { background: #f1c40f; color: #000; }
        body.dark-mode .message .bubble .file-box { background: #3e4042; border-color: #4a4c4e; }
        body.dark-mode .message .msg-actions button { background: #3e4042; border-color: #4a4c4e; color: #b0b3b8; }
        body.dark-mode .message .msg-actions button:hover { background: #4a4c4e; }
        
        /* Mobile */
        @media (max-width: 768px) {
            .office-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .chat-container { flex-direction: column; height: calc(100vh - 120px); }
            .chat-sidebar { width: 100%; max-height: 280px; border-right: none; border-bottom: 1px solid #e4e6eb; }
            .chat-area-header .back-btn { display: block; }
            .chat-area { min-height: 300px; }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CHMSU E-CLEARANCE</h1>
        <p><?php echo strtoupper($office); ?> Portal - Messages</p>
    </div>
    <a href="?officesection=chat" class="chat-header-icon">
        <i class="fas fa-comment-dots"></i>
        <?php if ($chat_unread > 0): ?>
            <span class="chat-badge"><?php echo $chat_unread; ?></span>
        <?php endif; ?>
    </a>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i>
    </button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
            <p>Clearance Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?officesection=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?officesection=note"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li><a href="?officesection=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?officesection=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?officesection=chat" class="active"><i class="fas fa-comment-dots"></i> Messages</a></li>
            <li style="margin-top: 0; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
        <div style="padding: 12px 20px; font-size: 10px; color: #ddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px; border-radius:2px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f; border-radius:2px;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2><i class="fas fa-comment-dots"></i> Messages</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <!-- Chat Container -->
        <div class="chat-container">
            <!-- LEFT - Chat List -->
            <div class="chat-sidebar" id="chatSidebar">
                <div class="chat-sidebar-header">
                    <h4><i class="fas fa-users"></i> Students</h4>
                    <?php if ($chat_unread > 0): ?>
                        <span class="badge"><?php echo $chat_unread; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="chat-search">
                    <input type="text" id="searchInput" placeholder="🔍 Search students..." onkeyup="filterChats()">
                </div>
                
                <!-- Group Chats -->
                <div class="group-chat-section">
                    <div class="group-title"><i class="fas fa-users"></i> Group Chats</div>
                    <div class="group-items" id="groupItems">
                        <?php foreach ($allSections as $sec): ?>
                            <button class="group-btn" onclick="startGroupChat('<?php echo $sec['chmsu_year']; ?>', '<?php echo addslashes($sec['chmsu_section']); ?>')">
                                <?php echo $sec['chmsu_year']; ?>th - <?php echo $sec['chmsu_section']; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Individual Chats -->
                <div class="chat-list" id="chatList">
                    <?php if (count($allStudents) > 0): ?>
                        <?php foreach ($allStudents as $s): 
                            $unread = $s['unread'] ?: 0;
                            $lastMsg = $s['last_message'] ?: 'No messages yet';
                            $lastTime = $s['last_time'] ? date('M d, h:i A', strtotime($s['last_time'])) : '';
                            $initials = getInitials($s['name']);
                            $color = getAvatarColor($s['name']);
                        ?>
                            <div class="chat-item" data-name="<?php echo strtolower($s['name']); ?>" data-id="<?php echo $s['id']; ?>" onclick="selectStudent('<?php echo $s['id']; ?>', '<?php echo addslashes($s['name']); ?>')">
                                <div class="avatar-circle" style="background: <?php echo $color; ?>;">
                                    <?php echo $initials; ?>
                                </div>
                                <div class="chat-item-info">
                                    <div class="chat-item-name"><?php echo htmlspecialchars($s['name']); ?></div>
                                    <div class="chat-item-detail"><?php echo $s['course']; ?> <?php echo $s['year']; ?>th - <?php echo $s['section']; ?></div>
                                    <div class="chat-item-preview"><?php echo htmlspecialchars(substr($lastMsg, 0, 40)); ?></div>
                                </div>
                                <div class="chat-item-meta">
                                    <?php if ($lastTime): ?>
                                        <span class="chat-item-time"><?php echo $lastTime; ?></span>
                                    <?php endif; ?>
                                    <?php if ($unread > 0): ?>
                                        <span class="chat-item-unread"><?php echo $unread; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding: 20px; text-align: center; color: #999; font-size: 13px;">No students found</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- RIGHT - Chat Area -->
            <div class="chat-area" id="chatArea">
                <!-- Chat Header -->
                <div class="chat-area-header">
                    <button class="back-btn" onclick="closeChat()"><i class="fas fa-arrow-left"></i></button>
                    <div>
                        <span class="chat-with" id="chatWith">Select a student</span>
                        <span class="chat-type" id="chatType"></span>
                    </div>
                    <div class="chat-actions">
                        <button onclick="clearChatHistory()" title="Clear chat">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Messages -->
                <div class="chat-messages" id="chatMessages">
                    <div class="no-chat-selected">
                        <i class="fas fa-comment-dots"></i>
                        <h3>No conversation selected</h3>
                        <p style="color: #999; font-size: 13px;">Select a student from the left to start messaging</p>
                    </div>
                </div>
                
                <!-- Quick Replies -->
                <div class="quick-replies" id="quickReplies">
                    <button class="qr-btn" onclick="sendQuickReply('Hello')">Hello</button>
                    <button class="qr-btn" onclick="sendQuickReply('Clearance Status')">Clearance Status</button>
                    <button class="qr-btn" onclick="sendQuickReply('Requirements')">Requirements</button>
                    <button class="qr-btn" onclick="sendQuickReply('Help')">Help</button>
                    <button class="qr-btn" onclick="sendQuickReply('Thank you')">Thank you</button>
                </div>
                
                <!-- File Upload Box -->
                <div class="file-upload-box" id="fileUploadBox">
                    <div class="file-preview" id="filePreview">
                        <div class="file-icon"><i class="fas fa-file"></i></div>
                        <div class="file-info">
                            <div class="file-name" id="fileName">No file selected</div>
                            <div class="file-size" id="fileSize">0 KB</div>
                        </div>
                        <button class="remove-file" onclick="removeFile()"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                
                <!-- Input Area -->
                <div class="chat-input-area">
                    <div class="input-wrapper">
                        <input type="text" id="chatInput" placeholder="Type a message..." onkeydown="if(event.key==='Enter') sendChatMessage()">
                    </div>
                    <button class="action-btn file-btn" title="Attach file">
                        <i class="fas fa-paperclip"></i>
                        <input type="file" id="chatFileInput" onchange="handleFileSelect(this)">
                    </button>
                    <button class="action-btn send-btn" onclick="sendChatMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL ===== -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
        <h3 id="modalTitle">Confirm</h3>
        <p id="modalMessage">Are you sure?</p>
        <div class="modal-buttons">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-confirm" id="modalConfirmBtn" onclick="confirmAction()">Confirm</button>
        </div>
    </div>
</div>

<script>
// ============================================
// GLOBAL VARIABLES
// ============================================
let selectedStudentId = null;
let currentChatType = 'individual';
let groupYear = '';
let groupSection = '';
let isTyping = false;
let modalCallback = null;
let selectedFile = null;

// ============================================
// MODAL FUNCTIONS
// ============================================
function showModal(title, message, callback, confirmText = 'Confirm') {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalMessage').textContent = message;
    document.getElementById('modalConfirmBtn').textContent = confirmText;
    document.getElementById('modalConfirmBtn').className = confirmText === 'Confirm' ? 'btn-confirm' : 'btn-confirm-success';
    modalCallback = callback;
    document.getElementById('modalOverlay').classList.add('active');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('active');
    modalCallback = null;
}

function confirmAction() {
    if (modalCallback) {
        modalCallback();
        modalCallback = null;
    }
    closeModal();
}

// ============================================
// SEARCH FUNCTION
// ============================================
function filterChats() {
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    const items = document.querySelectorAll('.chat-item');
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
// AVATAR HELPERS
// ============================================
function getInitials(name) {
    const words = name.trim().split(' ');
    let initials = '';
    let count = 0;
    for (let word of words) {
        if (count < 2 && word.length > 0) {
            initials += word[0].toUpperCase();
            count++;
        }
    }
    return initials || '?';
}

function getAvatarColor(name) {
    const colors = ['#1b4d3e', '#2d6a4f', '#f39c12', '#e74c3c', '#3498db', '#9b59b6', '#1abc9c', '#e67e22', '#2ecc71', '#e84393'];
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash += name.charCodeAt(i);
    }
    return colors[hash % colors.length];
}

// ============================================
// GROUP CHAT FUNCTIONS
// ============================================
function startGroupChat(year, section) {
    const groupId = 'group_' + year + '_' + section;
    selectedStudentId = groupId;
    currentChatType = 'group';
    groupYear = year;
    groupSection = section;
    
    document.getElementById('chatWith').textContent = year + 'th Year - ' + section;
    document.getElementById('chatType').textContent = '👥 Group Chat';
    
    document.querySelectorAll('.group-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.group-btn').forEach(btn => {
        if (btn.textContent.trim() === year + 'th - ' + section) {
            btn.classList.add('active');
        }
    });
    document.querySelectorAll('.chat-item').forEach(item => item.classList.remove('active'));
    
    loadGroupChatMessages(groupId);
}

function loadGroupChatMessages(groupId) {
    const messagesDiv = document.getElementById('chatMessages');
    messagesDiv.innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">Loading messages...</div>';
    
    const formData = new FormData();
    formData.append('action', 'get_group_chat_messages');
    formData.append('group_id', groupId);
    formData.append('year', groupYear);
    formData.append('section', groupSection);
    
    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            messagesDiv.innerHTML = '';
            if (data.length === 0) {
                messagesDiv.innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">No messages yet. Start the conversation!</div>';
                return;
            }
            data.forEach(msg => {
                const isSent = msg.sender_type === 'office';
                const div = document.createElement('div');
                div.className = `message ${isSent ? 'sent' : 'received'}`;
                
                let bubbleContent = '';
                if (!isSent && msg.sender_name) {
                    const initials = getInitials(msg.sender_name);
                    const color = getAvatarColor(msg.sender_name);
                    bubbleContent += `<span class="msg-sender">${msg.sender_name}</span>`;
                }
                bubbleContent += msg.message;
                if (msg.file_path) {
                    const icon = getFileIcon(msg.file_type);
                    bubbleContent += `<div class="file-box" onclick="downloadFile('${msg.file_path}')">
                        <i class="fas ${icon} file-icon"></i>
                        <div class="file-info">
                            <div class="file-name">${msg.file_name}</div>
                            <div class="file-size">Click to download</div>
                        </div>
                    </div>`;
                }
                bubbleContent += `<span class="msg-time">${msg.time}</span>`;
                
                let avatarHtml = '';
                if (!isSent) {
                    const initials = getInitials(msg.sender_name || '');
                    const color = getAvatarColor(msg.sender_name || '');
                    avatarHtml = `<div class="msg-avatar" style="background:${color};">${initials}</div>`;
                }
                
                div.innerHTML = avatarHtml + `<div class="bubble">${bubbleContent}</div>`;
                messagesDiv.appendChild(div);
            });
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        });
}

// ============================================
// SELECT STUDENT (Individual)
// ============================================
function selectStudent(studentId, studentName) {
    selectedStudentId = studentId;
    currentChatType = 'individual';
    groupYear = '';
    groupSection = '';
    
    document.getElementById('chatWith').textContent = studentName;
    document.getElementById('chatType').textContent = '💬 Individual';
    
    document.querySelectorAll('.chat-item').forEach(item => {
        item.classList.remove('active');
        if (item.dataset.id === studentId) {
            item.classList.add('active');
        }
    });
    document.querySelectorAll('.group-btn').forEach(btn => btn.classList.remove('active'));
    document.getElementById('searchInput').value = '';
    filterChats();
    
    loadChatHistory(studentId);
    markChatRead(studentId);
}

function closeChat() {
    selectedStudentId = null;
    document.getElementById('chatWith').textContent = 'Select a student';
    document.getElementById('chatType').textContent = '';
    document.getElementById('chatMessages').innerHTML = `
        <div class="no-chat-selected">
            <i class="fas fa-comment-dots"></i>
            <h3>No conversation selected</h3>
            <p style="color: #999; font-size: 13px;">Select a student from the left to start messaging</p>
        </div>
    `;
    document.querySelectorAll('.chat-item').forEach(item => item.classList.remove('active'));
    document.querySelectorAll('.group-btn').forEach(btn => btn.classList.remove('active'));
}

// ============================================
// LOAD CHAT HISTORY (Individual)
// ============================================
function loadChatHistory(studentId) {
    const messagesDiv = document.getElementById('chatMessages');
    messagesDiv.innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">Loading messages...</div>';
    
    const formData = new FormData();
    formData.append('action', 'get_chat_messages');
    formData.append('student_id', studentId);
    
    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            messagesDiv.innerHTML = '';
            if (data.length === 0) {
                messagesDiv.innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">No messages yet. Say hello!</div>';
                return;
            }
            data.forEach(msg => {
                const isSent = msg.sender_type === 'office';
                const div = document.createElement('div');
                div.className = `message ${isSent ? 'sent' : 'received'}`;
                
                let bubbleContent = msg.message;
                if (msg.file_path) {
                    const icon = getFileIcon(msg.file_type);
                    bubbleContent += `<div class="file-box" onclick="downloadFile('${msg.file_path}')">
                        <i class="fas ${icon} file-icon"></i>
                        <div class="file-info">
                            <div class="file-name">${msg.file_name}</div>
                            <div class="file-size">Click to download</div>
                        </div>
                    </div>`;
                }
                bubbleContent += `<span class="msg-time">${msg.time}</span>`;
                
                let avatarHtml = '';
                if (!isSent) {
                    const student = <?php echo json_encode($allStudents); ?>;
                    const found = student.find(s => s.id === studentId);
                    const name = found ? found.name : 'Student';
                    const initials = getInitials(name);
                    const color = getAvatarColor(name);
                    avatarHtml = `<div class="msg-avatar" style="background:${color};">${initials}</div>`;
                }
                
                div.innerHTML = avatarHtml + `<div class="bubble">${bubbleContent}</div>`;
                messagesDiv.appendChild(div);
            });
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        });
}

function markChatRead(studentId) {
    const formData = new FormData();
    formData.append('action', 'mark_chat_read');
    formData.append('student_id', studentId);
    fetch('', { method: 'POST', body: formData });
    updateUnreadBadge();
}

// ============================================
// SEND MESSAGE
// ============================================
function sendChatMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message && !selectedFile) {
        if (!selectedStudentId) alert('Please select a student first.');
        return;
    }
    input.value = '';
    
    const messagesDiv = document.getElementById('chatMessages');
    const sentDiv = document.createElement('div');
    sentDiv.className = 'message sent';
    let bubbleContent = message || (selectedFile ? '📎 File attached' : '');
    if (selectedFile) {
        const icon = getFileIcon(selectedFile.name.split('.').pop());
        bubbleContent += `<div class="file-box">
            <i class="fas ${icon} file-icon"></i>
            <div class="file-info">
                <div class="file-name">${selectedFile.name}</div>
                <div class="file-size">${formatFileSize(selectedFile.size)}</div>
            </div>
        </div>`;
    }
    bubbleContent += `<span class="msg-time">Sending...</span>`;
    sentDiv.innerHTML = `<div class="bubble">${bubbleContent}</div>`;
    messagesDiv.appendChild(sentDiv);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
    
    const formData = new FormData();
    formData.append('action', 'send_chat_message');
    formData.append('student_id', selectedStudentId);
    formData.append('message', message || '📎 File attached');
    formData.append('chat_type', currentChatType);
    if (currentChatType === 'group') {
        formData.append('year', groupYear);
        formData.append('section', groupSection);
    }
    if (selectedFile) {
        formData.append('chat_file', selectedFile);
    }
    
    const fileToSend = selectedFile;
    selectedFile = null;
    document.getElementById('fileUploadBox').classList.remove('show');
    document.getElementById('filePreview').querySelector('.file-name').textContent = 'No file selected';
    document.getElementById('filePreview').querySelector('.file-size').textContent = '0 KB';
    document.getElementById('chatFileInput').value = '';
    
    fetch('', { method: 'POST', body: formData })
        .then(() => {
            if (currentChatType === 'group') {
                loadGroupChatMessages(selectedStudentId);
            } else {
                loadChatHistory(selectedStudentId);
            }
            updateUnreadBadge();
        });
}

function sendQuickReply(text) {
    document.getElementById('chatInput').value = text;
    sendChatMessage();
}

// ============================================
// FILE UPLOAD
// ============================================
function handleFileSelect(fileInput) {
    const file = fileInput.files[0];
    if (!file) return;
    selectedFile = file;
    
    const box = document.getElementById('fileUploadBox');
    box.classList.add('show');
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileSize').textContent = formatFileSize(file.size);
    
    const icon = getFileIcon(file.name.split('.').pop());
    document.querySelector('#filePreview .file-icon i').className = `fas ${icon}`;
}

function removeFile() {
    selectedFile = null;
    document.getElementById('fileUploadBox').classList.remove('show');
    document.getElementById('chatFileInput').value = '';
}

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
        txt: 'fa-file-alt',
        zip: 'fa-file-archive',
        rar: 'fa-file-archive'
    };
    return icons[type] || 'fa-file';
}

function formatFileSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
}

function downloadFile(path) {
    window.open(path, '_blank');
}

// ============================================
// CLEAR CHAT WITH CONFIRMATION
// ============================================
function clearChatHistory() {
    if (!selectedStudentId) return;
    showModal('Clear Chat', 'Are you sure you want to clear all messages in this conversation? This action cannot be undone.', function() {
        const formData = new FormData();
        formData.append('action', 'clear_chat');
        formData.append('student_id', selectedStudentId);
        formData.append('chat_type', currentChatType);
        if (currentChatType === 'group') {
            formData.append('year', groupYear);
            formData.append('section', groupSection);
        }
        fetch('', { method: 'POST', body: formData })
            .then(() => {
                if (currentChatType === 'group') {
                    loadGroupChatMessages(selectedStudentId);
                } else {
                    loadChatHistory(selectedStudentId);
                }
            });
    }, 'Clear Chat');
}

// ============================================
// UNREAD BADGE UPDATE
// ============================================
function updateUnreadBadge() {
    const formData = new FormData();
    formData.append('action', 'get_unread_count');
    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            const badges = document.querySelectorAll('.chat-badge, .badge');
            badges.forEach(badge => {
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
        btn.innerHTML = isDarkMode ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    }
}

if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
    const btn = document.querySelector('.dark-mode-toggle');
    if (btn) btn.innerHTML = '<i class="fas fa-sun"></i>';
}

function confirmLogout() {
    showModal('Logout', 'Are you sure you want to logout?', function() {
        window.location.href = '?logout=1';
    }, 'Logout');
}

// ============================================
// AUTO-REFRESH CHAT (every 5 seconds)
// ============================================
setInterval(function() {
    if (selectedStudentId) {
        if (currentChatType === 'group') {
            loadGroupChatMessages(selectedStudentId);
        } else {
            loadChatHistory(selectedStudentId);
        }
        updateUnreadBadge();
    }
}, 5000);

console.log('Office Chat system loaded successfully!');
</script>

</body>
</html>