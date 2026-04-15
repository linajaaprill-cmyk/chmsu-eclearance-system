<?php
require_once __DIR__ . '/../config/database.php';

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
    $ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
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
        body { font-family: "Times New Roman", Times, serif; margin: 20px; font-size: 11pt; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1b4d3e; padding-bottom: 15px; }
        .university-name { font-size: 18pt; font-weight: bold; color: #1b4d3e; letter-spacing: 2px; }
        .university-address { font-size: 10pt; color: #555; margin-top: 5px; }
        .report-title { font-size: 16pt; font-weight: bold; margin-top: 20px; text-transform: uppercase; }
        .report-subtitle { font-size: 12pt; color: #666; margin-top: 5px; }
        .report-info { text-align: right; margin: 20px 0; font-size: 10pt; }
        th { background: #1b4d3e; color: white; padding: 10px 8px; font-weight: bold; text-align: center; border: 1px solid #2d6a4f; }
        td { padding: 8px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        .footer { text-align: center; margin-top: 30px; padding-top: 10px; border-top: 1px solid #ccc; font-size: 9pt; color: #888; }
    </style>';
    echo '</head><body>';
    
    // Header
    echo '<div class="header">';
    echo '<div class="university-name">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>';
    echo '<div class="university-address">Talisay City, Negros Occidental, Philippines</div>';
    echo '<div class="university-address">E-Clearance System - Official Report</div>';
    echo '</div>';
    
    // Report Title
    echo '<div class="report-title">' . (!empty($report_title) ? $report_title : $filename) . '</div>';
    if (!empty($subtitle)) {
        echo '<div class="report-subtitle">' . $subtitle . '</div>';
    }
    
    // Report Info
    echo '<div class="report-info">';
    echo 'Date Generated: ' . date('F d, Y') . '<br>';
    echo 'Time Generated: ' . date('h:i A') . '<br>';
    echo 'Generated by: ' . (isset($_SESSION['admin']) ? 'System Administrator' : (isset($_SESSION['office']) ? $_SESSION['office'] : 'System User')) . '<br>';
    echo '</div>';
    
    // Table
    echo '<table border="1">';
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th>' . $header . '</th>';
    }
    echo '</tr>';
    
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . (is_null($cell) || $cell === '' ? '-' : $cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    
    // Footer
    echo '<div class="footer">';
    echo 'This is a computer-generated report. No signature is required.<br>';
    echo 'CHMSU E-Clearance System | Page 1 of 1';
    echo '</div>';
    
    echo '</body></html>';
    exit;
}

function exportToWordReport($data, $filename, $headers, $report_title = '', $subtitle = '') {
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $filename . '.doc"');
    
    echo '<html>';
    echo '<head><meta charset="UTF-8"><title>' . $filename . '</title>';
    echo '<style>
        body { font-family: "Times New Roman", Times, serif; margin: 2.54cm; font-size: 12pt; line-height: 1.5; }
        .header { text-align: center; margin-bottom: 40px; border-bottom: 2px solid #1b4d3e; padding-bottom: 20px; }
        .university-name { font-size: 20pt; font-weight: bold; color: #1b4d3e; letter-spacing: 2px; }
        .university-address { font-size: 11pt; color: #555; margin-top: 5px; }
        .report-title { font-size: 18pt; font-weight: bold; margin-top: 30px; text-align: center; text-transform: uppercase; }
        .report-subtitle { font-size: 14pt; color: #666; text-align: center; margin-top: 10px; }
        .report-info { margin: 30px 0; font-size: 11pt; }
        th { background: #1b4d3e; color: white; padding: 10px 8px; font-weight: bold; text-align: center; border: 1px solid #2d6a4f; }
        td { padding: 8px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        .footer { text-align: center; margin-top: 50px; padding-top: 15px; border-top: 1px solid #ccc; font-size: 10pt; color: #888; }
        .prepared-by { margin-top: 40px; text-align: right; font-size: 11pt; }
        .signature-line { margin-top: 50px; text-align: right; }
    </style>';
    echo '</head><body>';
    
    // Header
    echo '<div class="header">';
    echo '<div class="university-name">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>';
    echo '<div class="university-address">Talisay City, Negros Occidental, Philippines</div>';
    echo '<div class="university-address">E-Clearance System - Official Report</div>';
    echo '</div>';
    
    // Report Title
    echo '<div class="report-title">' . (!empty($report_title) ? $report_title : $filename) . '</div>';
    if (!empty($subtitle)) {
        echo '<div class="report-subtitle">' . $subtitle . '</div>';
    }
    
    // Report Info
    echo '<div class="report-info">';
    echo '<table style="width:100%; border:none;">';
    echo '<tr><td style="border:none; width:30%;"><strong>Date Generated:</strong></td><td style="border:none;">' . date('F d, Y') . '</td></tr>';
    echo '<tr><td style="border:none;"><strong>Time Generated:</strong></td><td style="border:none;">' . date('h:i A') . '</td></tr>';
    echo '<tr><td style="border:none;"><strong>Generated by:</strong></td><td style="border:none;">' . (isset($_SESSION['admin']) ? 'System Administrator' : (isset($_SESSION['office']) ? $_SESSION['office'] : 'System User')) . '</td></tr>';
    echo '</table>';
    echo '</div>';
    
    // Table
    echo '<table border="1">';
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th>' . $header . '</th>';
    }
    echo '</tr>';
    
    if (empty($data)) {
        echo '<tr><td colspan="' . count($headers) . '" style="text-align:center;">No records found.</td></tr>';
    } else {
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . (is_null($cell) || $cell === '' ? '-' : $cell) . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</table>';
    
    // Prepared By Section
    echo '<div class="prepared-by">';
    echo 'Prepared by:<br><br>';
    echo '_________________________<br>';
    echo '(' . (isset($_SESSION['admin']) ? 'System Administrator' : (isset($_SESSION['office']) ? $_SESSION['office'] : 'Authorized Personnel')) . ')<br>';
    echo '</div>';
    
    // Footer
    echo '<div class="footer">';
    echo 'This is a computer-generated document and does not require a physical signature.<br>';
    echo 'CHMSU E-Clearance System | ' . date('Y') . ' | Page 1 of 1';
    echo '</div>';
    
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
?>