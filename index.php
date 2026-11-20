<?php
// ============================================
// FIX FOR WINDOWS SESSION PERMISSION ERROR
// ============================================
if (!file_exists('C:/xampp/tmp/sessions')) {
    @mkdir('C:/xampp/tmp/sessions', 0777, true);
}
@session_save_path('C:/xampp/tmp/sessions');

require_once __DIR__ . '/includes/load_env.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// ============================================
// ADMIN DELETE OTP HANDLERS
// ============================================
if (isset($_POST['action']) && isset($_SESSION['admin']) && in_array($_POST['action'], ['send_delete_otp', 'resend_delete_otp', 'verify_delete_otp'])) {

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    require_once __DIR__ . '/includes/otp.php';

    $admin = $_SESSION['admin'];

    $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='" . $conn->real_escape_string($admin) . "'")->fetch_assoc();
    $email = ($admin_data && !empty($admin_data['email'])) ? $admin_data['email'] : '';

    if (!$email) {
        echo json_encode(['success' => false, 'message' => 'Gmail not connected. Please connect Gmail from the dashboard first.']);
        exit;
    }

    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;

    if ($_POST['action'] === 'send_delete_otp') {
        $otp_code = generateOTP();
        saveOTP($conn, $email, $otp_code, 'delete', $item_id);
        sendOTPEmail($email, $otp_code, 'delete');
        echo json_encode(['success' => true, 'message' => 'OTP sent to your Gmail']);
        exit;
    }

    if ($_POST['action'] === 'resend_delete_otp') {
        $otp_code = generateOTP();
        saveOTP($conn, $email, $otp_code, 'delete', $item_id);
        sendOTPEmail($email, $otp_code, 'delete');
        echo json_encode(['success' => true, 'message' => 'OTP resent']);
        exit;
    }

    if ($_POST['action'] === 'verify_delete_otp') {
        $otp_code = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';
        $table    = isset($_POST['table'])    ? sanitize($_POST['table']) : '';

        $result = verifyOTP($conn, $email, $otp_code, 'delete');

        if (!$result || empty($result['valid'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP']);
            exit;
        }

        $allowed_tables = [
            'offices'      => ['table' => 'chmsu_offices',         'column' => 'id'],
            'courses'      => ['table' => 'chmsu_courses',         'column' => 'id'],
            'sections'     => ['table' => 'chmsu_course_sections', 'column' => 'id'],
            'school_years' => ['table' => 'chmsu_school_years',    'column' => 'id'],
            'semesters'    => ['table' => 'chmsu_semesters',       'column' => 'id'],
        ];

        if (!isset($allowed_tables[$table])) {
            echo json_encode(['success' => false, 'message' => 'Invalid table']);
            exit;
        }

        $real_table = $allowed_tables[$table]['table'];
        $id_column  = $allowed_tables[$table]['column'];

        $exists = $conn->query("SELECT $id_column FROM $real_table WHERE $id_column = $item_id");
        if (!$exists || $exists->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Item not found (maybe already deleted).']);
            exit;
        }

        if ($conn->query("DELETE FROM $real_table WHERE $id_column = $item_id")) {
            logActivity($conn, $admin, 'admin', "Deleted $table ID: $item_id (OTP verified)");
            echo json_encode(['success' => true, 'message' => 'Item deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $conn->error]);
        }
        exit;
    }
}

// ============================================
// ADMIN SEMESTER OTP HANDLERS
// ============================================
if (isset($_POST['action']) && isset($_SESSION['admin']) && in_array($_POST['action'], ['send_semester_otp', 'resend_semester_otp', 'verify_semester_action'])) {

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    require_once __DIR__ . '/includes/otp.php';
    require_once __DIR__ . '/includes/semester_archive.php';

    $admin = $_SESSION['admin'];

    $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='" . $conn->real_escape_string($admin) . "'")->fetch_assoc();
    $email = ($admin_data && !empty($admin_data['email'])) ? $admin_data['email'] : '';

    if (!$email) {
        echo json_encode(['success' => false, 'message' => 'Gmail not connected. Please connect Gmail from the dashboard first.']);
        exit;
    }

    if ($_POST['action'] === 'send_semester_otp') {
        $otp_type  = isset($_POST['otp_type'])  ? sanitize($_POST['otp_type']) : 'advance';
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id'])   : 0;

        if (!in_array($otp_type, ['advance', 'rollback'])) {
            $otp_type = 'advance';
        }

        if ($otp_type === 'rollback') {
            if ($target_id > 0 && !sa_isMostRecentArchived($conn, $target_id)) {
                echo json_encode(['success' => false, 'message' => 'Only the most recently archived semester can be restored.']);
                exit;
            }
        }

        if ($otp_type === 'advance') {
            $cur = sa_getCurrentSemester($conn);
            if ($cur && intval($cur['id']) === $target_id) {
                echo json_encode(['success' => false, 'message' => 'That semester is already the current one.']);
                exit;
            }
        }

        $otp_code = generateOTP();
        saveOTP($conn, $email, $otp_code, $otp_type, $target_id);
        sendOTPEmail($email, $otp_code, $otp_type);

        echo json_encode(['success' => true, 'message' => 'OTP sent to your Gmail']);
        exit;
    }

    if ($_POST['action'] === 'resend_semester_otp') {
        $otp_type  = isset($_POST['otp_type'])  ? sanitize($_POST['otp_type']) : 'advance';
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id'])   : 0;

        if (!in_array($otp_type, ['advance', 'rollback'])) {
            $otp_type = 'advance';
        }

        $otp_code = generateOTP();
        saveOTP($conn, $email, $otp_code, $otp_type, $target_id);
        sendOTPEmail($email, $otp_code, $otp_type);

        echo json_encode(['success' => true, 'message' => 'OTP resent']);
        exit;
    }

    if ($_POST['action'] === 'verify_semester_action') {
        $otp_code        = isset($_POST['otp_code'])            ? trim($_POST['otp_code'])             : '';
        $semester_action = isset($_POST['semester_action'])     ? sanitize($_POST['semester_action'])  : 'advance';
        $target_id       = isset($_POST['target_semester_id'])  ? intval($_POST['target_semester_id']) : 0;

        if (!in_array($semester_action, ['advance', 'rollback'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
        }

        $result = verifyOTP($conn, $email, $otp_code, $semester_action);

        if (!$result || empty($result['valid'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP']);
            exit;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($semester_action === 'advance') {
            $op = sa_advanceSemester($conn, $target_id, $admin, $ip);
            if ($op['ok']) {
                logActivity($conn, $admin, 'admin', "Set semester as current: ID: {$op['to_id']} (OTP verified)");
                echo json_encode(['success' => true, 'message' => $op['message']]);
            } else {
                echo json_encode(['success' => false, 'message' => $op['message']]);
            }
            exit;
        }

        if ($semester_action === 'rollback') {
            $op = sa_rollbackSemester($conn, $target_id, $admin, $ip);
            if ($op['ok']) {
                logActivity($conn, $admin, 'admin', "Undo archive: semester ID: $target_id reactivated (OTP verified)");
                echo json_encode(['success' => true, 'message' => $op['message']]);
            } else {
                echo json_encode(['success' => false, 'message' => $op['message']]);
            }
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
    }
}

// ============================================
// NEW: OFFICE CHANGE PASSWORD — AJAX HANDLER
// ============================================
if (isset($_GET['ajax_change_password']) && $_GET['ajax_change_password'] == 1 && isset($_SESSION['office'])) {
    include 'modules/office/change_password.php';
    exit;
}

// ============================================
// BLOCK STUDENT LOGIN IF PASSWORD RESET IS PENDING
// ============================================
$GLOBALS['__force_password_reset'] = '';
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['check_pending_reset'])
    && !empty($_POST['username'])
) {
    $__pending_sid = sanitize($_POST['username']);
    if ($__pending_sid !== '') {
        $__pending_sid_esc = $conn->real_escape_string($__pending_sid);
        $__pending_row = $conn->query("SELECT id FROM chmsu_password_resets
                                       WHERE student_id = '$__pending_sid_esc' AND is_used = 0
                                       ORDER BY id DESC LIMIT 1")->fetch_assoc();
        if ($__pending_row) {
            // Do not let handleActions log them in — remove the action marker
            unset($_POST['action']);
            unset($_POST['login']);
            $GLOBALS['__force_password_reset'] = $__pending_sid;
        }
    }
}

// Handle actions
$error = '';
$success = '';
handleActions($conn, $error, $success);

// ============================================
// MASTERLIST FORM HANDLERS (Must be before header!)
// ============================================
if (isset($_SESSION['office']) && $_SESSION['office'] == 'Registrar' && isset($_GET['section']) && $_GET['section'] == 'masterlist') {
    
    $office = $_SESSION['office'];

    // 1. Delete Single Student
    if (isset($_POST['delete_single']) && isset($_POST['student_id'])) {
        $student_id = sanitize($_POST['student_id']);
        $check = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'");
        if ($check->num_rows > 0) {
            $_SESSION['masterlist_error'] = "Cannot delete student with existing account. Remove account first.";
        } else {
            $conn->query("DELETE FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
            logActivity($conn, $office, 'registrar', "Deleted student: $student_id");
            $_SESSION['masterlist_success'] = "Student deleted successfully!";
        }
        header("Location: ?section=masterlist");
        exit;
    }

    // 2. Bulk Delete
    if (isset($_POST['bulk_delete']) && isset($_POST['selected_students'])) {
        $selected = $_POST['selected_students'];
        $deleted = 0; $errors = 0;
        foreach ($selected as $student_id) {
            $student_id = sanitize($student_id);
            $check = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'");
            if ($check->num_rows > 0) {
                $errors++;
            } else {
                $conn->query("DELETE FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
                $deleted++;
            }
        }
        if ($deleted > 0) {
            logActivity($conn, $office, 'registrar', "Bulk deleted $deleted students");
            $_SESSION['masterlist_success'] = "$deleted students deleted successfully!";
        }
        if ($errors > 0) {
            $_SESSION['masterlist_error'] = "$errors students have active accounts and cannot be deleted.";
        }
        header("Location: ?section=masterlist");
        exit;
    }

    // 3. Edit Student
    if (isset($_POST['edit_student'])) {
        $original_id = sanitize($_POST['original_id']);
        $last = sanitize($_POST['last_name']);
        $first = sanitize($_POST['first_name']);
        $middle = sanitize($_POST['middle_name']);
        $birth = $_POST['birthdate'];
        $course = sanitize($_POST['course']);
        $year = sanitize($_POST['year']);
        $section = sanitize($_POST['section']);
        $is_irregular = isset($_POST['is_irregular']) ? 1 : 0;
        $fullName = "$last, $first, $middle";

        $conn->query("UPDATE chmsu_students_master SET 
                      chmsu_last_name='$last', 
                      chmsu_first_name='$first', 
                      chmsu_middle_name='$middle',
                      chmsu_full_name='$fullName',
                      chmsu_birthdate='$birth',
                      chmsu_course='$course',
                      chmsu_year='$year',
                      chmsu_section='$section',
                      is_irregular='$is_irregular'
                      WHERE chmsu_student_id='$original_id'");
        logActivity($conn, $office, 'registrar', "Edited student: $original_id");
        $_SESSION['masterlist_success'] = "Student updated successfully!";
        header("Location: ?section=masterlist");
        exit;
    }

    // 4. Add Irregular Student
    if (isset($_POST['action']) && $_POST['action'] == 'add_irregular') {
        $student_id = sanitize($_POST['student_id']);
        $masterCheck = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
        if ($masterCheck->num_rows == 0) {
            $_SESSION['masterlist_error'] = "Student ID not found in Master List!";
        } else {
            $student = $masterCheck->fetch_assoc();
            if ($student['is_irregular'] == 1) {
                $_SESSION['masterlist_error'] = "Student is already marked as Irregular!";
            } elseif ($student['is_archived'] == 1) {
                $_SESSION['masterlist_error'] = "Student is already archived!";
            } else {
                $conn->query("UPDATE chmsu_students_master SET is_irregular = 1 WHERE chmsu_student_id='$student_id'");
                logActivity($conn, $office, 'registrar', "Marked student as Irregular: $student_id");
                $_SESSION['masterlist_success'] = "Student marked as Irregular successfully!";
            }
        }
        header("Location: ?section=masterlist");
        exit;
    }

    // 5. AJAX Endpoint: Get Student Info
    if (isset($_GET['get_student_info']) && isset($_GET['student_id'])) {
        header('Content-Type: application/json');
        $student_id = sanitize($_GET['student_id']);
        $student = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
        if ($student->num_rows > 0) {
            $data = $student->fetch_assoc();
            echo json_encode([
                'success' => true,
                'full_name' => $data['chmsu_full_name'],
                'birthdate' => date('F d, Y', strtotime($data['chmsu_birthdate'])),
                'course' => $data['chmsu_course'],
                'year' => $data['chmsu_year'],
                'section' => $data['chmsu_section']
            ]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    // 6. AJAX Endpoint: Get Filter Sections
    if (isset($_GET['get_filter_sections'])) {
        header('Content-Type: application/json');
        $course = sanitize($_GET['course']);
        $year = isset($_GET['year']) ? sanitize($_GET['year']) : '';

        if (!empty($year)) {
            $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections WHERE course='$course' AND year='$year' ORDER BY section_name");
        } else {
            $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections WHERE course='$course' ORDER BY section_name");
        }

        $result = [];
        while ($s = $sections->fetch_assoc()) $result[] = $s;
        echo json_encode($result);
        exit;
    }

    // 7. AJAX Endpoint: Get Sections for Add
    if (isset($_GET['get_sections_for_add'])) {
        header('Content-Type: application/json');
        $course = sanitize($_GET['course']);
        $year = sanitize($_GET['year']);

        $sections = $conn->query("SELECT section_name FROM chmsu_course_sections WHERE course='$course' AND year='$year' ORDER BY section_name");
        $result = [];
        while ($s = $sections->fetch_assoc()) $result[] = $s;
        echo json_encode($result);
        exit;
    }
}
// ============================================
// END MASTERLIST FORM HANDLERS
// ============================================

// ============================================
// OFFICE FEED NOTE HANDLERS
// ============================================
if (isset($_SESSION['office']) && isset($_POST['action']) && in_array($_POST['action'], ['post_note', 'delete_note'])) {

    @set_time_limit(300);                     // more headroom for bulk emails
    @ini_set('max_execution_time', 300);

    require_once __DIR__ . '/includes/email.php';

    $office = $_SESSION['office'];

    // --- Detect current semester + school year ---
    $current_sem_row = $conn->query("SELECT * FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
    $current_sem_id  = $current_sem_row ? intval($current_sem_row['id']) : 0;

    // --- Ensure chmsu_feed has needed columns ---
    $feedChk1 = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
    if ($feedChk1 && $feedChk1->num_rows == 0) {
        $conn->query("ALTER TABLE chmsu_feed ADD COLUMN semester_id INT(11) DEFAULT NULL");
    }
    $feedChk2 = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'school_year_id'");
    if ($feedChk2 && $feedChk2->num_rows == 0) {
        $conn->query("ALTER TABLE chmsu_feed ADD COLUMN school_year_id INT(11) DEFAULT NULL AFTER semester_id");
    }

    // Ensure hidden_posts exists (used by delete)
    $conn->query("CREATE TABLE IF NOT EXISTS chmsu_hidden_posts (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        post_id INT(11) NOT NULL,
        hidden_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_hidden (student_id, post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ============================================
    // DELETE NOTE
    // ============================================
    if ($_POST['action'] === 'delete_note') {
        $note_id = intval($_POST['note_id']);
        $office_esc = $conn->real_escape_string($office);

        $conn->query("DELETE FROM chmsu_feed WHERE id = $note_id AND office_name = '$office_esc'");
        $conn->query("DELETE FROM chmsu_notifications WHERE feed_id = $note_id");
        $conn->query("DELETE FROM chmsu_feed_views WHERE feed_id = $note_id");
        $conn->query("DELETE FROM chmsu_hidden_posts WHERE post_id = $note_id");

        $_SESSION['note_flash_success'] = "Advisory deleted successfully!";
        header("Location: ?officesection=note");
        exit;
    }

    // ============================================
    // POST NOTE
    // ============================================
    if ($_POST['action'] === 'post_note') {
        $subject = sanitize($_POST['subject']);
        $content = sanitize($_POST['content']);
        $mentioned_names_raw    = isset($_POST['mentioned_students']) ? $_POST['mentioned_students'] : '';
        $mentioned_sections_raw = isset($_POST['mentioned_sections']) ? $_POST['mentioned_sections'] : '';
        $mentioned_courses_raw  = isset($_POST['mentioned_courses'])  ? $_POST['mentioned_courses']  : '';

        // --- Parse mentioned sections (JSON) ---
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

        // --- Parse mentioned courses (JSON) ---
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

        // --- Get students by section ---
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

        // --- Get students by course ---
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

        // --- Group IDs ---
        $section_student_ids = array();
        foreach ($section_students as $ss) $section_student_ids[] = $ss['chmsu_student_id'];
        $course_student_ids = array();
        foreach ($course_students as $cs) $course_student_ids[] = $cs['chmsu_student_id'];
        $group_student_ids = array_values(array_unique(array_merge($section_student_ids, $course_student_ids)));

        // --- Individual mentions ---
        $mentioned_names = array();
        if (!empty($mentioned_names_raw)) {
            if (strpos($mentioned_names_raw, '|||') !== false) {
                $mentioned_names = array_map('trim', explode('|||', $mentioned_names_raw));
            } else {
                $mentioned_names = array_filter(array_map('trim', explode(',', $mentioned_names_raw)));
            }
        }
        $mentioned_names = array_values(array_unique($mentioned_names));

        $mention_ids = array();
        $mention_full_names = array();

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
                if (in_array($s['chmsu_student_id'], $group_student_ids)) continue;
                $mention_ids[] = $s['chmsu_student_id'];
                $mention_full_names[] = $s['chmsu_full_name'];
            }
        }

        sort($mention_full_names, SORT_NATURAL | SORT_FLAG_CASE);
        $mention_full_names = array_values(array_unique($mention_full_names));

        $all_notify_ids = array_values(array_unique(array_merge($mention_ids, $group_student_ids)));

        // --- Validation ---
        if (empty($all_notify_ids) && empty($mentioned_sections) && empty($mentioned_courses)) {
            $_SESSION['note_flash_error'] = "Please mention at least one student, section, or course.";
            header("Location: ?officesection=note");
            exit;
        }

        $primary_student_id = !empty($all_notify_ids) ? $all_notify_ids[0] : '';

        // --- SECTION BLOCK ---
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

        // --- COURSE BLOCK ---
        $course_block = '';
        if (!empty($mentioned_courses)) {
            $grouped_by_course = array();
            foreach ($mentioned_courses as $cr) {
                $grouped_by_course[$cr['course']] = array();
            }
            foreach ($course_students as $s) {
                $sc = strtoupper(trim($s['chmsu_course']));
                if (!isset($grouped_by_course[$sc])) continue;
                $yearNumeric = intval($s['chmsu_year']);
                $subKey = $yearNumeric . 'th Year - Section ' . strtoupper(trim($s['chmsu_section']));
                if (!isset($grouped_by_course[$sc][$subKey])) {
                    $grouped_by_course[$sc][$subKey] = array();
                }
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

        // --- Full content ---
        $all_mentions = implode('|||', $mention_full_names);
        $full_content = $content;
        if (!empty($all_mentions)) $full_content .= "\n\n👥 Mentioned: " . $all_mentions;
        $full_content .= $section_block;
        $full_content .= $course_block;

        $section_text = '';
        if (!empty($mentioned_sections)) {
            $sl = array();
            foreach ($mentioned_sections as $sec) {
                $sl[] = ($sec['course'] !== '' ? $sec['course'] . ' - ' : '') . $sec['year'] . "th Year - " . $sec['section'];
            }
            $section_text .= " (Sections: " . implode(', ', $sl) . ")";
        }
        if (!empty($mentioned_courses)) {
            $cl = array();
            foreach ($mentioned_courses as $cr) $cl[] = $cr['course'];
            $section_text .= " (Courses: " . implode(', ', $cl) . ")";
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

        if (!$insert) {
            $_SESSION['note_flash_error'] = "Failed to post advisory: " . $conn->error;
            header("Location: ?officesection=note");
            exit;
        }

        $feed_id = $conn->insert_id;

        $allStudents = $conn->query("SELECT s.chmsu_student_id, s.chmsu_full_name, u.email 
                                     FROM chmsu_students_master s
                                     LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                     WHERE s.is_archived = 0");

        $emailSentCount = 0;
        $maxEmailsPerPost = 500;

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

        $_SESSION['note_flash_success'] = "Advisory posted! " . $totalMentioned . " individual(s) mentioned. Emails sent to " . $emailSentCount . " recipient(s).";
        header("Location: ?officesection=note");
        exit;
    }
}

// ============================================
// GOOGLE OAUTH
// ============================================
if (isset($_GET['google_login']) && (isset($_SESSION['admin']) || isset($_SESSION['office']) || isset($_SESSION['student']))) {
    require_once 'includes/google_oauth.php';
    $loginUrl = getGoogleLoginUrl();
    header("Location: " . $loginUrl);
    exit;
}

if (isset($_GET['google_callback'])) {
    require_once 'includes/google_oauth.php';
    
    $userInfo = handleGoogleCallback($conn);
    
    if ($userInfo && isset($userInfo['email'])) {
        $email = $userInfo['email'];
        
        if (isset($_SESSION['admin'])) {
            $admin = $_SESSION['admin'];
            $conn->query("UPDATE chmsu_admin_users SET email='$email', otp_enabled=1 WHERE username='$admin'");
            logActivity($conn, $admin, 'admin', "Connected Gmail: $email");
            $success = "✅ Gmail connected successfully!";
            header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=dashboard&gmail_connected=1");
            exit;
        }
        elseif (isset($_SESSION['student'])) {
            $student_id = $_SESSION['student'];
            $conn->query("UPDATE chmsu_user_accounts SET email='$email' WHERE chmsu_student_id='$student_id'");
            logActivity($conn, $student_id, 'student', "Connected Gmail: $email");
            $success = "✅ Gmail connected successfully!";
            header("Location: " . $_SERVER['PHP_SELF'] . "?view=home&gmail_connected=1");
            exit;
        }
        elseif (isset($_SESSION['office'])) {
            $office = $_SESSION['office'];
            $conn->query("UPDATE chmsu_auth_roles SET email='$email', otp_enabled=1 WHERE chmsu_role='$office'");
            logActivity($conn, $office, 'office', "Connected Gmail: $email");
            $success = "✅ Gmail connected successfully!";
            
            if ($office == 'Registrar') {
                header("Location: " . $_SERVER['PHP_SELF'] . "?section=dashboard&gmail_connected=1");
            } else {
                header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=dashboard&gmail_connected=1");
            }
            exit;
        }
    } else {
        $error = "Failed to connect Gmail. Please try again.";
        if (isset($_SESSION['admin'])) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=dashboard");
        } elseif (isset($_SESSION['student'])) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?view=home");
        } elseif (isset($_SESSION['office'])) {
            $office = $_SESSION['office'];
            if ($office == 'Registrar') {
                header("Location: " . $_SERVER['PHP_SELF'] . "?section=dashboard");
            } else {
                header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=dashboard");
            }
        } else {
            header("Location: " . $_SERVER['PHP_SELF']);
        }
        exit;
    }
}

// Handle export requests for Registrar
if (isset($_GET['export']) && isset($_SESSION['office']) && $_SESSION['office'] == 'Registrar') {
    $type = $_GET['export'];
    $course = isset($_GET['course']) ? $_GET['course'] : '';
    $year = isset($_GET['year']) ? $_GET['year'] : '';
    $section = isset($_GET['section']) ? $_GET['section'] : '';
    
    $where = "";
    if ($course || $year || $section) {
        $conditions = [];
        if ($course) $conditions[] = "chmsu_course='$course'";
        if ($year) $conditions[] = "chmsu_year='$year'";
        if ($section) $conditions[] = "chmsu_section='$section'";
        $where = "WHERE " . implode(" AND ", $conditions);
    }
    
    $students = $conn->query("SELECT chmsu_student_id as 'Student ID', chmsu_last_name as 'Last Name', 
                                     chmsu_first_name as 'First Name', chmsu_middle_name as 'Middle Name',
                                     chmsu_course as 'Course', chmsu_year as 'Year', chmsu_section as 'Section',
                                     DATE_FORMAT(chmsu_birthdate, '%M %d, %Y') as 'Birthdate'
                              FROM chmsu_students_master 
                              $where 
                              ORDER BY chmsu_course, chmsu_year, chmsu_section, chmsu_last_name ASC");
    
    $data = [];
    while ($row = $students->fetch_assoc()) {
        $data[] = $row;
    }
    
    if ($type == 'excel') {
        exportToExcel($data, 'Student_List_' . date('Ymd'));
    } elseif ($type == 'word') {
        exportToWord($data, 'Student_List_' . date('Ymd'));
    }
}

if (isset($_GET['export_activity'])) {
    $type = $_GET['export_activity'];
    $logs = $conn->query("SELECT user_id as 'User', user_type as 'Type', action as 'Action', ip_address as 'IP Address', created_at as 'Date/Time' FROM chmsu_activity_log ORDER BY created_at DESC");
    $data = [];
    while ($row = $logs->fetch_assoc()) {
        $data[] = $row;
    }
    
    if ($type == 'excel') {
        exportToExcel($data, 'Activity_Log_' . date('Ymd'));
    } elseif ($type == 'word') {
        exportToWord($data, 'Activity_Log_' . date('Ymd'));
    }
}

if (isset($_GET['get_comments'])) {
    header('Content-Type: application/json');
    $req_id = intval($_GET['req_id']);
    $student_id = sanitize($_GET['student_id']);
    
    $student_info = $conn->query("SELECT chmsu_name, chmsu_course, chmsu_year, chmsu_section FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
    $student_display = $student_info['chmsu_name'] . " (" . $student_info['chmsu_course'] . $student_info['chmsu_year'] . $student_info['chmsu_section'] . ")";
    
    $comments = $conn->query("SELECT * FROM chmsu_private_comments 
                              WHERE requirement_id=$req_id AND student_id='$student_id' 
                              ORDER BY created_at ASC");
    
    $result = [];
    while ($c = $comments->fetch_assoc()) {
        $result[] = [
            'id' => $c['id'],
            'comment' => $c['comment'],
            'created_by' => $c['created_by'],
            'created_by_display' => ($c['created_by'] == 'student') ? $student_display : $_SESSION['office'],
            'is_read' => $c['is_read'],
            'date' => date('M d, Y H:i', strtotime($c['created_at']))
        ];
    }
    echo json_encode($result);
    exit;
}

if (isset($_GET['get_sections'])) {
    header('Content-Type: application/json');
    $course = sanitize($_GET['course']);
    $year = isset($_GET['year']) ? sanitize($_GET['year']) : '';
    
    if (empty($year)) {
        $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections WHERE course='$course' ORDER BY section_name");
    } else {
        $sections = $conn->query("SELECT section_name FROM chmsu_course_sections WHERE course='$course' AND year='$year' ORDER BY section_name");
    }
    
    $result = [];
    while ($s = $sections->fetch_assoc()) {
        $result[] = $s;
    }
    echo json_encode($result);
    exit;
}

if (isset($_GET['get_sections_for_add'])) {
    header('Content-Type: application/json');
    $course = sanitize($_GET['course']);
    $year = sanitize($_GET['year']);
    
    $sections = $conn->query("SELECT section_name FROM chmsu_course_sections WHERE course='$course' AND year='$year' ORDER BY section_name");
    $result = [];
    while ($s = $sections->fetch_assoc()) {
        $result[] = $s;
    }
    echo json_encode($result);
    exit;
}

if (isset($_GET['get_filter_sections'])) {
    header('Content-Type: application/json');
    $course = sanitize($_GET['course']);
    $year = isset($_GET['year']) ? sanitize($_GET['year']) : '';
    
    if (!empty($year)) {
        $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections WHERE course='$course' AND year='$year' ORDER BY section_name");
    } else {
        $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections WHERE course='$course' ORDER BY section_name");
    }
    
    $result = [];
    while ($s = $sections->fetch_assoc()) {
        $result[] = $s;
    }
    echo json_encode($result);
    exit;
}

// ============================================
// OTP AJAX HANDLERS
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['action']) && $_GET['action'] == 'check_otp_status' && (isset($_SESSION['admin']) || isset($_SESSION['office']) || isset($_SESSION['student']))) {
    header('Content-Type: application/json');
    
    if (isset($_SESSION['admin'])) {
        $admin = $_SESSION['admin'];
        $data = $conn->query("SELECT otp_enabled FROM chmsu_admin_users WHERE username='$admin'")->fetch_assoc();
        echo json_encode(['otp_enabled' => $data && $data['otp_enabled']]);
    } elseif (isset($_SESSION['office'])) {
        $office = $_SESSION['office'];
        $data = $conn->query("SELECT otp_enabled FROM chmsu_auth_roles WHERE chmsu_role='$office'")->fetch_assoc();
        echo json_encode(['otp_enabled' => $data && $data['otp_enabled']]);
    } elseif (isset($_SESSION['student'])) {
        echo json_encode(['otp_enabled' => false]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['action']) && $_GET['action'] == 'send_otp' && (isset($_SESSION['admin']) || isset($_SESSION['office']))) {
    header('Content-Type: application/json');
    require_once 'includes/otp.php';
    
    $action_type = isset($_GET['action_type']) ? $_GET['action_type'] : 'delete';
    $email = null;
    $user_type = '';
    
    if (isset($_SESSION['admin'])) {
        $admin = $_SESSION['admin'];
        $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='$admin'")->fetch_assoc();
        $email = $admin_data['email'];
        $user_type = 'admin';
    } elseif (isset($_SESSION['office'])) {
        $office = $_SESSION['office'];
        $office_data = $conn->query("SELECT email FROM chmsu_auth_roles WHERE chmsu_role='$office'")->fetch_assoc();
        $email = $office_data['email'];
        $user_type = 'office';
    }
    
    if (!$email) {
        echo json_encode(['success' => false, 'message' => 'Gmail not connected']);
        exit;
    }
    
    $otp_code = generateOTP();
    saveOTP($conn, $email, $otp_code, $action_type);
    sendOTPEmail($email, $otp_code, $action_type);
    
    echo json_encode(['success' => true, 'message' => 'OTP sent to your Gmail']);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['action']) && $_GET['action'] == 'verify_otp' && (isset($_SESSION['admin']) || isset($_SESSION['office']))) {
    header('Content-Type: application/json');
    require_once 'includes/otp.php';
    
    $otp = sanitize($_GET['otp']);
    $email = null;
    
    if (isset($_SESSION['admin'])) {
        $admin = $_SESSION['admin'];
        $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='$admin'")->fetch_assoc();
        $email = $admin_data['email'];
    } elseif (isset($_SESSION['office'])) {
        $office = $_SESSION['office'];
        $office_data = $conn->query("SELECT email FROM chmsu_auth_roles WHERE chmsu_role='$office'")->fetch_assoc();
        $email = $office_data['email'];
    }
    
    if (!$email) {
        echo json_encode(['valid' => false]);
        exit;
    }
    
    $result = verifyOTP($conn, $email, $otp);
    
    if ($result['valid']) {
        $_SESSION['otp_verified'] = true;
        $_SESSION['otp_verified_at'] = time();
    }
    
    echo json_encode(['valid' => $result['valid']]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['action']) && $_GET['action'] == 'resend_otp' && (isset($_SESSION['admin']) || isset($_SESSION['office']))) {
    header('Content-Type: application/json');
    require_once 'includes/otp.php';
    
    $action_type = isset($_GET['action_type']) ? $_GET['action_type'] : 'delete';
    $email = null;
    
    if (isset($_SESSION['admin'])) {
        $admin = $_SESSION['admin'];
        $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='$admin'")->fetch_assoc();
        $email = $admin_data['email'];
    } elseif (isset($_SESSION['office'])) {
        $office = $_SESSION['office'];
        $office_data = $conn->query("SELECT email FROM chmsu_auth_roles WHERE chmsu_role='$office'")->fetch_assoc();
        $email = $office_data['email'];
    }
    
    if (!$email) {
        echo json_encode(['success' => false, 'message' => 'Gmail not connected']);
        exit;
    }
    
    $otp_code = generateOTP();
    saveOTP($conn, $email, $otp_code, $action_type);
    sendOTPEmail($email, $otp_code, $action_type);
    
    echo json_encode(['success' => true, 'message' => 'OTP resent']);
    exit;
}

// ============================================
// CHAT TABLES - AUTO-CREATE / MIGRATE
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

$checkCol = $conn->query("SHOW COLUMNS FROM chmsu_group_chat_messages LIKE 'course'");
if ($checkCol && $checkCol->num_rows == 0) {
    $conn->query("ALTER TABLE chmsu_group_chat_messages ADD COLUMN course VARCHAR(50) DEFAULT NULL AFTER sender_id");
    $conn->query("ALTER TABLE chmsu_group_chat_messages ADD INDEX idx_group (course, year_level, section, office_name)");
}

$checkMentionCol = $conn->query("SHOW COLUMNS FROM chmsu_group_chat_messages LIKE 'is_mention'");
if ($checkMentionCol && $checkMentionCol->num_rows == 0) {
    $conn->query("ALTER TABLE chmsu_group_chat_messages ADD COLUMN is_mention TINYINT(1) DEFAULT 0 AFTER message");
}

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

$checkCol2 = $conn->query("SHOW COLUMNS FROM chmsu_group_chat_reads LIKE 'course'");
if ($checkCol2 && $checkCol2->num_rows == 0) {
    $conn->query("ALTER TABLE chmsu_group_chat_reads DROP INDEX uniq_read");
    $conn->query("ALTER TABLE chmsu_group_chat_reads ADD COLUMN course VARCHAR(50) DEFAULT NULL AFTER office_name");
    $conn->query("ALTER TABLE chmsu_group_chat_reads ADD UNIQUE KEY uniq_read (student_id, office_name, course, year_level, section)");
}

// ============================================
// CHAT MENTION HELPERS
// ============================================
function parseChatMentions($msg, $current_course = '') {
    $mentions = [];

    if (preg_match_all('/@\s*(\d)(?:st|nd|rd|th)\s*Year\s*(?:Section\s*)?([A-Z])\b/i', $msg, $m1, PREG_SET_ORDER)) {
        foreach ($m1 as $row) {
            $mentions[] = ['type' => 'both', 'year' => $row[1], 'section' => strtoupper($row[2])];
        }
    }

    if (preg_match_all('/@\s*(\d)(?:st|nd|rd|th)\s*Year\b/i', $msg, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $row) {
            $mentions[] = ['type' => 'year', 'year' => $row[1], 'section' => null];
        }
    }

    if (preg_match_all('/@\s*Sec(?:tion)?\s*([A-Z])\b/i', $msg, $m3, PREG_SET_ORDER)) {
        foreach ($m3 as $row) {
            $mentions[] = ['type' => 'section', 'year' => null, 'section' => strtoupper($row[1])];
        }
    }

    if (!empty($current_course) && preg_match('/@\s*' . preg_quote($current_course, '/') . '\b/i', $msg)) {
        $mentions[] = ['type' => 'course', 'year' => null, 'section' => null, 'course' => $current_course];
    }

    $unique = [];
    foreach ($mentions as $m) {
        $key = ($m['type'] ?? '') . '|' . ($m['year'] ?? '_') . '|' . ($m['section'] ?? '_') . '|' . ($m['course'] ?? '_');
        if (!isset($unique[$key])) $unique[$key] = $m;
    }
    return array_values($unique);
}

function findChatMentionedStudents($conn, $course, $mentions) {
    if (empty($mentions) || empty($course)) return [];

    $course_esc = $conn->real_escape_string($course);
    $ids = [];

    foreach ($mentions as $m) {
        if (($m['type'] ?? '') === 'course') {
            $q = $conn->query("SELECT s.chmsu_student_id, s.chmsu_full_name, u.email 
                               FROM chmsu_students_master s
                               LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                               WHERE UPPER(TRIM(s.chmsu_course)) = UPPER('$course_esc') AND s.is_archived = 0");
            if ($q) {
                while ($row = $q->fetch_assoc()) {
                    $ids[$row['chmsu_student_id']] = $row;
                }
            }
            continue;
        }

        $y = $m['year'];
        $s = $m['section'];
        $where = "UPPER(TRIM(chmsu_course)) = UPPER('$course_esc') AND is_archived = 0";
        if ($y !== null && $y !== '') $where .= " AND chmsu_year = '" . intval($y) . "'";
        if ($s !== null && $s !== '') $where .= " AND TRIM(UPPER(chmsu_section)) = UPPER('" . $conn->real_escape_string($s) . "')";

        $q = $conn->query("SELECT s.chmsu_student_id, s.chmsu_full_name, u.email 
                           FROM chmsu_students_master s
                           LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                           WHERE $where");
        if ($q) {
            while ($row = $q->fetch_assoc()) {
                $ids[$row['chmsu_student_id']] = $row;
            }
        }
    }
    return array_values($ids);
}

// ============================================
// STUDENT CHAT ACTION HANDLERS
// ============================================
if (isset($_POST['action']) && isset($_SESSION['student'])) {
    $action = $_POST['action'];
    $student_id = $_SESSION['student'];

    if ($action == 'send_chat_message') {
        $office_name = sanitize($_POST['office_name'] ?? '');
        $message = sanitize($_POST['message']);
        $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'individual';

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

        if ($chat_type === 'group') {
            $course  = sanitize($_POST['course'] ?? '');
            $year    = sanitize($_POST['year'] ?? '');
            $section = sanitize($_POST['section'] ?? '');

            if (empty($course) || empty($year) || empty($section)) {
                $stInfo = $conn->query("SELECT chmsu_course, chmsu_year, chmsu_section FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
                if (!$stInfo) {
                    $stInfo = $conn->query("SELECT chmsu_course, chmsu_year, chmsu_section FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
                }
                if ($stInfo) {
                    if (empty($course)) $course = $stInfo['chmsu_course'];
                    if (empty($year)) $year = $stInfo['chmsu_year'];
                    if (empty($section)) $section = $stInfo['chmsu_section'];
                }
            }

            if ($course && $office_name) {
                $course_esc = $conn->real_escape_string($course);
                $conn->query("INSERT INTO chmsu_group_chat_messages 
                              (sender_type, sender_id, course, year_level, section, office_name, message, file_path, file_name, file_type) 
                              VALUES ('student', '$student_id', '$course_esc', '$year', '$section', '$office_name', '$message', '$file_path', '$file_name', '$file_type')");
                logActivity($conn, $student_id, 'student', "Sent group chat to $office_name ($course)");
            }
        } else {
            if ($office_name) {
                $conn->query("INSERT INTO chmsu_chat_messages 
                              (sender_type, sender_id, receiver_id, message, file_path, file_name, file_type) 
                              VALUES ('student', '$student_id', '$office_name', '$message', '$file_path', '$file_name', '$file_type')");
                $conn->query("INSERT INTO chmsu_chat_conversations 
                              (student_id, office_name, last_message, last_message_time, office_unread) 
                              VALUES ('$student_id', '$office_name', '$message', NOW(), 1)
                              ON DUPLICATE KEY UPDATE 
                              last_message='$message', last_message_time=NOW(), office_unread=office_unread+1");
                logActivity($conn, $student_id, 'student', "Sent chat message to office: $office_name");
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'get_chat_messages') {
        $office_name = sanitize($_POST['office_name'] ?? '');
        $since_id = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;
        $messages = $conn->query("SELECT * FROM chmsu_chat_messages 
                                  WHERE ((sender_type='student' AND sender_id='$student_id' AND (receiver_id='$office_name' OR LOWER(receiver_id)=LOWER('$office_name')))
                                      OR (sender_type='office' AND (sender_id='$office_name' OR LOWER(sender_id)=LOWER('$office_name')) AND receiver_id='$student_id'))
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
                'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
            ];
        }
        echo json_encode($result);
        exit;
    }

    if ($action == 'get_group_chat_messages') {
        $office_name = sanitize($_POST['office_name'] ?? '');
        $course  = sanitize($_POST['course'] ?? '');
        $year    = sanitize($_POST['year'] ?? '');
        $section = sanitize($_POST['section'] ?? '');
        $since_id = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;

        if (empty($course)) {
            $stInfo = $conn->query("SELECT chmsu_course FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
            if (!$stInfo) {
                $stInfo = $conn->query("SELECT chmsu_course FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
            }
            if ($stInfo && !empty($stInfo['chmsu_course'])) $course = $stInfo['chmsu_course'];
        }

        $courseClause = $course !== '' ? " AND UPPER(TRIM(m.course)) = UPPER('" . $conn->real_escape_string($course) . "')" : "";
        $messages = $conn->query("SELECT m.*, 
                                  CASE 
                                      WHEN m.sender_type='student' THEN COALESCE(NULLIF(s.chmsu_full_name,''), NULLIF(u.chmsu_name,''), m.sender_id) 
                                      ELSE m.office_name 
                                  END as sender_name
                                  FROM chmsu_group_chat_messages m
                                  LEFT JOIN chmsu_students_master s ON m.sender_id = s.chmsu_student_id
                                  LEFT JOIN chmsu_user_accounts u ON m.sender_id = u.chmsu_student_id
                                  WHERE (m.office_name='$office_name' OR LOWER(m.office_name)=LOWER('$office_name')) $courseClause
                                  AND m.id > $since_id
                                  ORDER BY m.created_at ASC");
        $result = [];
        if ($messages) {
            while ($msg = $messages->fetch_assoc()) {
                $result[] = [
                    'id' => intval($msg['id']),
                    'sender_type' => $msg['sender_type'],
                    'sender_id' => $msg['sender_id'],
                    'sender_name' => $msg['sender_name'],
                    'message' => $msg['message'],
                    'is_mention' => intval($msg['is_mention'] ?? 0),
                    'file_path' => $msg['file_path'],
                    'file_name' => $msg['file_name'],
                    'file_type' => $msg['file_type'],
                    'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
                ];
            }
        }
        echo json_encode($result);
        exit;
    }

    if ($action == 'mark_chat_read') {
        $office_name = sanitize($_POST['office_name'] ?? '');
        $conn->query("UPDATE chmsu_chat_messages SET is_read = 1 
                      WHERE sender_type='office' AND (sender_id='$office_name' OR LOWER(sender_id)=LOWER('$office_name')) AND receiver_id='$student_id'");
        $conn->query("UPDATE chmsu_chat_conversations SET student_unread = 0 
                      WHERE student_id='$student_id' AND (office_name='$office_name' OR LOWER(office_name)=LOWER('$office_name'))");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'mark_group_read') {
        $office_name = sanitize($_POST['office_name'] ?? '');
        $course  = sanitize($_POST['course'] ?? '');

        if (empty($course)) {
            $stInfo = $conn->query("SELECT chmsu_course FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
            if (!$stInfo) {
                $stInfo = $conn->query("SELECT chmsu_course FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
            }
            if ($stInfo && !empty($stInfo['chmsu_course'])) $course = $stInfo['chmsu_course'];
        }

        if ($office_name && $course !== '') {
            $course_esc = $conn->real_escape_string($course);
            $latest = $conn->query("SELECT MAX(id) as max_id FROM chmsu_group_chat_messages 
                                    WHERE (office_name='$office_name' OR LOWER(office_name)=LOWER('$office_name')) 
                                    AND UPPER(TRIM(course)) = UPPER('$course_esc')")->fetch_assoc()['max_id'];
            $latest = $latest ? intval($latest) : 0;
            $conn->query("INSERT INTO chmsu_group_chat_reads 
                          (student_id, office_name, course, year_level, section, last_read_message_id) 
                          VALUES ('$student_id', '$office_name', '$course_esc', '', '', $latest)
                          ON DUPLICATE KEY UPDATE last_read_message_id=$latest, updated_at=NOW()");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'clear_chat') {
        $office_name = sanitize($_POST['office_name'] ?? '');
        $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'individual';

        if ($chat_type === 'group') {
            $course = sanitize($_POST['course'] ?? '');
            if (empty($course)) {
                $stInfo = $conn->query("SELECT chmsu_course FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
                if ($stInfo && !empty($stInfo['chmsu_course'])) $course = $stInfo['chmsu_course'];
            }
            if ($office_name && $course !== '') {
                $course_esc = $conn->real_escape_string($course);
                $conn->query("DELETE FROM chmsu_group_chat_messages 
                              WHERE UPPER(TRIM(course)) = UPPER('$course_esc')
                              AND (office_name='$office_name' OR LOWER(office_name)=LOWER('$office_name'))");
            }
        } else {
            $conn->query("DELETE FROM chmsu_chat_messages 
                          WHERE (sender_type='student' AND sender_id='$student_id' AND (receiver_id='$office_name' OR LOWER(receiver_id)=LOWER('$office_name')))
                          OR (sender_type='office' AND (sender_id='$office_name' OR LOWER(sender_id)=LOWER('$office_name')) AND receiver_id='$student_id')");
            $conn->query("DELETE FROM chmsu_chat_conversations 
                          WHERE student_id='$student_id' AND (office_name='$office_name' OR LOWER(office_name)=LOWER('$office_name'))");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'get_unread_count') {
        $indiv = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='$student_id'")->fetch_assoc()['total'] ?: 0;

        $master = $conn->query("SELECT chmsu_year, chmsu_section, chmsu_course FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
        if (!$master) {
            $master = $conn->query("SELECT chmsu_year, chmsu_section, chmsu_course FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
        }
        $myCourse = $master ? $master['chmsu_course'] : '';

        $group = 0;
        if ($myCourse) {
            $course_esc = $conn->real_escape_string($myCourse);
            $officeList = $conn->query("SELECT office_name FROM chmsu_offices");
            while ($off = $officeList->fetch_assoc()) {
                $on = $off['office_name'];
                $readRow = $conn->query("SELECT last_read_message_id FROM chmsu_group_chat_reads 
                                         WHERE student_id='$student_id' AND (office_name='$on' OR LOWER(office_name)=LOWER('$on')) 
                                         AND UPPER(TRIM(course)) = UPPER('$course_esc')")->fetch_assoc();
                $lastRead = $readRow ? intval($readRow['last_read_message_id']) : 0;
                $group += intval($conn->query("SELECT COUNT(*) as c FROM chmsu_group_chat_messages 
                                               WHERE (office_name='$on' OR LOWER(office_name)=LOWER('$on'))
                                               AND UPPER(TRIM(course)) = UPPER('$course_esc')
                                               AND sender_type='office' AND id > $lastRead")->fetch_assoc()['c']);
            }
        }

        echo json_encode(['total' => intval($indiv) + intval($group)]);
        exit;
    }
}

// ============================================
// OFFICE CHAT ACTION HANDLERS
// ============================================
if (isset($_POST['action']) && isset($_SESSION['office'])) {
    $action = $_POST['action'];
    $office = $_SESSION['office'];

    if ($action == 'send_chat_message') {
        $student_id = sanitize($_POST['student_id'] ?? '');
        $message = sanitize($_POST['message'] ?? '');
        $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'individual';

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

        if ($chat_type === 'group') {
            $course  = sanitize($_POST['course'] ?? '');
            $year    = sanitize($_POST['year'] ?? '');
            $section = sanitize($_POST['section'] ?? '');

            if ($course) {
                $mentions = parseChatMentions($message, $course);
                $is_mention = !empty($mentions) ? 1 : 0;

                $course_esc = $conn->real_escape_string($course);
                $office_esc = $conn->real_escape_string($office);
                $message_esc = $conn->real_escape_string($message);
                $file_path_esc = $conn->real_escape_string($file_path);
                $file_name_esc = $conn->real_escape_string($file_name);
                $file_type_esc = $conn->real_escape_string($file_type);

                $conn->query("INSERT INTO chmsu_group_chat_messages 
                              (sender_type, sender_id, course, year_level, section, office_name, message, is_mention, file_path, file_name, file_type) 
                              VALUES ('office', '$office_esc', '$course_esc', '$year', '$section', '$office_esc', '$message_esc', $is_mention, '$file_path_esc', '$file_name_esc', '$file_type_esc')");

                if (!empty($mentions)) {
                    $mentioned = findChatMentionedStudents($conn, $course, $mentions);
                    foreach ($mentioned as $ms) {
                        $sid = $conn->real_escape_string($ms['chmsu_student_id']);
                        $sname_raw = $ms['chmsu_full_name'];
                        $sname = $conn->real_escape_string($sname_raw);
                        $semail = $ms['email'];

                        $notif_title = "🔔 You were mentioned by $office";
                        $notif_message = "You were mentioned in $course group chat";
                        $nt = $conn->real_escape_string($notif_title);
                        $nm = $conn->real_escape_string($notif_message);

                        $conn->query("INSERT INTO chmsu_notifications 
                                      (user_type, user_id, student_id, office_name, title, message, type, link) 
                                      VALUES ('student', '$sid', '$sid', '$office_esc', '$nt', '$nm', 'chat', '?view=chat')");

                        if (!empty($semail)) {
                            $email_subject = "URGENT: You were mentioned by $office - CHMSU";
                            $sname_html = htmlspecialchars($sname_raw);
                            $office_html = htmlspecialchars($office);
                            $course_html = htmlspecialchars($course);
                            $msg_html = nl2br(htmlspecialchars($message));
                            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                            $email_message = "
                            <html><head><style>
                                body { font-family: 'Times New Roman', Times, serif; }
                                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
                                .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
                                .note-box { background: #fff3cd; padding: 15px; border-left: 4px solid #f39c12; border-radius: 4px; margin: 10px 0; color: #7d5a00; }
                                .footer { text-align: center; padding: 15px; font-size: 12px; color: #666; border-top: 1px solid #ddd; margin-top: 20px; }
                                .btn { display: inline-block; background: #1b4d3e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; }
                            </style></head><body>
                                <div class='container'>
                                    <div class='header'><h2>CHMSU E-Clearance System</h2></div>
                                    <div style='padding:20px;'>
                                        <p>Hello <strong>$sname_html</strong>,</p>
                                        <p>You were mentioned by <strong>$office_html</strong> in the <strong>$course_html</strong> group chat:</p>
                                        <div class='note-box'><p style='margin:0;'><strong>$msg_html</strong></p></div>
                                        <p style='margin-top:15px;'>Login to view the full conversation.</p>
                                        <a href='http://$host/index.php?view=chat' class='btn'>View Messages</a>
                                    </div>
                                    <div class='footer'><p>CHMSU E-Clearance System</p></div>
                                </div>
                            </body></html>";
                            @sendEmail($semail, $sname_raw, $email_subject, $email_message, true);
                        }
                    }
                }

                logActivity($conn, $office, 'office', "Sent group chat to $course" . ($is_mention ? " (mention)" : ""));
            }
        } else {
            if ($student_id) {
                $conn->query("INSERT INTO chmsu_chat_messages 
                              (sender_type, sender_id, receiver_id, message, file_path, file_name, file_type) 
                              VALUES ('office', '$office', '$student_id', '$message', '$file_path', '$file_name', '$file_type')");
                $conn->query("INSERT INTO chmsu_chat_conversations 
                              (student_id, office_name, last_message, last_message_time, student_unread) 
                              VALUES ('$student_id', '$office', '$message', NOW(), 1)
                              ON DUPLICATE KEY UPDATE 
                              last_message='$message', last_message_time=NOW(), student_unread=student_unread+1");
                logActivity($conn, $office, 'office', "Sent chat message to student: $student_id");
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'get_chat_messages') {
        $student_id = sanitize($_POST['student_id'] ?? '');
        $since_id = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;
        $messages = $conn->query("SELECT * FROM chmsu_chat_messages 
                                  WHERE ((sender_type='office' AND (sender_id='$office' OR LOWER(sender_id)=LOWER('$office')) AND receiver_id='$student_id')
                                      OR (sender_type='student' AND sender_id='$student_id' AND (receiver_id='$office' OR LOWER(receiver_id)=LOWER('$office'))))
                                  AND id > $since_id
                                  ORDER BY created_at ASC");
        $result = [];
        if ($messages) {
            while ($msg = $messages->fetch_assoc()) {
                $result[] = [
                    'id' => intval($msg['id']),
                    'sender_type' => $msg['sender_type'],
                    'message' => $msg['message'],
                    'file_path' => $msg['file_path'],
                    'file_name' => $msg['file_name'],
                    'file_type' => $msg['file_type'],
                    'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
                ];
            }
        }
        echo json_encode($result);
        exit;
    }

    if ($action == 'get_group_chat_messages') {
        $course  = sanitize($_POST['course'] ?? '');
        $year    = sanitize($_POST['year'] ?? '');
        $section = sanitize($_POST['section'] ?? '');
        $since_id = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;

        $courseClause = $course !== '' ? " AND UPPER(TRIM(m.course)) = UPPER('" . $conn->real_escape_string($course) . "')" : "";
        $messages = $conn->query("SELECT m.*, 
                                  CASE 
                                      WHEN m.sender_type='student' THEN COALESCE(NULLIF(s.chmsu_full_name,''), NULLIF(u.chmsu_name,''), m.sender_id) 
                                      ELSE m.office_name 
                                  END as sender_name
                                  FROM chmsu_group_chat_messages m
                                  LEFT JOIN chmsu_students_master s ON m.sender_id = s.chmsu_student_id
                                  LEFT JOIN chmsu_user_accounts u ON m.sender_id = u.chmsu_student_id
                                  WHERE (m.office_name='$office' OR LOWER(m.office_name)=LOWER('$office')) $courseClause
                                  AND m.id > $since_id
                                  ORDER BY m.created_at ASC");
        $result = [];
        if ($messages) {
            while ($msg = $messages->fetch_assoc()) {
                $result[] = [
                    'id' => intval($msg['id']),
                    'sender_type' => $msg['sender_type'],
                    'sender_name' => $msg['sender_name'],
                    'message' => $msg['message'],
                    'is_mention' => intval($msg['is_mention'] ?? 0),
                    'file_path' => $msg['file_path'],
                    'file_name' => $msg['file_name'],
                    'file_type' => $msg['file_type'],
                    'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
                ];
            }
        }
        echo json_encode($result);
        exit;
    }

    if ($action == 'mark_chat_read') {
        $student_id = sanitize($_POST['student_id'] ?? '');
        $conn->query("UPDATE chmsu_chat_messages SET is_read = 1 
                      WHERE sender_type='student' AND sender_id='$student_id' AND (receiver_id='$office' OR LOWER(receiver_id)=LOWER('$office'))");
        $conn->query("UPDATE chmsu_chat_conversations SET office_unread = 0 
                      WHERE student_id='$student_id' AND (office_name='$office' OR LOWER(office_name)=LOWER('$office'))");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'mark_group_read') {
        $course  = sanitize($_POST['course'] ?? '');
        if ($course !== '') {
            $course_esc = $conn->real_escape_string($course);
            $latest = $conn->query("SELECT MAX(id) as max_id FROM chmsu_group_chat_messages 
                                    WHERE (office_name='$office' OR LOWER(office_name)=LOWER('$office')) 
                                    AND UPPER(TRIM(course)) = UPPER('$course_esc')")->fetch_assoc()['max_id'];
            $latest = $latest ? intval($latest) : 0;
            $conn->query("INSERT INTO chmsu_group_chat_reads 
                          (student_id, office_name, course, year_level, section, last_read_message_id) 
                          VALUES ('OFFICE_$office', '$office', '$course_esc', '', '', $latest)
                          ON DUPLICATE KEY UPDATE last_read_message_id=$latest, updated_at=NOW()");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'clear_chat') {
        $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'individual';

        if ($chat_type === 'group') {
            $course  = sanitize($_POST['course'] ?? '');
            if ($course !== '') {
                $course_esc = $conn->real_escape_string($course);
                $conn->query("DELETE FROM chmsu_group_chat_messages 
                              WHERE UPPER(TRIM(course)) = UPPER('$course_esc')
                              AND (office_name='$office' OR LOWER(office_name)=LOWER('$office'))");
            }
        } else {
            $student_id = sanitize($_POST['student_id'] ?? '');
            $conn->query("DELETE FROM chmsu_chat_messages 
                          WHERE (sender_type='office' AND (sender_id='$office' OR LOWER(sender_id)=LOWER('$office')) AND receiver_id='$student_id')
                          OR (sender_type='student' AND sender_id='$student_id' AND (receiver_id='$office' OR LOWER(receiver_id)=LOWER('$office')))");
            $conn->query("DELETE FROM chmsu_chat_conversations 
                          WHERE student_id='$student_id' AND (office_name='$office' OR LOWER(office_name)=LOWER('$office'))");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action == 'get_unread_count') {
        $indiv = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$office' OR LOWER(office_name)=LOWER('$office')")->fetch_assoc()['total'] ?: 0;

        $courses = $conn->query("SELECT DISTINCT UPPER(TRIM(chmsu_course)) as course
                                 FROM chmsu_students_master WHERE is_archived=0 
                                 AND chmsu_course IS NOT NULL AND chmsu_course != ''");
        $group = 0;
        if ($courses) {
            while ($c = $courses->fetch_assoc()) {
                $course = $c['course'];
                $course_esc = $conn->real_escape_string($course);
                $readRow = $conn->query("SELECT last_read_message_id FROM chmsu_group_chat_reads 
                                         WHERE student_id='OFFICE_$office' AND (office_name='$office' OR LOWER(office_name)=LOWER('$office')) 
                                         AND UPPER(TRIM(course)) = UPPER('$course_esc')")->fetch_assoc();
                $lastRead = $readRow ? intval($readRow['last_read_message_id']) : 0;
                $group += intval($conn->query("SELECT COUNT(*) as c FROM chmsu_group_chat_messages 
                                               WHERE (office_name='$office' OR LOWER(office_name)=LOWER('$office'))
                                               AND UPPER(TRIM(course)) = UPPER('$course_esc')
                                               AND sender_type='student' AND id > $lastRead")->fetch_assoc()['c']);
            }
        }

        echo json_encode(['total' => intval($indiv) + intval($group)]);
        exit;
    }

    if ($action == 'get_students_for_chat') {
        $students = $conn->query("SELECT DISTINCT 
                                   s.chmsu_student_id as id,
                                   s.chmsu_full_name as name,
                                   s.chmsu_course as course,
                                   s.chmsu_year as year,
                                   s.chmsu_section as section,
                                   (SELECT office_unread FROM chmsu_chat_conversations 
                                    WHERE student_id = s.chmsu_student_id AND (office_name = '$office' OR LOWER(office_name) = LOWER('$office'))) as unread
                                  FROM chmsu_students_master s
                                  WHERE s.is_archived = 0
                                  ORDER BY s.chmsu_last_name ASC");
        $result = [];
        if ($students) {
            while ($s = $students->fetch_assoc()) {
                $result[] = [
                    'id' => $s['id'],
                    'name' => $s['name'],
                    'course' => $s['course'],
                    'year' => $s['year'],
                    'section' => $s['section'],
                    'unread' => $s['unread'] ?: 0
                ];
            }
        }
        echo json_encode($result);
        exit;
    }
}

// Print certificate
if (isset($_GET['print_certificate'])) {
    $student_id = sanitize($_GET['print_certificate']);
    
    if (hasAllClearanceApproved($conn, $student_id)) {
        $student = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
        $user = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
        
        include 'modules/certificate.php';
        exit;
    } else {
        $error = "Student does not have complete clearance yet.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=home");
        exit;
    }
}

// Logout
if (isset($_GET['logout'])) {
    if (isset($_SESSION['student'])) {
        logActivity($conn, $_SESSION['student'], 'student', 'Logged out');
    } elseif (isset($_SESSION['office'])) {
        logActivity($conn, $_SESSION['office'], 'office', 'Logged out');
    } elseif (isset($_SESSION['admin'])) {
        logActivity($conn, $_SESSION['admin'], 'admin', 'Logged out');
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Page routing parameters
$page = isset($_GET['page']) ? $_GET['page'] : '';
$view = isset($_GET['view']) ? $_GET['view'] : 'home';
$selectedOffice = isset($_GET['office']) ? $_GET['office'] : '';
$selectedReq = isset($_GET['req']) ? intval($_GET['req']) : 0;

// Include header
include 'includes/header.php';

// ============================================
// ROUTING LOGIC
// ============================================
if (isset($_SESSION['admin'])) {
    $adminSection = isset($_GET['adminsection']) ? $_GET['adminsection'] : 'dashboard';
    
    if ($adminSection == 'dashboard') {
        include 'modules/admin/dashboard.php';
    } elseif ($adminSection == 'courses') {
        include 'modules/admin/courses.php';
    } elseif ($adminSection == 'offices') {
        include 'modules/admin/offices.php';
    } elseif ($adminSection == 'sections') {
        include 'modules/admin/sections.php';
    } elseif ($adminSection == 'school_years') {
        include 'modules/admin/school_years.php';
    } elseif ($adminSection == 'semesters') {
        include 'modules/admin/semesters.php';
    } elseif ($adminSection == 'reports') {
        include 'modules/admin/reports.php';
    } elseif ($adminSection == 'history') {
        include 'modules/admin/history.php';
    } elseif ($adminSection == 'activity') {
        include 'modules/admin/activity.php';
    } elseif ($adminSection == 'maintenance') {
        include 'modules/admin/courses.php';
    } elseif ($adminSection == 'password_resets') {          // NEW
        include 'modules/admin/password_resets.php';          // NEW
    } else {
        include 'modules/admin/dashboard.php';
    }
}
elseif (isset($_SESSION['student'])) {
    if ($view == 'home') {
        include 'modules/student/dashboard.php';
    } elseif ($view == 'office') {
        include 'modules/student/clearance.php';
    } elseif ($view == 'history') {
        include 'modules/student/history.php';
    } elseif ($view == 'reports') {
        include 'modules/student/reports.php';
    } elseif ($view == 'feed') {
        include 'modules/student/feed.php';
    } elseif ($view == 'notifications') {
        include 'modules/student/notifications.php';
    } elseif ($view == 'chat') {
        include 'modules/student/chat.php';
    } else {
        include 'modules/student/dashboard.php';
    }
}
elseif (isset($_SESSION['office']) && $_SESSION['office'] == 'Registrar') {
    $currentSection = isset($_GET['section']) ? $_GET['section'] : 'dashboard';
    
    if (isset($_GET['view_year']) && $_GET['view_year'] > 0) {
        include 'modules/registrar/archived_students.php';
    } 
    elseif ($currentSection == 'dashboard') {
        include 'modules/registrar/dashboard.php';
    } 
    elseif ($currentSection == 'masterlist') {
        include 'modules/registrar/masterlist.php';
    } 
    elseif ($currentSection == 'clearance') {
        include 'modules/registrar/clearance.php';
    } 
    elseif ($currentSection == 'clearance_status') {
        include 'modules/registrar/clearance_status.php';
    }
    elseif ($currentSection == 'history') {
        include 'modules/registrar/history.php';
    }
    elseif ($currentSection == 'requirements') {
        include 'modules/registrar/requirements.php';
    } 
    elseif ($currentSection == 'submissions') {
        include 'modules/registrar/submissions.php';
    } 
    elseif ($currentSection == 'reports') {
        include 'modules/registrar/reports.php';
    } 
    elseif ($currentSection == 'archived') {
        include 'modules/registrar/archived.php';
    }
    elseif ($currentSection == 'promotion') {
        include 'modules/registrar/promotion.php';
    }
    elseif ($currentSection == 'reports_clearance') {
        include 'modules/registrar/reports_clearance_only.php';
    }
    elseif ($currentSection == 'note') {
        include 'modules/registrar/note.php';
    }
    elseif ($currentSection == 'chat') {
        include 'modules/registrar/chat.php';
    }
    else {
        include 'modules/registrar/dashboard.php';
    }
}
elseif (isset($_SESSION['office'])) {
    $currentOfficeSection = isset($_GET['officesection']) ? $_GET['officesection'] : 'dashboard';
    
    if ($currentOfficeSection == 'dashboard') {
        include 'modules/office/dashboard.php';
    } elseif ($currentOfficeSection == 'note') {
        include 'modules/office/chmsu_notes.php';
    } elseif ($currentOfficeSection == 'clearance') {
        include 'modules/office/clearance.php';
    } elseif ($currentOfficeSection == 'history') {
        include 'modules/office/history.php';
    } elseif ($currentOfficeSection == 'requirements') {
        include 'modules/office/requirements.php';
    } elseif ($currentOfficeSection == 'submissions') {
        include 'modules/office/submissions.php';
    } elseif ($currentOfficeSection == 'reports') {
        include 'modules/office/reports.php';
    } elseif ($currentOfficeSection == 'chat') {
        include 'modules/office/chat.php';
    } else {
        include 'modules/office/dashboard.php';
    }
}
else {
    if ($page == 'register') {
        include 'modules/main/register.php';
    } else {
        include 'modules/main/login.php';
    }
}

// Include footer
include 'includes/footer.php';
?>