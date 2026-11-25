<?php
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$selectedOffice = isset($_GET['office']) ? $_GET['office'] : '';
$show_certificate = isset($_GET['show_certificate']) ? intval($_GET['show_certificate']) : 0;

$current_year_result = $conn->query("SELECT chmsu_year FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'");
$current_student_year = '1';
if ($current_year_result && $current_year_result->num_rows > 0) {
    $current_student_year = $current_year_result->fetch_assoc()['chmsu_year'];
}

$chat_unread_count = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='{$_SESSION['student']}'")->fetch_assoc()['total'] ?: 0;

// ============================================
// Detect the CURRENT semester (so clearance is
// always scoped to whatever semester is active).
// Falls back gracefully if the column doesn't exist.
// ============================================
$current_sem_id = 0;
$current_sem = $conn->query("SELECT id FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
if ($current_sem) $current_sem_id = intval($current_sem['id']);

$has_clearance_sem_col = false;
$chk = $conn->query("SHOW COLUMNS FROM chmsu_clearance_status LIKE 'semester_id'");
if ($chk && $chk->num_rows > 0) $has_clearance_sem_col = true;

// We only filter when we can (column exists AND there's a current sem)
$sem_filter = '';
if ($has_clearance_sem_col && $current_sem_id > 0) {
    $sem_filter = " AND semester_id = $current_sem_id";
}

// Get all offices with this student's clearance status for each
// (Sorted strictly: Library -> Accounting -> OSA -> Dean -> Registrar)
$offices_result = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY FIELD(LOWER(TRIM(office_name)), 'library', 'accounting', 'osa', 'dean', 'registrar'), office_name ASC");
$offices = [];
$officeStatuses = [];
$officeNotes = [];
$totalOffices = 0;
$approvedCount = 0;

while ($office_row = $offices_result->fetch_assoc()) {
    $office = $office_row['office_name'];
    $offices[] = $office;
    $totalOffices++;

    $office_esc = $conn->real_escape_string($office);
    $student_esc = $conn->real_escape_string($_SESSION['student']);
    $clearanceQ = $conn->query("SELECT status, note FROM chmsu_clearance_status 
                                WHERE student_id='$student_esc' 
                                AND office_name='$office_esc' $sem_filter LIMIT 1");

    if ($clearanceQ && $clearanceQ->num_rows > 0) {
        $row = $clearanceQ->fetch_assoc();
        if ($row['status'] == 'clear') {
            $officeStatuses[$office] = 'Approved';
            $approvedCount++;
        } elseif ($row['status'] == 'unclear') {
            $officeStatuses[$office] = 'Declined';
        } else {
            $officeStatuses[$office] = 'Pending';
        }
        $officeNotes[$office] = $row['note'];
    } else {
        $officeStatuses[$office] = 'Pending';
        $officeNotes[$office] = '';
    }
}

// Check feed mentions for this student per office (for Pending reason popup)
$officeFeedMentions = [];
$feedSemChk = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
$feedHasSem = ($feedSemChk && $feedSemChk->num_rows > 0);
$feedSemSql = ($feedHasSem && $current_sem_id > 0) ? " AND (semester_id = $current_sem_id OR semester_id IS NULL) " : "";
$stuFullName = trim($master['chmsu_full_name'] ?? '');
$stuLastName = trim($master['chmsu_last_name'] ?? '');
$stuFirstName = trim($master['chmsu_first_name'] ?? '');
$stuIdStr = trim($_SESSION['student']);

$feedQ = $conn->query("SELECT id, office_name, title, content, student_id FROM chmsu_feed WHERE 1=1 $feedSemSql ORDER BY created_at DESC");
if ($feedQ) {
    while ($fp = $feedQ->fetch_assoc()) {
        $fOff = $fp['office_name'];
        $isHit = false;
        if (!empty($fp['student_id']) && trim($fp['student_id']) === $stuIdStr) {
            $isHit = true;
        } elseif (!empty($stuFullName) && stripos($fp['content'], $stuFullName) !== false) {
            $isHit = true;
        } elseif (!empty($stuLastName) && !empty($stuFirstName) && stripos($fp['content'], $stuLastName . ', ' . $stuFirstName) !== false) {
            $isHit = true;
        }
        if ($isHit && !isset($officeFeedMentions[$fOff])) {
            $officeFeedMentions[$fOff] = [
                'post_id' => intval($fp['id']),
                'title'   => $fp['title']
            ];
        }
    }
}

$officeDetailData = [];
foreach ($offices as $ofName) {
    $st = $officeStatuses[$ofName] ?? 'Pending';
    $nt = trim($officeNotes[$ofName] ?? '');
    $reasons = [];
    $mentionPostId = 0;

    if ($st === 'Approved') {
        $reasons[] = "Your clearance for $ofName has been approved.";
    } elseif ($st === 'Declined') {
        $reasons[] = !empty($nt) ? $nt : "Declined by $ofName.";
    } else {
        if (!empty($officeFeedMentions[$ofName])) {
            $mentionPostId = $officeFeedMentions[$ofName]['post_id'];
            $reasons[] = "Mentioned in Feed post: \"" . $officeFeedMentions[$ofName]['title'] . "\"";
        }
        if (strcasecmp($ofName, 'Dean') === 0 && ($officeStatuses['OSA'] ?? 'Pending') !== 'Approved') {
            $reasons[] = "Locked: Waiting for OSA approval first before Dean can clear you.";
        }
        if (strcasecmp($ofName, 'Registrar') === 0) {
            $notCleared = [];
            foreach ($offices as $otherOf) {
                if (strcasecmp($otherOf, 'Registrar') !== 0 && ($officeStatuses[$otherOf] ?? 'Pending') !== 'Approved') {
                    $notCleared[] = $otherOf;
                }
            }
            if (!empty($notCleared)) {
                $reasons[] = "Locked: Waiting for completion of " . implode(', ', $notCleared) . ".";
            }
        }
        if (!empty($nt)) {
            $reasons[] = $nt;
        }
        if (empty($reasons)) {
            $reasons[] = "Awaiting review and approval from $ofName.";
        }
    }

    $officeDetailData[$ofName] = [
        'office' => $ofName,
        'status' => $st,
        'reasons' => $reasons,
        'mention_post_id' => $mentionPostId
    ];
}

$allApproved = ($approvedCount == $totalOffices && $totalOffices > 0);
$progressPercent = ($totalOffices > 0) ? round(($approvedCount / $totalOffices) * 100) : 0;

// ============================================
// PREPARE CERTIFICATE DATA (only when viewing)
// ============================================
$cert_ready = false;
if ($allApproved && $show_certificate == 1) {
    $cert_ready = true;

    // School year / semester
    $currentSY = $conn->query("SELECT school_year FROM chmsu_school_years WHERE is_current = 1 LIMIT 1");
    $schoolYear = $currentSY && $currentSY->num_rows > 0 ? $currentSY->fetch_assoc()['school_year'] : date('Y') . '-' . (date('Y') + 1);

    $currentSem = $conn->query("SELECT semester_name FROM chmsu_semesters WHERE is_current = 1 LIMIT 1");
    $semester = $currentSem && $currentSem->num_rows > 0 ? $currentSem->fetch_assoc()['semester_name'] : 'First Semester';

    $semesterFirstChecked  = (stripos($semester, '1st') !== false || stripos($semester, 'First') !== false) ? 'checked' : '';
    $semesterSecondChecked = (stripos($semester, '2nd') !== false || stripos($semester, 'Second') !== false) ? 'checked' : '';

    // Split name
    $fullName = $master['chmsu_full_name'] ?? '';
    $nameParts = explode(',', $fullName);
    $lastName = trim($nameParts[0] ?? '');
    $firstName = isset($nameParts[1]) ? trim($nameParts[1]) : '';
    $middleName = $master['chmsu_middle_name'] ?? '';

    // Registrar document verification status
    $cert_reg_verify = ['shs_sf10' => 0, 'jotr' => 0, 'psa_birth' => 0];
    $rv_student_id = $conn->real_escape_string($_SESSION['student']);
    $rv_q = $conn->query("SELECT shs_sf10, jotr, psa_birth FROM chmsu_registrar_verification WHERE student_id='$rv_student_id' LIMIT 1");
    if ($rv_q && $rv_q->num_rows > 0) {
        $cert_reg_verify = $rv_q->fetch_assoc();
    }
    $sf_checked   = !empty($cert_reg_verify['shs_sf10'])  ? 'checked' : '';
    $jotr_checked = !empty($cert_reg_verify['jotr'])       ? 'checked' : '';
    $psa_checked  = !empty($cert_reg_verify['psa_birth']) ? 'checked' : '';
}
?>

<style>
body { font-family: 'Times New Roman', Times, serif !important; background: #f5f5f5 !important; font-size: 14px !important; }
.dark-sidebar {
    width: 260px !important; background: #1b4d3e !important; color: #ffffff !important;
    position: fixed !important; height: calc(100vh - 73px) !important;
    overflow-y: auto !important; overflow-x: hidden !important; z-index: 100 !important;
    font-family: 'Times New Roman', Times, serif !important; font-size: 14px !important;
}
.dark-sidebar .sidebar-header { padding: 20px !important; background: #0f3b2f !important; border-bottom: 1px solid #2d6a4f !important; }
.dark-sidebar .sidebar-header h3 { font-size: 16px !important; font-weight: normal !important; font-family: 'Times New Roman', Times, serif !important; }
.dark-sidebar .sidebar-header p { font-size: 11px !important; opacity: 0.7 !important; margin-top: 5px !important; font-family: 'Times New Roman', Times, serif !important; }
.dark-sidebar .sidebar-menu { list-style: none !important; padding: 0 !important; margin: 0 !important; padding-bottom: 20px !important; }
.dark-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f !important; }
.dark-sidebar .sidebar-menu a {
    display: flex !important; align-items: center !important; padding: 12px 20px !important;
    color: #ffffff !important; text-decoration: none !important; font-size: 13px !important;
    font-family: 'Times New Roman', Times, serif !important; transition: all 0.3s !important;
}
.dark-sidebar .sidebar-menu a:hover,
.dark-sidebar .sidebar-menu a.active { background: #f1c40f !important; color: #000000 !important; }
.dark-sidebar .sidebar-menu i { width: 18px !important; margin-right: 8px !important; font-size: 13px !important; }
.dark-sidebar .sidebar-badge {
    background: #e74c3c !important; color: white !important; border-radius: 50% !important;
    padding: 1px 6px !important; font-size: 9px !important; margin-left: 5px !important;
    animation: pulse 1.5s infinite !important; font-family: 'Times New Roman', Times, serif !important;
}
.main-content { margin-left: 260px !important; font-family: 'Times New Roman', Times, serif !important; font-size: 14px !important; }
@keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.2); } 100% { transform: scale(1); } }
@media (max-width: 768px) {
    .dark-sidebar { width: 100% !important; position: relative !important; height: auto !important; }
    .main-content { margin-left: 0 !important; }
}

/* ============================================ */
/* COLOR LEGEND */
/* ============================================ */
.color-legend {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    align-items: center;
    padding: 10px 14px;
    background: #ffffff;
    border: 1px solid #ddd;
    border-radius: 8px;
    margin-bottom: 15px;
    font-size: 12px;
    font-family: 'Times New Roman', Times, serif;
}
.color-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #333;
}
.color-legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.legend-dot-green { background: #27ae60; }
.legend-dot-red { background: #e74c3c; }
.legend-dot-orange { background: #f39c12; }

body.dark-mode .color-legend {
    background: #1a1a1a;
    border-color: #333;
}
body.dark-mode .color-legend-item {
    color: #ddd;
}

/* ============================================ */
/* FULL COLOR OFFICE BOXES */
/* ============================================ */
.office-boxes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 15px;
    margin-top: 15px;
}
.office-box {
    border-radius: 8px;
    padding: 25px 18px;
    text-align: center;
    transition: all 0.3s;
    color: white;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.office-box:hover { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,0.25); }
.office-box.approved { background: #27ae60; }
.office-box.declined { background: #e74c3c; }
.office-box.pending { background: #f39c12; }
.office-box-name { font-size: 18px; font-weight: bold; margin-bottom: 8px; font-family: 'Times New Roman', Times, serif; color: white; }
.office-box-status { font-size: 15px; font-weight: bold; font-family: 'Times New Roman', Times, serif; color: white; text-transform: uppercase; letter-spacing: 1px; }
.office-box-note { font-size: 11px; margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.3); font-family: 'Times New Roman', Times, serif; color: white; }

/* Certificate Banner */
.cert-banner {
    background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
    color: white;
    padding: 25px 30px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
    box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
}
.cert-banner-left { display: flex; align-items: center; gap: 15px; flex: 1; min-width: 250px; }
.cert-banner-icon { font-size: 44px; line-height: 1; }
.cert-banner-text h2 { font-size: 22px; margin-bottom: 5px; font-weight: bold; }
.cert-banner-text p { font-size: 14px; opacity: 0.95; }
.cert-banner-btn {
    padding: 12px 24px; background: white; color: #27ae60; border: none; border-radius: 6px;
    cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; display: inline-block;
    font-family: 'Times New Roman', Times, serif; transition: all 0.3s;
}
.cert-banner-btn:hover { background: #f8f9fa; transform: scale(1.03); }

body.dark-mode .office-box { color: white; }

/* ============================================ */
/* TOP-RIGHT DOWNLOAD BUTTON */
/* ============================================ */
.cert-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    gap: 12px;
    flex-wrap: wrap;
}
.cert-top-bar h2 {
    font-size: 16px;
    margin: 0;
}
.cert-top-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-left: auto;
}
.btn-download-img {
    padding: 8px 16px;
    background: #27ae60;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: bold;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: 'Times New Roman', Times, serif;
    transition: all 0.25s;
}
.btn-download-img:hover {
    background: #219150;
    transform: translateY(-1px);
}
.btn-download-img:disabled,
.btn-download-img.loading {
    background: #95a5a6;
    cursor: not-allowed;
    transform: none;
}
.btn-download-img .spinner {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 2px solid rgba(255,255,255,0.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
.btn-download-pdf {
    padding: 8px 16px;
    background: #c0392b;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: bold;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: 'Times New Roman', Times, serif;
    transition: all 0.25s;
}
.btn-download-pdf:hover {
    background: #a93226;
    transform: translateY(-1px);
}
.btn-download-pdf:disabled,
.btn-download-pdf.loading {
    background: #95a5a6;
    cursor: not-allowed;
    transform: none;
}
.btn-download-pdf .spinner {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 2px solid rgba(255,255,255,0.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.btn-back-top {
    padding: 8px 16px;
    background: #7f8c8d;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-family: 'Times New Roman', Times, serif;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}
.btn-back-top:hover { background: #6c7a7d; }

/* ============================================ */
/* INLINE CERTIFICATE STYLES */
/* ============================================ */
.certificate-inline-wrap {
    background: #ffffff;
    border: 1px solid #000;
    padding: 30px 35px;
    position: relative;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    margin-top: 15px;
}
.cert-logo-container { position: absolute; top: 25px; right: 35px; width: 80px; height: 80px; z-index: 10; }
.cert-logo-img { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; }

.cert-doc-header {
    display: flex; justify-content: space-between; border-bottom: 1px solid #000;
    padding-bottom: 5px; margin-bottom: 25px; margin-right: 90px; font-size: 10px;
    font-family: 'Times New Roman', Times, serif;
}
.cert-title {
    font-size: 22px; font-weight: bold; text-align: center; letter-spacing: 1px;
    text-transform: uppercase; margin: 10px 0 25px 0; font-family: 'Times New Roman', Times, serif;
}
.cert-student-info { width: 100%; margin-bottom: 25px; font-size: 12px; font-family: 'Times New Roman', Times, serif; }
.cert-info-row { margin: 4px 0; display: flex; align-items: baseline; flex-wrap: wrap; }
.cert-info-label { width: 110px; }
.cert-info-underline { border-bottom: 1px solid #000; min-width: 250px; display: inline-block; margin-left: 10px; }
.cert-info-underline-short { border-bottom: 1px solid #000; min-width: 150px; display: inline-block; margin-left: 10px; }
.cert-info-underline-medium { border-bottom: 1px solid #000; min-width: 200px; display: inline-block; margin-left: 10px; }
.cert-info-row-double { display: flex; justify-content: space-between; margin: 4px 0; }
.cert-field-group { display: flex; align-items: baseline; flex: 1; }
.cert-field-group .cert-info-label { width: auto; margin-right: 10px; }
.cert-field-group .cert-info-underline { min-width: 180px; margin-left: 0; }
.cert-certify-text {
    font-size: 12px; line-height: 1.6; margin: 20px 0 25px 0; text-align: justify;
    font-family: 'Times New Roman', Times, serif;
}
.cert-checkbox-section { margin: 20px 0; font-size: 12px; font-family: 'Times New Roman', Times, serif; }
.cert-checkbox-line { display: flex; align-items: center; gap: 25px; flex-wrap: wrap; margin: 8px 0; }
.cert-checkbox-item { display: inline-flex; align-items: center; gap: 5px; }
.cert-checkbox-item input { width: 13px; height: 13px; margin: 0; }
.cert-academic-year { display: inline-flex; align-items: center; gap: 10px; margin-left: 20px; }
.cert-academic-year-underline { border-bottom: 1px solid #000; min-width: 120px; display: inline-block; }
.cert-signatures-grid {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 25px 50px; margin: 25px 0 20px 0;
}
.cert-signature-item { text-align: center; font-family: 'Times New Roman', Times, serif; }
.cert-approval-text { font-size: 18px; font-weight: bold; letter-spacing: 2px; margin-bottom: 5px; }
.cert-approval-approved { color: #27ae60; }
.cert-approval-pending { color: #c0392b; font-size: 14px; }
.cert-signature-line { border-bottom: 1px solid #000; width: 100%; margin: 8px 0 5px 0; }
.cert-office-name { font-size: 12px; font-weight: normal; text-transform: uppercase; margin-top: 3px; }
.cert-docs-section { margin: 20px 0 15px 0; font-size: 12px; font-family: 'Times New Roman', Times, serif; }
.cert-docs-row { display: flex; gap: 30px; flex-wrap: wrap; margin-top: 5px; }
.cert-doc-item { display: inline-flex; align-items: center; gap: 5px; }
.cert-doc-item input { width: 13px; height: 13px; margin: 0; }

body.dark-mode .certificate-inline-wrap {
    background: #1a1a1a;
    border-color: #444;
    color: #fff;
}
body.dark-mode .cert-doc-header,
body.dark-mode .cert-info-underline,
body.dark-mode .cert-info-underline-short,
body.dark-mode .cert-info-underline-medium,
body.dark-mode .cert-academic-year-underline,
body.dark-mode .cert-signature-line {
    border-color: #888;
}
</style>

<!-- html2canvas and jsPDF for Download as Image / PDF -->
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<div class="header">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Student Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="dark-sidebar">
        <div class="sidebar-header">
            <h3><?php echo $master['chmsu_full_name']; ?></h3>
            <p><?php echo $student['chmsu_course']; ?> <?php echo $student['chmsu_year']; ?><?php echo $student['chmsu_section']; ?></p>
        </div>

        <ul class="sidebar-menu">
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=home"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=feed"><i class="fas fa-rss"></i> Updates Feed</a></li>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=chat">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php if ($chat_unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $chat_unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=office" class="active"><i class="fas fa-clipboard-check"></i> OFFICES</a></li>
            <li style="margin-top: 15px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout(); return false;"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="info-bar">
            <div class="info-bar-item"><strong>ID:</strong> <?php echo $student['chmsu_student_id']; ?></div>
            <div class="info-bar-item"><strong>Name:</strong> <?php echo $master['chmsu_full_name']; ?></div>
            <div class="info-bar-item"><strong>Course/Year/Section:</strong> <?php echo $student['chmsu_course']; ?> <?php echo $student['chmsu_year']; ?><?php echo $student['chmsu_section']; ?></div>
        </div>

        <?php if ($cert_ready): ?>
            <!-- ============================================ -->
            <!-- INLINE CERTIFICATE VIEW -->
            <!-- ============================================ -->
            <div class="cert-top-bar">
                <h2><i class="fas fa-certificate"></i> Certificate of Completion</h2>
                <div class="cert-top-actions">
                    <button class="btn-download-pdf" id="downloadPdfBtn" onclick="downloadCertificateAsPdf()">
                        <i class="fas fa-file-pdf"></i> Download as PDF
                    </button>
                    <button class="btn-download-img" id="downloadImgBtn" onclick="downloadCertificateAsImage()">
                        <i class="fas fa-download"></i> Download as Image
                    </button>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=office" class="btn-back-top">
                        <i class="fas fa-arrow-left"></i> Back to Clearance
                    </a>
                </div>
            </div>

            <div class="certificate-inline-wrap" id="certificateToDownload">
                <div class="cert-logo-container">
                    <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo" class="cert-logo-img" crossorigin="anonymous">
                </div>

                <div class="cert-doc-header">
                    <span>Document Code: F.15-RO-CHMSU</span>
                    <span>Revision No.: 0</span>
                    <span>Effective Date: May 6, 2024</span>
                    <span>Page: 1 of 1</span>
                </div>

                <div class="cert-title">STUDENT'S CLEARANCE</div>

                <div class="cert-student-info">
                    <div class="cert-info-row">
                        <span class="cert-info-label">ID Number:</span>
                        <span class="cert-info-underline"><?php echo htmlspecialchars($student['chmsu_student_id']); ?></span>
                    </div>
                    <div class="cert-info-row-double">
                        <div class="cert-field-group">
                            <span class="cert-info-label">Last Name</span>
                            <span class="cert-info-underline"><?php echo htmlspecialchars($lastName); ?></span>
                        </div>
                        <div class="cert-field-group">
                            <span class="cert-info-label">First Name</span>
                            <span class="cert-info-underline"><?php echo htmlspecialchars($firstName); ?></span>
                        </div>
                    </div>
                    <div class="cert-info-row-double">
                        <div class="cert-field-group">
                            <span class="cert-info-label">Middle Name</span>
                            <span class="cert-info-underline"><?php echo htmlspecialchars($middleName); ?></span>
                        </div>
                        <div class="cert-field-group">
                            <span class="cert-info-label">Ext.</span>
                            <span class="cert-info-underline-short"></span>
                        </div>
                    </div>
                    <div class="cert-info-row-double">
                        <div class="cert-field-group">
                            <span class="cert-info-label">Signature</span>
                            <span class="cert-info-underline-medium"></span>
                        </div>
                        <div class="cert-field-group">
                            <span class="cert-info-label"></span>
                            <span></span>
                        </div>
                    </div>
                    <div class="cert-info-row-double">
                        <div class="cert-field-group">
                            <span class="cert-info-label">Course/Year/Section</span>
                            <span class="cert-info-underline-medium"><?php echo htmlspecialchars($student['chmsu_course']); ?> <?php echo $student['chmsu_year']; ?> - <?php echo htmlspecialchars($student['chmsu_section']); ?></span>
                        </div>
                        <div class="cert-field-group">
                            <span class="cert-info-label">Major</span>
                            <span class="cert-info-underline-short"></span>
                        </div>
                    </div>
                    <div class="cert-info-row">
                        <span class="cert-info-label">Contact number</span>
                        <span class="cert-info-underline-medium"></span>
                    </div>
                </div>

                <div class="cert-certify-text">
                    This is to certify that the student is cleared of money accountability and property responsibility for the concerned personnel of the University.
                </div>

                <div class="cert-checkbox-section">
                    <div class="cert-checkbox-line">
                        <div class="cert-checkbox-item"><input type="checkbox" <?php echo $semesterFirstChecked; ?> disabled> 1st</div>
                        <div class="cert-checkbox-item"><input type="checkbox" <?php echo $semesterSecondChecked; ?> disabled> 2nd</div>
                        <div class="cert-checkbox-item"><input type="checkbox" disabled> Summer</div>
                        <div class="cert-academic-year">
                            <span>Semester, Academic Year</span>
                            <span class="cert-academic-year-underline"><?php echo htmlspecialchars($schoolYear); ?></span>
                        </div>
                    </div>
                </div>

                <div class="cert-signatures-grid">
                    <?php foreach ($offices as $officeName):
                        $isApproved = ($officeStatuses[$officeName] === 'Approved');
                    ?>
                    <div class="cert-signature-item">
                        <?php if ($isApproved): ?>
                            <div class="cert-approval-text cert-approval-approved">APPROVED</div>
                        <?php else: ?>
                            <div class="cert-approval-text cert-approval-pending">PENDING</div>
                        <?php endif; ?>
                        <div class="cert-signature-line"></div>
                        <div class="cert-office-name"><?php echo htmlspecialchars($officeName); ?></div>
                    </div>
                    <?php endforeach; ?>

                    <div class="cert-signature-item">
                        <?php if ($allApproved): ?>
                            <div class="cert-approval-text cert-approval-approved">COMPLETED</div>
                        <?php else: ?>
                            <div class="cert-approval-text cert-approval-pending">IN PROGRESS</div>
                        <?php endif; ?>
                        <div class="cert-signature-line"></div>
                        <div class="cert-office-name">STATUS</div>
                    </div>
                </div>

                <div class="cert-docs-section">
                    <div class="cert-docs-row">
                        <div class="cert-doc-item"><input type="checkbox" <?php echo $sf_checked; ?> disabled> SHS SF10</div>
                        <div class="cert-doc-item"><input type="checkbox" <?php echo $jotr_checked; ?> disabled> JOTR</div>
                        <div class="cert-doc-item"><input type="checkbox" <?php echo $psa_checked; ?> disabled> PSA Birth</div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- ============================================ -->
            <!-- DEFAULT CLEARANCE STATUS VIEW -->
            <!-- ============================================ -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h2 style="font-size: 16px;"><i class="fas fa-clipboard-check"></i> My Clearance Status</h2>
                <button class="btn btn-back btn-sm" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>?view=home'" style="padding: 6px 12px; background: #7f8c8d; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 11px;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </button>
            </div>

            <div style="background: white; padding: 15px 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 13px; color: #1b4d3e;"><strong><i class="fas fa-chart-line"></i> Overall Progress</strong></span>
                    <span style="font-size: 13px;"><?php echo $approvedCount; ?> / <?php echo $totalOffices; ?> Approved (<?php echo $progressPercent; ?>%)</span>
                </div>
                <div style="width: 100%; height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden;">
                    <div style="width: <?php echo $progressPercent; ?>%; height: 100%; background: #27ae60; border-radius: 5px;"></div>
                </div>
            </div>

            <?php if ($allApproved): ?>
            <div class="cert-banner">
                <div class="cert-banner-left">
                    <div class="cert-banner-icon">🎉</div>
                    <div class="cert-banner-text">
                        <h2>You are fully cleared!</h2>
                        <p>All offices have approved your clearance.</p>
                    </div>
                </div>
                <a href="?view=office&show_certificate=1" class="cert-banner-btn">
                    <i class="fas fa-certificate"></i> View Certificate of Completion
                </a>
            </div>
            <?php endif; ?>

            <!-- ============================================ -->
            <!-- COLOR LEGEND -->
            <!-- ============================================ -->
            <div class="color-legend">
                <span class="color-legend-item">
                    <span class="color-legend-dot legend-dot-green"></span> Approved
                </span>
                <span class="color-legend-item">
                    <span class="color-legend-dot legend-dot-red"></span> Declined
                </span>
                <span class="color-legend-item">
                    <span class="color-legend-dot legend-dot-orange"></span> Pending
                </span>
            </div>

            <div class="office-boxes-grid">
                <?php foreach ($offices as $office):
                    $status = $officeStatuses[$office];
                    $statusClass = strtolower($status);
                    $note = $officeNotes[$office];
                ?>
                <div class="office-box <?php echo $statusClass; ?>" 
                     onclick="openOfficeReasonModal('<?php echo htmlspecialchars(addslashes($office)); ?>')"
                     style="cursor: pointer;"
                     title="Tap to view status & reason">
                    <div class="office-box-name"><?php echo htmlspecialchars($office); ?></div>
                    <div class="office-box-status"><?php echo $status; ?></div>
                    <?php if ($status == 'Declined' && !empty($note)): ?>
                        <div class="office-box-note"><strong>Reason:</strong> <?php echo htmlspecialchars($note); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Same-Screen Office Reason Popup Modal -->
<div id="officeReasonModal" onclick="if(event.target===this) closeOfficeReasonModal()" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; width:100%; max-width:440px; border-radius:10px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.25); font-family:'Times New Roman', Times, serif;">
        <div id="ormHeader" style="background:#1b4d3e; color:#fff; padding:14px 18px; display:flex; align-items:center; justify-content:space-between;">
            <span id="ormTitle" style="font-size:16px; font-weight:bold;">Office Details</span>
            <button type="button" onclick="closeOfficeReasonModal()" style="background:none; border:none; color:#fff; font-size:20px; cursor:pointer; line-height:1;">&times;</button>
        </div>
        <div style="padding:18px;">
            <div style="margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                <span style="font-size:13px; color:#555;"><strong>Status:</strong></span>
                <span id="ormStatusBadge" style="padding:3px 10px; border-radius:12px; font-size:12px; font-weight:bold;"></span>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:14px;">
                <div style="font-size:12px; font-weight:bold; color:#334155; margin-bottom:6px;" id="ormReasonHeading">Reason / Details:</div>
                <ul id="ormReasonList" style="margin:0; padding-left:18px; font-size:13px; color:#1f2937; line-height:1.5;"></ul>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap;">
                <a id="ormFeedLink" href="#" style="display:none; padding:7px 14px; background:#d35400; color:#fff; text-decoration:none; border-radius:5px; font-size:12px; font-weight:bold;">
                    <i class="fas fa-bullhorn"></i> View in Feed
                </a>
                <?php if ($allApproved): ?>
                <a href="?view=office&show_certificate=1" style="padding:7px 14px; background:#1b4d3e; color:#fff; text-decoration:none; border-radius:5px; font-size:12px; font-weight:bold;">
                    <i class="fas fa-certificate"></i> View Certificate
                </a>
                <?php endif; ?>
                <button type="button" onclick="closeOfficeReasonModal()" style="padding:7px 14px; background:#64748b; color:#fff; border:none; border-radius:5px; font-size:12px; cursor:pointer;">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
var officeDetailData = <?php echo json_encode($officeDetailData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function openOfficeReasonModal(officeName) {
    var data = officeDetailData[officeName];
    if (!data) return;

    document.getElementById('ormTitle').textContent = data.office;
    var badge = document.getElementById('ormStatusBadge');
    badge.textContent = data.status;

    if (data.status === 'Approved') {
        badge.style.background = '#d4efdf';
        badge.style.color = '#1e8449';
        document.getElementById('ormReasonHeading').textContent = 'Clearance Status:';
    } else if (data.status === 'Declined') {
        badge.style.background = '#fadbd8';
        badge.style.color = '#c0392b';
        document.getElementById('ormReasonHeading').textContent = 'Reason for Declination:';
    } else {
        badge.style.background = '#fdebd0';
        badge.style.color = '#b9770e';
        document.getElementById('ormReasonHeading').textContent = 'Why Pending:';
    }

    var list = document.getElementById('ormReasonList');
    list.innerHTML = '';
    (data.reasons || []).forEach(function(r) {
        var li = document.createElement('li');
        li.textContent = r;
        list.appendChild(li);
    });

    var feedLink = document.getElementById('ormFeedLink');
    if (data.mention_post_id && data.mention_post_id > 0 && data.status !== 'Approved') {
        feedLink.href = '<?php echo $_SERVER['PHP_SELF']; ?>?view=feed#post-' + data.mention_post_id;
        feedLink.style.display = 'inline-block';
    } else {
        feedLink.style.display = 'none';
    }

    document.getElementById('officeReasonModal').style.display = 'flex';
}

function closeOfficeReasonModal() {
    document.getElementById('officeReasonModal').style.display = 'none';
}
// ============================================
// DOWNLOAD CERTIFICATE AS PDF
// ============================================
function downloadCertificateAsPdf() {
    const cert = document.getElementById('certificateToDownload');
    const btn = document.getElementById('downloadPdfBtn');

    if (!cert) {
        alert('Certificate not found.');
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.classList.add('loading');
        btn.innerHTML = '<span class="spinner"></span> Generating PDF...';
    }

    setTimeout(function () {
        html2canvas(cert, {
            scale: 2,
            useCORS: true,
            allowTaint: false,
            backgroundColor: '#ffffff',
            logging: false,
            windowWidth: cert.scrollWidth,
            windowHeight: cert.scrollHeight
        }).then(function (canvas) {
            const studentId = "<?php echo addslashes($student['chmsu_student_id']); ?>";
            const fileName = 'CHMSU_Clearance_Certificate_' + studentId + '_' + Date.now() + '.pdf';
            
            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            if (!window.jspdf || !window.jspdf.jsPDF) {
                // Fallback to print
                window.print();
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('loading');
                    btn.innerHTML = '<i class="fas fa-file-pdf"></i> Download as PDF';
                }
                return;
            }
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('p', 'mm', 'a4');
            const pdfWidth = pdf.internal.pageSize.getWidth();
            const pdfHeight = (canvas.height * pdfWidth) / canvas.width;
            
            pdf.addImage(imgData, 'JPEG', 0, 0, pdfWidth, pdfHeight);
            pdf.save(fileName);

            if (btn) {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.innerHTML = '<i class="fas fa-file-pdf"></i> Download as PDF';
            }
        }).catch(function (err) {
            console.error('PDF generation error:', err);
            alert('Could not generate PDF. Please try again or use browser Print.');
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.innerHTML = '<i class="fas fa-file-pdf"></i> Download as PDF';
            }
        });
    }, 250);
}

// ============================================
// DOWNLOAD CERTIFICATE AS IMAGE
// ============================================
function downloadCertificateAsImage() {
    const cert = document.getElementById('certificateToDownload');
    const btn = document.getElementById('downloadImgBtn');

    if (!cert) {
        alert('Certificate not found.');
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.classList.add('loading');
        btn.innerHTML = '<span class="spinner"></span> Preparing...';
    }

    setTimeout(function () {
        html2canvas(cert, {
            scale: 2,
            useCORS: true,
            allowTaint: false,
            backgroundColor: '#ffffff',
            logging: false,
            windowWidth: cert.scrollWidth,
            windowHeight: cert.scrollHeight
        }).then(function (canvas) {
            const studentId = "<?php echo addslashes($student['chmsu_student_id']); ?>";
            const fileName = 'CHMSU_Clearance_' + studentId + '_' + Date.now() + '.png';

            canvas.toBlob(function (blob) {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = fileName;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('loading');
                    btn.innerHTML = '<i class="fas fa-download"></i> Download as Image';
                }
            }, 'image/png');
        }).catch(function (err) {
            console.error('html2canvas error:', err);
            alert('Could not generate image. Please try again.');
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.innerHTML = '<i class="fas fa-download"></i> Download as Image';
            }
        });
    }, 250);
}
</script>