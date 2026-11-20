<?php
// ============================================================
// FORCED FIX: Strictly determine the student ID
// ============================================================
$cert_student_id = '';

if (!empty($student_id)) {
    $cert_student_id = trim($student_id);
} elseif (isset($_GET['print_certificate']) && !empty($_GET['print_certificate'])) {
    $cert_student_id = trim($_GET['print_certificate']);
} elseif (isset($_SESSION['student']) && !empty($_SESSION['student'])) {
    $cert_student_id = trim($_SESSION['student']);
}

if (empty($cert_student_id)) {
    die("Invalid certificate request. Could not determine Student ID.");
}

$student_id = $cert_student_id; // Set the variable for the rest of the file

// Get student information
$studentInfo = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'");
$student = $studentInfo->fetch_assoc();

if (!$student) {
    die("Student record not found.");
}

$userInfo = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='$student_id'");
$user = $userInfo->fetch_assoc();

$fullName = $student['chmsu_full_name'];
$nameParts = explode(',', $fullName);
$lastName = trim($nameParts[0]);
$firstName = isset($nameParts[1]) ? trim($nameParts[1]) : '';
$middleName = isset($student['chmsu_middle_name']) ? $student['chmsu_middle_name'] : '';

// Current semester (same scoping the Clearance page uses)
$cert_sem_row = $conn->query("SELECT id FROM chmsu_semesters WHERE is_current = 1 LIMIT 1");
$cert_sem_id = ($cert_sem_row && $cert_sem_row->num_rows > 0) ? intval($cert_sem_row->fetch_assoc()['id']) : 0;
$cert_sem_clause = $cert_sem_id > 0 ? " AND semester_id = $cert_sem_id " : "";

// Get all offices and check approval status for this student
$offices = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
$allOffices = [];
while ($o = $offices->fetch_assoc()) {
    $allOffices[] = $o['office_name'];
}
$totalOffices = count($allOffices);

// Check approval for each office
$officeStatus = [];
$approvedCount = 0;
foreach ($allOffices as $officeName) {
    $officeNameEsc = $conn->real_escape_string($officeName);

    $checkApproval = $conn->query("SELECT s.chmsu_status 
                                   FROM chmsu_submissions s
                                   JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id
                                   WHERE r.chmsu_office = '$officeNameEsc' 
                                   AND s.chmsu_student_id = '$student_id'
                                   AND s.chmsu_status = 'Approved'
                                   LIMIT 1");
                                   
    $checkClearance = $conn->query("SELECT status FROM chmsu_clearance_status 
                                    WHERE office_name = '$officeNameEsc' 
                                    AND student_id = '$student_id' 
                                    AND status = 'clear' $cert_sem_clause LIMIT 1");

    if (($checkApproval && $checkApproval->num_rows > 0) || ($checkClearance && $checkClearance->num_rows > 0)) {
        $officeStatus[$officeName] = true;
        $approvedCount++;
    } else {
        $officeStatus[$officeName] = false;
    }
}

// Get current school year and semester
$currentSY = $conn->query("SELECT school_year FROM chmsu_school_years WHERE is_current = 1 LIMIT 1");
$schoolYear = $currentSY && $currentSY->num_rows > 0 ? $currentSY->fetch_assoc()['school_year'] : date('Y') . '-' . (date('Y') + 1);

$currentSem = $conn->query("SELECT semester_name FROM chmsu_semesters WHERE is_current = 1 LIMIT 1");
$semester = $currentSem && $currentSem->num_rows > 0 ? $currentSem->fetch_assoc()['semester_name'] : 'First Semester';

$semesterFirstChecked = (stripos($semester, '1st') !== false || stripos($semester, 'First') !== false) ? 'checked' : '';
$semesterSecondChecked = (stripos($semester, '2nd') !== false || stripos($semester, 'Second') !== false) ? 'checked' : '';

// Back button target depends on who is viewing (same page for students as before)
$cert_back_url = $_SERVER['PHP_SELF'] . '?view=home';
$cert_back_label = 'BACK TO DASHBOARD';
if (isset($_SESSION['office']) && $_SESSION['office'] === 'Registrar') {
    $cert_back_url = $_SERVER['PHP_SELF'] . '?section=clearance';
    $cert_back_label = 'BACK TO CLEARANCE';
} elseif (isset($_SESSION['office'])) {
    $cert_back_url = $_SERVER['PHP_SELF'] . '?officesection=dashboard';
    $cert_back_label = 'BACK TO DASHBOARD';
} elseif (isset($_SESSION['admin'])) {
    $cert_back_url = $_SERVER['PHP_SELF'] . '?adminsection=dashboard';
    $cert_back_label = 'BACK TO DASHBOARD';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student's Clearance Certificate - CHMSU</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Preline UI -->
    <script src="https://cdn.jsdelivr.net/npm/preline@latest/dist/preline.js"></script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        chmsu: '#1b4d3e',
                        chmsuLight: '#2d6a4f',
                    }
                }
            }
        }
    </script>

    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            background: #e0e0e0;
        }
        
        .certificate-container {
            max-width: 1000px;
            width: 100%;
            margin: 0 auto;
        }

        /* Mobile: keep the certificate layout intact and let it scroll sideways */
        .certificate-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .certificate {
            background: white;
            border: 1px solid #000;
            padding: 30px 35px;
            position: relative;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            min-width: 720px;
        }
        
        .logo-container {
            position: absolute;
            top: 25px;
            right: 35px;
            width: 80px;
            height: 80px;
            z-index: 10;
        }
        
        .logo-img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .doc-header {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 25px;
            margin-right: 90px;
            font-size: 10px;
        }
        
        .certificate-title {
            font-size: 22px;
            font-weight: bold;
            text-align: center;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 10px 0 25px 0;
        }
        
        .student-info {
            width: 100%;
            margin-bottom: 25px;
            font-size: 12px;
        }
        
        .info-row {
            margin: 4px 0;
            display: flex;
            align-items: baseline;
            flex-wrap: wrap;
        }
        
        .info-label {
            width: 110px;
        }
        
        .info-underline {
            border-bottom: 1px solid #000;
            min-width: 250px;
            display: inline-block;
            margin-left: 10px;
        }
        
        .info-underline-short {
            border-bottom: 1px solid #000;
            min-width: 150px;
            display: inline-block;
            margin-left: 10px;
        }
        
        .info-underline-medium {
            border-bottom: 1px solid #000;
            min-width: 200px;
            display: inline-block;
            margin-left: 10px;
        }
        
        .info-row-double {
            display: flex;
            justify-content: space-between;
            margin: 4px 0;
        }
        
        .field-group {
            display: flex;
            align-items: baseline;
            flex: 1;
        }
        
        .field-group .info-label {
            width: auto;
            margin-right: 10px;
        }
        
        .field-group .info-underline {
            min-width: 180px;
            margin-left: 0;
        }
        
        .certify-text {
            font-size: 12px;
            line-height: 1.6;
            margin: 20px 0 25px 0;
            text-align: justify;
        }
        
        .checkbox-section {
            margin: 20px 0;
            font-size: 12px;
        }
        
        .checkbox-line {
            display: flex;
            align-items: center;
            gap: 25px;
            flex-wrap: wrap;
            margin: 8px 0;
        }
        
        .checkbox-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .checkbox-item input {
            width: 13px;
            height: 13px;
            margin: 0;
        }
        
        .academic-year {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-left: 20px;
        }
        
        .academic-year-underline {
            border-bottom: 1px solid #000;
            min-width: 120px;
            display: inline-block;
        }
        
        .signatures-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px 50px;
            margin: 25px 0 20px 0;
        }
        
        .signature-item {
            text-align: center;
        }
        
        .approval-text {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 2px;
            margin-bottom: 5px;
        }
        
        .approval-approved {
            color: #27ae60;
        }
        
        .approval-pending {
            color: #c0392b;
            font-size: 14px;
        }
        
        .signature-line {
            border-bottom: 1px solid #000;
            width: 100%;
            margin: 8px 0 5px 0;
        }
        
        .office-name {
            font-size: 12px;
            font-weight: normal;
            text-transform: uppercase;
            margin-top: 3px;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .certificate {
                box-shadow: none;
                padding: 25px 30px;
                border: 1px solid #000;
                margin: 0;
                min-width: 0;
            }
            .certificate-scroll {
                overflow: visible;
            }
            .btn-container {
                display: none !important;
            }
            .doc-header {
                font-size: 9px;
            }
        }
    </style>
</head>
<body class="bg-gray-200 flex justify-center items-center min-h-screen p-3 sm:p-6 print:p-0 print:bg-white">

<div class="certificate-container">
    
    <!-- Action Buttons -->
    <div class="btn-container text-center mb-6 flex flex-wrap justify-center gap-3 sm:gap-4 print:hidden">
        <button class="bg-chmsu hover:bg-chmsuLight text-white px-5 py-2.5 rounded-lg font-medium transition-colors shadow-md inline-flex items-center gap-2" onclick="window.print()">
            <i data-lucide="printer" class="w-4 h-4"></i> PRINT CERTIFICATE
        </button>
        <a href="<?php echo htmlspecialchars($cert_back_url); ?>" class="bg-gray-600 hover:bg-gray-700 text-white px-5 py-2.5 rounded-lg font-medium transition-colors shadow-md inline-flex items-center gap-2">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> <?php echo $cert_back_label; ?>
        </a>
    </div>
    
    <!-- Certificate Body -->
    <div class="certificate-scroll">
    <div class="certificate">
        
        <!-- Logo -->
        <div class="logo-container">
            <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo" class="logo-img">
        </div>
        
        <!-- Document Header -->
        <div class="doc-header">
            <span>Document Code: F.15-RO-CHMSU</span>
            <span>Revision No.: 0</span>
            <span>Effective Date: May 6, 2024</span>
            <span>Page: 1 of 1</span>
        </div>
        
        <!-- Title -->
        <div class="certificate-title">STUDENT'S CLEARANCE</div>
        
        <!-- Student Info -->
        <div class="student-info">
            <div class="info-row">
                <span class="info-label">ID Number:</span>
                <span class="info-underline"><?php echo htmlspecialchars($student_id); ?></span>
            </div>
            <div class="info-row-double">
                <div class="field-group">
                    <span class="info-label">Last Name</span>
                    <span class="info-underline"><?php echo htmlspecialchars($lastName); ?></span>
                </div>
                <div class="field-group">
                    <span class="info-label">First Name</span>
                    <span class="info-underline"><?php echo htmlspecialchars($firstName); ?></span>
                </div>
            </div>
            <div class="info-row-double">
                <div class="field-group">
                    <span class="info-label">Middle Name</span>
                    <span class="info-underline"><?php echo htmlspecialchars($middleName); ?></span>
                </div>
                <div class="field-group">
                    <span class="info-label">Ext.</span>
                    <span class="info-underline-short"></span>
                </div>
            </div>
            <div class="info-row-double">
                <div class="field-group">
                    <span class="info-label">Signature</span>
                    <span class="info-underline-medium"></span>
                </div>
                <div class="field-group">
                    <span class="info-label"></span>
                    <span></span>
                </div>
            </div>
            <div class="info-row-double">
                <div class="field-group">
                    <span class="info-label">Course/Year/Section</span>
                    <span class="info-underline-medium"><?php echo htmlspecialchars($student['chmsu_course']); ?> <?php echo $student['chmsu_year']; ?> - <?php echo htmlspecialchars($student['chmsu_section']); ?></span>
                </div>
                <div class="field-group">
                    <span class="info-label">Major</span>
                    <span class="info-underline-short"></span>
                </div>
            </div>
            <div class="info-row">
                <span class="info-label">Contact number</span>
                <span class="info-underline-medium"></span>
            </div>
        </div>
        
        <!-- Certify Text -->
        <div class="certify-text">
            This is to certify that the student is cleared of money accountability and property responsibility for the concerned personnel of the University.
        </div>
        
        <!-- Checkbox Section -->
        <div class="checkbox-section">
            <div class="checkbox-line">
                <div class="checkbox-item">
                    <input type="checkbox" <?php echo $semesterFirstChecked; ?> disabled> 1st
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" <?php echo $semesterSecondChecked; ?> disabled> 2nd
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" disabled> Summer
                </div>
                <div class="academic-year">
                    <span>Semester, Academic Year</span>
                    <span class="academic-year-underline"><?php echo htmlspecialchars($schoolYear); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Signatures Grid -->
        <div class="signatures-grid">
            <?php 
            foreach ($allOffices as $officeName):
                $isApproved = $officeStatus[$officeName];
            ?>
            <div class="signature-item">
                <?php if ($isApproved): ?>
                    <div class="approval-text approval-approved">APPROVED</div>
                <?php else: ?>
                    <div class="approval-text approval-pending">PENDING</div>
                <?php endif; ?>
                <div class="signature-line"></div>
                <div class="office-name"><?php echo htmlspecialchars($officeName); ?></div>
            </div>
            <?php endforeach; ?>
            
            <!-- STATUS -->
            <div class="signature-item">
                <?php if ($approvedCount == $totalOffices && $totalOffices > 0): ?>
                    <div class="approval-text approval-approved">COMPLETED</div>
                <?php else: ?>
                    <div class="approval-text approval-pending">IN PROGRESS</div>
                <?php endif; ?>
                <div class="signature-line"></div>
                <div class="office-name">STATUS</div>
            </div>
        </div>

    </div>
    </div>
</div>

<script>
    lucide.createIcons();
</script>

</body>
</html>