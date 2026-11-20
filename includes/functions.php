<?php
require_once __DIR__ . '/../config/database.php';

// ============================================
// INCLUDE EMAIL FUNCTIONS
// ============================================
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/otp.php';

function handleActions($conn, &$error, &$success) {

    // ============================================
    // AUTO-FIX FOR CORRUPTED CHAT TABLES (REMOVED)
    // ============================================

    // ============================================
    // UNIFIED LOGIN
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'unified_login') {
        $username = sanitize($_POST['username']);
        $password = $_POST['password'];

        $adminCheck = $conn->query("SELECT * FROM chmsu_admin_users WHERE username='$username'");
        if ($adminCheck && $adminCheck->num_rows > 0) {
            $admin = $adminCheck->fetch_assoc();
            if (password_verify($password, $admin['password'])) {
                $_SESSION['admin'] = $username;
                logActivity($conn, $username, 'admin', 'Logged in');
                header("Location: " . $_SERVER['PHP_SELF'] . "?admin=1");
                exit;
            } else { $error = "Wrong Password!"; }
        }

        $officeCheck = $conn->query("SELECT * FROM chmsu_auth_roles WHERE chmsu_role='$username'");
        if ($officeCheck && $officeCheck->num_rows > 0) {
            $office = $officeCheck->fetch_assoc();
            if (password_verify($password, $office['chmsu_password'])) {
                $_SESSION['office'] = $username;
                logActivity($conn, $username, 'office', 'Logged in');
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else { $error = "Wrong Password!"; }
        }

        $studentCheck = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$username'");
        if ($studentCheck && $studentCheck->num_rows > 0) {
            $student = $studentCheck->fetch_assoc();
            if (password_verify($password, $student['chmsu_password'])) {
                $_SESSION['student'] = $username;
                $conn->query("UPDATE chmsu_user_accounts SET last_login = NOW() WHERE chmsu_student_id='$username'");
                logActivity($conn, $username, 'student', 'Logged in');
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else { $error = "Wrong Password!"; }
        }

        if (empty($error)) { $error = "Invalid username/ID or password!"; }
    }

    // ============================================
    // UNIFIED REGISTER
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'unified_register') {
        $username = sanitize($_POST['username']);
        $last_name = sanitize($_POST['last_name']);
        $first_name = sanitize($_POST['first_name']);
        $password = $_POST['password'];
        $confirm = $_POST['confirm_password'];

        if ($password !== $confirm) {
            $error = "password_mismatch";
        } elseif (!validatePassword($password)) {
            $error = "Password must be 6+ characters with 1 uppercase and 1 number!";
        } else {
            $adminExists = $conn->query("SELECT * FROM chmsu_admin_users WHERE username='$username'")->num_rows;
            $officeExists = $conn->query("SELECT * FROM chmsu_auth_roles WHERE chmsu_role='$username'")->num_rows;
            $studentExists = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$username'")->num_rows;

            if ($adminExists > 0 || $officeExists > 0 || $studentExists > 0) {
                $error = "Username/ID already exists! Please choose another.";
            } else {
                $masterCheck = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$username'");

                if ($masterCheck && $masterCheck->num_rows > 0) {
                    $masterData = $masterCheck->fetch_assoc();
                    $fullName = $masterData['chmsu_full_name'];
                    $course = $masterData['chmsu_course'];
                    $year = $masterData['chmsu_year'];
                    $section = $masterData['chmsu_section'];
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $insert = $conn->query("INSERT INTO chmsu_user_accounts (chmsu_student_id, chmsu_name, chmsu_password, chmsu_course, chmsu_year, chmsu_section) 
                                            VALUES ('$username', '$fullName', '$hash', '$course', '$year', '$section')");

                    if ($insert) {
                        logActivity($conn, $username, 'student', 'Registered account');
                        $success = "Student account created successfully! You can now login.";
                    } else { $error = "Registration failed: " . $conn->error; }
                } else {
                    $adminCount = $conn->query("SELECT * FROM chmsu_admin_users")->num_rows;

                    if ($adminCount == 0 && strtolower($username) == 'admin') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $insert = $conn->query("INSERT INTO chmsu_admin_users (username, password) VALUES ('$username', '$hash')");
                        if ($insert) {
                            logActivity($conn, $username, 'admin', 'Admin account created');
                            $success = "Admin account created successfully! You can now login.";
                        } else { $error = "Admin registration failed!"; }
                    } else {
                        $officeCheck = $conn->query("SELECT * FROM chmsu_offices WHERE office_name='$username'");
                        if ($officeCheck && $officeCheck->num_rows > 0) {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            $authCheck = $conn->query("SELECT * FROM chmsu_auth_roles WHERE chmsu_role='$username'");
                            if ($authCheck && $authCheck->num_rows == 0) {
                                $insert = $conn->query("INSERT INTO chmsu_auth_roles (chmsu_role, chmsu_password) VALUES ('$username', '$hash')");
                                if ($insert) {
                                    logActivity($conn, $username, 'office', 'Office account created');
                                    $success = "Office account created successfully! You can now login.";
                                } else { $error = "Office registration failed!"; }
                            } else { $error = "Office already has an account!"; }
                        } else {
                            $error = "Student ID not found in Master List! Contact Registrar for students, or use correct office name for personnel.";
                        }
                    }
                }
            }
        }
    }

    // ============================================
    // ADMIN LOGIN (Legacy)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'login_admin') {
        $username = sanitize($_POST['username']);
        $pass = $_POST['password'];
        $res = $conn->query("SELECT * FROM chmsu_admin_users WHERE username='$username'");
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (password_verify($pass, $row['password'])) {
                $_SESSION['admin'] = $username;
                logActivity($conn, $username, 'admin', 'Logged in');
                header("Location: " . $_SERVER['PHP_SELF'] . "?admin=1");
                exit;
            } else { $error = "Wrong Password!"; }
        } else { $error = "Admin not found!"; }
    }

    // ============================================
    // CREATE ADMIN
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'create_admin' && !$conn->query("SELECT * FROM chmsu_admin_users")->num_rows) {
        $username = sanitize($_POST['username']);
        $pass = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        if ($pass !== $confirm) { $error = "password_mismatch"; }
        elseif (!validatePassword($pass)) { $error = "Password must be 6+ characters with 1 uppercase and 1 number!"; }
        else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $conn->query("INSERT INTO chmsu_admin_users (username, password) VALUES ('$username', '$hash')");
            $success = "Admin created successfully!";
        }
    }

    // ============================================
    // STUDENT LOGIN (Legacy)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'login_student') {
        $id = sanitize($_POST['student_id']);
        $pass = $_POST['password'];
        $res = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$id'");
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (password_verify($pass, $row['chmsu_password'])) {
                $_SESSION['student'] = $id;
                logActivity($conn, $id, 'student', 'Logged in');
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else { $error = "Wrong Password!"; }
        } else { $error = "Student ID not found! Please register first."; }
    }

    // ============================================
    // STUDENT REGISTRATION (Legacy)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'register_student') {
        $id = sanitize($_POST['student_id']);
        $pass = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        if ($pass !== $confirm) { $error = "password_mismatch"; }
        elseif (!validatePassword($pass)) { $error = "Password must be 6+ characters with 1 uppercase and 1 number!"; }
        else {
            $master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$id'");
            if ($master->num_rows == 0) { $error = "Student ID not found in Master List! Contact Registrar."; }
            else {
                $exists = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$id'");
                if ($exists->num_rows > 0) { $error = "Account already activated! Please login."; }
                else {
                    $masterData = $master->fetch_assoc();
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $fullName = $masterData['chmsu_full_name'];
                    $course = $masterData['chmsu_course'];
                    $year = $masterData['chmsu_year'];
                    $section = $masterData['chmsu_section'];

                    $conn->query("INSERT INTO chmsu_user_accounts (chmsu_student_id, chmsu_name, chmsu_password, chmsu_course, chmsu_year, chmsu_section) 
                                 VALUES ('$id', '$fullName', '$hash', '$course', '$year', '$section')");
                    logActivity($conn, $id, 'student', 'Registered account');
                    $success = "Registration successful! You can now login.";
                    header("Location: " . $_SERVER['PHP_SELF'] . "?page=student_login");
                    exit;
                }
            }
        }
    }

    // ============================================
    // OFFICE LOGIN (Legacy)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'login_office') {
        $role = sanitize($_POST['role']);
        $pass = $_POST['password'];
        $res = $conn->query("SELECT * FROM chmsu_auth_roles WHERE chmsu_role='$role'");
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (password_verify($pass, $row['chmsu_password'])) {
                $_SESSION['office'] = $role;
                logActivity($conn, $role, 'office', 'Logged in');
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else { $error = "Wrong Password!"; }
        } else { $error = "Office not registered! Please register first."; }
    }

    // ============================================
    // REGISTER OFFICE (Legacy)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'register_office') {
        $role = sanitize($_POST['role']);
        $pass = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        if ($pass !== $confirm) { $error = "password_mismatch"; }
        elseif (!validatePassword($pass)) { $error = "Invalid password format!"; }
        else {
            $officeExists = $conn->query("SELECT * FROM chmsu_offices WHERE office_name='$role'");
            if ($officeExists->num_rows == 0) { $error = "Office not found in system! Contact administrator."; }
            else {
                $exists = $conn->query("SELECT * FROM chmsu_auth_roles WHERE chmsu_role='$role'");
                if ($exists->num_rows > 0) { $error = "Office already registered!"; }
                else {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $conn->query("INSERT INTO chmsu_auth_roles (chmsu_role, chmsu_password) VALUES ('$role', '$hash')");
                    logActivity($conn, $role, 'office', 'Office registered');
                    $success = "Office registered successfully! You can now login.";
                    header("Location: " . $_SERVER['PHP_SELF'] . "?page=personnel_login");
                    exit;
                }
            }
        }
    }

    // ============================================
    // ADD MASTER STUDENT (Registrar only)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'add_master' && isset($_SESSION['office']) && $_SESSION['office'] == 'Registrar') {
        $last = sanitize($_POST['last_name']);
        $first = sanitize($_POST['first_name']);
        $middle = sanitize($_POST['middle_name']);
        $birth = $_POST['birthdate'];
        $course = sanitize($_POST['course']);
        $year = sanitize($_POST['year']);
        $section = sanitize($_POST['section']);

        $birthDate = new DateTime($birth);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;

        if ($age < 15) { $error = "Student must be at least 15 years old!"; }
        else {
            $lastInitial = strtoupper(substr($last, 0, 1));
            $firstInitial = strtoupper(substr($first, 0, 1));
            $middleInitial = strtoupper(substr($middle, 0, 1));
            $month = date("m", strtotime($birth));
            $day = date("d", strtotime($birth));
            $yearShort = date("y", strtotime($birth));
            $id = $lastInitial . $firstInitial . $middleInitial . $month . $day . $yearShort . "00";

            $check = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$id'");
            if ($check->num_rows > 0) { $id = substr($id, 0, -2) . "01"; }

            $fullName = "$last, $first, $middle";
            $conn->query("INSERT INTO chmsu_students_master (chmsu_student_id, chmsu_last_name, chmsu_first_name, chmsu_middle_name, chmsu_full_name, chmsu_birthdate, chmsu_course, chmsu_year, chmsu_section) 
                         VALUES ('$id', '$last', '$first', '$middle', '$fullName', '$birth', '$course', '$year', '$section')");
            logActivity($conn, $_SESSION['office'], 'office', "Added student: $id");
            $success = "Student added! ID: $id";
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=masterlist");
            exit;
        }
    }

    // ============================================
    // ADD COURSE (Admin only)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'add_course' && isset($_SESSION['admin'])) {
        $course_code = sanitize($_POST['course_code']);
        $course_name = sanitize($_POST['course_name']);
        $check = $conn->query("SELECT * FROM chmsu_courses WHERE course_code='$course_code'");
        if ($check->num_rows > 0) { $error = "Course already exists!"; }
        else {
            $conn->query("INSERT INTO chmsu_courses (course_code, course_name) VALUES ('$course_code', '$course_name')");
            logActivity($conn, $_SESSION['admin'], 'admin', "Added course: $course_code");
            $success = "Course added successfully!";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=courses");
        exit;
    }

    // ============================================
    // DELETE COURSE - OTP only
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'delete_course' && isset($_SESSION['admin'])) {
        $error = "Please use OTP verification to delete.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=courses");
        exit;
    }

    // ============================================
    // ADD OFFICE (Admin only)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'add_office' && isset($_SESSION['admin'])) {
        $office_name = sanitize($_POST['office_name']);
        $check = $conn->query("SELECT * FROM chmsu_offices WHERE office_name='$office_name'");
        if ($check->num_rows > 0) { $error = "Office already exists!"; }
        else {
            $conn->query("INSERT INTO chmsu_offices (office_name) VALUES ('$office_name')");
            logActivity($conn, $_SESSION['admin'], 'admin', "Added office: $office_name");
            $success = "Office added successfully!";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=offices");
        exit;
    }

    // ============================================
    // DELETE OFFICE - OTP only
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'delete_office' && isset($_SESSION['admin'])) {
        $error = "Please use OTP verification to delete.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=offices");
        exit;
    }

    // ============================================
    // ADD SECTION (Admin only)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'add_section' && isset($_SESSION['admin'])) {
        $course = sanitize($_POST['section_course']);
        $year = sanitize($_POST['section_year']);
        $section_name = sanitize($_POST['section_name']);
        $check = $conn->query("SELECT * FROM chmsu_course_sections WHERE course='$course' AND year='$year' AND section_name='$section_name'");
        if ($check->num_rows > 0) { $error = "Section already exists for this course and year!"; }
        else {
            $conn->query("INSERT INTO chmsu_course_sections (course, year, section_name) VALUES ('$course', '$year', '$section_name')");
            logActivity($conn, $_SESSION['admin'], 'admin', "Added section: $course-$year-$section_name");
            $success = "Section added successfully!";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=sections");
        exit;
    }

    // ============================================
    // DELETE SECTION - OTP only
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'delete_section' && isset($_SESSION['admin'])) {
        $error = "Please use OTP verification to delete.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=sections");
        exit;
    }

    // ============================================
    // ADD REQUIREMENT WITH FILE UPLOAD
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'add_requirement' && isset($_SESSION['office'])) {
        $title = sanitize($_POST['title']);
        $course = sanitize($_POST['course']);
        $year = sanitize($_POST['year']);
        $section = sanitize($_POST['section']);
        $deadline = $_POST['deadline'];
        $office = $_SESSION['office'];
        $description = sanitize($_POST['description']);

        $file_path = ''; $file_type = ''; $file_name = '';
        if (isset($_FILES['req_file']) && $_FILES['req_file']['error'] == 0) {
            $allowed = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','ppt','pptx','txt'];
            $filename = $_FILES['req_file']['name'];
            $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($filetype, $allowed) && $_FILES['req_file']['size'] <= MAX_FILE_SIZE) {
                $newname = UPLOAD_DIR . 'req_' . $office . '_' . time() . '_' . basename($filename);
                if (move_uploaded_file($_FILES['req_file']['tmp_name'], $newname)) {
                    $file_path = $newname; $file_type = $filetype; $file_name = $filename;
                }
            }
        }

        $conn->query("INSERT INTO chmsu_requirements (chmsu_office, chmsu_course, chmsu_year, chmsu_section, chmsu_title, chmsu_deadline, chmsu_description, chmsu_file_path, chmsu_file_type, chmsu_file_name) 
                     VALUES ('$office', '$course', '$year', '$section', '$title', '$deadline', '$description', '$file_path', '$file_type', '$file_name')");
        logActivity($conn, $office, 'office', "Added requirement: $title");
        $success = "Requirement added successfully!";
        if ($office == 'Registrar') {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=requirements");
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=requirements");
        }
        exit;
    }

    // ============================================
    // SET/UPDATE DEADLINE
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'set_deadline' && isset($_SESSION['office'])) {
        $req_id = intval($_POST['req_id']);
        $deadline = $_POST['deadline'];
        $conn->query("UPDATE chmsu_requirements SET chmsu_deadline='$deadline' WHERE chmsu_id=$req_id");
        logActivity($conn, $_SESSION['office'], 'office', "Updated deadline for requirement ID: $req_id");
        $success = "Deadline updated!";
        if ($_SESSION['office'] == 'Registrar') {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=requirements");
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=requirements");
        }
        exit;
    }

    // ============================================
    // DELETE REQUIREMENT - OTP only
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'delete_requirement' && isset($_SESSION['office'])) {
        $error = "Please use OTP verification to delete.";
        if ($_SESSION['office'] == 'Registrar') {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=requirements");
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=requirements");
        }
        exit;
    }

    // ============================================
    // ADD PRIVATE COMMENT (Student)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'add_private_comment' && isset($_SESSION['student'])) {
        $req_id = intval($_POST['req_id']);
        $comment = sanitize($_POST['comment']);
        $student_id = $_SESSION['student'];
        $conn->query("INSERT INTO chmsu_private_comments (requirement_id, student_id, comment, created_by) 
                     VALUES ($req_id, '$student_id', '$comment', 'student')");
        $office = $conn->query("SELECT chmsu_office FROM chmsu_requirements WHERE chmsu_id=$req_id")->fetch_assoc()['chmsu_office'];
        $conn->query("INSERT INTO chmsu_comment_notifications (requirement_id, student_id, office_name) 
                     VALUES ($req_id, '$student_id', '$office')");
        $success = "Comment added!";
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=office&office=" . urlencode($_GET['office']) . "&req=" . $req_id);
        exit;
    }

    // ============================================
    // ADD PRIVATE COMMENT (Office)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'add_office_private_comment' && isset($_SESSION['office'])) {
        $req_id = intval($_POST['req_id']);
        $student_id = sanitize($_POST['student_id']);
        $comment = sanitize($_POST['comment']);
        $office = $_SESSION['office'];
        $conn->query("INSERT INTO chmsu_private_comments (requirement_id, student_id, comment, created_by) 
                     VALUES ($req_id, '$student_id', '$comment', 'office')");
        $conn->query("INSERT INTO chmsu_comment_notifications (requirement_id, student_id, office_name) 
                     VALUES ($req_id, '$student_id', '$office')");
        logActivity($conn, $office, 'office', "Added comment on requirement ID: $req_id for student: $student_id");
        $success = "Comment added!";
        if ($office == 'Registrar') {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=submissions");
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=submissions");
        }
        exit;
    }

    // ============================================
    // MARK COMMENT AS READ
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'mark_comments_read') {
        $req_id = intval($_POST['req_id']);
        $student_id = sanitize($_POST['student_id']);
        if (isset($_SESSION['office'])) {
            $office = $_SESSION['office'];
            $conn->query("UPDATE chmsu_comment_notifications SET is_read=1 
                         WHERE requirement_id=$req_id AND student_id='$student_id' AND office_name='$office'");
        } elseif (isset($_SESSION['student'])) {
            $conn->query("UPDATE chmsu_comment_notifications SET is_read=1 
                         WHERE requirement_id=$req_id AND student_id='{$_SESSION['student']}'");
        }
        exit;
    }

    // ============================================
    // HANDLE FILE SUBMISSION
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'submit_req' && isset($_SESSION['student'])) {
        $req_id = intval($_POST['req_id']);
        $text = sanitize($_POST['text']);
        $link = sanitize($_POST['link']);
        $student_id = $_SESSION['student'];

        $req_check = $conn->query("SELECT * FROM chmsu_requirements WHERE chmsu_id=$req_id");
        if ($req_check->num_rows == 0) { $error = "Requirement not found!"; }
        else {
            $req = $req_check->fetch_assoc();
            if ($req['chmsu_deadline'] && strtotime($req['chmsu_deadline']) < time()) {
                $error = "Deadline has passed! Cannot submit.";
            } else {
                $existing = $conn->query("SELECT * FROM chmsu_submissions WHERE chmsu_requirement_id=$req_id AND chmsu_student_id='$student_id'");
                if ($existing->num_rows > 0) { $error = "You have already submitted this requirement!"; }
                else {
                    $file_path = ''; $file_type = ''; $file_name = '';
                    $submission_text = ''; $submission_link = '';

                    if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
                        $allowed = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','ppt','pptx','txt'];
                        $filename = $_FILES['file']['name'];
                        $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        if (!in_array($filetype, $allowed)) { $error = "Invalid file type!"; }
                        elseif ($_FILES['file']['size'] > MAX_FILE_SIZE) { $error = "File too large!"; }
                        else {
                            $newname = UPLOAD_DIR . $student_id . '_' . time() . '_' . basename($filename);
                            if (move_uploaded_file($_FILES['file']['tmp_name'], $newname)) {
                                $file_path = $newname; $file_type = $filetype; $file_name = $filename;
                            } else { $error = "Failed to upload file!"; }
                        }
                    } elseif (!empty($link)) {
                        if (filter_var($link, FILTER_VALIDATE_URL)) {
                            $submission_link = $link; $file_type = 'link'; $file_name = 'External Link';
                        } else { $error = "Invalid URL!"; }
                    } elseif (!empty($text)) { $submission_text = $text; }

                    if (empty($error) && ($file_path || $submission_text || $submission_link)) {
                        $sql = "INSERT INTO chmsu_submissions (chmsu_requirement_id, chmsu_student_id, chmsu_text, chmsu_link, chmsu_file_path, chmsu_file_type, chmsu_file_name, chmsu_status) 
                                VALUES ($req_id, '$student_id', '$submission_text', '$submission_link', '$file_path', '$file_type', '$file_name', 'Submitted')";
                        if ($conn->query($sql)) {
                            logActivity($conn, $student_id, 'student', "Submitted requirement ID: $req_id");
                            $office_info = $conn->query("SELECT email FROM chmsu_auth_roles WHERE chmsu_role='{$req['chmsu_office']}'")->fetch_assoc();
                            if ($office_info && $office_info['email']) {
                                $student_info = $conn->query("SELECT chmsu_name FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
                                sendSubmissionNotification($office_info['email'], $req['chmsu_office'], $student_info['chmsu_name'], $req['chmsu_title']);
                            }
                            $success = "Requirement submitted successfully!";
                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=office&office=" . urlencode($req['chmsu_office']) . "&req=" . $req_id);
                            exit;
                        } else { $error = "Database error: " . $conn->error; }
                    } else { $error = "Please provide a file, link, or text!"; }
                }
            }
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=office&office=" . urlencode($req['chmsu_office']) . "&req=" . $req_id);
        exit;
    }

    // ============================================
    // UNSUBMIT
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'unsubmit' && isset($_SESSION['student'])) {
        $sub_id = intval($_POST['sub_id']);
        $sub = $conn->query("SELECT * FROM chmsu_submissions WHERE chmsu_id=$sub_id AND chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
        if ($sub) {
            if ($sub['chmsu_file_path'] && file_exists($sub['chmsu_file_path'])) { unlink($sub['chmsu_file_path']); }
            $conn->query("DELETE FROM chmsu_submissions WHERE chmsu_id=$sub_id");
            logActivity($conn, $_SESSION['student'], 'student', "Unsubmitted requirement submission ID: $sub_id");
            $success = "Submission withdrawn!";
            header("Location: " . $_SERVER['PHP_SELF'] . "?view=office&office=" . urlencode($_GET['office']) . "&req=" . $_GET['req']);
            exit;
        }
    }

    // ============================================
    // APPROVE SUBMISSION
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'approve' && isset($_SESSION['office'])) {
        $sub_id = intval($_POST['sub_id']);
        $sub = $conn->query("SELECT s.*, r.chmsu_office, r.chmsu_title FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE s.chmsu_id=$sub_id")->fetch_assoc();
        if ($sub['chmsu_office'] == $_SESSION['office']) {
            $conn->query("UPDATE chmsu_submissions SET chmsu_status='Approved', chmsu_office_action='Approved', chmsu_action_taken_at=NOW() WHERE chmsu_id=$sub_id");
            logActivity($conn, $_SESSION['office'], 'office', "Approved submission ID: $sub_id");
            $student_info = $conn->query("SELECT chmsu_name, email FROM chmsu_user_accounts WHERE chmsu_student_id='{$sub['chmsu_student_id']}'")->fetch_assoc();
            if ($student_info && $student_info['email']) {
                sendApprovalNotification($student_info['email'], $student_info['chmsu_name'], $sub['chmsu_office'], $sub['chmsu_title']);
            }
            $success = "Approved!";
        }
        if ($_SESSION['office'] == 'Registrar') {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=submissions");
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=submissions");
        }
        exit;
    }

    // ============================================
    // REJECT SUBMISSION
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'reject' && isset($_SESSION['office'])) {
        $sub_id = intval($_POST['sub_id']);
        $reason = sanitize($_POST['reason']);
        $sub = $conn->query("SELECT s.*, r.chmsu_office, r.chmsu_title FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE s.chmsu_id=$sub_id")->fetch_assoc();
        if ($sub['chmsu_office'] == $_SESSION['office']) {
            $conn->query("UPDATE chmsu_submissions SET chmsu_status='Declined', chmsu_reject_reason='$reason', chmsu_office_action='Declined', chmsu_action_taken_at=NOW() WHERE chmsu_id=$sub_id");
            logActivity($conn, $_SESSION['office'], 'office', "Declined submission ID: $sub_id");
            $student_info = $conn->query("SELECT chmsu_name, email FROM chmsu_user_accounts WHERE chmsu_student_id='{$sub['chmsu_student_id']}'")->fetch_assoc();
            if ($student_info && $student_info['email']) {
                sendDeclineNotification($student_info['email'], $student_info['chmsu_name'], $sub['chmsu_office'], $sub['chmsu_title'], $reason);
            }
            $success = "Declined with reason!";
        }
        if ($_SESSION['office'] == 'Registrar') {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=submissions");
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=submissions");
        }
        exit;
    }

    // ============================================
    // REVERT ACTION
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'revert_action' && isset($_SESSION['office'])) {
        $sub_id = intval($_POST['sub_id']);
        $sub = $conn->query("SELECT s.*, r.chmsu_office FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE s.chmsu_id=$sub_id")->fetch_assoc();
        if ($sub['chmsu_office'] == $_SESSION['office']) {
            $conn->query("UPDATE chmsu_submissions SET chmsu_status='Submitted', chmsu_reject_reason=NULL, chmsu_office_action=NULL, chmsu_action_taken_at=NULL WHERE chmsu_id=$sub_id");
            logActivity($conn, $_SESSION['office'], 'office', "Reverted action on submission ID: $sub_id");
            $success = "Action reverted! Submission is now pending.";
        }
        if ($_SESSION['office'] == 'Registrar') {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=submissions");
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=submissions");
        }
        exit;
    }

    // ============================================
    // HANDLE EXCEL IMPORT (Registrar only)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'import_students' && isset($_SESSION['office']) && $_SESSION['office'] == 'Registrar') {
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == 0) {
            $filename = $_FILES['excel_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xls', 'xlsx', 'csv'])) { $error = "Please upload Excel file (xls, xlsx, or csv)"; }
            else {
                $file = fopen($_FILES['excel_file']['tmp_name'], 'r');
                $row_count = 0; $imported = 0; $errors = [];
                $header = fgetcsv($file);
                while (($data = fgetcsv($file)) !== FALSE) {
                    $row_count++;
                    if (count($data) < 7) { $errors[] = "Row $row_count: Insufficient data"; continue; }
                    $last = sanitize($data[0]);
                    $first = sanitize($data[1]);
                    $middle = sanitize($data[2]);
                    $birth = trim($data[3]);
                    $course = sanitize($data[4]);
                    $year = sanitize($data[5]);
                    $section = sanitize($data[6]);
                    $birthDate = DateTime::createFromFormat('Y-m-d', $birth);
                    if (!$birthDate) { $errors[] = "Row $row_count: Invalid birthdate"; continue; }
                    $today = new DateTime();
                    $age = $today->diff($birthDate)->y;
                    if ($age < 15) { $errors[] = "Row $row_count: Must be at least 15"; continue; }
                    $lastInitial = strtoupper(substr($last, 0, 1));
                    $firstInitial = strtoupper(substr($first, 0, 1));
                    $middleInitial = strtoupper(substr($middle, 0, 1));
                    $month = $birthDate->format('m');
                    $day = $birthDate->format('d');
                    $yearShort = $birthDate->format('y');
                    $id = $lastInitial . $firstInitial . $middleInitial . $month . $day . $yearShort . "00";
                    $check = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$id'");
                    if ($check->num_rows > 0) { $id = substr($id, 0, -2) . "01"; }
                    $fullName = "$last, $first, $middle";
                    $courseCheck = $conn->query("SELECT * FROM chmsu_courses WHERE course_code='$course'");
                    if ($courseCheck->num_rows == 0) { $errors[] = "Row $row_count: Course '$course' not found"; continue; }
                    $sql = "INSERT INTO chmsu_students_master 
                            (chmsu_student_id, chmsu_last_name, chmsu_first_name, chmsu_middle_name, 
                             chmsu_full_name, chmsu_birthdate, chmsu_course, chmsu_year, chmsu_section) 
                            VALUES ('$id', '$last', '$first', '$middle', '$fullName', '$birth', '$course', '$year', '$section')";
                    if ($conn->query($sql)) { $imported++; } else { $errors[] = "Row $row_count: " . $conn->error; }
                }
                fclose($file);
                if ($imported > 0) {
                    logActivity($conn, $_SESSION['office'], 'office', "Imported $imported students");
                    $success = "Successfully imported $imported students";
                    if (!empty($errors)) { $success .= ". " . count($errors) . " errors occurred."; }
                } else { $error = "No students imported. " . implode("<br>", array_slice($errors, 0, 5)); }
            }
        } else { $error = "Please select a file to upload"; }
        header("Location: " . $_SERVER['PHP_SELF'] . "?section=masterlist");
        exit;
    }

    // ============================================
    // DEAN APPROVE STUDENT
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'dean_approve' && isset($_SESSION['office']) && $_SESSION['office'] == 'Dean') {
        $student_id = sanitize($_POST['student_id']);
        $studentInfo = $conn->query("SELECT chmsu_course, chmsu_year, chmsu_section FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
        $studentData = $studentInfo->fetch_assoc();
        $course = $studentData['chmsu_course'];
        $year = $studentData['chmsu_year'];
        $section = $studentData['chmsu_section'];
        $deanReq = $conn->query("SELECT chmsu_id FROM chmsu_requirements WHERE chmsu_office = 'Dean' AND chmsu_course = '$course' AND chmsu_year = '$year' AND chmsu_section = '$section' LIMIT 1");
        if ($deanReq->num_rows == 0) {
            $anyDeanReq = $conn->query("SELECT chmsu_id FROM chmsu_requirements WHERE chmsu_office = 'Dean' LIMIT 1");
            if ($anyDeanReq->num_rows > 0) { $req_id = $anyDeanReq->fetch_assoc()['chmsu_id']; }
            else {
                $conn->query("INSERT INTO chmsu_requirements (chmsu_office, chmsu_course, chmsu_year, chmsu_section, chmsu_title, chmsu_deadline) 
                              VALUES ('Dean', '$course', '$year', '$section', 'Dean Clearance', DATE_ADD(NOW(), INTERVAL 365 DAY))");
                $req_id = $conn->insert_id;
            }
        } else { $req_id = $deanReq->fetch_assoc()['chmsu_id']; }
        $check = $conn->query("SELECT * FROM chmsu_submissions WHERE chmsu_requirement_id = $req_id AND chmsu_student_id = '$student_id'");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE chmsu_submissions SET chmsu_status = 'Approved', chmsu_action_taken_at = NOW() WHERE chmsu_requirement_id = $req_id AND chmsu_student_id = '$student_id'");
        } else {
            $conn->query("INSERT INTO chmsu_submissions (chmsu_requirement_id, chmsu_student_id, chmsu_status, chmsu_action_taken_at) 
                          VALUES ($req_id, '$student_id', 'Approved', NOW())");
        }
        logActivity($conn, $_SESSION['office'], 'office', "Dean approved student: $student_id");
        $success = "Student approved by Dean!";
        header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=submissions");
        exit;
    }

    // ============================================
    // DEAN DECLINE STUDENT
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'dean_decline' && isset($_SESSION['office']) && $_SESSION['office'] == 'Dean') {
        $student_id = sanitize($_POST['student_id']);
        $reason = sanitize($_POST['reason']);
        $studentInfo = $conn->query("SELECT chmsu_course, chmsu_year, chmsu_section FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
        $studentData = $studentInfo->fetch_assoc();
        $course = $studentData['chmsu_course'];
        $year = $studentData['chmsu_year'];
        $section = $studentData['chmsu_section'];
        $deanReq = $conn->query("SELECT chmsu_id FROM chmsu_requirements WHERE chmsu_office = 'Dean' AND chmsu_course = '$course' AND chmsu_year = '$year' AND chmsu_section = '$section' LIMIT 1");
        if ($deanReq->num_rows == 0) {
            $anyDeanReq = $conn->query("SELECT chmsu_id FROM chmsu_requirements WHERE chmsu_office = 'Dean' LIMIT 1");
            if ($anyDeanReq->num_rows > 0) { $req_id = $anyDeanReq->fetch_assoc()['chmsu_id']; }
            else {
                $conn->query("INSERT INTO chmsu_requirements (chmsu_office, chmsu_course, chmsu_year, chmsu_section, chmsu_title, chmsu_deadline) 
                              VALUES ('Dean', '$course', '$year', '$section', 'Dean Clearance', DATE_ADD(NOW(), INTERVAL 365 DAY))");
                $req_id = $conn->insert_id;
            }
        } else { $req_id = $deanReq->fetch_assoc()['chmsu_id']; }
        $check = $conn->query("SELECT * FROM chmsu_submissions WHERE chmsu_requirement_id = $req_id AND chmsu_student_id = '$student_id'");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE chmsu_submissions SET chmsu_status = 'Declined', chmsu_reject_reason = '$reason', chmsu_action_taken_at = NOW() WHERE chmsu_requirement_id = $req_id AND chmsu_student_id = '$student_id'");
        } else {
            $conn->query("INSERT INTO chmsu_submissions (chmsu_requirement_id, chmsu_student_id, chmsu_status, chmsu_reject_reason, chmsu_action_taken_at) 
                          VALUES ($req_id, '$student_id', 'Declined', '$reason', NOW())");
        }
        logActivity($conn, $_SESSION['office'], 'office', "Dean declined student: $student_id");
        $success = "Student declined by Dean!";
        header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=submissions");
        exit;
    }

    // ============================================
    // DEAN REVERT ACTION
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'dean_revert' && isset($_SESSION['office']) && $_SESSION['office'] == 'Dean') {
        $student_id = sanitize($_POST['student_id']);
        $studentInfo = $conn->query("SELECT chmsu_course, chmsu_year, chmsu_section FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
        $studentData = $studentInfo->fetch_assoc();
        $course = $studentData['chmsu_course'];
        $year = $studentData['chmsu_year'];
        $section = $studentData['chmsu_section'];
        $deanReq = $conn->query("SELECT chmsu_id FROM chmsu_requirements WHERE chmsu_office = 'Dean' AND chmsu_course = '$course' AND chmsu_year = '$year' AND chmsu_section = '$section' LIMIT 1");
        if ($deanReq->num_rows == 0) {
            $anyDeanReq = $conn->query("SELECT chmsu_id FROM chmsu_requirements WHERE chmsu_office = 'Dean' LIMIT 1");
            if ($anyDeanReq->num_rows > 0) {
                $req_id = $anyDeanReq->fetch_assoc()['chmsu_id'];
                $conn->query("DELETE FROM chmsu_submissions WHERE chmsu_requirement_id = $req_id AND chmsu_student_id = '$student_id'");
            }
        } else {
            $req_id = $deanReq->fetch_assoc()['chmsu_id'];
            $conn->query("DELETE FROM chmsu_submissions WHERE chmsu_requirement_id = $req_id AND chmsu_student_id = '$student_id'");
        }
        logActivity($conn, $_SESSION['office'], 'office', "Dean reverted action for student: $student_id");
        $success = "Action reverted! Student is now pending Dean approval.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=submissions");
        exit;
    }

    // ============================================
    // ============================================
    // CHAT HANDLERS (Student ↔ Office ↔ Group)
    // ============================================
    // ============================================
    if (isset($_POST['action']) && in_array($_POST['action'], [
        'send_chat_message',
        'get_chat_messages',
        'get_group_chat_messages',
        'mark_chat_read',
        'mark_group_read',
        'clear_chat',
        'get_unread_count'
    ])) {
        $chat_action = $_POST['action'];

        // Detect session type
        $is_student = isset($_SESSION['student']);
        $is_office  = isset($_SESSION['office']);
        if (!$is_student && !$is_office) {
            echo json_encode($is_action_returning_json($chat_action) ? ['success' => false, 'message' => 'Not authenticated'] : []);
            exit;
        }

        // ---------------------------------------------------
        // SEND MESSAGE
        // ---------------------------------------------------
        if ($chat_action == 'send_chat_message') {
            $message = sanitize($_POST['message']);
            $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'individual';

            // File upload
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

            // GROUP
            if ($chat_type == 'group') {
                $course  = isset($_POST['course']) ? sanitize($_POST['course']) : '';
                $year    = isset($_POST['year']) ? sanitize($_POST['year']) : '';
                $section = isset($_POST['section']) ? sanitize($_POST['section']) : '';

                if ($is_student) {
                    $office_name = sanitize($_POST['office_name'] ?? '');
                    $sender_id = $_SESSION['student'];
                    $sender_type = 'student';
                } else {
                    $office_name = $_SESSION['office'];
                    $sender_id = $office_name;
                    $sender_type = 'office';
                }

                if ($office_name && $year && $section && $course !== '') {
                    $conn->query("INSERT INTO chmsu_group_chat_messages 
                                  (sender_type, sender_id, course, year_level, section, office_name, message, file_path, file_name, file_type) 
                                  VALUES ('$sender_type', '$sender_id', '$course', '$year', '$section', '$office_name', '$message', '$file_path', '$file_name', '$file_type')");
                    logActivity($conn, $sender_id, $sender_type, "Sent group chat to $course-$year-$section");
                }
                echo json_encode(['success' => true]);
                exit;
            }

            // INDIVIDUAL
            if ($is_student) {
                $student_id  = $_SESSION['student'];
                $office_name = sanitize($_POST['office_name'] ?? '');
                if (!$office_name) { echo json_encode(['success' => false, 'message' => 'Missing office']); exit; }

                // student → office
                $conn->query("INSERT INTO chmsu_chat_messages 
                              (sender_type, sender_id, receiver_id, message, file_path, file_name, file_type) 
                              VALUES ('student', '$student_id', '$office_name', '$message', '$file_path', '$file_name', '$file_type')");
                $conn->query("INSERT INTO chmsu_chat_conversations 
                              (student_id, office_name, last_message, last_message_time, office_unread) 
                              VALUES ('$student_id', '$office_name', '$message', NOW(), 1)
                              ON DUPLICATE KEY UPDATE 
                              last_message = '$message', 
                              last_message_time = NOW(),
                              office_unread = office_unread + 1");
                logActivity($conn, $student_id, 'student', "Sent chat message to office: $office_name");
            } else {
                $office      = $_SESSION['office'];
                $student_id  = sanitize($_POST['student_id'] ?? '');
                if (!$student_id) { echo json_encode(['success' => false, 'message' => 'Missing student']); exit; }

                // office → student
                $conn->query("INSERT INTO chmsu_chat_messages 
                              (sender_type, sender_id, receiver_id, message, file_path, file_name, file_type) 
                              VALUES ('office', '$office', '$student_id', '$message', '$file_path', '$file_name', '$file_type')");
                $conn->query("INSERT INTO chmsu_chat_conversations 
                              (student_id, office_name, last_message, last_message_time, student_unread) 
                              VALUES ('$student_id', '$office', '$message', NOW(), 1)
                              ON DUPLICATE KEY UPDATE 
                              last_message = '$message', 
                              last_message_time = NOW(),
                              student_unread = student_unread + 1");
                logActivity($conn, $office, 'office', "Sent chat message to student: $student_id");
            }
            echo json_encode(['success' => true]);
            exit;
        }

        // ---------------------------------------------------
        // GET INDIVIDUAL MESSAGES
        // ---------------------------------------------------
        if ($chat_action == 'get_chat_messages') {
            if ($is_student) {
                $student_id = $_SESSION['student'];
                $office_name = sanitize($_POST['office_name'] ?? '');
            } else {
                $office_name = $_SESSION['office'];
                $student_id = sanitize($_POST['student_id'] ?? '');
            }
            $since_id = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;

            $messages = $conn->query("SELECT * FROM chmsu_chat_messages 
                                      WHERE ((sender_type='student' AND sender_id='$student_id' AND receiver_id='$office_name')
                                          OR (sender_type='office' AND sender_id='$office_name' AND receiver_id='$student_id'))
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
                    'time' => date('M d, Y h:i A', strtotime($msg['created_at']))
                ];
            }
            echo json_encode($result);
            exit;
        }

        // ---------------------------------------------------
        // GET GROUP MESSAGES
        // ---------------------------------------------------
        if ($chat_action == 'get_group_chat_messages') {
            if ($is_student) {
                $office_name = sanitize($_POST['office_name'] ?? '');
            } else {
                $office_name = $_SESSION['office'];
            }
            $course  = isset($_POST['course']) ? sanitize($_POST['course']) : '';
            $year    = isset($_POST['year']) ? sanitize($_POST['year']) : '';
            $section = isset($_POST['section']) ? sanitize($_POST['section']) : '';
            $since_id = isset($_POST['since_id']) ? intval($_POST['since_id']) : 0;

            $courseClause = $course !== '' ? " AND m.course = '$course'" : "";

            $messages = $conn->query("SELECT m.*, 
                                      CASE WHEN m.sender_type='student' THEN s.chmsu_full_name ELSE m.office_name END as sender_name
                                      FROM chmsu_group_chat_messages m
                                      LEFT JOIN chmsu_students_master s ON m.sender_id = s.chmsu_student_id
                                      WHERE m.year_level='$year' AND m.section='$section' 
                                      AND m.office_name='$office_name' $courseClause
                                      AND m.id > $since_id
                                      ORDER BY m.created_at ASC");

            $result = [];
            while ($msg = $messages->fetch_assoc()) {
                $result[] = [
                    'id' => intval($msg['id']),
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

        // ---------------------------------------------------
        // MARK INDIVIDUAL MESSAGES READ
        // ---------------------------------------------------
        if ($chat_action == 'mark_chat_read') {
            if ($is_student) {
                $student_id = $_SESSION['student'];
                $office_name = sanitize($_POST['office_name'] ?? '');
                $conn->query("UPDATE chmsu_chat_messages SET is_read=1 
                              WHERE sender_type='office' AND sender_id='$office_name' AND receiver_id='$student_id'");
                $conn->query("UPDATE chmsu_chat_conversations SET student_unread=0 
                              WHERE student_id='$student_id' AND office_name='$office_name'");
            } else {
                $office = $_SESSION['office'];
                $student_id = sanitize($_POST['student_id'] ?? '');
                $conn->query("UPDATE chmsu_chat_messages SET is_read=1 
                              WHERE sender_type='student' AND sender_id='$student_id' AND receiver_id='$office'");
                $conn->query("UPDATE chmsu_chat_conversations SET office_unread=0 
                              WHERE student_id='$student_id' AND office_name='$office'");
            }
            echo json_encode(['success' => true]);
            exit;
        }

        // ---------------------------------------------------
        // MARK GROUP READ
        // ---------------------------------------------------
        if ($chat_action == 'mark_group_read') {
            if ($is_student) {
                $student_id = $_SESSION['student'];
                $office_name = sanitize($_POST['office_name'] ?? '');
                $reader_id = $student_id;
            } else {
                $office = $_SESSION['office'];
                $office_name = $office;
                $reader_id = "OFFICE_$office";
            }
            $course  = isset($_POST['course']) ? sanitize($_POST['course']) : '';
            $year    = isset($_POST['year']) ? sanitize($_POST['year']) : '';
            $section = isset($_POST['section']) ? sanitize($_POST['section']) : '';

            if ($office_name && $year && $section && $course !== '') {
                $latest = $conn->query("SELECT MAX(id) as max_id FROM chmsu_group_chat_messages 
                                        WHERE office_name='$office_name' 
                                        AND course='$course' AND year_level='$year' AND section='$section'")->fetch_assoc()['max_id'];
                $latest = $latest ? intval($latest) : 0;

                $conn->query("INSERT INTO chmsu_group_chat_reads 
                              (student_id, office_name, course, year_level, section, last_read_message_id) 
                              VALUES ('$reader_id', '$office_name', '$course', '$year', '$section', $latest)
                              ON DUPLICATE KEY UPDATE last_read_message_id = $latest, updated_at = NOW()");
            }
            echo json_encode(['success' => true]);
            exit;
        }

        // ---------------------------------------------------
        // CLEAR CHAT
        // ---------------------------------------------------
        if ($chat_action == 'clear_chat') {
            $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'individual';

            if ($chat_type == 'group') {
                if ($is_student) {
                    $office_name = sanitize($_POST['office_name'] ?? '');
                } else {
                    $office_name = $_SESSION['office'];
                }
                $course  = isset($_POST['course']) ? sanitize($_POST['course']) : '';
                $year    = isset($_POST['year']) ? sanitize($_POST['year']) : '';
                $section = isset($_POST['section']) ? sanitize($_POST['section']) : '';

                if ($office_name && $year && $section) {
                    $courseClause = $course !== '' ? " AND course='$course'" : "";
                    $conn->query("DELETE FROM chmsu_group_chat_messages 
                                  WHERE year_level='$year' AND section='$section' 
                                  AND office_name='$office_name' $courseClause");
                }
            } else {
                if ($is_student) {
                    $student_id = $_SESSION['student'];
                    $office_name = sanitize($_POST['office_name'] ?? '');
                    $conn->query("DELETE FROM chmsu_chat_messages 
                                  WHERE (sender_type='student' AND sender_id='$student_id' AND receiver_id='$office_name')
                                  OR (sender_type='office' AND sender_id='$office_name' AND receiver_id='$student_id')");
                    $conn->query("DELETE FROM chmsu_chat_conversations 
                                  WHERE student_id='$student_id' AND office_name='$office_name'");
                } else {
                    $office = $_SESSION['office'];
                    $student_id = sanitize($_POST['student_id'] ?? '');
                    $conn->query("DELETE FROM chmsu_chat_messages 
                                  WHERE (sender_type='student' AND sender_id='$student_id' AND receiver_id='$office')
                                  OR (sender_type='office' AND sender_id='$office' AND receiver_id='$student_id')");
                    $conn->query("DELETE FROM chmsu_chat_conversations 
                                  WHERE student_id='$student_id' AND office_name='$office'");
                }
            }
            echo json_encode(['success' => true]);
            exit;
        }

        // ---------------------------------------------------
        // GET UNREAD COUNT (both sessions)
        // ---------------------------------------------------
        if ($chat_action == 'get_unread_count') {
            if ($is_student) {
                $student_id = $_SESSION['student'];
                $indiv = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='$student_id'")->fetch_assoc()['total'] ?: 0;

                // Group unread for student — use master record
                $master = $conn->query("SELECT chmsu_year, chmsu_section, chmsu_course FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
                $myYear = $master ? $master['chmsu_year'] : '';
                $mySec  = $master ? $master['chmsu_section'] : '';
                $myCourse = $master ? $master['chmsu_course'] : '';

                $group = 0;
                if ($myYear && $mySec && $myCourse) {
                    $officeList = $conn->query("SELECT office_name FROM chmsu_offices");
                    while ($off = $officeList->fetch_assoc()) {
                        $on = $off['office_name'];
                        $readRow = $conn->query("SELECT last_read_message_id FROM chmsu_group_chat_reads 
                                                 WHERE student_id='$student_id' AND office_name='$on' 
                                                 AND course='$myCourse' AND year_level='$myYear' AND section='$mySec'")->fetch_assoc();
                        $lastRead = $readRow ? intval($readRow['last_read_message_id']) : 0;
                        $group += intval($conn->query("SELECT COUNT(*) as c FROM chmsu_group_chat_messages 
                                                       WHERE office_name='$on' AND course='$myCourse'
                                                       AND year_level='$myYear' AND section='$mySec'
                                                       AND sender_type='office' AND id > $lastRead")->fetch_assoc()['c']);
                    }
                }
                echo json_encode(['total' => intval($indiv) + intval($group)]);
                exit;
            } else {
                $office = $_SESSION['office'];
                $indiv = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$office'")->fetch_assoc()['total'] ?: 0;

                // Group unread for office across all sections
                $sections = $conn->query("SELECT DISTINCT UPPER(TRIM(chmsu_course)) as course, chmsu_year, UPPER(TRIM(chmsu_section)) as section 
                                          FROM chmsu_students_master WHERE is_archived=0 
                                          AND chmsu_course IS NOT NULL AND chmsu_course != ''");
                $group = 0;
                while ($g = $sections->fetch_assoc()) {
                    $c = $g['course']; $y = $g['chmsu_year']; $s = $g['section'];
                    $readRow = $conn->query("SELECT last_read_message_id FROM chmsu_group_chat_reads 
                                             WHERE student_id='OFFICE_$office' AND office_name='$office' 
                                             AND course='$c' AND year_level='$y' AND section='$s'")->fetch_assoc();
                    $lastRead = $readRow ? intval($readRow['last_read_message_id']) : 0;
                    $group += intval($conn->query("SELECT COUNT(*) as c FROM chmsu_group_chat_messages 
                                                   WHERE office_name='$office' AND course='$c'
                                                   AND year_level='$y' AND section='$s'
                                                   AND sender_type='student' AND id > $lastRead")->fetch_assoc()['c']);
                }
                echo json_encode(['total' => intval($indiv) + intval($group)]);
                exit;
            }
        }
    }

    // Helper for the above block
    function is_action_returning_json($action) {
        return in_array($action, ['send_chat_message','get_chat_messages','get_group_chat_messages','mark_chat_read','mark_group_read','clear_chat','get_unread_count']);
    }

    // ============================================
    // CLEARANCE STATUS IMPORT
    // ============================================
    $conn->query("CREATE TABLE IF NOT EXISTS chmsu_clearance_status (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id VARCHAR(50) NOT NULL,
        office_name VARCHAR(100) NOT NULL,
        status ENUM('clear', 'unclear', 'pending') DEFAULT 'pending',
        note TEXT,
        updated_by VARCHAR(100),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_status (student_id, office_name)
    )");

    if (isset($_POST['action']) && $_POST['action'] == 'import_clearance' && isset($_SESSION['office'])) {
        $office = $_SESSION['office'];
        if (isset($_FILES['clearance_file']) && $_FILES['clearance_file']['error'] == 0) {
            $filename = $_FILES['clearance_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xls', 'xlsx', 'csv'])) { $_SESSION['import_error'] = "Please upload Excel file"; }
            else {
                $file = fopen($_FILES['clearance_file']['tmp_name'], 'r');
                $imported = 0; $errors = []; $row_count = 0;
                $header = fgetcsv($file);
                while (($data = fgetcsv($file)) !== FALSE) {
                    $row_count++;
                    if (count($data) < 2) { $errors[] = "Row $row_count: Missing data"; continue; }
                    $student_id = sanitize($data[0]);
                    $status = strtolower(sanitize($data[1]));
                    $note = isset($data[2]) ? sanitize($data[2]) : '';
                    if (!in_array($status, ['clear', 'unclear'])) { $errors[] = "Row $row_count: Invalid status"; continue; }
                    $student_check = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
                    if ($student_check->num_rows == 0) { $errors[] = "Row $row_count: Student not found"; continue; }
                    $check = $conn->query("SELECT * FROM chmsu_clearance_status WHERE student_id='$student_id' AND office_name='$office'");
                    if ($check->num_rows > 0) {
                        $conn->query("UPDATE chmsu_clearance_status SET status='$status', note='$note', updated_by='$office', updated_at=NOW() WHERE student_id='$student_id' AND office_name='$office'");
                    } else {
                        $conn->query("INSERT INTO chmsu_clearance_status (student_id, office_name, status, note, updated_by) VALUES ('$student_id', '$office', '$status', '$note', '$office')");
                    }
                    $imported++;
                }
                fclose($file);
                if ($imported > 0) {
                    logActivity($conn, $office, 'office', "Imported $imported clearance statuses");
                    $_SESSION['import_success'] = "Successfully imported $imported students. " . count($errors) . " errors.";
                } else {
                    $_SESSION['import_error'] = "No records imported. " . implode("<br>", array_slice($errors, 0, 5));
                }
            }
        } else { $_SESSION['import_error'] = "Please select a file"; }
        header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=submissions");
        exit;
    }

    // ============================================
    // CONNECT GMAIL
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'connect_gmail') {
        $email = sanitize($_POST['email']);
        $app_password = sanitize($_POST['app_password']);
        $user_type = $_POST['user_type'];

        if ($user_type == 'student' && isset($_SESSION['student'])) {
            $student_id = $_SESSION['student'];
            $conn->query("UPDATE chmsu_user_accounts SET email='$email', app_password='$app_password' WHERE chmsu_student_id='$student_id'");
            $_SESSION['gmail_connected'] = true;
            $success = "Gmail connected successfully!";
            logActivity($conn, $student_id, 'student', "Connected Gmail: $email");
        } elseif ($user_type == 'office' && isset($_SESSION['office'])) {
            $office = $_SESSION['office'];
            $conn->query("UPDATE chmsu_auth_roles SET email='$email', app_password='$app_password' WHERE chmsu_role='$office'");
            $_SESSION['gmail_connected'] = true;
            $success = "Gmail connected successfully!";
            logActivity($conn, $office, 'office', "Connected Gmail: $email");
        } else { $error = "Unable to connect Gmail. Please login first."; }
        $redirect_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : $_SERVER['PHP_SELF'];
        header("Location: " . $redirect_url);
        exit;
    }

    // ============================================
    // UPDATE STUDENT EMAIL FROM GOOGLE OAUTH
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'update_student_email' && isset($_SESSION['student'])) {
        $student_id = $_SESSION['student'];
        $email = sanitize($_POST['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $conn->query("UPDATE chmsu_user_accounts SET email='$email' WHERE chmsu_student_id='$student_id'");
            logActivity($conn, $student_id, 'student', "Updated email from Google OAuth: $email");
            $success = "Email updated successfully!";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=home&gmail_connected=1");
        exit;
    }

    // ============================================
    // GET STUDENT EMAIL STATUS (AJAX)
    // ============================================
    if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['action']) && $_GET['action'] == 'get_student_email' && isset($_SESSION['student'])) {
        header('Content-Type: application/json');
        $student_id = $_SESSION['student'];
        $result = $conn->query("SELECT email FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'");
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            echo json_encode(['email' => $data['email']]);
        } else { echo json_encode(['email' => null]); }
        exit;
    }

    // ============================================
    // MARK NOTIFICATIONS AS READ
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'mark_notifications_read' && isset($_SESSION['student'])) {
        $student_id = $_SESSION['student'];
        $conn->query("UPDATE chmsu_notifications SET is_read = 1 WHERE user_type='student' AND user_id='$student_id'");
        exit;
    }

    // ============================================
    // SEND NOTIFICATION TO STUDENT
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'send_student_notification' && isset($_SESSION['office'])) {
        $student_id = sanitize($_POST['student_id']);
        $title = sanitize($_POST['title']);
        $message = sanitize($_POST['message']);
        $office = $_SESSION['office'];
        $link = isset($_POST['link']) ? sanitize($_POST['link']) : '?view=home';

        $conn->query("INSERT INTO chmsu_notifications 
                      (user_type, user_id, student_id, office_name, title, message, type, link) 
                      VALUES 
                      ('student', '$student_id', '$student_id', '$office', '$title', '$message', 'custom', '$link')");

        $student_info = $conn->query("SELECT email, chmsu_name FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'")->fetch_assoc();
        if ($student_info && !empty($student_info['email'])) {
            $subject = "📢 Notification from $office - CHMSU Clearance";
            $email_message = "<html><body><div style='font-family:Times New Roman;max-width:600px;margin:0 auto;padding:20px;border:1px solid #ddd;border-radius:8px;'><div style='background:#1b4d3e;color:white;padding:15px;text-align:center;'><h2>CHMSU E-Clearance System</h2></div><div style='padding:20px;'><h3>📢 Notification from <strong>$office</strong></h3><p>Hello <strong>{$student_info['chmsu_name']}</strong>,</p><div style='background:#f8f9fa;padding:15px;border-radius:4px;'><h4 style='color:#1b4d3e;'>$title</h4><p>$message</p></div><p>Please login to your student portal to view this notification.</p><a href='http://" . $_SERVER['HTTP_HOST'] . "/index.php$link' style='background:#1b4d3e;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;'>View Notification</a></div></div></body></html>";
            sendEmail($student_info['email'], 'student', $subject, $email_message, true);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // BULK NOTIFY ALL STUDENTS
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'bulk_notify_students' && isset($_SESSION['office'])) {
        $title = sanitize($_POST['title']);
        $message = sanitize($_POST['message']);
        $office = $_SESSION['office'];
        $link = isset($_POST['link']) ? sanitize($_POST['link']) : '?view=home';

        $students = $conn->query("SELECT chmsu_student_id, email, chmsu_name FROM chmsu_user_accounts");
        while ($student = $students->fetch_assoc()) {
            $conn->query("INSERT INTO chmsu_notifications 
                          (user_type, user_id, student_id, office_name, title, message, type, link) 
                          VALUES 
                          ('student', '{$student['chmsu_student_id']}', '{$student['chmsu_student_id']}', '$office', 
                           '$title', '$message', 'bulk', '$link')");
            if (!empty($student['email'])) {
                $subject = "📢 Announcement from $office - CHMSU Clearance";
                $email_message = "<html><body><div style='font-family:Times New Roman;max-width:600px;margin:0 auto;padding:20px;border:1px solid #ddd;border-radius:8px;'><div style='background:#1b4d3e;color:white;padding:15px;text-align:center;'><h2>CHMSU E-Clearance System</h2></div><div style='padding:20px;'><h3>📢 Announcement from <strong>$office</strong></h3><p>Hello <strong>{$student['chmsu_name']}</strong>,</p><div style='background:#f8f9fa;padding:15px;border-radius:4px;'><h4 style='color:#1b4d3e;'>$title</h4><p>$message</p></div><a href='http://" . $_SERVER['HTTP_HOST'] . "/index.php$link' style='background:#1b4d3e;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;'>View Announcement</a></div></div></body></html>";
                sendEmail($student['email'], 'student', $subject, $email_message, true);
            }
        }
        logActivity($conn, $office, 'office', "Bulk notified all students: $title");
        $success = "Announcement sent to all students!";
        header("Location: " . $_SERVER['PHP_SELF'] . "?officesection=note");
        exit;
    }

    // ============================================
    // SEND OTP FOR DELETE
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'send_delete_otp' && isset($_SESSION['admin'])) {
        $admin = $_SESSION['admin'];
        $table = sanitize($_POST['table']);
        $item_id = intval($_POST['item_id']);
        $item_name = sanitize($_POST['item_name']);

        $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='$admin'")->fetch_assoc();
        if (!$admin_data || empty($admin_data['email'])) {
            echo json_encode(['success' => false, 'message' => 'Please configure your Gmail in the dashboard first.']);
            exit;
        }
        $admin_email = $admin_data['email'];
        $otp_code = generateOTP();
        saveOTP($conn, $admin_email, $otp_code, 'delete', $item_id);
        $result = sendOTPEmail($admin_email, $otp_code, 'delete');
        if ($result['success']) { echo json_encode(['success' => true, 'message' => 'OTP sent to your email.']); }
        else { echo json_encode(['success' => false, 'message' => 'Failed to send email: ' . $result['message']]); }
        exit;
    }

    // ============================================
    // VERIFY OTP AND DELETE
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'verify_delete_otp' && isset($_SESSION['admin'])) {
        $admin = $_SESSION['admin'];
        $otp_code = sanitize($_POST['otp_code']);
        $table = sanitize($_POST['table']);
        $item_id = intval($_POST['item_id']);

        $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='$admin'")->fetch_assoc();
        $admin_email = $admin_data['email'];
        $result = verifyOTP($conn, $admin_email, $otp_code, 'delete');

        if ($result['valid']) {
            $delete_success = false; $message = '';
            switch($table) {
                case 'courses':
                    $conn->query("DELETE FROM chmsu_courses WHERE id=$item_id");
                    $delete_success = true; $message = "Course deleted successfully!";
                    logActivity($conn, $admin, 'admin', "Deleted course ID: $item_id (OTP)");
                    break;
                case 'offices':
                    $conn->query("DELETE FROM chmsu_offices WHERE id=$item_id");
                    $delete_success = true; $message = "Office deleted successfully!";
                    logActivity($conn, $admin, 'admin', "Deleted office ID: $item_id (OTP)");
                    break;
                case 'sections':
                    $conn->query("DELETE FROM chmsu_course_sections WHERE id=$item_id");
                    $delete_success = true; $message = "Section deleted successfully!";
                    logActivity($conn, $admin, 'admin', "Deleted section ID: $item_id (OTP)");
                    break;
                case 'school_years':
                    $conn->query("DELETE FROM chmsu_school_years WHERE id=$item_id");
                    $delete_success = true; $message = "School year deleted successfully!";
                    logActivity($conn, $admin, 'admin', "Deleted school year ID: $item_id (OTP)");
                    break;
                case 'semesters':
                    $conn->query("DELETE FROM chmsu_semesters WHERE id=$item_id");
                    $delete_success = true; $message = "Semester deleted successfully!";
                    logActivity($conn, $admin, 'admin', "Deleted semester ID: $item_id (OTP)");
                    break;
                case 'requirements':
                    $subs = $conn->query("SELECT chmsu_file_path FROM chmsu_submissions WHERE chmsu_requirement_id=$item_id");
                    while ($sub = $subs->fetch_assoc()) {
                        if ($sub['chmsu_file_path'] && file_exists($sub['chmsu_file_path'])) { unlink($sub['chmsu_file_path']); }
                    }
                    $req = $conn->query("SELECT chmsu_file_path FROM chmsu_requirements WHERE chmsu_id=$item_id")->fetch_assoc();
                    if ($req['chmsu_file_path'] && file_exists($req['chmsu_file_path'])) { unlink($req['chmsu_file_path']); }
                    $conn->query("DELETE FROM chmsu_submissions WHERE chmsu_requirement_id=$item_id");
                    $conn->query("DELETE FROM chmsu_requirements WHERE chmsu_id=$item_id");
                    $delete_success = true; $message = "Requirement deleted successfully!";
                    logActivity($conn, $admin, 'admin', "Deleted requirement ID: $item_id (OTP)");
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => 'Unknown table: ' . $table]);
                    exit;
            }
            if ($delete_success) { echo json_encode(['success' => true, 'message' => $message]); }
            else { echo json_encode(['success' => false, 'message' => 'Failed to delete item.']); }
        } else { echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP. Please try again.']); }
        exit;
    }

    // ============================================
    // RESEND OTP
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'resend_delete_otp' && isset($_SESSION['admin'])) {
        $admin = $_SESSION['admin'];
        $table = sanitize($_POST['table']);
        $item_id = intval($_POST['item_id']);
        $item_name = sanitize($_POST['item_name']);

        $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='$admin'")->fetch_assoc();
        if (!$admin_data || empty($admin_data['email'])) {
            echo json_encode(['success' => false, 'message' => 'Please configure your Gmail first.']);
            exit;
        }
        $admin_email = $admin_data['email'];
        resendOTP($conn, $admin_email, 'delete', $item_id);
        echo json_encode(['success' => true, 'message' => 'New OTP sent to your email.']);
        exit;
    }

    // ============================================
    // ADMIN CONFIGURE EMAIL
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'admin_configure_email' && isset($_SESSION['admin'])) {
        $admin = $_SESSION['admin'];
        $email = sanitize($_POST['email']);
        $app_password = sanitize($_POST['app_password']);
        $conn->query("UPDATE chmsu_admin_users SET email='$email', app_password='$app_password', otp_enabled=1 WHERE username='$admin'");
        sendEmail($email, 'Admin', '📧 Email Configured - CHMSU Clearance', '<h2>Admin Email Configured</h2><p>Your email has been successfully configured for CHMSU E-Clearance System.</p><p>You will now receive OTP codes and notifications.</p>', true, $email, $app_password);
        $_SESSION['gmail_connected'] = true;
        $success = "Email configured successfully! OTP verification enabled.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=dashboard");
        exit;
    }

    // ============================================
    // TOGGLE ADMIN OTP
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'toggle_admin_otp' && isset($_SESSION['admin'])) {
        $admin = $_SESSION['admin'];
        $current = $conn->query("SELECT otp_enabled FROM chmsu_admin_users WHERE username='$admin'")->fetch_assoc();
        $new_status = $current['otp_enabled'] ? 0 : 1;
        $conn->query("UPDATE chmsu_admin_users SET otp_enabled=$new_status WHERE username='$admin'");
        $success = "OTP verification " . ($new_status ? 'enabled' : 'disabled');
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=dashboard");
        exit;
    }
}

// ============================================
// HELPER FUNCTIONS (SANITIZE, LOGIN, ETC.)
// ============================================
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function validatePassword($pass) {
    return preg_match('/^(?=.*[A-Z])(?=.*[0-9])[a-zA-Z0-9]{6,}$/', $pass);
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

function getTotalUploadSize() {
    $total = 0;
    if (file_exists(UPLOAD_DIR)) {
        foreach (glob(UPLOAD_DIR . '*') as $file) {
            $total += filesize($file);
        }
    }
    return $total;
}

function formatBirthdate($date) {
    return date('F d, Y', strtotime($date));
}

function logActivity($conn, $user_id, $user_type, $action) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $conn->query("INSERT INTO chmsu_activity_log (user_id, user_type, action, ip_address, user_agent) 
                  VALUES ('$user_id', '$user_type', '$action', '$ip', '$user_agent')");
}

function exportToExcel($data, $filename) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    
    echo '<table border="1">';
    if (!empty($data)) {
        echo '<tr>';
        foreach (array_keys($data[0]) as $header) {
            echo '<th>' . $header . '</th>';
        }
        echo '</tr>';
        
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . $cell . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</table>';
    exit;
}

function exportToWord($data, $filename) {
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $filename . '.doc"');
    
    echo '<html>';
    echo '<head><meta charset="UTF-8"><title>' . $filename . '</title></head>';
    echo '<body>';
    echo '<h2>' . $filename . '</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
    if (!empty($data)) {
        echo '<tr>';
        foreach (array_keys($data[0]) as $header) {
            echo '<th bgcolor="#f2f2f2">' . $header . '</th>';
        }
        echo '<tr>';
        
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . $cell . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</table>';
    echo '</body></html>';
    exit;
}

function getFileIcon($type) {
    $type = strtolower($type);
    if (in_array($type, ['jpg', 'jpeg', 'png', 'gif'])) return 'fa-file-image';
    if ($type === 'pdf') return 'fa-file-pdf';
    if (in_array($type, ['doc', 'docx'])) return 'fa-file-word';
    if (in_array($type, ['xls', 'xlsx'])) return 'fa-file-excel';
    if (in_array($type, ['ppt', 'pptx'])) return 'fa-file-powerpoint';
    if ($type === 'txt') return 'fa-file-alt';
    if ($type === 'link') return 'fa-link';
    return 'fa-file';
}

function getFileColor($type) {
    $type = strtolower($type);
    if (in_array($type, ['jpg', 'jpeg', 'png', 'gif'])) return '#4285f4';
    if ($type === 'pdf') return '#ea4335';
    if (in_array($type, ['doc', 'docx'])) return '#4285f4';
    if (in_array($type, ['xls', 'xlsx'])) return '#34a853';
    if (in_array($type, ['ppt', 'pptx'])) return '#fbbc04';
    if ($type === 'link') return '#1a73e8';
    return '#5f6368';
}

function hasAllClearanceApproved($conn, $student_id) {
    $totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];
    if ($totalOffices == 0) return false;
    
    $approvedCount = $conn->query("SELECT COUNT(DISTINCT r.chmsu_office) as c 
                                    FROM chmsu_submissions s 
                                    JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id 
                                    WHERE s.chmsu_student_id = '$student_id' AND s.chmsu_status = 'Approved'")->fetch_assoc()['c'];
    return $approvedCount >= $totalOffices;
}

function getCurrentPageUrl() {
    $url = $_SERVER['PHP_SELF'];
    if (!empty($_SERVER['QUERY_STRING'])) {
        $url .= '?' . $_SERVER['QUERY_STRING'];
    }
    return $url;
}

// ============================================
// REPORT EXPORT FUNCTIONS (Excel & Word)
// ============================================

function exportToExcelReport($data, $filename, $headers, $report_title = '', $subtitle = '') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    
    echo '<html>';
    echo '<head><meta charset="UTF-8"><title>' . $filename . '</title>';
    echo '<style>
        @page { size: landscape; margin: 1cm; }
        body { font-family: "Times New Roman", Times, serif; margin: 20px; font-size: 11pt; color: #000; }
        .header { text-align: center; margin-bottom: 30px; }
        .university-name { font-size: 16pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .university-address { font-size: 10pt; margin-top: 2px; }
        .report-title { font-size: 14pt; font-weight: bold; margin-top: 20px; text-transform: uppercase; text-decoration: underline; }
        .report-subtitle { font-size: 11pt; margin-top: 5px; }
        .report-info { margin: 20px 0; font-size: 10pt; width: 100%; }
        .report-info td { border: none; padding: 2px 5px; }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data-table th { border: 1px solid #000; padding: 8px; background-color: #f0f0f0; font-weight: bold; text-align: center; font-size: 11pt; }
        table.data-table td { border: 1px solid #000; padding: 6px 8px; text-align: left; font-size: 10pt; }
        .footer { text-align: center; margin-top: 30px; font-size: 9pt; font-style: italic; }
    </style>';
    echo '</head><body>';
    
    // Formal Header
    echo '<div class="header">';
    echo '<div class="university-name">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>';
    echo '<div class="university-address">Talisay City, Negros Occidental, Philippines</div>';
    echo '</div>';
    
    // Title
    echo '<div class="report-title">' . (!empty($report_title) ? $report_title : $filename) . '</div>';
    if (!empty($subtitle)) {
        echo '<div class="report-subtitle">' . $subtitle . '</div>';
    }
    
    // Metadata
    echo '<table class="report-info">';
    echo '<tr><td width="15%"><strong>Date Generated:</strong></td><td width="35%">' . date('F d, Y') . '</td>';
    echo '<td width="15%"><strong>Time Generated:</strong></td><td width="35%">' . date('h:i A') . '</td></tr>';
    echo '<tr><td><strong>Generated By:</strong></td><td>' . (isset($_SESSION['office']) ? htmlspecialchars($_SESSION['office']) . ' Office' : 'System Administrator') . '</td>';
    echo '<td><strong>Total Records:</strong></td><td>' . count($data) . '</td></tr>';
    echo '</table>';
    
    // Data Table
    echo '<table class="data-table">';
    echo '<thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . $header . '</th>';
    }
    echo '</tr></thead><tbody>';
    
    if (empty($data)) {
        echo '<tr><td colspan="' . count($headers) . '" style="text-align:center; padding: 20px;">No records found.</td></tr>';
    } else {
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . (is_null($cell) || $cell === '' ? '-' : htmlspecialchars($cell)) . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    
    echo '<div class="footer">This is a system-generated report. No signature is required.</div>';
    echo '</body></html>';
    exit;
}

function exportToWordReport($data, $filename, $headers, $report_title = '', $subtitle = '') {
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $filename . '.doc"');
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"><title>' . $filename . '</title>';
    echo '<style>
        @page { size: landscape; margin: 2.54cm; }
        body { font-family: "Times New Roman", Times, serif; font-size: 11pt; color: #000; line-height: 1.3; }
        .header { text-align: center; margin-bottom: 25px; }
        .university-name { font-size: 16pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .university-address { font-size: 10pt; margin-top: 2px; }
        .report-title { font-size: 14pt; font-weight: bold; margin-top: 20px; text-transform: uppercase; text-decoration: underline; }
        .report-subtitle { font-size: 11pt; margin-top: 5px; }
        .report-info { margin: 20px 0; font-size: 10pt; width: 100%; }
        .report-info td { border: none; padding: 2px 5px; }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data-table th { border: 1px solid #000; padding: 8px; background-color: #f0f0f0; font-weight: bold; text-align: center; font-size: 11pt; }
        table.data-table td { border: 1px solid #000; padding: 6px 8px; text-align: left; font-size: 10pt; }
        .footer { text-align: center; margin-top: 30px; font-size: 9pt; font-style: italic; }
    </style>';
    echo '</head><body>';
    
    // Formal Header
    echo '<div class="header">';
    echo '<div class="university-name">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>';
    echo '<div class="university-address">Talisay City, Negros Occidental, Philippines</div>';
    echo '</div>';
    
    // Title
    echo '<div class="report-title">' . (!empty($report_title) ? $report_title : $filename) . '</div>';
    if (!empty($subtitle)) {
        echo '<div class="report-subtitle">' . $subtitle . '</div>';
    }
    
    // Metadata
    echo '<table class="report-info">';
    echo '<tr><td width="15%"><strong>Date Generated:</strong></td><td width="35%">' . date('F d, Y') . '</td>';
    echo '<td width="15%"><strong>Time Generated:</strong></td><td width="35%">' . date('h:i A') . '</td></tr>';
    echo '<tr><td><strong>Generated By:</strong></td><td>' . (isset($_SESSION['office']) ? htmlspecialchars($_SESSION['office']) . ' Office' : 'System Administrator') . '</td>';
    echo '<td><strong>Total Records:</strong></td><td>' . count($data) . '</td></tr>';
    echo '</table>';
    
    // Data Table
    echo '<table class="data-table">';
    echo '<thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . $header . '</th>';
    }
    echo '</tr></thead><tbody>';
    
    if (empty($data)) {
        echo '<tr><td colspan="' . count($headers) . '" style="text-align:center; padding: 20px;">No records found.</td></tr>';
    } else {
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . (is_null($cell) || $cell === '' ? '-' : htmlspecialchars($cell)) . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    
    echo '<div class="footer">This is a system-generated report. No signature is required.</div>';
    echo '</body></html>';
    exit;
}

// ============================================
// COMPREHENSIVE REPORT FUNCTION
// ============================================

function exportComprehensiveReport($conn, $filename, $user_role = 'admin') {
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $filename . '.doc"');
    
    $date_generated = date('F d, Y');
    $time_generated = date('h:i A');
    
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>' . $filename . '</title>';
    echo '<style>
        body {
            font-family: "Times New Roman", Times, serif;
            margin: 2.54cm;
            font-size: 12pt;
            line-height: 1.5;
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 2px solid #1b4d3e;
            padding-bottom: 20px;
        }
        .university-name {
            font-size: 22pt;
            font-weight: bold;
            color: #1b4d3e;
            letter-spacing: 2px;
        }
        .university-address {
            font-size: 11pt;
            color: #555;
            margin-top: 5px;
        }
        .main-title {
            font-size: 20pt;
            font-weight: bold;
            text-align: center;
            margin: 30px 0 10px 0;
            text-transform: uppercase;
        }
        .report-info {
            margin: 30px 0;
            padding: 15px;
            background: #f5f5f5;
            border: 1px solid #ddd;
        }
        .section-title {
            font-size: 16pt;
            font-weight: bold;
            color: #1b4d3e;
            margin: 30px 0 15px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #1b4d3e;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin: 15px 0;
        }
        th {
            background: #1b4d3e;
            color: white;
            padding: 10px 8px;
            font-weight: bold;
            text-align: center;
            border: 1px solid #2d6a4f;
        }
        td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
            vertical-align: top;
        }
        .footer {
            text-align: center;
            margin-top: 50px;
            padding-top: 15px;
            border-top: 1px solid #ccc;
            font-size: 10pt;
            color: #888;
        }
        .signature-section {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            width: 45%;
            text-align: center;
        }
        .signature-line {
            margin-top: 60px;
            border-top: 1px solid #000;
            width: 80%;
            margin-left: auto;
            margin-right: auto;
        }
    </style>';
    echo '</head><body>';
    
    // Header
    echo '<div class="header">';
    echo '<div class="university-name">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>';
    echo '<div class="university-address">Talisay City, Negros Occidental, Philippines</div>';
    echo '<div class="university-address">E-Clearance System</div>';
    echo '</div>';
    
    // Main Title
    echo '<div class="main-title">COMPREHENSIVE SYSTEM REPORT</div>';
    
    // Report Information
    echo '<div class="report-info">';
    echo '<table style="background:transparent; border:none; width:100%;">';
    echo '<tr><td style="border:none; width:30%;"><strong>Report Type:</strong></td><td style="border:none;">Comprehensive System Report</td></tr>';
    echo '<tr><td style="border:none;"><strong>Date Generated:</strong></td><td style="border:none;">' . $date_generated . '</td></tr>';
    echo '<tr><td style="border:none;"><strong>Time Generated:</strong></td><td style="border:none;">' . $time_generated . '</td></tr>';
    echo '<tr><td style="border:none;"><strong>Generated by:</strong></td><td style="border:none;">System Administrator</td></tr>';
    echo '<tr><td style="border:none;"><strong>Reporting Period:</strong></td><td style="border:none;">Full Academic Year ' . date('Y') . '</td></tr>';
    echo '</table>';
    echo '</div>';
    
    // SECTION 1: EXECUTIVE SUMMARY
    echo '<div class="section-title">I. EXECUTIVE SUMMARY</div>';
    
    $totalStudents = $conn->query("SELECT COUNT(*) as c FROM chmsu_user_accounts")->fetch_assoc()['c'];
    $totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];
    $totalRequirements = $conn->query("SELECT COUNT(*) as c FROM chmsu_requirements")->fetch_assoc()['c'];
    $totalSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions")->fetch_assoc()['c'];
    $approvedSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions WHERE chmsu_status='Approved'")->fetch_assoc()['c'];
    $declinedSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions WHERE chmsu_status='Declined'")->fetch_assoc()['c'];
    $pendingSubmissions = $conn->query("SELECT COUNT(*) as c FROM chmsu_submissions WHERE chmsu_status='Submitted'")->fetch_assoc()['c'];
    
    // Get completed clearance count
    $totalOfficesCount = $totalOffices;
    $completedQuery = $conn->query("SELECT COUNT(DISTINCT s.chmsu_student_id) as c 
        FROM chmsu_submissions s 
        JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id 
        WHERE s.chmsu_status = 'Approved' 
        GROUP BY s.chmsu_student_id 
        HAVING COUNT(DISTINCT r.chmsu_office) >= $totalOfficesCount");
    $completedClearance = ($completedQuery && $completedQuery->num_rows > 0) ? $completedQuery->fetch_assoc()['c'] : 0;
    
    echo '<table>';
    echo '<tr><th>Metric</th><th>Count</th><th>Percentage</th></tr>';
    echo '<tr><td>Total Registered Students</td><td>' . number_format($totalStudents) . '</td><td>100%</td></tr>';
    echo '<tr><td>Total Offices</td><td>' . number_format($totalOffices) . '</td><td>100%</td></tr>';
    echo '<tr><td>Total Requirements Created</td><td>' . number_format($totalRequirements) . '</td><td>-</td></tr>';
    echo '<tr><td>Total Submissions</td><td>' . number_format($totalSubmissions) . '</td><td>100%</td></tr>';
    echo '<tr><td>Approved Submissions</td><td>' . number_format($approvedSubmissions) . '</td><td>' . ($totalSubmissions > 0 ? round(($approvedSubmissions / $totalSubmissions) * 100, 2) : 0) . '%</td></tr>';
    echo '<tr><td>Declined Submissions</td><td>' . number_format($declinedSubmissions) . '</td><td>' . ($totalSubmissions > 0 ? round(($declinedSubmissions / $totalSubmissions) * 100, 2) : 0) . '%</td></tr>';
    echo '<tr><td>Pending Submissions</td><td>' . number_format($pendingSubmissions) . '</td><td>' . ($totalSubmissions > 0 ? round(($pendingSubmissions / $totalSubmissions) * 100, 2) : 0) . '%</td></tr>';
    echo '<tr><td>Students with Complete Clearance</td><td>' . number_format($completedClearance) . '</td><td>' . ($totalStudents > 0 ? round(($completedClearance / $totalStudents) * 100, 2) : 0) . '%</td></tr>';
    echo '</table>';
    
    // SECTION 2: STUDENT MASTER LIST
    echo '<div class="section-title">II. STUDENT MASTER LIST</div>';
    
    $students = $conn->query("SELECT chmsu_student_id, chmsu_last_name, chmsu_first_name, chmsu_middle_name, chmsu_course, chmsu_year, chmsu_section FROM chmsu_students_master ORDER BY chmsu_course, chmsu_year, chmsu_section, chmsu_last_name LIMIT 50");
    
    echo '<table border="1">';
    echo '<tr><th>Student ID</th><th>Last Name</th><th>First Name</th><th>Course</th><th>Year</th><th>Section</th></tr>';
    while ($s = $students->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($s['chmsu_student_id']) . '</td>';
        echo '<td>' . htmlspecialchars($s['chmsu_last_name']) . '</td>';
        echo '<td>' . htmlspecialchars($s['chmsu_first_name']) . '</td>';
        echo '<td>' . htmlspecialchars($s['chmsu_course']) . '</td>';
        echo '<td>' . htmlspecialchars($s['chmsu_year']) . '</td>';
        echo '<td>' . htmlspecialchars($s['chmsu_section']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // SECTION 3: OFFICE PERFORMANCE
    echo '<div class="section-title">III. OFFICE PERFORMANCE REPORT</div>';
    
    $offices = $conn->query("SELECT o.office_name,
        (SELECT COUNT(*) FROM chmsu_requirements r WHERE r.chmsu_office = o.office_name) as total_req,
        (SELECT COUNT(*) FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id WHERE r.chmsu_office = o.office_name) as total_sub,
        (SELECT COUNT(*) FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id WHERE r.chmsu_office = o.office_name AND s.chmsu_status = 'Approved') as approved,
        (SELECT COUNT(*) FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id WHERE r.chmsu_office = o.office_name AND s.chmsu_status = 'Declined') as declined
        FROM chmsu_offices o ORDER BY o.office_name");
    
    echo '<table border="1">';
    echo '<tr><th>Office</th><th>Requirements</th><th>Submissions</th><th>Approved</th><th>Declined</th><th>Approval Rate</th></tr>';
    while ($o = $offices->fetch_assoc()) {
        $approval_rate = ($o['total_sub'] > 0) ? round(($o['approved'] / $o['total_sub']) * 100, 2) . '%' : '0%';
        echo '<tr>';
        echo '<td>' . htmlspecialchars($o['office_name']) . '</td>';
        echo '<td>' . $o['total_req'] . '</td>';
        echo '<td>' . $o['total_sub'] . '</td>';
        echo '<td>' . $o['approved'] . '</td>';
        echo '<td>' . $o['declined'] . '</td>';
        echo '<td>' . $approval_rate . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // SECTION 4: CLEARANCE STATUS
    echo '<div class="section-title">IV. STUDENT CLEARANCE STATUS</div>';
    
    $totalOfficesCount = $totalOffices;
    $clearanceStatus = $conn->query("SELECT u.chmsu_student_id, u.chmsu_name, u.chmsu_course, u.chmsu_year, u.chmsu_section,
        (SELECT COUNT(DISTINCT r.chmsu_office) FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id WHERE s.chmsu_student_id = u.chmsu_student_id AND s.chmsu_status = 'Approved') as approved_count
        FROM chmsu_user_accounts u ORDER BY u.chmsu_course, u.chmsu_year, u.chmsu_section LIMIT 50");
    
    echo '<table border="1">';
    echo '<tr><th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th>Section</th><th>Progress</th><th>Status</th></tr>';
    while ($cs = $clearanceStatus->fetch_assoc()) {
        $progress = $cs['approved_count'] . '/' . $totalOfficesCount;
        $status = ($cs['approved_count'] >= $totalOfficesCount) ? 'COMPLETE' : 'IN PROGRESS';
        echo '<tr>';
        echo '<td>' . htmlspecialchars($cs['chmsu_student_id']) . '</td>';
        echo '<td>' . htmlspecialchars($cs['chmsu_name']) . '</td>';
        echo '<td>' . htmlspecialchars($cs['chmsu_course']) . '</td>';
        echo '<td>' . htmlspecialchars($cs['chmsu_year']) . '</td>';
        echo '<td>' . htmlspecialchars($cs['chmsu_section']) . '</td>';
        echo '<td>' . $progress . '</td>';
        echo '<td>' . $status . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // SECTION 5: RECENT ACTIVITIES
    echo '<div class="section-title">V. RECENT SYSTEM ACTIVITIES</div>';
    
    $activities = $conn->query("SELECT user_id, user_type, action, DATE_FORMAT(created_at, '%M %d, %Y %h:%i %p') as created_at FROM chmsu_activity_log ORDER BY created_at DESC LIMIT 30");
    
    echo '<table border="1">';
    echo '<tr><th>User</th><th>Type</th><th>Action</th><th>Date/Time</th></tr>';
    while ($act = $activities->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($act['user_id']) . '</td>';
        echo '<td>' . ucfirst($act['user_type']) . '</td>';
        echo '<td>' . htmlspecialchars($act['action']) . '</td>';
        echo '<td>' . $act['created_at'] . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // SIGNATURE SECTION
    echo '<div class="signature-section">';
    echo '<div class="signature-box">';
    echo '<div class="signature-line"></div>';
    echo '<strong>DR. MA. TERESA L. MANALO</strong><br>';
    echo 'Registrar<br>';
    echo 'Date: ___________________';
    echo '</div>';
    echo '<div class="signature-box">';
    echo '<div class="signature-line"></div>';
    echo '<strong>DR. JULIUS A. SORIANO</strong><br>';
    echo 'Dean of Students<br>';
    echo 'Date: ___________________';
    echo '</div>';
    echo '</div>';
    
    // Footer
    echo '<div class="footer">';
    echo 'This is an official computer-generated report from the CHMSU E-Clearance System.<br>';
    echo 'For verification, please contact the Office of the Registrar.<br>';
    echo 'CHMSU E-Clearance System | ' . date('Y') . ' | All Rights Reserved';
    echo '</div>';
    
    echo '</body></html>';
    exit;
}

// Function to get student's current year level from masterlist
function getStudentYear($conn, $student_id) {
    $result = $conn->query("SELECT chmsu_year FROM chmsu_students_master WHERE chmsu_student_id = '$student_id'");
    if($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['chmsu_year'];
    }
    return '1';
}

// ============================================
// CHAT FUNCTIONS
// ============================================

function getChatUnreadCount($conn, $user_type, $user_id) {
    if ($user_type == 'office') {
        $count = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$user_id'")->fetch_assoc()['total'];
        return $count ?: 0;
    } elseif ($user_type == 'student') {
        $count = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='$user_id'")->fetch_assoc()['total'];
        return $count ?: 0;
    }
    return 0;
}

function getChatbotResponse($message) {
    global $conn;
    $message = strtolower(trim($message));
    
    // Check for exact matches first
    $faq = $conn->query("SELECT * FROM chmsu_chatbot_faq WHERE LOWER(keyword) = '$message' AND is_active = 1 LIMIT 1");
    if ($faq && $faq->num_rows > 0) {
        return $faq->fetch_assoc()['response'];
    }
    
    // Check for partial matches
    $faq = $conn->query("SELECT * FROM chmsu_chatbot_faq WHERE LOWER(keyword) LIKE '%$message%' AND is_active = 1 LIMIT 1");
    if ($faq && $faq->num_rows > 0) {
        return $faq->fetch_assoc()['response'];
    }
    
    // Check for keyword in message
    $all_faq = $conn->query("SELECT * FROM chmsu_chatbot_faq WHERE is_active = 1");
    while ($row = $all_faq->fetch_assoc()) {
        if (strpos($message, strtolower($row['keyword'])) !== false) {
            return $row['response'];
        }
    }
    
    // Fallback reply
    return "Sorry, I don't understand that. Please try asking about:\n- Clearance status\n- Requirements\n- How to submit\n- Deadlines\n- Office information\n\nType 'help' for more options.";
}

function getQuickReplies() {
    return [
        ['text' => 'Hello', 'value' => 'hello'],
        ['text' => 'Clearance Status', 'value' => 'clearance status'],
        ['text' => 'Requirements', 'value' => 'requirements'],
        ['text' => 'How to Submit', 'value' => 'how to submit'],
        ['text' => 'Deadlines', 'value' => 'deadlines'],
        ['text' => 'Help', 'value' => 'help'],
        ['text' => 'Thank You', 'value' => 'thank you']
    ];
}

// ============================================
// HISTORY HELPERS (for header dropdown panel)
// ============================================
function getHistoryUrl() {
    if (isset($_SESSION['admin']))  return '?adminsection=history';
    if (isset($_SESSION['office'])) {
        if ($_SESSION['office'] === 'Registrar') return '?section=history';
        return '?officesection=history';
    }
    if (isset($_SESSION['student'])) return '?view=history';
    return '';
}

function isHistoryActive() {
    if (isset($_GET['adminsection'])   && $_GET['adminsection'] === 'history')   return true;
    if (isset($_GET['officesection'])  && $_GET['officesection'] === 'history')  return true;
    if (isset($_GET['section'])        && $_GET['section'] === 'history')        return true;
    if (isset($_GET['view'])           && $_GET['view'] === 'history')           return true;
    return false;
}

function getCurrentRoleLabel() {
    if (isset($_SESSION['admin']))  return 'Admin';
    if (isset($_SESSION['office'])) {
        if ($_SESSION['office'] === 'Registrar') return 'Registrar';
        return 'Office';
    }
    if (isset($_SESSION['student'])) return 'Student';
    return 'Guest';
}
?>