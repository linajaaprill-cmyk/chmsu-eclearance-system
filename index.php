<?php
require_once __DIR__ . '/includes/load_env.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Handle actions
$error = '';
$success = '';
handleActions($conn, $error, $success);

// ============================================
// GOOGLE OAUTH - SUPPORT ADMIN, OFFICE, REGISTRAR, AND STUDENT
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
        
        // Check if ADMIN is logged in
        if (isset($_SESSION['admin'])) {
            $admin = $_SESSION['admin'];
            $conn->query("UPDATE chmsu_admin_users SET email='$email', otp_enabled=1 WHERE username='$admin'");
            logActivity($conn, $admin, 'admin', "Connected Gmail: $email");
            $success = "✅ Gmail connected successfully!";
            header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=dashboard&gmail_connected=1");
            exit;
        }
        // Check if STUDENT is logged in
        elseif (isset($_SESSION['student'])) {
            $student_id = $_SESSION['student'];
            $conn->query("UPDATE chmsu_user_accounts SET 
                          email='$email' 
                          WHERE chmsu_student_id='$student_id'");
            logActivity($conn, $student_id, 'student', "Connected Gmail: $email");
            $success = "✅ Gmail connected successfully!";
            header("Location: " . $_SERVER['PHP_SELF'] . "?view=home&gmail_connected=1");
            exit;
        }
        // Check if OFFICE is logged in (includes Registrar)
        elseif (isset($_SESSION['office'])) {
            $office = $_SESSION['office'];
            $conn->query("UPDATE chmsu_auth_roles SET email='$email', otp_enabled=1 WHERE chmsu_role='$office'");
            logActivity($conn, $office, 'office', "Connected Gmail: $email");
            $success = "✅ Gmail connected successfully!";
            
            // Check if user is Registrar
            if ($office == 'Registrar') {
                header("Location: " . $_SERVER['PHP_SELF'] . "?section=dashboard&gmail_connected=1");
            } else {
                header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=dashboard&gmail_connected=1");
            }
            exit;
        }
    } else {
        $error = "Failed to connect Gmail. Please try again.";
        // Redirect based on user type
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

// Handle export activity for Admin
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

// Get comments via AJAX
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

// ============================================
// AJAX endpoint to get sections based on course and year (for Requirements)
// ============================================
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

// ============================================
// AJAX endpoint to get sections for Add Student form in Masterlist
// ============================================
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

// ============================================
// AJAX endpoint to get sections for FILTER dropdown in Masterlist
// ============================================
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
// OTP AJAX HANDLERS - SUPPORT ADMIN, OFFICE, REGISTRAR, AND STUDENT
// ============================================

// ============================================
// AJAX: CHECK OTP STATUS
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
        // Students don't have OTP enabled by default
        echo json_encode(['otp_enabled' => false]);
    }
    exit;
}

// ============================================
// AJAX: SEND OTP
// ============================================
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

// ============================================
// AJAX: VERIFY OTP
// ============================================
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

// ============================================
// AJAX: RESEND OTP
// ============================================
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
// STUDENT CHAT ACTION HANDLERS
// ============================================
if (isset($_POST['action']) && isset($_SESSION['student'])) {
    $action = $_POST['action'];
    
    // Send chat message (Student to Office)
    if ($action == 'send_chat_message' && isset($_SESSION['student'])) {
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
        
        $conn->query("INSERT INTO chmsu_chat_conversations (student_id, office_name, last_message, last_message_time, office_unread) 
                      VALUES ('$student_id', '$office_name', '$message', NOW(), 1)
                      ON DUPLICATE KEY UPDATE 
                      last_message = '$message', 
                      last_message_time = NOW(),
                      office_unread = office_unread + 1");
        
        logActivity($conn, $student_id, 'student', "Sent chat message to office: $office_name");
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Get chat messages (AJAX)
    if ($action == 'get_chat_messages' && isset($_SESSION['student'])) {
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
    if ($action == 'mark_chat_read' && isset($_SESSION['student'])) {
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
    if ($action == 'clear_chat' && isset($_SESSION['student'])) {
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
    if ($action == 'get_unread_count' && isset($_SESSION['student'])) {
        $student_id = $_SESSION['student'];
        $total = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='$student_id'")->fetch_assoc()['total'];
        echo json_encode(['total' => $total ?: 0]);
        exit;
    }
}

// ============================================
// OFFICE CHAT ACTION HANDLERS
// ============================================
if (isset($_POST['action']) && isset($_SESSION['office'])) {
    $action = $_POST['action'];
    $office = $_SESSION['office'];
    
    // Send chat message (Office to Student)
    if ($action == 'send_chat_message' && isset($_SESSION['office'])) {
        $student_id = sanitize($_POST['student_id']);
        $message = sanitize($_POST['message']);
        $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'individual';
        
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
                      VALUES ('office', '$office', '$student_id', '$message', '$file_path', '$file_name', '$file_type')");
        
        if ($chat_type == 'individual') {
            $conn->query("INSERT INTO chmsu_chat_conversations (student_id, office_name, last_message, last_message_time, student_unread) 
                          VALUES ('$student_id', '$office', '$message', NOW(), 1)
                          ON DUPLICATE KEY UPDATE 
                          last_message = '$message', 
                          last_message_time = NOW(),
                          student_unread = student_unread + 1");
        } else {
            // Group chat
            $year = isset($_POST['year']) ? sanitize($_POST['year']) : '';
            $section = isset($_POST['section']) ? sanitize($_POST['section']) : '';
            
            if ($year && $section) {
                $groupStudents = $conn->query("SELECT chmsu_student_id FROM chmsu_students_master 
                                               WHERE chmsu_year = '$year' AND chmsu_section = '$section' AND is_archived = 0");
                while ($gs = $groupStudents->fetch_assoc()) {
                    $sid = $gs['chmsu_student_id'];
                    $conn->query("INSERT INTO chmsu_chat_conversations (student_id, office_name, last_message, last_message_time, student_unread) 
                                  VALUES ('$sid', '$office', '$message', NOW(), 1)
                                  ON DUPLICATE KEY UPDATE 
                                  last_message = '$message', 
                                  last_message_time = NOW(),
                                  student_unread = student_unread + 1");
                }
            }
        }
        
        logActivity($conn, $office, 'office', "Sent chat message to: $student_id");
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Get chat messages (AJAX) - Office
    if ($action == 'get_chat_messages' && isset($_SESSION['office'])) {
        $student_id = sanitize($_POST['student_id']);
        
        $messages = $conn->query("SELECT * FROM chmsu_chat_messages 
                                  WHERE (sender_type='office' AND sender_id='$office' AND receiver_id='$student_id')
                                  OR (sender_type='student' AND sender_id='$student_id' AND receiver_id='$office')
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
    
    // Get group chat messages (AJAX) - Office
    if ($action == 'get_group_chat_messages' && isset($_SESSION['office'])) {
        $year = isset($_POST['year']) ? sanitize($_POST['year']) : '';
        $section = isset($_POST['section']) ? sanitize($_POST['section']) : '';
        
        if ($year && $section) {
            $groupIds = [];
            $groupStudents = $conn->query("SELECT chmsu_student_id, chmsu_full_name FROM chmsu_students_master 
                                           WHERE chmsu_year = '$year' AND chmsu_section = '$section' AND is_archived = 0");
            while ($gs = $groupStudents->fetch_assoc()) {
                $groupIds[] = "'" . $gs['chmsu_student_id'] . "'";
            }
            
            if (!empty($groupIds)) {
                $idsList = implode(',', $groupIds);
                $messages = $conn->query("SELECT m.*, 
                                          CASE 
                                              WHEN m.sender_type = 'student' THEN s.chmsu_full_name 
                                              ELSE '$office' 
                                          END as sender_name
                                          FROM chmsu_chat_messages m
                                          LEFT JOIN chmsu_students_master s ON m.sender_id = s.chmsu_student_id
                                          WHERE (sender_type='office' AND sender_id='$office' AND receiver_id IN ($idsList))
                                          OR (sender_type='student' AND sender_id IN ($idsList) AND receiver_id='$office')
                                          ORDER BY created_at ASC");
                
                $result = [];
                while ($msg = $messages->fetch_assoc()) {
                    $result[] = [
                        'id' => $msg['id'],
                        'sender_type' => $msg['sender_type'],
                        'sender_name' => $msg['sender_name'],
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
        }
        echo json_encode([]);
        exit;
    }
    
    // Mark messages as read (Office)
    if ($action == 'mark_chat_read' && isset($_SESSION['office'])) {
        $student_id = sanitize($_POST['student_id']);
        
        $conn->query("UPDATE chmsu_chat_messages SET is_read = 1 
                      WHERE sender_type='student' AND sender_id='$student_id' AND receiver_id='$office'");
        $conn->query("UPDATE chmsu_chat_conversations SET office_unread = 0 
                      WHERE student_id='$student_id' AND office_name='$office'");
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Clear chat history (Office)
    if ($action == 'clear_chat' && isset($_SESSION['office'])) {
        $student_id = sanitize($_POST['student_id']);
        $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'individual';
        
        if ($chat_type == 'individual') {
            $conn->query("DELETE FROM chmsu_chat_messages 
                          WHERE (sender_type='office' AND sender_id='$office' AND receiver_id='$student_id')
                          OR (sender_type='student' AND sender_id='$student_id' AND receiver_id='$office')");
            $conn->query("DELETE FROM chmsu_chat_conversations 
                          WHERE student_id='$student_id' AND office_name='$office'");
        } else {
            $year = isset($_POST['year']) ? sanitize($_POST['year']) : '';
            $section = isset($_POST['section']) ? sanitize($_POST['section']) : '';
            
            if ($year && $section) {
                $groupStudents = $conn->query("SELECT chmsu_student_id FROM chmsu_students_master 
                                               WHERE chmsu_year = '$year' AND chmsu_section = '$section' AND is_archived = 0");
                while ($gs = $groupStudents->fetch_assoc()) {
                    $sid = $gs['chmsu_student_id'];
                    $conn->query("DELETE FROM chmsu_chat_messages 
                                  WHERE (sender_type='office' AND sender_id='$office' AND receiver_id='$sid')
                                  OR (sender_type='student' AND sender_id='$sid' AND receiver_id='$office')");
                    $conn->query("DELETE FROM chmsu_chat_conversations 
                                  WHERE student_id='$sid' AND office_name='$office'");
                }
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Get unread count (Office)
    if ($action == 'get_unread_count' && isset($_SESSION['office'])) {
        $total = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$office'")->fetch_assoc()['total'];
        echo json_encode(['total' => $total ?: 0]);
        exit;
    }
    
    // Get students for chat list (Office)
    if ($action == 'get_students_for_chat' && isset($_SESSION['office'])) {
        $students = $conn->query("SELECT DISTINCT 
                                   s.chmsu_student_id as id,
                                   s.chmsu_full_name as name,
                                   s.chmsu_course as course,
                                   s.chmsu_year as year,
                                   s.chmsu_section as section,
                                   (SELECT office_unread FROM chmsu_chat_conversations 
                                    WHERE student_id = s.chmsu_student_id AND office_name = '$office') as unread
                                  FROM chmsu_students_master s
                                  WHERE s.is_archived = 0
                                  ORDER BY s.chmsu_last_name ASC");
        
        $result = [];
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

// Check if user is logged in as ADMIN
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
    } elseif ($adminSection == 'activity') {
        include 'modules/admin/activity.php';
    } elseif ($adminSection == 'maintenance') {
        include 'modules/admin/courses.php';
    } else {
        include 'modules/admin/dashboard.php';
    }
}
// Check if user is logged in as STUDENT
elseif (isset($_SESSION['student'])) {
    // ============================================
    // STUDENT ROUTING WITH FEED, NOTIFICATIONS & CHAT
    // ============================================
    if ($view == 'home') {
        include 'modules/student/dashboard.php';
    } elseif ($view == 'office') {
        include 'modules/student/clearance.php';
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
// Check if user is logged in as REGISTRAR
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
    else {
        include 'modules/registrar/dashboard.php';
    }
}
// Check if user is logged in as OTHER OFFICE
elseif (isset($_SESSION['office'])) {
    $currentOfficeSection = isset($_GET['officesection']) ? $_GET['officesection'] : 'dashboard';
    
    if ($currentOfficeSection == 'dashboard') {
        include 'modules/office/dashboard.php';
    } elseif ($currentOfficeSection == 'note') {
        include 'modules/office/chmsu_notes.php';
    } elseif ($currentOfficeSection == 'clearance') {
        include 'modules/office/clearance.php';
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
// NOT LOGGED IN - Show login or register page
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