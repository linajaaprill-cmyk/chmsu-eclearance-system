<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Handle actions
$error = '';
$success = '';
handleActions($conn, $error, $success);

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
    if ($view == 'home') {
        include 'modules/student/dashboard.php';
    } elseif ($view == 'office') {
        include 'modules/student/clearance.php';
    } elseif ($view == 'reports') {
        include 'modules/student/reports.php';
    } else {
        include 'modules/student/dashboard.php';
    }
}
// Check if user is logged in as REGISTRAR
elseif (isset($_SESSION['office']) && $_SESSION['office'] == 'Registrar') {
    $currentSection = isset($_GET['section']) ? $_GET['section'] : 'dashboard';
    
    if ($currentSection == 'dashboard') {
        include 'modules/registrar/dashboard.php';
    } elseif ($currentSection == 'masterlist') {
        include 'modules/registrar/masterlist.php';
    } elseif ($currentSection == 'clearance') {
        include 'modules/registrar/clearance.php';
    } elseif ($currentSection == 'requirements') {
        include 'modules/registrar/requirements.php';
    } elseif ($currentSection == 'submissions') {
        include 'modules/registrar/submissions.php';
    } elseif ($currentSection == 'reports') {
        include 'modules/registrar/reports.php';
    } else {
        include 'modules/registrar/dashboard.php';
    }
}
// Check if user is logged in as OTHER OFFICE
elseif (isset($_SESSION['office'])) {
    $currentOfficeSection = isset($_GET['officesection']) ? $_GET['officesection'] : 'dashboard';
    
    if ($currentOfficeSection == 'dashboard') {
        include 'modules/office/dashboard.php';
    } elseif ($currentOfficeSection == 'requirements') {
        include 'modules/office/requirements.php';
    } elseif ($currentOfficeSection == 'submissions') {
        include 'modules/office/submissions.php';
    } elseif ($currentOfficeSection == 'reports') {
        include 'modules/office/reports.php';
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