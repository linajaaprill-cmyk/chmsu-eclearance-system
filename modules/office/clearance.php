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
    $feed_posts = $conn->query("SELECT id, title, content, student_id 
                                FROM chmsu_feed 
                                WHERE LOWER(office_name) = LOWER('$office_sql') $feed_sem_filter
                                ORDER BY created_at DESC");

    $student_mention_info = array(); // Map: student_id => ['post_id' => ..., 'post_title' => ...]

    while ($post = $feed_posts->fetch_assoc()) {
        $pid = intval($post['id']);
        $ptitle = $post['title'];

        if (!empty($post['student_id'])) {
            $sid = trim($post['student_id']);
            $mentioned_students[] = $sid;
            if (!isset($student_mention_info[$sid])) {
                $student_mention_info[$sid] = ['post_id' => $pid, 'post_title' => $ptitle];
            }
        }

        $content = $post['content'];

        // Individual mentions
        if (preg_match('/(?:👥|ðŸ‘¥)?\s*Mentioned:\s*(.+?)(?:\n|$)/u', $content, $m)) {
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
                        $sid = $studentMatch->fetch_assoc()['chmsu_student_id'];
                        $mentioned_students[] = $sid;
                        if (!isset($student_mention_info[$sid])) {
                            $student_mention_info[$sid] = ['post_id' => $pid, 'post_title' => $ptitle];
                        }
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
                        $sid = $studentMatch->fetch_assoc()['chmsu_student_id'];
                        $mentioned_students[] = $sid;
                        if (!isset($student_mention_info[$sid])) {
                            $student_mention_info[$sid] = ['post_id' => $pid, 'post_title' => $ptitle];
                        }
                    }
                }
            }
        }

        // Course / Program / Year mentions
        if (preg_match('/=== (?:COURSE|PROGRAM|YEAR) MENTION ===(.*?)=== END (?:COURSE|PROGRAM|YEAR) ===/s', $content, $cm)) {
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
                        $sid = $studentMatch->fetch_assoc()['chmsu_student_id'];
                        $mentioned_students[] = $sid;
                        if (!isset($student_mention_info[$sid])) {
                            $student_mention_info[$sid] = ['post_id' => $pid, 'post_title' => $ptitle];
                        }
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
                    $total_other = (int)$conn->query("SELECT COUNT(DISTINCT LOWER(TRIM(office_name))) AS c FROM chmsu_offices WHERE LOWER(TRIM(office_name)) != 'registrar'")->fetch_assoc()['c'];
                }
                $sem_clause = ($has_sem_col && $current_sem_id > 0) ? " AND semester_id = $current_sem_id " : "";

                foreach ($selected as $student_id) {

                    if (!$allow_mentioned && in_array($student_id, $mentioned_students, true)) {
                        $skipped_mentioned++;
                        continue;
                    }

                    if ($office === 'Registrar') {
                        $cleared_other = (int)$conn->query("SELECT COUNT(DISTINCT LOWER(TRIM(office_name))) AS c FROM chmsu_clearance_status
                                                            WHERE student_id='$student_id' AND LOWER(TRIM(office_name)) != 'registrar'
                                                            AND LOWER(TRIM(office_name)) IN (SELECT LOWER(TRIM(office_name)) FROM chmsu_offices)
                                                            AND status='clear'
                                                            $sem_clause
                                                           ")->fetch_assoc()['c'];
                        if ($total_other > 0 && $cleared_other < $total_other) {
                            $skipped_locked++;
                            continue;
                        }
                    } elseif ($office === 'Dean') {
                        $cleared_osa = (int)$conn->query("SELECT COUNT(*) AS c FROM chmsu_clearance_status
                                                          WHERE student_id='$student_id' AND LOWER(TRIM(office_name)) = 'osa' AND status='clear'
                                                          $sem_clause
                                                         ")->fetch_assoc()['c'];
                        if ($cleared_osa <= 0) {
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
                $lock_reason_label = ($office === 'Dean') ? 'pending OSA approval' : 'registrar lock';
                logActivity($conn, $office, 'office', "$verb $processed students" . ($skipped_mentioned ? " ($skipped_mentioned skipped — mentioned)" : "") . ($skipped_locked ? " ($skipped_locked skipped — $lock_reason_label)" : ""));
                $success = "$processed student(s) " . strtolower($verb) . " successfully!";
                if ($skipped_mentioned > 0) $success .= " — $skipped_mentioned skipped (mentioned in posts — turn on the Mentioned toggle to include them).";
                if ($skipped_locked > 0)  $success .= " — $skipped_locked skipped (" . (($office === 'Dean') ? 'pending OSA approval' : 'pending other offices') . ").";
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
    // GET STUDENTS: PENDING & PROCESSED
    // ============================================
    $filter_course = isset($_GET['filter_course']) ? $_GET['filter_course'] : '';
    $filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';
    $filter_section = isset($_GET['filter_section']) ? $_GET['filter_section'] : '';
    $filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
    $search_term = isset($_GET['search_term']) ? $_GET['search_term'] : '';

    $where_common = "WHERE s.is_archived = 0";
    if ($filter_course) $where_common .= " AND s.chmsu_course = '$filter_course'";
    if ($filter_year) $where_common .= " AND s.chmsu_year = '$filter_year'";
    if ($filter_section) $where_common .= " AND s.chmsu_section = '$filter_section'";
    if ($search_term) $where_common .= " AND (s.chmsu_last_name LIKE '%$search_term%' OR s.chmsu_first_name LIKE '%$search_term%' OR s.chmsu_full_name LIKE '%$search_term%' OR s.chmsu_student_id LIKE '%$search_term%')";

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

    // 1. Pending students
    $where_pending = $where_common . " AND (c.status IS NULL OR c.status = 'pending')";
    $pending_students = $conn->query("SELECT s.*, 
                              c.status as clearance_status, 
                              c.note as clearance_note,
                              c.updated_at as clearance_date,
                              (SELECT COUNT(*) FROM chmsu_feed WHERE LOWER(office_name)=LOWER('$office_sql_count') AND student_id=s.chmsu_student_id $mentioned_count_scope) as mentioned_count
                              FROM chmsu_students_master s
                              LEFT JOIN chmsu_clearance_status c ON $join_cond
                              $where_pending
                              ORDER BY s.chmsu_course ASC, s.chmsu_year ASC, s.chmsu_section ASC, s.chmsu_last_name ASC");

    // 2. Processed / Registrar Review students
    if ($office === 'Registrar' && $view_mode === 'review') {
        $where_review = $where_common;
        if ($filter_status === 'clear' || $filter_status === 'unclear') {
            $where_review .= " AND c.status = '$filter_status'";
        } elseif ($filter_status === 'pending') {
            $where_review .= " AND (c.status IS NULL OR c.status = 'pending')";
        }
        $processed_students = $conn->query("SELECT s.*, 
                                  c.status as clearance_status, 
                                  c.note as clearance_note,
                                  c.updated_at as clearance_date,
                                  (SELECT COUNT(*) FROM chmsu_feed WHERE LOWER(office_name)=LOWER('$office_sql_count') AND student_id=s.chmsu_student_id $mentioned_count_scope) as mentioned_count
                                  FROM chmsu_students_master s
                                  LEFT JOIN chmsu_clearance_status c ON $join_cond
                                  $where_review
                                  ORDER BY s.chmsu_course ASC, s.chmsu_year ASC, s.chmsu_section ASC, s.chmsu_last_name ASC");
    } else {
        $where_processed = $where_common . " AND c.status IN ('clear', 'unclear')";
        if ($filter_status === 'clear' || $filter_status === 'unclear') {
            $where_processed .= " AND c.status = '$filter_status'";
        }
        $processed_students = $conn->query("SELECT s.*, 
                                  c.status as clearance_status, 
                                  c.note as clearance_note,
                                  c.updated_at as clearance_date,
                                  (SELECT COUNT(*) FROM chmsu_feed WHERE LOWER(office_name)=LOWER('$office_sql_count') AND student_id=s.chmsu_student_id $mentioned_count_scope) as mentioned_count
                                  FROM chmsu_students_master s
                                  INNER JOIN chmsu_clearance_status c ON $join_cond
                                  $where_processed
                                  ORDER BY c.updated_at DESC, s.chmsu_course ASC, s.chmsu_last_name ASC");
    }

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

    // Strict Office Sort Order:
    // 1. Library -> 2. Accounting -> 3. OSA -> 4. Dean -> 5. Registrar
    $office_sort_weights = [
        'library' => 1,
        'accounting' => 2,
        'osa' => 3,
        'dean' => 4,
        'registrar' => 5
    ];

    $all_offices_list = [];
    $other_offices_list = [];
    $student_office_statuses = [];
    if ($office === 'Registrar') {
        $oof_res = $conn->query("SELECT office_name FROM chmsu_offices");
        if ($oof_res) {
            while ($ofr = $oof_res->fetch_assoc()) {
                $all_offices_list[] = $ofr['office_name'];
            }
        }
        usort($all_offices_list, function($a, $b) use ($office_sort_weights) {
            $wa = $office_sort_weights[strtolower(trim($a))] ?? 99;
            $wb = $office_sort_weights[strtolower(trim($b))] ?? 99;
            return $wa <=> $wb;
        });
        foreach ($all_offices_list as $oName) {
            if (strtolower(trim($oName)) !== 'registrar') {
                $other_offices_list[] = $oName;
            }
        }

        $sem_clause_all = ($has_sem_col && $current_sem_id > 0) ? " AND semester_id = $current_sem_id " : "";
        $all_status_res = $conn->query("SELECT student_id, office_name, status, note FROM chmsu_clearance_status WHERE 1=1 $sem_clause_all");
        if ($all_status_res) {
            while ($asr = $all_status_res->fetch_assoc()) {
                $oKey = strtolower(trim($asr['office_name']));
                $student_office_statuses[$asr['student_id']][$oKey] = [
                    'status' => $asr['status'],
                    'note'   => $asr['note']
                ];
            }
        }

        $all_office_feed_posts = [];
        $all_feed_res = $conn->query("SELECT id, office_name, title, content, student_id FROM chmsu_feed WHERE 1=1 $feed_sem_filter ORDER BY created_at DESC");
        if ($all_feed_res) {
            while ($afp = $all_feed_res->fetch_assoc()) {
                $fOKey = strtolower(trim($afp['office_name']));
                $all_office_feed_posts[$fOKey][] = $afp;
            }
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
            .office-sidebar .sidebar-menu a .chat-badge { background: #e74c3c; color: white; border-radius: 12px; padding: 2px 8px; font-size: 10px; min-width: 20px; text-align: center; float: right; }
            
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
            
            /* Registrar Office Progress Button & Popover */
            .reg-progress-btn {
                background: #eef7f2;
                color: #1b4d3e;
                border: 1px solid #b7dfca;
                padding: 3px 10px;
                border-radius: 14px;
                font-size: 11px;
                font-weight: 700;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-family: inherit;
                transition: all 0.2s;
                white-space: nowrap;
            }
            .reg-progress-btn:hover {
                background: #d4efdf;
                border-color: #27ae60;
            }
            .reg-prog-popup {
                position: absolute;
                top: calc(100% + 5px);
                left: 50%;
                transform: translateX(-50%);
                width: 250px;
                background: #ffffff;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 6px 20px rgba(0,0,0,0.18);
                padding: 10px 12px;
                z-index: 99999;
                text-align: left;
            }
            .reg-prog-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid #eee;
                padding-bottom: 4px;
                margin-bottom: 6px;
                color: #1b4d3e;
                font-size: 11.5px;
            }
            .reg-prog-close {
                cursor: pointer;
                font-size: 16px;
                color: #888;
                line-height: 1;
            }
            .reg-prog-close:hover { color: #e74c3c; }
            body.dark-mode .reg-progress-btn {
                background: #222;
                color: #f1c40f;
                border-color: #444;
            }
            body.dark-mode .reg-prog-popup {
                background: #1e1e1e;
                border-color: #444;
                color: #eee;
            }
            body.dark-mode .reg-prog-header {
                color: #f1c40f;
                border-color: #333;
            }
            
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
        <?php
        $chat_unread_count = 0;
        $chat_res = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='" . $conn->real_escape_string($office) . "'");
        if ($chat_res) {
            $chat_row = $chat_res->fetch_assoc();
            $chat_unread_count = intval($chat_row['total']);
        }
        ?>
        <div class="office-sidebar">
            <div class="sidebar-header">
                <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
                <p>Clearance Management</p>
            </div>
            <ul class="sidebar-menu">
                <li><a href="?officesection=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="?officesection=note"><i class="fas fa-rss"></i> Feed Updates</a></li>
                <li><a href="?officesection=clearance" class="active"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
                <li><a href="?officesection=history"><i class="fas fa-history"></i> Clearance History</a></li>
                <li><a href="?officesection=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li>
                    <a href="?officesection=chat" class="chat-link">
                        <i class="fas fa-comment"></i> Messages
                        <?php if ($chat_unread_count > 0): ?>
                            <span class="chat-badge"><?php echo $chat_unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
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
                    <span>Clearance &amp; mentions are scoped to this semester only.</span>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($mentioned_students)): ?>
                <div class="info-message">
                    <i class="fas fa-info-circle"></i> 
                    <strong><?php echo count($mentioned_students); ?> student(s)</strong> have been mentioned in posts this semester and are locked from Approve / Decline. Turn on the <strong>Mentioned</strong> toggle to include them.
                </div>
            <?php endif; ?>
            <?php if ($office === 'Dean'): ?>
                <div class="info-message" style="background: #e8f4fd; border-left: 4px solid #3498db; color: #1d6fa5;">
                    <i class="fas fa-shield-alt"></i> 
                    <strong>Dean Clearance Rule:</strong> You can only take action on students who have already been approved by <strong>OSA</strong>. Students pending OSA clearance are locked (<i class="fas fa-lock" style="color:#e74c3c;"></i>).
                </div>
            <?php endif; ?>
            
            <?php if ($office === 'Registrar'): 
                $toggle_base_params = $_GET;
                $toggle_base_params['view_mode'] = 'pending';
                $pending_toggle_url = '?' . http_build_query($toggle_base_params);
                $toggle_base_params['view_mode'] = 'review';
                $review_toggle_url = '?' . http_build_query($toggle_base_params);
            ?>
            <div style="display: flex; gap: 10px; margin-bottom: 18px; border-bottom: 2px solid #e1e8ed; padding-bottom: 10px;">
                <a href="<?php echo htmlspecialchars($pending_toggle_url); ?>" 
                   style="padding: 8px 18px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; <?php echo $view_mode !== 'review' ? 'background: #1b4d3e; color: #fff;' : 'background: #e2e8f0; color: #334155;'; ?>">
                    <i class="fas fa-clock"></i> Pending
                </a>
                <a href="<?php echo htmlspecialchars($review_toggle_url); ?>" 
                   style="padding: 8px 18px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; <?php echo $view_mode === 'review' ? 'background: #1b4d3e; color: #fff;' : 'background: #e2e8f0; color: #334155;'; ?>">
                    <i class="fas fa-clipboard-check"></i> Registrar Review
                </a>
            </div>
            <?php endif; ?>

            <?php if ($office !== 'Registrar' || $view_mode !== 'review'): ?>
            <!-- ============================================ -->
            <!-- 1. PENDING STUDENTS SECTION (TOP)            -->
            <!-- ============================================ -->
            <div class="content-card">
                <div class="content-card-header">
                    <span>
                        <i class="fas fa-clock"></i> Pending Students (Awaiting Action)
                    </span>
                    <span style="font-size: 11px; color: #666;">
                        Select students and approve or decline
                    </span>
                </div>
                <div class="content-card-body">
                    <form method="GET" id="filterForm">
                        <input type="hidden" name="officesection" value="clearance">
                        <?php if ($office === 'Registrar'): ?>
                            <input type="hidden" name="view_mode" value="<?php echo htmlspecialchars($view_mode); ?>">
                        <?php endif; ?>
                        <?php if (!empty($filter_status)): ?>
                            <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        <?php endif; ?>
                        <div class="filter-row">
                            <div class="search-wrapper">
                                <input type="text" name="search_term" id="searchInput" placeholder="Search by name or ID..." 
                                       value="<?php echo htmlspecialchars($search_term); ?>"
                                       autocomplete="off"
                                       oninput="handleSearchInput()" 
                                       onfocus="handleSearchFocus()" 
                                       onblur="handleSearchBlur()">
                                <div class="search-suggestions" id="searchSuggestions"></div>
                            </div>
                            
                            <div class="form-group">
                                <select name="filter_course" onchange="this.form.submit()">
                                    <option value="">All Programs</option>
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
                            <div>
                                <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filter</button>
                                <a href="?officesection=clearance" class="btn-reset"><i class="fas fa-times"></i> Clear</a>
                            </div>
                        </div>
                    </form>
                    
                    <form method="POST" id="clearanceForm">
                        <input type="hidden" name="filter_course" value="<?php echo htmlspecialchars($filter_course); ?>">
                        <input type="hidden" name="filter_year" value="<?php echo htmlspecialchars($filter_year); ?>">
                        <input type="hidden" name="filter_section" value="<?php echo htmlspecialchars($filter_section); ?>">
                        <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filter_status); ?>">
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
                    
                    <div style="overflow-x: auto; max-height: 550px; overflow-y: auto; margin-top: 15px;">
                        <table class="clearance-table" id="clearanceTable">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"></th>
                                    <th>Student ID</th>
                                    <th>Last Name</th>
                                    <th>First Name</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Section</th>
                                    <?php if ($office === 'Registrar'): ?>
                                    <th>Office Progress</th>
                                    <?php endif; ?>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $pendingRowCount = 0;
                                
                                if ($pending_students && $pending_students->num_rows > 0):
                                    while ($s = $pending_students->fetch_assoc()):
                                        $sid = $s['chmsu_student_id'];
                                        $isMentioned = in_array($sid, $mentioned_students);
                                        $status = $s['clearance_status'] ?: 'pending';
                                        $statusClass = $status == 'clear' ? 'status-cleared' : ($status == 'unclear' ? 'status-unclear' : 'status-pending');
                                        $pendingRowCount++;
                                ?>
                                <tr data-student-id="<?php echo $sid; ?>"
                                    data-name="<?php echo strtolower($s['chmsu_full_name']); ?>"
                                    data-last="<?php echo strtolower($s['chmsu_last_name']); ?>"
                                    data-first="<?php echo strtolower($s['chmsu_first_name']); ?>"
                                    data-course="<?php echo $s['chmsu_course']; ?>"
                                    data-year="<?php echo $s['chmsu_year']; ?>"
                                    data-section="<?php echo htmlspecialchars($s['chmsu_section']); ?>">
                                    <?php 
                                    $is_locked = false;
                                    if ($office == 'Registrar') {
                                        $total_other = count($other_offices_list);
                                        $cleared_by_others = 0;
                                        foreach ($other_offices_list as $oof) {
                                            $ost = $student_office_statuses[$sid][strtolower(trim($oof))]['status'] ?? 'pending';
                                            if ($ost === 'clear') {
                                                $cleared_by_others++;
                                            }
                                        }
                                        if ($cleared_by_others < $total_other || $total_other == 0) {
                                            $is_locked = true;
                                        }
                                    } elseif ($office == 'Dean') {
                                        $sem_clause_ui = ($has_sem_col && $current_sem_id > 0) ? " AND semester_id = $current_sem_id " : "";
                                        $cleared_by_osa = (int)$conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE student_id='$sid' AND LOWER(TRIM(office_name)) = 'osa' AND status='clear' $sem_clause_ui")->fetch_assoc()['c'];
                                        
                                        if ($cleared_by_osa <= 0) {
                                            $is_locked = true;
                                        }
                                    }
                                    $lock_title = ($office == 'Dean') ? 'Locked: Not yet cleared by OSA' : 'Locked: Incomplete requirements / Not yet complete';
                                    ?>

                                    <td>
                                        <?php if ($is_locked): ?>
                                            <div style="color: #e74c3c; font-size: 16px; text-align: center; cursor: not-allowed;" title="<?php echo htmlspecialchars($lock_title); ?>">
                                                <i class="fas fa-lock"></i>
                                            </div>
                                        <?php else: ?>
                                            <input type="checkbox" name="selected_students[]" value="<?php echo $sid; ?>" 
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
                                    <td><code><?php echo $sid; ?></code></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_first_name']); ?></td>
                                    <td><?php echo $s['chmsu_course']; ?></td>
                                    <td><?php echo $s['chmsu_year']; ?></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_section']); ?></td>

                                    <?php if ($office === 'Registrar'): ?>
                                    <td class="reg-progress-cell" style="position: relative; text-align: center;">
                                        <?php 
                                        $tot = count($all_offices_list);
                                        $clr = 0;
                                        foreach ($all_offices_list as $oof) {
                                            $ost = $student_office_statuses[$sid][strtolower(trim($oof))]['status'] ?? 'pending';
                                            if ($ost === 'clear') $clr++;
                                        }
                                        ?>
                                        <button type="button" class="reg-progress-btn" onclick="toggleRegProg(event, '<?php echo $sid; ?>')">
                                            <i class="fas fa-tasks"></i> <?php echo "$clr / $tot"; ?> <i class="fas fa-caret-down"></i>
                                        </button>
                                        <div id="prog_popup_<?php echo $sid; ?>" class="reg-prog-popup" style="display: none;">
                                            <div class="reg-prog-header">
                                                <span><strong>Office Clearances</strong></span>
                                                <span class="reg-prog-close" onclick="closeRegProg('<?php echo $sid; ?>')">&times;</span>
                                            </div>
                                            <div style="font-size: 11px; margin-bottom: 6px; color: #555;">
                                                <strong><?php echo htmlspecialchars($s['chmsu_full_name']); ?></strong> (<?php echo $sid; ?>)
                                            </div>
                                            <div class="reg-prog-list" style="display: flex; flex-direction: column; gap: 4px;">
                                                <?php foreach ($all_offices_list as $oof): 
                                                    $ost = $student_office_statuses[$sid][strtolower(trim($oof))]['status'] ?? 'pending';
                                                    if ($ost === 'clear') {
                                                        $badge_bg = '#d4efdf';
                                                        $badge_fg = '#1e8449';
                                                        $badge_txt = 'Approved';
                                                        $badge_icon = 'fa-check-circle';
                                                    } elseif ($ost === 'unclear') {
                                                        $badge_bg = '#fadbd8';
                                                        $badge_fg = '#c0392b';
                                                        $badge_txt = 'Declined';
                                                        $badge_icon = 'fa-times-circle';
                                                    } else {
                                                        $badge_bg = '#fdebd0';
                                                        $badge_fg = '#b9770e';
                                                        $badge_txt = 'Pending';
                                                        $badge_icon = 'fa-clock';
                                                    }
                                                ?>
                                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 2px 0; border-bottom: 1px dashed #eee;">
                                                    <span style="font-size: 11px; color: #333; font-weight: 500;"><?php echo htmlspecialchars($oof); ?></span>
                                                    <span style="background: <?php echo $badge_bg; ?>; color: <?php echo $badge_fg; ?>; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; display: inline-flex; align-items: center; gap: 3px;">
                                                        <i class="fas <?php echo $badge_icon; ?>" style="font-size: 9px;"></i> <?php echo $badge_txt; ?>
                                                    </span>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <?php endif; ?>

                                    <td>
                                        <span class="status-badge status-pending">Pending</span>
                                    </td>
                                    <td>
                                        <?php 
                                        $has_note_content = false;
                                        // 1. Lock Reason for Dean and Registrar
                                        if ($is_locked): 
                                            $has_note_content = true;
                                            if ($office === 'Dean'): ?>
                                                <div style="color: #c0392b; font-size: 10.5px; font-weight: bold; margin-bottom: 3px;">
                                                    <i class="fas fa-lock"></i> Locked: Not yet cleared by OSA
                                                </div>
                                            <?php elseif ($office === 'Registrar'): ?>
                                                <div style="color: #c0392b; font-size: 10.5px; font-weight: bold; margin-bottom: 3px;">
                                                    <i class="fas fa-lock"></i> Locked: Incomplete requirements / Not yet complete
                                                </div>
                                            <?php else: ?>
                                                <div style="color: #c0392b; font-size: 10.5px; font-weight: bold; margin-bottom: 3px;">
                                                    <i class="fas fa-lock"></i> Locked
                                                </div>
                                            <?php endif; 
                                        endif;

                                        // 2. Mentioned in Feed Link (opens in SAME tab with red student highlight)
                                        if ($isMentioned && !empty($student_mention_info[$sid])): 
                                            $has_note_content = true;
                                            $m_post = $student_mention_info[$sid];
                                            $feed_target_url = ($office === 'Registrar') 
                                                ? "index.php?section=note&highlight_student=" . urlencode($sid) . "&highlight_name=" . urlencode($s['chmsu_full_name']) . "#post-" . $m_post['post_id'] 
                                                : "index.php?officesection=note&highlight_student=" . urlencode($sid) . "&highlight_name=" . urlencode($s['chmsu_full_name']) . "#post-" . $m_post['post_id'];
                                        ?>
                                            <div style="margin-bottom: 3px;">
                                                <a href="<?php echo htmlspecialchars($feed_target_url); ?>" 
                                                   title="View post: <?php echo htmlspecialchars($m_post['post_title']); ?>"
                                                   style="display: inline-flex; align-items: center; gap: 3px; color: #d35400; font-weight: bold; font-size: 10.5px; text-decoration: underline; background: #fef5e7; padding: 1px 6px; border-radius: 3px; border: 1px solid #f8c471;">
                                                    <i class="fas fa-bullhorn"></i> Mentioned in Feed <i class="fas fa-arrow-right" style="font-size: 8px;"></i>
                                                </a>
                                            </div>
                                        <?php endif;

                                        // 3. Fallback: empty if no notes
                                        if (!$has_note_content): ?>
                                            <!-- empty when no note/lock/mention -->
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <tr>
                                    <td colspan="<?php echo $office === 'Registrar' ? '10' : '9'; ?>" class="empty-state">
                                        <i class="fas fa-check-circle"></i>
                                        No pending students. All students have been processed!
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 10px; font-size: 11px; color: #666;">
                        <span id="totalCount"><?php echo $pendingRowCount; ?></span> pending students
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php $isRegReview = ($office === 'Registrar' && $view_mode === 'review'); ?>
            <!-- ============================================ -->
            <!-- 2. FOR REGISTRAR REVIEW / PROCESSED SECTION   -->
            <!-- ============================================ -->
            <div class="content-card" style="margin-top: <?php echo $isRegReview ? '0' : '25px'; ?>;">
                <div class="content-card-header" style="background: #fdfefe; border-bottom: 2px solid <?php echo $isRegReview ? '#1b4d3e' : '#27ae60'; ?>;">
                    <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <?php if ($isRegReview): ?>
                                <span><i class="fas fa-clipboard-check" style="color: #1b4d3e;"></i> For Registrar Review</span>
                                <span style="font-size: 11px; color: #666; margin-left: 8px;">
                                    (<span id="procVisibleCount"><?php echo $processed_students ? $processed_students->num_rows : 0; ?></span> of <span id="procTotalCount"><?php echo $processed_students ? $processed_students->num_rows : 0; ?></span> students)
                                </span>
                            <?php else: ?>
                                <span><i class="fas fa-check-double" style="color: #27ae60;"></i> Processed Students (Approved &amp; Declined)</span>
                                <span style="font-size: 11px; color: #666; margin-left: 8px;">
                                    (<span id="procVisibleCount"><?php echo $processed_students ? $processed_students->num_rows : 0; ?></span> of <span id="procTotalCount"><?php echo $processed_students ? $processed_students->num_rows : 0; ?></span> students processed)
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 11px; color: #7f8c8d;" id="procFilterNotice" style="display: none;">
                            <i class="fas fa-filter"></i> Filtered results
                        </div>
                    </div>
                </div>
                <div class="content-card-body">
                    <!-- REAL-TIME INSTANT AUTO FILTER BAR -->
                    <div style="background: #f8fafc; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 12px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <div style="flex: 1; min-width: 170px;">
                            <input type="text" id="autoFilterProcSearch" oninput="autoFilterProcessedTable()" placeholder="🔍 Search Student ID or Name..." style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; font-family: inherit;">
                        </div>
                        <div>
                            <select id="autoFilterProcCourse" onchange="autoFilterProcessedTable()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; font-family: inherit;">
                                <option value="">All Programs</option>
                                <?php foreach ($allCourses as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select id="autoFilterProcYear" onchange="autoFilterProcessedTable()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; font-family: inherit;">
                                <option value="">All Years</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>
                        <div>
                            <select id="autoFilterProcSection" onchange="autoFilterProcessedTable()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; font-family: inherit;">
                                <option value="">All Sections</option>
                                <?php 
                                if ($sections && $sections->num_rows > 0) {
                                    $sections->data_seek(0);
                                    while ($sec = $sections->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($sec['section_name']) . '">' . htmlspecialchars($sec['section_name']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <select id="autoFilterProcDecision" onchange="autoFilterProcessedTable()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; font-family: inherit;">
                                <option value="">All Decisions</option>
                                <?php if ($isRegReview): ?>
                                    <option value="pending">Pending Only</option>
                                <?php endif; ?>
                                <option value="clear">Approved Only</option>
                                <option value="unclear">Declined Only</option>
                            </select>
                        </div>
                        <div>
                            <button type="button" onclick="resetAutoFilterProcessedTable()" class="btn btn-sm" style="background: #e2e8f0; color: #334155; padding: 6px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer;">
                                <i class="fas fa-undo"></i> Reset Filters
                            </button>
                        </div>
                    </div>

                    <?php if (!$isRegReview): ?>
                    <form method="POST" id="revertForm">
                        <input type="hidden" name="filter_course" value="<?php echo htmlspecialchars($filter_course); ?>">
                        <input type="hidden" name="filter_year" value="<?php echo htmlspecialchars($filter_year); ?>">
                        <input type="hidden" name="filter_section" value="<?php echo htmlspecialchars($filter_section); ?>">
                        <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        <input type="hidden" name="search_term" value="<?php echo htmlspecialchars($search_term); ?>">
                        <input type="hidden" name="selected_csv" id="selectedCsvRevert" value="">
                        
                        <div class="action-bar" style="border-top: 1px solid #e1e8ed; border-bottom: 1px solid #e1e8ed; padding: 10px 0; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <div class="select-all">
                                    <input type="checkbox" id="selectAllProcessed" onclick="toggleAllCheckboxesProcessed()">
                                    <label for="selectAllProcessed">Select All</label>
                                </div>
                                <button type="submit" name="action" value="revert_selected" class="btn btn-undo" style="padding: 6px 14px; font-size: 12px;" onclick="return confirmRevertProc(event)">
                                    <i class="fas fa-undo"></i> Undo Selected (Back to Pending)
                                </button>
                                <span style="font-size: 11.5px; color: #555;">
                                    <span id="selectedCountProcessed">0</span> selected
                                </span>
                            </div>
                            <span style="font-size: 11px; color: #e67e22;">
                                <i class="fas fa-info-circle"></i> Undo will move students back to the Pending list above
                            </span>
                        </div>
                    </form>
                    <?php endif; ?>

                    <div style="overflow-x: auto; max-height: 550px; overflow-y: auto;">
                        <table class="clearance-table" id="processedTable">
                            <thead>
                                <tr>
                                    <?php if (!$isRegReview): ?>
                                    <th style="width: 30px;"></th>
                                    <?php endif; ?>
                                    <th>Student ID</th>
                                    <th>Last Name</th>
                                    <th>First Name</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Section</th>
                                    <?php if ($isRegReview): ?>
                                        <?php foreach ($all_offices_list as $oof): ?>
                                            <th style="text-align: center; white-space: nowrap;"><?php echo htmlspecialchars($oof); ?></th>
                                        <?php endforeach; ?>
                                        <th style="text-align: center; white-space: nowrap;">Overall Progress</th>
                                    <?php endif; ?>
                                    <th>Decision</th>
                                    <?php if (!$isRegReview): ?>
                                    <th>Notes / Reason</th>
                                    <th>Action Date</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $procCount = 0;
                                if ($processed_students && $processed_students->num_rows > 0):
                                    while ($ps = $processed_students->fetch_assoc()):
                                        $psid = $ps['chmsu_student_id'];
                                        $pStatus = $ps['clearance_status'] ?: ($isRegReview ? 'pending' : 'clear');
                                        $pStatusClass = $pStatus == 'clear' ? 'status-cleared' : ($pStatus == 'unclear' ? 'status-unclear' : 'status-pending');
                                        $procCount++;
                                ?>
                                <tr class="proc-row" 
                                    data-student-id="<?php echo $psid; ?>" 
                                    data-student-name="<?php echo htmlspecialchars(strtolower($ps['chmsu_full_name'] . ' ' . $ps['chmsu_last_name'] . ' ' . $ps['chmsu_first_name'])); ?>" 
                                    data-course="<?php echo htmlspecialchars($ps['chmsu_course']); ?>" 
                                    data-year="<?php echo htmlspecialchars($ps['chmsu_year']); ?>" 
                                    data-section="<?php echo htmlspecialchars($ps['chmsu_section']); ?>" 
                                    data-decision="<?php echo $pStatus; ?>">
                                    <?php if (!$isRegReview): ?>
                                    <td>
                                        <input type="checkbox" name="selected_students[]" value="<?php echo $psid; ?>" 
                                               class="student-checkbox-processed" form="revertForm"
                                               onchange="updateSelectedCountProcessed()">
                                    </td>
                                    <?php endif; ?>
                                    <td><code><?php echo $psid; ?></code></td>
                                    <td><?php echo htmlspecialchars($ps['chmsu_last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($ps['chmsu_first_name']); ?></td>
                                    <td><?php echo $ps['chmsu_course']; ?></td>
                                    <td><?php echo $ps['chmsu_year']; ?></td>
                                    <td><?php echo htmlspecialchars($ps['chmsu_section']); ?></td>

                                    <?php if ($isRegReview): ?>
                                        <?php 
                                        $tot = count($all_offices_list);
                                        $clr = 0;
                                        foreach ($all_offices_list as $oof) {
                                            $oKey = strtolower(trim($oof));
                                            $ost = $student_office_statuses[$psid][$oKey]['status'] ?? 'pending';
                                            $onote = trim($student_office_statuses[$psid][$oKey]['note'] ?? '');
                                            if ($ost === 'clear') {
                                                $clr++;
                                                $badge_bg = '#d4efdf';
                                                $badge_fg = '#1e8449';
                                                $badge_txt = 'Approved';
                                                $badge_icon = 'fa-check';
                                            } elseif ($ost === 'unclear') {
                                                $badge_bg = '#fadbd8';
                                                $badge_fg = '#c0392b';
                                                $badge_txt = 'Declined';
                                                $badge_icon = 'fa-times';
                                            } else {
                                                $badge_bg = '#fef9e7';
                                                $badge_fg = '#b7950b';
                                                $badge_txt = 'Pending';
                                                $badge_icon = 'fa-clock';
                                            }

                                            // Build exact connected reasons for this office
                                            $offReasons = [];
                                            if ($ost === 'clear') {
                                                $offReasons[] = ['icon' => 'fa-check-circle', 'color' => '#1e8449', 'bg' => '#d4efdf', 'text' => "Cleared by $oof."];
                                            } elseif ($ost === 'unclear') {
                                                $offReasons[] = ['icon' => 'fa-times-circle', 'color' => '#c0392b', 'bg' => '#fadbd8', 'text' => !empty($onote) ? "Declined: $onote" : "Declined by $oof."];
                                            } else {
                                                // Check feed mentions from this office
                                                if (!empty($all_office_feed_posts[$oKey])) {
                                                    $stuFull = trim($ps['chmsu_full_name'] ?? '');
                                                    $stuLF = trim(($ps['chmsu_last_name'] ?? '') . ', ' . ($ps['chmsu_first_name'] ?? ''));
                                                    foreach ($all_office_feed_posts[$oKey] as $fp) {
                                                        $hit = false;
                                                        if (!empty($fp['student_id']) && trim($fp['student_id']) === $psid) $hit = true;
                                                        elseif ($stuFull !== '' && stripos($fp['content'], $stuFull) !== false) $hit = true;
                                                        elseif ($stuLF !== ', ' && stripos($fp['content'], $stuLF) !== false) $hit = true;
                                                        if ($hit) {
                                                            $offReasons[] = ['icon' => 'fa-bullhorn', 'color' => '#d35400', 'bg' => '#fef5e7', 'text' => 'Mentioned in Feed: "' . $fp['title'] . '"'];
                                                            break;
                                                        }
                                                    }
                                                }
                                                if ($oKey === 'dean' && ($student_office_statuses[$psid]['osa']['status'] ?? 'pending') !== 'clear') {
                                                    $offReasons[] = ['icon' => 'fa-lock', 'color' => '#856404', 'bg' => '#fff3cd', 'text' => 'Locked: Waiting for OSA approval first.'];
                                                }
                                                if ($oKey === 'registrar') {
                                                    $pendingOthers = [];
                                                    foreach ($other_offices_list as $oo) {
                                                        if (($student_office_statuses[$psid][strtolower(trim($oo))]['status'] ?? 'pending') !== 'clear') {
                                                            $pendingOthers[] = $oo;
                                                        }
                                                    }
                                                    if (!empty($pendingOthers)) {
                                                        $offReasons[] = ['icon' => 'fa-lock', 'color' => '#856404', 'bg' => '#fff3cd', 'text' => 'Locked: Pending ' . implode(', ', $pendingOthers) . '.'];
                                                    }
                                                }
                                                if (!empty($onote)) {
                                                    $offReasons[] = ['icon' => 'fa-info-circle', 'color' => '#334155', 'bg' => '#f1f5f9', 'text' => $onote];
                                                }
                                                if (empty($offReasons)) {
                                                    $offReasons[] = ['icon' => 'fa-clock', 'color' => '#b7950b', 'bg' => '#fef9e7', 'text' => "Awaiting review and approval from $oof."];
                                                }
                                            }
                                            $popKey = 'off_' . $psid . '_' . $oKey;
                                        ?>
                                        <td class="reg-progress-cell" style="position: relative; text-align: center; white-space: nowrap;">
                                            <button type="button" onclick="toggleRegProg(event, '<?php echo $popKey; ?>')"
                                                    title="Tap to view <?php echo htmlspecialchars($oof); ?> reason"
                                                    style="background: <?php echo $badge_bg; ?>; color: <?php echo $badge_fg; ?>; padding: 3px 7px; border-radius: 4px; font-size: 10px; font-weight: bold; display: inline-flex; align-items: center; gap: 3px; border: 1px solid rgba(0,0,0,0.08); cursor: pointer;">
                                                <i class="fas <?php echo $badge_icon; ?>" style="font-size: 8.5px;"></i> <?php echo $badge_txt; ?>
                                            </button>
                                            <div id="prog_popup_<?php echo $popKey; ?>" class="reg-prog-popup" style="display: none; min-width: 220px; white-space: normal; text-align: left;">
                                                <div class="reg-prog-header">
                                                    <span><strong><?php echo htmlspecialchars($oof); ?> — <?php echo $badge_txt; ?></strong></span>
                                                    <span class="reg-prog-close" onclick="closeRegProg('<?php echo $popKey; ?>')">&times;</span>
                                                </div>
                                                <div style="font-size: 11px; margin-bottom: 6px; color: #555;">
                                                    <strong><?php echo htmlspecialchars($ps['chmsu_full_name']); ?></strong> (<?php echo $psid; ?>)
                                                </div>
                                                <div style="display: flex; flex-direction: column; gap: 5px;">
                                                    <?php foreach ($offReasons as $or): ?>
                                                    <div style="background: <?php echo $or['bg']; ?>; color: <?php echo $or['color']; ?>; padding: 5px 7px; border-radius: 4px; font-size: 10.5px; line-height: 1.35;">
                                                        <i class="fas <?php echo $or['icon']; ?>"></i> <?php echo htmlspecialchars($or['text']); ?>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <?php } ?>

                                        <td style="text-align: center; font-weight: bold; font-size: 12px; color: <?php echo ($clr == $tot) ? '#1e8449' : '#d35400'; ?>;">
                                            <?php echo "$clr / $tot"; ?>
                                        </td>
                                    <?php endif; ?>

                                    <td>
                                        <span class="status-badge <?php echo $pStatusClass; ?>">
                                            <?php echo $pStatus == 'clear' ? 'Approved' : ($pStatus == 'unclear' ? 'Declined' : 'Pending'); ?>
                                        </span>
                                    </td>
                                    <?php if (!$isRegReview): ?>
                                    <td>
                                        <?php if ($pStatus == 'unclear' && !empty($ps['clearance_note'])): ?>
                                            <div style="color: #c0392b; font-size: 10.5px; margin-bottom: 2px;">
                                                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($ps['clearance_note']); ?>
                                            </div>
                                        <?php elseif ($pStatus == 'clear'): ?>
                                            <div style="color: #27ae60; font-size: 10.5px; font-weight: 500;">✓ Approved</div>
                                        <?php endif; ?>

                                        <?php if ($pStatus !== 'clear' && in_array($psid, $mentioned_students)):
                                            $m_post = $student_mention_info[$psid] ?? null;
                                            $feed_target_url = ($office === 'Registrar')
                                                ? "index.php?section=note&highlight_student=" . urlencode($psid) . "&highlight_name=" . urlencode($ps['chmsu_full_name']) . ($m_post ? "#post-" . $m_post['post_id'] : "")
                                                : "index.php?officesection=note&highlight_student=" . urlencode($psid) . "&highlight_name=" . urlencode($ps['chmsu_full_name']) . ($m_post ? "#post-" . $m_post['post_id'] : "");
                                        ?>
                                            <div style="margin-top: 2px;">
                                                <a href="<?php echo htmlspecialchars($feed_target_url); ?>" 
                                                   style="display: inline-flex; align-items: center; gap: 3px; color: #d35400; font-weight: bold; font-size: 10px; text-decoration: underline; background: #fef5e7; padding: 1px 5px; border-radius: 3px; border: 1px solid #f8c471;">
                                                    <i class="fas fa-bullhorn"></i> Mentioned in Feed
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 11px; color: #666; white-space: nowrap;">
                                        <?php echo !empty($ps['clearance_date']) ? date('M d, Y h:i A', strtotime($ps['clearance_date'])) : '—'; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php 
                                    endwhile;
                                else:
                                    $procColspan = $isRegReview ? (8 + count($all_offices_list)) : 10;
                                ?>
                                <tr>
                                    <td colspan="<?php echo $procColspan; ?>" class="empty-state">
                                        <i class="fas fa-inbox"></i> No processed students found.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top: 10px; font-size: 11px; color: #666;">
                        <span><?php echo $procCount; ?></span> total students in this section
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
            alert('Please select at least one student.');
            return false;
        }
        return true;
    }

    function autoFilterProcessedTable() {
        var searchInput = document.getElementById('autoFilterProcSearch');
        var courseInput = document.getElementById('autoFilterProcCourse');
        var yearInput = document.getElementById('autoFilterProcYear');
        var sectionInput = document.getElementById('autoFilterProcSection');
        var decisionInput = document.getElementById('autoFilterProcDecision');

        var search = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var course = courseInput ? courseInput.value.trim().toUpperCase() : '';
        var year = yearInput ? yearInput.value.trim() : '';
        var section = sectionInput ? sectionInput.value.trim().toLowerCase() : '';
        var decision = decisionInput ? decisionInput.value.trim().toLowerCase() : '';

        var rows = document.querySelectorAll('#processedTable tbody tr.proc-row');
        var visibleCount = 0;

        rows.forEach(function(row) {
            var rSid = (row.getAttribute('data-student-id') || '').toLowerCase();
            var rName = (row.getAttribute('data-student-name') || '').toLowerCase();
            var rCourse = (row.getAttribute('data-course') || '').toUpperCase();
            var rYear = (row.getAttribute('data-year') || '');
            var rSection = (row.getAttribute('data-section') || '').toLowerCase();
            var rDecision = (row.getAttribute('data-decision') || '').toLowerCase();

            var matchSearch = !search || (rSid.indexOf(search) !== -1 || rName.indexOf(search) !== -1);
            var matchCourse = !course || (rCourse === course);
            var matchYear = !year || (rYear === year);
            var matchSection = !section || (rSection === section);
            var matchDecision = !decision || (rDecision === decision);

            if (matchSearch && matchCourse && matchYear && matchSection && matchDecision) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        var countSpan = document.getElementById('procVisibleCount');
        if (countSpan) countSpan.textContent = visibleCount;
        var notice = document.getElementById('procFilterNotice');
        if (notice) {
            var isFiltered = search || course || year || section || decision;
            notice.style.display = isFiltered ? 'inline' : 'none';
        }
    }

    function resetAutoFilterProcessedTable() {
        if (document.getElementById('autoFilterProcSearch')) document.getElementById('autoFilterProcSearch').value = '';
        if (document.getElementById('autoFilterProcCourse')) document.getElementById('autoFilterProcCourse').value = '';
        if (document.getElementById('autoFilterProcYear')) document.getElementById('autoFilterProcYear').value = '';
        if (document.getElementById('autoFilterProcSection')) document.getElementById('autoFilterProcSection').value = '';
        if (document.getElementById('autoFilterProcDecision')) document.getElementById('autoFilterProcDecision').value = '';
        autoFilterProcessedTable();
    }

    function toggleDeclineReasonProc() {
        var div = document.getElementById('declineReasonDivProc');
        if (div) {
            div.style.display = (div.style.display === 'none' || div.style.display === '') ? 'block' : 'none';
            if (div.style.display === 'block') {
                var ta = document.getElementById('declineReasonProc');
                if (ta) ta.focus();
            }
        }
    }

    function confirmApproveProc(event) {
        const checkboxes = document.querySelectorAll('.student-checkbox-processed:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one student to approve.');
            event.preventDefault();
            return false;
        }
        return confirm('Are you sure you want to approve ' + checkboxes.length + ' selected student(s)?');
    }

    function confirmDeclineProc(event) {
        const checkboxes = document.querySelectorAll('.student-checkbox-processed:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one student to decline.');
            event.preventDefault();
            return false;
        }
        const reason = document.getElementById('declineReasonProc') ? document.getElementById('declineReasonProc').value.trim() : '';
        if (!reason) {
            alert('Please enter a reason for declination.');
            if (document.getElementById('declineReasonProc')) document.getElementById('declineReasonProc').focus();
            event.preventDefault();
            return false;
        }
        return confirm('Are you sure you want to decline ' + checkboxes.length + ' selected student(s)?\n\nReason: ' + reason);
    }

    function confirmRevertProc(event) {
        const checkboxes = document.querySelectorAll('.student-checkbox-processed:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one student to undo.');
            event.preventDefault();
            return false;
        }
        return confirm('Are you sure you want to undo the decision for ' + checkboxes.length + ' selected student(s)?\n\nThey will be moved back to the Pending list above.');
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

    function toggleRegProg(event, sid) {
        if (event) event.stopPropagation();
        var popup = document.getElementById('prog_popup_' + sid);
        if (!popup) return;
        var isShown = popup.style.display !== 'none';
        document.querySelectorAll('.reg-prog-popup').forEach(function(p) { p.style.display = 'none'; });
        popup.style.display = isShown ? 'none' : 'block';
    }

    function closeRegProg(sid) {
        var popup = document.getElementById('prog_popup_' + sid);
        if (popup) popup.style.display = 'none';
    }

    function applyProcessedFilter(val) {
        var url = new URL(window.location.href);
        if (val) {
            url.searchParams.set('filter_status', val);
        } else {
            url.searchParams.delete('filter_status');
        }
        window.location.href = url.toString();
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.reg-progress-cell')) {
            document.querySelectorAll('.reg-prog-popup').forEach(function(p) { p.style.display = 'none'; });
        }
    });
    </script>

    </body>
    </html>