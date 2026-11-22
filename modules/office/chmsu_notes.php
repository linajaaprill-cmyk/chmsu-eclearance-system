<?php
// ============================================
// FIX: Prevent "headers already sent" errors
// Buffer all output so header("Location: ...") keeps working.
// ============================================
if (!ob_get_level()) {
    ob_start();
}

// ============================================
// INCREASE TIMEOUT FOR BULK EMAIL OPERATIONS
// ============================================
@set_time_limit(120);
@ini_set('max_execution_time', 120);

$office = $_SESSION['office'];
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentOfficeSection = 'note';

// ============================================
// DETECT CURRENT SEMESTER + SCHOOL YEAR
// ============================================
$current_sem_row = $conn->query("SELECT * FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
$current_sem_id = $current_sem_row ? intval($current_sem_row['id']) : 0;
$current_sem_name = $current_sem_row ? $current_sem_row['semester_name'] : '—';

$current_sy_label = '—';
if ($current_sem_row && !empty($current_sem_row['school_year_id'])) {
    $syCol = 'school_year';
    $syColCheck = $conn->query("SHOW COLUMNS FROM chmsu_school_years");
    if ($syColCheck) {
        $syCols = [];
        while ($c = $syColCheck->fetch_assoc()) $syCols[] = $c['Field'];
        foreach (['school_year','year_name','sy','name','school_year_name'] as $cand) {
            if (in_array($cand, $syCols)) { $syCol = $cand; break; }
        }
    }
    $syId = intval($current_sem_row['school_year_id']);
    $syR = $conn->query("SELECT `$syCol` as label FROM chmsu_school_years WHERE id = $syId LIMIT 1")->fetch_assoc();
    if ($syR) $current_sy_label = $syR['label'];
}

// ============================================
// ENSURE chmsu_feed HAS semester_id + school_year_id
// ============================================
$feedChk1 = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
if ($feedChk1 && $feedChk1->num_rows == 0) {
    $conn->query("ALTER TABLE chmsu_feed ADD COLUMN semester_id INT(11) DEFAULT NULL");
}
$feedChk2 = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'school_year_id'");
if ($feedChk2 && $feedChk2->num_rows == 0) {
    $conn->query("ALTER TABLE chmsu_feed ADD COLUMN school_year_id INT(11) DEFAULT NULL AFTER semester_id");
}

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
// DISPLAY FLASH MESSAGES
// ============================================
$error = '';
$success = '';
if (isset($_SESSION['note_flash_success'])) {
    $success = $_SESSION['note_flash_success'];
    unset($_SESSION['note_flash_success']);
}
if (isset($_SESSION['note_flash_error'])) {
    $error = $_SESSION['note_flash_error'];
    unset($_SESSION['note_flash_error']);
}

// ============================================
// HANDLE POST ADVISORY
// ============================================
if (isset($_POST['action']) && $_POST['action'] == 'post_note') {
    $subject = sanitize($_POST['subject']);
    $content = sanitize($_POST['content']);
    $mentioned_names_raw = isset($_POST['mentioned_students']) ? $_POST['mentioned_students'] : '';
    $mentioned_sections_raw = isset($_POST['mentioned_sections']) ? $_POST['mentioned_sections'] : '';
    $mentioned_courses_raw = isset($_POST['mentioned_courses']) ? $_POST['mentioned_courses'] : '';
    $office = $_SESSION['office'];

    // Parse mentioned sections (JSON array)
    $mentioned_sections = array();
    if (!empty($mentioned_sections_raw)) {
        $decoded = json_decode($mentioned_sections_raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $sec) {
                $y = isset($sec['year']) ? trim($sec['year']) : '';
                $s = isset($sec['section']) ? strtoupper(trim($sec['section'])) : '';
                $c = isset($sec['course']) ? strtoupper(trim($sec['course'])) : '';
                if ($y !== '' && $s !== '') {
                    $mentioned_sections[] = array('year' => $y, 'section' => $s, 'course' => $c);
                }
            }
        }
    }

    // Parse mentioned courses (JSON array)
    $mentioned_courses = array();
    if (!empty($mentioned_courses_raw)) {
        $decoded_c = json_decode($mentioned_courses_raw, true);
        if (is_array($decoded_c)) {
            foreach ($decoded_c as $cr) {
                $cc = isset($cr['course']) ? strtoupper(trim($cr['course'])) : '';
                if ($cc !== '') {
                    $mentioned_courses[] = array('course' => $cc);
                }
            }
        }
    }

    // Get students by section
    $section_students = array();
    foreach ($mentioned_sections as $sec) {
        $clean_year = $sec['year'];
        $clean_section = $sec['section'];
        $clean_course = $sec['course'];
        $courseClause = $clean_course !== '' ? " AND UPPER(TRIM(chmsu_course)) = '$clean_course' " : "";

        $sectionQuery = $conn->query("SELECT chmsu_student_id, chmsu_full_name, chmsu_course, chmsu_year, chmsu_section, email 
                                      FROM chmsu_students_master 
                                      WHERE chmsu_year = '$clean_year' 
                                      AND TRIM(UPPER(chmsu_section)) = '$clean_section'
                                      $courseClause
                                      AND is_archived = 0
                                      ORDER BY chmsu_last_name ASC, chmsu_first_name ASC");
        while ($s = $sectionQuery->fetch_assoc()) {
            $section_students[] = $s;
        }
        if ($sectionQuery->num_rows == 0) {
            $sectionQuery2 = $conn->query("SELECT chmsu_student_id, chmsu_full_name, chmsu_course, chmsu_year, chmsu_section, email 
                                           FROM chmsu_students_master 
                                           WHERE chmsu_year = '$clean_year' 
                                           AND TRIM(UPPER(chmsu_section)) = '$clean_section'
                                           $courseClause
                                           ORDER BY chmsu_last_name ASC, chmsu_first_name ASC");
            while ($s = $sectionQuery2->fetch_assoc()) {
                $section_students[] = $s;
            }
        }
    }

    // Get students by course
    $course_students = array();
    foreach ($mentioned_courses as $cr) {
        $clean_course = $cr['course'];
        if ($clean_course === '') continue;

        $courseQuery = $conn->query("SELECT chmsu_student_id, chmsu_full_name, chmsu_course, chmsu_year, chmsu_section, email 
                                     FROM chmsu_students_master 
                                     WHERE UPPER(TRIM(chmsu_course)) = '$clean_course'
                                     AND is_archived = 0
                                     ORDER BY CAST(chmsu_year AS UNSIGNED) ASC, chmsu_section ASC, chmsu_last_name ASC, chmsu_first_name ASC");
        while ($s = $courseQuery->fetch_assoc()) {
            $course_students[] = $s;
        }
        if ($courseQuery->num_rows == 0) {
            $courseQuery2 = $conn->query("SELECT chmsu_student_id, chmsu_full_name, chmsu_course, chmsu_year, chmsu_section, email 
                                          FROM chmsu_students_master 
                                          WHERE UPPER(TRIM(chmsu_course)) = '$clean_course'
                                          ORDER BY CAST(chmsu_year AS UNSIGNED) ASC, chmsu_section ASC, chmsu_last_name ASC, chmsu_first_name ASC");
            while ($s = $courseQuery2->fetch_assoc()) {
                $course_students[] = $s;
            }
        }
    }

    $section_student_ids = array();
    foreach ($section_students as $ss) $section_student_ids[] = $ss['chmsu_student_id'];
    $course_student_ids = array();
    foreach ($course_students as $cs) $course_student_ids[] = $cs['chmsu_student_id'];
    $group_student_ids = array_values(array_unique(array_merge($section_student_ids, $course_student_ids)));

    // Individual mentions
    $mentioned_names = array();
    if (!empty($mentioned_names_raw)) {
        if (strpos($mentioned_names_raw, '|||') !== false) {
            $mentioned_names = array_map('trim', explode('|||', $mentioned_names_raw));
        } else {
            $mentioned_names = array(trim($mentioned_names_raw));
        }
    }
    $mentioned_names = array_values(array_unique(array_filter($mentioned_names)));

    $mention_ids = array();
    $mention_emails = array();
    $mention_full_names = array();

    foreach ($mentioned_names as $student_name) {
        $student_name = trim(sanitize($student_name));
        if (empty($student_name)) continue;

        $student_name_esc = $conn->real_escape_string($student_name);
        $student = $conn->query("SELECT s.chmsu_student_id, s.chmsu_full_name, u.email 
                                 FROM chmsu_students_master s
                                 LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                 WHERE s.chmsu_full_name = '$student_name_esc'
                                    OR s.chmsu_student_id = '$student_name_esc'
                                    OR TRIM(CONCAT(s.chmsu_last_name, ', ', s.chmsu_first_name, ', ', s.chmsu_middle_name)) = '$student_name_esc'
                                    OR TRIM(CONCAT(s.chmsu_last_name, ', ', s.chmsu_first_name)) = '$student_name_esc'
                                 LIMIT 1");
        if ($student && $student->num_rows > 0) {
            $s = $student->fetch_assoc();
            if (in_array($s['chmsu_student_id'], $group_student_ids)) continue;
            $mention_ids[] = $s['chmsu_student_id'];
            $mention_full_names[] = $s['chmsu_full_name'];
            $mention_emails[] = $s['email'];
        }
    }

    sort($mention_full_names, SORT_NATURAL | SORT_FLAG_CASE);
    $mention_full_names = array_values(array_unique($mention_full_names));
    $all_notify_ids = array_values(array_unique(array_merge($mention_ids, $group_student_ids)));

    if (empty($all_notify_ids) && empty($mentioned_sections) && empty($mentioned_courses)) {
        $_SESSION['note_flash_error'] = "Please mention at least one student, section, or course.";
        $note_redirect = (isset($_SESSION['role']) && $_SESSION['role'] === 'registrar') ? '?section=note' : '?officesection=note';
        header("Location: " . $note_redirect);
        exit;
    } else {
        $primary_student_id = !empty($all_notify_ids) ? $all_notify_ids[0] : '';

        // Build SECTION SEPARATOR BLOCK
        $section_block = '';
        if (!empty($mentioned_sections)) {
            $grouped_by_section = array();
            foreach ($mentioned_sections as $sec) {
                $key = ($sec['course'] !== '' ? $sec['course'] . ' - ' : '') . $sec['year'] . 'th Year - ' . $sec['section'];
                $grouped_by_section[$key] = array();
            }
            foreach ($section_students as $s) {
                $sc = strtoupper(trim($s['chmsu_course']));
                foreach ($mentioned_sections as $sec) {
                    if ($sc === $sec['course'] || $sec['course'] === '') {
                        if ((string)$s['chmsu_year'] === (string)$sec['year'] &&
                            strtoupper(trim($s['chmsu_section'])) === $sec['section']) {
                            $key = ($sec['course'] !== '' ? $sec['course'] . ' - ' : '') . $sec['year'] . 'th Year - ' . $sec['section'];
                            $grouped_by_section[$key][] = $s['chmsu_full_name'];
                            break;
                        }
                    }
                }
            }

            uksort($grouped_by_section, function($a, $b) {
                preg_match('/(\d+)/', $a, $ma);
                preg_match('/(\d+)/', $b, $mb);
                $ya = isset($ma[1]) ? intval($ma[1]) : 0;
                $yb = isset($mb[1]) ? intval($mb[1]) : 0;
                if ($ya !== $yb) return $ya - $yb;
                return strcmp($a, $b);
            });

            $section_block .= "\n\n=== SECTION MENTION ===";
            foreach ($grouped_by_section as $label => $names) {
                sort($names, SORT_NATURAL | SORT_FLAG_CASE);
                $section_block .= "\n[" . $label . "]";
                foreach ($names as $n) $section_block .= "\n- " . $n;
                $section_block .= "\n---SEP---";
            }
            $section_block .= "\n=== END SECTION ===";
        }

        // Build COURSE SEPARATOR BLOCK
        $course_block = '';
        if (!empty($mentioned_courses)) {
            $grouped_by_course = array();
            foreach ($mentioned_courses as $cr) $grouped_by_course[$cr['course']] = array();

            foreach ($course_students as $s) {
                $sc = strtoupper(trim($s['chmsu_course']));
                if (!isset($grouped_by_course[$sc])) continue;
                $yearNumeric = intval($s['chmsu_year']);
                $subKey = $yearNumeric . 'th Year - Section ' . strtoupper(trim($s['chmsu_section']));
                if (!isset($grouped_by_course[$sc][$subKey])) $grouped_by_course[$sc][$subKey] = array();
                $grouped_by_course[$sc][$subKey][] = $s['chmsu_full_name'];
            }

            ksort($grouped_by_course);

            $course_block .= "\n\n=== COURSE MENTION ===";
            foreach ($grouped_by_course as $courseName => $subgroups) {
                uksort($subgroups, function($a, $b) {
                    preg_match('/^(\d+)/', $a, $ma);
                    preg_match('/^(\d+)/', $b, $mb);
                    $ya = isset($ma[1]) ? intval($ma[1]) : 0;
                    $yb = isset($mb[1]) ? intval($mb[1]) : 0;
                    if ($ya !== $yb) return $ya - $yb;
                    return strcmp($a, $b);
                });

                $course_block .= "\n{" . $courseName . "}";
                foreach ($subgroups as $subLabel => $names) {
                    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
                    $course_block .= "\n<" . $subLabel . ">";
                    foreach ($names as $n) $course_block .= "\n- " . $n;
                    $course_block .= "\n---SEP---";
                }
                $course_block .= "\n---COURSE-SEP---";
            }
            $course_block .= "\n=== END COURSE ===";
        }

        // Build final content
        $all_mentions = implode('|||', $mention_full_names);
        $full_content = $content;
        if (!empty($all_mentions)) $full_content .= "\n\n👥 Mentioned: " . $all_mentions;
        $full_content .= $section_block;
        $full_content .= $course_block;

        $section_text = '';
        if (!empty($mentioned_sections)) {
            $section_labels = array();
            foreach ($mentioned_sections as $sec) $section_labels[] = ($sec['course'] !== '' ? $sec['course'] . ' - ' : '') . $sec['year'] . "th Year - " . $sec['section'];
            $section_text .= " (Sections: " . implode(', ', $section_labels) . ")";
        }
        if (!empty($mentioned_courses)) {
            $course_labels = array();
            foreach ($mentioned_courses as $cr) $course_labels[] = $cr['course'];
            $section_text .= " (Courses: " . implode(', ', $course_labels) . ")";
        }

        $subject_esc = $conn->real_escape_string($subject);
        $content_esc = $conn->real_escape_string($full_content);
        $primary_esc = $conn->real_escape_string($primary_student_id);
        $office_esc = $conn->real_escape_string($office);

        $insert_sem_id = $current_sem_id > 0 ? $current_sem_id : 'NULL';
        $insert_sy_id  = ($current_sem_row && !empty($current_sem_row['school_year_id'])) ? intval($current_sem_row['school_year_id']) : 'NULL';

        $insert = $conn->query("INSERT INTO chmsu_feed 
                               (office_name, student_id, title, content, priority, category, is_pinned, is_archived, created_by, semester_id, school_year_id) 
                               VALUES ('$office_esc', '$primary_esc', '$subject_esc', '$content_esc', 'medium', 'advisory', 0, 0, '$office_esc', $insert_sem_id, $insert_sy_id)");

        if ($insert) {
            $feed_id = $conn->insert_id;

            $allStudents = $conn->query("SELECT s.chmsu_student_id, s.chmsu_full_name, u.email 
                                         FROM chmsu_students_master s
                                         LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                         WHERE s.is_archived = 0");

            $emailSentCount = 0;
            $maxEmailsPerPost = 20;

            while ($allStudent = $allStudents->fetch_assoc()) {
                $sid = $allStudent['chmsu_student_id'];
                $sname = $allStudent['chmsu_full_name'];
                $semail = $allStudent['email'];
                $isMentioned = in_array($sid, $all_notify_ids);

                $notif_title = $isMentioned ? "🔔 You were mentioned by $office" : "📢 New update from $office";
                $notif_message = $isMentioned ? "You were mentioned in: $subject" : "New advisory posted: $subject";

                $sid_esc = $conn->real_escape_string($sid);
                $nt = $conn->real_escape_string($notif_title);
                $nm = $conn->real_escape_string($notif_message);

                $conn->query("INSERT INTO chmsu_notifications 
                              (user_type, user_id, student_id, office_name, title, message, type, related_id, feed_id, link) 
                              VALUES 
                              ('student', '$sid_esc', '$sid_esc', '$office_esc', 
                               '$nt', '$nm', 'feed', $feed_id, $feed_id, '?view=feed')");

                if ($isMentioned && !empty($semail) && $emailSentCount < $maxEmailsPerPost) {
                    $emailSentCount++;
                    $subject_email = "URGENT: You were mentioned by $office - CHMSU";
                    $email_message = "
                    <html><head><style>
                        body { font-family: 'Times New Roman', Times, serif; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
                        .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
                        .alarm { background: #e74c3c; color: white; padding: 15px; text-align: center; font-size: 22px; font-weight: bold; border-radius: 4px; margin: 15px 0; }
                        .subject-box { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #1b4d3e; }
                        .note-box { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #f39c12; }
                        .footer { text-align: center; padding: 15px; font-size: 12px; color: #666; border-top: 1px solid #ddd; margin-top: 20px; }
                        .btn { display: inline-block; background: #1b4d3e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; }
                    </style></head><body>
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
                    </body></html>
                    ";
                    @sendEmail($semail, $sname, $subject_email, $email_message, true);
                }
            }

            $totalMentioned = count($mention_full_names);
            logActivity($conn, $office, 'office', "Posted advisory with mentions: $subject");

            $_SESSION['note_flash_success'] = "Advisory posted! " . $totalMentioned . " individual(s) mentioned.";
            $note_redirect = (isset($_SESSION['role']) && $_SESSION['role'] === 'registrar') ? '?section=note' : '?officesection=note';
            header("Location: " . $note_redirect);
            exit;
        } else {
            $_SESSION['note_flash_error'] = "Failed to post advisory: " . $conn->error;
            $note_redirect = (isset($_SESSION['role']) && $_SESSION['role'] === 'registrar') ? '?section=note' : '?officesection=note';
            header("Location: " . $note_redirect);
            exit;
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

    $_SESSION['note_flash_success'] = "Advisory deleted successfully!";
    $note_redirect = (isset($_SESSION['role']) && $_SESSION['role'] === 'registrar') ? '?section=note' : '?officesection=note';
    header("Location: " . $note_redirect);
    exit;
}

// ============================================
// GET ALL NOTES FOR THIS OFFICE
// ============================================
$has_sem_col = false;
$chk_sem_col = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
if ($chk_sem_col && $chk_sem_col->num_rows > 0) $has_sem_col = true;

$sem_filter = '';
if ($has_sem_col && $current_sem_id > 0) {
    $sem_filter = " AND f.semester_id = $current_sem_id ";
}

$notes = $conn->query("SELECT f.*, 
                        (SELECT COUNT(*) FROM chmsu_feed_views v WHERE v.feed_id = f.id) as view_count,
                        (SELECT GROUP_CONCAT(CONCAT(s.chmsu_full_name, ' (', DATE_FORMAT(v.viewed_at, '%m/%d/%Y %h:%i %p'), ')') SEPARATOR ', ') 
                         FROM chmsu_feed_views v 
                         JOIN chmsu_students_master s ON v.student_id = s.chmsu_student_id
                         WHERE v.feed_id = f.id ORDER BY v.viewed_at DESC) as viewers
                        FROM chmsu_feed f 
                        WHERE f.office_name='$office' $sem_filter
                        ORDER BY f.created_at DESC");
$totalPosts = $notes ? $notes->num_rows : 0;
$allNotes = [];
if ($notes && $notes->num_rows > 0) {
    while ($row = $notes->fetch_assoc()) $allNotes[] = $row;
}

// ============================================
// GET ALL STUDENTS FOR AUTOCOMPLETE
// ============================================
$students = $conn->query("SELECT s.chmsu_student_id, s.chmsu_full_name, s.chmsu_last_name, s.chmsu_first_name, s.chmsu_year, s.chmsu_section, s.chmsu_course, u.email 
                          FROM chmsu_students_master s
                          LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                          WHERE s.is_archived = 0 
                          ORDER BY s.chmsu_last_name ASC");
$allStudents = [];
if ($students && $students->num_rows > 0) {
    while ($s = $students->fetch_assoc()) $allStudents[] = $s;
}

// ============================================
// GET DISTINCT COURSES
// ============================================
$courses_result = $conn->query("SELECT DISTINCT UPPER(TRIM(chmsu_course)) as course_code 
                                FROM chmsu_students_master 
                                WHERE is_archived = 0 AND chmsu_course IS NOT NULL AND chmsu_course != ''
                                ORDER BY course_code ASC");
$allCourses = [];
if ($courses_result && $courses_result->num_rows > 0) {
    while ($c = $courses_result->fetch_assoc()) $allCourses[] = $c['course_code'];
}

// ============================================
// GET DISTINCT SECTIONS
// ============================================
$sections_result = $conn->query("SELECT DISTINCT UPPER(TRIM(chmsu_course)) as course_code, chmsu_year, UPPER(TRIM(chmsu_section)) as section_name 
                                 FROM chmsu_students_master 
                                 WHERE is_archived = 0
                                 ORDER BY course_code ASC, CAST(chmsu_year AS UNSIGNED) ASC, section_name ASC");
$allSections = [];
if ($sections_result && $sections_result->num_rows > 0) {
    while ($sec = $sections_result->fetch_assoc()) $allSections[] = $sec;
}

$years = [1, 2, 3, 4];

// Get chat unread count for sidebar badge
$chat_unread_count = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$office'")->fetch_assoc()['total'] ?: 0;

while (ob_get_level() > 0) { ob_end_flush(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - <?php echo strtoupper($office); ?> Advisories</title>

    <!-- Apply dark mode BEFORE paint -->
    <script>
        (function () {
            try {
                if (localStorage.getItem('darkMode') === 'true') {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>

    <!-- Lucide Icons & Tailwind (Unified Assets matching Dashboard) -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { colors: { brand: { DEFAULT: '#1b4d3e', 600: '#2d6a4f', 50: '#eef6f2' }, gold: '#f1c40f' } } }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- SHARED SHELL STYLES (Identical to Dashboard) -->
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; background: #f5f5f5; font-size: 14px; }

        .header { background: #1b4d3e; color: white; padding: 15px 25px; display: flex; align-items: center; gap: 20px; position: sticky; top: 0; z-index: 1000; }
        .header-logo img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 20px; font-weight: normal; }
        .header-title p { font-size: 11px; opacity: 0.8; margin-top: 3px; }
        .dark-mode-toggle { background: transparent; border: 1px solid rgba(255,255,255,0.3); color: white; padding: 6px 12px; cursor: pointer; margin-left: auto; display: flex; align-items: center; gap: 6px; border-radius: 4px; font-family: 'Times New Roman', Times, serif; }
        .dark-mode-toggle:hover { background: rgba(255,255,255,0.15); }
        .logout-btn { background: #e74c3c; color: white; padding: 6px 15px; border: none; cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 6px; font-family: 'Times New Roman', Times, serif; }
        .logout-btn:hover { background: #c0392b; }

        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }

        /* SIDEBAR (Identical to Dashboard) */
        .office-sidebar { width: 260px; background: #1b4d3e; color: white; position: fixed; height: calc(100vh - 73px); overflow-y: auto; overflow-x: hidden; z-index: 1001; }
        .office-sidebar::-webkit-scrollbar { width: 5px; }
        .office-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .office-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .office-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .office-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        .office-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; padding-bottom: 20px; }
        .office-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-menu a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 13px; transition: all 0.3s; position: relative; pointer-events: auto; cursor: pointer; }
        .office-sidebar .sidebar-menu a:hover, .office-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        .office-sidebar .sidebar-menu a .chat-badge { background: #e74c3c; color: white; border-radius: 12px; padding: 2px 8px; font-size: 10px; min-width: 20px; text-align: center; float: right; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }

        .main-content { flex: 1; margin-left: 260px; padding: 20px; background: #f5f5f5; min-height: calc(100vh - 73px); }
        .dashboard-header-bar { background: white; padding: 15px 20px; border: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }

        /* Feed Styles */
        .post-content { max-height: 420px; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; scrollbar-color: #1b4d3e #e5e7eb; }
        .post-content.expanded { max-height: none; }
        .post-content::-webkit-scrollbar { width: 8px; }
                .post-content::-webkit-scrollbar-track { background: #e5e7eb; border-radius: 4px; }
        .post-content::-webkit-scrollbar-thumb { background: #1b4d3e; border-radius: 4px; }
        body.dark-mode .post-content { scrollbar-color: #f1c40f #1a1a1a; }
        body.dark-mode .post-content::-webkit-scrollbar-track { background: #1a1a1a; }
        body.dark-mode .post-content::-webkit-scrollbar-thumb { background: #f1c40f; }

        .section-separator-box { margin: 12px 0; padding: 12px 14px; background: #f0f7ff; border: 1px solid #d0e3f7; border-radius: 8px; }
        .section-separator-box .sec-label { font-weight: 700; color: #1b4d3e; font-size: 12px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; gap: 6px; }
        .section-separator-box .sec-names { display: flex; flex-wrap: wrap; gap: 6px; }
        .sec-name { display: inline-flex; align-items: center; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 3px 10px; font-size: 11.5px; color: #334155; line-height: 1.3; font-weight: 500; }
        .sec-name i { font-size: 10px; margin-right: 4px; color: #1b4d3e; }

        .course-separator-box { margin: 12px 0; padding: 12px 16px; background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 8px; }
        .course-separator-box .course-label { font-weight: 700; color: #7e22ce; font-size: 13px; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #e9d5ff; display: flex; align-items: center; gap: 6px; }
        .course-separator-box .course-subgroup { margin: 8px 0; padding: 8px 12px; background: #ffffff; border: 1px solid #f3e8ff; border-left: 3px solid #9333ea; border-radius: 6px; }
        .course-separator-box .course-sub-label { font-weight: 700; color: #6b21a8; font-size: 11px; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .3px; display: flex; align-items: center; justify-content: space-between; }
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

        /* Mention picker */
        .student-suggestions { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e5e7eb; border-top: none; max-height: 240px; overflow-y: auto; z-index: 9999; display: none; border-radius: 0 0 8px 8px; box-shadow: 0 8px 20px rgba(0,0,0,.12); }
        .student-suggestions.show { display: block; }
        .suggestion-section-title { font-size: 10px; font-weight: bold; color: #65676b; padding: 6px 12px; background: #f3f4f6; border-bottom: 1px solid #eee; }
        .suggestion-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; font-size: 12px; display: flex; align-items: center; gap: 8px; }
        .suggestion-item:hover { background: #f0f8ff; }
        .suggestion-id { color: #999; font-size: 10px; }
        .suggestion-icon { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #fff; flex-shrink: 0; }
        .suggestion-icon.student { background: #1b4d3e; }
        .suggestion-icon.section { background: #3498db; }
        .suggestion-icon.course { background: #8e44ad; }
        body.dark-mode .student-suggestions { background: #1a1a1a; border-color: #444; }
        body.dark-mode .suggestion-item { border-bottom-color: #333; color: #fff; }
        body.dark-mode .suggestion-item:hover { background: #2c2c2c; }
        body.dark-mode .suggestion-section-title { background: #0f0f0f; color: #aaa; border-color: #333; }

        .mention-tag-item { background: #1b4d3e; color: #fff; padding: 4px 10px; border-radius: 999px; font-size: 11px; display: inline-flex; align-items: center; gap: 6px; }
        .mention-tag-item.section-tag { background: #3498db; }
        .mention-tag-item.course-tag { background: #8e44ad; }
        .mention-tag-item .remove-mention { cursor: pointer; color: #ffb3ab; font-weight: bold; }
        .mention-tag-item .remove-mention:hover { color: #fff; background: #e74c3c; border-radius: 50%; padding: 0 4px; }

        .mode-btn.active { background: #1b4d3e; color: #fff; }
        .mode-btn.course-mode.active { background: #8e44ad; color: #fff; }
        body.dark-mode .mode-btn.active { background: #f1c40f; color: #000; }
        body.dark-mode .mode-btn.course-mode.active { background: #b07ad1; color: #fff; }

        /* Modal & Dark Mode Overrides */
        #noteModal { display: none; }
        #noteModal.open { display: flex; }
        body.modal-open { overflow: hidden; }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .note-post { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .bg-white { background-color: #1a1a1a !important; }
        body.dark-mode .bg-gray-50 { background-color: #222 !important; }
        body.dark-mode .bg-gray-100 { background-color: #2c2c2c !important; }
        body.dark-mode .text-gray-800, body.dark-mode .text-gray-700 { color: #eee !important; }
        body.dark-mode .border-gray-200, body.dark-mode .border-gray-300 { border-color: #333 !important; }
        body.dark-mode #noteModal > div { background: #1a1a1a; border-color: #333; }
        
        @media (max-width: 768px) {
            .office-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body class="font-serif text-gray-800 dark:text-gray-100">

<!-- ============ HEADER ============ -->
<div class="header">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | <?php echo strtoupper($office); ?> Portal - Feed Updates</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i data-lucide="moon" style="width:14px;height:14px;"></i> Dark Mode
    </button>
    <button onclick="confirmLogout()" class="logout-btn">
        <i data-lucide="log-out" style="width:14px;height:14px;"></i> Logout
    </button>
</div>

<div class="dashboard-wrapper">
    <!-- ============ SIDEBAR ============ -->
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
            <p>Clearance Management</p>
        </div>
        <ul class="sidebar-menu">
            <li>
                <a href="?officesection=dashboard">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="?officesection=note" class="active">
                    <i class="fas fa-rss"></i> Feed Updates
                </a>
            </li>
            <li>
                <a href="?officesection=clearance">
                    <i class="fas fa-clipboard-check"></i> Clearance
                </a>
            </li>
            <li>
                <a href="?officesection=reports">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </li>
            <li>
                <a href="?officesection=chat" class="chat-link">
                    <i class="fas fa-comment"></i> Messages
                    <?php if ($chat_unread_count > 0): ?>
                        <span class="chat-badge"><?php echo $chat_unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout(); return false;">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
        <div style="padding: 12px 20px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px; border-radius: 2px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f; border-radius: 2px;"></div>
            </div>
        </div>
    </div>

    <!-- ============ MAIN ============ -->
    <div class="main-content">
        <!-- Title bar -->
        <div class="dashboard-header-bar">
            <h2><?php echo htmlspecialchars($office); ?> - Feed Updates</h2>
            <div class="flex items-center gap-3">
                <span><?php echo date('F d, Y'); ?></span>
                <button type="button" onclick="openNoteModal()" class="bg-[#1b4d3e] hover:bg-[#2d6a4f] text-white text-xs font-bold px-4 py-2 rounded shadow-sm flex items-center gap-2 transition-colors" style="font-family: 'Times New Roman', Times, serif;">
                    <i class="fas fa-plus"></i> Add Notes
                </button>
            </div>
        </div>

        <!-- Active semester banner -->
        <div style="background: linear-gradient(135deg, #1b4d3e, #2d6a4f); color: white; padding: 14px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <i data-lucide="calendar-check" style="width:24px;height:24px;opacity:0.9;"></i>
                <div>
                    <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.75; font-weight: bold;">Currently Active</div>
                    <div style="font-size: 16px; font-weight: bold; margin-top: 2px;">
                        <?php echo htmlspecialchars($current_sy_label); ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($current_sem_name); ?>
                    </div>
                </div>
            </div>
            <div style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: bold; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="info" style="width:14px;height:14px;opacity:0.9;"></i>
                <span>Posts here are auto-archived to this semester when a new one is set.</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div style="background:#f8d7da; color:#721c24; padding:10px; margin-bottom:15px; border-left:3px solid #e74c3c;"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#d4edda; color:#155724; padding:10px; margin-bottom:15px; border-left:3px solid #27ae60;"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Feed List -->
        <div class="bg-white border border-gray-300 rounded-lg overflow-hidden mb-6">
            <div class="bg-gray-100 px-4 py-3 border-b border-gray-300 flex items-center justify-between">
                <span class="text-sm font-bold text-gray-800"><i class="fas fa-history text-[#1b4d3e]"></i> My Advisories — Current Semester</span>
                <span class="text-xs text-gray-600">
                    <span id="visibleCount"><?php echo $totalPosts; ?></span>/<?php echo $totalPosts; ?> posts
                </span>
            </div>

            <!-- Search -->
            <div class="px-4 py-3 border-b border-gray-300 bg-white">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    <input type="text" id="feedSearch" placeholder="Search subject or description..." class="w-full pl-8 pr-3 py-2 text-sm border border-gray-300 rounded focus:outline-none focus:border-[#1b4d3e]" style="font-family: 'Times New Roman', Times, serif;">
                </div>
            </div>

            <div class="p-4" id="feedList">
                <?php if (count($allNotes) > 0): ?>
                    <?php foreach ($allNotes as $post): 
                        $mentionedNames = array();
                        if (preg_match_all('/(?:👥|ðŸ‘¥)?\s*Mentioned:\s*(.*?)(?:\n|$)/u', $post['content'], $matches)) {
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

                        $postContentRaw = str_replace(["\r\n", "\r"], "\n", $post['content']);

                        $sectionBlocks = array();
                        if (preg_match('/=== SECTION MENTION ===(.*?)=== END SECTION ===/s', $postContentRaw, $smatch)) {
                            $inner = trim($smatch[1]);
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
                        if (preg_match('/=== COURSE MENTION ===(.*?)=== END COURSE ===/s', $postContentRaw, $cmatch)) {
                            $cInner = trim($cmatch[1]);
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
                            foreach ($courseBlocks as $cName => $subs) {
                                uksort($courseBlocks[$cName], function($a, $b) {
                                    preg_match('/^(\d+)/', $a, $ma);
                                    preg_match('/^(\d+)/', $b, $mb);
                                    $ya = isset($ma[1]) ? intval($ma[1]) : 0;
                                    $yb = isset($mb[1]) ? intval($mb[1]) : 0;
                                    if ($ya !== $yb) return $ya - $yb;
                                    return strcmp($a, $b);
                                });
                            }
                        }

                        uksort($sectionBlocks, function($a, $b) {
                            preg_match('/(\d+)/', $a, $ma);
                            preg_match('/(\d+)/', $b, $mb);
                            $ya = isset($ma[1]) ? intval($ma[1]) : 0;
                            $yb = isset($mb[1]) ? intval($mb[1]) : 0;
                            if ($ya !== $yb) return $ya - $yb;
                            return strcmp($a, $b);
                        });

                        $cleanContent = preg_replace('/[\r\n]*=== SECTION MENTION ===.*?=== END SECTION ===[\r\n]*/s', '', $postContentRaw);
                        $cleanContent = preg_replace('/[\r\n]*=== COURSE MENTION ===.*?=== END COURSE ===[\r\n]*/s', '', $cleanContent);
                        $cleanContent = preg_replace('/[\r\n]*(?:👥|ðŸ‘¥)?\s*Mentioned: .*/u', '', $cleanContent);
                        $cleanContent = preg_replace('/---(?:COURSE-)?SEP---/', '', $cleanContent);
                        $cleanContent = preg_replace('/===\s*(?:END\s+)?(?:COURSE|SECTION)\s*===/', '', $cleanContent);
                        $cleanContent = trim($cleanContent);

                        $isLong = strlen($cleanContent) > 500 || substr_count($cleanContent, "\n") > 10;
                        $searchBlob = strtolower($post['title'] . ' ' . $cleanContent);
                    ?>
                    <article class="note-post border border-gray-300 rounded bg-white mb-4 p-4 hover:shadow-md transition-shadow" data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES); ?>">
                        <div class="flex justify-between items-start gap-3 mb-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-[#1b4d3e] text-sm"><i class="fas fa-building mr-1"></i> <?php echo htmlspecialchars($post['office_name']); ?></span>
                                <?php if (!empty($mentionedNames)): ?>
                                    <span class="bg-[#f1c40f] text-black px-2 py-0.5 rounded-full text-[11px] font-bold"><i class="fas fa-at"></i> <?php echo count($mentionedNames); ?> individual</span>
                                <?php endif; ?>
                            </div>
                            <form method="POST" class="inline flex-shrink-0">
                                <input type="hidden" name="action" value="delete_note">
                                <input type="hidden" name="note_id" value="<?php echo $post['id']; ?>">
                                <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-2.5 py-1.5 text-[11px] rounded" onclick="return confirm('Delete this advisory?')"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>

                        <div class="text-lg font-bold text-[#1b4d3e] mb-2 px-3 py-2 bg-[#eef7f2] rounded border-l-4 border-[#1b4d3e] break-words">
                            <?php echo htmlspecialchars($post['title']); ?>
                        </div>

                        <div class="post-content text-sm text-gray-800 leading-relaxed mb-2 p-3 bg-gray-50 rounded break-words" id="postContent_<?php echo $post['id']; ?>">
                            <?php echo nl2br(htmlspecialchars($cleanContent)); ?>

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
                                            <span><i class="fas fa-users mr-1"></i><?php echo htmlspecialchars($label); ?></span>
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

                        <?php if ($isLong): ?>
                            <div class="block text-center py-1.5 bg-gray-100 hover:bg-gray-200 rounded cursor-pointer text-xs text-[#1b4d3e] mt-1" onclick="toggleContent(<?php echo $post['id']; ?>)">
                                <span id="seeMoreText_<?php echo $post['id']; ?>">See More</span>
                            </div>
                        <?php endif; ?>

                        <div class="text-[11px] text-gray-500 flex flex-wrap gap-x-4 gap-y-1 mt-2 pt-2 border-t border-gray-200">
                            <span><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y H:i', strtotime($post['created_at'])); ?></span>
                            <span><i class="fas fa-eye"></i> <?php echo $post['view_count']; ?> views</span>
                        </div>
                    </article>
                    <?php endforeach; ?>

                    <div id="feedNoMatch" class="hidden text-center py-10 text-gray-400">
                        <i class="fas fa-search text-4xl mb-2"></i>
                        <p>No advisories match your search.</p>
                    </div>
                <?php else: ?>
                    <div class="text-center py-10 text-gray-400">
                        <i class="fas fa-sticky-note text-5xl mb-3"></i>
                        <p>No advisories yet for the current semester.</p>
                        <p class="text-[11px] mt-1.5 text-gray-400">Posts made in the previous semester have been archived with it.</p>
                        <button type="button" onclick="openNoteModal()" class="mt-4 inline-flex items-center gap-2 bg-[#1b4d3e] text-white text-xs font-bold px-4 py-2 rounded hover:bg-[#2d6a4f]"><i class="fas fa-plus"></i> Add Notes</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================
     ADD NOTES MODAL
     ===================================================== -->
<div id="noteModal" class="fixed inset-0 z-[9999] items-start sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/60" onclick="closeNoteModal()"></div>

    <div class="relative bg-white w-full sm:max-w-3xl max-h-screen sm:max-h-[92vh] flex flex-col sm:rounded-xl shadow-2xl border border-gray-300">
        <div class="flex items-center justify-between px-4 sm:px-5 py-3 border-b border-gray-300 bg-gray-50 sm:rounded-t-xl">
            <div>
                <div class="text-sm font-bold text-[#1b4d3e]"><i class="fas fa-sticky-note"></i> Create New Advisory</div>
                <div class="text-[11px] text-gray-500">Choose a mention mode, then search</div>
            </div>
            <button type="button" onclick="closeNoteModal()" class="w-9 h-9 rounded-full hover:bg-gray-200 text-gray-500 text-xl leading-none" aria-label="Close">&times;</button>
        </div>

        <div class="overflow-y-auto px-4 sm:px-5 py-4">
            <form method="POST" id="noteForm">
                <input type="hidden" name="action" value="post_note">

                <div class="mb-4">
                    <label class="block mb-1 text-xs text-gray-700">Subject <span class="text-red-500">*</span></label>
                    <input type="text" name="subject" placeholder="Enter subject/title" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-base font-bold focus:outline-none focus:border-[#1b4d3e]" required>
                </div>

                <div class="mb-4">
                    <label class="block mb-1 text-xs text-gray-700">Description <span class="text-red-500">*</span></label>
                    <textarea name="content" id="postContent" placeholder="Write your advisory details here..." required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-[13px] min-h-[120px] focus:outline-none focus:border-[#1b4d3e]"></textarea>
                </div>

                <div class="mb-4">
                    <label class="block mb-1 text-xs text-gray-700"><i class="fas fa-users"></i> Mentions</label>
                    <div class="text-[11px] text-gray-500 mb-2">Pick a mode below. Course mode groups all students under their course → year → section automatically.</div>

                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <span class="text-xs text-gray-700">Mention Mode</span>
                        <div class="inline-flex bg-gray-100 border border-gray-300 rounded-full p-[3px] select-none" id="modeSegmented">
                            <button type="button" class="mode-btn active px-3.5 py-1.5 rounded-full text-[11px] font-bold text-gray-600 inline-flex items-center gap-1.5" data-mode="student" onclick="setMentionMode('student')"><i class="fas fa-user"></i> Students</button>
                            <button type="button" class="mode-btn px-3.5 py-1.5 rounded-full text-[11px] font-bold text-gray-600 inline-flex items-center gap-1.5" data-mode="section" onclick="setMentionMode('section')"><i class="fas fa-layer-group"></i> Sections</button>
                            <button type="button" class="mode-btn course-mode px-3.5 py-1.5 rounded-full text-[11px] font-bold text-gray-600 inline-flex items-center gap-1.5" data-mode="course" onclick="setMentionMode('course')"><i class="fas fa-graduation-cap"></i> Courses</button>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-2">
                        <select id="courseFilter" onchange="onCourseChange()" class="w-full sm:w-36 flex-shrink-0 px-2 py-2 text-xs border border-gray-300 rounded-lg bg-white">
                            <option value="">All Courses</option>
                            <?php foreach ($allCourses as $c): ?>
                                <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div class="student-scanner relative flex-1">
                            <input type="text" id="mentionSearch" placeholder="Type a student name..." autocomplete="off" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-[13px] bg-white focus:outline-none focus:border-[#1b4d3e]">
                            <div class="student-suggestions" id="mentionSuggestions"></div>
                        </div>
                    </div>

                    <small class="block mt-1.5 text-[10px] text-gray-500" id="modeHint"><strong>Students</strong> mode — search by student name, ID, or course.</small>

                    <div class="flex flex-wrap gap-1.5 mt-2 p-2 bg-gray-50 rounded-lg min-h-[40px] border border-dashed border-gray-300" id="mentionTagsContainer">
                        <span style="color: #999; font-size: 11px;" id="emptyMentionText">No mentions yet</span>
                    </div>
                    <input type="hidden" name="mentioned_students" id="mentionedStudents" value="">
                    <input type="hidden" name="mentioned_sections" id="mentionedSections" value="">
                    <input type="hidden" name="mentioned_courses" id="mentionedCourses" value="">
                </div>

                <button type="submit" class="w-full bg-[#1b4d3e] hover:bg-[#2d6a4f] text-white py-2.5 rounded-lg text-sm font-bold"><i class="fas fa-paper-plane"></i> Post &amp; Mention Students</button>
                <div class="mt-2.5 text-[11px] text-gray-600 bg-gray-50 px-3 py-2 rounded-lg"><i class="fas fa-info-circle"></i> All mentioned students will receive in-app notifications and Gmail alerts.</div>
            </form>
        </div>
    </div>
</div>

<script>
// ============================================
// DATA
// ============================================
var students = <?php echo json_encode($allStudents); ?>;
var sections = <?php echo json_encode($allSections); ?>;
var courses  = <?php echo json_encode($allCourses); ?>;

var selectedMentions = [];
var selectedSections = [];
var selectedCourses = [];
var mentionMode = 'student';

// ============================================
// UI: sidebar (mobile), modal
// ============================================
function toggleSidebar() {
    document.getElementById('appSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('hidden');
}

function openNoteModal() {
    document.getElementById('noteModal').classList.add('open');
    document.body.classList.add('modal-open');
    setTimeout(function () {
        var f = document.querySelector('#noteForm input[name="subject"]');
        if (f) f.focus();
    }, 50);
}
function closeNoteModal() {
    document.getElementById('noteModal').classList.remove('open');
    document.body.classList.remove('modal-open');
    document.getElementById('mentionSuggestions').classList.remove('show');
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeNoteModal();
});

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

function setMentionMode(mode) {
    mentionMode = mode;
    document.querySelectorAll('#modeSegmented .mode-btn').forEach(function(btn) {
                if (btn.dataset.mode === mode) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    var searchInput = document.getElementById('mentionSearch');
    var hint = document.getElementById('modeHint');

    if (mode === 'student') {
        searchInput.placeholder = 'Type a student name...';
        hint.innerHTML = '<strong>Students</strong> mode — search by student name, ID, or course.';
    } else if (mode === 'section') {
        searchInput.placeholder = 'Type a section letter (A, B, C...)...';
        hint.innerHTML = '<strong>Sections</strong> mode — pick a course, then type a section letter to mention whole sections across years.';
    } else if (mode === 'course') {
        searchInput.placeholder = 'Type a course code (e.g., BSIS)...';
        hint.innerHTML = '<strong>Courses</strong> mode — mention an entire course. All students under it will be grouped by year & section.';
    }

    searchInput.value = '';
    document.getElementById('mentionSuggestions').classList.remove('show');
    document.getElementById('mentionSuggestions').innerHTML = '';
}

function onCourseChange() {
    document.getElementById('mentionSearch').dispatchEvent(new Event('input'));
}

document.getElementById('mentionSearch').addEventListener('input', function() {
    var raw = this.value.trim();
    var search = raw.toLowerCase();
    var suggestions = document.getElementById('mentionSuggestions');
    var courseFilter = document.getElementById('courseFilter').value.toUpperCase();

    if (search.length < 1) {
        suggestions.classList.remove('show');
        return;
    }

    var html = '';

    if (mentionMode === 'student') {
        var matchedStudents = students.filter(function(s) {
            if (courseFilter && (s.chmsu_course || '').toUpperCase() !== courseFilter) return false;
            return selectedMentions.indexOf(s.chmsu_full_name) === -1 &&
                (s.chmsu_full_name.toLowerCase().indexOf(search) !== -1 ||
                 s.chmsu_last_name.toLowerCase().indexOf(search) !== -1 ||
                 s.chmsu_first_name.toLowerCase().indexOf(search) !== -1 ||
                 s.chmsu_student_id.indexOf(search) !== -1);
        });

        if (matchedStudents.length > 0) {
            html += '<div class="suggestion-section-title"><i class="fas fa-user"></i> Students</div>';
            for (var j = 0; j < Math.min(matchedStudents.length, 15); j++) {
                var s = matchedStudents[j];
                html += '<div class="suggestion-item" data-type="student" data-name="' + s.chmsu_full_name + '">' +
                    '<div class="suggestion-icon student"><i class="fas fa-user"></i></div>' +
                    '<div><strong>' + s.chmsu_full_name + '</strong> ' +
                    '<span class="suggestion-id">(' + s.chmsu_student_id + ')</span>' +
                    (s.email ? '<span style="color: #27ae60; font-size: 9px;"> 📧</span>' : '') +
                    '</div></div>';
            }
        } else {
            html = '<div class="suggestion-item" style="color: #999;">No students found</div>';
        }
    }
    else if (mentionMode === 'section') {
        var matchedSections = sections.filter(function(sec) {
            var yearStr = String(sec.chmsu_year).toLowerCase();
            var sectionStr = String(sec.section_name).toLowerCase();
            var courseStr = (sec.course_code || '').toLowerCase();
            var combined = yearStr + sectionStr;

            if (courseFilter && courseStr !== courseFilter.toLowerCase()) return false;

            var alreadyAdded = selectedSections.some(function(x) {
                return String(x.year) === String(sec.chmsu_year)
                    && x.section === sec.section_name
                    && (x.course || '') === (sec.course_code || '');
            });
            if (alreadyAdded) return false;

            return yearStr.indexOf(search) !== -1
                || sectionStr.indexOf(search) !== -1
                || combined.indexOf(search) !== -1
                || courseStr.indexOf(search) !== -1;
        });

        matchedSections.sort(function(a, b) {
            if ((a.course_code || '') !== (b.course_code || '')) return (a.course_code || '').localeCompare(b.course_code || '');
            if (String(a.chmsu_year) !== String(b.chmsu_year)) return parseInt(a.chmsu_year) - parseInt(b.chmsu_year);
            return a.section_name.localeCompare(b.section_name);
        });

        if (matchedSections.length > 0) {
            html += '<div class="suggestion-section-title"><i class="fas fa-layer-group"></i> Sections (Groups)</div>';
            for (var i = 0; i < Math.min(matchedSections.length, 20); i++) {
                var sec = matchedSections[i];
                var courseLabel = sec.course_code ? sec.course_code + ' - ' : '';
                var label = courseLabel + sec.chmsu_year + 'th Year - ' + sec.section_name;
                html += '<div class="suggestion-item" data-type="section" data-year="' + sec.chmsu_year + '" data-section="' + sec.section_name + '" data-course="' + (sec.course_code || '') + '">' +
                    '<div class="suggestion-icon section"><i class="fas fa-layer-group"></i></div>' +
                    '<div><strong>' + label + '</strong> <span class="suggestion-id">(mention whole section)</span></div>' +
                    '</div>';
            }
        } else {
            html = '<div class="suggestion-item" style="color: #999;">No sections found</div>';
        }
    }
    else if (mentionMode === 'course') {
        var matchedCourses = courses.filter(function(c) {
            var courseStr = String(c).toLowerCase();
            if (courseFilter && courseStr !== courseFilter.toLowerCase()) return false;
            var alreadyAdded = selectedCourses.some(function(x) { return x.course === c; });
            if (alreadyAdded) return false;
            return courseStr.indexOf(search) !== -1;
        });

        matchedCourses.sort();

        if (matchedCourses.length > 0) {
            html += '<div class="suggestion-section-title"><i class="fas fa-graduation-cap"></i> Courses</div>';
            for (var k = 0; k < Math.min(matchedCourses.length, 20); k++) {
                var c = matchedCourses[k];
                var countUnderCourse = students.filter(function(s) {
                    return (s.chmsu_course || '').toUpperCase() === c.toUpperCase();
                }).length;
                html += '<div class="suggestion-item" data-type="course" data-course="' + c + '">' +
                    '<div class="suggestion-icon course"><i class="fas fa-graduation-cap"></i></div>' +
                    '<div><strong>' + c + '</strong> ' +
                    '<span class="suggestion-id">(' + countUnderCourse + ' student' + (countUnderCourse === 1 ? '' : 's') + ' — whole course)</span></div>' +
                    '</div>';
            }
        } else {
            html = '<div class="suggestion-item" style="color: #999;">No courses found</div>';
        }
    }

    suggestions.innerHTML = html;
    suggestions.classList.add('show');

    suggestions.querySelectorAll('.suggestion-item').forEach(function(item) {
        item.addEventListener('click', function() {
            var type = this.dataset.type;
            if (type === 'section') {
                addSectionMention(this.dataset.year, this.dataset.section, this.dataset.course);
            } else if (type === 'student') {
                addMention(this.dataset.name);
            } else if (type === 'course') {
                addCourseMention(this.dataset.course);
            }
            document.getElementById('mentionSearch').value = '';
            suggestions.classList.remove('show');
        });
    });
});

function addMention(name) {
    if (selectedMentions.indexOf(name) !== -1) return;
    selectedMentions.push(name);
    updateMentionTags();
}

function removeMention(name) {
    selectedMentions = selectedMentions.filter(function(m) { return m !== name; });
    updateMentionTags();
}

function addSectionMention(year, section, course) {
    year = String(year);
    section = String(section).toUpperCase().trim();
    course = (course || '').toUpperCase().trim();

    var exists = selectedSections.some(function(x) {
        return String(x.year) === year && x.section === section && (x.course || '') === course;
    });
    if (exists) return;

    var matchCount = students.filter(function(s) {
        var studentSection = s.chmsu_section ? String(s.chmsu_section).trim().toUpperCase() : '';
        var studentCourse = (s.chmsu_course || '').toUpperCase();
        return String(s.chmsu_year) === year
            && studentSection === section
            && (!course || studentCourse === course);
    }).length;

    selectedSections.push({ year: year, section: section, course: course });
    updateMentionTags();

    if (matchCount === 0) {
        setTimeout(function() {
            alert('Note: No active students currently found in ' + (course ? course + ' - ' : '') + year + 'th Year - ' + section + '.');
        }, 100);
    }
}

function removeSection(year, section, course) {
    year = String(year);
    section = String(section).toUpperCase().trim();
    course = (course || '').toUpperCase().trim();
    selectedSections = selectedSections.filter(function(x) {
        return !(String(x.year) === year && x.section === section && (x.course || '') === course);
    });
    updateMentionTags();
}

function addCourseMention(course) {
    course = String(course).toUpperCase().trim();
    if (course === '') return;

    var exists = selectedCourses.some(function(x) { return x.course === course; });
    if (exists) return;

    var matchCount = students.filter(function(s) {
        return (s.chmsu_course || '').toUpperCase() === course;
    }).length;

    selectedCourses.push({ course: course });
    updateMentionTags();

    if (matchCount === 0) {
        setTimeout(function() {
            alert('Note: No active students currently found in course ' + course + '.');
        }, 100);
    }
}

function removeCourse(course) {
    course = String(course).toUpperCase().trim();
    selectedCourses = selectedCourses.filter(function(x) { return x.course !== course; });
    updateMentionTags();
}

function updateMentionTags() {
    var container = document.getElementById('mentionTagsContainer');
    var hiddenStudents = document.getElementById('mentionedStudents');
    var hiddenSections = document.getElementById('mentionedSections');
    var hiddenCourses = document.getElementById('mentionedCourses');

    container.innerHTML = '';

    if (selectedMentions.length === 0 && selectedSections.length === 0 && selectedCourses.length === 0) {
        container.innerHTML = '<span style="color: #999; font-size: 11px;">No mentions yet</span>';
        hiddenStudents.value = '';
        hiddenSections.value = '';
        hiddenCourses.value = '';
        return;
    }

    for (var c = 0; c < selectedCourses.length; c++) {
        var cr = selectedCourses[c];
        var cTag = document.createElement('span');
        cTag.className = 'mention-tag-item course-tag';
        cTag.innerHTML = '<i class="fas fa-graduation-cap"></i> ' + cr.course +
            '<span class="remove-mention" onclick="removeCourse(\'' + cr.course + '\')">&times;</span>';
        container.appendChild(cTag);
    }

    for (var i = 0; i < selectedSections.length; i++) {
        var sec = selectedSections[i];
        var label = (sec.course ? sec.course + ' - ' : '') + sec.year + 'th Year - ' + sec.section;
        var tag = document.createElement('span');
        tag.className = 'mention-tag-item section-tag';
        tag.innerHTML = '<i class="fas fa-layer-group"></i> ' + label +
            '<span class="remove-mention" onclick="removeSection(\'' + sec.year + '\', \'' + sec.section + '\', \'' + sec.course + '\')">&times;</span>';
        container.appendChild(tag);
    }

    for (var j = 0; j < selectedMentions.length; j++) {
        var name = selectedMentions[j];
        var tag2 = document.createElement('span');
        tag2.className = 'mention-tag-item';
        tag2.innerHTML = '<i class="fas fa-at"></i> ' + name +
            '<span class="remove-mention" onclick="removeMention(\'' + name.replace(/'/g, "\\'") + '\')">&times;</span>';
        container.appendChild(tag2);
    }

    hiddenStudents.value = selectedMentions.join('|||');
    hiddenSections.value = JSON.stringify(selectedSections);
    hiddenCourses.value = JSON.stringify(selectedCourses);
}

document.addEventListener('click', function(e) {
    var container = document.querySelector('.student-scanner');
    if (container && !container.contains(e.target)) {
        var cs = document.getElementById('courseFilter');
        if (!cs.contains(e.target)) {
            document.getElementById('mentionSuggestions').classList.remove('show');
        }
    }
});

document.getElementById('noteForm').addEventListener('submit', function(e) {
    var selected = document.getElementById('mentionedStudents').value;
    var sections = document.getElementById('mentionedSections').value;
    var courses = document.getElementById('mentionedCourses').value;

    var hasStudents = selected && selected.trim() !== '';
    var hasSections = sections && sections !== '[]' && sections.trim() !== '';
    var hasCourses = courses && courses !== '[]' && courses.trim() !== '';

    if (!hasStudents && !hasSections && !hasCourses) {
        e.preventDefault();
        alert('Please mention at least one student, section, or course.');
        return false;
    }

    return true;
});

// ============================================
// DARK MODE
// ============================================
function syncDarkButton() {
    var isDark = document.documentElement.classList.contains('dark');
    var btn = document.querySelector('.dark-mode-toggle');
    if (btn) {
        btn.innerHTML = isDark ? '<i data-lucide="sun" style="width:14px;height:14px;"></i> Light Mode' : '<i data-lucide="moon" style="width:14px;height:14px;"></i> Dark Mode';
        lucide.createIcons();
    }
    document.body.classList.toggle('dark-mode', isDark);
}

function toggleDarkMode() {
    document.documentElement.classList.toggle('dark');
    var isDarkMode = document.documentElement.classList.contains('dark');
    localStorage.setItem('darkMode', isDarkMode);
    syncDarkButton();
}

syncDarkButton();
lucide.createIcons();

function confirmLogout() {
    if(confirm('Are you sure you want to logout?')) {
        window.location.href = '?logout=1';
    }
}

// ============================================
// UI ONLY: search filter for the feed
// ============================================
function applyFeedSearch() {
    var q = (document.getElementById('feedSearch').value || '').toLowerCase().trim();
    var shown = 0;

    document.querySelectorAll('#feedList .note-post').forEach(function (el) {
        var show = !q || (el.dataset.search || '').indexOf(q) !== -1;
        el.style.display = show ? '' : 'none';
        if (show) shown++;
    });

    var vc = document.getElementById('visibleCount');
    if (vc) vc.textContent = shown;
    var nm = document.getElementById('feedNoMatch');
    if (nm) nm.classList.toggle('hidden', shown !== 0);
}
document.getElementById('feedSearch').addEventListener('input', applyFeedSearch);
</script>

</body>
</html>