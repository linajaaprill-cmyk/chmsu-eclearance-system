<?php
// modules/office/chat.php
// Office Chat - Individual + Course Group Chat (Messenger-style unread, ordering, live lists)

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['office'])) { echo "Not authorized"; exit; }

$office = $_SESSION['office'];
$office_esc_session = $conn->real_escape_string($office);
$storage_used = function_exists('getTotalUploadSize') ? getTotalUploadSize() : 0;
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

$chat_url_param = ($office === 'Registrar') ? '?section=chat' : '?officesection=chat';

// ============================================
// CREATE CHAT TABLES
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS chmsu_chat_conversations (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    office_name VARCHAR(100) NOT NULL,
    last_message TEXT,
    last_message_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    student_unread INT(11) DEFAULT 0,
    office_unread INT(11) DEFAULT 0,
    UNIQUE KEY unique_conversation (student_id, office_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS chmsu_group_chat_messages (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('student', 'office') NOT NULL,
    sender_id VARCHAR(50) NOT NULL,
    receiver_id VARCHAR(50) DEFAULT NULL,
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
    INDEX idx_group (course, year_level, section, office_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Old read-pointer table (kept so other files that use it keep working)
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

// NEW: dedicated "seen" pointer for office course groups (one row per office + course)
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_office_group_reads (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    office_name VARCHAR(100) NOT NULL,
    course VARCHAR(50) NOT NULL,
    last_read_id INT(11) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_office_course (office_name, course)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ============================================
// HELPERS
// ============================================
function of_out($data) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo '@@CHATJSON@@' . json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function of_clean($t) {
    $t = trim(strip_tags((string)$t));
    if (function_exists('mb_substr')) $t = mb_substr($t, 0, 2000);
    return $t;
}
function of_upload() {
    $r = ['path' => '', 'name' => '', 'type' => ''];
    if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] == 0) {
        $allowed = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','txt'];
        $filename = $_FILES['chat_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed) && $_FILES['chat_file']['size'] <= 10 * 1024 * 1024) {
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename));
            $newname = UPLOAD_DIR . 'chat_' . time() . '_' . $safe;
            if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $newname)) {
                $r = ['path' => $newname, 'name' => $filename, 'type' => $ext];
            }
        }
    }
    return $r;
}
function of_short($t, $n = 60) {
    $t = (string)$t;
    return function_exists('mb_substr') ? mb_substr($t, 0, $n) : substr($t, 0, $n);
}
function of_time_label($ts) {
    if (!$ts) return '';
    return date('Y-m-d', $ts) === date('Y-m-d') ? date('h:i A', $ts) : date('M d, h:i A', $ts);
}
function of_courses($conn) {
    $courses = [];
    $q = $conn->query("SELECT UPPER(TRIM(chmsu_course)) as c, COUNT(*) as n FROM chmsu_students_master WHERE chmsu_course IS NOT NULL AND chmsu_course != '' GROUP BY UPPER(TRIM(chmsu_course)) ORDER BY c ASC");
    if ($q) while ($r = $q->fetch_assoc()) $courses[] = $r;
    return $courses;
}
// Mark a course group as seen up to its newest message (never moves backwards)
function of_mark_group_seen($conn, $office_e, $ce) {
    $latest = $conn->query("SELECT MAX(id) AS max_id FROM chmsu_group_chat_messages
                            WHERE LOWER(office_name)=LOWER('$office_e') AND UPPER(TRIM(course))='$ce'")->fetch_assoc()['max_id'];
    $latest = $latest ? intval($latest) : 0;
    $conn->query("INSERT INTO chmsu_office_group_reads (office_name, course, last_read_id)
                  VALUES ('$office_e', '$ce', $latest)
                  ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, $latest), updated_at = NOW()");
    return $latest;
}
// Individual conversations (newest message first) with accurate unread
function of_individual_list($conn, $office_e) {
    $list = [];
    $q = $conn->query("SELECT s.chmsu_student_id AS id, s.chmsu_full_name AS name,
                              c.office_unread AS unread, c.last_message, c.last_message_time AS last_time
                       FROM chmsu_students_master s
                       JOIN chmsu_chat_conversations c
                         ON s.chmsu_student_id = c.student_id AND LOWER(c.office_name) = LOWER('$office_e')
                       WHERE c.last_message_time IS NOT NULL
                       ORDER BY c.last_message_time DESC");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $ts = $r['last_time'] ? strtotime($r['last_time']) : 0;
            $list[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'unread' => max(0, intval($r['unread'])),
                'last_message' => of_short($r['last_message'], 60),
                'ts' => $ts,
                'label' => of_time_label($ts)
            ];
        }
    }
    return $list;
}
// Course group stats: total messages, unread (student messages after the "seen" pointer), last message
function of_group_stats($conn, $office_e, $courses) {
    $out = [];
    foreach ($courses as $c) {
        $course = $c['c'];
        $ce = $conn->real_escape_string($course);

        $row = $conn->query("SELECT COUNT(*) AS total, MAX(id) AS last_id
                             FROM chmsu_group_chat_messages
                             WHERE LOWER(office_name)=LOWER('$office_e') AND UPPER(TRIM(course))='$ce'")->fetch_assoc();
        $total = intval($row['total'] ?? 0);
        $last_id = intval($row['last_id'] ?? 0);

        $lastRead = 0;
        $rr = $conn->query("SELECT last_read_id FROM chmsu_office_group_reads
                            WHERE LOWER(office_name)=LOWER('$office_e') AND course='$ce' LIMIT 1");
        if ($rr && ($rrow = $rr->fetch_assoc())) $lastRead = intval($rrow['last_read_id']);

        $unread = intval($conn->query("SELECT COUNT(*) AS c FROM chmsu_group_chat_messages
                                       WHERE LOWER(office_name)=LOWER('$office_e') AND UPPER(TRIM(course))='$ce'
                                         AND sender_type='student' AND id > $lastRead")->fetch_assoc()['c'] ?? 0);

        $last_message = ''; $last_sender = ''; $ts = 0;
        if ($last_id > 0) {
            $m = $conn->query("SELECT m.message, m.sender_type, m.created_at,
                                      CASE WHEN m.sender_type='student'
                                           THEN COALESCE(NULLIF(s.chmsu_full_name,''), NULLIF(u.chmsu_name,''), m.sender_id)
                                           ELSE m.office_name END AS sender_name
                               FROM chmsu_group_chat_messages m
                               LEFT JOIN chmsu_students_master s ON m.sender_id = s.chmsu_student_id
                               LEFT JOIN chmsu_user_accounts u ON m.sender_id = u.chmsu_student_id
                               WHERE m.id = $last_id")->fetch_assoc();
            if ($m) {
                $last_message = of_short($m['message'], 60);
                $ts = strtotime($m['created_at']);
                if ($m['sender_type'] === 'office') {
                    $last_sender = 'You';
                } else {
                    $parts = explode(',', (string)$m['sender_name']);
                    $last_sender = count($parts) > 1 ? trim($parts[1]) : trim($parts[0]);
                }
            }
        }

        $out[] = [
            'course' => $course,
            'students' => intval($c['n']),
            'total' => $total,
            'unread' => max(0, $unread),
            'last_message' => $last_message,
            'last_sender' => $last_sender,
            'ts' => $ts,
            'label' => of_time_label($ts)
        ];
    }
    // Newest activity on top; groups with no messages go last (alphabetical)
    usort($out, function ($a, $b) {
        if ($a['ts'] !== $b['ts']) return $b['ts'] - $a['ts'];
        return strcmp($a['course'], $b['course']);
    });
    return $out;
}

// ============================================
// GROUP CHAT AJAX (course groups) + LIST REFRESH
// ============================================
if (isset($_POST['action']) && in_array($_POST['action'], ['grp_send','grp_get','grp_read','chat_lists'], true)) {
    try {
        $act = $_POST['action'];
        $office_e = $conn->real_escape_string($office);

        // ---------- LISTS (individual + groups, with unread) ----------
        if ($act === 'chat_lists') {
            $courses_now = of_courses($conn);
            of_out([
                'individual' => of_individual_list($conn, $office_e),
                'groups' => of_group_stats($conn, $office_e, $courses_now)
            ]);
        }

        // ---------- SEND TO COURSE GROUP ----------
        if ($act === 'grp_send') {
            $course = strtoupper(trim($_POST['course'] ?? ''));
            if ($course === '') of_out(['success' => false, 'message' => 'Course is required']);

            $ce = $conn->real_escape_string($course);
            $chk = $conn->query("SELECT 1 FROM chmsu_students_master WHERE UPPER(TRIM(chmsu_course))='$ce' LIMIT 1");
            if (!$chk || $chk->num_rows == 0) of_out(['success' => false, 'message' => 'Course not found']);

            $message = of_clean($_POST['message'] ?? '');
            $up = of_upload();
            if ($message === '' && $up['path'] === '') of_out(['success' => false, 'message' => 'Empty message']);
            if ($message === '') $message = '📎 File attached';

            $me = $conn->real_escape_string($message);
            $fpe = $conn->real_escape_string($up['path']);
            $fne = $conn->real_escape_string($up['name']);
            $fte = $conn->real_escape_string($up['type']);

            $ok = $conn->query("INSERT INTO chmsu_group_chat_messages
                (sender_type, sender_id, course, year_level, section, office_name, message, file_path, file_name, file_type)
                VALUES ('office', '$office_e', '$ce', '', '', '$office_e', '$me', '$fpe', '$fne', '$fte')");
            if (!$ok) of_out(['success' => false, 'message' => 'DB error: ' . $conn->error]);

            of_out(['success' => true]);
        }

        // ---------- GET GROUP MESSAGES ----------
        if ($act === 'grp_get') {
            $course = strtoupper(trim($_POST['course'] ?? ''));
            $since  = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;
            $isOpen = !empty($_POST['open']);
            if ($course === '') of_out([]);

            $ce = $conn->real_escape_string($course);

            $q = $conn->query("SELECT m.*,
                    CASE WHEN m.sender_type='student'
                         THEN COALESCE(NULLIF(s.chmsu_full_name,''), NULLIF(u.chmsu_name,''), m.sender_id)
                         ELSE m.office_name END AS sender_name
                FROM chmsu_group_chat_messages m
                LEFT JOIN chmsu_students_master s ON m.sender_id = s.chmsu_student_id
                LEFT JOIN chmsu_user_accounts u ON m.sender_id = u.chmsu_student_id
                WHERE LOWER(m.office_name)=LOWER('$office_e') AND UPPER(TRIM(m.course))='$ce' AND m.id > $since
                ORDER BY m.id ASC");

            $result = [];
            if ($q) {
                while ($msg = $q->fetch_assoc()) {
                    $label = '';
                    if ($msg['sender_type'] === 'student' && (trim($msg['year_level']) !== '' || trim($msg['section']) !== '')) {
                        $label = trim($msg['year_level']) . strtoupper(trim($msg['section']));
                    }
                    $result[] = [
                        'id' => intval($msg['id']),
                        'sender_type' => $msg['sender_type'],
                        'sender_name' => $msg['sender_name'],
                        'target_label' => $label,
                        'message' => $msg['message'],
                        'file_path' => $msg['file_path'],
                        'file_name' => $msg['file_name'],
                        'file_type' => $msg['file_type'],
                        'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
                    ];
                }
            }

            // The group is open on screen: everything fetched is SEEN, so clear its unread now
            if ($isOpen) of_mark_group_seen($conn, $office_e, $ce);

            of_out($result);
        }

        // ---------- MARK COURSE GROUP AS READ ----------
        if ($act === 'grp_read') {
            $course = strtoupper(trim($_POST['course'] ?? ''));
            if ($course === '') of_out(['success' => false]);
            $ce = $conn->real_escape_string($course);
            of_mark_group_seen($conn, $office_e, $ce);
            of_out(['success' => true]);
        }
    } catch (Throwable $e) {
        of_out(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================
// INDIVIDUAL CHAT AJAX
// ============================================
if (isset($_POST['action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');

    try {
        // --- SEND MESSAGE (individual) ---
        if ($_POST['action'] == 'send_chat_message' && isset($_SESSION['office'])) {
            $message = isset($_POST['message']) ? sanitize($_POST['message']) : '';
            $student_id = $_POST['student_id'] ?? '';

            $file_path = ''; $file_name = ''; $file_type = '';
            if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] == 0) {
                $allowed = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','txt'];
                $filename = $_FILES['chat_file']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed) && $_FILES['chat_file']['size'] <= 10 * 1024 * 1024) {
                    $newname = UPLOAD_DIR . 'chat_' . time() . '_' . basename($filename);
                    if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $newname)) {
                        $file_path = $newname; $file_name = $filename; $file_type = $ext;
                    }
                }
            }
            if ($message === '' && $file_path === '') { echo json_encode(['success'=>false,'message'=>'Empty']); exit; }
            if ($message === '' && $file_path !== '') { $message = '📎 File attached'; }
            if ($student_id === '') { echo json_encode(['success'=>false,'message'=>'Missing student']); exit; }

            $fpe = $conn->real_escape_string($file_path);
            $fne = $conn->real_escape_string($file_name);
            $fte = $conn->real_escape_string($file_type);
            $me  = $conn->real_escape_string($message);
            $office_esc = $conn->real_escape_string($office);
            $se = $conn->real_escape_string($student_id);

            $conn->query("INSERT INTO chmsu_chat_messages (sender_type, sender_id, receiver_id, message, file_path, file_name, file_type) VALUES ('office', '$office_esc', '$se', '$me', '$fpe', '$fne', '$fte')");
            $conn->query("INSERT INTO chmsu_chat_conversations (student_id, office_name, last_message, last_message_time, student_unread) VALUES ('$se', '$office_esc', '$me', NOW(), 1) ON DUPLICATE KEY UPDATE last_message='$me', last_message_time=NOW(), student_unread=student_unread+1");
            echo json_encode(['success' => true]); exit;
        }

        // --- GET INDIVIDUAL MESSAGES ---
        if ($_POST['action'] == 'get_chat_messages') {
            $student_id = sanitize($_POST['student_id'] ?? '');
            $since_id = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;
            $office_esc = $conn->real_escape_string($office);

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
                    'message' => $msg['message'],
                    'file_path' => $msg['file_path'],
                    'file_name' => $msg['file_name'],
                    'file_type' => $msg['file_type'],
                    'is_read' => intval($msg['is_read']),
                    'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
                ];
            }
            echo json_encode($result); exit;
        }

        // --- MARK READ ---
        if ($_POST['action'] == 'mark_chat_read') {
            $student_id = sanitize($_POST['student_id'] ?? '');
            $office_esc = $conn->real_escape_string($office);
            $conn->query("UPDATE chmsu_chat_messages SET is_read = 1 WHERE sender_type='student' AND sender_id='$student_id' AND receiver_id='$office_esc'");
            $conn->query("UPDATE chmsu_chat_conversations SET office_unread = 0 WHERE student_id='$student_id' AND office_name='$office_esc'");
            echo json_encode(['success' => true]); exit;
        }

        // --- CLEAR CHAT (individual) ---
        if ($_POST['action'] == 'clear_chat') {
            $student_id = sanitize($_POST['student_id'] ?? '');
            $office_esc = $conn->real_escape_string($office);
            $conn->query("DELETE FROM chmsu_chat_messages WHERE (sender_type='student' AND sender_id='$student_id' AND receiver_id='$office_esc') OR (sender_type='office' AND sender_id='$office_esc' AND receiver_id='$student_id')");
            $conn->query("DELETE FROM chmsu_chat_conversations WHERE student_id='$student_id' AND office_name='$office_esc'");
            echo json_encode(['success' => true]); exit;
        }

        // --- UNREAD COUNT (individual + course groups, same source as the lists) ---
        if ($_POST['action'] == 'get_unread_count') {
            $office_esc = $conn->real_escape_string($office);
            $total = intval($conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$office_esc'")->fetch_assoc()['total'] ?: 0);
            foreach (of_group_stats($conn, $office_esc, of_courses($conn)) as $g) $total += $g['unread'];
            echo json_encode(['total' => $total]); exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['success'=>false, 'message'=>'PHP Error: '.$e->getMessage()]); exit;
    }
}

// ============================================
// FETCH DATA FOR UI
// ============================================
$courses = of_courses($conn);
$initIndividual = of_individual_list($conn, $office_esc_session);
$initGroups = of_group_stats($conn, $office_esc_session, $courses);

$chat_unread = 0;
foreach ($initIndividual as $x) $chat_unread += $x['unread'];
foreach ($initGroups as $x) $chat_unread += $x['unread'];

$initJson = json_encode(
    ['individual' => $initIndividual, 'groups' => $initGroups],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
);
if ($initJson === false) $initJson = '{"individual":[],"groups":[]}';

// Suggestion data
$suggestionYears = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
$suggestionSections = ['Section A', 'Section B', 'Section C', 'Section D', 'Section E'];
$suggestionCourses = array_column($courses, 'c');
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

        .header { background: #1b4d3e; color: white; padding: 12px 20px; display: flex; align-items: center; gap: 15px; position: sticky; top: 0; z-index: 1000; }
        .header-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 18px; font-weight: 600; margin: 0; }
        .header-title p { font-size: 11px; opacity: 0.8; margin: 0; }
        .dark-mode-toggle, .logout-btn { background: transparent; border: 1px solid rgba(255,255,255,0.3); color: white; padding: 5px 12px; cursor: pointer; border-radius: 4px; font-size: 12px; }
        .logout-btn { background: #e74c3c; border: none; margin-left: auto; }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }

        .dashboard-wrapper { display: flex; min-height: calc(100vh - 64px); }
        .dark-sidebar { width: 260px; background: #1b4d3e; color: white; position: fixed; height: calc(100vh - 64px); overflow-y: auto; flex-shrink: 0; }
        .dark-sidebar .sidebar-header { padding: 18px 20px; border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-header h3 { font-size: 15px; font-weight: 600; margin: 0; }
        .dark-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin: 3px 0 0; }
        .dark-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .dark-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .dark-sidebar .sidebar-menu a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 13px; transition: 0.2s; }
        .dark-sidebar .sidebar-menu a:hover, .dark-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000; }
        .sidebar-badge { background: #e74c3c; color: white; border-radius: 50%; padding: 1px 6px; font-size: 9px; margin-left: 5px; animation: pulse 1.5s infinite; }

        .main-content { flex: 1; margin-left: 260px; padding: 15px; background: #f0f2f5; min-height: calc(100vh - 64px); }
        .dashboard-header-bar { background: white; padding: 10px 20px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: 600; color: #1b4d3e; margin: 0; }

        .chat-container { display: flex; height: calc(100vh - 170px); background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.08); min-height: 0; }

        .chat-sidebar { width: 340px; background: #fff; border-right: 1px solid #e4e6eb; display: flex; flex-direction: column; flex-shrink: 0; min-height: 0; }
        .chat-sidebar-header { padding: 14px 16px; border-bottom: 1px solid #e4e6eb; display: flex; justify-content: space-between; align-items: center; background: #fff; }
        .chat-sidebar-header h4 { font-size: 16px; font-weight: 600; color: #050505; margin: 0; }
        .chat-search { padding: 10px 16px; border-bottom: 1px solid #e4e6eb; position: relative; }
        .chat-search input { width: 100%; padding: 8px 14px; border: none; border-radius: 20px; font-size: 13px; outline: none; background: #f0f2f5; }
        .chat-search input:focus { background: #fff; box-shadow: 0 0 0 2px #1b4d3e30; }
        .list-scroll { flex: 1; overflow-y: auto; }
        .list-scroll::-webkit-scrollbar { width: 4px; }
        .list-scroll::-webkit-scrollbar-track { background: #f0f2f5; }
        .list-scroll::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
        
        .chat-office-item { display: flex; align-items: center; padding: 10px 16px; cursor: pointer; transition: 0.15s; border-left: 3px solid transparent; }
        .chat-office-item:hover { background: #f0f2f5; }
        .chat-office-item.active { background: #e7f3ff; border-left-color: #1b4d3e; }
        .chat-office-item .avatar-circle { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 600; color: white; flex-shrink: 0; margin-right: 12px; }
        .chat-office-item .office-info { flex: 1; min-width: 0; }
        .chat-office-item .office-name { font-size: 14px; font-weight: 600; color: #050505; }
        .chat-office-item .office-last { font-size: 12px; color: #65676b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .chat-office-item .chat-item-meta { display: flex; flex-direction: column; align-items: flex-end; flex-shrink: 0; margin-left: 8px; }
        .chat-office-item .chat-item-time { font-size: 11px; color: #65676b; }
        .chat-office-item .unread-badge { background: #e74c3c; color: white; border-radius: 50%; padding: 2px 8px; font-size: 10px; min-width: 20px; text-align: center; margin-top: 4px; }
        /* Messenger-style unread (bold) */
        .chat-office-item.has-unread .office-name { font-weight: 800; }
        .chat-office-item.has-unread .office-last { font-weight: 700; color: #050505; }
        .chat-office-item.has-unread .chat-item-time { font-weight: 700; color: #1b4d3e; }
        .list-section-label { padding: 8px 16px 4px; font-size: 10px; font-weight: 600; color: #65676b; text-transform: uppercase; }

        .chat-area { flex: 1; display: flex; flex-direction: column; background: #fff; min-height: 0; }
        .chat-area-header { padding: 12px 20px; border-bottom: 1px solid #e4e6eb; display: flex; align-items: center; gap: 12px; background: #fff; }
        .chat-area-header .back-btn { display: none; background: none; border: none; font-size: 20px; cursor: pointer; color: #65676b; }
        .chat-area-header .header-avatar { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; color: white; flex-shrink: 0; }
        .chat-area-header .header-info { flex: 1; min-width: 0; }
        .chat-area-header .chat-with { font-size: 15px; font-weight: 600; color: #050505; }
        .chat-area-header .chat-type { font-size: 11px; color: #65676b; }
        .chat-area-header .chat-actions { margin-left: auto; display: flex; gap: 8px; }
        .chat-area-header .chat-actions button { background: none; border: none; cursor: pointer; font-size: 16px; color: #65676b; padding: 4px 8px; border-radius: 4px; }
        .chat-area-header .chat-actions button:hover { background: #f0f2f5; }

        .chat-messages { flex: 1; overflow-y: auto; padding: 16px 20px; background: #fafafa; min-height: 0; }
        .day-divider { clear: both; display: block; width: 100%; text-align: center; margin: 12px 0 8px; font-size: 11px; font-weight: 600; color: #888; }
        .message { display: flex; margin-bottom: 8px; max-width: 75%; clear: both; }
        .message.sent { float: right; flex-direction: row-reverse; }
        .message.received { float: left; }
        .message .msg-avatar { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: white; flex-shrink: 0; margin: 0 8px; }
        .message.sent .msg-avatar { display: none; }
        .message .bubble { padding: 8px 14px; border-radius: 18px; font-size: 14px; word-wrap: break-word; position: relative; max-width: 100%; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .message.sent .bubble { background: #1b4d3e; color: white; border-bottom-right-radius: 4px; }
        .message.received .bubble { background: white; color: #050505; border-bottom-left-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.08); }
        .message .bubble .msg-sender { font-size: 11px; font-weight: 600; color: #1b4d3e; display: block; margin-bottom: 3px; }
        .message .bubble .msg-sender .tag { background: #e7f3ff; color: #1b4d3e; border-radius: 8px; padding: 0 6px; margin-left: 4px; font-size: 10px; }
        .message.sent .bubble .msg-sender { color: #f1c40f; }
        .message .bubble .msg-time { font-size: 10px; opacity: 0.6; display: block; margin-top: 3px; text-align: right; }
        .message.sent .bubble .msg-time { color: #ddd; }
        .message.received .bubble .msg-time { color: #888; }
        .message .bubble .msg-status { font-size: 10px; display: inline-block; margin-left: 6px; opacity: 0.8; }
        .message.sent .bubble .msg-status.seen { color: #a3e4d7; font-weight: 600; }
        .message.sent .bubble .msg-status.delivered { color: #bdc3c7; }
        .message .bubble .file-box { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: rgba(255,255,255,0.08); border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); cursor: pointer; margin-top: 5px; flex-wrap: wrap; }
        .message.received .bubble .file-box { background: #f0f2f5; border: 1px solid #e4e6eb; }
        .message .bubble .file-box .file-icon { font-size: 24px; flex-shrink: 0; }
        .message .bubble .file-box .file-info { flex: 1; min-width: 100px; }
        .message .bubble .file-box .file-name { font-size: 13px; font-weight: 500; word-break: break-all; }
        .message .bubble .file-box .file-size { font-size: 11px; opacity: 0.6; }

        .no-chat-selected { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #999; padding: 40px; }
        .no-chat-selected i { font-size: 56px; margin-bottom: 12px; color: #ddd; }
        .no-chat-selected h3 { color: #050505; margin-bottom: 4px; }

        .quick-replies { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px 16px; background: #f8f9fa; border-top: 1px solid #e4e6eb; }
        .quick-replies .qr-btn { background: #e7f3ff; color: #1b4d3e; border: none; border-radius: 16px; padding: 4px 14px; font-size: 12px; cursor: pointer; }
        .quick-replies .qr-btn:hover { background: #1b4d3e; color: white; }

        .staged-file-box { display: none; padding: 10px 16px; background: #f8f9fa; border-top: 1px solid #e4e6eb; }
        .staged-file-box.show { display: block; }
        .staged-file-box .staged-preview { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: #fff; border-radius: 8px; border: 1px solid #e4e6eb; }
        .staged-file-box .staged-preview .staged-icon { font-size: 26px; color: #1b4d3e; flex-shrink: 0; }
        .staged-file-box .staged-preview .staged-info { flex: 1; min-width: 0; }
        .staged-file-box .staged-preview .staged-name { font-size: 13px; font-weight: 500; color: #050505; word-break: break-all; }
        .staged-file-box .staged-preview .staged-size { font-size: 11px; color: #888; }
        .staged-file-box .staged-preview .staged-remove { background: none; border: none; color: #e74c3c; cursor: pointer; font-size: 18px; flex-shrink: 0; padding: 4px 8px; }
        .staged-file-box .staged-hint { font-size: 11px; color: #888; margin-top: 6px; text-align: center; }

        .chat-input-area { padding: 8px 16px 12px; border-top: 1px solid #e4e6eb; background: white; display: flex; gap: 8px; align-items: flex-end; position: relative; flex-wrap: wrap; }
        .chat-input-area .input-wrapper { flex: 1; display: flex; align-items: center; background: #f0f2f5; border-radius: 20px; padding: 4px 12px; border: 1px solid transparent; transition: 0.2s; min-width: 200px; position: relative; }
        .chat-input-area .input-wrapper:focus-within { border-color: #1b4d3e; background: white; }
        .chat-input-area .input-wrapper input { flex: 1; border: none; background: transparent; padding: 8px 4px; font-size: 14px; outline: none; font-family: inherit; }
        .chat-input-area .action-btn { width: 38px; height: 38px; border: none; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .chat-input-area .file-btn { background: #e4e6eb; color: #65676b; position: relative; }
        .chat-input-area .file-btn:hover { background: #d0d2d5; }
        .chat-input-area .file-btn input[type="file"] { position: absolute; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .chat-input-area .clear-btn { background: #e4e6eb; color: #e74c3c; }
        .chat-input-area .clear-btn:hover { background: #fdecea; }
        .chat-input-area .send-btn { background: #1b4d3e; color: white; }
        .chat-input-area .send-btn:hover { background: #2d6a4f; }

        .storage-info { padding: 12px 20px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px; }

        .file-viewer-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.85); z-index: 99999; justify-content: center; align-items: center; padding: 20px; backdrop-filter: blur(3px); }
        .file-viewer-overlay.active { display: flex; }
        .file-viewer-modal { background: #fff; width: 100%; max-width: 900px; height: 90vh; border-radius: 10px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.4); }
        .file-viewer-header { background: #1b4d3e; color: white; padding: 12px 18px; display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .file-viewer-header .fv-icon { font-size: 20px; }
        .file-viewer-header .fv-name { flex: 1; min-width: 0; font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-viewer-header .fv-btn { background: #f1c40f; color: #1b4d3e; border: none; border-radius: 4px; cursor: pointer; padding: 6px 14px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .file-viewer-header .fv-btn:hover { background: #d4a800; }
        .file-viewer-header .fv-close { background: transparent; color: white; border: 1px solid rgba(255,255,255,0.4); border-radius: 4px; cursor: pointer; padding: 6px 12px; font-size: 14px; }
        .file-viewer-body { flex: 1; overflow: auto; background: #f0f2f5; display: flex; align-items: center; justify-content: center; padding: 15px; }
        .file-viewer-body iframe { width: 100%; height: 100%; border: none; border-radius: 6px; background: #fff; }
        .file-viewer-body img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px; }
        .file-viewer-body .fv-fallback { text-align: center; color: #555; padding: 40px 20px; }
        .file-viewer-body .fv-fallback .fv-fallback-icon { font-size: 72px; color: #1b4d3e; margin-bottom: 20px; }
        .file-viewer-body .fv-fallback h3 { font-size: 18px; margin-bottom: 8px; color: #1b4d3e; }
        .file-viewer-body .fv-fallback a { display: inline-block; padding: 10px 24px; background: #1b4d3e; color: white; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600; }

        .mode-toggle { display: flex; justify-content: center; background: #e4e6eb; border-radius: 20px; padding: 4px; margin: 10px 16px; }
        .mode-toggle button { flex: 1; border: none; background: transparent; padding: 6px; border-radius: 16px; font-size: 12px; font-weight: 600; color: #65676b; cursor: pointer; transition: 0.2s; }
        .mode-toggle button.active { background: #fff; color: #1b4d3e; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

        /* SUGGESTION DROPDOWN */
        .mention-suggestions {
            position: absolute; bottom: 100%; left: 0; width: 100%; background: #fff;
            border: 1px solid #ddd; border-radius: 6px; box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            max-height: 200px; overflow-y: auto; z-index: 100; display: none; margin-bottom: 5px;
        }
        .mention-suggestions.show { display: block; }
        .mention-suggestions div { padding: 8px 12px; cursor: pointer; font-size: 13px; color: #1b4d3e; font-weight: 600; }
        .mention-suggestions div:hover { background: #e7f3ff; }

        /* Dark mode */
        body.dark-mode { background: #18191a; }
        body.dark-mode .main-content { background: #18191a; }
        body.dark-mode .dashboard-header-bar, body.dark-mode .chat-container, body.dark-mode .chat-sidebar, body.dark-mode .chat-area, body.dark-mode .chat-area-header, body.dark-mode .chat-input-area, body.dark-mode .chat-sidebar-header, body.dark-mode .file-viewer-modal { background: #242526; border-color: #3e4042; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .chat-sidebar-header h4 { color: #e4e6eb; }
        body.dark-mode .chat-search input { background: #3e4042; color: #e4e6eb; }
        body.dark-mode .chat-office-item:hover { background: #3e4042; }
        body.dark-mode .chat-office-item.active { background: #2d3a4f; border-left-color: #f1c40f; }
        body.dark-mode .chat-office-item .office-name { color: #e4e6eb; }
        body.dark-mode .chat-office-item .office-last, body.dark-mode .chat-office-item .chat-item-time { color: #b0b3b8; }
        body.dark-mode .chat-office-item.has-unread .office-name, body.dark-mode .chat-office-item.has-unread .office-last { color: #fff; }
        body.dark-mode .chat-office-item.has-unread .chat-item-time { color: #f1c40f; }
        body.dark-mode .day-divider { color: #b0b3b8; }
        body.dark-mode .chat-area-header .chat-with { color: #e4e6eb; }
        body.dark-mode .chat-area-header .chat-type { color: #b0b3b8; }
        body.dark-mode .chat-messages { background: #18191a; }
        body.dark-mode .message.received .bubble { background: #3e4042; color: #e4e6eb; }
        body.dark-mode .message.received .bubble .msg-time { color: #b0b3b8; }
        body.dark-mode .message.received .bubble .msg-sender { color: #f1c40f; }
        body.dark-mode .quick-replies { background: #242526; border-color: #3e4042; }
        body.dark-mode .quick-replies .qr-btn { background: #3e4042; color: #f1c40f; }
        body.dark-mode .chat-input-area .input-wrapper { background: #3e4042; }
        body.dark-mode .chat-input-area .input-wrapper input { color: #e4e6eb; }
        body.dark-mode .chat-input-area .file-btn { background: #3e4042; color: #b0b3b8; }
        body.dark-mode .chat-input-area .clear-btn { background: #3e4042; color: #e74c3c; }
        body.dark-mode .no-chat-selected h3 { color: #e4e6eb; }
        body.dark-mode .no-chat-selected i { color: #3e4042; }
        body.dark-mode .message .bubble .file-box { background: #3e4042; border-color: #4a4c4e; }
        body.dark-mode .staged-file-box { background: #242526; border-color: #3e4042; }
        body.dark-mode .staged-file-box .staged-preview { background: #3e4042; border-color: #4a4c4e; }
        body.dark-mode .staged-file-box .staged-preview .staged-name { color: #e4e6eb; }
        body.dark-mode .file-viewer-body { background: #2c2c2c; }
        body.dark-mode .mode-toggle { background: #1a1a1a; }
        body.dark-mode .mode-toggle button.active { background: #3e4042; color: #f1c40f; }
        body.dark-mode .mention-suggestions { background: #242526; border-color: #3e4042; }
        body.dark-mode .mention-suggestions div { color: #f1c40f; border-bottom: 1px solid #3e4042; }
        body.dark-mode .mention-suggestions div:hover { background: #3e4042; }

        @media (max-width: 768px) {
            .dark-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .chat-container { flex-direction: column; height: calc(100vh - 120px); }
            .chat-sidebar { width: 100%; max-height: 320px; border-right: none; border-bottom: 1px solid #e4e6eb; }
            .chat-area-header .back-btn { display: block; }
            .chat-area { min-height: 300px; }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo"><img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo"></div>
    <div class="header-title">
        <h1>CHMSU E-CLEARANCE</h1>
        <p><?php echo strtoupper($office); ?> - Messages</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()"><i class="fas fa-moon"></i></button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="dark-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
            <p>Clearance Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?officesection=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?officesection=note"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li><a href="?officesection=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?officesection=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li>
                <a href="?officesection=chat" class="active">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php if ($chat_unread > 0): ?>
                        <span class="sidebar-badge" id="sidebarChatBadge"><?php echo $chat_unread; ?></span>
                    <?php else: ?>
                        <span class="sidebar-badge" id="sidebarChatBadge" style="display:none;">0</span>
                    <?php endif; ?>
                </a>
            </li>
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

        <div class="chat-container">
            <div class="chat-sidebar">
                <div class="chat-sidebar-header">
                    <h4><i class="fas fa-comment"></i> Messages</h4>
                </div>
                
                <div class="mode-toggle">
                    <button id="btnIndividual" class="active" onclick="toggleSidebarMode('individual')">Individual</button>
                    <button id="btnGroup" onclick="toggleSidebarMode('group')">Group</button>
                </div>

                <div class="chat-search">
                    <input type="text" id="searchInput" placeholder="🔍 Search..." onkeyup="filterSidebar()">
                </div>

                <!-- Lists are rendered by JavaScript (live, Messenger-style) -->
                <div class="list-scroll" id="individualList"></div>
                <div class="list-scroll" id="groupList" style="display:none;"></div>
            </div>

            <div class="chat-area">
                <div class="chat-area-header">
                    <button class="back-btn" onclick="closeChat()"><i class="fas fa-arrow-left"></i></button>
                    <div class="header-avatar" id="headerAvatar" style="background: #1b4d3e; display: none;">?</div>
                    <div class="header-info">
                        <div class="chat-with" id="chatWith">Select a conversation</div>
                        <div class="chat-type" id="chatStatus">Click an item to start chatting</div>
                    </div>
                    <div class="chat-actions">
                        <button onclick="clearChatHistory()" title="Clear chat"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </div>

                <div class="chat-messages" id="chatMessages">
                    <div class="no-chat-selected">
                        <i class="fas fa-comment-dots"></i>
                        <h3>No conversation selected</h3>
                        <p style="color: #999; font-size: 13px;">Select a student or group to start chatting</p>
                    </div>
                </div>

                <div class="quick-replies" id="quickReplies">
                    <button class="qr-btn" onclick="sendQuickReply('Hello')">Hello</button>
                    <button class="qr-btn" onclick="sendQuickReply('Clearance Status')">Clearance Status</button>
                    <button class="qr-btn" onclick="sendQuickReply('Requirements')">Requirements</button>
                    <button class="qr-btn" onclick="sendQuickReply('Help')">Help</button>
                    <button class="qr-btn" onclick="sendQuickReply('Thank you')">Thank you</button>
                </div>

                <div class="staged-file-box" id="stagedFileBox">
                    <div class="staged-preview">
                        <i class="fas fa-file staged-icon" id="stagedIcon"></i>
                        <div class="staged-info">
                            <div class="staged-name" id="stagedName">—</div>
                            <div class="staged-size" id="stagedSize">0 KB</div>
                        </div>
                        <button type="button" class="staged-remove" onclick="removeStagedFile()" title="Remove file"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="staged-hint">📎 File ready. Press <strong>Send</strong> to upload it.</div>
                </div>

                <div class="chat-input-area">
                    <div class="input-wrapper">
                        <div class="mention-suggestions" id="mentionSuggestions"></div>
                        <input type="text" id="chatInput" placeholder="Type a message..." onkeydown="if(event.key==='Enter') sendChatMessage()" oninput="checkMentions(this.value)">
                    </div>
                    <button class="action-btn file-btn" title="Attach file">
                        <i class="fas fa-paperclip"></i>
                        <input type="file" id="chatFileInput" onchange="stageFile(this)" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt">
                    </button>
                    <button class="action-btn send-btn" onclick="sendChatMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="file-viewer-overlay" id="fileViewerOverlay" onclick="closeFileViewer(event)">
    <div class="file-viewer-modal" onclick="event.stopPropagation()">
        <div class="file-viewer-header">
            <i class="fas fa-file file-viewer-fv-icon" id="fvIcon"></i>
            <div class="fv-name" id="fvName">File Preview</div>
            <a class="fv-btn" id="fvDownloadBtn" href="#" download><i class="fas fa-download"></i> Download</a>
            <button class="fv-close" onclick="closeFileViewer(event)"><i class="fas fa-times"></i></button>
        </div>
        <div class="file-viewer-body" id="fvBody"></div>
    </div>
</div>

<script>
const CHAT_URL = window.location.pathname + <?php echo json_encode($chat_url_param); ?>;
const suggestions = {
    years: <?php echo json_encode($suggestionYears); ?>,
    sections: <?php echo json_encode($suggestionSections); ?>,
    courses: <?php echo json_encode($suggestionCourses); ?>
};
const INIT = <?php echo $initJson; ?>;

let currentMode = 'none';          // none | individual | course
let selectedStudentId = null;
let selectedCourse = '';
let lastRenderedMsgId = 0;
let lastRenderedDay = '';
let stagedFile = null;
let listData = { individual: INIT.individual || [], groups: INIT.groups || [] };
let listsBusy = false;

function getInitials(name) {
    if (!name) return '?';
    const words = name.trim().split(/\s+/);
    let ini = '', c = 0;
    for (const w of words) { if (c < 2 && w.length > 0) { ini += w[0].toUpperCase(); c++; } }
    return ini || '?';
}
function getAvatarColor(name) {
    const colors = ['#1b4d3e','#2d6a4f','#f39c12','#e74c3c','#3498db','#9b59b6','#1abc9c','#e67e22','#2ecc71','#e84393'];
    let hash = 0;
    const s = name ? String(name) : '';
    for (let i = 0; i < s.length; i++) hash += s.charCodeAt(i);
    return colors[hash % colors.length];
}
function escapeHtml(t) { if (!t) return ''; const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
function getFileIcon(type) {
    const icons = { pdf:'fa-file-pdf', doc:'fa-file-word', docx:'fa-file-word', xls:'fa-file-excel', xlsx:'fa-file-excel', jpg:'fa-file-image', jpeg:'fa-file-image', png:'fa-file-image', gif:'fa-file-image', txt:'fa-file-alt' };
    return icons[(type || '').toLowerCase()] || 'fa-file';
}
function formatFileSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
}
function chatPost(fd) {
    return fetch(CHAT_URL, { method: 'POST', body: fd })
        .then(r => r.text())
        .then(t => {
            const i = t.lastIndexOf('@@CHATJSON@@');
            if (i < 0) throw new Error('Invalid server response');
            return JSON.parse(t.slice(i + 12));
        });
}

// ============================================
// MESSENGER-STYLE LISTS (live, newest on top, accurate unread)
// ============================================
function isActiveStudent(id) { return currentMode === 'individual' && String(selectedStudentId) === String(id); }
function isActiveCourse(c) { return currentMode === 'course' && selectedCourse === c; }

function renderIndividualList() {
    const box = document.getElementById('individualList');
    const st = box.scrollTop;
    if (!listData.individual.length) {
        box.innerHTML = '<div style="padding: 20px; text-align: center; color: #999;">No conversations yet.</div>';
        return;
    }
    box.innerHTML = '';
    listData.individual.forEach(s => {
        const active = isActiveStudent(s.id);
        const un = active ? 0 : (parseInt(s.unread) || 0);
        const item = document.createElement('div');
        item.className = 'chat-office-item student-item' + (active ? ' active' : '') + (un > 0 ? ' has-unread' : '');
        item.id = 'student_item_' + s.id;
        item.dataset.name = (s.name || '').toLowerCase();
        item.innerHTML =
            `<div class="avatar-circle" style="background:#1b4d3e;">${escapeHtml((s.name || '?').charAt(0).toUpperCase())}</div>` +
            `<div class="office-info"><div class="office-name">${escapeHtml(s.name)}</div>` +
            `<div class="office-last">${escapeHtml((s.last_message || 'No messages yet').substring(0, 40))}</div></div>` +
            `<div class="chat-item-meta">${s.label ? `<span class="chat-item-time">${escapeHtml(s.label)}</span>` : ''}` +
            `<span class="unread-badge" ${un > 0 ? '' : 'style="display:none;"'}>${un > 99 ? '99+' : un}</span></div>`;
        item.addEventListener('click', () => selectStudent(s.id, s.name));
        box.appendChild(item);
    });
    box.scrollTop = st;
}

function renderGroupList() {
    const box = document.getElementById('groupList');
    const st = box.scrollTop;
    box.innerHTML = '<div class="list-section-label">Course Groups</div>';
    listData.groups.forEach(g => {
        const active = isActiveCourse(g.course);
        const un = active ? 0 : (parseInt(g.unread) || 0);
        const preview = g.last_message
            ? ((g.last_sender ? g.last_sender + ': ' : '') + g.last_message).substring(0, 42)
            : (g.students + ' students');
        const item = document.createElement('div');
        item.className = 'chat-office-item course-item' + (active ? ' active' : '') + (un > 0 ? ' has-unread' : '');
        item.dataset.name = (g.course || '').toLowerCase();
        item.innerHTML =
            `<div class="avatar-circle" style="background:#9b59b6;">${escapeHtml((g.course || '?').charAt(0))}</div>` +
            `<div class="office-info"><div class="office-name">${escapeHtml(g.course)} Group</div>` +
            `<div class="office-last">${escapeHtml(preview)}</div></div>` +
            `<div class="chat-item-meta">${g.label ? `<span class="chat-item-time">${escapeHtml(g.label)}</span>` : ''}` +
            `<span class="unread-badge" ${un > 0 ? '' : 'style="display:none;"'}>${un > 99 ? '99+' : un}</span></div>`;
        item.addEventListener('click', () => selectCourse(g.course, g.students));
        box.appendChild(item);
    });
    if (!listData.groups.length) {
        box.innerHTML += '<div style="padding: 20px; text-align: center; color: #999;">No courses found.</div>';
    }
    box.scrollTop = st;
}

function updateBadgeTotal() {
    let total = 0;
    listData.individual.forEach(s => { if (!isActiveStudent(s.id)) total += parseInt(s.unread) || 0; });
    listData.groups.forEach(g => { if (!isActiveCourse(g.course)) total += parseInt(g.unread) || 0; });
    document.querySelectorAll('.chat-badge, #sidebarChatBadge, #hdrMessagesBadge').forEach(b => {
        if (total > 0) {
            b.textContent = total > 99 ? '99+' : total;
            b.style.display = (b.id === 'hdrMessagesBadge') ? '' : 'inline';
        } else {
            b.style.display = 'none';
        }
    });
}

function renderLists() {
    renderIndividualList();
    renderGroupList();
    updateBadgeTotal();
    filterSidebar();
    // Live message count in the open group header
    if (currentMode === 'course') {
        const g = listData.groups.find(x => x.course === selectedCourse);
        if (g) {
            document.getElementById('chatStatus').textContent =
                g.students + ' students • Course Group • ' + g.total + ' message' + (g.total == 1 ? '' : 's');
        }
    }
}

function refreshLists() {
    if (listsBusy) return Promise.resolve();
    listsBusy = true;
    const fd = new FormData();
    fd.append('action', 'chat_lists');
    return chatPost(fd)
        .then(d => {
            if (d && Array.isArray(d.individual) && Array.isArray(d.groups)) {
                listData = d;
                renderLists();
            }
        })
        .catch(() => {})
        .then(() => { listsBusy = false; });
}
function updateUnreadBadge() { refreshLists(); }

function toggleSidebarMode(mode) {
    if (mode === 'individual') {
        document.getElementById('btnIndividual').classList.add('active');
        document.getElementById('btnGroup').classList.remove('active');
        document.getElementById('individualList').style.display = 'block';
        document.getElementById('groupList').style.display = 'none';
    } else {
        document.getElementById('btnIndividual').classList.remove('active');
        document.getElementById('btnGroup').classList.add('active');
        document.getElementById('individualList').style.display = 'none';
        document.getElementById('groupList').style.display = 'block';
    }
    closeChat();
    document.getElementById('searchInput').value = '';
    filterSidebar();
}

function filterSidebar() {
    const s = document.getElementById('searchInput').value.toLowerCase().trim();
    const isIndiv = document.getElementById('individualList').style.display !== 'none';
    const listId = isIndiv ? 'individualList' : 'groupList';
    document.querySelectorAll(`#${listId} .chat-office-item`).forEach(item => {
        const name = item.dataset.name || '';
        item.style.display = name.includes(s) ? 'flex' : 'none';
    });
}

// ============================================
// SELECT CONVERSATIONS
// ============================================
function selectStudent(studentId, studentName) {
    currentMode = 'individual';
    selectedStudentId = studentId;
    selectedCourse = '';
    lastRenderedMsgId = 0;
    hideStagedFile();
    document.getElementById('mentionSuggestions').classList.remove('show');
    
    document.getElementById('chatInput').placeholder = 'Type a message...';
    document.getElementById('chatWith').textContent = studentName;
    document.getElementById('chatStatus').textContent = 'Individual Chat';

    const headerAvatar = document.getElementById('headerAvatar');
    headerAvatar.textContent = getInitials(studentName);
    headerAvatar.style.background = getAvatarColor(studentName);
    headerAvatar.style.display = 'flex';

    document.getElementById('searchInput').value = '';
    document.getElementById('chatMessages').innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">Loading messages...</div>';

    renderLists();                 // highlights the chat and hides its badge immediately
    loadChatHistory(studentId, true);
    markChatRead(studentId);
}

function selectCourse(course, count) {
    currentMode = 'course';
    selectedCourse = course;
    selectedStudentId = null;
    lastRenderedMsgId = 0;
    hideStagedFile();
    
    document.getElementById('chatInput').placeholder = 'Message ' + course + '... (type @ to mention)';
    document.getElementById('chatWith').textContent = course + ' Group';
    document.getElementById('chatStatus').textContent = count + ' students • Course Group';

    const headerAvatar = document.getElementById('headerAvatar');
    headerAvatar.textContent = course.charAt(0);
    headerAvatar.style.background = getAvatarColor(course);
    headerAvatar.style.display = 'flex';

    document.getElementById('searchInput').value = '';
    document.getElementById('chatMessages').innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">Loading messages...</div>';

    renderLists();
    loadGroupMessages(course, true);
    markGroupRead(course);
}

// ============================================
// MENTIONS (course group only)
// ============================================
function checkMentions(val) {
    const suggestionsBox = document.getElementById('mentionSuggestions');
    if (currentMode !== 'course') {
        suggestionsBox.classList.remove('show');
        return;
    }

    const lastAt = val.lastIndexOf('@');
    if (lastAt === -1) {
        suggestionsBox.classList.remove('show');
        return;
    }

    const query = val.substring(lastAt + 1).toLowerCase();
    let allOptions = [];
    suggestions.years.forEach(y => allOptions.push(y));
    suggestions.years.forEach(y => {
        suggestions.sections.forEach(s => { allOptions.push(y + ' ' + s); });
    });

    const filtered = allOptions.filter(opt => opt.toLowerCase().includes(query));
    if (filtered.length === 0) {
        suggestionsBox.classList.remove('show');
        return;
    }

    suggestionsBox.innerHTML = '';
    filtered.forEach(opt => {
        const div = document.createElement('div');
        div.textContent = '@' + opt;
        div.onclick = () => insertMention(opt, lastAt);
        suggestionsBox.appendChild(div);
    });
    suggestionsBox.classList.add('show');
}

function insertMention(mention, startIndex) {
    const input = document.getElementById('chatInput');
    const val = input.value;
    const before = val.substring(0, startIndex);
    const after = val.substring(startIndex + 1);
    input.value = before + '@' + mention + ' ' + after;
    document.getElementById('mentionSuggestions').classList.remove('show');
    input.focus();
}

// ============================================
// RENDER MESSAGES (with Messenger-style day dividers)
// ============================================
function dayLabel(dayKey) {
    const d = new Date(dayKey);
    if (isNaN(d.getTime())) return dayKey;
    const t = new Date(); const y = new Date(); y.setDate(t.getDate() - 1);
    const same = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    if (same(d, t)) return 'Today';
    if (same(d, y)) return 'Yesterday';
    return dayKey;
}

function renderMessage(messagesDiv, msg, isGroup) {
    if (messagesDiv.querySelector(`[data-msg-id="${msg.id}"]`)) return msg.id;

    const dayKey = (msg.time || '').split(' ').slice(0, 3).join(' ');
    if (dayKey && dayKey !== lastRenderedDay) {
        lastRenderedDay = dayKey;
        const dv = document.createElement('div');
        dv.className = 'day-divider';
        dv.textContent = dayLabel(dayKey);
        messagesDiv.appendChild(dv);
    }

    const isSent = msg.sender_type === 'office';
    const div = document.createElement('div');
    div.className = `message ${isSent ? 'sent' : 'received'}`;
    div.dataset.msgId = msg.id;

    let content = '';
    if (isGroup) {
        let tag = msg.target_label ? `<span class="tag">${escapeHtml(msg.target_label)}</span>` : '';
        if (!isSent) content += `<span class="msg-sender">${escapeHtml(msg.sender_name || 'Student')}${tag}</span>`;
    }
    if (msg.message !== '📎 File attached') content += escapeHtml(msg.message);

    if (msg.file_path) {
        const icon = getFileIcon(msg.file_type);
        const sp = String(msg.file_path).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        const sn = escapeHtml(msg.file_name || 'file');
        const st = String(msg.file_type || '').replace(/'/g, "\\'");
        content += `<div class="file-box" onclick="openFileViewer('${sp}', '${sn}', '${st}')"><i class="fas ${icon} file-icon"></i><div class="file-info"><div class="file-name">${sn}</div><div class="file-size">Click to view</div></div></div>`;
    }

    let statusHtml = '';
    if (isSent && !isGroup) {
        if (parseInt(msg.is_read) === 1) statusHtml = ' <span class="msg-status seen">✓✓</span>';
        else statusHtml = ' <span class="msg-status delivered">✓</span>';
    }
    content += `<span class="msg-time">${msg.time}${statusHtml}</span>`;

    let avatarHtml = '';
    if (!isSent) {
        const nm = isGroup ? (msg.sender_name || 'Student') : document.getElementById('chatWith').textContent;
        avatarHtml = `<div class="msg-avatar" style="background:${getAvatarColor(nm)};">${getInitials(nm)}</div>`;
    }

    div.innerHTML = avatarHtml + `<div class="bubble">${content}</div>`;
    messagesDiv.appendChild(div);
    return msg.id;
}

function loadChatHistory(studentId, clearFirst) {
    const messagesDiv = document.getElementById('chatMessages');
    if (clearFirst) { messagesDiv.innerHTML = ''; lastRenderedMsgId = 0; lastRenderedDay = ''; }

    const fd = new FormData();
    fd.append('action', 'get_chat_messages');
    fd.append('student_id', studentId);
    fd.append('since_id', lastRenderedMsgId);

    fetch(CHAT_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (currentMode !== 'individual' || selectedStudentId !== studentId) return;
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

            // New student message arrived while this chat is open -> it is seen, so clear unread
            if (!clearFirst && data.some(m => m.sender_type === 'student')) markChatRead(studentId);
        })
        .catch(() => {});
}

function loadGroupMessages(course, clearFirst) {
    const messagesDiv = document.getElementById('chatMessages');
    if (clearFirst) { messagesDiv.innerHTML = ''; lastRenderedMsgId = 0; lastRenderedDay = ''; }

    const fd = new FormData();
    fd.append('action', 'grp_get');
    fd.append('course', course);
    fd.append('since_id', lastRenderedMsgId);
    fd.append('open', '1');          // chat is open on screen: server marks everything fetched as seen

    chatPost(fd)
        .then(data => {
            if (currentMode !== 'course' || selectedCourse !== course) return;
            if (!Array.isArray(data)) return;
            if (clearFirst && data.length === 0) {
                messagesDiv.innerHTML = '<div style="text-align:center;color:#999;font-size:12px;padding:20px;">No group messages yet.</div>';
                return;
            }
            if (data.length === 0) return;

            const notice = messagesDiv.querySelector('div[style*="No group messages yet"]');
            if (notice) notice.remove();
            const nearBottom = (messagesDiv.scrollHeight - messagesDiv.scrollTop - messagesDiv.clientHeight) < 80;

            data.forEach(msg => {
                const id = renderMessage(messagesDiv, msg, true);
                if (id > lastRenderedMsgId) lastRenderedMsgId = id;
            });
            if (nearBottom || clearFirst) messagesDiv.scrollTop = messagesDiv.scrollHeight;
        })
        .catch(() => {});
}

// ============================================
// MARK READ (clears the alert immediately, then confirms with the server)
// ============================================
function markChatRead(studentId) {
    const s = listData.individual.find(x => String(x.id) === String(studentId));
    if (s) s.unread = 0;
    renderLists();

    const fd = new FormData();
    fd.append('action', 'mark_chat_read');
    fd.append('student_id', studentId);
    fetch(CHAT_URL, { method: 'POST', body: fd }).then(() => refreshLists()).catch(() => {});
}

function markGroupRead(course) {
    const g = listData.groups.find(x => x.course === course);
    if (g) g.unread = 0;
    renderLists();

    const fd = new FormData();
    fd.append('action', 'grp_read');
    fd.append('course', course);
    chatPost(fd).then(() => refreshLists()).catch(() => {});
}

// ============================================
// FILE STAGING
// ============================================
function stageFile(fileInput) {
    if (currentMode === 'none') { alert('Please select a chat first.'); fileInput.value = ''; return; }
    const file = fileInput.files[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) { alert('Max 10 MB.'); fileInput.value = ''; return; }
    stagedFile = file;
    const ext = file.name.split('.').pop().toLowerCase();
    document.getElementById('stagedIcon').className = `fas ${getFileIcon(ext)} staged-icon`;
    document.getElementById('stagedName').textContent = file.name;
    document.getElementById('stagedSize').textContent = formatFileSize(file.size);
    document.getElementById('stagedFileBox').classList.add('show');
}
function removeStagedFile() { stagedFile = null; hideStagedFile(); }
function hideStagedFile() {
    document.getElementById('stagedFileBox').classList.remove('show');
    document.getElementById('chatFileInput').value = '';
    stagedFile = null;
}

// ============================================
// SEND
// ============================================
function sendChatMessage() {
    if (currentMode === 'none') { alert('Please select a chat first.'); return; }

    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message && !stagedFile) return;

    const fd = new FormData();
    let useWrapped = true;
    let refresh;

    if (currentMode === 'course') {
        fd.append('action', 'grp_send');
        fd.append('group_type', 'course');
        fd.append('course', selectedCourse);
        const c = selectedCourse;
        refresh = () => loadGroupMessages(c, false);
    } else {
        useWrapped = false;
        const sid = selectedStudentId;
        fd.append('action', 'send_chat_message');
        fd.append('chat_type', 'individual');
        fd.append('student_id', sid);
        refresh = () => loadChatHistory(sid, false);
    }

    input.value = '';
    document.getElementById('mentionSuggestions').classList.remove('show');
    const messagesDiv = document.getElementById('chatMessages');
    messagesDiv.querySelectorAll('div[style*="No messages yet"], div[style*="No group messages yet"]').forEach(n => n.remove());

    const sentDiv = document.createElement('div');
    sentDiv.className = 'message sent';
    let tempBubble = '';
    if (message) tempBubble += escapeHtml(message);
    if (stagedFile) {
        const ext = stagedFile.name.split('.').pop().toLowerCase();
        tempBubble += `<div class="file-box"><i class="fas ${getFileIcon(ext)} file-icon"></i><div class="file-info"><div class="file-name">${escapeHtml(stagedFile.name)}</div><div class="file-size">Uploading...</div></div></div>`;
    }
    tempBubble += `<span class="msg-time">Sending...</span>`;
    sentDiv.innerHTML = `<div class="bubble">${tempBubble}</div>`;
    messagesDiv.appendChild(sentDiv);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;

    fd.append('message', message || '📎 File attached');
    if (stagedFile) fd.append('chat_file', stagedFile);

    stagedFile = null;
    hideStagedFile();

    const req = useWrapped ? chatPost(fd) : fetch(CHAT_URL, { method: 'POST', body: fd }).then(r => r.json());

    req.then(res => {
        sentDiv.remove();
        if (res && res.success) {
            if (currentMode === 'individual') refresh();
            else setTimeout(refresh, 300);
            // Move this conversation to the top right away (Messenger style)
            setTimeout(refreshLists, 350);
        } else {
            alert('Failed to send: ' + ((res && res.message) || 'unknown'));
        }
    })
    .catch(() => {
        const t = sentDiv.querySelector('.msg-time');
        if (t) t.textContent = 'Failed';
    });
}

function sendQuickReply(t) {
    document.getElementById('chatInput').value = t;
    sendChatMessage();
}

function clearChatHistory() {
    if (currentMode !== 'individual') { alert('Clear only available in individual chat.'); return; }
    if (!selectedStudentId) return;
    if (confirm('Clear all messages with this student?')) {
        const sid = selectedStudentId;
        const fd = new FormData();
        fd.append('action', 'clear_chat');
        fd.append('student_id', sid);
        fetch(CHAT_URL, { method: 'POST', body: fd })
            .then(() => { loadChatHistory(sid, true); refreshLists(); })
            .catch(() => {});
    }
}

function closeChat() {
    currentMode = 'none';
    selectedStudentId = null; selectedCourse = '';
    document.getElementById('chatInput').placeholder = 'Type a message...';
    document.getElementById('mentionSuggestions').classList.remove('show');
    document.getElementById('chatWith').textContent = 'Select a conversation';
    document.getElementById('chatStatus').textContent = 'Click an item to start chatting';
    document.getElementById('headerAvatar').style.display = 'none';
    document.getElementById('chatMessages').innerHTML = `<div class="no-chat-selected"><i class="fas fa-comment-dots"></i><h3>No conversation selected</h3><p style="color: #999; font-size: 13px;">Select a student or group to start chatting</p></div>`;
    hideStagedFile();
    renderLists();
}

// ============================================
// FILE VIEWER
// ============================================
function openFileViewer(path, name, type) {
    const overlay = document.getElementById('fileViewerOverlay');
    const body = document.getElementById('fvBody');
    const iconEl = document.getElementById('fvIcon');
    const nameEl = document.getElementById('fvName');
    const dlBtn = document.getElementById('fvDownloadBtn');

    const t = (type || '').toLowerCase();
    const ic = getFileIcon(t);
    iconEl.className = `fas ${ic} file-viewer-fv-icon`;
    nameEl.textContent = name || 'File';
    dlBtn.href = path; dlBtn.setAttribute('download', name || '');

    if (['jpg','jpeg','png','gif'].includes(t)) body.innerHTML = `<img src="${path}" alt="${escapeHtml(name)}">`;
    else if (t === 'pdf' || t === 'txt') body.innerHTML = `<iframe src="${path}" title="${escapeHtml(name)}"></iframe>`;
    else body.innerHTML = `<div class="fv-fallback"><i class="fas ${ic} fv-fallback-icon"></i><h3>Preview not available</h3><a href="${path}" download="${escapeHtml(name)}"><i class="fas fa-download"></i> Download</a></div>`;

    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeFileViewer() {
    document.getElementById('fileViewerOverlay').classList.remove('active');
    document.getElementById('fvBody').innerHTML = '';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFileViewer(); });

function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const dark = document.body.classList.contains('dark-mode');
    localStorage.setItem('darkMode', dark);
    const btn = document.querySelector('.dark-mode-toggle');
    if (btn) btn.innerHTML = dark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
}
if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
    const btn = document.querySelector('.dark-mode-toggle');
    if (btn) btn.innerHTML = '<i class="fas fa-sun"></i>';
}
function confirmLogout() { if (confirm('Logout?')) window.location.href = '?logout=1'; }

// ============================================
// START + LIVE REFRESH
// ============================================
renderLists();

setInterval(() => {
    if (currentMode === 'individual') loadChatHistory(selectedStudentId, false);
    else if (currentMode === 'course') loadGroupMessages(selectedCourse, false);
    refreshLists();
}, 4000);

console.log('Office chat ready');
</script>

</body>
</html>