<?php
require_once __DIR__ . '/functions.php';

function handleActions($conn, &$error, &$success) {
    
    // ============================================
    // UNIFIED LOGIN (Used by your login page)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'unified_login') {
        $username = sanitize($_POST['username']);
        $password = $_POST['password'];
        
        // Check ADMIN
        $adminCheck = $conn->query("SELECT * FROM chmsu_admin_users WHERE username='$username'");
        if ($adminCheck && $adminCheck->num_rows > 0) {
            $admin = $adminCheck->fetch_assoc();
            if (password_verify($password, $admin['password'])) {
                $_SESSION['admin'] = $username;
                logActivity($conn, $username, 'admin', 'Logged in');
                header("Location: " . $_SERVER['PHP_SELF'] . "?admin=1");
                exit;
            } else {
                $error = "Wrong Password!";
            }
        }
        
        // Check OFFICE
        $officeCheck = $conn->query("SELECT * FROM chmsu_auth_roles WHERE chmsu_role='$username'");
        if ($officeCheck && $officeCheck->num_rows > 0) {
            $office = $officeCheck->fetch_assoc();
            if (password_verify($password, $office['chmsu_password'])) {
                $_SESSION['office'] = $username;
                logActivity($conn, $username, 'office', 'Logged in');
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $error = "Wrong Password!";
            }
        }
        
        // Check STUDENT
        $studentCheck = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$username'");
        if ($studentCheck && $studentCheck->num_rows > 0) {
            $student = $studentCheck->fetch_assoc();
            if (password_verify($password, $student['chmsu_password'])) {
                $_SESSION['student'] = $username;
                $conn->query("UPDATE chmsu_user_accounts SET last_login = NOW() WHERE chmsu_student_id='$username'");
                logActivity($conn, $username, 'student', 'Logged in');
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $error = "Wrong Password!";
            }
        }
        
        // If nothing matched
        if (empty($error)) {
            $error = "Invalid username/ID or password!";
        }
    }
    
    // ============================================
    // UNIFIED REGISTER (Used by your login page)
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
            // Check if user already exists
            $adminExists = $conn->query("SELECT * FROM chmsu_admin_users WHERE username='$username'")->num_rows;
            $officeExists = $conn->query("SELECT * FROM chmsu_auth_roles WHERE chmsu_role='$username'")->num_rows;
            $studentExists = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$username'")->num_rows;
            
            if ($adminExists > 0 || $officeExists > 0 || $studentExists > 0) {
                $error = "Username/ID already exists! Please choose another.";
            } else {
                // Check if STUDENT (exists in masterlist)
                $masterCheck = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$username'");
                
                if ($masterCheck && $masterCheck->num_rows > 0) {
                    // Register as STUDENT
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
                    } else {
                        $error = "Registration failed: " . $conn->error;
                    }
                } else {
                    // Check if ADMIN (first time only)
                    $adminCount = $conn->query("SELECT * FROM chmsu_admin_users")->num_rows;
                    
                    if ($adminCount == 0 && strtolower($username) == 'admin') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $insert = $conn->query("INSERT INTO chmsu_admin_users (username, password) VALUES ('$username', '$hash')");
                        
                        if ($insert) {
                            logActivity($conn, $username, 'admin', 'Admin account created');
                            $success = "Admin account created successfully! You can now login.";
                        } else {
                            $error = "Admin registration failed!";
                        }
                    } else {
                        // Check if OFFICE (exists in chmsu_offices)
                        $officeCheck = $conn->query("SELECT * FROM chmsu_offices WHERE office_name='$username'");
                        
                        if ($officeCheck && $officeCheck->num_rows > 0) {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            $authCheck = $conn->query("SELECT * FROM chmsu_auth_roles WHERE chmsu_role='$username'");
                            
                            if ($authCheck && $authCheck->num_rows == 0) {
                                $insert = $conn->query("INSERT INTO chmsu_auth_roles (chmsu_role, chmsu_password) VALUES ('$username', '$hash')");
                                
                                if ($insert) {
                                    logActivity($conn, $username, 'office', 'Office account created');
                                    $success = "Office account created successfully! You can now login.";
                                } else {
                                    $error = "Office registration failed!";
                                }
                            } else {
                                $error = "Office already has an account!";
                            }
                        } else {
                            $error = "Student ID not found in Master List! Contact Registrar for students, or use correct office name for personnel.";
                        }
                    }
                }
            }
        }
    }
    
    // ============================================
    // ADMIN LOGIN (Legacy - Keep for compatibility)
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
            } else {
                $error = "Wrong Password!";
            }
        } else {
            $error = "Admin not found!";
        }
    }
    
    // ============================================
    // CREATE ADMIN
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'create_admin' && !$conn->query("SELECT * FROM chmsu_admin_users")->num_rows) {
        $username = sanitize($_POST['username']);
        $pass = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        
        if ($pass !== $confirm) {
            $error = "password_mismatch";
        } elseif (!validatePassword($pass)) {
            $error = "Password must be 6+ characters with 1 uppercase and 1 number!";
        } else {
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
            } else {
                $error = "Wrong Password!";
            }
        } else {
            $error = "Student ID not found! Please register first.";
        }
    }
    
    // ============================================
    // STUDENT REGISTRATION (Legacy)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'register_student') {
        $id = sanitize($_POST['student_id']);
        $pass = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        
        if ($pass !== $confirm) {
            $error = "password_mismatch";
        } elseif (!validatePassword($pass)) {
            $error = "Password must be 6+ characters with 1 uppercase and 1 number!";
        } else {
            $master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$id'");
            if ($master->num_rows == 0) {
                $error = "Student ID not found in Master List! Contact Registrar.";
            } else {
                $exists = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$id'");
                if ($exists->num_rows > 0) {
                    $error = "Account already activated! Please login.";
                } else {
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
            } else {
                $error = "Wrong Password!";
            }
        } else {
            $error = "Office not registered! Please register first.";
        }
    }
    
    // ============================================
    // REGISTER OFFICE (Legacy)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'register_office') {
        $role = sanitize($_POST['role']);
        $pass = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        
        if ($pass !== $confirm) {
            $error = "password_mismatch";
        } elseif (!validatePassword($pass)) {
            $error = "Invalid password format!";
        } else {
            $officeExists = $conn->query("SELECT * FROM chmsu_offices WHERE office_name='$role'");
            if ($officeExists->num_rows == 0) {
                $error = "Office not found in system! Contact administrator.";
            } else {
                $exists = $conn->query("SELECT * FROM chmsu_auth_roles WHERE chmsu_role='$role'");
                if ($exists->num_rows > 0) {
                    $error = "Office already registered!";
                } else {
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
        
        if ($age < 15) {
            $error = "Student must be at least 15 years old!";
        } else {
            $lastInitial = strtoupper(substr($last, 0, 1));
            $firstInitial = strtoupper(substr($first, 0, 1));
            $middleInitial = strtoupper(substr($middle, 0, 1));
            $month = date("m", strtotime($birth));
            $day = date("d", strtotime($birth));
            $yearShort = date("y", strtotime($birth));
            $id = $lastInitial . $firstInitial . $middleInitial . $month . $day . $yearShort . "00";
            
            $check = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$id'");
            if ($check->num_rows > 0) {
                $id = substr($id, 0, -2) . "01";
            }
            
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
        if ($check->num_rows > 0) {
            $error = "Course already exists!";
        } else {
            $conn->query("INSERT INTO chmsu_courses (course_code, course_name) VALUES ('$course_code', '$course_name')");
            logActivity($conn, $_SESSION['admin'], 'admin', "Added course: $course_code");
            $success = "Course added successfully!";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=courses");
        exit;
    }
    
    // ============================================
    // DELETE COURSE (Admin only)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'delete_course' && isset($_SESSION['admin'])) {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM chmsu_courses WHERE id=$id");
        logActivity($conn, $_SESSION['admin'], 'admin', "Deleted course ID: $id");
        $success = "Course deleted successfully!";
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=courses");
        exit;
    }
    
    // ============================================
    // ADD OFFICE (Admin only)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'add_office' && isset($_SESSION['admin'])) {
        $office_name = sanitize($_POST['office_name']);
        
        $check = $conn->query("SELECT * FROM chmsu_offices WHERE office_name='$office_name'");
        if ($check->num_rows > 0) {
            $error = "Office already exists!";
        } else {
            $conn->query("INSERT INTO chmsu_offices (office_name) VALUES ('$office_name')");
            logActivity($conn, $_SESSION['admin'], 'admin', "Added office: $office_name");
            $success = "Office added successfully!";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=offices");
        exit;
    }
    
    // ============================================
    // DELETE OFFICE (Admin only)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'delete_office' && isset($_SESSION['admin'])) {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM chmsu_offices WHERE id=$id");
        logActivity($conn, $_SESSION['admin'], 'admin', "Deleted office ID: $id");
        $success = "Office deleted successfully!";
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
        if ($check->num_rows > 0) {
            $error = "Section already exists for this course and year!";
        } else {
            $conn->query("INSERT INTO chmsu_course_sections (course, year, section_name) VALUES ('$course', '$year', '$section_name')");
            logActivity($conn, $_SESSION['admin'], 'admin', "Added section: $course-$year-$section_name");
            $success = "Section added successfully!";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?adminsection=sections");
        exit;
    }
    
    // ============================================
    // DELETE SECTION (Admin only)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'delete_section' && isset($_SESSION['admin'])) {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM chmsu_course_sections WHERE id=$id");
        logActivity($conn, $_SESSION['admin'], 'admin', "Deleted section ID: $id");
        $success = "Section deleted successfully!";
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
        
        $file_path = '';
        $file_type = '';
        $file_name = '';
        
        if (isset($_FILES['req_file']) && $_FILES['req_file']['error'] == 0) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
            $filename = $_FILES['req_file']['name'];
            $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($filetype, $allowed) && $_FILES['req_file']['size'] <= MAX_FILE_SIZE) {
                $newname = UPLOAD_DIR . 'req_' . $office . '_' . time() . '_' . basename($filename);
                if (move_uploaded_file($_FILES['req_file']['tmp_name'], $newname)) {
                    $file_path = $newname;
                    $file_type = $filetype;
                    $file_name = $filename;
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
    // DELETE REQUIREMENT
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'delete_requirement' && isset($_SESSION['office'])) {
        $req_id = intval($_POST['req_id']);
        $subs = $conn->query("SELECT chmsu_file_path FROM chmsu_submissions WHERE chmsu_requirement_id=$req_id");
        while ($sub = $subs->fetch_assoc()) {
            if ($sub['chmsu_file_path'] && file_exists($sub['chmsu_file_path'])) {
                unlink($sub['chmsu_file_path']);
            }
        }
        $req = $conn->query("SELECT chmsu_file_path FROM chmsu_requirements WHERE chmsu_id=$req_id")->fetch_assoc();
        if ($req['chmsu_file_path'] && file_exists($req['chmsu_file_path'])) {
            unlink($req['chmsu_file_path']);
        }
        $conn->query("DELETE FROM chmsu_submissions WHERE chmsu_requirement_id=$req_id");
        $conn->query("DELETE FROM chmsu_requirements WHERE chmsu_id=$req_id");
        logActivity($conn, $_SESSION['office'], 'office', "Deleted requirement ID: $req_id");
        $success = "Requirement deleted!";
        
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
        if ($req_check->num_rows == 0) {
            $error = "Requirement not found!";
        } else {
            $req = $req_check->fetch_assoc();
            
            if ($req['chmsu_deadline'] && strtotime($req['chmsu_deadline']) < time()) {
                $error = "Deadline has passed! Cannot submit.";
            } else {
                $existing = $conn->query("SELECT * FROM chmsu_submissions WHERE chmsu_requirement_id=$req_id AND chmsu_student_id='$student_id'");
                if ($existing->num_rows > 0) {
                    $error = "You have already submitted this requirement!";
                } else {
                    $file_path = '';
                    $file_type = '';
                    $file_name = '';
                    $submission_text = '';
                    $submission_link = '';
                    
                    if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
                        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
                        $filename = $_FILES['file']['name'];
                        $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        
                        if (!in_array($filetype, $allowed)) {
                            $error = "Invalid file type! Allowed: PDF, Images, DOC, XLS, PPT, TXT";
                        } elseif ($_FILES['file']['size'] > MAX_FILE_SIZE) {
                            $error = "File too large! Max size: 10MB";
                        } else {
                            $newname = UPLOAD_DIR . $student_id . '_' . time() . '_' . basename($filename);
                            if (move_uploaded_file($_FILES['file']['tmp_name'], $newname)) {
                                $file_path = $newname;
                                $file_type = $filetype;
                                $file_name = $filename;
                            } else {
                                $error = "Failed to upload file!";
                            }
                        }
                    } elseif (!empty($link)) {
                        if (filter_var($link, FILTER_VALIDATE_URL)) {
                            $submission_link = $link;
                            $file_type = 'link';
                            $file_name = 'External Link';
                        } else {
                            $error = "Invalid URL!";
                        }
                    } elseif (!empty($text)) {
                        $submission_text = $text;
                    }
                    
                    if (empty($error) && ($file_path || $submission_text || $submission_link)) {
                        $sql = "INSERT INTO chmsu_submissions (chmsu_requirement_id, chmsu_student_id, chmsu_text, chmsu_link, chmsu_file_path, chmsu_file_type, chmsu_file_name, chmsu_status) 
                                VALUES ($req_id, '$student_id', '$submission_text', '$submission_link', '$file_path', '$file_type', '$file_name', 'Submitted')";
                        
                        if ($conn->query($sql)) {
                            logActivity($conn, $student_id, 'student', "Submitted requirement ID: $req_id");
                            $success = "Requirement submitted successfully!";
                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=office&office=" . urlencode($req['chmsu_office']) . "&req=" . $req_id);
                            exit;
                        } else {
                            $error = "Database error: " . $conn->error;
                        }
                    } else {
                        $error = "Please provide a file, link, or text!";
                    }
                }
            }
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=office&office=" . urlencode($req['chmsu_office']) . "&req=" . $req_id);
        exit;
    }
    
    // ============================================
    // UNSUBMIT (Delete submission)
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] == 'unsubmit' && isset($_SESSION['student'])) {
        $sub_id = intval($_POST['sub_id']);
        $sub = $conn->query("SELECT * FROM chmsu_submissions WHERE chmsu_id=$sub_id AND chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
        
        if ($sub) {
            if ($sub['chmsu_file_path'] && file_exists($sub['chmsu_file_path'])) {
                unlink($sub['chmsu_file_path']);
            }
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
        $sub = $conn->query("SELECT s.*, r.chmsu_office FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE s.chmsu_id=$sub_id")->fetch_assoc();
        
        if ($sub['chmsu_office'] == $_SESSION['office']) {
            $conn->query("UPDATE chmsu_submissions SET chmsu_status='Approved', chmsu_office_action='Approved', chmsu_action_taken_at=NOW() WHERE chmsu_id=$sub_id");
            logActivity($conn, $_SESSION['office'], 'office', "Approved submission ID: $sub_id");
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
        $sub = $conn->query("SELECT s.*, r.chmsu_office FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id WHERE s.chmsu_id=$sub_id")->fetch_assoc();
        
        if ($sub['chmsu_office'] == $_SESSION['office']) {
            $conn->query("UPDATE chmsu_submissions SET chmsu_status='Declined', chmsu_reject_reason='$reason', chmsu_office_action='Declined', chmsu_action_taken_at=NOW() WHERE chmsu_id=$sub_id");
            logActivity($conn, $_SESSION['office'], 'office', "Declined submission ID: $sub_id");
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
            
            if (!in_array($ext, ['xls', 'xlsx', 'csv'])) {
                $error = "Please upload Excel file (xls, xlsx, or csv)";
            } else {
                $file = fopen($_FILES['excel_file']['tmp_name'], 'r');
                $row_count = 0;
                $imported = 0;
                $errors = [];
                
                $header = fgetcsv($file);
                
                while (($data = fgetcsv($file)) !== FALSE) {
                    $row_count++;
                    
                    if (count($data) < 7) {
                        $errors[] = "Row $row_count: Insufficient data";
                        continue;
                    }
                    
                    $last = sanitize($data[0]);
                    $first = sanitize($data[1]);
                    $middle = sanitize($data[2]);
                    $birth = trim($data[3]);
                    $course = sanitize($data[4]);
                    $year = sanitize($data[5]);
                    $section = sanitize($data[6]);
                    
                    $birthDate = DateTime::createFromFormat('Y-m-d', $birth);
                    if (!$birthDate) {
                        $errors[] = "Row $row_count: Invalid birthdate format (use YYYY-MM-DD)";
                        continue;
                    }
                    
                    $today = new DateTime();
                    $age = $today->diff($birthDate)->y;
                    
                    if ($age < 15) {
                        $errors[] = "Row $row_count: Student must be at least 15 years old";
                        continue;
                    }
                    
                    $lastInitial = strtoupper(substr($last, 0, 1));
                    $firstInitial = strtoupper(substr($first, 0, 1));
                    $middleInitial = strtoupper(substr($middle, 0, 1));
                    $month = $birthDate->format('m');
                    $day = $birthDate->format('d');
                    $yearShort = $birthDate->format('y');
                    $id = $lastInitial . $firstInitial . $middleInitial . $month . $day . $yearShort . "00";
                    
                    $check = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$id'");
                    if ($check->num_rows > 0) {
                        $id = substr($id, 0, -2) . "01";
                    }
                    
                    $fullName = "$last, $first, $middle";
                    
                    $sql = "INSERT INTO chmsu_students_master 
                            (chmsu_student_id, chmsu_last_name, chmsu_first_name, chmsu_middle_name, 
                             chmsu_full_name, chmsu_birthdate, chmsu_course, chmsu_year, chmsu_section) 
                            VALUES ('$id', '$last', '$first', '$middle', '$fullName', '$birth', '$course', '$year', '$section')";
                    
                    if ($conn->query($sql)) {
                        $imported++;
                    } else {
                        $errors[] = "Row $row_count: " . $conn->error;
                    }
                }
                
                fclose($file);
                
                if ($imported > 0) {
                    logActivity($conn, $_SESSION['office'], 'office', "Imported $imported students");
                    $success = "Successfully imported $imported students";
                    if (!empty($errors)) {
                        $success .= ". " . count($errors) . " errors occurred.";
                    }
                } else {
                    $error = "No students imported. " . implode("<br>", array_slice($errors, 0, 5));
                }
            }
        } else {
            $error = "Please select a file to upload";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?section=masterlist");
        exit;
    }
}
?>