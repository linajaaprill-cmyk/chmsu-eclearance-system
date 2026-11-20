<?php
    $office = $_SESSION['office'];
    $storage_used = getTotalUploadSize();
    $storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
    $currentOfficeSection = 'clearance';

    $view_mode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'pending';

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
    // CREATE CLEARANCE STATUS TABLE
    // ============================================
    $conn->query("CREATE TABLE IF NOT EXISTS chmsu_clearance_status (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        office_name VARCHAR(100) NOT NULL,
        semester_id INT(11) DEFAULT NULL,
        status ENUM('clear', 'unclear', 'pending') DEFAULT 'pending',
        note TEXT,
        updated_by VARCHAR(100),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_status (student_id, office_name, semester_id),
        INDEX idx_student (student_id),
        INDEX idx_office (office_name),
        INDEX idx_semester (semester_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $csColChk = $conn->query("SHOW COLUMNS FROM chmsu_clearance_status LIKE 'semester_id'");
    if ($csColChk && $csColChk->num_rows == 0) {
        $conn->query("ALTER TABLE chmsu_clearance_status ADD COLUMN semester_id INT(11) DEFAULT NULL AFTER office_name");
    }

    $has_sem_col = false;
    $chk_sem = $conn->query("SHOW COLUMNS FROM chmsu_clearance_status LIKE 'semester_id'");
    if ($chk_sem && $chk_sem->num_rows > 0) $has_sem_col = true;

    // ---- FIX: unique key must include semester_id (old key was student+office only) ----
    if ($has_sem_col) {
        $idxRes = $conn->query("SHOW INDEX FROM chmsu_clearance_status WHERE Key_name = 'unique_status'");
        if ($idxRes && $idxRes->num_rows > 0) {
            $idxCols = [];
            while ($ir = $idxRes->fetch_assoc()) $idxCols[] = $ir['Column_name'];
            if (!in_array('semester_id', $idxCols)) {
                @$conn->query("ALTER TABLE chmsu_clearance_status DROP INDEX unique_status");
                @$conn->query("ALTER TABLE chmsu_clearance_status ADD UNIQUE KEY unique_status (student_id, office_name, semester_id)");
            }
        }
        // ---- FIX: legacy rows without a semester belong to the semester that is active now ----
        if ($current_sem_id > 0) {
            $conn->query("UPDATE chmsu_clearance_status SET semester_id = $current_sem_id WHERE semester_id IS NULL");
        }
    }

    // ---- helper: save a clearance decision for the CURRENT semester (update-or-insert) ----
    if (!function_exists('cl_save_status')) {
        function cl_save_status($conn, $student_id, $office, $sem_id, $has_sem, $status, $note) {
            $sid = $conn->real_escape_string($student_id);
            $off = $conn->real_escape_string($office);
            $st  = ($status === 'clear') ? 'clear' : 'unclear';
            $noteSql = ($note === null || $note === '') ? "NULL" : "'" . $conn->real_escape_string($note) . "'";
            $useSem = ($has_sem && $sem_id > 0);
            $semCond = $useSem ? " AND semester_id = " . intval($sem_id) : "";

            $ex = $conn->query("SELECT id FROM chmsu_clearance_status WHERE student_id='$sid' AND office_name='$off' $semCond ORDER BY id DESC LIMIT 1");
            if ($ex && $ex->num_rows > 0) {
                $rid = intval($ex->fetch_assoc()['id']);
                $semSet = $useSem ? ", semester_id = " . intval($sem_id) : "";
                return (bool)$conn->query("UPDATE chmsu_clearance_status
                                          SET status='$st', note=$noteSql, updated_by='$off', updated_at=NOW() $semSet
                                          WHERE id = $rid");
            }

            if ($useSem) {
                $ok = $conn->query("INSERT INTO chmsu_clearance_status (student_id, office_name, semester_id, status, note, updated_by)
                                    VALUES ('$sid', '$off', " . intval($sem_id) . ", '$st', $noteSql, '$off')");
            } else {
                $ok = $conn->query("INSERT INTO chmsu_clearance_status (student_id, office_name, status, note, updated_by)
                                    VALUES ('$sid', '$off', '$st', $noteSql, '$off')");
            }
            if (!$ok) {
                // fallback (old unique key student+office still present): take over that row for the current semester
                $semSet = $useSem ? ", semester_id = " . intval($sem_id) : "";
                $ok = $conn->query("UPDATE chmsu_clearance_status
                                    SET status='$st', note=$noteSql, updated_by='$off', updated_at=NOW() $semSet
                                    WHERE student_id='$sid' AND office_name='$off'");
            }
            return (bool)$ok;
        }
    }

    // ============================================
    // GET MENTIONED STUDENTS FROM POSTS
    // *** FILTERED BY CURRENT SEMESTER — RESETS ON ADVANCE ***
    // *** PARSES: 👥 Mentioned, === SECTION MENTION ===, === COURSE MENTION === ***
    // *** ALSO PICKS UP THE PRIMARY student_id COLUMN ON EVERY POST ***
    // ============================================
    $mentioned_students = array();

    $feed_sem_filter = '';
    $feedHasSemCol = false;
    $fchk = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
    if ($fchk && $fchk->num_rows > 0) $feedHasSemCol = true;

    if ($feedHasSemCol && $current_sem_id > 0) {
        $feed_sem_filter = " AND (semester_id = $current_sem_id OR semester_id IS NULL) ";
    } elseif ($feedHasSemCol && $current_sem_id <= 0) {
        $feed_sem_filter = " AND 1 = 0 ";
    }

    $office_sql = $conn->real_escape_string($office);
    $feed_posts = $conn->query("SELECT content, student_id 
                                FROM chmsu_feed 
                                WHERE LOWER(office_name) = LOWER('$office_sql') $feed_sem_filter
                                ORDER BY created_at DESC");

    while ($post = $feed_posts->fetch_assoc()) {
        if (!empty($post['student_id'])) {
            $mentioned_students[] = $post['student_id'];
        }

        $content = $post['content'];

        // Individual mentions
        if (preg_match('/👥 Mentioned:\s*(.+?)(?:\n|$)/', $content, $m)) {
            $raw_names = trim($m[1]);
            if ($raw_names !== '') {
                $names = array_map('trim', explode('|||', $raw_names));

                foreach ($names as $name) {
                    if ($name === '') continue;
                    $name_esc = $conn->real_escape_string($name);
                    $studentMatch = $conn->query("
                        SELECT chmsu_student_id 
                        FROM chmsu_students_master 
                        WHERE chmsu_full_name = '$name_esc'
                           OR TRIM(CONCAT(chmsu_last_name, ', ', chmsu_first_name, ', ', chmsu_middle_name)) = '$name_esc'
                           OR TRIM(CONCAT(chmsu_last_name, ', ', chmsu_first_name)) = '$name_esc'
                        LIMIT 1
                    ");
                    if ($studentMatch && $studentMatch->num_rows > 0) {
                        $mentioned_students[] = $studentMatch->fetch_assoc()['chmsu_student_id'];
                    }
                }
            }
        }

        // Section mentions
        if (preg_match('/=== SECTION MENTION ===(.*?)=== END SECTION ===/s', $content, $sm)) {
            $inner = $sm[1];
            $lines = explode("\n", $inner);
            foreach ($lines as $line) {
                $line = trim($line);
                if (substr($line, 0, 2) === '- ') {
                    $name = trim(substr($line, 2));
                    if ($name === '') continue;
                    $name_esc = $conn->real_escape_string($name);
                    $studentMatch = $conn->query("
                        SELECT chmsu_student_id 
                        FROM chmsu_students_master 
                        WHERE chmsu_full_name = '$name_esc'
                           OR TRIM(CONCAT(chmsu_last_name, ', ', chmsu_first_name, ', ', chmsu_middle_name)) = '$name_esc'
                           OR TRIM(CONCAT(chmsu_last_name, ', ', chmsu_first_name)) = '$name_esc'
                        LIMIT 1
                    ");
                    if ($studentMatch && $studentMatch->num_rows > 0) {
                        $mentioned_students[] = $studentMatch->fetch_assoc()['chmsu_student_id'];
                    }
                }
            }
        }

        // Course mentions
        if (preg_match('/=== COURSE MENTION ===(.*?)=== END COURSE ===/s', $content, $cm)) {
            $inner = $cm[1];
            $lines = explode("\n", $inner);
            foreach ($lines as $line) {
                $line = trim($line);
                if (substr($line, 0, 2) === '- ') {
                    $name = trim(substr($line, 2));
                    if ($name === '') continue;
                    $name_esc = $conn->real_escape_string($name);
                    $studentMatch = $conn->query("
                        SELECT chmsu_student_id 
                        FROM chmsu_students_master 
                        WHERE chmsu_full_name = '$name_esc'
                           OR TRIM(CONCAT(chmsu_last_name, ', ', chmsu_first_name, ', ', chmsu_middle_name)) = '$name_esc'
                           OR TRIM(CONCAT(chmsu_last_name, ', ', chmsu_first_name)) = '$name_esc'
                        LIMIT 1
                    ");
                    if ($studentMatch && $studentMatch->num_rows > 0) {
                        $mentioned_students[] = $studentMatch->fetch_assoc()['chmsu_student_id'];
                    }
                }
            }
        }
    }
    $mentioned_students = array_values(array_unique($mentioned_students));

    // ============================================
    // HANDLE APPROVE/DECLINE/REVERT
    // ============================================
    if (isset($_POST['action']) && in_array($_POST['action'], ['approve_selected', 'decline_selected', 'revert_selected'], true)) {
        @set_time_limit(0);
        $act = $_POST['action'];

        // Selected students: compact CSV (avoids max_input_vars limit) or the normal array
        $selected = [];
        if (!empty($_POST['selected_csv'])) {
            $selected = explode(',', $_POST['selected_csv']);
        } elseif (isset($_POST['selected_students']) && is_array($_POST['selected_students'])) {
            $selected = $_POST['selected_students'];
        }
        $clean = [];
        foreach ($selected as $sid_raw) {
            $sid_raw = sanitize($sid_raw);
            if ($sid_raw !== '') $clean[$sid_raw] = true;
        }
        $selected = array_keys($clean);

        $allow_mentioned = (isset($_POST['allow_mentioned']) && $_POST['allow_mentioned'] === '1');

        if (empty($selected)) {
            $error = "Please select at least one student.";
        } else {

            if ($act === 'approve_selected' || $act === 'decline_selected') {
                $is_approve = ($act === 'approve_selected');
                $reason = '';
                if (!$is_approve) {
                    $reason = isset($_POST['decline_reason']) ? sanitize($_POST['decline_reason']) : 'Requirement not met';
                    if ($reason === '') $reason = 'Requirement not met';
                }

                $skipped_mentioned = 0;
                $skipped_locked = 0;
                $failed = 0;
                $processed = 0;
                $notify_list = [];

                $total_other = 0;
                if ($office === 'Registrar') {
                    $total_other = (int)$conn->query("SELECT COUNT(*) AS c FROM chmsu_offices WHERE office_name != 'Registrar'")->fetch_assoc()['c'];
                }
                $sem_clause = ($has_sem_col && $current_sem_id > 0) ? " AND semester_id = $current_sem_id " : "";

                foreach ($selected as $student_id) {

                    if (!$allow_mentioned && in_array($student_id, $mentioned_students, true)) {
                        $skipped_mentioned++;
                        continue;
                    }

                    if ($office === 'Registrar') {
                        $cleared_other = (int)$conn->query("SELECT COUNT(*) AS c FROM chmsu_clearance_status
                                                            WHERE student_id='$student_id' AND office_name != 'Registrar' AND status='clear'
                                                            $sem_clause
                                                           ")->fetch_assoc()['c'];
                        if ($total_other > 0 && $cleared_other < $total_other) {
                            $skipped_locked++;
                            continue;
                        }
                    }

                    $saved = cl_save_status($conn, $student_id, $office, $current_sem_id, $has_sem_col,
                                            $is_approve ? 'clear' : 'unclear',
                                            $is_approve ? null : $reason);
                    if (!$saved) { $failed++; continue; }

                    // ---- in-app notification to the student ----
                    $sid_e = $conn->real_escape_string($student_id);
                    $off_e = $conn->real_escape_string($office);
                    if ($is_approve) {
                        $n_title = "✅ Cleared by $office";
                        $n_msg   = "Your clearance status has been approved by $office";
                    } else {
                        $n_title = "❌ Declined by $office";
                        $n_msg   = "Your clearance status has been declined by $office. Reason: $reason";
                    }
                    $n_title_e = $conn->real_escape_string($n_title);
                    $n_msg_e   = $conn->real_escape_string($n_msg);
                    $conn->query("INSERT INTO chmsu_notifications 
                                  (user_type, user_id, student_id, office_name, title, message, type, link) 
                                  VALUES 
                                  ('student', '$sid_e', '$sid_e', '$off_e', '$n_title_e', '$n_msg_e', 'clearance', '?view=office')");

                    $notify_list[] = $student_id;
                    $processed++;
                }

                // ---- email (Gmail) notification — done AFTER all DB work so a slow mail server never blocks processing ----
                if (!empty($notify_list) && function_exists('sendEmail')) {
                    foreach ($notify_list as $nid) {
                        $nid_e = $conn->real_escape_string($nid);
                        $ui = $conn->query("SELECT chmsu_name, email FROM chmsu_user_accounts WHERE chmsu_student_id='$nid_e' LIMIT 1");
                        if ($ui && $ui->num_rows > 0) {
                            $u = $ui->fetch_assoc();
                            if (!empty($u['email'])) {
                                $sname = htmlspecialchars($u['chmsu_name']);
                                $oname = htmlspecialchars($office);
                                if ($is_approve) {
                                    $subj = "✅ Cleared by $office - CHMSU Clearance";
                                    $detail = "<p>Your clearance has been <strong style='color:#27ae60;'>APPROVED</strong> by <strong>$oname</strong>.</p>";
                                } else {
                                    $subj = "❌ Declined by $office - CHMSU Clearance";
                                    $detail = "<p>Your clearance has been <strong style='color:#e74c3c;'>DECLINED</strong> by <strong>$oname</strong>.</p>"
                                            . "<div style='background:#f8d7da;padding:10px;border-left:3px solid #e74c3c;'><strong>Reason:</strong> " . htmlspecialchars($reason) . "</div>";
                                }
                                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                $body = "<html><body><div style='font-family:Times New Roman;max-width:600px;margin:0 auto;padding:20px;border:1px solid #ddd;border-radius:8px;'>"
                                      . "<div style='background:#1b4d3e;color:white;padding:15px;text-align:center;'><h2>CHMSU E-Clearance System</h2></div>"
                                      . "<div style='padding:20px;'><p>Hello <strong>$sname</strong>,</p>$detail"
                                      . "<p>Login to view your clearance status.</p>"
                                      . "<a href='http://$host/index.php?view=office' style='background:#1b4d3e;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;'>View Clearance</a>"
                                      . "</div></div></body></html>";
                                try { @sendEmail($u['email'], $u['chmsu_name'], $subj, $body, true); } catch (Throwable $e) {}
                            }
                        }
                    }
                }

                $verb = $is_approve ? 'Approved' : 'Declined';
                logActivity($conn, $office, 'office', "$verb $processed students" . ($skipped_mentioned ? " ($skipped_mentioned skipped — mentioned)" : "") . ($skipped_locked ? " ($skipped_locked skipped — registrar lock)" : ""));
                $success = "$processed student(s) " . strtolower($verb) . " successfully!";
                if ($skipped_mentioned > 0) $success .= " — $skipped_mentioned skipped (mentioned in posts — turn on the Mentioned toggle to include them).";
                if ($skipped_locked > 0)  $success .= " — $skipped_locked skipped (pending other offices).";
                if ($failed > 0)          $success .= " — $failed could not be saved.";
            }

            // ============================================
            // UNDO / REVERT — always allowed.
            // ============================================
            if ($act === 'revert_selected') {
                $processed = 0;

                foreach ($selected as $student_id) {
                    if ($has_sem_col && $current_sem_id > 0) {
                        $conn->query("DELETE FROM chmsu_clearance_status 
                                      WHERE student_id='$student_id' AND office_name='$office' AND semester_id = $current_sem_id");
                    } else {
                        $conn->query("DELETE FROM chmsu_clearance_status 
                                      WHERE student_id='$student_id' AND office_name='$office'");
                    }
                    $processed++;
                }
                logActivity($conn, $office, 'office', "Reverted $processed students to pending");
                $success = "$processed student(s) reverted to pending!";
            }
        }
    }

    // ============================================
    // GET ALL STUDENTS WITH THEIR CLEARANCE STATUS
    // ============================================
    $filter_course = isset($_GET['filter_course']) ? $_GET['filter_course'] : '';
    $filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';
    $filter_section = isset($_GET['filter_section']) ? $_GET['filter_section'] : '';
    $filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
    $search_term = isset($_GET['search_term']) ? $_GET['search_term'] : '';

    $where = "WHERE s.is_archived = 0";
    if ($filter_course) $where .= " AND s.chmsu_course = '$filter_course'";
    if ($filter_year) $where .= " AND s.chmsu_year = '$filter_year'";
    if ($filter_section) $where .= " AND s.chmsu_section = '$filter_section'";
    if ($search_term) $where .= " AND (s.chmsu_last_name LIKE '%$search_term%' OR s.chmsu_first_name LIKE '%$search_term%' OR s.chmsu_full_name LIKE '%$search_term%')";

    if ($view_mode == 'pending') {
        $where .= " AND (c.status IS NULL OR c.status = 'pending')";
    } else {
        $where .= " AND c.status IN ('clear', 'unclear')";
        if ($filter_status) $where .= " AND c.status = '$filter_status'";
    }

    if ($has_sem_col && $current_sem_id > 0) {
        $join_cond = "s.chmsu_student_id = c.student_id AND c.office_name = '$office' AND c.semester_id = $current_sem_id";
    } else {
        $join_cond = "s.chmsu_student_id = c.student_id AND c.office_name = '$office'";
    }

    $mentioned_count_scope = '';
    if ($feedHasSemCol && $current_sem_id > 0) {
        $mentioned_count_scope = " AND (semester_id = $current_sem_id OR semester_id IS NULL) ";
    } elseif ($feedHasSemCol && $current_sem_id <= 0) {
        $mentioned_count_scope = " AND 1 = 0 ";
    }
    $office_sql_count = $conn->real_escape_string($office);

    $students = $conn->query("SELECT s.*, 
                              c.status as clearance_status, 
                              c.note as clearance_note,
                              c.updated_at as clearance_date,
                              (SELECT COUNT(*) FROM chmsu_feed WHERE LOWER(office_name)=LOWER('$office_sql_count') AND student_id=s.chmsu_student_id $mentioned_count_scope) as mentioned_count
                              FROM chmsu_students_master s
                              LEFT JOIN chmsu_clearance_status c ON $join_cond
                              $where
                              ORDER BY s.chmsu_course ASC, s.chmsu_year ASC, s.chmsu_section ASC, s.chmsu_last_name ASC");

    // ============================================
    // GET COURSES FOR FILTER
    // ============================================
    $courses_result = $conn->query("SELECT DISTINCT UPPER(TRIM(chmsu_course)) as course_code 
                                    FROM chmsu_students_master 
                                    WHERE is_archived = 0 
                                    AND chmsu_course IS NOT NULL 
                                    AND chmsu_course != ''
                                    ORDER BY course_code ASC");
    $allCourses = [];
    if ($courses_result && $courses_result->num_rows > 0) {
        while ($c = $courses_result->fetch_assoc()) {
            $allCourses[] = $c['course_code'];
        }
    }

    $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections ORDER BY section_name");

    $years = [1, 2, 3, 4];

    $suggestion_list = [];
    $suggestion_query = $conn->query("SELECT chmsu_student_id, chmsu_full_name, chmsu_course, chmsu_year, chmsu_section 
                                       FROM chmsu_students_master 
                                       WHERE is_archived = 0 
                                       ORDER BY chmsu_last_name ASC");
    if ($suggestion_query && $suggestion_query->num_rows > 0) {
        while ($s = $suggestion_query->fetch_assoc()) {
            $suggestion_list[] = $s;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CHMSU E-Clearance System - <?php echo strtoupper($office); ?> Clearance</title>
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
            
            .main-content {
                flex: 1;
                margin-left: 260px;
                padding: 20px;
                background: #f5f5f5;
                min-height: calc(100vh - 73px);
            }
            .dashboard-header-bar {
                background: white;
                padding: 15px 20px;
                border: 1px solid #ddd;
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                flex-wrap: wrap;
                gap: 10px;
            }
            .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }

            .active-sem-banner {
                background: linear-gradient(135deg, #1b4d3e 0%, #2d6a4f 100%);
                color: white;
                padding: 14px 22px;
                border-radius: 8px;
                margin-bottom: 18px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 12px;
                box-shadow: 0 3px 10px rgba(27,77,62,0.25);
            }
            .active-sem-banner-left {
                display: flex;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
            }
            .active-sem-banner-icon {
                font-size: 24px;
                line-height: 1;
                opacity: 0.9;
            }
            .active-sem-banner-text {
                display: flex;
                flex-direction: column;
                gap: 3px;
            }
            .active-sem-banner-label {
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 1px;
                opacity: 0.75;
                font-weight: bold;
            }
            .active-sem-banner-value {
                font-size: 16px;
                font-weight: bold;
                letter-spacing: 0.3px;
            }
            .active-sem-banner-right {
                background: rgba(255,255,255,0.15);
                padding: 8px 16px;
                border-radius: 6px;
                font-size: 12px;
                font-weight: bold;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            .active-sem-banner-right i { opacity: 0.9; }

            body.dark-mode .active-sem-banner {
                background: linear-gradient(135deg, #0f3b2f 0%, #1b4d3e 100%);
                box-shadow: 0 3px 10px rgba(0,0,0,0.4);
            }
            
            .view-toggle {
                display: flex;
                gap: 0;
                background: #f0f0f0;
                border-radius: 30px;
                padding: 4px;
                border: 2px solid #1b4d3e;
            }
            .view-toggle a {
                padding: 8px 20px;
                text-decoration: none;
                color: #1b4d3e;
                font-size: 13px;
                font-weight: bold;
                border-radius: 30px;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .view-toggle a.active {
                background: #1b4d3e;
                color: white;
            }
            .view-toggle a:hover:not(.active) {
                background: #e0e0e0;
            }
            
            .content-card {
                background: white;
                border: 1px solid #ddd;
                margin-bottom: 20px;
                border-radius: 8px;
                overflow: hidden;
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
                flex-wrap: wrap;
                gap: 10px;
            }
            .content-card-body { padding: 15px; }
            
            .filter-row {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                align-items: flex-end;
                margin-bottom: 15px;
            }
            .filter-row select, .filter-row input {
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 12px;
                min-width: 120px;
            }
            .btn-filter {
                background: #1b4d3e;
                color: white;
                padding: 8px 15px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
            }
            .btn-reset {
                background: #7f8c8d;
                color: white;
                padding: 8px 15px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                text-decoration: none;
            }
            
            .search-wrapper { position: relative; flex: 1; min-width: 220px; }
            .search-wrapper input { width: 100%; }
            .search-suggestions {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: white;
                border: 1px solid #ddd;
                border-top: none;
                max-height: 240px;
                overflow-y: auto;
                z-index: 1500;
                border-radius: 0 0 4px 4px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            .search-suggestions.show { display: block; }
            .search-suggestions .suggestion-item {
                padding: 8px 12px;
                cursor: pointer;
                font-size: 12px;
                border-bottom: 1px solid #f0f0f0;
                transition: 0.15s;
            }
            .search-suggestions .suggestion-item:hover,
            .search-suggestions .suggestion-item.active {
                background: #f0f8ff;
            }
            .search-suggestions .suggestion-item .sname {
                font-weight: bold;
                color: #1b4d3e;
            }
            .search-suggestions .suggestion-item .sdetails {
                font-size: 10px;
                color: #666;
                margin-top: 2px;
            }
            .search-suggestions .no-results {
                padding: 12px;
                text-align: center;
                color: #999;
                font-size: 12px;
            }
            
            .btn {
                padding: 8px 15px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 12px;
            }
            .btn-success { background: #27ae60; color: white; }
            .btn-success:hover { background: #219a52; }
            .btn-danger { background: #e74c3c; color: white; }
            .btn-danger:hover { background: #c0392b; }
            .btn-primary { background: #1b4d3e; color: white; }
            .btn-primary:hover { background: #2d6a4f; }
            .btn-warning { background: #f39c12; color: white; }
            .btn-warning:hover { background: #e67e22; }
            .btn-sm { padding: 4px 8px; font-size: 11px; }
            .btn-back { background: #7f8c8d; color: white; }
            .btn-back:hover { background: #6c7a7d; }
            .btn-undo { background: #f39c12; color: white; }
            .btn-undo:hover { background: #e67e22; }
            
            .clearance-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
            }
            .clearance-table th, .clearance-table td {
                border: 1px solid #ddd;
                padding: 8px 10px;
                text-align: left;
                vertical-align: middle;
            }
            .clearance-table th {
                background: #1b4d3e;
                color: white;
                font-weight: normal;
                position: sticky;
                top: 0;
                z-index: 10;
            }
            .clearance-table tr:nth-child(even) { background: #fafafa; }
            .clearance-table tr:hover { background: #f0f0f0; }
            .clearance-table input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
            
            .status-badge {
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 10px;
                font-weight: 600;
                display: inline-block;
            }
            .status-cleared { background: #27ae60; color: white; }
            .status-unclear { background: #e74c3c; color: white; }
            .status-pending { background: #f39c12; color: white; }
            
            .mentioned-badge {
                background: #3498db;
                color: white;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 9px;
                display: inline-block;
                margin-left: 4px;
            }
            
            .action-bar {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                align-items: center;
                padding: 10px 0;
                border-bottom: 1px solid #eee;
                margin-bottom: 15px;
            }
            .action-bar .select-all {
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .toggle-switch {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 5px 12px 5px 8px;
                background: #e0e0e0;
                border-radius: 20px;
                font-size: 11px;
                cursor: pointer;
                border: 1px solid #ccc;
                user-select: none;
                transition: all 0.3s;
            }
            .toggle-switch .toggle-track {
                width: 34px;
                height: 18px;
                background: #999;
                border-radius: 20px;
                position: relative;
                transition: background 0.3s;
                flex-shrink: 0;
            }
            .toggle-switch .toggle-track::after {
                content: '';
                position: absolute;
                top: 2px;
                left: 2px;
                width: 14px;
                height: 14px;
                background: white;
                border-radius: 50%;
                transition: left 0.3s;
                box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            }
            .toggle-switch.active {
                background: #27ae60;
                border-color: #27ae60;
                color: white;
            }
            .toggle-switch.active .toggle-track {
                background: rgba(255,255,255,0.5);
            }
            .toggle-switch.active .toggle-track::after {
                left: 18px;
            }
            
            .decline-reason-input {
                display: none;
                margin-top: 10px;
                padding: 10px;
                background: #f8f9fa;
                border-radius: 4px;
            }
            .decline-reason-input.show {
                display: block;
            }
            .decline-reason-input textarea {
                width: 100%;
                min-height: 60px;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 12px;
            }
            
            .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
            .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
            .info-message { background: #d1ecf1; color: #0c5460; padding: 10px; margin-bottom: 15px; border-left: 3px solid #17a2b8; }
            
            .empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #999;
            }
            .empty-state i {
                font-size: 48px;
                display: block;
                margin-bottom: 15px;
                color: #ddd;
            }
            
            body.dark-mode { background: #0a0a0a; }
            body.dark-mode .main-content { background: #0a0a0a; }
            body.dark-mode .dashboard-header-bar,
            body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
            body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
            body.dark-mode .clearance-table th { background: #2c2c2c; color: #fff; border-color: #444; }
            body.dark-mode .clearance-table td { border-color: #333; color: #fff; }
            body.dark-mode .clearance-table tr:nth-child(even) { background: #1a1a1a; }
            body.dark-mode .clearance-table tr:hover { background: #2c2c2c; }
            body.dark-mode .filter-row select,
            body.dark-mode .filter-row input { background: #2c2c2c; border-color: #444; color: #fff; }
            body.dark-mode .action-bar { border-bottom-color: #444; }
            body.dark-mode .decline-reason-input { background: #2c2c2c; }
            body.dark-mode .decline-reason-input textarea { background: #1a1a1a; border-color: #444; color: #fff; }
            body.dark-mode .view-toggle { background: #2c2c2c; }
            body.dark-mode .view-toggle a { color: #f1c40f; }
            body.dark-mode .view-toggle a.active { background: #f1c40f; color: #000; }
            body.dark-mode .toggle-switch { background: #2c2c2c; border-color: #444; color: #ddd; }
            body.dark-mode .toggle-switch.active { background: #27ae60; border-color: #27ae60; color: #fff; }
            body.dark-mode .search-suggestions { background: #1a1a1a; border-color: #444; }
            body.dark-mode .search-suggestions .suggestion-item { border-bottom-color: #333; color: #fff; }
            body.dark-mode .search-suggestions .suggestion-item:hover,
            body.dark-mode .search-suggestions .suggestion-item.active { background: #2c2c2c; }
            body.dark-mode .search-suggestions .suggestion-item .sname { color: #f1c40f; }
            body.dark-mode .search-suggestions .suggestion-item .sdetails { color: #aaa; }
            
            @media (max-width: 768px) {
                .office-sidebar { width: 100%; position: relative; height: auto; }
                .main-content { margin-left: 0; }
                .clearance-table { font-size: 10px; }
                .clearance-table th, .clearance-table td { padding: 5px; }
                .view-toggle a { padding: 6px 12px; font-size: 11px; }
                .active-sem-banner { flex-direction: column; align-items: flex-start; }
                .active-sem-banner-right { width: 100%; justify-content: center; }
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
            <p>CLEARANCE SYSTEM | <?php echo strtoupper($office); ?> Portal</p>
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
                <li><a href="?officesection=feed"><i class="fas fa-rss"></i> Feed Updates</a></li>
                <li><a href="?officesection=clearance" class="active"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
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
                <h2>Clearance Management</h2>
                <div class="view-toggle">
                    <a href="?officesection=clearance&view_mode=pending" class="<?php echo $view_mode == 'pending' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Pending
                    </a>
                    <a href="?officesection=clearance&view_mode=processed" class="<?php echo $view_mode == 'processed' ? 'active' : ''; ?>">
                        <i class="fas fa-check-double"></i> Processed
                    </a>
                </div>
            </div>

            <div class="active-sem-banner">
                <div class="active-sem-banner-left">
                    <div class="active-sem-banner-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="active-sem-banner-text">
                        <span class="active-sem-banner-label">Currently Active</span>
                        <span class="active-sem-banner-value">
                            <?php echo htmlspecialchars($current_sy_label); ?>
                            &nbsp;·&nbsp;
                            <?php echo htmlspecialchars($current_sem_name); ?>
                        </span>
                    </div>
                </div>
                <div class="active-sem-banner-right">
                    <i class="fas fa-info-circle"></i>
                    <span>Clearance & mentions are scoped to this semester only.</span>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($mentioned_students) && $view_mode == 'pending'): ?>
                <div class="info-message">
                    <i class="fas fa-info-circle"></i> 
                    <strong><?php echo count($mentioned_students); ?> student(s)</strong> have been mentioned in posts this semester and are locked from Approve / Decline. Turn on the <strong>Mentioned</strong> toggle to include them.
                </div>
            <?php endif; ?>
            
            <div class="content-card">
                <div class="content-card-header">
                    <span>
                        <i class="fas fa-users"></i> 
                        <?php echo $view_mode == 'pending' ? 'Pending Students' : 'Processed Students (Approved / Declined)'; ?>
                    </span>
                    <span style="font-size: 11px; color: #666;">
                        <?php echo $view_mode == 'pending' ? 'Select students and approve or decline' : 'Students already processed'; ?>
                    </span>
                </div>
                <div class="content-card-body">
                    <form method="GET" id="filterForm">
                        <input type="hidden" name="officesection" value="clearance">
                        <input type="hidden" name="view_mode" value="<?php echo $view_mode; ?>">
                        <div class="filter-row">
                            
                            <div class="search-wrapper">
                                <input type="text" name="search_term" id="searchInput" placeholder="Search by name..." 
                                       value="<?php echo htmlspecialchars($search_term); ?>"
                                       autocomplete="off"
                                       oninput="handleSearchInput()" 
                                       onfocus="handleSearchFocus()" 
                                       onblur="handleSearchBlur()">
                                <div class="search-suggestions" id="searchSuggestions"></div>
                            </div>
                            
                            <div class="form-group">
                                <select name="filter_course" onchange="this.form.submit()">
                                    <option value="">All Courses</option>
                                    <?php foreach ($allCourses as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filter_course == $c ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <select name="filter_year" onchange="this.form.submit()">
                                    <option value="">All Years</option>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>th Year
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <select name="filter_section" onchange="this.form.submit()">
                                    <option value="">All Sections</option>
                                    <?php 
                                    $sections->data_seek(0);
                                    while ($s = $sections->fetch_assoc()): ?>
                                        <option value="<?php echo $s['section_name']; ?>" <?php echo $filter_section == $s['section_name'] ? 'selected' : ''; ?>>
                                            <?php echo $s['section_name']; ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <?php if ($view_mode == 'processed'): ?>
                            <div class="form-group">
                                <select name="filter_status" onchange="this.form.submit()">
                                    <option value="">All Status</option>
                                    <option value="clear" <?php echo $filter_status == 'clear' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="unclear" <?php echo $filter_status == 'unclear' ? 'selected' : ''; ?>>Declined</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div>
                                <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filter</button>
                                <a href="?officesection=clearance&view_mode=<?php echo $view_mode; ?>" class="btn-reset"><i class="fas fa-times"></i> Clear</a>
                            </div>
                        </div>
                    </form>
                    
                    <?php if ($view_mode == 'pending'): ?>
                    <form method="POST" id="clearanceForm">
                        <input type="hidden" name="filter_course" value="<?php echo $filter_course; ?>">
                        <input type="hidden" name="filter_year" value="<?php echo $filter_year; ?>">
                        <input type="hidden" name="filter_section" value="<?php echo $filter_section; ?>">
                        <input type="hidden" name="filter_status" value="<?php echo $filter_status; ?>">
                        <input type="hidden" name="search_term" value="<?php echo htmlspecialchars($search_term); ?>">
                        <input type="hidden" name="allow_mentioned" id="allowMentioned" value="0">
                        <input type="hidden" name="selected_csv" id="selectedCsv" value="">
                        
                        <div class="action-bar">
                            <div class="select-all">
                                <input type="checkbox" id="selectAll" onclick="toggleAllCheckboxes()">
                                <label for="selectAll">Select All</label>
                            </div>
                            <button type="submit" name="action" value="approve_selected" class="btn btn-success" onclick="return confirmApprove(event)">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button type="button" class="btn btn-danger" onclick="toggleDeclineReason()">
                                <i class="fas fa-times"></i> Decline
                            </button>
                            
                            <div class="toggle-switch" id="mentionToggle" onclick="toggleMentionSelectable()">
                                <div class="toggle-track"></div>
                                <span><i class="fas fa-at"></i> Mentioned</span>
                            </div>
                            
                            <span style="font-size: 11px; color: #666; margin-left: 10px;">
                                <span id="selectedCount">0</span> selected
                            </span>
                        </div>
                        
                        <div class="decline-reason-input" id="declineReasonDiv">
                            <label><strong>Reason for Declination:</strong></label>
                            <textarea name="decline_reason" id="declineReason" placeholder="Enter reason for declining these students..."></textarea>
                            <button type="submit" name="action" value="decline_selected" class="btn btn-danger" style="margin-top: 5px;" onclick="return confirmDecline(event)">
                                <i class="fas fa-times"></i> Confirm Decline
                            </button>
                            <button type="button" class="btn btn-sm btn-back" onclick="toggleDeclineReason()" style="margin-top: 5px;">Cancel</button>
                        </div>
                    </form>
                    <?php else: ?>
                    <form method="POST" id="revertForm" onsubmit="return validateRevertForm()">
                        <input type="hidden" name="filter_course" value="<?php echo $filter_course; ?>">
                        <input type="hidden" name="filter_year" value="<?php echo $filter_year; ?>">
                        <input type="hidden" name="filter_section" value="<?php echo $filter_section; ?>">
                        <input type="hidden" name="filter_status" value="<?php echo $filter_status; ?>">
                        <input type="hidden" name="search_term" value="<?php echo htmlspecialchars($search_term); ?>">
                        <input type="hidden" name="selected_csv" id="selectedCsvRevert" value="">
                        
                        <div class="action-bar" style="border-top: 2px solid #f39c12; border-bottom: 2px solid #f39c12; padding-top: 10px; padding-bottom: 10px;">
                            <div class="select-all">
                                <input type="checkbox" id="selectAllProcessed" onclick="toggleAllCheckboxesProcessed()">
                                <label for="selectAllProcessed">Select All</label>
                            </div>
                            <button type="submit" name="action" value="revert_selected" class="btn btn-undo">
                                <i class="fas fa-undo"></i> Undo Selected (Back to Pending)
                            </button>
                            <span style="font-size: 11px; color: #666; margin-left: 10px;">
                                <span id="selectedCountProcessed">0</span> selected
                            </span>
                            <span style="font-size: 11px; color: #e67e22; margin-left: 10px;">
                                <i class="fas fa-info-circle"></i> Undo will move students back to Pending list
                            </span>
                        </div>
                    </form>
                    <?php endif; ?>
                    
                    <div style="overflow-x: auto; max-height: 600px; overflow-y: auto; margin-top: 15px;">
                        <table class="clearance-table" id="clearanceTable">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"></th>
                                    <th>Student ID</th>
                                    <th>Last Name</th>
                                    <th>First Name</th>
                                    <th>Course</th>
                                    <th>Year</th>
                                    <th>Section</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $rowCount = 0;
                                
                                if ($students && $students->num_rows > 0):
                                    while ($s = $students->fetch_assoc()):
                                        $isMentioned = in_array($s['chmsu_student_id'], $mentioned_students);
                                        $status = $s['clearance_status'] ?: 'pending';
                                        $statusClass = $status == 'clear' ? 'status-cleared' : ($status == 'unclear' ? 'status-unclear' : 'status-pending');
                                        $rowCount++;
                                ?>
                                <tr data-student-id="<?php echo $s['chmsu_student_id']; ?>"
                                    data-name="<?php echo strtolower($s['chmsu_full_name']); ?>"
                                    data-last="<?php echo strtolower($s['chmsu_last_name']); ?>"
                                    data-first="<?php echo strtolower($s['chmsu_first_name']); ?>"
                                    data-course="<?php echo $s['chmsu_course']; ?>"
                                    data-year="<?php echo $s['chmsu_year']; ?>"
                                    data-section="<?php echo htmlspecialchars($s['chmsu_section']); ?>">
                                    <?php 
                                    $is_locked = false;
                                    if ($office == 'Registrar') {
                                        $total_other = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices WHERE office_name != 'Registrar'")->fetch_assoc()['c'];
                                        $sem_clause_ui = ($has_sem_col && $current_sem_id > 0) ? " AND semester_id = $current_sem_id " : "";
                                        $cleared_by_others = $conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE student_id='{$s['chmsu_student_id']}' AND office_name != 'Registrar' AND status='clear' $sem_clause_ui")->fetch_assoc()['c'];
                                        
                                        if ($cleared_by_others < $total_other || $total_other == 0) {
                                            $is_locked = true;
                                        }
                                    }
                                    ?>

                                    <?php if ($view_mode == 'pending'): ?>
                                    <td>
                                        <?php if ($is_locked): ?>
                                            <div style="color: #e74c3c; font-size: 16px; text-align: center; cursor: not-allowed;" title="Pending other offices">
                                                <i class="fas fa-lock"></i>
                                            </div>
                                        <?php else: ?>
                                            <input type="checkbox" name="selected_students[]" value="<?php echo $s['chmsu_student_id']; ?>" 
                                                   class="student-checkbox <?php echo $isMentioned ? 'mentioned-checkbox' : ''; ?>" 
                                                   form="clearanceForm" 
                                                   data-mentioned="<?php echo $isMentioned ? '1' : '0'; ?>"
                                                   <?php echo $isMentioned ? 'disabled' : ''; ?>
                                                   onchange="updateSelectedCount()">
                                            <?php if ($isMentioned): ?>
                                                <span class="mentioned-badge" title="Mentioned in post - locked by default">📌</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php else: ?>
                                    <td>
                                        <?php if ($is_locked): ?>
                                             <div style="color: #e74c3c; font-size: 16px; text-align: center; cursor: not-allowed;" title="Pending other offices">
                                                <i class="fas fa-lock"></i>
                                            </div>
                                        <?php else: ?>
                                            <input type="checkbox" name="selected_students[]" value="<?php echo $s['chmsu_student_id']; ?>" 
                                                   class="student-checkbox-processed" form="revertForm"
                                                   data-mentioned="<?php echo $isMentioned ? '1' : '0'; ?>"
                                                   onchange="updateSelectedCountProcessed()">
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td><code><?php echo $s['chmsu_student_id']; ?></code></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_first_name']); ?></td>
                                    <td><?php echo $s['chmsu_course']; ?></td>
                                    <td><?php echo $s['chmsu_year']; ?></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_section']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo $status == 'clear' ? 'Approved' : ($status == 'unclear' ? 'Declined' : 'Pending'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($is_locked): ?>
                                            <span style="color: #e74c3c; font-size: 10px; font-weight: bold;"><i class="fas fa-exclamation-triangle"></i> Pending other offices</span>
                                        <?php elseif ($status == 'unclear' && !empty($s['clearance_note'])): ?>
                                            <span style="color: #e74c3c; font-size: 10px;"><?php echo htmlspecialchars($s['clearance_note']); ?></span>
                                        <?php elseif ($status == 'clear'): ?>
                                            <span style="color: #27ae60; font-size: 10px;">✓ Approved</span>
                                        <?php else: ?>
                                            <span style="color: #999; font-size: 10px;">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <tr>
                                    <td colspan="9" class="empty-state">
                                        <i class="fas fa-<?php echo $view_mode == 'pending' ? 'check-circle' : 'inbox'; ?>"></i>
                                        <?php echo $view_mode == 'pending' ? 'No pending students. All students have been processed!' : 'No processed students yet.'; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 10px; font-size: 11px; color: #666;">
                        <span id="totalCount"><?php echo $rowCount; ?></span> 
                        <?php echo $view_mode == 'pending' ? 'pending' : 'processed'; ?> students
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    var suggestionStudents = <?php echo json_encode($suggestion_list); ?>;

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

    var currentFocus = -1;

    function handleSearchInput() {
        const input = document.getElementById('searchInput');
        const query = input.value.trim().toLowerCase();
        const box = document.getElementById('searchSuggestions');

        filterTableRows(query);

        if (query.length < 1) {
            box.classList.remove('show');
            box.innerHTML = '';
            return;
        }

        const matches = suggestionStudents.filter(s =>
            s.chmsu_full_name.toLowerCase().includes(query) ||
            s.chmsu_student_id.toLowerCase().includes(query)
        ).slice(0, 8);

        if (matches.length === 0) {
            box.innerHTML = '<div class="no-results"><i class="fas fa-search"></i> No students found</div>';
            box.classList.add('show');
            return;
        }

        let html = '';
        matches.forEach((s, idx) => {
            html += `<div class="suggestion-item" data-name="${escapeHtml(s.chmsu_full_name)}" data-index="${idx}">
                <div class="sname"><i class="fas fa-user"></i> ${escapeHtml(s.chmsu_full_name)}</div>
                <div class="sdetails">${escapeHtml(s.chmsu_course)} ${s.chmsu_year}${escapeHtml(s.chmsu_section)} &middot; ${escapeHtml(s.chmsu_student_id)}</div>
            </div>`;
        });
        box.innerHTML = html;
        box.classList.add('show');
        currentFocus = -1;

        box.querySelectorAll('.suggestion-item').forEach(item => {
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                input.value = this.dataset.name;
                filterTableRows(this.dataset.name.toLowerCase());
                box.classList.remove('show');
                box.innerHTML = '';
            });
        });
    }

    function handleSearchFocus() {
        const input = document.getElementById('searchInput');
        if (input.value.trim().length >= 1) {
            handleSearchInput();
        }
    }

    function handleSearchBlur() {
        setTimeout(() => {
            document.getElementById('searchSuggestions').classList.remove('show');
        }, 200);
    }

    function filterTableRows(query) {
        const rows = document.querySelectorAll('#clearanceTable tbody tr');
        let visible = 0;
        rows.forEach(row => {
            if (!row.dataset.studentId) return;
            const name = row.dataset.name || '';
            const last = row.dataset.last || '';
            const first = row.dataset.first || '';
            const sid = row.dataset.studentId || '';
            if (
                name.includes(query) ||
                last.includes(query) ||
                first.includes(query) ||
                sid.toLowerCase().includes(query)
            ) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });
        const tc = document.getElementById('totalCount');
        if (tc) tc.textContent = visible;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    let mentionEnabled = false;

    function toggleMentionSelectable() {
        mentionEnabled = !mentionEnabled;
        const toggle = document.getElementById('mentionToggle');
        const mentionedCheckboxes = document.querySelectorAll('.mentioned-checkbox');
        const allowField = document.getElementById('allowMentioned');
        if (allowField) allowField.value = mentionEnabled ? '1' : '0';
        
        if (mentionEnabled) {
            toggle.classList.add('active');
            mentionedCheckboxes.forEach(cb => {
                cb.disabled = false;
            });
        } else {
            toggle.classList.remove('active');
            mentionedCheckboxes.forEach(cb => {
                cb.disabled = true;
                cb.checked = false;
            });
        }
        updateSelectedCount();
    }

    function toggleAllCheckboxes() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.student-checkbox');
        const isChecked = selectAll.checked;
        
        checkboxes.forEach(cb => {
            const row = cb.closest('tr');
            if (row && row.style.display !== 'none' && !cb.disabled) {
                cb.checked = isChecked;
            }
        });
        
        updateSelectedCount();
    }

    function updateSelectedCount() {
        const checkboxes = document.querySelectorAll('.student-checkbox:checked');
        const enabledCheckboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
        const sc = document.getElementById('selectedCount');
        if (sc) sc.textContent = checkboxes.length;
        
        const allChecked = enabledCheckboxes.length > 0 && checkboxes.length === enabledCheckboxes.length;
        const selectAll = document.getElementById('selectAll');
        if (selectAll) selectAll.checked = allChecked;
    }

    function toggleAllCheckboxesProcessed() {
        const selectAll = document.getElementById('selectAllProcessed');
        const checkboxes = document.querySelectorAll('.student-checkbox-processed');
        const isChecked = selectAll.checked;
        
        checkboxes.forEach(cb => {
            const row = cb.closest('tr');
            if (row && row.style.display !== 'none') {
                cb.checked = isChecked;
            }
        });
        
        updateSelectedCountProcessed();
    }

    function updateSelectedCountProcessed() {
        const checkboxes = document.querySelectorAll('.student-checkbox-processed:checked');
        const total = document.querySelectorAll('.student-checkbox-processed').length;
        const sc = document.getElementById('selectedCountProcessed');
        if (sc) sc.textContent = checkboxes.length;
        
        const allChecked = checkboxes.length > 0 && checkboxes.length === total;
        const selectAll = document.getElementById('selectAllProcessed');
        if (selectAll) selectAll.checked = allChecked;
    }

    function toggleDeclineReason() {
        const div = document.getElementById('declineReasonDiv');
        div.classList.toggle('show');
        if (div.classList.contains('show')) {
            document.getElementById('declineReason').focus();
        }
    }

    function confirmApprove(event) {
        const checkboxes = document.querySelectorAll('.student-checkbox:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one student.');
            event.preventDefault();
            return false;
        }
        const mentionedSel = Array.from(checkboxes).filter(cb => cb.dataset.mentioned === '1').length;
        let msg = 'Are you sure you want to approve ' + checkboxes.length + ' selected student(s)?\n\nThey will be marked as CLEARED and notified.';
        if (mentionedSel > 0) msg += '\n\n(' + mentionedSel + ' of them are mentioned students.)';
        return confirm(msg);
    }

    function confirmDecline(event) {
        const checkboxes = document.querySelectorAll('.student-checkbox:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one student to decline.');
            event.preventDefault();
            return false;
        }
        const reason = document.getElementById('declineReason').value.trim();
        if (!reason) {
            alert('Please enter a reason for declination.');
            document.getElementById('declineReason').focus();
            event.preventDefault();
            return false;
        }
        const mentionedSel = Array.from(checkboxes).filter(cb => cb.dataset.mentioned === '1').length;
        let msg = 'Are you sure you want to decline ' + checkboxes.length + ' selected student(s)?\n\nReason: ' + reason;
        if (mentionedSel > 0) msg += '\n\n(' + mentionedSel + ' of them are mentioned students.)';
        return confirm(msg);
    }

    function validateRevertForm() {
        const checkboxes = document.querySelectorAll('.student-checkbox-processed:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one student to undo.');
            return false;
        }
        return confirm('Are you sure you want to undo the decision for ' + checkboxes.length + ' selected student(s)?\n\nThey will be moved back to the Pending list.');
    }

    // Send selected IDs as ONE comma-separated field (no PHP max_input_vars limit for big classes)
    function packSelected(selector, csvId) {
        var ids = [];
        document.querySelectorAll(selector + ':checked').forEach(function(cb) {
            ids.push(cb.value);
            cb.removeAttribute('name');
        });
        var f = document.getElementById(csvId);
        if (f) f.value = ids.join(',');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateSelectedCount();
        updateSelectedCountProcessed();
        
        document.querySelectorAll('.student-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });
        
        document.querySelectorAll('.student-checkbox-processed').forEach(cb => {
            cb.addEventListener('change', updateSelectedCountProcessed);
        });

        var cf = document.getElementById('clearanceForm');
        if (cf) cf.addEventListener('submit', function() { packSelected('.student-checkbox', 'selectedCsv'); });

        var rf = document.getElementById('revertForm');
        if (rf) rf.addEventListener('submit', function() { packSelected('.student-checkbox-processed', 'selectedCsvRevert'); });
    });
    </script>

    </body>
    </html>