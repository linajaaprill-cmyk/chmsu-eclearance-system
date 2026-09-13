<?php
$office = $_SESSION['office'];
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentOfficeSection = 'note';

// ============================================
// CREATE TABLES IF NOT EXISTS
// ============================================
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_hidden_posts (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    post_id INT(11) NOT NULL,
    hidden_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_hidden (student_id, post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ============================================
// HANDLE POST ADVISORY
// ============================================
if (isset($_POST['action']) && $_POST['action'] == 'post_note') {
    $subject = sanitize($_POST['subject']);
    $content = sanitize($_POST['content']);
    $mentioned_names_raw = isset($_POST['mentioned_students']) ? $_POST['mentioned_students'] : '';
    $mentioned_section = isset($_POST['mentioned_section']) ? sanitize($_POST['mentioned_section']) : '';
    $mentioned_year = isset($_POST['mentioned_year']) ? sanitize($_POST['mentioned_year']) : '';
    $office = $_SESSION['office'];
    
    // ============================================
    // Get students by section from masterlist - CASE INSENSITIVE & TRIM
    // ============================================
    $section_students = array();
    if (!empty($mentioned_section) && !empty($mentioned_year)) {
        // Clean up the section value - trim and uppercase for comparison
        $clean_section = strtoupper(trim($mentioned_section));
        $clean_year = trim($mentioned_year);
        
        $sectionQuery = $conn->query("SELECT chmsu_student_id, chmsu_full_name, email 
                                      FROM chmsu_students_master 
                                      WHERE chmsu_year = '$clean_year' 
                                      AND TRIM(UPPER(chmsu_section)) = '$clean_section'
                                      AND is_archived = 0");
        
        // DEBUG: Log the query and count
        error_log("Section query: SELECT chmsu_student_id, chmsu_full_name, email FROM chmsu_students_master WHERE chmsu_year = '$clean_year' AND TRIM(UPPER(chmsu_section)) = '$clean_section' AND is_archived = 0");
        error_log("Found " . $sectionQuery->num_rows . " students in section");
        
        while ($s = $sectionQuery->fetch_assoc()) {
            $section_students[] = $s;
        }
        
        // If no students found, try with archived students too (just in case)
        if (empty($section_students)) {
            $sectionQuery = $conn->query("SELECT chmsu_student_id, chmsu_full_name, email 
                                          FROM chmsu_students_master 
                                          WHERE chmsu_year = '$clean_year' 
                                          AND TRIM(UPPER(chmsu_section)) = '$clean_section'");
            while ($s = $sectionQuery->fetch_assoc()) {
                $section_students[] = $s;
            }
            if (!empty($section_students)) {
                error_log("Found " . count($section_students) . " students in section (including archived)");
            }
        }
    }
    
    // ============================================
    // Handle individual student mentions
    // ============================================
    $mentioned_names = array();
    if (!empty($mentioned_names_raw)) {
        // Split by ||| delimiter
        if (strpos($mentioned_names_raw, '|||') !== false) {
            $mentioned_names = array_map('trim', explode('|||', $mentioned_names_raw));
        } else {
            // Fallback: split by comma and try to group full names
            $temp = array_map('trim', explode(',', $mentioned_names_raw));
            $full_names = array();
            $i = 0;
            while ($i < count($temp)) {
                if (isset($temp[$i+1]) && isset($temp[$i+2])) {
                    $combined = $temp[$i] . ', ' . $temp[$i+1] . ', ' . $temp[$i+2];
                    $check = $conn->query("SELECT chmsu_full_name FROM chmsu_students_master WHERE chmsu_full_name = '$combined'");
                    if ($check && $check->num_rows > 0) {
                        $full_names[] = $combined;
                        $i += 3;
                        continue;
                    }
                }
                if (isset($temp[$i+1])) {
                    $combined = $temp[$i] . ', ' . $temp[$i+1];
                    $check = $conn->query("SELECT chmsu_full_name FROM chmsu_students_master WHERE chmsu_full_name = '$combined'");
                    if ($check && $check->num_rows > 0) {
                        $full_names[] = $combined;
                        $i += 2;
                        continue;
                    }
                }
                $full_names[] = $temp[$i];
                $i++;
            }
            $mentioned_names = $full_names;
        }
    }
    
    // Filter out empty values and remove duplicates
    $mentioned_names = array_filter($mentioned_names, function($name) {
        return !empty(trim($name));
    });
    $mentioned_names = array_values(array_unique($mentioned_names));
    
    $mention_ids = array();
    $mention_emails = array();
    $mention_full_names = array();
    
    // Get student data for each individual mention
    foreach ($mentioned_names as $student_name) {
        $student_name = trim(sanitize($student_name));
        if (empty($student_name)) continue;
        
        $student = $conn->query("SELECT s.chmsu_student_id, s.chmsu_full_name, u.email 
                                 FROM chmsu_students_master s
                                 LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                 WHERE s.chmsu_full_name = '$student_name' 
                                 LIMIT 1");
        
        if ($student && $student->num_rows > 0) {
            $s = $student->fetch_assoc();
            $mention_ids[] = $s['chmsu_student_id'];
            $mention_full_names[] = $s['chmsu_full_name'];
            $mention_emails[] = $s['email'];
        }
    }
    
    // ============================================
    // Add section students to the mention list
    // ============================================
    $section_mention_names = array();
    foreach ($section_students as $s) {
        // Check if already mentioned individually
        if (!in_array($s['chmsu_student_id'], $mention_ids)) {
            $mention_ids[] = $s['chmsu_student_id'];
            $mention_full_names[] = $s['chmsu_full_name'];
            $mention_emails[] = $s['email'];
            $section_mention_names[] = $s['chmsu_full_name'];
        }
    }
    
    // ============================================
    // Check if any students were mentioned (individual or section)
    // ============================================
    if (empty($mention_ids)) {
        $error = "Please mention at least one student or select a section with students.";
    } else {
        $primary_student_id = $mention_ids[0];
        
        // Build section mention text
        $section_text = '';
        if (!empty($mentioned_section) && !empty($mentioned_year)) {
            $section_text = " (Section: " . $mentioned_year . "th Year - " . $mentioned_section . ")";
        }
        
        // Build full content with mentions
        $all_mentions = implode('|||', $mention_full_names);
        $full_content = $content;
        
        if (!empty($all_mentions)) {
            $full_content .= "\n\n👥 Mentioned: " . $all_mentions;
        }
        
        // Insert into chmsu_feed
        $insert = $conn->query("INSERT INTO chmsu_feed 
                               (office_name, student_id, title, content, priority, category, is_pinned, is_archived, created_by) 
                               VALUES ('$office', '$primary_student_id', '$subject', '$full_content', 'medium', 'advisory', 0, 0, '$office')");
        
        if ($insert) {
            $feed_id = $conn->insert_id;
            
            // Notify ALL students about new update
            $allStudents = $conn->query("SELECT s.chmsu_student_id, s.chmsu_full_name, u.email 
                                         FROM chmsu_students_master s
                                         LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                         WHERE s.is_archived = 0");
            
            while ($allStudent = $allStudents->fetch_assoc()) {
                $sid = $allStudent['chmsu_student_id'];
                $sname = $allStudent['chmsu_full_name'];
                $semail = $allStudent['email'];
                $isMentioned = in_array($sid, $mention_ids);
                
                // System notification for ALL students
                $notif_title = $isMentioned ? "🔔 You were mentioned by $office" : "📢 New update from $office";
                $notif_message = $isMentioned ? "You were mentioned in: $subject" : "New advisory posted: $subject";
                
                $conn->query("INSERT INTO chmsu_notifications 
                              (user_type, user_id, student_id, office_name, title, message, type, related_id, feed_id, link) 
                              VALUES 
                              ('student', '$sid', '$sid', '$office', 
                               '$notif_title', '$notif_message', 'feed', $feed_id, $feed_id, '?view=feed')");
                
                // Gmail notification - ONLY for mentioned students
                if ($isMentioned && !empty($semail)) {
                    $subject_email = "🔔 URGENT: You were mentioned by $office - CHMSU";
                    $email_message = "
                    <html>
                    <head>
                    <style>
                        body { font-family: 'Times New Roman', Times, serif; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
                        .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
                        .header h2 { margin: 0; }
                        .alarm { background: #e74c3c; color: white; padding: 15px; text-align: center; font-size: 22px; font-weight: bold; border-radius: 4px; margin: 15px 0; }
                        .subject-box { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #1b4d3e; }
                        .subject-box h3 { color: #1b4d3e; margin: 0; font-size: 18px; }
                        .note-box { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #f39c12; }
                        .footer { text-align: center; padding: 15px; font-size: 12px; color: #666; border-top: 1px solid #ddd; margin-top: 20px; }
                        .btn { display: inline-block; background: #1b4d3e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; }
                        .section-tag { background: #3498db; color: white; padding: 2px 10px; border-radius: 12px; font-size: 11px; }
                    </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'><h2>CHMSU E-Clearance System</h2></div>
                            <div class='alarm'>🚨 YOU HAVE BEEN MENTIONED 🚨</div>
                            <div class='content'>
                                <p>Hello <strong>$sname</strong>,</p>
                                <p>You have been <strong>mentioned</strong> by <strong>$office</strong>:</p>
                                <div class='subject-box'><h3>📌 $subject</h3></div>
                                <div class='note-box'><p>$content</p></div>
                                <p style='margin-top: 10px;'>
                                    <span style='background:#f1c40f;padding:2px 10px;border-radius:12px;'>@$sname</span>
                                    $section_text
                                </p>
                                <p>Please login to your student portal to view this advisory.</p>
                                <a href='http://" . $_SERVER['HTTP_HOST'] . "/index.php?view=feed' class='btn'>View Advisories</a>
                            </div>
                            <div class='footer'><p>CHMSU E-Clearance System</p></div>
                        </div>
                    </body>
                    </html>
                    ";
                    sendEmail($semail, $sname, $subject_email, $email_message, true);
                }
            }
            
            $totalMentioned = count($mention_full_names);
            $sectionMsg = (!empty($mentioned_section) && !empty($mentioned_year) && count($section_students) > 0) ? " and " . count($section_students) . " student(s) from " . $mentioned_year . "th Year - " . $mentioned_section : "";
            logActivity($conn, $office, 'office', "Posted advisory with mentions: $subject");
            $success = "Advisory posted! " . $totalMentioned . " student(s) mentioned" . $sectionMsg . ".";
        } else {
            $error = "Failed to post advisory: " . $conn->error;
        }
    }
}

// ============================================
// DELETE NOTE
// ============================================
if (isset($_POST['action']) && $_POST['action'] == 'delete_note' && isset($_SESSION['office'])) {
    $note_id = intval($_POST['note_id']);
    $conn->query("DELETE FROM chmsu_feed WHERE id = $note_id AND office_name = '{$_SESSION['office']}'");
    $conn->query("DELETE FROM chmsu_notifications WHERE feed_id = $note_id");
    $conn->query("DELETE FROM chmsu_feed_views WHERE feed_id = $note_id");
    $conn->query("DELETE FROM chmsu_hidden_posts WHERE post_id = $note_id");
    $success = "Advisory deleted successfully!";
}

// ============================================
// GET ALL NOTES FOR THIS OFFICE
// ============================================
$notes = $conn->query("SELECT f.*, 
                        (SELECT COUNT(*) FROM chmsu_feed_views v WHERE v.feed_id = f.id) as view_count,
                        (SELECT GROUP_CONCAT(CONCAT(s.chmsu_full_name, ' (', DATE_FORMAT(v.viewed_at, '%m/%d/%Y %h:%i %p'), ')') SEPARATOR ', ') 
                         FROM chmsu_feed_views v 
                         JOIN chmsu_students_master s ON v.student_id = s.chmsu_student_id
                         WHERE v.feed_id = f.id ORDER BY v.viewed_at DESC) as viewers
                        FROM chmsu_feed f 
                        WHERE f.office_name='$office' 
                        ORDER BY f.created_at DESC");
$totalPosts = $notes ? $notes->num_rows : 0;
$allNotes = [];
if ($notes && $notes->num_rows > 0) {
    while ($row = $notes->fetch_assoc()) {
        $allNotes[] = $row;
    }
}

// ============================================
// GET ALL STUDENTS FOR AUTOCOMPLETE
// ============================================
$students = $conn->query("SELECT s.chmsu_student_id, s.chmsu_full_name, s.chmsu_last_name, s.chmsu_first_name, u.email 
                          FROM chmsu_students_master s
                          LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                          WHERE s.is_archived = 0 
                          ORDER BY s.chmsu_last_name ASC");
$allStudents = [];
if ($students && $students->num_rows > 0) {
    while ($s = $students->fetch_assoc()) {
        $allStudents[] = $s;
    }
}

// ============================================
// GET SECTIONS FOR DROPDOWN - UPPERCASE FOR CONSISTENCY
// ============================================
$sections = $conn->query("SELECT DISTINCT UPPER(TRIM(section_name)) as section_name FROM chmsu_course_sections ORDER BY section_name");
$years = [1, 2, 3, 4];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - <?php echo strtoupper($office); ?> Advisories</title>
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
        
        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }
        
        .office-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .office-sidebar::-webkit-scrollbar { width: 5px; }
        .office-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .office-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .office-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .office-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .office-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
        }
        .office-sidebar .sidebar-menu a:hover,
        .office-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
        .main-content { flex: 1; margin-left: 260px; padding: 20px; background: #f5f5f5; min-height: calc(100vh - 73px); }
        .dashboard-header-bar {
            background: white;
            padding: 15px 20px;
            border: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }
        
        .content-card { background: white; border: 1px solid #ddd; margin-bottom: 20px; border-radius: 8px; overflow: hidden; }
        .content-card-header { background: #f8f9fa; padding: 12px 15px; border-bottom: 1px solid #ddd; font-size: 13px; font-weight: normal; display: flex; justify-content: space-between; align-items: center; }
        .content-card-body { padding: 15px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #333; font-size: 12px; }
        input, select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #ddd; font-size: 13px; border-radius: 4px; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #1b4d3e; }
        
        .btn { padding: 8px 15px; border: none; cursor: pointer; font-size: 12px; border-radius: 4px; }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-primary:hover { background: #2d6a4f; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
        
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        
        .student-scanner { position: relative; }
        .student-scanner input { width: 100%; padding: 8px 10px; border: 1px solid #ddd; font-size: 13px; border-radius: 4px; }
        .student-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        .student-suggestions.show { display: block; }
        .student-suggestions .suggestion-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; font-size: 12px; }
        .student-suggestions .suggestion-item:hover { background: #f0f8ff; }
        .student-suggestions .suggestion-item .suggestion-id { color: #999; font-size: 10px; }
        
        .mention-tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
            min-height: 40px;
            border: 1px dashed #ddd;
        }
        .mention-tag-item {
            background: #1b4d3e;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .mention-tag-item .remove-mention { cursor: pointer; color: #e74c3c; font-weight: bold; }
        .mention-tag-item .remove-mention:hover { color: #fff; background: #e74c3c; border-radius: 50%; padding: 0 4px; }
        
        .section-selector {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .section-selector select {
            flex: 1;
            min-width: 100px;
        }
        .section-selector .btn {
            flex: 0 0 auto;
        }
        .section-info {
            margin-top: 8px;
            font-size: 11px;
            color: #27ae60;
            background: #e8f5e9;
            padding: 6px 12px;
            border-radius: 4px;
            display: none;
        }
        .section-info.show {
            display: block;
        }
        
        .note-post {
            background: white;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            margin-bottom: 16px;
            padding: 16px 20px;
            transition: all 0.3s;
        }
        .note-post:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .note-post .post-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
        .note-post .post-office { font-weight: bold; color: #1b4d3e; font-size: 14px; }
        .note-post .post-office i { color: #1b4d3e; margin-right: 5px; }
        .note-post .post-subject {
            font-size: 20px;
            font-weight: bold;
            color: #1b4d3e;
            margin-bottom: 6px;
            padding: 8px 12px;
            background: #f0f8ff;
            border-radius: 4px;
            border-left: 4px solid #1b4d3e;
        }
        .note-post .post-content {
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
        .note-post .post-content.expanded { max-height: none; }
        .note-post .post-content .mention-section {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px dashed #ddd;
            font-size: 12px;
            color: #555;
        }
        .note-post .post-content .mention-section .mention-name { font-weight: bold; color: #1b4d3e; }
        .post-meta { font-size: 11px; color: #999; display: flex; gap: 15px; flex-wrap: wrap; margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee; }
        .post-viewers { font-size: 10px; color: #666; margin-top: 5px; padding-top: 5px; border-top: 1px solid #eee; }
        .mention-tag { background: #f1c40f; color: #000; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; }
        .section-badge { background: #3498db; color: white; padding: 2px 10px; border-radius: 12px; font-size: 10px; display: inline-block; }
        
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
        
        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card,
        body.dark-mode .note-post { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .note-post .post-subject { background: #1a3a2a; color: #f1c40f; border-left-color: #f1c40f; }
        body.dark-mode .note-post .post-content { background: #2c2c2c; color: #fff; }
        body.dark-mode .note-post .post-meta { color: #aaa; }
        body.dark-mode .note-post .post-viewers { border-top-color: #333; color: #aaa; }
        body.dark-mode input, body.dark-mode select, body.dark-mode textarea { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .student-suggestions { background: #1a1a1a; border-color: #444; }
        body.dark-mode .student-suggestions .suggestion-item { border-bottom-color: #333; color: #fff; }
        body.dark-mode .student-suggestions .suggestion-item:hover { background: #2c2c2c; }
        body.dark-mode .mention-tags-container { background: #2c2c2c; border-color: #444; }
        body.dark-mode .see-more-btn { background: #2c2c2c; color: #f1c40f; }
        body.dark-mode .see-more-btn:hover { background: #3c3c3c; }
        body.dark-mode .section-info { background: #1a3a2a; color: #f1c40f; }
        
        @media (max-width: 768px) {
            .office-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .section-selector { flex-direction: column; align-items: stretch; }
            .section-selector select { flex: 1; }
            .section-selector .btn { width: 100%; }
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
        <p>CLEARANCE SYSTEM | <?php echo strtoupper($office); ?> Portal - Advisories</p>
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
            <li><a href="?officesection=note" class="active"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li><a href="?officesection=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?officesection=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
        <div style="padding: 12px 20px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px; border-radius: 2px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f; border-radius: 2px;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2><?php echo htmlspecialchars($office); ?> - Post Advisories</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- POST ADVISORY FORM -->
        <div class="content-card">
            <div class="content-card-header">
                <span><i class="fas fa-sticky-note"></i> Create New Advisory</span>
                <span style="font-size: 11px; color: #666;">Mention students individually or by section</span>
            </div>
            <div class="content-card-body">
                <form method="POST" id="noteForm">
                    <input type="hidden" name="action" value="post_note">
                    
                    <div class="form-group">
                        <label>Subject <span style="color: #e74c3c;">*</span></label>
                        <input type="text" name="subject" placeholder="Enter subject/title" 
                               style="font-size: 16px; font-weight: bold; padding: 10px 12px;" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Description <span style="color: #e74c3c;">*</span></label>
                        <textarea name="content" id="postContent" placeholder="Write your advisory details here..." required style="min-height: 120px;"></textarea>
                    </div>
                    
                    <!-- SECTION MENTION -->
                    <div class="form-group">
                        <label>Mention by Section</label>
                        <div class="section-selector">
                            <select name="mentioned_year" id="sectionYear">
                                <option value="">Select Year</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                            <select name="mentioned_section" id="sectionName">
                                <option value="">Select Section</option>
                                <?php 
                                $sections_list = $conn->query("SELECT DISTINCT UPPER(TRIM(section_name)) as section_name FROM chmsu_course_sections ORDER BY section_name");
                                while ($s = $sections_list->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $s['section_name']; ?>"><?php echo $s['section_name']; ?></option>
                                <?php endwhile; ?>
                            </select>
                            <button type="button" class="btn btn-primary" onclick="addSectionMention()">
                                <i class="fas fa-users"></i> Add Section
                            </button>
                        </div>
                        <div class="section-info" id="sectionInfo">
                            <i class="fas fa-info-circle"></i> 
                            All active students in the selected section will be mentioned and receive Gmail notifications.
                        </div>
                    </div>
                    
                    <!-- INDIVIDUAL MENTION -->
                    <div class="form-group">
                        <label>Mention Individual Students (Optional)</label>
                        <div class="student-scanner">
                            <input type="text" id="studentSearch" placeholder="Type student name to mention..." autocomplete="off">
                            <div class="student-suggestions" id="studentSuggestions"></div>
                        </div>
                        <small style="font-size: 10px; color: #999;">Type a name and click to add. You can mention multiple students.</small>
                        
                        <div class="mention-tags-container" id="mentionTagsContainer">
                            <span style="color: #999; font-size: 11px;" id="emptyMentionText">No students mentioned yet</span>
                        </div>
                        <input type="hidden" name="mentioned_students" id="mentionedStudents" value="">
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px;">
                        <i class="fas fa-paper-plane"></i> Post & Mention Students
                    </button>
                    <div style="margin-top: 10px; font-size: 11px; color: #666; background: #f8f9fa; padding: 8px 12px; border-radius: 4px;">
                        <i class="fas fa-info-circle"></i> 
                        All mentioned students will receive notifications in their feed and via Gmail.
                    </div>
                </form>
            </div>
        </div>
        
        <!-- MY ADVISORIES -->
        <div class="content-card">
            <div class="content-card-header">
                <span><i class="fas fa-history"></i> My Advisories</span>
                <span style="font-size: 11px; color: #666;">
                    <?php echo $totalPosts; ?> posts
                </span>
            </div>
            <div class="content-card-body">
                <?php if (count($allNotes) > 0): ?>
                    <?php foreach ($allNotes as $post): 
                        // Split by ||| delimiter for proper full names
                        $mentionedNames = array();
                        if (preg_match_all('/👥 Mentioned: (.*?)(?:\n|$)/', $post['content'], $matches)) {
                            $namesStr = trim($matches[1][0]);
                            if (!empty($namesStr)) {
                                if (strpos($namesStr, '|||') !== false) {
                                    $mentionedNames = array_map('trim', explode('|||', $namesStr));
                                } else {
                                    $temp = array_map('trim', explode(',', $namesStr));
                                    $fullNames = array();
                                    for ($i = 0; $i < count($temp); $i += 2) {
                                        if (isset($temp[$i+1])) {
                                            $fullNames[] = $temp[$i] . ', ' . $temp[$i+1];
                                        } else {
                                            $fullNames[] = $temp[$i];
                                        }
                                    }
                                    $mentionedNames = $fullNames;
                                }
                            }
                        }
                        $mentionedNames = array_values(array_unique($mentionedNames));
                        $cleanContent = preg_replace('/\n\n👥 Mentioned: .*/', '', $post['content']);
                        $isLong = strlen($cleanContent) > 500 || substr_count($cleanContent, "\n") > 10;
                    ?>
                    <div class="note-post">
                        <div class="post-header">
                            <div>
                                <span class="post-office">
                                    <i class="fas fa-building"></i> <?php echo htmlspecialchars($post['office_name']); ?>
                                </span>
                                <?php if (!empty($mentionedNames)): ?>
                                    <span class="mention-tag">
                                        <i class="fas fa-at"></i> <?php echo count($mentionedNames); ?> mentioned
                                    </span>
                                <?php endif; ?>
                            </div>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete_note">
                                <input type="hidden" name="note_id" value="<?php echo $post['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this advisory?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                        <div class="post-subject"><?php echo htmlspecialchars($post['title']); ?></div>
                        <div class="post-content" id="postContent_<?php echo $post['id']; ?>">
                            <?php echo nl2br(htmlspecialchars($cleanContent)); ?>
                            <?php if (!empty($mentionedNames)): ?>
                                <div class="mention-section">
                                    <div style="font-weight: bold; margin-bottom: 5px;">👥 Mentioned:</div>
                                    <?php foreach ($mentionedNames as $name): ?>
                                        <div class="mention-name" style="display: block; padding: 3px 0;">
                                            <?php echo htmlspecialchars($name); ?>
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
                            <span><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y H:i', strtotime($post['created_at'])); ?></span>
                            <span><i class="fas fa-eye"></i> <?php echo $post['view_count']; ?> views</span>
                        </div>
                        <?php if (!empty($post['viewers'])): ?>
                            <div class="post-viewers">
                                <i class="fas fa-users"></i> Viewed by: <?php echo htmlspecialchars($post['viewers']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 30px; color: #999;">
                        <i class="fas fa-sticky-note" style="font-size: 48px; margin-bottom: 10px;"></i>
                        <p>You haven't posted any advisories yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
var students = <?php echo json_encode($allStudents); ?>;
var selectedMentions = [];

function toggleContent(postId) {
    var content = document.getElementById('postContent_' + postId);
    var seeMoreText = document.getElementById('seeMoreText_' + postId);
    if (content.classList.contains('expanded')) {
        content.classList.remove('expanded');
        seeMoreText.textContent = 'See More';
    } else {
        content.classList.add('expanded');
        seeMoreText.textContent = 'See Less';
    }
}

// ============================================
// SECTION MENTION FUNCTION - CASE INSENSITIVE
// ============================================
function addSectionMention() {
    var year = document.getElementById('sectionYear').value;
    var section = document.getElementById('sectionName').value;
    
    if (!year || !section) {
        alert('Please select both Year and Section.');
        return;
    }
    
    // DEBUG: Log what we're searching for
    console.log('Searching for students in Year:', year, 'Section:', section);
    
    // Find students in this section from masterlist - case insensitive, trim spaces
    var sectionStudents = students.filter(function(s) {
        var studentSection = s.chmsu_section ? s.chmsu_section.trim().toUpperCase() : '';
        var searchSection = section.trim().toUpperCase();
        var match = s.chmsu_year == year && studentSection == searchSection;
        if (match) {
            console.log('Found student:', s.chmsu_full_name, 'in section', studentSection);
        }
        return match;
    });
    
    console.log('Total students found in section:', sectionStudents.length);
    
    if (sectionStudents.length === 0) {
        alert('No students found in ' + year + 'th Year - ' + section + '.\n\nPlease check:\n1. The section name is correct (case insensitive)\n2. The year is correct\n3. Students are active (not archived)');
        return;
    }
    
    // Add all students from this section
    var added = 0;
    sectionStudents.forEach(function(s) {
        if (selectedMentions.indexOf(s.chmsu_full_name) === -1) {
            selectedMentions.push(s.chmsu_full_name);
            added++;
        }
    });
    
    if (added > 0) {
        var infoDiv = document.getElementById('sectionInfo');
        infoDiv.classList.add('show');
        infoDiv.innerHTML = '<i class="fas fa-check-circle"></i> Added ' + added + ' student(s) from ' + year + 'th Year - ' + section;
        updateMentionTags();
    } else {
        alert('All students in this section are already mentioned.');
    }
}

// ============================================
// INDIVIDUAL STUDENT MENTION
// ============================================
document.getElementById('studentSearch').addEventListener('input', function() {
    var search = this.value.toLowerCase().trim();
    var suggestions = document.getElementById('studentSuggestions');
    
    if (search.length < 1) {
        suggestions.classList.remove('show');
        return;
    }
    
    var filtered = students.filter(function(s) {
        return selectedMentions.indexOf(s.chmsu_full_name) === -1 &&
            (s.chmsu_full_name.toLowerCase().indexOf(search) !== -1 ||
            s.chmsu_last_name.toLowerCase().indexOf(search) !== -1 ||
            s.chmsu_first_name.toLowerCase().indexOf(search) !== -1 ||
            s.chmsu_student_id.indexOf(search) !== -1);
    });
    
    if (filtered.length === 0) {
        suggestions.innerHTML = '<div class="suggestion-item" style="color: #999;">No students found</div>';
        suggestions.classList.add('show');
        return;
    }
    
    var html = '';
    for (var i = 0; i < Math.min(filtered.length, 10); i++) {
        var s = filtered[i];
        html += '<div class="suggestion-item" data-name="' + s.chmsu_full_name + '">' +
            '<strong>' + s.chmsu_full_name + '</strong> ' +
            '<span class="suggestion-id">(' + s.chmsu_student_id + ')</span>' +
            (s.email ? '<span style="color: #27ae60; font-size: 9px;"> 📧</span>' : '') +
            '</div>';
    }
    suggestions.innerHTML = html;
    suggestions.classList.add('show');
    
    suggestions.querySelectorAll('.suggestion-item').forEach(function(item) {
        item.addEventListener('click', function() {
            var name = this.dataset.name;
            addMention(name);
            document.getElementById('studentSearch').value = '';
            suggestions.classList.remove('show');
        });
    });
});

function addMention(name) {
    if (selectedMentions.indexOf(name) !== -1) return;
    selectedMentions.push(name);
    updateMentionTags();
    // Hide section info if it was showing
    document.getElementById('sectionInfo').classList.remove('show');
}

function removeMention(name) {
    selectedMentions = selectedMentions.filter(function(m) { return m !== name; });
    updateMentionTags();
}

function updateMentionTags() {
    var container = document.getElementById('mentionTagsContainer');
    var hiddenInput = document.getElementById('mentionedStudents');
    
    container.innerHTML = '';
    
    if (selectedMentions.length === 0) {
        container.innerHTML = '<span style="color: #999; font-size: 11px;">No students mentioned yet</span>';
        hiddenInput.value = '';
        return;
    }
    
    for (var i = 0; i < selectedMentions.length; i++) {
        var name = selectedMentions[i];
        var tag = document.createElement('span');
        tag.className = 'mention-tag-item';
        tag.innerHTML = '<i class="fas fa-at"></i> ' + name +
            '<span class="remove-mention" onclick="removeMention(\'' + name + '\')">&times;</span>';
        container.appendChild(tag);
    }
    
    hiddenInput.value = selectedMentions.join('|||');
    console.log('Mentions saved to hidden input:', hiddenInput.value);
}

// Close suggestions when clicking outside
document.addEventListener('click', function(e) {
    var container = document.querySelector('.student-scanner');
    if (!container.contains(e.target)) {
        document.getElementById('studentSuggestions').classList.remove('show');
    }
});

// Form validation
document.getElementById('noteForm').addEventListener('submit', function(e) {
    var selected = document.getElementById('mentionedStudents').value;
    var year = document.getElementById('sectionYear').value;
    var section = document.getElementById('sectionName').value;
    
    console.log('SUBMITTING - Mentions:', selected, 'Year:', year, 'Section:', section);
    
    // Check if either individual students or section is selected
    if ((!selected || selected.trim() === '') && (!year || !section)) {
        e.preventDefault();
        alert('Please mention at least one student or select a section.');
        return false;
    }
    
    // If section is selected but no students in that section
    if (year && section) {
        var sectionStudents = students.filter(function(s) {
            var studentSection = s.chmsu_section ? s.chmsu_section.trim().toUpperCase() : '';
            var searchSection = section.trim().toUpperCase();
            return s.chmsu_year == year && studentSection == searchSection;
        });
        console.log('Form validation - Students found in section:', sectionStudents.length);
        if (sectionStudents.length === 0) {
            e.preventDefault();
            alert('No active students found in ' + year + 'th Year - ' + section + '.\n\nPlease check:\n1. The section name is correct\n2. The year is correct\n3. Students are active (not archived)');
            return false;
        }
    }
    
    return true;
});

function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    var isDarkMode = document.body.classList.contains('dark-mode');
    localStorage.setItem('darkMode', isDarkMode);
    var btn = document.querySelector('.dark-mode-toggle');
    if (btn) {
        btn.innerHTML = isDarkMode ? '<i class="fas fa-sun"></i> Light Mode' : '<i class="fas fa-moon"></i> Dark Mode';
    }
}

if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
    var btn = document.querySelector('.dark-mode-toggle');
    if (btn) {
        btn.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
    }
}

function confirmLogout() {
    if(confirm('Are you sure you want to logout?')) {
        window.location.href = '?logout=1';
    }
}
</script>

</body>
</html>