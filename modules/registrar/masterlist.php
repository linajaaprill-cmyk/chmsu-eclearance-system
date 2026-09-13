<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['office']) || $_SESSION['office'] != 'Registrar') {
    header("Location: ../index.php");
    exit;
}

$office = $_SESSION['office'];
$currentSection = 'masterlist';
$error = '';
$success = '';
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

$filter_course = isset($_GET['filter_course']) ? $_GET['filter_course'] : '';
$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';
$filter_section = isset($_GET['filter_section']) ? $_GET['filter_section'] : '';

// Build WHERE conditions for initial load
$conditions = array();
$conditions[] = "is_archived = 0";
if($filter_course) $conditions[] = "chmsu_course = '$filter_course'";
if($filter_year) $conditions[] = "chmsu_year = '$filter_year'";
if($filter_section) $conditions[] = "chmsu_section = '$filter_section'";
$where_sql = "WHERE " . implode(" AND ", $conditions);

// Handle Delete Single Student
if (isset($_POST['delete_single']) && isset($_POST['student_id'])) {
    $student_id = sanitize($_POST['student_id']);
    $check = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'");
    if ($check->num_rows > 0) {
        $error = "Cannot delete student with existing account. Remove account first.";
    } else {
        $conn->query("DELETE FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
        logActivity($conn, $office, 'registrar', "Deleted student: $student_id");
        $success = "Student deleted successfully!";
    }
    header("Location: ?section=masterlist");
    exit;
}

// Handle Bulk Delete
if (isset($_POST['bulk_delete']) && isset($_POST['selected_students'])) {
    $selected = $_POST['selected_students'];
    $deleted = 0;
    $errors = 0;
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
        $success = "$deleted students deleted successfully!";
    }
    if ($errors > 0) {
        $error = "$errors students have active accounts and cannot be deleted.";
    }
    header("Location: ?section=masterlist");
    exit;
}

// Handle Edit Student
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
    $success = "Student updated successfully!";
    header("Location: ?section=masterlist");
    exit;
}

// Handle Add Irregular Student
if (isset($_POST['action']) && $_POST['action'] == 'add_irregular') {
    $student_id = sanitize($_POST['student_id']);
    $masterCheck = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
    if ($masterCheck->num_rows == 0) {
        $error = "Student ID not found in Master List!";
    } else {
        $student = $masterCheck->fetch_assoc();
        if ($student['is_irregular'] == 1) {
            $error = "Student is already marked as Irregular!";
        } elseif ($student['is_archived'] == 1) {
            $error = "Student is already archived!";
        } else {
            $conn->query("UPDATE chmsu_students_master SET is_irregular = 1 WHERE chmsu_student_id='$student_id'");
            logActivity($conn, $office, 'registrar', "Marked student as Irregular: $student_id");
            $success = "Student marked as Irregular successfully!";
        }
    }
    header("Location: ?section=masterlist");
    exit;
}

// AJAX endpoint to get student info
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

// AJAX endpoint to get sections for the FILTER dropdown based on course and year
if (isset($_GET['get_filter_sections'])) {
    header('Content-Type: application/json');
    $course = sanitize($_GET['course']);
    $year = sanitize($_GET['year']);
    
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

// AJAX endpoint to get sections for the ADD FORM dropdown
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

// AJAX handler for filtering table (NO PAGE REFRESH)
if (isset($_GET['ajax_filter'])) {
    $filter_course = isset($_GET['filter_course']) ? sanitize($_GET['filter_course']) : '';
    $filter_year = isset($_GET['filter_year']) ? sanitize($_GET['filter_year']) : '';
    $filter_section = isset($_GET['filter_section']) ? sanitize($_GET['filter_section']) : '';
    
    $conditions = array();
    $conditions[] = "is_archived = 0";
    if($filter_course) $conditions[] = "chmsu_course = '$filter_course'";
    if($filter_year) $conditions[] = "chmsu_year = '$filter_year'";
    if($filter_section) $conditions[] = "chmsu_section = '$filter_section'";
    $where_sql = "WHERE " . implode(" AND ", $conditions);
    
    $students = $conn->query("SELECT * FROM chmsu_students_master $where_sql ORDER BY chmsu_course, chmsu_year, chmsu_section, chmsu_last_name");
    
    $output = '';
    if($students && $students->num_rows > 0):
        while($s = $students->fetch_assoc()):
            $hasAccount = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='{$s['chmsu_student_id']}'")->num_rows > 0;
            $isIrregular = $s['is_irregular'] == 1;
            $output .= '<tr data-name="' . strtolower($s['chmsu_full_name']) . '">';
            $output .= '<td><input type="checkbox" name="selected_students[]" value="' . $s['chmsu_student_id'] . '" class="student-checkbox"></td>';
            $output .= '<td><code>' . $s['chmsu_student_id'] . '</code></td>';
            $output .= '<td>' . htmlspecialchars($s['chmsu_last_name']) . '</td>';
            $output .= '<td>' . htmlspecialchars($s['chmsu_first_name']) . '</td>';
            $output .= '<td>' . htmlspecialchars($s['chmsu_middle_name']) . '</td>';
            $output .= '<td>' . $s['chmsu_course'] . '</td>';
            $output .= '<td>' . $s['chmsu_year'] . '</td>';
            $output .= '<td>' . htmlspecialchars($s['chmsu_section']) . '</td>';
            $output .= '<td>' . date('F d, Y', strtotime($s['chmsu_birthdate'])) . '</td>';
            $output .= '<td>';
            if($isIrregular) {
                $output .= '<span class="badge-irregular"><i class="fas fa-exclamation-triangle"></i> Irregular</span>';
            } else {
                $output .= '<span class="badge-regular">Regular</span>';
            }
            $output .= '</td>';
            $output .= '<td style="white-space: nowrap;">';
            $output .= '<button class="btn btn-warning btn-sm" onclick="openEditModal(\'' . $s['chmsu_student_id'] . '\', \'' . addslashes($s['chmsu_last_name']) . '\', \'' . addslashes($s['chmsu_first_name']) . '\', \'' . addslashes($s['chmsu_middle_name']) . '\', \'' . $s['chmsu_birthdate'] . '\', \'' . $s['chmsu_course'] . '\', \'' . $s['chmsu_year'] . '\', \'' . addslashes($s['chmsu_section']) . '\', ' . ($isIrregular ? 'true' : 'false') . ')"><i class="fas fa-edit"></i> Edit</button>';
            if (!$hasAccount) {
                $output .= '<button class="btn btn-danger btn-sm" onclick="deleteSingleStudent(\'' . $s['chmsu_student_id'] . '\')"><i class="fas fa-trash"></i> Delete</button>';
            } else {
                $output .= '<button class="btn btn-back btn-sm" disabled title="Cannot delete - student has active account"><i class="fas fa-lock"></i> Has Account</button>';
            }
            $output .= '</td>';
            $output .= '</tr>';
        endwhile;
    else:
        $output .= '<tr><td colspan="11" style="text-align: center;">No students found</td></tr>';
    endif;
    
    echo $output;
    exit;
}

// Get total counts for display
$totalQuery = $conn->query("SELECT COUNT(*) as total FROM chmsu_students_master $where_sql");
$totalFiltered = $totalQuery->fetch_assoc()['total'];

$totalAllQuery = $conn->query("SELECT COUNT(*) as total FROM chmsu_students_master WHERE is_archived = 0");
$totalAll = $totalAllQuery->fetch_assoc()['total'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Master List</title>
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
        .dark-mode-toggle {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 6px 12px;
            cursor: pointer;
            margin-left: auto;
        }
        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 15px;
            border: none;
            cursor: pointer;
        }
        
        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }
        
        .registrar-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .registrar-sidebar::-webkit-scrollbar { width: 5px; }
        .registrar-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .registrar-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .registrar-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .registrar-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .registrar-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
        }
        .registrar-sidebar .sidebar-menu a:hover,
        .registrar-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
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
        }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }
        
        .content-card {
            background: white;
            border: 1px solid #ddd;
            margin-bottom: 20px;
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
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #333; font-size: 12px; }
        input, select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            font-size: 13px;
        }
        input:focus, select:focus { outline: none; border-color: #1b4d3e; }
        
        .btn {
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-primary:hover { background: #2d6a4f; }
        .btn-back { background: #7f8c8d; color: white; }
        .btn-back:hover { background: #6c7a7d; }
        .btn-excel { background: #27ae60; color: white; }
        .btn-word { background: #1b4d3e; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
        
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 15px; }
        .search-box { width: 100%; padding: 8px 12px; border: 1px solid #ddd; margin-bottom: 15px; }
        .export-buttons { display: flex; gap: 10px; margin-bottom: 15px; }
        
        .student-count-bar {
            background: #e8f5e9;
            padding: 10px 15px;
            margin-bottom: 15px;
            border-left: 4px solid #27ae60;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .student-count-bar span.count {
            font-size: 20px;
            font-weight: bold;
            color: #1b4d3e;
        }
        .bulk-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .plain-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .plain-table th, .plain-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            vertical-align: middle;
        }
        .plain-table th { background: #f8f9fa; font-weight: normal; }
        .plain-table input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
        
        .badge-irregular {
            background: #e74c3c;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            display: inline-block;
        }
        .badge-regular {
            background: #27ae60;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            display: inline-block;
        }
        
        .hidden { display: none; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            max-width: 500px;
            width: 90%;
            padding: 25px;
            border-radius: 5px;
        }
        .modal-content h3 { margin-bottom: 20px; color: #1b4d3e; }
        .modal-buttons { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .plain-table th { background: #2c2c2c; color: #fff; border-color: #444; }
        body.dark-mode .plain-table td { border-color: #333; color: #fff; }
        body.dark-mode input, body.dark-mode select { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .student-count-bar { background: #1a3a2a; border-left-color: #27ae60; }
        body.dark-mode .bulk-actions { background: #2c2c2c; }
        body.dark-mode .modal-content { background: #1a1a1a; color: #fff; }
        
        @media (max-width: 768px) {
            .registrar-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .plain-table { font-size: 10px; }
            .plain-table th, .plain-table td { padding: 5px; }
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
        <p>CLEARANCE SYSTEM | Registrar Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="registrar-sidebar">
        <div class="sidebar-header">
            <h3>Registrar Portal</h3>
            <p>Student Records Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?section=masterlist" class="active"><i class="fas fa-users"></i> Master List</a></li>
            <li><a href="?section=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?section=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?section=archived"><i class="fas fa-archive"></i> Archive</a></li>
            <li><a href="?section=promotion"><i class="fas fa-arrow-up"></i> Student Promotion</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
        <div style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Master Student List</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- ADD IRREGULAR STUDENT FORM -->
        <div class="content-card" style="border-left: 4px solid #e74c3c;">
            <div class="content-card-header" style="background: #fce8e6;">
                <span><i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> Add Irregular Student (Only Student ID Required)</span>
            </div>
            <div class="content-card-body">
                <form method="POST" id="irregularForm" onsubmit="return validateIrregularForm()">
                    <input type="hidden" name="action" value="add_irregular">
                    <div class="filter-row">
                        <div class="form-group" style="flex: 0 0 250px;">
                            <label>Student ID <span style="color: #e74c3c;">*</span></label>
                            <input type="text" name="student_id" id="irregularStudentId" placeholder="Enter Student ID" required onchange="fetchStudentInfo()">
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn" style="background: #e74c3c; color: white;">
                                <i class="fas fa-exclamation-triangle"></i> Mark as Irregular Student
                            </button>
                        </div>
                    </div>
                    <div id="studentInfoPreview" style="background: #f8f9fa; padding: 15px; margin-top: 15px; display: none; border-left: 4px solid #e74c3c;">
                        <h4 style="color: #e74c3c; margin-bottom: 10px;">Student Information (Auto-filled)</h4>
                        <div class="filter-row">
                            <div class="form-group" style="flex: 2;"><label>Full Name</label><input type="text" id="previewFullName" class="form-control" readonly style="background: #e8f5e9;"></div>
                            <div class="form-group" style="flex: 1;"><label>Birthdate</label><input type="text" id="previewBirthdate" class="form-control" readonly></div>
                        </div>
                        <div class="filter-row">
                            <div class="form-group" style="flex: 1;"><label>Course</label><input type="text" id="previewCourse" class="form-control" readonly></div>
                            <div class="form-group" style="flex: 0 0 100px;"><label>Year</label><input type="text" id="previewYear" class="form-control" readonly></div>
                            <div class="form-group" style="flex: 0 0 100px;"><label>Section</label><input type="text" id="previewSection" class="form-control" readonly></div>
                        </div>
                        <div class="form-group">
                            <label style="color: #e74c3c;"><input type="checkbox" name="is_irregular" checked disabled> This student will be marked as IRREGULAR</label>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- REGULAR ADD STUDENT FORM -->
        <div class="content-card">
            <div class="content-card-header">
                <span>Add New Regular Student</span>
                <div style="display: flex; gap: 5px;">
                    <button class="btn btn-primary btn-sm" onclick="toggleForm('import-student-form')"><i class="fas fa-upload"></i> Import Excel</button>
                    <button class="btn btn-primary btn-sm" onclick="toggleForm('add-student-form')"><i class="fas fa-plus"></i> Add Student</button>
                </div>
            </div>
            <div class="content-card-body">
                <div id="import-student-form" class="hidden" style="background:#f8f9fa; padding:12px; margin-bottom:12px;">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import_students">
                        <div style="display: flex; gap: 10px; align-items: flex-end;">
                            <div class="form-group" style="flex: 1;">
                                <label>Excel File (.xls, .xlsx, .csv)</label>
                                <input type="file" name="excel_file" accept=".xls,.xlsx,.csv" required>
                                <small>Format: Last Name, First Name, Middle Name, Birthdate (YYYY-MM-DD), Course, Year, Section</small>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Import</button>
                            <button type="button" class="btn btn-back btn-sm" onclick="toggleForm('import-student-form')">Cancel</button>
                        </div>
                    </form>
                </div>
                
                <div id="add-student-form" class="hidden" style="background:#f8f9fa; padding:12px; margin-bottom:12px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_master">
                        <div class="filter-row">
                            <div class="form-group"><label>Last Name</label><input type="text" name="last_name" id="lastName" required oninput="updateGeneratedID()"></div>
                            <div class="form-group"><label>First Name</label><input type="text" name="first_name" id="firstName" required oninput="updateGeneratedID()"></div>
                            <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name" id="middleName" required oninput="updateGeneratedID()"></div>
                        </div>
                        <div class="filter-row">
                            <div class="form-group"><label>Birthdate</label><input type="date" name="birthdate" id="birthdate" required onchange="updateGeneratedID()"></div>
                            <div class="form-group"><label>Generated ID</label><div id="generatedID" style="background:#1b4d3e; color:white; padding:6px; border-radius:4px; text-align:center; font-size:11px;">---</div></div>
                        </div>
                        <div class="filter-row">
                            <div class="form-group">
                                <label>Course</label>
                                <select name="course" id="addCourseSelect" required onchange="loadSectionsForAdd()">
                                    <option value="">Select Course</option>
                                    <?php $courses = $conn->query("SELECT * FROM chmsu_courses ORDER BY course_code"); while($c=$courses->fetch_assoc()): ?>
                                    <option value="<?php echo $c['course_code']; ?>"><?php echo $c['course_code']; ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Year</label>
                                <select name="year" id="addYearSelect" required onchange="loadSectionsForAdd()">
                                    <option value="">Select Year</option>
                                    <option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Section</label>
                                <select name="section" id="addSectionSelect" required>
                                    <option value="">Select Course and Year First</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Add Student</button>
                        <button type="button" class="btn btn-back btn-sm" onclick="toggleForm('add-student-form')">Cancel</button>
                    </form>
                </div>
                
                <!-- FILTER ROW - DYNAMIC SECTION DROPDOWN -->
                <div class="filter-row">
                    <div class="form-group">
                        <label>Course</label>
                        <select id="filter_course_select" onchange="loadFilterSections()">
                            <option value="">All Courses</option>
                            <?php $courses = $conn->query("SELECT * FROM chmsu_courses ORDER BY course_code"); while($c=$courses->fetch_assoc()): ?>
                            <option value="<?php echo $c['course_code']; ?>" <?php echo $filter_course==$c['course_code']?'selected':''; ?>><?php echo $c['course_code']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Year Level</label>
                        <select id="filter_year_select" onchange="loadFilterSections()">
                            <option value="">All Years</option>
                            <option value="1" <?php echo $filter_year=='1'?'selected':''; ?>>1st</option>
                            <option value="2" <?php echo $filter_year=='2'?'selected':''; ?>>2nd</option>
                            <option value="3" <?php echo $filter_year=='3'?'selected':''; ?>>3rd</option>
                            <option value="4" <?php echo $filter_year=='4'?'selected':''; ?>>4th</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Section</label>
                        <select id="filter_section_select" onchange="filterMasterList()">
                            <option value="">All Sections</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <a href="?section=masterlist" class="btn btn-primary btn-sm" onclick="resetFilters(); return false;">Clear Filters</a>
                    </div>
                </div>
                
                <!-- STUDENT COUNT BAR -->
                <div class="student-count-bar" id="studentCountBar">
                    <div>
                        <i class="fas fa-users"></i> 
                        <strong>Total Students: <span class="count" id="totalCount"><?php echo $totalFiltered; ?></span></strong>
                        <?php if ($filter_course || $filter_year || $filter_section): ?>
                            <span style="font-size: 11px; color: #666;"> (Filtered from <?php echo $totalAll; ?> total)</span>
                        <?php endif; ?>
                    </div>
                    <div id="filterBadges">
                        <?php if ($filter_course): ?><span class="badge">Course: <?php echo htmlspecialchars($filter_course); ?></span><?php endif; ?>
                        <?php if ($filter_year): ?><span class="badge">Year: <?php echo $filter_year; ?></span><?php endif; ?>
                        <?php if ($filter_section): ?><span class="badge">Section: <?php echo htmlspecialchars($filter_section); ?></span><?php endif; ?>
                    </div>
                </div>
                
                <!-- EXPORT BUTTONS -->
                <div class="export-buttons">
                    <a href="?export=excel&course=<?php echo $filter_course; ?>&year=<?php echo $filter_year; ?>&section=<?php echo $filter_section; ?>" class="btn btn-excel">Export Excel</a>
                    <a href="?export=word&course=<?php echo $filter_course; ?>&year=<?php echo $filter_year; ?>&section=<?php echo $filter_section; ?>" class="btn btn-word">Export Word</a>
                </div>
                
                <!-- BULK DELETE ACTIONS -->
                <form method="POST" id="bulkDeleteForm" onsubmit="return confirmBulkDelete()">
                    <div class="bulk-actions">
                        <label style="display: flex; align-items: center; gap: 5px;">
                            <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll()"> 
                            <strong>Select All</strong>
                        </label>
                        <button type="submit" name="bulk_delete" class="btn btn-danger btn-sm" onclick="return confirmBulkDelete()">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                        <span style="font-size: 11px; color: #666;">Note: Students with existing accounts cannot be deleted</span>
                    </div>
                    
                    <input type="text" class="search-box" id="masterSearch" placeholder="Search student by name..." onkeyup="searchMasterList()">
                    
                    <div class="table-responsive">
                        <table class="plain-table" id="masterTable">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"><input type="checkbox" id="selectAllHeader" onclick="toggleSelectAllFromHeader()"></th>
                                    <th>Student ID</th>
                                    <th>Last Name</th>
                                    <th>First Name</th>
                                    <th>Middle Name</th>
                                    <th>Course</th>
                                    <th>Year</th>
                                    <th>Section</th>
                                    <th>Birthdate</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="masterTableBody">
                                <?php
                                $students = $conn->query("SELECT * FROM chmsu_students_master $where_sql ORDER BY chmsu_course, chmsu_year, chmsu_section, chmsu_last_name");
                                if($students->num_rows > 0):
                                    while($s = $students->fetch_assoc()):
                                        $hasAccount = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='{$s['chmsu_student_id']}'")->num_rows > 0;
                                        $isIrregular = $s['is_irregular'] == 1;
                                ?>
                                <tr data-name="<?php echo strtolower($s['chmsu_full_name']); ?>">
                                    <td><input type="checkbox" name="selected_students[]" value="<?php echo $s['chmsu_student_id']; ?>" class="student-checkbox"></td>
                                    <td><code><?php echo $s['chmsu_student_id']; ?></code></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_first_name']); ?></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_middle_name']); ?></td>
                                    <td><?php echo $s['chmsu_course']; ?></td>
                                    <td><?php echo $s['chmsu_year']; ?></td>
                                    <td><?php echo htmlspecialchars($s['chmsu_section']); ?></td>
                                    <td><?php echo date('F d, Y', strtotime($s['chmsu_birthdate'])); ?></td>
                                    <td>
                                        <?php if($isIrregular): ?>
                                            <span class="badge-irregular"><i class="fas fa-exclamation-triangle"></i> Irregular</span>
                                        <?php else: ?>
                                            <span class="badge-regular">Regular</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <button class="btn btn-warning btn-sm" onclick="openEditModal('<?php echo $s['chmsu_student_id']; ?>', '<?php echo addslashes($s['chmsu_last_name']); ?>', '<?php echo addslashes($s['chmsu_first_name']); ?>', '<?php echo addslashes($s['chmsu_middle_name']); ?>', '<?php echo $s['chmsu_birthdate']; ?>', '<?php echo $s['chmsu_course']; ?>', '<?php echo $s['chmsu_year']; ?>', '<?php echo addslashes($s['chmsu_section']); ?>', <?php echo $isIrregular ? 'true' : 'false'; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php if (!$hasAccount): ?>
                                        <button class="btn btn-danger btn-sm" onclick="deleteSingleStudent('<?php echo $s['chmsu_student_id']; ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-back btn-sm" disabled title="Cannot delete - student has active account">
                                            <i class="fas fa-lock"></i> Has Account
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <tr>
                                    <td colspan="11" style="text-align: center;">No students found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- EDIT STUDENT MODAL -->
<div class="modal-overlay" id="editModal">
    <div class="modal-content">
        <h3><i class="fas fa-edit"></i> Edit Student</h3>
        <form method="POST" id="editForm">
            <input type="hidden" name="edit_student" value="1">
            <input type="hidden" name="original_id" id="editOriginalId">
            <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="last_name" id="editLastName" required>
            </div>
            <div class="form-group">
                <label>First Name</label>
                <input type="text" name="first_name" id="editFirstName" required>
            </div>
            <div class="form-group">
                <label>Middle Name</label>
                <input type="text" name="middle_name" id="editMiddleName" required>
            </div>
            <div class="form-group">
                <label>Birthdate</label>
                <input type="date" name="birthdate" id="editBirthdate" required>
            </div>
            <div class="form-group">
                <label>Course</label>
                <select name="course" id="editCourse" required>
                    <option value="">Select Course</option>
                    <?php $courses = $conn->query("SELECT * FROM chmsu_courses"); while($c=$courses->fetch_assoc()): ?>
                    <option value="<?php echo $c['course_code']; ?>"><?php echo $c['course_code']; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Year</label>
                <select name="year" id="editYear" required>
                    <option value="1">1st Year</option>
                    <option value="2">2nd Year</option>
                    <option value="3">3rd Year</option>
                    <option value="4">4th Year</option>
                </select>
            </div>
            <div class="form-group">
                <label>Section</label>
                <input type="text" name="section" id="editSection" required>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_irregular" id="editIrregular" value="1"> 
                    <i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> Mark as Irregular Student
                </label>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn btn-back" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentScrollPosition = 0;
    
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
    
    function toggleForm(id) {
        const element = document.getElementById(id);
        if (element) element.classList.toggle('hidden');
    }
    
    function updateGeneratedID() {
        const last = document.getElementById('lastName')?.value || '';
        const first = document.getElementById('firstName')?.value || '';
        const middle = document.getElementById('middleName')?.value || '';
        const birth = document.getElementById('birthdate')?.value || '';
        const generatedIdDiv = document.getElementById('generatedID');
        
        if (last && first && middle && birth && generatedIdDiv) {
            const li = last[0].toUpperCase();
            const fi = first[0].toUpperCase();
            const mi = middle[0].toUpperCase();
            const d = new Date(birth);
            const id = li + fi + mi +
                String(d.getMonth() + 1).padStart(2, '0') +
                String(d.getDate()).padStart(2, '0') +
                String(d.getFullYear()).slice(2) + '00';
            generatedIdDiv.textContent = id;
        } else if (generatedIdDiv) {
            generatedIdDiv.textContent = '---';
        }
    }
    
    // DYNAMIC SECTION LOADING FOR FILTER - loads sections based on selected course and year
    function loadFilterSections() {
        var course = document.getElementById('filter_course_select').value;
        var year = document.getElementById('filter_year_select').value;
        var sectionSelect = document.getElementById('filter_section_select');
        
        if (course) {
            var yearParam = year ? year : '';
            var url = window.location.pathname + '?section=masterlist&get_filter_sections=1&course=' + encodeURIComponent(course) + '&year=' + encodeURIComponent(yearParam);
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    sectionSelect.innerHTML = '<option value="">All Sections</option>';
                    if (data.length > 0) {
                        for (var i = 0; i < data.length; i++) {
                            var selected = ('<?php echo $filter_section; ?>' == data[i].section_name) ? 'selected' : '';
                            sectionSelect.innerHTML += '<option value="' + data[i].section_name + '" ' + selected + '>' + data[i].section_name + '</option>';
                        }
                    }
                    // After loading sections, apply filter
                    filterMasterList();
                })
                .catch(error => {
                    console.error('Error loading sections:', error);
                    sectionSelect.innerHTML = '<option value="">All Sections</option>';
                    filterMasterList();
                });
        } else {
            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            filterMasterList();
        }
    }
    
    // DYNAMIC SECTION LOADING FOR ADD FORM
    function loadSectionsForAdd() {
        var course = document.getElementById('addCourseSelect').value;
        var year = document.getElementById('addYearSelect').value;
        var sectionSelect = document.getElementById('addSectionSelect');
        
        if (course && year) {
            var url = window.location.pathname + '?section=masterlist&get_sections_for_add=1&course=' + encodeURIComponent(course) + '&year=' + encodeURIComponent(year);
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                    if (data.length > 0) {
                        for (var i = 0; i < data.length; i++) {
                            sectionSelect.innerHTML += '<option value="' + data[i].section_name + '">' + data[i].section_name + '</option>';
                        }
                    } else {
                        sectionSelect.innerHTML = '<option value="">No sections available for this course and year</option>';
                    }
                })
                .catch(error => {
                    console.error('Error loading sections:', error);
                    sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
                });
        } else {
            sectionSelect.innerHTML = '<option value="">Select Course and Year First</option>';
        }
    }
    
    // AJAX FILTER - NO PAGE REFRESH
    function filterMasterList() {
        currentScrollPosition = window.scrollY;
        
        var course = document.getElementById('filter_course_select').value;
        var year = document.getElementById('filter_year_select').value;
        var section = document.getElementById('filter_section_select').value;
        
        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.location.pathname + '?section=masterlist&ajax_filter=1&filter_course=' + encodeURIComponent(course) + '&filter_year=' + encodeURIComponent(year) + '&filter_section=' + encodeURIComponent(section), true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                document.getElementById('masterTableBody').innerHTML = xhr.responseText;
                window.scrollTo(0, currentScrollPosition);
                
                var rowCount = document.querySelectorAll('#masterTableBody tr:not(:has(td[colspan]))').length;
                document.getElementById('totalCount').innerText = rowCount;
                
                var badgesHtml = '';
                if (course) badgesHtml += '<span class="badge">Course: ' + course + '</span>';
                if (year) badgesHtml += '<span class="badge">Year: ' + year + '</span>';
                if (section) badgesHtml += '<span class="badge">Section: ' + section + '</span>';
                document.getElementById('filterBadges').innerHTML = badgesHtml;
                
                document.querySelectorAll('.student-checkbox').forEach(cb => {
                    cb.addEventListener('change', updateSelectAllHeader);
                });
            }
        };
        xhr.send();
    }
    
    function resetFilters() {
        document.getElementById('filter_course_select').value = '';
        document.getElementById('filter_year_select').value = '';
        document.getElementById('filter_section_select').innerHTML = '<option value="">All Sections</option>';
        filterMasterList();
        return false;
    }
    
    function searchMasterList() {
        const search = document.getElementById('masterSearch')?.value.toLowerCase() || '';
        const rows = document.querySelectorAll('#masterTableBody tr');
        rows.forEach(row => {
            const name = row.dataset.name;
            if (name && name.includes(search)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        updateSelectAllHeader();
    }
    
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAllCheckbox');
        const checkboxes = document.querySelectorAll('#masterTableBody .student-checkbox');
        const visibleRows = document.querySelectorAll('#masterTableBody tr:not([style*="display: none"])');
        
        checkboxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            if (row && row.style.display !== 'none') {
                checkbox.checked = selectAll.checked;
            }
        });
        updateSelectAllHeader();
    }
    
    function toggleSelectAllFromHeader() {
        const headerCheckbox = document.getElementById('selectAllHeader');
        const checkboxes = document.querySelectorAll('#masterTableBody .student-checkbox');
        const visibleRows = document.querySelectorAll('#masterTableBody tr:not([style*="display: none"])');
        
        checkboxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            if (row && row.style.display !== 'none') {
                checkbox.checked = headerCheckbox.checked;
            }
        });
        
        const mainSelectAll = document.getElementById('selectAllCheckbox');
        if (mainSelectAll) mainSelectAll.checked = headerCheckbox.checked;
    }
    
    function updateSelectAllHeader() {
        const checkboxes = document.querySelectorAll('#masterTableBody .student-checkbox');
        const visibleCheckboxes = Array.from(checkboxes).filter(cb => {
            const row = cb.closest('tr');
            return row && row.style.display !== 'none';
        });
        const allChecked = visibleCheckboxes.length > 0 && visibleCheckboxes.every(cb => cb.checked);
        const headerCheckbox = document.getElementById('selectAllHeader');
        const mainSelectAll = document.getElementById('selectAllCheckbox');
        if (headerCheckbox) headerCheckbox.checked = allChecked;
        if (mainSelectAll) mainSelectAll.checked = allChecked;
    }
    
    function confirmBulkDelete() {
        const selected = document.querySelectorAll('#masterTableBody .student-checkbox:checked');
        if (selected.length === 0) {
            alert('Please select at least one student to delete.');
            return false;
        }
        return confirm('Are you sure you want to delete ' + selected.length + ' selected student(s)? Students with existing accounts cannot be deleted.');
    }
    
    function deleteSingleStudent(studentId) {
        if (confirm('Are you sure you want to delete this student? This action cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="delete_single" value="1"><input type="hidden" name="student_id" value="' + studentId + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function openEditModal(id, lastName, firstName, middleName, birthdate, course, year, section, isIrregular) {
        document.getElementById('editOriginalId').value = id;
        document.getElementById('editLastName').value = lastName;
        document.getElementById('editFirstName').value = firstName;
        document.getElementById('editMiddleName').value = middleName;
        document.getElementById('editBirthdate').value = birthdate;
        document.getElementById('editCourse').value = course;
        document.getElementById('editYear').value = year;
        document.getElementById('editSection').value = section;
        document.getElementById('editIrregular').checked = isIrregular;
        document.getElementById('editModal').style.display = 'flex';
    }
    
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }
    
    function fetchStudentInfo() {
        const studentId = document.getElementById('irregularStudentId').value;
        if (studentId.length < 5) return;
        
        fetch(`?get_student_info=1&student_id=${studentId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('previewFullName').value = data.full_name;
                    document.getElementById('previewBirthdate').value = data.birthdate;
                    document.getElementById('previewCourse').value = data.course;
                    document.getElementById('previewYear').value = data.year;
                    document.getElementById('previewSection').value = data.section;
                    document.getElementById('studentInfoPreview').style.display = 'block';
                } else {
                    document.getElementById('studentInfoPreview').style.display = 'none';
                    alert('Student ID not found in masterlist!');
                }
            });
    }
    
    function validateIrregularForm() {
        const studentId = document.getElementById('irregularStudentId').value;
        if (!studentId) {
            alert('Please enter Student ID');
            return false;
        }
        return confirm('Mark this student as Irregular? They will stay in their current year level until they complete all requirements.');
    }
    
    window.onclick = function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.style.display = 'none';
        }
    }
    
    // Initialize filter sections on page load
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('filter_course_select').value) {
            loadFilterSections();
        }
    });
</script>

</body>
</html>