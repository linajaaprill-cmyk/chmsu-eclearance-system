<?php
// modules/student/chat.php
// Student Chat Interface - Individual + Group Chat (Messenger-style unread, seen, ordering, mention alerts)

$student_id = $_SESSION['student'];
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

$myCourse  = trim($master['chmsu_course'] ?? ($student['chmsu_course'] ?? ''));
$myYear    = trim($master['chmsu_year'] ?? ($student['chmsu_year'] ?? ''));
$mySection = trim($master['chmsu_section'] ?? ($student['chmsu_section'] ?? ''));

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

$conn->query("CREATE TABLE IF NOT EXISTS chmsu_group_chat_messages (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('student', 'office') NOT NULL,
    sender_id VARCHAR(50) NOT NULL,
    course VARCHAR(50) DEFAULT NULL,
    year_level VARCHAR(10) NOT NULL,
    section VARCHAR(50) NOT NULL,
    office_name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    is_mention TINYINT(1) DEFAULT 0,
    file_path VARCHAR(500) DEFAULT NULL,
    file_name VARCHAR(255) DEFAULT NULL,
    file_type VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_group (course, year_level, section, office_name),
    INDEX idx_sender (sender_type, sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS chmsu_group_chat_reads (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    office_name VARCHAR(100) NOT NULL,
    course VARCHAR(50) DEFAULT NULL,
    year_level VARCHAR(10) NOT NULL,
    section VARCHAR(50) NOT NULL,
    last_read_message_id INT(11) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_read (student_id, office_name, course, year_level, section),
    INDEX idx_student (student_id),
    INDEX idx_office (office_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$chkCol = $conn->query("SHOW COLUMNS FROM chmsu_group_chat_reads LIKE 'updated_at'");
if ($chkCol && $chkCol->num_rows == 0) {
    $conn->query("ALTER TABLE chmsu_group_chat_reads ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

// Per-user "clear chat" marker for group chat (so clearing doesn't delete for everyone)
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_group_chat_clears (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    viewer_id VARCHAR(60) NOT NULL,
    office_name VARCHAR(100) NOT NULL,
    course VARCHAR(50) NOT NULL,
    year_level VARCHAR(10) NOT NULL DEFAULT '',
    section VARCHAR(50) NOT NULL DEFAULT '',
    cleared_id INT(11) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_clear (viewer_id, office_name, course, year_level, section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ============================================
// HELPERS
// ============================================
function st_getInitials($name) {
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
function st_getAvatarColor($name) {
    $colors = ['#1b4d3e', '#2d6a4f', '#f39c12', '#e74c3c', '#3498db', '#9b59b6', '#1abc9c', '#e67e22', '#2ecc71', '#e84393'];
    $hash = 0;
    for ($i = 0; $i < strlen($name); $i++) $hash += ord($name[$i]);
    return $colors[$hash % count($colors)];
}

// ---- Group chat helpers (student side) ----
function st_out($data) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo '@@CHATJSON@@' . json_encode($data);
    exit;
}
function st_clean($t) {
    $t = trim(strip_tags((string)$t));
    if (function_exists('mb_substr')) $t = mb_substr($t, 0, 2000);
    return $t;
}
function st_canonOffice($conn, $name) {
    $n = $conn->real_escape_string(trim((string)$name));
    if ($n === '') return '';
    $r = $conn->query("SELECT office_name FROM chmsu_offices WHERE LOWER(office_name)=LOWER('$n') LIMIT 1");
    if ($r && $r->num_rows > 0) return $r->fetch_assoc()['office_name'];
    return '';
}

// ---- Send email notification to office when student chats (no emoji in Gmail) ----
function st_notifyOfficeNewChat($conn, $office_name, $student_id, $message, $chat_context = 'PM', $course = '', $file_path = '', $file_name = '', $file_type = '') {
    if (!function_exists('sendEmail') && file_exists(__DIR__ . '/../../includes/email.php')) {
        require_once __DIR__ . '/../../includes/email.php';
    }
    if (empty($office_name) || empty($student_id) || !function_exists('sendEmail')) return;

    $off_sql = $conn->real_escape_string($office_name);
    $offRow = $conn->query("SELECT email FROM chmsu_auth_roles WHERE LOWER(chmsu_role) = LOWER('$off_sql') LIMIT 1");
    $office_email = '';
    if ($offRow && $offRow->num_rows > 0) {
        $offData = $offRow->fetch_assoc();
        $office_email = trim($offData['email'] ?? '');
    }

    // Fallback if office hasn't connected Google yet
    if (empty($office_email) || strpos($office_email, 'your_') !== false || strpos($office_email, '@example.com') !== false) {
        $admRow = $conn->query("SELECT email FROM chmsu_admin_users WHERE email != '' LIMIT 1");
        if ($admRow && $admRow->num_rows > 0) {
            $office_email = trim($admRow->fetch_assoc()['email'] ?? '');
        }
        if (empty($office_email) && defined('SMTP_USER')) {
            $office_email = SMTP_USER;
        }
    }
    if (empty($office_email)) return;

    $stRow = $conn->query("SELECT chmsu_full_name, chmsu_course, chmsu_year, chmsu_section FROM chmsu_students_master WHERE chmsu_student_id = '" . $conn->real_escape_string($student_id) . "' LIMIT 1");
    $stData = ($stRow && $stRow->num_rows > 0) ? $stRow->fetch_assoc() : null;
    $student_name = $stData['chmsu_full_name'] ?? $student_id;
    $student_course = $course ?: ($stData['chmsu_course'] ?? '');
    $sec = trim(($stData['chmsu_course'] ?? '') . ' ' . ($stData['chmsu_year'] ?? '') . '-' . ($stData['chmsu_section'] ?? ''));

    // Strip emojis for clean Gmail delivery
    $clean_msg = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}]/u', '', $message);
    $clean_msg = trim(str_replace('📎', '', $clean_msg));
    if ($clean_msg === '') $clean_msg = '[Attachment Sent]';

    $is_group = (stripos($chat_context, 'group') !== false || !empty($course));
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Handle image or file attachments in notification
    $is_image = false;
    $attachment_html = '';
    $ext = strtolower($file_type ?: pathinfo($file_name ?: $file_path, PATHINFO_EXTENSION));
    $disk_file = '';
    $embedded_cid = null;

    if (!empty($file_path)) {
        $candidates = [
            $file_path,
            __DIR__ . '/../../' . ltrim($file_path, '/'),
            __DIR__ . '/../' . ltrim($file_path, '/'),
            __DIR__ . '/../../uploads/' . basename($file_path),
        ];
        foreach ($candidates as $cand) {
            if (file_exists($cand) && is_file($cand)) {
                $disk_file = realpath($cand);
                break;
            }
        }
    }

    $base_app_url = "http://" . $host . (strpos($_SERVER['REQUEST_URI'] ?? '', '/capstone') !== false ? '/capstone' : (file_exists($_SERVER['DOCUMENT_ROOT'] . '/capstone') ? '/capstone' : ''));

    if (!empty($file_path) && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'])) {
        $is_image = true;
        $embedded_cid = 'chat_img_' . time() . '_' . mt_rand(100, 999);
        $img_src = !empty($disk_file) ? "cid:$embedded_cid" : ($base_app_url . '/' . ltrim($file_path, '/'));
        $attachment_html = "<div style='margin:14px 0; text-align:center; background:#ffffff; border:1px solid #e1e4e8; border-radius:6px; padding:12px;'>
            <p style='margin:0 0 8px 0; font-size:12px; color:#555;'><strong>Attached Image:</strong> " . htmlspecialchars($file_name ?: basename($file_path)) . "</p>
            <div style='display:inline-block;'>
                <img src='$img_src' alt='Chat Image Attachment' style='max-width:100%; max-height:420px; border-radius:4px; border:1px solid #ddd; object-fit:contain;' />
            </div>
        </div>";
    } elseif (!empty($file_path)) {
        $file_url = $base_app_url . '/' . ltrim($file_path, '/');
        $attachment_html = "<div style='margin:12px 0; padding:10px 14px; background:#eef7f2; border-left:4px solid #1b4d3e; border-radius:4px; font-size:13px;'>
            <strong>Attached File:</strong> " . htmlspecialchars($file_name ?: basename($file_path)) . " (" . htmlspecialchars(strtoupper($ext)) . ")
        </div>";
    }

    $img_tag = $is_image ? " [Image Attached]" : (!empty($file_path) ? " [File Attached]" : "");

    if ($is_group) {
        $course_label = $student_course ? $student_course : 'All Courses';
        $subject = "New Group Chat Message" . $img_tag . " [Course: " . $course_label . "] - CHMSU Clearance";
        $context_text = "<p>A new message was posted in the <strong>Course: " . htmlspecialchars($course_label) . "</strong> group chat by student <strong>" . htmlspecialchars($student_name) . "</strong> (" . htmlspecialchars($student_id) . ($sec ? " - " . htmlspecialchars($sec) : "") . "):</p>
        <div class='msg-box'>
            <p style='margin:0 0 6px 0; font-size:12px; color:#1b4d3e;'><strong>COURSE: " . htmlspecialchars($course_label) . "</strong></p>
            <p style='margin:0;'><strong>" . nl2br(htmlspecialchars($clean_msg)) . "</strong></p>
        </div>" . $attachment_html;
    } else {
        $subject = "New PM Chat Message" . $img_tag . " from " . $student_name . " - CHMSU Clearance";
        $context_text = "<p>Student <strong>" . htmlspecialchars($student_name) . "</strong> (" . htmlspecialchars($student_id) . ($sec ? " - " . htmlspecialchars($sec) : "") . ") sent you a private direct message (PM):</p>
        <div class='msg-box'>
            <p style='margin:0 0 6px 0; font-size:12px; color:#1b4d3e;'><strong>DIRECT MESSAGE (PM)</strong></p>
            <p style='margin:0;'><strong>" . nl2br(htmlspecialchars($clean_msg)) . "</strong></p>
        </div>" . $attachment_html;
    }

    $chat_url = (strcasecmp($office_name, 'Registrar') === 0) 
        ? "$base_app_url/index.php?section=chat" 
        : "$base_app_url/index.php?officesection=chat";

    $body = "<html><head><style>
        body { font-family: 'Times New Roman', Times, serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
        .msg-box { background: #f8f9fa; padding: 15px; border-left: 4px solid #1b4d3e; border-radius: 4px; margin: 15px 0; font-size: 14px; }
        .footer { text-align: center; padding: 15px; font-size: 11px; color: #888; border-top: 1px solid #ddd; margin-top: 20px; }
        .btn { display: inline-block; background: #1b4d3e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold; }
    </style></head><body>
        <div class='container'>
            <div class='header'><h2>CHMSU E-Clearance System</h2></div>
            <div style='padding:20px;'>
                <p>Dear <strong>" . htmlspecialchars($office_name) . "</strong>,</p>
                $context_text
                <p style='margin-top:15px;'>Please log in to your office portal to view and reply.</p>
                <p><a href='$chat_url' class='btn'>Open Chat</a></p>
            </div>
            <div class='footer'><p>CHMSU E-Clearance System - Automated Notification</p></div>
        </div>
    </body></html>";

    try { @sendEmail($office_email, $office_name, $subject, $body, true, null, null, false, $disk_file, $file_name ?: basename($file_path), $embedded_cid); } catch (Throwable $e) {}
}
// WHERE clause: which group messages this student may see
function st_grpWhere($conn, $office, $course, $year, $section) {
    $o = $conn->real_escape_string($office);
    $c = $conn->real_escape_string($course);
    $y = $conn->real_escape_string($year);
    $s = $conn->real_escape_string($section);
    return "LOWER(m.office_name)=LOWER('$o')
            AND UPPER(TRIM(m.course))=UPPER('$c')
            AND (m.sender_type='student'
                 OR TRIM(m.year_level)=''
                 OR (TRIM(m.year_level)='$y' AND UPPER(TRIM(m.section))=UPPER('$s')))";
}
function st_grpMarker($conn, $table, $col, $sid, $office, $course) {
    $s = $conn->real_escape_string($sid);
    $o = $conn->real_escape_string($office);
    $c = $conn->real_escape_string($course);
    $idCol = ($table === 'chmsu_group_chat_reads') ? 'student_id' : 'viewer_id';
    $r = $conn->query("SELECT $col FROM $table
                       WHERE $idCol='$s' AND LOWER(office_name)=LOWER('$o')
                       AND UPPER(TRIM(course))=UPPER('$c') AND year_level='' AND section='' LIMIT 1");
    if ($r && $r->num_rows > 0) return intval($r->fetch_assoc()[$col]);
    return 0;
}
// Unread = messages from OTHER people (office + classmates) newer than my last read / clear
function st_grpUnreadInfo($conn, $sid, $office, $course, $year, $section) {
    if ($course === '') return ['count' => 0, 'first' => 0];
    $base = max(
        st_grpMarker($conn, 'chmsu_group_chat_reads', 'last_read_message_id', $sid, $office, $course),
        st_grpMarker($conn, 'chmsu_group_chat_clears', 'cleared_id', $sid, $office, $course)
    );
    $where = st_grpWhere($conn, $office, $course, $year, $section);
    $s = $conn->real_escape_string($sid);
    $r = $conn->query("SELECT COUNT(*) AS c, MIN(m.id) AS f FROM chmsu_group_chat_messages m
                       WHERE $where AND NOT (m.sender_type='student' AND m.sender_id='$s') AND m.id > $base");
    if ($r && ($row = $r->fetch_assoc())) {
        return ['count' => intval($row['c']), 'first' => intval($row['f'])];
    }
    return ['count' => 0, 'first' => 0];
}
function st_grpUnread($conn, $sid, $office, $course, $year, $section) {
    $i = st_grpUnreadInfo($conn, $sid, $office, $course, $year, $section);
    return $i['count'];
}

// ============================================
// MENTION RULES (only these two):
//   @BSIS4A     -> that exact course + year + section
//   @4th Year   -> everyone in that year level (of this course group)
// ============================================
function st_mentionsMe($text, $course, $year, $section) {
    $text = (string)$text;
    $myYear = intval(preg_replace('/\D/', '', (string)$year));
    $mySec  = strtoupper(trim((string)$section));
    $myCrs  = strtoupper(trim((string)$course));

    // Course + Year + Section  (e.g. @BSIS4A)
    if (preg_match_all('/@\s*([A-Za-z][A-Za-z\-]{1,14}?)\s*(\d)\s*([A-Za-z])(?![A-Za-z])/', $text, $m1, PREG_SET_ORDER)) {
        foreach ($m1 as $row) {
            if (strtoupper($row[1]) === $myCrs && intval($row[2]) === $myYear && strtoupper($row[3]) === $mySec) return true;
        }
    }
    // Whole year level  (e.g. @4th Year)
    if (preg_match_all('/@\s*(\d)\s*(?:st|nd|rd|th)?\s*Year(?!\s*Section)(?![A-Za-z])/i', $text, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $row) {
            if (intval($row[1]) === $myYear) return true;
        }
    }
    return false;
}
// Unread office messages that mention me (count + first id)
function st_grpMentionInfo($conn, $sid, $office, $course, $year, $section) {
    $info = ['count' => 0, 'first' => 0];
    if ($course === '') return $info;
    $base = max(
        st_grpMarker($conn, 'chmsu_group_chat_reads', 'last_read_message_id', $sid, $office, $course),
        st_grpMarker($conn, 'chmsu_group_chat_clears', 'cleared_id', $sid, $office, $course)
    );
    $where = st_grpWhere($conn, $office, $course, $year, $section);
    $r = $conn->query("SELECT m.id, m.message FROM chmsu_group_chat_messages m
                       WHERE $where AND m.sender_type='office' AND m.id > $base
                       ORDER BY m.id ASC LIMIT 300");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            if (st_mentionsMe($row['message'], $course, $year, $section)) {
                $info['count']++;
                if (!$info['first']) $info['first'] = intval($row['id']);
            }
        }
    }
    return $info;
}

// ---- Notifications: clear the "mentioned in chat" alert once the chat is seen ----
function st_tableCols($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cols = [];
    try {
        $r = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
        if ($r) { while ($row = $r->fetch_assoc()) $cols[] = $row['Field']; }
    } catch (Throwable $e) {}
    $cache[$table] = $cols;
    return $cols;
}
function st_clearChatNotifs($conn, $sid, $office = '') {
    try {
        $cols = st_tableCols($conn, 'chmsu_notifications');
        $s = $conn->real_escape_string($sid);
        $w = "(student_id='$s' OR user_id='$s') AND (type='chat' OR title LIKE '%mentioned%')";
        if ($office !== '' && in_array('office_name', $cols, true)) {
            $o = $conn->real_escape_string($office);
            $w .= " AND LOWER(office_name)=LOWER('$o')";
        }
        if (in_array('is_read', $cols, true)) {
            $conn->query("UPDATE chmsu_notifications SET is_read=1 WHERE $w AND is_read=0");
        } else {
            $conn->query("DELETE FROM chmsu_notifications WHERE $w");
        }
    } catch (Throwable $e) {}
}

// ---- Mark group chat as read for this student ----
function st_markGroupRead($conn, $sid, $office, $course, $year, $section) {
    if ($office === '' || $course === '') return;
    $where = st_grpWhere($conn, $office, $course, $year, $section);
    $r = $conn->query("SELECT MAX(m.id) as max_id FROM chmsu_group_chat_messages m WHERE $where");
    $latest = $r ? intval($r->fetch_assoc()['max_id']) : 0;
    $sid_e = $conn->real_escape_string($sid);
    $off_e = $conn->real_escape_string($office);
    $c_e   = $conn->real_escape_string($course);
    $conn->query("INSERT INTO chmsu_group_chat_reads
        (student_id, office_name, course, year_level, section, last_read_message_id)
        VALUES ('$sid_e', '$off_e', '$c_e', '', '', $latest)
        ON DUPLICATE KEY UPDATE last_read_message_id = $latest");
}

// ---- Messenger-style list data ----
function st_timeLabel($ts) {
    if (!$ts) return '';
    $t = strtotime($ts);
    if (!$t) return '';
    $now = time();
    if (date('Y-m-d', $t) === date('Y-m-d', $now)) return date('h:i A', $t);
    if (($now - $t) < 6 * 86400) return date('D', $t);
    if (date('Y', $t) === date('Y', $now)) return date('M d', $t);
    return date('M d, Y', $t);
}
function st_preview($text, $limit = 35) {
    $m = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($m === '📎 File attached') $m = '📎 Attachment';
    if (function_exists('mb_strlen')) {
        if (mb_strlen($m) > $limit) $m = mb_substr($m, 0, $limit) . '…';
    } elseif (strlen($m) > $limit) {
        $m = substr($m, 0, $limit) . '...';
    }
    return $m;
}
function st_firstName($name) {
    $parts = explode(' ', trim((string)$name));
    return $parts[0] !== '' ? $parts[0] : (string)$name;
}

function st_overview($conn, $sid, $course, $year, $section) {
    $out = ['individual' => [], 'group' => [], 'total' => 0];
    $sid_e = $conn->real_escape_string($sid);

    $members = 0;
    if ($course !== '') {
        $c_e = $conn->real_escape_string($course);
        $mr = $conn->query("SELECT COUNT(*) AS c FROM chmsu_students_master WHERE is_archived=0 AND UPPER(TRIM(chmsu_course))=UPPER('$c_e')");
        if ($mr) $members = intval($mr->fetch_assoc()['c']);
    }

    $offices = $conn->query("SELECT DISTINCT o.office_name AS name FROM chmsu_offices o ORDER BY o.office_name ASC");
    $total = 0;
    if ($offices) {
        while ($o = $offices->fetch_assoc()) {
            $name = $o['name'];
            $n_e = $conn->real_escape_string($name);

            // ---------- individual ----------
            $conv = $conn->query("SELECT last_message, last_message_time FROM chmsu_chat_conversations
                                  WHERE student_id='$sid_e' AND LOWER(office_name)=LOWER('$n_e')");
            $convRow = ($conv && $conv->num_rows > 0) ? $conv->fetch_assoc() : null;
            $ur = $conn->query("SELECT COUNT(*) AS c, MIN(id) AS f FROM chmsu_chat_messages
                                WHERE sender_type='office' AND LOWER(sender_id)=LOWER('$n_e')
                                AND receiver_id='$sid_e' AND is_read=0");
            $uRow = $ur ? $ur->fetch_assoc() : ['c' => 0, 'f' => 0];
            $iu = intval($uRow['c']);
            $lastRaw = $convRow ? $convRow['last_message_time'] : '';
            $out['individual'][] = [
                'name' => $name,
                'unread' => $iu,
                'first_unread' => intval($uRow['f']),
                'preview' => $convRow ? st_preview($convRow['last_message']) : '',
                'time_label' => $lastRaw ? st_timeLabel($lastRaw) : '',
                'sort' => $lastRaw ? strtotime($lastRaw) : 0,
                'mention_unread' => 0
            ];
            $total += $iu;

            // ---------- group ----------
            $gu = ['count' => 0, 'first' => 0];
            $gm = ['count' => 0, 'first' => 0];
            $gPreview = $course !== '' ? $course . ' • All years & sections' : '';
            $gTime = ''; $gSort = 0; $gTotal = 0;
            if ($course !== '') {
                $gu = st_grpUnreadInfo($conn, $sid, $name, $course, $year, $section);
                $gm = st_grpMentionInfo($conn, $sid, $name, $course, $year, $section);
                $where = st_grpWhere($conn, $name, $course, $year, $section);
                $cleared = st_grpMarker($conn, 'chmsu_group_chat_clears', 'cleared_id', $sid, $name, $course);

                $cq = $conn->query("SELECT COUNT(*) AS c FROM chmsu_group_chat_messages m WHERE $where AND m.id > $cleared");
                if ($cq) $gTotal = intval($cq->fetch_assoc()['c']);

                $lq = $conn->query("SELECT m.id, m.sender_type, m.sender_id, m.message, m.created_at,
                        CASE WHEN m.sender_type='student'
                             THEN COALESCE(NULLIF(s.chmsu_full_name,''), NULLIF(u.chmsu_name,''), m.sender_id)
                             ELSE m.office_name END AS sender_name
                    FROM chmsu_group_chat_messages m
                    LEFT JOIN chmsu_students_master s ON m.sender_id = s.chmsu_student_id
                    LEFT JOIN chmsu_user_accounts u ON m.sender_id = u.chmsu_student_id
                    WHERE $where AND m.id > $cleared
                    ORDER BY m.id DESC LIMIT 1");
                if ($lq && $lq->num_rows > 0) {
                    $lm = $lq->fetch_assoc();
                    if ($lm['sender_type'] === 'student' && $lm['sender_id'] === $sid) $prefix = 'You: ';
                    elseif ($lm['sender_type'] === 'student') $prefix = st_firstName($lm['sender_name']) . ': ';
                    else $prefix = $lm['sender_name'] . ': ';
                    $gPreview = st_preview($prefix . $lm['message'], 38);
                    $gTime = st_timeLabel($lm['created_at']);
                    $gSort = strtotime($lm['created_at']);
                }
            }
            $out['group'][] = [
                'name' => $name,
                'unread' => $gu['count'],
                'first_unread' => $gu['first'],
                'preview' => $gPreview,
                'time_label' => $gTime,
                'sort' => $gSort,
                'total' => $gTotal,
                'members' => $members,
                'mention_unread' => $gm['count']
            ];
            $total += $gu['count'];
        }
    }

    $cmp = function ($a, $b) {
        if ($a['sort'] !== $b['sort']) return $b['sort'] - $a['sort'];
        return strcasecmp($a['name'], $b['name']);
    };
    usort($out['individual'], $cmp);
    usort($out['group'], $cmp);
    $out['total'] = $total;
    return $out;
}

// ============================================
// CHAT AJAX (unique action names - not intercepted by handleActions)
// ============================================
if (isset($_POST['action'])
    && in_array($_POST['action'], ['grp_send','grp_get','grp_mark_read','grp_clear','chat_unread_total','chat_overview','chat_open_meta','chat_seen'], true)) {

    try {
        $act = $_POST['action'];

        // ---------- SEND GROUP MESSAGE ----------
        if ($act === 'grp_send') {
            $office = st_canonOffice($conn, $_POST['office_name'] ?? '');
            if ($office === '')   st_out(['success' => false, 'message' => 'Office not found']);
            if ($myCourse === '') st_out(['success' => false, 'message' => 'Your course is not set']);

            $message = st_clean($_POST['message'] ?? '');

            $file_path = ''; $file_name = ''; $file_type = '';
            if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] == 0) {
                $allowed = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','txt'];
                $filename = $_FILES['chat_file']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed) && $_FILES['chat_file']['size'] <= 10 * 1024 * 1024) {
                    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename));
                    $newname = UPLOAD_DIR . 'chat_' . time() . '_' . $safe;
                    if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $newname)) {
                        $file_path = $newname; $file_name = $filename; $file_type = $ext;
                    }
                }
            }
            if ($message === '' && $file_path === '') st_out(['success' => false, 'message' => 'Empty message']);
            if ($message === '') $message = '📎 File attached';

            $sid_e = $conn->real_escape_string($student_id);
            $off_e = $conn->real_escape_string($office);
            $c_e   = $conn->real_escape_string($myCourse);
            $y_e   = $conn->real_escape_string($myYear);
            $s_e   = $conn->real_escape_string($mySection);
            $m_e   = $conn->real_escape_string($message);
            $fp_e  = $conn->real_escape_string($file_path);
            $fn_e  = $conn->real_escape_string($file_name);
            $ft_e  = $conn->real_escape_string($file_type);

            $ok = $conn->query("INSERT INTO chmsu_group_chat_messages
                (sender_type, sender_id, course, year_level, section, office_name, message, is_mention, file_path, file_name, file_type)
                VALUES ('student', '$sid_e', '$c_e', '$y_e', '$s_e', '$off_e', '$m_e', 0, '$fp_e', '$fn_e', '$ft_e')");
            if (!$ok) st_out(['success' => false, 'message' => 'DB error: ' . $conn->error]);

            logActivity($conn, $student_id, 'student', "Sent group chat to $office ($myCourse)");
            st_notifyOfficeNewChat($conn, $office, $student_id, $message, 'Group Chat', $myCourse, $file_path, $file_name, $file_type);
            st_out(['success' => true]);
        }

        // ---------- GET GROUP MESSAGES ----------
        if ($act === 'grp_get') {
            $office = st_canonOffice($conn, $_POST['office_name'] ?? '');
            $since  = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;
            if ($office === '' || $myCourse === '') st_out([]);

            $cleared = st_grpMarker($conn, 'chmsu_group_chat_clears', 'cleared_id', $student_id, $office, $myCourse);
            $after = max($since, $cleared);
            $where = st_grpWhere($conn, $office, $myCourse, $myYear, $mySection);

            $q = $conn->query("SELECT m.*,
                    CASE WHEN m.sender_type='student'
                         THEN COALESCE(NULLIF(s.chmsu_full_name,''), NULLIF(u.chmsu_name,''), m.sender_id)
                         ELSE m.office_name END AS sender_name
                FROM chmsu_group_chat_messages m
                LEFT JOIN chmsu_students_master s ON m.sender_id = s.chmsu_student_id
                LEFT JOIN chmsu_user_accounts u ON m.sender_id = u.chmsu_student_id
                WHERE $where AND m.id > $after
                ORDER BY m.id ASC");

            $result = [];
            if ($q) {
                while ($msg = $q->fetch_assoc()) {
                    $result[] = [
                        'id' => intval($msg['id']),
                        'sender_type' => $msg['sender_type'],
                        'sender_id' => $msg['sender_id'],
                        'sender_name' => $msg['sender_name'],
                        'is_mine' => ($msg['sender_type'] === 'student' && $msg['sender_id'] === $student_id),
                        'message' => $msg['message'],
                        'is_mention' => intval($msg['is_mention'] ?? 0),
                        'mentions_me' => ($msg['sender_type'] === 'office' && st_mentionsMe($msg['message'], $myCourse, $myYear, $mySection)),
                        'file_path' => $msg['file_path'],
                        'file_name' => $msg['file_name'],
                        'file_type' => $msg['file_type'],
                        'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
                    ];
                }
            }
            st_out($result);
        }

        // ---------- MARK GROUP READ (kept) ----------
        if ($act === 'grp_mark_read') {
            $office = st_canonOffice($conn, $_POST['office_name'] ?? '');
            if ($office !== '' && $myCourse !== '') {
                st_markGroupRead($conn, $student_id, $office, $myCourse, $myYear, $mySection);
                st_clearChatNotifs($conn, $student_id, $office);
            }
            st_out(['success' => true]);
        }

        // ---------- SEEN: mark the open chat as read + clear its notification alert ----------
        if ($act === 'chat_seen') {
            $office = st_canonOffice($conn, $_POST['office_name'] ?? '');
            $type = (($_POST['chat_type'] ?? 'individual') === 'group') ? 'group' : 'individual';
            if ($office !== '') {
                if ($type === 'group') {
                    st_markGroupRead($conn, $student_id, $office, $myCourse, $myYear, $mySection);
                } else {
                    $o_e = $conn->real_escape_string($office);
                    $s_e = $conn->real_escape_string($student_id);
                    $conn->query("UPDATE chmsu_chat_messages SET is_read = 1
                                  WHERE sender_type='office' AND LOWER(sender_id)=LOWER('$o_e') AND receiver_id='$s_e'");
                    $conn->query("UPDATE chmsu_chat_conversations SET student_unread = 0
                                  WHERE student_id='$s_e' AND LOWER(office_name)=LOWER('$o_e')");
                }
                st_clearChatNotifs($conn, $student_id, $office);
            }
            st_out(['success' => true]);
        }

        // ---------- OVERVIEW: both lists (unread, preview, time, order, mentions) + total ----------
        if ($act === 'chat_overview') {
            st_out(st_overview($conn, $student_id, $myCourse, $myYear, $mySection));
        }

        // ---------- OPEN CHAT META: counts + seen status + pinned mention ----------
        if ($act === 'chat_open_meta') {
            $office = st_canonOffice($conn, $_POST['office_name'] ?? '');
            $type = (($_POST['chat_type'] ?? 'individual') === 'group') ? 'group' : 'individual';
            if ($office === '') st_out(['success' => false]);
            $s_e = $conn->real_escape_string($student_id);
            $o_e = $conn->real_escape_string($office);

            if ($type === 'group') {
                if ($myCourse === '') st_out(['success' => false]);
                $c_e = $conn->real_escape_string($myCourse);
                $where = st_grpWhere($conn, $office, $myCourse, $myYear, $mySection);
                $cleared = st_grpMarker($conn, 'chmsu_group_chat_clears', 'cleared_id', $student_id, $office, $myCourse);

                $total = 0;
                $tq = $conn->query("SELECT COUNT(*) AS c FROM chmsu_group_chat_messages m WHERE $where AND m.id > $cleared");
                if ($tq) $total = intval($tq->fetch_assoc()['c']);

                $members = 0;
                $mq = $conn->query("SELECT COUNT(*) AS c FROM chmsu_students_master WHERE is_archived=0 AND UPPER(TRIM(chmsu_course))=UPPER('$c_e')");
                if ($mq) $members = intval($mq->fetch_assoc()['c']);

                $myLast = 0;
                $ml = $conn->query("SELECT MAX(m.id) AS mx FROM chmsu_group_chat_messages m
                                    WHERE $where AND m.id > $cleared AND m.sender_type='student' AND m.sender_id='$s_e'");
                if ($ml) $myLast = intval($ml->fetch_assoc()['mx']);

                $seenStudents = 0; $officeSeen = false;
                if ($myLast > 0) {
                    $sq = $conn->query("SELECT COUNT(DISTINCT student_id) AS c FROM chmsu_group_chat_reads
                                        WHERE LOWER(office_name)=LOWER('$o_e') AND UPPER(TRIM(course))=UPPER('$c_e')
                                        AND year_level='' AND section=''
                                        AND last_read_message_id >= $myLast
                                        AND student_id <> '$s_e' AND LEFT(student_id,7) <> 'OFFICE_'");
                    if ($sq) $seenStudents = intval($sq->fetch_assoc()['c']);
                    $oq = $conn->query("SELECT 1 FROM chmsu_group_chat_reads
                                        WHERE LOWER(student_id)=LOWER('OFFICE_$o_e') AND UPPER(TRIM(course))=UPPER('$c_e')
                                        AND year_level='' AND section='' AND last_read_message_id >= $myLast LIMIT 1");
                    $officeSeen = ($oq && $oq->num_rows > 0);
                }

                // latest office message that mentions MY course/year/section -> pinned at the top
                $pinned = null;
                $pq = $conn->query("SELECT m.id, m.message, m.office_name, m.created_at FROM chmsu_group_chat_messages m
                                    WHERE $where AND m.id > $cleared AND m.sender_type='office'
                                    ORDER BY m.id DESC LIMIT 60");
                if ($pq) {
                    while ($pr = $pq->fetch_assoc()) {
                        if (st_mentionsMe($pr['message'], $myCourse, $myYear, $mySection)) {
                            $pinned = [
                                'id' => intval($pr['id']),
                                'text' => st_preview($pr['message'], 110),
                                'office' => $pr['office_name'],
                                'time' => date('M d, h:i A', strtotime($pr['created_at']))
                            ];
                            break;
                        }
                    }
                }

                st_out([
                    'success' => true, 'type' => 'group', 'total' => $total, 'members' => $members,
                    'my_last_id' => $myLast, 'seen_students' => $seenStudents,
                    'office_seen' => $officeSeen, 'office' => $office, 'pinned' => $pinned
                ]);
            } else {
                $upto = 0;
                $rq = $conn->query("SELECT MAX(id) AS mx FROM chmsu_chat_messages
                                    WHERE sender_type='student' AND sender_id='$s_e'
                                    AND LOWER(receiver_id)=LOWER('$o_e') AND is_read=1");
                if ($rq) $upto = intval($rq->fetch_assoc()['mx']);
                st_out(['success' => true, 'type' => 'individual', 'my_read_upto' => $upto]);
            }
        }

        // ---------- CLEAR GROUP CHAT (only for this student) ----------
        if ($act === 'grp_clear') {
            $office = st_canonOffice($conn, $_POST['office_name'] ?? '');
            if ($office !== '' && $myCourse !== '') {
                $where = st_grpWhere($conn, $office, $myCourse, $myYear, $mySection);
                $r = $conn->query("SELECT MAX(m.id) as max_id FROM chmsu_group_chat_messages m WHERE $where");
                $latest = $r ? intval($r->fetch_assoc()['max_id']) : 0;
                $sid_e = $conn->real_escape_string($student_id);
                $off_e = $conn->real_escape_string($office);
                $c_e   = $conn->real_escape_string($myCourse);
                $conn->query("INSERT INTO chmsu_group_chat_clears
                    (viewer_id, office_name, course, year_level, section, cleared_id)
                    VALUES ('$sid_e', '$off_e', '$c_e', '', '', $latest)
                    ON DUPLICATE KEY UPDATE cleared_id = $latest");
            }
            st_out(['success' => true]);
        }

        // ---------- TOTAL UNREAD (individual + group) ----------
        if ($act === 'chat_unread_total') {
            $ov = st_overview($conn, $student_id, $myCourse, $myYear, $mySection);
            st_out(['total' => intval($ov['total'])]);
        }
    } catch (Throwable $e) {
        st_out(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================
// INDIVIDUAL CHAT AJAX (UNCHANGED - normally handled earlier by handleActions,
// kept here as fallback exactly like before)
// ============================================
if (isset($_POST['action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');

    // ---------- SEND MESSAGE ----------
    if ($_POST['action'] == 'send_chat_message' && isset($_SESSION['student'])) {
        $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'individual';
        $office_name = sanitize($_POST['office_name'] ?? '');
        $message = isset($_POST['message']) ? sanitize($_POST['message']) : '';

        $file_path = ''; $file_name = ''; $file_type = '';
        if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] == 0) {
            $allowed = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','txt'];
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

        if ($message === '' && $file_path === '') {
            echo json_encode(['success' => false, 'message' => 'Empty message']);
            exit;
        }
        if ($message === '' && $file_path !== '') {
            $message = '📎 File attached';
        }

        $file_path_esc = $conn->real_escape_string($file_path);
        $file_name_esc = $conn->real_escape_string($file_name);
        $file_type_esc = $conn->real_escape_string($file_type);
        $message_esc   = $conn->real_escape_string($message);
        $office_esc    = $conn->real_escape_string($office_name);

        $conn->query("INSERT INTO chmsu_chat_messages 
                      (sender_type, sender_id, receiver_id, message, file_path, file_name, file_type) 
                      VALUES ('student', '$student_id', '$office_esc', '$message_esc', '$file_path_esc', '$file_name_esc', '$file_type_esc')");
        $conn->query("INSERT INTO chmsu_chat_conversations 
                      (student_id, office_name, last_message, last_message_time, office_unread) 
                      VALUES ('$student_id', '$office_esc', '$message_esc', NOW(), 1)
                      ON DUPLICATE KEY UPDATE 
                      last_message = '$message_esc', 
                      last_message_time = NOW(),
                      office_unread = office_unread + 1");
        logActivity($conn, $student_id, 'student', "Sent chat message to office: $office_name");
        st_notifyOfficeNewChat($conn, $office_name, $student_id, $message, 'PM', '', $file_path, $file_name, $file_type);

        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- GET INDIVIDUAL MESSAGES ----------
    if ($_POST['action'] == 'get_chat_messages' && isset($_SESSION['student'])) {
        $office_name = sanitize($_POST['office_name'] ?? '');
        $since_id = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;
        $office_esc = $conn->real_escape_string($office_name);

        $messages = $conn->query("SELECT * FROM chmsu_chat_messages 
                                  WHERE ((sender_type='student' AND sender_id='$student_id' AND receiver_id='$office_esc')
                                      OR (sender_type='office' AND sender_id='$office_esc' AND receiver_id='$student_id'))
                                  AND id > $since_id
                                  ORDER BY created_at ASC");

        $result = [];
        while ($msg = $messages->fetch_assoc()) {
            $result[] = [
                'id' => intval($msg['id']),
                'sender_type' => $msg['sender_type'],
                'sender_id' => $msg['sender_id'],
                'message' => $msg['message'],
                'file_path' => $msg['file_path'],
                'file_name' => $msg['file_name'],
                'file_type' => $msg['file_type'],
                'is_read' => intval($msg['is_read']),
                'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
            ];
        }
        echo json_encode($result);
        exit;
    }

    // ---------- MARK INDIVIDUAL READ ----------
    if ($_POST['action'] == 'mark_chat_read' && isset($_SESSION['student'])) {
        $office_name = sanitize($_POST['office_name'] ?? '');
        $office_esc = $conn->real_escape_string($office_name);

        $conn->query("UPDATE chmsu_chat_messages SET is_read = 1 
                      WHERE sender_type='office' AND sender_id='$office_esc' AND receiver_id='$student_id'");
        $conn->query("UPDATE chmsu_chat_conversations SET student_unread = 0 
                      WHERE student_id='$student_id' AND office_name='$office_esc'");
        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- CLEAR INDIVIDUAL CHAT ----------
    if ($_POST['action'] == 'clear_chat' && isset($_SESSION['student'])) {
        $office_name = sanitize($_POST['office_name'] ?? '');
        $office_esc = $conn->real_escape_string($office_name);
        $conn->query("DELETE FROM chmsu_chat_messages 
                      WHERE (sender_type='student' AND sender_id='$student_id' AND receiver_id='$office_esc')
                      OR (sender_type='office' AND sender_id='$office_esc' AND receiver_id='$student_id')");
        $conn->query("DELETE FROM chmsu_chat_conversations 
                      WHERE student_id='$student_id' AND office_name='$office_esc'");
        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- GET UNREAD COUNT ----------
    if ($_POST['action'] == 'get_unread_count' && isset($_SESSION['student'])) {
        $ovx = st_overview($conn, $student_id, $myCourse, $myYear, $mySection);
        echo json_encode(['total' => intval($ovx['total'])]);
        exit;
    }
}

// ============================================
// LIST DATA FOR FIRST PAINT (same function the live refresh uses)
// ============================================
$ov = st_overview($conn, $student_id, $myCourse, $myYear, $mySection);
$allOffices = $ov['individual'];
$allGroups  = $ov['group'];
$chat_unread = intval($ov['total']);

// ============================================
// NOTIFICATION TAP: if there is an unread chat alert (e.g. "You were mentioned"),
// open that office's group chat so the alert is seen and cleared.
// ============================================
$autoOpenOffice = '';
try {
    $ncols = st_tableCols($conn, 'chmsu_notifications');
    if (in_array('student_id', $ncols, true) && in_array('type', $ncols, true)
        && in_array('is_read', $ncols, true) && in_array('office_name', $ncols, true) && in_array('id', $ncols, true)) {
        $sid_n = $conn->real_escape_string($student_id);
        $nr = $conn->query("SELECT office_name FROM chmsu_notifications
                            WHERE student_id='$sid_n' AND type='chat' AND is_read=0
                            ORDER BY id DESC LIMIT 1");
        if ($nr && $nr->num_rows > 0) {
            $autoOpenOffice = st_canonOffice($conn, $nr->fetch_assoc()['office_name']);
        }
    }
} catch (Throwable $e) { $autoOpenOffice = ''; }

function st_badgeText($n) { return $n > 99 ? '99+' : (string)intval($n); }
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
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f0f2f5; font-size: 14px; }

        .header {
            background: #1b4d3e; color: white; padding: 12px 20px;
            display: flex; align-items: center; gap: 15px;
            position: sticky; top: 0; z-index: 1000;
            height: 64px;
        }
        .header-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 18px; font-weight: 600; margin: 0; }
        .header-title p { font-size: 11px; opacity: 0.8; margin: 0; }
        .dark-mode-toggle, .logout-btn {
            background: transparent; border: 1px solid rgba(255,255,255,0.3);
            color: white; padding: 5px 12px; cursor: pointer; border-radius: 4px; font-size: 12px;
        }
        .logout-btn { background: #e74c3c; border: none; margin-left: auto; }
        .menu-btn {
            display: none; background: transparent; border: 1px solid rgba(255,255,255,0.3);
            color: white; width: 38px; height: 38px; border-radius: 6px; cursor: pointer; font-size: 16px;
            align-items: center; justify-content: center; flex-shrink: 0;
        }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }

        .dashboard-wrapper { display: flex; min-height: calc(100vh - 64px); }
        .dark-sidebar {
            width: 260px; background: #1b4d3e; color: white; position: fixed;
            height: calc(100vh - 64px); overflow-y: auto; flex-shrink: 0;
        }
        .dark-sidebar::-webkit-scrollbar { width: 4px; }
        .dark-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .dark-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 4px; }
        .dark-sidebar .sidebar-header { padding: 18px 20px; border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-header h3 { font-size: 15px; font-weight: 600; margin: 0; }
        .dark-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin: 3px 0 0; }
        .dark-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .dark-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-menu a {
            display: block; padding: 12px 20px; color: white; text-decoration: none;
            font-size: 13px; transition: 0.2s;
        }
        .dark-sidebar .sidebar-menu a:hover,
        .dark-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000; }
        .sidebar-badge {
            background: #e74c3c; color: white; border-radius: 10px;
            padding: 1px 7px; font-size: 9px; margin-left: 5px;
            animation: pulse 1.5s infinite;
        }

        .main-content {
            flex: 1; margin-left: 260px; padding: 15px; background: #f0f2f5; min-height: calc(100vh - 64px);
        }
        .dashboard-header-bar {
            background: white; padding: 10px 20px; border-radius: 8px;
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: 600; color: #1b4d3e; margin: 0; }

        .chat-container {
            display: flex; height: calc(100vh - 170px);
            background: white; border-radius: 10px; overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            min-height: 0;
        }

        .chat-sidebar {
            width: 340px; background: #fff; border-right: 1px solid #e4e6eb;
            display: flex; flex-direction: column; flex-shrink: 0;
            min-height: 0;
        }
        .chat-sidebar-header {
            padding: 14px 16px; border-bottom: 1px solid #e4e6eb;
            display: flex; justify-content: space-between; align-items: center; background: #fff;
        }
        .chat-sidebar-header h4 { font-size: 16px; font-weight: 600; color: #050505; margin: 0; }
        .chat-sidebar-header .badge {
            background: #e74c3c; color: white; border-radius: 10px;
            padding: 2px 8px; font-size: 11px; font-weight: 700;
        }

        .mode-toggle {
            display: flex; justify-content: center; background: #e4e6eb;
            border-radius: 20px; padding: 4px; margin: 10px 16px;
        }
        .mode-toggle button {
            flex: 1; border: none; background: transparent; padding: 6px;
            border-radius: 16px; font-size: 12px; font-weight: 600;
            color: #65676b; cursor: pointer; transition: 0.2s;
        }
        .mode-toggle button.active {
            background: #fff; color: #1b4d3e; box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        /* "You were mentioned" alert (stays until you open that chat) */
        .mention-alert {
            display: none; margin: 0 16px 8px; padding: 9px 12px; border-radius: 10px;
            background: #fff3cd; border: 1px solid #ffe08a; border-left: 4px solid #f39c12;
            color: #7d5a00; font-size: 12px; cursor: pointer; line-height: 1.4;
        }
        .mention-alert.show { display: block; animation: pulse 1.6s 2; }
        .mention-alert b { color: #5c4200; }
        .mention-alert .ma-tap { font-size: 10px; opacity: .8; display: block; margin-top: 2px; }

        .chat-search { padding: 10px 16px; border-bottom: 1px solid #e4e6eb; position: relative; }
        .chat-search input {
            width: 100%; padding: 8px 14px; border: none; border-radius: 20px;
            font-size: 13px; outline: none; background: #f0f2f5;
        }
        .chat-search input:focus { background: #fff; box-shadow: 0 0 0 2px #1b4d3e30; }

        .list-wrapper { display: flex; flex-direction: column; flex: 1; overflow: hidden; }
        .list-scroll { flex: 1; overflow-y: auto; }
        .list-scroll::-webkit-scrollbar { width: 4px; }
        .list-scroll::-webkit-scrollbar-track { background: #f0f2f5; }
        .list-scroll::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }

        .chat-office-item {
            display: flex; align-items: center; padding: 10px 16px;
            cursor: pointer; transition: 0.15s; border-left: 3px solid transparent;
        }
        .chat-office-item:hover { background: #f0f2f5; }
        .chat-office-item.active { background: #e7f3ff; border-left-color: #1b4d3e; }

        .chat-office-item .avatar-circle {
            width: 44px; height: 44px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 600; color: white; flex-shrink: 0; margin-right: 12px;
        }
        .chat-office-item .office-info { flex: 1; min-width: 0; }
        .chat-office-item .office-name { font-size: 14px; font-weight: 600; color: #050505; display: flex; align-items: center; gap: 6px; }
        .chat-office-item .office-name-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .chat-office-item .office-last {
            font-size: 12px; color: #65676b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .chat-office-item.has-unread .office-last { color: #050505; font-weight: 700; }
        .chat-office-item.has-unread .chat-item-time { color: #1b4d3e; font-weight: 700; }
        .chat-office-item .chat-item-meta {
            display: flex; flex-direction: column; align-items: flex-end;
            flex-shrink: 0; margin-left: 8px; min-width: 44px;
        }
        .chat-office-item .chat-item-time { font-size: 11px; color: #65676b; min-height: 14px; }
        .chat-office-item .unread-badge, .chat-office-item .group-unread-badge {
            background: #e74c3c; color: white; border-radius: 10px;
            padding: 2px 7px; font-size: 10px; min-width: 20px;
            text-align: center; margin-top: 4px; font-weight: 700;
        }
        .list-header-row {
            padding: 8px 16px 4px; font-size: 10px; font-weight: 600; color: #65676b;
            text-transform: uppercase; letter-spacing: 0.5px;
            background: #f8f9fa; border-bottom: 1px solid #e4e6eb;
        }

        /* OFFICE tag + mention flag: easy to tell it is an office */
        .office-pill {
            display: inline-block; background: #f1c40f; color: #1b4d3e;
            font-size: 8px; font-weight: 800; padding: 1px 6px; border-radius: 8px;
            letter-spacing: .5px; line-height: 1.5; flex-shrink: 0; vertical-align: middle;
        }
        .mention-flag {
            display: none; background: #f39c12; color: #fff; width: 16px; height: 16px;
            border-radius: 50%; font-size: 10px; font-weight: 800; line-height: 16px;
            text-align: center; flex-shrink: 0;
        }

        .chat-area { flex: 1; display: flex; flex-direction: column; background: #fff; min-height: 0; min-width: 0; }
        .chat-area-header {
            padding: 12px 20px; border-bottom: 1px solid #e4e6eb;
            display: flex; align-items: center; gap: 12px; background: #fff;
        }
        .chat-area-header .back-btn {
            display: none; background: none; border: none; font-size: 20px; cursor: pointer; color: #65676b;
        }
        .chat-area-header .header-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 600; color: white; flex-shrink: 0;
        }
        .chat-area-header .header-info { flex: 1; min-width: 0; }
        .chat-area-header .chat-with { font-size: 15px; font-weight: 600; color: #050505; }
        .chat-area-header .chat-type { font-size: 11px; color: #65676b; }
        .chat-area-header .chat-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; }
        .chat-area-header .chat-actions button {
            background: none; border: none; cursor: pointer; font-size: 16px; color: #65676b;
            padding: 4px 8px; border-radius: 4px;
        }
        .chat-area-header .chat-actions button:hover { background: #f0f2f5; }

        /* PINNED MENTION (your course / year / section was tagged) */
        .pinned-mention-banner {
            display: none; padding: 9px 14px; background: #fff3cd;
            border-bottom: 1px solid #ffeaa7; border-left: 4px solid #f39c12;
            font-size: 13px; color: #7d5a00; align-items: center; gap: 10px; cursor: pointer;
        }
        .pinned-mention-banner.show { display: flex; }
        .pinned-mention-banner .pm-icon { font-size: 16px; color: #f39c12; flex-shrink: 0; }
        .pinned-mention-banner .pm-content { flex: 1; min-width: 0; }
        .pinned-mention-banner .pm-label { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #b9770e; font-weight: 700; }
        .pinned-mention-banner .pm-text { font-weight: 600; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pinned-mention-banner .pm-close { background: transparent; border: none; color: #b9770e; cursor: pointer; font-size: 18px; padding: 2px 8px; flex-shrink: 0; }

        .chat-messages { flex: 1; overflow-y: auto; padding: 16px 20px; background: #fafafa; min-height: 0; }

        .message { display: flex; margin-bottom: 8px; max-width: 75%; clear: both; }
        .message.sent { float: right; flex-direction: row-reverse; }
        .message.received { float: left; }

        .new-divider {
            clear: both; display: flex; align-items: center; gap: 10px;
            margin: 12px 0; color: #e74c3c; font-size: 11px; font-weight: 700;
        }
        .new-divider::before, .new-divider::after { content: ''; flex: 1; height: 1px; background: #f5b7b1; }
        .seen-line {
            clear: both; text-align: right; font-size: 10px; color: #65676b;
            padding: 0 4px 6px; font-weight: 600;
        }

        .message .msg-avatar {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 600; color: white; flex-shrink: 0; margin: 0 8px;
        }
        .message.sent .msg-avatar { display: none; }

        .message .bubble {
            padding: 8px 14px; border-radius: 18px; font-size: 14px;
            word-wrap: break-word; position: relative; max-width: 100%;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .message.sent .bubble {
            background: #1b4d3e; color: white; border-bottom-right-radius: 4px;
        }
        .message.received .bubble {
            background: white; color: #050505; border-bottom-left-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }
        /* office messages look different from classmates */
        .message.office-msg .bubble {
            background: #eaf4ee; border-left: 4px solid #1b4d3e;
        }
        .message.office-msg .msg-avatar { border: 2px solid #f1c40f; }
        .message.mention-me .bubble {
            background: #fff3cd; border-left: 4px solid #f39c12; color: #5c4200;
        }
        .message.flash .bubble { animation: pulse 0.6s 2; box-shadow: 0 0 0 3px #f1c40f; }
        .message .bubble .msg-sender {
            font-size: 11px; font-weight: 600; color: #1b4d3e; display: flex; align-items: center; gap: 6px; margin-bottom: 3px;
        }
        .message .bubble .mention-chip {
            display: inline-block; background: #f39c12; color: #fff; padding: 0 7px;
            border-radius: 10px; font-size: 12px; font-weight: 700;
        }
        .message .bubble .msg-time {
            font-size: 10px; opacity: 0.6; display: block; margin-top: 3px; text-align: right;
        }
        .message.sent .bubble .msg-time { color: #ddd; }
        .message.received .bubble .msg-time { color: #888; }

        .message .bubble .msg-status {
            font-size: 10px; display: inline-block; margin-left: 6px; opacity: 0.8;
        }
        .message.sent .bubble .msg-status.seen { color: #a3e4d7; font-weight: 600; }
        .message.sent .bubble .msg-status.delivered { color: #bdc3c7; }

        .message .bubble .file-box {
            display: flex; align-items: center; gap: 10px; padding: 10px 14px;
            background: rgba(255,255,255,0.08); border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.1); margin-top: 5px;
            flex-wrap: wrap;
        }
        .message.received .bubble .file-box { background: #f0f2f5; border: 1px solid #e4e6eb; }
        .message .bubble .file-box .file-icon { font-size: 24px; flex-shrink: 0; }
        .message .bubble .file-box .file-info { flex: 1; min-width: 100px; }
        .message .bubble .file-box .file-name { font-size: 13px; font-weight: 500; word-break: break-all; }
        .message .bubble .file-box .file-size { font-size: 11px; opacity: 0.6; }
        .message .bubble .file-box .file-actions { display: flex; gap: 6px; flex-shrink: 0; }
        .message .bubble .file-box .file-actions button {
            border: none; border-radius: 4px; cursor: pointer;
            padding: 4px 10px; font-size: 11px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 4px;
            transition: 0.15s;
        }
        .message.sent .bubble .file-box .file-actions .btn-view-file {
            background: rgba(255,255,255,0.2); color: white;
        }
        .message.sent .bubble .file-box .file-actions .btn-view-file:hover {
            background: rgba(255,255,255,0.35);
        }
        .message.sent .bubble .file-box .file-actions .btn-dl-file {
            background: #f1c40f; color: #1b4d3e;
        }
        .message.sent .bubble .file-box .file-actions .btn-dl-file:hover {
            background: #d4a800;
        }
        .message.received .bubble .file-box .file-actions .btn-view-file {
            background: #1b4d3e; color: white;
        }
        .message.received .bubble .file-box .file-actions .btn-view-file:hover {
            background: #2d6a4f;
        }
        .message.received .bubble .file-box .file-actions .btn-dl-file {
            background: #f1c40f; color: #1b4d3e;
        }
        .message.received .bubble .file-box .file-actions .btn-dl-file:hover {
            background: #d4a800;
        }

        .no-chat-selected {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 100%; color: #999; padding: 40px;
        }
        .no-chat-selected i { font-size: 56px; margin-bottom: 12px; color: #ddd; }
        .no-chat-selected h3 { color: #050505; margin-bottom: 4px; }

        .quick-replies {
            display: flex; flex-wrap: wrap; gap: 6px; padding: 6px 16px;
            background: #f8f9fa; border-top: 1px solid #e4e6eb;
        }
        .quick-replies .qr-btn {
            background: #e7f3ff; color: #1b4d3e; border: none;
            border-radius: 16px; padding: 4px 14px; font-size: 12px; cursor: pointer;
        }
        .quick-replies .qr-btn:hover { background: #1b4d3e; color: white; }

        .staged-file-box { display: none; padding: 10px 16px; background: #f8f9fa; border-top: 1px solid #e4e6eb; }
        .staged-file-box.show { display: block; }
        .staged-file-box .staged-preview { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: #fff; border-radius: 8px; border: 1px solid #e4e6eb; }
        .staged-file-box .staged-preview .staged-icon { font-size: 26px; color: #1b4d3e; flex-shrink: 0; }
        .staged-file-box .staged-preview .staged-info { flex: 1; min-width: 0; }
        .staged-file-box .staged-preview .staged-name { font-size: 13px; font-weight: 500; color: #050505; word-break: break-all; }
        .staged-file-box .staged-preview .staged-size { font-size: 11px; color: #888; }
        .staged-file-box .staged-preview .staged-remove { background: none; border: none; color: #e74c3c; cursor: pointer; font-size: 18px; flex-shrink: 0; padding: 4px 8px; }
        .staged-file-box .staged-preview .staged-remove:hover { color: #c0392b; }
        .staged-file-box .staged-hint { font-size: 11px; color: #888; margin-top: 6px; text-align: center; }

        .chat-input-area {
            padding: 8px 16px 12px; border-top: 1px solid #e4e6eb; background: white;
            display: flex; gap: 8px; align-items: flex-end;
        }
        .chat-input-area .input-wrapper {
            flex: 1; display: flex; align-items: center; background: #f0f2f5;
            border-radius: 20px; padding: 4px 12px; border: 1px solid transparent; transition: 0.2s;
        }
        .chat-input-area .input-wrapper:focus-within { border-color: #1b4d3e; background: white; }
        .chat-input-area .input-wrapper input {
            flex: 1; border: none; background: transparent; padding: 8px 4px;
            font-size: 14px; outline: none; font-family: inherit; min-width: 0;
        }
        .chat-input-area .action-btn {
            width: 38px; height: 38px; border: none; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .chat-input-area .file-btn { background: #e4e6eb; color: #65676b; position: relative; }
        .chat-input-area .file-btn:hover { background: #d0d2d5; }
        .chat-input-area .file-btn input[type="file"] {
            position: absolute; width: 100%; height: 100%; opacity: 0; cursor: pointer;
        }
        .chat-input-area .clear-btn { background: #e4e6eb; color: #e74c3c; }
        .chat-input-area .clear-btn:hover { background: #fdecea; }
        .chat-input-area .send-btn { background: #1b4d3e; color: white; }
        .chat-input-area .send-btn:hover { background: #2d6a4f; }

        .storage-info { padding: 12px 20px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px; }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050; }
        .sidebar-overlay.show { display: block; }

        .file-viewer-overlay {
            display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.85); z-index: 99999; justify-content: center; align-items: center;
            padding: 20px; backdrop-filter: blur(3px);
        }
        .file-viewer-overlay.active { display: flex; }
        .file-viewer-modal {
            background: #fff; width: 100%; max-width: 900px; height: 90vh;
            border-radius: 10px; display: flex; flex-direction: column;
            overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            animation: fileViewerPop 0.25s ease;
        }
        @keyframes fileViewerPop { from { opacity: 0; transform: scale(0.96); } to { opacity: 1; transform: scale(1); } }
        .file-viewer-header { background: #1b4d3e; color: white; padding: 12px 18px; display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .file-viewer-header .fv-icon { font-size: 20px; }
        .file-viewer-header .fv-name { flex: 1; min-width: 0; font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-viewer-header .fv-btn { background: #f1c40f; color: #1b4d3e; border: none; border-radius: 4px; cursor: pointer; padding: 6px 14px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .file-viewer-header .fv-btn:hover { background: #d4a800; }
        .file-viewer-header .fv-close { background: transparent; color: white; border: 1px solid rgba(255,255,255,0.4); border-radius: 4px; cursor: pointer; padding: 6px 12px; font-size: 14px; }
        .file-viewer-header .fv-close:hover { background: rgba(255,255,255,0.15); }
        .file-viewer-body { flex: 1; overflow: auto; background: #f0f2f5; display: flex; align-items: center; justify-content: center; padding: 15px; }
        .file-viewer-body iframe { width: 100%; height: 100%; border: none; border-radius: 6px; background: #fff; }
        .file-viewer-body img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
        .file-viewer-body .fv-fallback { text-align: center; color: #555; padding: 40px 20px; }
        .file-viewer-body .fv-fallback .fv-fallback-icon { font-size: 72px; color: #1b4d3e; margin-bottom: 20px; }
        .file-viewer-body .fv-fallback h3 { font-size: 18px; margin-bottom: 8px; color: #1b4d3e; }
        .file-viewer-body .fv-fallback p { font-size: 13px; color: #666; margin-bottom: 20px; }
        .file-viewer-body .fv-fallback a { display: inline-block; padding: 10px 24px; background: #1b4d3e; color: white; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600; }
        .file-viewer-body .fv-fallback a:hover { background: #2d6a4f; }

        body.dark-mode .file-viewer-modal { background: #1a1a1a; }
        body.dark-mode .file-viewer-body { background: #2c2c2c; }
        body.dark-mode .file-viewer-body .fv-fallback h3 { color: #f1c40f; }
        body.dark-mode .file-viewer-body .fv-fallback p { color: #ccc; }
        body.dark-mode .file-viewer-body .fv-fallback .fv-fallback-icon { color: #f1c40f; }

        /* Dark mode */
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
        body.dark-mode .chat-office-item:hover { background: #3e4042; }
        body.dark-mode .chat-office-item.active { background: #2d3a4f; border-left-color: #f1c40f; }
        body.dark-mode .chat-office-item .office-name { color: #e4e6eb; }
        body.dark-mode .chat-office-item .office-last,
        body.dark-mode .chat-office-item .chat-item-time { color: #b0b3b8; }
        body.dark-mode .chat-office-item.has-unread .office-last { color: #e4e6eb; }
        body.dark-mode .chat-office-item.has-unread .chat-item-time { color: #f1c40f; }
        body.dark-mode .chat-area-header .chat-with { color: #e4e6eb; }
        body.dark-mode .chat-area-header .chat-type { color: #b0b3b8; }
        body.dark-mode .chat-messages { background: #18191a; }
        body.dark-mode .message.received .bubble { background: #3e4042; color: #e4e6eb; }
        body.dark-mode .message.received .bubble .msg-time { color: #b0b3b8; }
        body.dark-mode .message.received .bubble .msg-sender { color: #f1c40f; }
        body.dark-mode .message.office-msg .bubble { background: #1f3a30; border-left-color: #f1c40f; color: #e4e6eb; }
        body.dark-mode .message.mention-me .bubble { background: #3a2e0f; border-left-color: #f1c40f; color: #f1c40f; }
        body.dark-mode .mention-alert { background: #3a2e0f; border-color: #5a4a1f; color: #f1c40f; }
        body.dark-mode .mention-alert b { color: #fff; }
        body.dark-mode .pinned-mention-banner { background: #3a2e0f; border-bottom-color: #5a4a1f; border-left-color: #f1c40f; color: #f1c40f; }
        body.dark-mode .pinned-mention-banner .pm-label { color: #f1c40f; }
        body.dark-mode .pinned-mention-banner .pm-text { color: #fff; }
        body.dark-mode .seen-line { color: #b0b3b8; }
        body.dark-mode .quick-replies { background: #242526; border-color: #3e4042; }
        body.dark-mode .quick-replies .qr-btn { background: #3e4042; color: #f1c40f; }
        body.dark-mode .quick-replies .qr-btn:hover { background: #f1c40f; color: #000; }
        body.dark-mode .chat-input-area .input-wrapper { background: #3e4042; }
        body.dark-mode .chat-input-area .input-wrapper input { color: #e4e6eb; }
        body.dark-mode .chat-input-area .file-btn { background: #3e4042; color: #b0b3b8; }
        body.dark-mode .chat-input-area .clear-btn { background: #3e4042; color: #e74c3c; }
        body.dark-mode .no-chat-selected h3 { color: #e4e6eb; }
        body.dark-mode .no-chat-selected i { color: #3e4042; }
        body.dark-mode .list-header-row { background: #1a1a1a; color: #b0b3b8; border-color: #3e4042; }
        body.dark-mode .message .bubble .file-box { background: #3e4042; border-color: #4a4c4e; }
        body.dark-mode .staged-file-box { background: #242526; border-color: #3e4042; }
        body.dark-mode .staged-file-box .staged-preview { background: #3e4042; border-color: #4a4c4e; }
        body.dark-mode .staged-file-box .staged-preview .staged-name { color: #e4e6eb; }
        body.dark-mode .staged-file-box .staged-preview .staged-size { color: #b0b3b8; }
        body.dark-mode .staged-file-box .staged-preview .staged-icon { color: #f1c40f; }
        body.dark-mode .mode-toggle { background: #1a1a1a; }
        body.dark-mode .mode-toggle button.active { background: #3e4042; color: #f1c40f; }

        /* ===== MOBILE: sidebar becomes a drawer, chat works like a phone messenger ===== */
        @media (max-width: 768px) {
            .menu-btn { display: inline-flex; }
            .header-title p { display: none; }
            .dark-sidebar {
                transform: translateX(-100%); transition: transform .25s ease;
                z-index: 1100; box-shadow: 4px 0 20px rgba(0,0,0,.3);
            }
            .dark-sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 8px; }
            .dashboard-header-bar { padding: 8px 14px; margin-bottom: 8px; }
            .chat-container { height: calc(100vh - 140px); height: calc(100dvh - 140px); position: relative; }
            .chat-sidebar { width: 100%; max-height: none; flex: 1; border-right: none; }
            .chat-area { display: none; width: 100%; }
            .chat-container.chat-open .chat-sidebar { display: none; }
            .chat-container.chat-open .chat-area { display: flex; }
            .chat-area-header { padding: 10px 12px; }
            .chat-area-header .back-btn { display: block; }
            .chat-messages { padding: 12px; }
            .message { max-width: 88%; }
            .quick-replies { overflow-x: auto; flex-wrap: nowrap; }
            .quick-replies .qr-btn { white-space: nowrap; }
            .file-viewer-modal { height: 95vh; }
        }
    </style>
</head>
<body>

<div class="header">
    <button class="menu-btn" onclick="toggleSidebar()" aria-label="Open menu"><i class="fas fa-bars"></i></button>
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CHMSU E-CLEARANCE</h1>
        <p>Student Portal - Messages</p>
    </div>

    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i>
    </button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="dashboard-wrapper">
    <div class="dark-sidebar" id="darkSidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($master['chmsu_full_name']); ?></h3>
            <p><?php echo htmlspecialchars($student['chmsu_course']); ?> <?php echo $student['chmsu_year']; ?><?php echo htmlspecialchars($student['chmsu_section']); ?></p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=home"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=feed"><i class="fas fa-rss"></i> Updates Feed</a></li>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=chat" class="active">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php if ($chat_unread > 0): ?>
                        <span class="sidebar-badge" id="sidebarChatBadge"><?php echo st_badgeText($chat_unread); ?></span>
                    <?php else: ?>
                        <span class="sidebar-badge" id="sidebarChatBadge" style="display:none;">0</span>
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
        <div class="dashboard-header-bar">
            <h2><i class="fas fa-comment-dots"></i> Messages</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>

        <div class="chat-container" id="chatContainer">
            <div class="chat-sidebar">
                <div class="chat-sidebar-header">
                    <h4><i class="fas fa-comment"></i> Messages</h4>
                    <!-- unread total (was a fixed office count before) -->
                    <span class="badge" id="sidebarCountBadge" <?php echo $chat_unread > 0 ? '' : 'style="display:none;"'; ?>><?php echo st_badgeText($chat_unread); ?></span>
                </div>

                <div class="mode-toggle">
                    <button id="btnIndividual" class="active" onclick="toggleSidebarMode('individual')">Individual</button>
                    <button id="btnGroup" onclick="toggleSidebarMode('group')">Group</button>
                </div>

                <!-- You were mentioned (course+year+section, or whole year) -->
                <div class="mention-alert" id="mentionAlert" onclick="openMentionAlert()"></div>

                <div class="chat-search">
                    <input type="text" id="officeSearchInput" placeholder="🔍 Search..." onkeyup="filterOffices()">
                </div>

                <!-- INDIVIDUAL LIST -->
                <div class="list-wrapper" id="individualWrapper">
                    <div class="list-header-row"><i class="fas fa-building"></i> Offices</div>
                    <div class="list-scroll" id="officeList">
                        <?php if (count($allOffices) > 0): ?>
                            <?php foreach ($allOffices as $o):
                                $unread = intval($o['unread']);
                                $initials = st_getInitials($o['name']);
                                $color = st_getAvatarColor($o['name']);
                            ?>
                                <div class="chat-office-item<?php echo $unread > 0 ? ' has-unread' : ''; ?>"
                                     data-kind="individual"
                                     data-name="<?php echo htmlspecialchars(strtolower($o['name']), ENT_QUOTES); ?>"
                                     data-office="<?php echo htmlspecialchars($o['name'], ENT_QUOTES); ?>"
                                     data-unread="<?php echo $unread; ?>"
                                     data-mention-unread="0"
                                     data-first-unread="<?php echo intval($o['first_unread']); ?>"
                                     onclick="selectOffice('<?php echo addslashes($o['name']); ?>')">
                                    <div class="avatar-circle" style="background: <?php echo $color; ?>;">
                                        <?php echo $initials; ?>
                                    </div>
                                    <div class="office-info">
                                        <div class="office-name"><span class="office-name-text"><?php echo htmlspecialchars($o['name']); ?></span><span class="office-pill">OFFICE</span></div>
                                        <div class="office-last"><?php echo htmlspecialchars($o['preview']); ?></div>
                                    </div>
                                    <div class="chat-item-meta">
                                        <span class="chat-item-time"><?php echo htmlspecialchars($o['time_label']); ?></span>
                                        <span class="unread-badge" data-individual-unread="<?php echo htmlspecialchars($o['name'], ENT_QUOTES); ?>" <?php echo $unread > 0 ? '' : 'style="display:none;"'; ?>><?php echo st_badgeText($unread); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 20px; text-align: center; color: #999;">No offices found</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- GROUP LIST -->
                <div class="list-wrapper" id="groupWrapper" style="display:none;">
                    <div class="list-header-row"><i class="fas fa-graduation-cap"></i> Group Chats (Your Course)</div>
                    <div class="list-scroll" id="groupList">
                        <?php if (count($allGroups) > 0): ?>
                            <?php foreach ($allGroups as $o):
                                $gu = intval($o['unread']);
                                $gmn = intval($o['mention_unread']);
                                $initials = st_getInitials($o['name']);
                                $color = st_getAvatarColor($o['name']);
                            ?>
                                <div class="chat-office-item group-item<?php echo $gu > 0 ? ' has-unread' : ''; ?>"
                                     data-kind="group"
                                     data-name="<?php echo htmlspecialchars(strtolower($o['name']), ENT_QUOTES); ?>"
                                     data-office="<?php echo htmlspecialchars($o['name'], ENT_QUOTES); ?>"
                                     data-unread="<?php echo $gu; ?>"
                                     data-mention-unread="<?php echo $gmn; ?>"
                                     data-first-unread="<?php echo intval($o['first_unread']); ?>"
                                     onclick="selectGroupOffice('<?php echo addslashes($o['name']); ?>')">
                                    <div class="avatar-circle" style="background: <?php echo $color; ?>;">
                                        <?php echo $initials; ?>
                                    </div>
                                    <div class="office-info">
                                        <div class="office-name"><span class="office-name-text"><?php echo htmlspecialchars($o['name']); ?></span><span class="office-pill">OFFICE</span><span class="mention-flag" title="You were mentioned" <?php echo $gmn > 0 ? 'style="display:inline-block;"' : ''; ?>>@</span></div>
                                        <div class="office-last"><?php echo htmlspecialchars($o['preview']); ?></div>
                                    </div>
                                    <div class="chat-item-meta">
                                        <span class="chat-item-time"><?php echo htmlspecialchars($o['time_label']); ?></span>
                                        <span class="group-unread-badge" data-group-unread="<?php echo htmlspecialchars($o['name'], ENT_QUOTES); ?>" <?php echo $gu > 0 ? '' : 'style="display:none;"'; ?>><?php echo st_badgeText($gu); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 20px; text-align: center; color: #999;">No group chats available</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="chat-area" id="chatArea">
                <div class="chat-area-header">
                    <button class="back-btn" onclick="closeChat()"><i class="fas fa-arrow-left"></i></button>
                    <div class="header-avatar" id="headerAvatar" style="background: #1b4d3e; display: none;">?</div>
                    <div class="header-info">
                        <div class="chat-with" id="chatWith">Select an office</div>
                        <div class="chat-type" id="chatStatus">Click an office to start chatting</div>
                    </div>
                    <div class="chat-actions">
                        <span class="office-pill" id="headerOfficePill" style="display:none;">OFFICE</span>
                        <button onclick="clearChatHistory()" title="Clear chat">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>

                <!-- PINNED MENTION: shows when your course/year/section was tagged -->
                <div class="pinned-mention-banner" id="pinnedMentionBanner" onclick="jumpToPinned()">
                    <i class="fas fa-thumbtack pm-icon"></i>
                    <div class="pm-content">
                        <div class="pm-label" id="pmLabel">Pinned — you were mentioned</div>
                        <div class="pm-text" id="pmText">—</div>
                    </div>
                    <button class="pm-close" onclick="dismissPinned(event)" title="Dismiss">&times;</button>
                </div>

                <div class="chat-messages" id="chatMessages">
                    <div class="no-chat-selected">
                        <i class="fas fa-comment-dots"></i>
                        <h3>No conversation selected</h3>
                        <p style="color: #999; font-size: 13px;">Select an office from the left to start chatting</p>
                    </div>
                </div>

                <div class="quick-replies" id="quickReplies">
                    <button class="qr-btn" onclick="sendQuickReply('Hello')">Hello</button>
                    <button class="qr-btn" onclick="sendQuickReply('Clearance Status')">Clearance Status</button>
                    <button class="qr-btn" onclick="sendQuickReply('Requirements')">Requirements</button>
                    <button class="qr-btn" onclick="sendQuickReply('Help')">Help</button>
                    <button class="qr-btn" onclick="sendQuickReply('Thank you')">Thank you</button>
                </div>

                <!-- STAGED FILE PREVIEW (before send) -->
                <div class="staged-file-box" id="stagedFileBox">
                    <div class="staged-preview">
                        <i class="fas fa-file staged-icon" id="stagedIcon"></i>
                        <div class="staged-info">
                            <div class="staged-name" id="stagedName">—</div>
                            <div class="staged-size" id="stagedSize">0 KB</div>
                        </div>
                        <button type="button" class="staged-remove" onclick="removeStagedFile()" title="Remove file">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="staged-hint">
                        📎 File ready. Press <strong>Send</strong> to upload it.
                    </div>
                </div>

                <div class="chat-input-area">
                    <div class="input-wrapper">
                        <input type="text" id="chatInput" placeholder="Type a message..." onkeydown="if(event.key==='Enter') sendChatMessage()">
                    </div>
                    <button class="action-btn file-btn" title="Attach file">
                        <i class="fas fa-paperclip"></i>
                        <input type="file" id="chatFileInput" onchange="stageFile(this)" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt">
                    </button>
                    <button class="action-btn clear-btn" onclick="clearChatHistory()" title="Clear chat">
                        <i class="fas fa-trash"></i>
                    </button>
                    <button class="action-btn send-btn" onclick="sendChatMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- FULLSCREEN FILE VIEWER MODAL -->
<div class="file-viewer-overlay" id="fileViewerOverlay" onclick="closeFileViewer(event)">
    <div class="file-viewer-modal" onclick="event.stopPropagation()">
        <div class="file-viewer-header">
            <i class="fas fa-file file-viewer-fv-icon" id="fvIcon"></i>
            <div class="fv-name" id="fvName">File Preview</div>
            <a class="fv-btn" id="fvDownloadBtn" href="#" download>
                <i class="fas fa-download"></i> Download
            </a>
            <button class="fv-close" onclick="closeFileViewer(event)">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="file-viewer-body" id="fvBody"></div>
    </div>
</div>

<script>
// ============================================
// HELPERS
// ============================================
function getInitials(name) {
    if (!name) return '?';
    const words = name.trim().split(' ');
    let ini = '', c = 0;
    for (let w of words) { if (c < 2 && w.length > 0) { ini += w[0].toUpperCase(); c++; } }
    return ini || '?';
}
function getAvatarColor(name) {
    const colors = ['#1b4d3e', '#2d6a4f', '#f39c12', '#e74c3c', '#3498db', '#9b59b6', '#1abc9c', '#e67e22', '#2ecc71', '#e84393'];
    let hash = 0;
    for (let i = 0; i < (name ? name.length : 0); i++) hash += name.charCodeAt(i);
    return colors[hash % colors.length];
}
function fmtCount(n) { n = parseInt(n) || 0; return n > 99 ? '99+' : String(n); }

// ============================================
// STATE
// ============================================
let selectedOffice = null;
let currentChatType = 'individual';
let groupCourse = '';
let lastRenderedMsgId = 0;
let stagedFile = null;
let pendingDivider = null;       // {id, count} -> "N new messages" line (like Messenger)
let unreadMeta = {};             // 'kind|office' -> {count, first}
let seenTimer = null;
let overviewBusy = false;
const BASE_TITLE = document.title;

const CHAT_URL  = window.location.pathname + '?view=chat';
const myCourse  = "<?php echo addslashes($myCourse); ?>";
const myYear    = "<?php echo addslashes($myYear); ?>";
const mySection = "<?php echo addslashes($mySection); ?>";
const autoOpenOffice = "<?php echo addslashes($autoOpenOffice); ?>";

// POST helper (reads the JSON even if extra HTML was printed before it)
function chatPost(formData) {
    return fetch(CHAT_URL, { method: 'POST', body: formData })
        .then(r => r.text())
        .then(t => {
            const i = t.lastIndexOf('@@CHATJSON@@');
            if (i < 0) throw new Error('Invalid server response');
            return JSON.parse(t.slice(i + 12));
        });
}
function metaKey(kind, office) { return kind + '|' + String(office || '').toLowerCase(); }

// ============================================
// MENTION TEXT: @BSIS4A  and  @4th Year  get a highlighted chip
// ============================================
function highlightMentions(html) {
    return html
        .replace(/@\s*[A-Za-z][A-Za-z\-]{1,14}?\s*\d\s*[A-Za-z](?![A-Za-z])/g, m => '<span class="mention-chip">' + m + '</span>')
        .replace(/@\s*\d\s*(?:st|nd|rd|th)?\s*Year(?!\s*Section)(?![A-Za-z])/gi, m => '<span class="mention-chip">' + m + '</span>');
}

// ============================================
// MOBILE: sidebar drawer + messenger-style panels
// ============================================
function toggleSidebar() {
    document.getElementById('darkSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
function setChatOpenUI(open) {
    const c = document.getElementById('chatContainer');
    if (c) c.classList.toggle('chat-open', !!open);
}

// ============================================
// SIDEBAR TOGGLE
// ============================================
function toggleSidebarMode(mode) {
    if (mode === 'individual') {
        document.getElementById('btnIndividual').classList.add('active');
        document.getElementById('btnGroup').classList.remove('active');
        document.getElementById('individualWrapper').style.display = 'flex';
        document.getElementById('groupWrapper').style.display = 'none';
    } else {
        document.getElementById('btnIndividual').classList.remove('active');
        document.getElementById('btnGroup').classList.add('active');
        document.getElementById('individualWrapper').style.display = 'none';
        document.getElementById('groupWrapper').style.display = 'flex';
    }
    closeChat();
    document.getElementById('officeSearchInput').value = '';
    document.getElementById('officeSearchInput').placeholder = mode === 'individual' ? '🔍 Search office...' : '🔍 Search group...';
    filterOffices();
}

// ============================================
// SEARCH
// ============================================
function filterOffices() {
    const s = document.getElementById('officeSearchInput').value.toLowerCase().trim();
    const isIndiv = document.getElementById('btnIndividual').classList.contains('active');
    const listId = isIndiv ? 'officeList' : 'groupList';

    document.querySelectorAll(`#${listId} .chat-office-item`).forEach(item => {
        const name = item.dataset.name || '';
        item.style.display = name.includes(s) ? 'flex' : 'none';
    });
}

// ============================================
// MENTION ALERT (list area) + PINNED MENTION (inside the chat)
// ============================================
function refreshMentionAlert() {
    const bar = document.getElementById('mentionAlert');
    if (!bar) return;
    let hit = null, total = 0;
    document.querySelectorAll('#groupList .chat-office-item').forEach(el => {
        const n = parseInt(el.dataset.mentionUnread) || 0;
        total += n;
        if (n > 0 && !hit) hit = el;
        const flag = el.querySelector('.mention-flag');
        if (flag) flag.style.display = n > 0 ? 'inline-block' : 'none';
    });
    if (hit) {
        const label = myCourse + myYear + mySection;
        bar.dataset.office = hit.dataset.office || '';
        bar.innerHTML = '🔔 <b>' + escapeHtml(hit.dataset.office || 'An office') + '</b> mentioned <b>' + escapeHtml(label) + '</b>' +
            (total > 1 ? ' (' + total + ' mentions)' : '') +
            '<span class="ma-tap">Tap to view — this alert goes away once you open it</span>';
        bar.classList.add('show');
    } else {
        bar.classList.remove('show');
        bar.innerHTML = '';
        bar.dataset.office = '';
    }
}
function openMentionAlert() {
    const bar = document.getElementById('mentionAlert');
    if (!bar) return;
    const o = bar.dataset.office;
    if (!o) return;
    bar.classList.remove('show');
    bar.innerHTML = '';
    bar.dataset.office = '';
    toggleSidebarMode('group');
    selectGroupOffice(o);
}

let pinnedMsgId = 0;
function renderPinned(p) {
    const banner = document.getElementById('pinnedMentionBanner');
    if (!banner) return;
    if (!p || currentChatType !== 'group' || !selectedOffice) { banner.classList.remove('show'); pinnedMsgId = 0; return; }
    const key = 'pinDismissed_' + selectedOffice.toLowerCase();
    if (localStorage.getItem(key) === String(p.id)) { banner.classList.remove('show'); pinnedMsgId = 0; return; }
    pinnedMsgId = p.id;
    document.getElementById('pmLabel').textContent = 'Pinned — ' + p.office + ' mentioned ' + myCourse + myYear + mySection + ' • ' + p.time;
    document.getElementById('pmText').textContent = p.text;
    banner.classList.add('show');
}
function dismissPinned(ev) {
    ev.stopPropagation();
    if (selectedOffice && pinnedMsgId) localStorage.setItem('pinDismissed_' + selectedOffice.toLowerCase(), String(pinnedMsgId));
    document.getElementById('pinnedMentionBanner').classList.remove('show');
}
function jumpToPinned() {
    if (!pinnedMsgId) return;
    const el = document.querySelector('#chatMessages [data-msg-id="' + pinnedMsgId + '"]');
    if (el) {
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        el.classList.add('flash');
        setTimeout(() => el.classList.remove('flash'), 1600);
    }
}

// ============================================
// MESSENGER LIST: live unread / preview / time / newest on top
// ============================================
function applyOverview(data) {
    if (!data) return;

    ['individual', 'group'].forEach(kind => {
        const container = document.getElementById(kind === 'individual' ? 'officeList' : 'groupList');
        const arr = data[kind];
        if (!container || !Array.isArray(arr)) return;

        const map = {};
        container.querySelectorAll('.chat-office-item').forEach(el => { map[el.dataset.name] = el; });

        const orderedEls = [];
        arr.forEach(e => {
            const key = String(e.name).toLowerCase();
            const el = map[key];
            if (!el) return;
            orderedEls.push(el);

            unreadMeta[metaKey(kind, e.name)] = { count: e.unread, first: e.first_unread };

            const isOpen = selectedOffice && currentChatType === kind &&
                           selectedOffice.toLowerCase() === key && !document.hidden;
            const shown = isOpen ? 0 : parseInt(e.unread) || 0;
            const mShown = isOpen ? 0 : parseInt(e.mention_unread) || 0;

            el.dataset.unread = shown;
            el.dataset.mentionUnread = mShown;
            el.dataset.firstUnread = e.first_unread;
            el.classList.toggle('has-unread', shown > 0);

            const badge = el.querySelector('.unread-badge, .group-unread-badge');
            if (badge) {
                badge.textContent = fmtCount(shown);
                badge.style.display = shown > 0 ? '' : 'none';
            }
            const prev = el.querySelector('.office-last');
            if (prev) prev.textContent = e.preview || '';
            const tm = el.querySelector('.chat-item-time');
            if (tm) tm.textContent = e.time_label || '';
        });

        // newest conversation first
        const current = Array.prototype.slice.call(container.querySelectorAll('.chat-office-item'));
        let same = current.length === orderedEls.length;
        for (let i = 0; same && i < current.length; i++) { if (current[i] !== orderedEls[i]) same = false; }
        if (!same) orderedEls.forEach(el => container.appendChild(el));
    });

    // accurate total: only the chats that are not being read right now
    let total = 0;
    document.querySelectorAll('#officeList .chat-office-item, #groupList .chat-office-item').forEach(el => {
        total += parseInt(el.dataset.unread) || 0;
    });
    applyUnreadTotal(total);
    refreshMentionAlert();
}

function refreshOverview() {
    if (overviewBusy) return Promise.resolve();
    overviewBusy = true;
    const fd = new FormData();
    fd.append('action', 'chat_overview');
    return chatPost(fd)
        .then(applyOverview)
        .catch(() => {})
        .then(() => { overviewBusy = false; });
}

// Optimistic "just sent" move to top (server refresh confirms right after)
function moveChatToTop(officeName, previewText) {
    const currentMode = document.getElementById('btnGroup').classList.contains('active') ? 'group' : 'individual';
    const listId = currentMode === 'group' ? 'groupList' : 'officeList';
    const listContainer = document.getElementById(listId);
    if (!listContainer) return;

    let targetItem = null;
    listContainer.querySelectorAll('.chat-office-item').forEach(item => {
        if (item.dataset.name === officeName.toLowerCase()) targetItem = item;
    });

    if (targetItem) {
        const preview = targetItem.querySelector('.office-last');
        const text = (currentMode === 'group' ? 'You: ' : '') + previewText;
        if (preview) preview.textContent = text.length > 38 ? text.substring(0, 38) + '…' : text;
        const timeSpan = targetItem.querySelector('.chat-item-time');
        if (timeSpan) timeSpan.textContent = 'Now';
        if (listContainer.firstElementChild !== targetItem) {
            listContainer.insertBefore(targetItem, listContainer.firstElementChild);
        }
    }
}

function markActiveItem(officeName) {
    const key = officeName.toLowerCase();
    document.querySelectorAll('.chat-office-item').forEach(item => {
        item.classList.remove('active');
        if (item.dataset.name === key && item.dataset.kind === currentChatType) item.classList.add('active');
    });
}

// ============================================
// SELECT INDIVIDUAL OFFICE
// ============================================
function selectOffice(officeName) {
    const meta = unreadMeta[metaKey('individual', officeName)] || { count: 0, first: 0 };
    pendingDivider = (meta.count > 0 && meta.first > 0) ? { id: meta.first, count: meta.count } : null;

    selectedOffice = officeName;
    currentChatType = 'individual';
    groupCourse = '';
    lastRenderedMsgId = 0;
    stagedFile = null;
    hideStagedFile();
    setChatOpenUI(true);
    renderPinned(null);

    document.getElementById('chatWith').textContent = officeName;
    document.getElementById('chatStatus').textContent = 'Individual chat';
    document.getElementById('headerOfficePill').style.display = 'inline-block';

    const headerAvatar = document.getElementById('headerAvatar');
    headerAvatar.textContent = getInitials(officeName);
    headerAvatar.style.background = getAvatarColor(officeName);
    headerAvatar.style.display = 'flex';

    markActiveItem(officeName);

    document.getElementById('officeSearchInput').value = '';
    document.getElementById('chatMessages').innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">Loading messages...</div>';
    loadChatHistory(officeName, true);
    markOpenChatSeen(true);
    refreshOpenMeta();
}

// ============================================
// SELECT GROUP OFFICE
// ============================================
function selectGroupOffice(officeName) {
    const meta = unreadMeta[metaKey('group', officeName)] || { count: 0, first: 0 };
    pendingDivider = (meta.count > 0 && meta.first > 0) ? { id: meta.first, count: meta.count } : null;

    selectedOffice = officeName;
    currentChatType = 'group';
    groupCourse = myCourse;
    lastRenderedMsgId = 0;
    stagedFile = null;
    hideStagedFile();
    setChatOpenUI(true);

    document.getElementById('chatWith').textContent = officeName + ' Group';
    document.getElementById('chatStatus').textContent = myCourse + ' • Course Group Chat';
    document.getElementById('headerOfficePill').style.display = 'inline-block';

    const headerAvatar = document.getElementById('headerAvatar');
    headerAvatar.textContent = getInitials(officeName);
    headerAvatar.style.background = getAvatarColor(officeName);
    headerAvatar.style.display = 'flex';

    markActiveItem(officeName);

    document.getElementById('officeSearchInput').value = '';
    document.getElementById('chatMessages').innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">Loading messages...</div>';
    loadGroupChatMessages(officeName, true);
    markOpenChatSeen(true);
    refreshOpenMeta();
}

// ============================================
// RENDER MESSAGE (Seen indicator + file View/Download + unread divider + office look)
// ============================================
function renderMessage(messagesDiv, msg, isGroup) {
    // avoid duplicates (auto-refresh + send overlapping)
    if (messagesDiv.querySelector('[data-msg-id="' + msg.id + '"]')) return msg.id;

    const isSent = (typeof msg.is_mine !== 'undefined')
        ? msg.is_mine === true
        : (msg.sender_type === 'student');
    const isOffice = !isSent && msg.sender_type === 'office';

    // "N new messages" line, placed before the first message you had not read
    if (pendingDivider && !isSent && msg.id >= pendingDivider.id) {
        const dv = document.createElement('div');
        dv.className = 'new-divider';
        dv.innerHTML = '<span>' + pendingDivider.count + ' new message' + (pendingDivider.count === 1 ? '' : 's') + '</span>';
        messagesDiv.appendChild(dv);
        pendingDivider = null;
    }

    const div = document.createElement('div');
    div.className = 'message ' + (isSent ? 'sent' : 'received') +
                    (isOffice ? ' office-msg' : '') +
                    (msg.mentions_me ? ' mention-me' : '');
    div.dataset.msgId = msg.id;

    let content = '';
    if (!isSent && isGroup) {
        if (isOffice) {
            content += `<span class="msg-sender">${escapeHtml(msg.sender_name || 'Office')} <span class="office-pill">OFFICE</span></span>`;
        } else if (msg.sender_name) {
            content += `<span class="msg-sender">${escapeHtml(msg.sender_name)}</span>`;
        }
    }

    const isFilePlaceholder = (msg.message === '📎 File attached');
    if (!isFilePlaceholder) {
        content += highlightMentions(escapeHtml(msg.message));
    }

    if (msg.file_path) {
        const icon = getFileIcon(msg.file_type);
        const safePath = String(msg.file_path).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        const safeName = escapeHtml(msg.file_name || 'file');
        const safeType = String(msg.file_type || '').replace(/'/g, "\\'");
        content += `
            <div class="file-box">
                <i class="fas ${icon} file-icon"></i>
                <div class="file-info">
                    <div class="file-name">${safeName}</div>
                    <div class="file-size">Attachment</div>
                </div>
                <div class="file-actions">
                    <button type="button" class="btn-view-file" onclick="openFileViewer('${safePath}', '${safeName}', '${safeType}')">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <button type="button" class="btn-dl-file" onclick="downloadFileDirect('${safePath}')">
                        <i class="fas fa-download"></i> Download
                    </button>
                </div>
            </div>`;
    }

    let statusHtml = '';
    if (isSent && !isGroup) {
        if (parseInt(msg.is_read) === 1) {
            statusHtml = ' <span class="msg-status seen">✓✓ Seen</span>';
        } else {
            statusHtml = ' <span class="msg-status delivered">✓ Sent</span>';
        }
    }
    content += `<span class="msg-time">${msg.time}${statusHtml}</span>`;

    let avatarHtml = '';
    if (!isSent) {
        if (isOffice) {
            avatarHtml = `<div class="msg-avatar" style="background:#1b4d3e;" title="Office"><i class="fas fa-building" style="font-size:13px;"></i></div>`;
        } else {
            const name = msg.sender_name || 'Student';
            avatarHtml = `<div class="msg-avatar" style="background:${getAvatarColor(name)};">${getInitials(name)}</div>`;
        }
    }

    div.innerHTML = avatarHtml + `<div class="bubble">${content}</div>`;
    messagesDiv.appendChild(div);
    return msg.id;
}

// ============================================
// LOAD INDIVIDUAL HISTORY
// ============================================
function loadChatHistory(officeName, clearFirst) {
    const messagesDiv = document.getElementById('chatMessages');
    if (clearFirst) {
        messagesDiv.innerHTML = '';
        lastRenderedMsgId = 0;
    }

    const formData = new FormData();
    formData.append('action', 'get_chat_messages');
    formData.append('office_name', officeName);
    formData.append('since_id', lastRenderedMsgId);

    fetch(CHAT_URL, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (selectedOffice !== officeName || currentChatType !== 'individual') return;
            if (!Array.isArray(data)) return;

            if (clearFirst && data.length === 0) {
                messagesDiv.innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">No messages yet. Say hello!</div>';
                return;
            }
            if (data.length === 0) return;

            const notice = messagesDiv.querySelector('div[style*="No messages yet"]');
            if (notice) notice.remove();

            const nearBottom = (messagesDiv.scrollHeight - messagesDiv.scrollTop - messagesDiv.clientHeight) < 80;

            data.forEach(msg => {
                const id = renderMessage(messagesDiv, msg, false);
                if (id > lastRenderedMsgId) lastRenderedMsgId = id;
            });

            if (nearBottom || clearFirst) messagesDiv.scrollTop = messagesDiv.scrollHeight;

            // a new message from the office arrived while this chat is open -> it is seen
            if (data.some(m => m.sender_type === 'office')) markOpenChatSeen(false);
        })
        .catch(() => {});
}

// ============================================
// LOAD GROUP HISTORY
// ============================================
function loadGroupChatMessages(officeName, clearFirst) {
    const messagesDiv = document.getElementById('chatMessages');
    if (clearFirst) {
        messagesDiv.innerHTML = '';
        lastRenderedMsgId = 0;
    }

    const formData = new FormData();
    formData.append('action', 'grp_get');
    formData.append('office_name', officeName);
    formData.append('since_id', lastRenderedMsgId);

    chatPost(formData)
        .then(data => {
            if (selectedOffice !== officeName || currentChatType !== 'group') return;
            if (!Array.isArray(data)) return;

            if (clearFirst && data.length === 0) {
                messagesDiv.innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">No messages yet in this group.</div>';
                return;
            }
            if (data.length === 0) return;

            const notice = messagesDiv.querySelector('div[style*="No messages yet"]');
            if (notice) notice.remove();

            const nearBottom = (messagesDiv.scrollHeight - messagesDiv.scrollTop - messagesDiv.clientHeight) < 80;

            data.forEach(msg => {
                const id = renderMessage(messagesDiv, msg, true);
                if (id > lastRenderedMsgId) lastRenderedMsgId = id;
            });

            if (nearBottom || clearFirst) messagesDiv.scrollTop = messagesDiv.scrollHeight;

            // new message from someone else while the group is open -> it is seen
            if (data.some(m => !m.is_mine)) markOpenChatSeen(false);
        })
        .catch(() => {});
}

// ============================================
// SEEN: mark the open chat as read (clears the badge AND the notification alert)
// ============================================
function markOpenChatSeen(immediate) {
    if (!selectedOffice || document.hidden) return;

    // clear the badge right away for the chat being read
    const key = selectedOffice.toLowerCase();
    document.querySelectorAll('.chat-office-item').forEach(el => {
        if (el.dataset.name === key && el.dataset.kind === currentChatType) {
            el.dataset.unread = 0;
            el.dataset.mentionUnread = 0;
            el.classList.remove('has-unread');
            const b = el.querySelector('.unread-badge, .group-unread-badge');
            if (b) { b.style.display = 'none'; b.textContent = '0'; }
        }
    });
    refreshMentionAlert();

    if (seenTimer) { if (!immediate) return; clearTimeout(seenTimer); }
    const office = selectedOffice, type = currentChatType;
    seenTimer = setTimeout(function () {
        seenTimer = null;
        const fd = new FormData();
        fd.append('action', 'chat_seen');
        fd.append('office_name', office);
        fd.append('chat_type', type);
        chatPost(fd).then(refreshOverview).catch(() => {});
    }, immediate ? 0 : 400);
}
document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { markOpenChatSeen(true); refreshOverview(); }
});

// ============================================
// OPEN CHAT META: message count, members, "Seen by ...", pinned mention
// ============================================
function refreshOpenMeta() {
    if (!selectedOffice) return;
    const office = selectedOffice, type = currentChatType;
    const fd = new FormData();
    fd.append('action', 'chat_open_meta');
    fd.append('office_name', office);
    fd.append('chat_type', type);
    chatPost(fd).then(res => {
        if (!res || res.success === false) return;
        if (selectedOffice !== office || currentChatType !== type) return;

        if (type === 'group') {
            const m = parseInt(res.members) || 0, t = parseInt(res.total) || 0;
            document.getElementById('chatStatus').textContent =
                myCourse + ' • ' + m + ' member' + (m === 1 ? '' : 's') + ' • ' + t + ' message' + (t === 1 ? '' : 's');
            renderGroupSeen(res);
            renderPinned(res.pinned);
        } else {
            const upto = parseInt(res.my_read_upto) || 0;
            document.querySelectorAll('#chatMessages .message.sent').forEach(el => {
                const id = parseInt(el.dataset.msgId) || 0;
                if (id > 0 && id <= upto) {
                    const st = el.querySelector('.msg-status');
                    if (st && !st.classList.contains('seen')) {
                        st.className = 'msg-status seen';
                        st.textContent = '✓✓ Seen';
                    }
                }
            });
        }
    }).catch(() => {});
}

function renderGroupSeen(res) {
    const old = document.getElementById('grpSeenLine');
    if (old) old.remove();
    const myLast = parseInt(res.my_last_id) || 0;
    if (myLast <= 0) return;

    const who = [];
    if (res.office_seen) who.push(res.office || 'Office');
    const n = parseInt(res.seen_students) || 0;
    if (n > 0) who.push(n + ' student' + (n === 1 ? '' : 's'));
    if (who.length === 0) return;

    const node = document.querySelector('#chatMessages [data-msg-id="' + myLast + '"]');
    if (!node) return;
    const line = document.createElement('div');
    line.id = 'grpSeenLine';
    line.className = 'seen-line';
    line.textContent = '✓✓ Seen by ' + who.join(' and ');
    node.after(line);
}

// ============================================
// STAGE FILE
// ============================================
function stageFile(fileInput) {
    if (!selectedOffice) {
        alert('Please select an office first.');
        fileInput.value = '';
        return;
    }
    const file = fileInput.files[0];
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
        alert('File is too large. Max size is 10 MB.');
        fileInput.value = '';
        return;
    }

    stagedFile = file;

    const ext = file.name.split('.').pop().toLowerCase();
    document.getElementById('stagedIcon').className = `fas ${getFileIcon(ext)} staged-icon`;
    document.getElementById('stagedName').textContent = file.name;
    document.getElementById('stagedSize').textContent = formatFileSize(file.size);
    document.getElementById('stagedFileBox').classList.add('show');
}

function removeStagedFile() {
    stagedFile = null;
    hideStagedFile();
    document.getElementById('chatFileInput').value = '';
}

function hideStagedFile() {
    document.getElementById('stagedFileBox').classList.remove('show');
    document.getElementById('chatFileInput').value = '';
    stagedFile = null;
}

// ============================================
// SEND MESSAGE
// ============================================
function sendChatMessage() {
    if (!selectedOffice) { alert('Please select an office first.'); return; }

    const input = document.getElementById('chatInput');
    const message = input.value.trim();

    if (!message && !stagedFile) return;

    input.value = '';

    const isGroup = (currentChatType === 'group');
    const office = selectedOffice;

    const messagesDiv = document.getElementById('chatMessages');
    const notice = messagesDiv.querySelector('div[style*="No messages yet"]');
    if (notice) notice.remove();

    const sentDiv = document.createElement('div');
    sentDiv.className = 'message sent';

    let tempBubble = '';
    if (message) tempBubble += escapeHtml(message);

    if (stagedFile) {
        const ext = stagedFile.name.split('.').pop().toLowerCase();
        tempBubble += `
            <div class="file-box">
                <i class="fas ${getFileIcon(ext)} file-icon"></i>
                <div class="file-info">
                    <div class="file-name">${escapeHtml(stagedFile.name)}</div>
                    <div class="file-size">Uploading...</div>
                </div>
            </div>`;
    }
    tempBubble += `<span class="msg-time">Sending...</span>`;
    sentDiv.innerHTML = `<div class="bubble">${tempBubble}</div>`;
    messagesDiv.appendChild(sentDiv);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;

    const formData = new FormData();
    formData.append('action', isGroup ? 'grp_send' : 'send_chat_message');
    formData.append('office_name', office);
    formData.append('message', message || '📎 File attached');
    formData.append('chat_type', currentChatType);
    if (isGroup) formData.append('course', groupCourse);
    if (stagedFile) formData.append('chat_file', stagedFile);

    const previewText = message || (stagedFile ? '📎 ' + stagedFile.name : '');

    stagedFile = null;
    hideStagedFile();

    const req = isGroup
        ? chatPost(formData)
        : fetch(CHAT_URL, { method: 'POST', body: formData }).then(r => r.json());

    req.then(res => {
            sentDiv.remove();
            if (res && res.success === false) {
                alert('Failed to send: ' + (res.message || 'unknown error'));
                return;
            }
            if (isGroup) {
                loadGroupChatMessages(office, false);
            } else {
                loadChatHistory(office, false);
            }
            moveChatToTop(office, previewText);
            setTimeout(function () { refreshOverview(); refreshOpenMeta(); }, 600);
        })
        .catch(() => {
            const t = sentDiv.querySelector('.msg-time');
            if (t) t.textContent = 'Failed to send';
        });
}

function sendQuickReply(text) {
    document.getElementById('chatInput').value = text;
    sendChatMessage();
}

// ============================================
// CLEAR CHAT
// ============================================
function clearChatHistory() {
    if (!selectedOffice) return;
    if (!confirm('Clear all messages with ' + selectedOffice + '?')) return;

    const office = selectedOffice;
    const formData = new FormData();
    formData.append('office_name', office);

    if (currentChatType === 'group') {
        formData.append('action', 'grp_clear');
        chatPost(formData).then(() => { loadGroupChatMessages(office, true); refreshOverview(); }).catch(() => {});
    } else {
        formData.append('action', 'clear_chat');
        formData.append('chat_type', 'individual');
        fetch(CHAT_URL, { method: 'POST', body: formData })
            .then(() => { loadChatHistory(office, true); refreshOverview(); })
            .catch(() => {});
    }
}

function closeChat() {
    selectedOffice = null;
    pendingDivider = null;
    document.getElementById('chatWith').textContent = 'Select an office';
    document.getElementById('chatStatus').textContent = 'Click an office to start chatting';
    document.getElementById('headerAvatar').style.display = 'none';
    document.getElementById('headerOfficePill').style.display = 'none';
    document.getElementById('chatMessages').innerHTML = `
        <div class="no-chat-selected">
            <i class="fas fa-comment-dots"></i>
            <h3>No conversation selected</h3>
            <p style="color: #999; font-size: 13px;">Select an office from the left to start chatting</p>
        </div>
    `;
    document.querySelectorAll('.chat-office-item').forEach(i => i.classList.remove('active'));
    hideStagedFile();
    setChatOpenUI(false);
    renderPinned(null);
    refreshOverview();
}

// ============================================
// FILE VIEWER MODAL
// ============================================
function openFileViewer(path, name, type) {
    const overlay = document.getElementById('fileViewerOverlay');
    const body = document.getElementById('fvBody');
    const iconEl = document.getElementById('fvIcon');
    const nameEl = document.getElementById('fvName');
    const dlBtn = document.getElementById('fvDownloadBtn');

    const t = (type || '').toLowerCase();
    const iconClass = getFileIcon(t);

    iconEl.className = `fas ${iconClass} file-viewer-fv-icon`;
    nameEl.textContent = name || 'File';
    dlBtn.href = path;
    dlBtn.setAttribute('download', name || '');

    if (['jpg', 'jpeg', 'png', 'gif'].includes(t)) {
        body.innerHTML = `<img src="${path}" alt="${escapeHtml(name)}">`;
    } else if (t === 'pdf') {
        body.innerHTML = `<iframe src="${path}#toolbar=1&navpanes=0&scrollbar=1" title="${escapeHtml(name)}"></iframe>`;
    } else if (t === 'txt') {
        body.innerHTML = `<iframe src="${path}" title="${escapeHtml(name)}"></iframe>`;
    } else {
        body.innerHTML = `
            <div class="fv-fallback">
                <i class="fas ${iconClass} fv-fallback-icon"></i>
                <h3>Preview not available</h3>
                <p>This file type (<strong>.${escapeHtml(t)}</strong>) cannot be previewed inline.<br>
                   Click the Download button above to save it.</p>
                <a href="${path}" download="${escapeHtml(name)}">
                    <i class="fas fa-download"></i> Download ${escapeHtml(name)}
                </a>
            </div>`;
    }

    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeFileViewer(event) {
    const overlay = document.getElementById('fileViewerOverlay');
    overlay.classList.remove('active');
    document.getElementById('fvBody').innerHTML = '';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const overlay = document.getElementById('fileViewerOverlay');
        if (overlay && overlay.classList.contains('active')) closeFileViewer(e);
    }
});

// ============================================
// HELPERS
// ============================================
function getFileIcon(type) {
    const icons = {
        pdf: 'fa-file-pdf', doc: 'fa-file-word', docx: 'fa-file-word',
        xls: 'fa-file-excel', xlsx: 'fa-file-excel',
        jpg: 'fa-file-image', jpeg: 'fa-file-image', png: 'fa-file-image', gif: 'fa-file-image',
        txt: 'fa-file-alt'
    };
    return icons[(type || '').toLowerCase()] || 'fa-file';
}
function downloadFileDirect(path) {
    const a = document.createElement('a');
    a.href = path;
    a.download = '';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}
function formatFileSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
}
function escapeHtml(t) { if (!t) return ''; const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

// ============================================
// UNREAD BADGES (sidebar, list header, tab title) - one accurate number
// ============================================
function applyUnreadTotal(total) {
    total = parseInt(total) || 0;
    document.querySelectorAll('.chat-badge, #sidebarChatBadge').forEach(b => {
        if (total > 0) { b.textContent = fmtCount(total); b.style.display = 'inline'; }
        else { b.style.display = 'none'; }
    });
    const hb = document.getElementById('sidebarCountBadge');
    if (hb) {
        if (total > 0) { hb.textContent = fmtCount(total); hb.style.display = ''; }
        else { hb.style.display = 'none'; }
    }
    document.title = (total > 0 ? '(' + fmtCount(total) + ') ' : '') + BASE_TITLE;
}
function updateUnreadBadge() { return refreshOverview(); }

// build the "first unread" info from the page's first paint
document.querySelectorAll('.chat-office-item').forEach(el => {
    unreadMeta[metaKey(el.dataset.kind, el.dataset.name)] = {
        count: parseInt(el.dataset.unread) || 0,
        first: parseInt(el.dataset.firstUnread) || 0
    };
});
refreshMentionAlert();

// ============================================
// DARK MODE / LOGOUT
// ============================================
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const isDarkMode = document.body.classList.contains('dark-mode');
    localStorage.setItem('darkMode', isDarkMode);
    const btn = document.querySelector('.dark-mode-toggle');
    if (btn) btn.innerHTML = isDarkMode ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
}
if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
    const btn = document.querySelector('.dark-mode-toggle');
    if (btn) btn.innerHTML = '<i class="fas fa-sun"></i>';
}
function confirmLogout() {
    if(confirm('Are you sure you want to logout?')) window.location.href = '?logout=1';
}

// ============================================
// TAPPED A "YOU WERE MENTIONED" ALERT -> open that group chat (this also clears the alert)
// ============================================
document.addEventListener('DOMContentLoaded', function () {
    if (autoOpenOffice) {
        toggleSidebarMode('group');
        selectGroupOffice(autoOpenOffice);
    }
});

// ============================================
// AUTO-REFRESH (messages + list order + counts)
// ============================================
setInterval(function() {
    if (selectedOffice && !document.hidden) {
        if (currentChatType === 'group') {
            loadGroupChatMessages(selectedOffice, false);
        } else {
            loadChatHistory(selectedOffice, false);
        }
        refreshOpenMeta();
    }
    refreshOverview();
}, 3000);

console.log('Student Course Chat loaded!');
</script>

</body>
</html>