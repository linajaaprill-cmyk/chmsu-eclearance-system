<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/semester_archive.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin'])) {
    header("Location: ../index.php");
    exit;
}

$admin = $_SESSION['admin'];
$adminSection = 'semesters';
$error = '';
$success = '';

// ============================================
// CREATE / MIGRATE TABLES
// ============================================
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_semesters (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    semester_name VARCHAR(50) NOT NULL,
    semester_code VARCHAR(20) NOT NULL,
    is_current TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    archived_at DATETIME DEFAULT NULL,
    archived_by VARCHAR(100) DEFAULT NULL,
    archived_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$cols = [
    'school_year_id'  => "ALTER TABLE chmsu_semesters ADD COLUMN school_year_id INT(11) DEFAULT NULL AFTER semester_code",
    'sequence_no'     => "ALTER TABLE chmsu_semesters ADD COLUMN sequence_no INT(11) DEFAULT 0 AFTER school_year_id",
    'archived_at'     => "ALTER TABLE chmsu_semesters ADD COLUMN archived_at DATETIME DEFAULT NULL AFTER is_active",
    'archived_by'     => "ALTER TABLE chmsu_semesters ADD COLUMN archived_by VARCHAR(100) DEFAULT NULL AFTER archived_at",
    'archived_reason' => "ALTER TABLE chmsu_semesters ADD COLUMN archived_reason TEXT DEFAULT NULL AFTER archived_by",
];
foreach ($cols as $cname => $sql) {
    $chk = $conn->query("SHOW COLUMNS FROM chmsu_semesters LIKE '$cname'");
    if ($chk && $chk->num_rows == 0) {
        $conn->query($sql);
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS chmsu_semester_transitions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    from_semester_id INT(11) DEFAULT NULL,
    to_semester_id INT(11) NOT NULL,
    transition_type ENUM('advance','rollback') NOT NULL,
    reason TEXT NOT NULL,
    admin_username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$csChk = $conn->query("SHOW COLUMNS FROM chmsu_clearance_status LIKE 'semester_id'");
if ($csChk && $csChk->num_rows == 0) {
    $conn->query("ALTER TABLE chmsu_clearance_status ADD COLUMN semester_id INT(11) DEFAULT NULL");
}

$feedChk = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
if ($feedChk && $feedChk->num_rows == 0) {
    $conn->query("ALTER TABLE chmsu_feed ADD COLUMN semester_id INT(11) DEFAULT NULL AFTER is_archived");
    $curRow = $conn->query("SELECT id FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
    if ($curRow) {
        $curId = intval($curRow['id']);
        $conn->query("UPDATE chmsu_feed SET semester_id = $curId WHERE semester_id IS NULL");
    }
}

sa_ensureSequenceNumbers($conn);

// ============================================
// AUTO-SEED 1ST + 2ND SEM FOR A SPECIFIC SCHOOL YEAR
// Runs when SY filter is active, OR when a new SY is chosen in Add form.
// ============================================
if (!function_exists('sa_seed_semesters_for_sy')) {
    function sa_seed_semesters_for_sy($conn, $syId, $adminUsername = '') {
        $syId = intval($syId);
        if ($syId <= 0) return;

        // Does the semesters table support school_year_id?
        $hasSY = false;
        $chk = $conn->query("SHOW COLUMNS FROM chmsu_semesters LIKE 'school_year_id'");
        if ($chk && $chk->num_rows > 0) $hasSY = true;
        if (!$hasSY) return;

        // Are there already semesters for this SY?
        $existing = $conn->query("SELECT COUNT(*) as c FROM chmsu_semesters WHERE school_year_id = $syId")->fetch_assoc()['c'];
        if (intval($existing) > 0) return;

        // Insert 1st and 2nd Sem for this SY
        $conn->query("INSERT INTO chmsu_semesters
                      (semester_name, semester_code, school_year_id, sequence_no, is_current, is_active)
                      VALUES ('First Semester', '1st Sem', $syId, 999999, 0, 1)");
        $conn->query("INSERT INTO chmsu_semesters
                      (semester_name, semester_code, school_year_id, sequence_no, is_current, is_active)
                      VALUES ('Second Semester', '2nd Sem', $syId, 999999, 0, 1)");

        sa_ensureSequenceNumbers($conn);

        if (!empty($adminUsername)) {
            logActivity($conn, $adminUsername, 'admin', "Auto-created 1st & 2nd Sem for SY ID: $syId");
        }
    }
}

// If the SY filter is active → make sure that SY has both semesters
if (isset($_GET['sy']) && intval($_GET['sy']) > 0) {
    sa_seed_semesters_for_sy($conn, intval($_GET['sy']), $admin);
}

// ============================================
// HANDLE ACTIONS (non-OTP)
// ============================================
if (isset($_POST['action'])) {

    if ($_POST['action'] == 'add') {
        $semester_name  = sanitize($_POST['semester_name']);
        $semester_code  = sanitize($_POST['semester_code']);
        $school_year_id = isset($_POST['school_year_id']) ? intval($_POST['school_year_id']) : 0;

        // If admin picked a School Year → auto-seed 1st & 2nd Sem for it (if not yet present)
        if ($school_year_id > 0) {
            sa_seed_semesters_for_sy($conn, $school_year_id, $admin);
        }

        $check = $conn->query("SELECT * FROM chmsu_semesters
                               WHERE semester_name='$semester_name' OR semester_code='$semester_code'");
        if ($check && $check->num_rows > 0) {
            $error = "Semester already exists!";
        } else {
            $hasSY = false;
            $syChk = $conn->query("SHOW COLUMNS FROM chmsu_semesters LIKE 'school_year_id'");
            if ($syChk && $syChk->num_rows > 0) $hasSY = true;

            if ($hasSY && $school_year_id > 0) {
                $conn->query("INSERT INTO chmsu_semesters
                              (semester_name, semester_code, school_year_id, sequence_no)
                              VALUES ('$semester_name', '$semester_code', $school_year_id, 999999)");
            } else {
                $conn->query("INSERT INTO chmsu_semesters
                              (semester_name, semester_code, sequence_no)
                              VALUES ('$semester_name', '$semester_code', 999999)");
            }
            logActivity($conn, $admin, 'admin', "Added semester: $semester_name");
            sa_ensureSequenceNumbers($conn);
            $success = "Semester added successfully!";
        }
    }
    elseif ($_POST['action'] == 'delete') {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM chmsu_semesters WHERE id = $id AND is_current = 0");
        $success = "Semester deleted successfully!";
    }
}

// ============================================
// READ
// ============================================
$currentSem = sa_getCurrentSemester($conn);
$nextSem    = sa_getNextLogicalSemester($conn, $currentSem);
$nextSemId  = $nextSem ? intval($nextSem['id']) : 0;
$mostRecentArchived = sa_getMostRecentArchived($conn);
$mostRecentArchivedId = $mostRecentArchived ? intval($mostRecentArchived['id']) : 0;

$hasSYCol = false;
$syChk2 = $conn->query("SHOW COLUMNS FROM chmsu_semesters LIKE 'school_year_id'");
if ($syChk2 && $syChk2->num_rows > 0) $hasSYCol = true;

$hasSchoolYearsTable = false;
$tblChk = $conn->query("SHOW TABLES LIKE 'chmsu_school_years'");
if ($tblChk && $tblChk->num_rows > 0) $hasSchoolYearsTable = true;

$syLabelCol = 'school_year';
if ($hasSchoolYearsTable) {
    $syCols = [];
    $syColCheck = $conn->query("SHOW COLUMNS FROM chmsu_school_years");
    if ($syColCheck) while ($c = $syColCheck->fetch_assoc()) $syCols[] = $c['Field'];
    foreach (['school_year','year_name','sy','name','school_year_name'] as $cand) {
        if (in_array($cand, $syCols)) { $syLabelCol = $cand; break; }
    }
}

$filter_sy = isset($_GET['sy']) ? intval($_GET['sy']) : 0;
$syList = [];
if ($hasSchoolYearsTable) {
    $syRes = $conn->query("SELECT id, `$syLabelCol` as label FROM chmsu_school_years ORDER BY id DESC");
    if ($syRes) while ($r = $syRes->fetch_assoc()) $syList[] = $r;
}

$syClause = '';
if ($filter_sy > 0 && $hasSYCol) {
    $syClause = " AND s.school_year_id = $filter_sy";
}

if ($hasSYCol && $hasSchoolYearsTable) {
    $semQuery = "
        SELECT s.*, sy.`$syLabelCol` as school_year_label
        FROM chmsu_semesters s
        LEFT JOIN chmsu_school_years sy ON sy.id = s.school_year_id
        WHERE 1=1 $syClause
        ORDER BY
            sy.id DESC,
            s.sequence_no ASC,
            s.is_current DESC
    ";
} else {
    $semQuery = "
        SELECT s.*, NULL as school_year_label
        FROM chmsu_semesters s
        WHERE 1=1
        ORDER BY
            s.sequence_no ASC,
            s.is_current DESC
    ";
}
$semesters = $conn->query($semQuery);

// AJAX: semester summary
if (isset($_GET['ajax']) && $_GET['ajax'] === 'semester_summary' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $sid = intval($_GET['id']);
    $semRow = $conn->query("SELECT * FROM chmsu_semesters WHERE id = $sid")->fetch_assoc();
    if (!$semRow) { echo json_encode(['ok' => false]); exit; }

    $syLabel = '—';
    if ($hasSYCol && $hasSchoolYearsTable && !empty($semRow['school_year_id'])) {
        $syId = intval($semRow['school_year_id']);
        $syR = $conn->query("SELECT `$syLabelCol` as label FROM chmsu_school_years WHERE id = $syId")->fetch_assoc();
        if ($syR) $syLabel = $syR['label'];
    }

    $totalOffices = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c']);
    $approved = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE semester_id = $sid AND status = 'clear'")->fetch_assoc()['c']);
    $declined = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE semester_id = $sid AND status = 'unclear'")->fetch_assoc()['c']);
    $pending  = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE semester_id = $sid AND status = 'pending'")->fetch_assoc()['c']);

    $feedCount = 0;
    $feedHasSem = false;
    $fchk = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
    if ($fchk && $fchk->num_rows > 0) $feedHasSem = true;
    if ($feedHasSem) {
        $feedCount = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_feed WHERE semester_id = $sid")->fetch_assoc()['c']);
    }

    echo json_encode([
        'ok' => true,
        'id' => intval($semRow['id']),
        'name' => $semRow['semester_name'],
        'code' => $semRow['semester_code'],
        'school_year' => $syLabel,
        'is_current' => intval($semRow['is_current']) === 1,
        'archived_at' => $semRow['archived_at'],
        'archived_by' => $semRow['archived_by'],
        'archived_reason' => $semRow['archived_reason'],
        'created_at' => $semRow['created_at'],
        'total_offices' => $totalOffices,
        'approved' => $approved,
        'declined' => $declined,
        'pending'  => $pending,
        'feed_count' => $feedCount,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Manage Semesters</title>
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

        .admin-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .admin-sidebar::-webkit-scrollbar { width: 5px; }
        .admin-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .admin-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .admin-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .admin-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        .admin-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; padding-bottom: 20px; }
        .admin-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-menu li.dropdown { border-bottom: none; }
        .admin-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
        }
        .admin-sidebar .sidebar-menu a:hover,
        .admin-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        .admin-sidebar .sidebar-menu .dropdown-menu {
            list-style: none;
            padding: 0;
            margin: 0;
            background: #0f3b2f;
            display: block;
        }
        .admin-sidebar .sidebar-menu .dropdown-menu li { border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-menu .dropdown-menu a { padding: 10px 20px 10px 35px; font-size: 12px; }
        .admin-sidebar .sidebar-menu .dropdown-menu a:hover,
        .admin-sidebar .sidebar-menu .dropdown-menu a.active { background: #f1c40f; color: #000000; }
        .dropdown-toggle::after { display: none; }

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
        }
        .content-card-body { padding: 15px; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #333; font-size: 12px; }
        input, textarea, select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }
        input:focus, textarea:focus, select:focus { outline: none; border-color: #1b4d3e; }

        .btn {
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            font-family: 'Times New Roman', Times, serif;
        }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-primary:hover { background: #2d6a4f; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #219a52; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        .btn-view { background: #3498db; color: white; }
        .btn-view:hover { background: #2980b9; }
        .btn-muted { background: #95a5a6; color: white; cursor: not-allowed; opacity: 0.75; }

        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .item-list {
            list-style: none;
            max-height: 700px;
            overflow-y: auto;
        }
        .item-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            gap: 15px;
            flex-wrap: wrap;
        }
        .item-list li:hover { background: #f5f5f5; }

        .badge-current { background: #27ae60; color: white; padding: 2px 10px; border-radius: 3px; font-size: 10px; display: inline-block; }
        .badge-archived { background: #e8f0fe; color: #1967d2; padding: 2px 10px; border-radius: 3px; font-size: 10px; display: inline-block; }
        .badge-planned { background: #f0f0f0; color: #666; padding: 2px 10px; border-radius: 3px; font-size: 10px; display: inline-block; }
        .badge-next { background: #fef3c7; color: #92400e; padding: 2px 10px; border-radius: 3px; font-size: 10px; display: inline-block; }
        .badge-code { background: #f0f0f0; color: #333; padding: 2px 8px; border-radius: 3px; font-size: 10px; display: inline-block; }
        .badge-sy { background: #fff3cd; color: #856404; padding: 2px 8px; border-radius: 3px; font-size: 10px; display: inline-block; }

        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }

        /* Adv modal */
        .adv-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99998; justify-content: center; align-items: center; backdrop-filter: blur(3px); }
        .adv-modal-overlay.active { display: flex; }
        .adv-modal { background: #fff; max-width: 520px; width: 92%; padding: 22px 26px; border-radius: 10px; box-shadow: 0 12px 40px rgba(0,0,0,0.2); font-family: 'Times New Roman', Times, serif; max-height: 90vh; overflow-y: auto; }
        .adv-modal h3 { color: #1b4d3e; margin-bottom: 12px; font-weight: 600; font-size: 17px; text-align: center; }
        .adv-modal .flow-row { display: flex; justify-content: center; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
        .adv-modal .flow-box { padding: 10px 16px; border-radius: 6px; font-size: 13px; text-align: center; min-width: 150px; }
        .adv-modal .flow-from { background: #fdecea; border: 1px solid #f5c6cb; color: #c0392b; }
        .adv-modal .flow-to { background: #d4efdf; border: 1px solid #c3e6cb; color: #1e8449; }
        .adv-modal .flow-arrow { color: #888; font-size: 20px; }
        .adv-modal .warning-list { background: #fff8e1; border-left: 4px solid #f39c12; padding: 12px 16px; margin-bottom: 16px; border-radius: 4px; font-size: 12px; line-height: 1.6; }
        .adv-modal .warning-list strong { color: #b9770e; display: block; margin-bottom: 6px; }
        .adv-modal .warning-list ul { padding-left: 20px; }
        .adv-modal .warning-list li { margin-bottom: 4px; }
        .adv-modal .modal-buttons { display: flex; gap: 10px; justify-content: flex-end; }
        .adv-modal .btn-cancel-adv { padding: 8px 20px; background: #f0f0f0; color: #555; border: none; border-radius: 5px; cursor: pointer; font-size: 13px; font-family: 'Times New Roman', Times, serif; }
        .adv-modal .btn-continue { padding: 8px 20px; background: #1b4d3e; color: #fff; border: none; border-radius: 5px; cursor: pointer; font-size: 13px; font-family: 'Times New Roman', Times, serif; transition: background 0.3s; }
        .adv-modal .btn-continue:hover { background: #2d6a4f; }
        .adv-modal .btn-continue.rollback { background: #e74c3c; }
        .adv-modal .btn-continue.rollback:hover { background: #c0392b; }

        /* View modal */
        .view-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99997; justify-content: center; align-items: center; backdrop-filter: blur(3px); }
        .view-modal-overlay.active { display: flex; }
        .view-modal { background: #fff; max-width: 560px; width: 92%; padding: 22px 26px; border-radius: 10px; box-shadow: 0 12px 40px rgba(0,0,0,0.2); font-family: 'Times New Roman', Times, serif; max-height: 90vh; overflow-y: auto; }
        .view-modal h3 { color: #1b4d3e; margin-bottom: 14px; font-weight: 600; font-size: 17px; text-align: center; }
        .view-modal .readonly-banner { background: #f0f8ff; border-left: 4px solid #3498db; padding: 10px 14px; border-radius: 4px; font-size: 12px; color: #1b4d3e; margin-bottom: 14px; }
        .view-modal table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .view-modal table th { background: #f8f9fa; text-align: left; padding: 8px 10px; border-bottom: 1px solid #ddd; font-weight: 600; }
        .view-modal table td { padding: 8px 10px; border-bottom: 1px solid #eee; }
        .view-modal .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 10px; margin: 14px 0; }
        .view-modal .stat-box { background: #f8f9fa; padding: 10px; border-radius: 6px; text-align: center; }
        .view-modal .stat-box .num { font-size: 20px; font-weight: 700; color: #1b4d3e; }
        .view-modal .stat-box .lbl { font-size: 11px; color: #777; text-transform: uppercase; }
        .view-modal .close-btn-view { display: block; margin: 16px auto 0; padding: 8px 22px; background: #7f8c8d; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 13px; font-family: 'Times New Roman', Times, serif; }
        .view-modal .close-btn-view:hover { background: #6c7a7d; }

        /* OTP modal */
        .otp-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999; justify-content: center; align-items: center; backdrop-filter: blur(3px); }
        .otp-modal-overlay.active { display: flex; }
        .otp-modal { background: #fff; max-width: 420px; width: 92%; padding: 16px 20px 14px 20px; border-radius: 10px; box-shadow: 0 12px 40px rgba(0,0,0,0.2); position: relative; animation: modalFadeIn 0.25s ease; font-family: 'Times New Roman', Times, serif; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(-15px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .otp-modal .close-btn { position: absolute; top: 6px; right: 10px; cursor: pointer; font-size: 18px; color: #aaa; background: none; border: none; transition: color 0.2s; line-height: 1; }
        .otp-modal .close-btn:hover { color: #333; }
        .otp-modal h3 { color: #1b4d3e; margin-bottom: 2px; font-weight: 600; font-size: 15px; text-align: center; }
        .otp-modal .modal-subtitle { font-size: 11px; color: #888; margin-bottom: 10px; text-align: center; line-height: 1.3; }
        .otp-modal .otp-status { padding: 5px 8px; margin-bottom: 8px; border-radius: 4px; display: none; font-size: 11px; text-align: center; }
        .otp-modal .otp-status.success { background: #d4edda; color: #155724; display: block; border-left: 3px solid #27ae60; }
        .otp-modal .otp-status.error { background: #f8d7da; color: #721c24; display: block; border-left: 3px solid #e74c3c; }
        .otp-modal .otp-status.info { background: #f0f8ff; color: #1b4d3e; display: block; border-left: 3px solid #1b4d3e; }
        .otp-modal .otp-item-label { display: block; font-size: 10px; color: #666; margin-bottom: 2px; text-align: center; }
        .otp-modal .otp-item-name { background: #f5f5f5; padding: 6px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; color: #1b4d3e; margin-bottom: 10px; border: 1px solid #e8e8e8; text-align: center; }
        .otp-boxes-container { display: flex; justify-content: center; gap: 8px; margin-bottom: 10px; }
        .otp-box { width: 38px; height: 40px; border: 2px solid #d0d0d0; border-radius: 6px; text-align: center; font-size: 18px; font-weight: 600; font-family: 'Times New Roman', Times, serif; background: #fafafa; transition: all 0.2s; color: #1b4d3e; outline: none; }
        .otp-box:focus { border-color: #1b4d3e; background: #fff; box-shadow: 0 0 0 2px rgba(27,77,62,0.12); }
        .otp-box.has-value { border-color: #1b4d3e; background: #eaf3ef; }
        .otp-box.loading { border-color: #f1c40f; background: #fef9e7; animation: pulse-border 1s infinite; }
        @keyframes pulse-border { 0% { border-color: #f1c40f; } 50% { border-color: #d4a800; } 100% { border-color: #f1c40f; } }
        .otp-box.error-shake { animation: shake 0.35s ease; border-color: #e74c3c; background: #fde8e8; }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }
        .otp-actions-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
        .otp-timer { font-size: 11px; color: #e74c3c; }
        .otp-timer .countdown { font-weight: 700; font-size: 13px; }
        .btn-resend { background: transparent; color: #1b4d3e; border: 1px solid #1b4d3e; padding: 2px 12px; border-radius: 4px; cursor: pointer; font-size: 10px; transition: all 0.3s; }
        .btn-resend:hover { background: #1b4d3e; color: white; }
        .btn-resend:disabled { opacity: 0.4; cursor: not-allowed; }
        .otp-verify-row { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 10px; }
        .btn-verify { padding: 5px 18px; background: #1b4d3e; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.3s; opacity: 0.5; pointer-events: none; font-family: 'Times New Roman', Times, serif; }
        .btn-verify.active { opacity: 1; pointer-events: auto; }
        .btn-verify.active:hover { background: #2d6a4f; }
        .btn-verify:disabled { opacity: 0.5; pointer-events: none; }
        .btn-verify .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.7s linear infinite; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .otp-status-indicator { display: flex; align-items: center; gap: 5px; min-width: 55px; }
        .otp-status-indicator .circle { width: 18px; height: 18px; border-radius: 50%; display: none; align-items: center; justify-content: center; font-size: 9px; color: white; font-weight: 700; flex-shrink: 0; }
        .otp-status-indicator .circle.show { display: flex; }
        .otp-status-indicator .circle.success { background: #27ae60; animation: popIn 0.3s ease; }
        .otp-status-indicator .circle.error { background: #e74c3c; animation: popIn 0.3s ease; }
        .otp-status-indicator .circle.loading { background: #f1c40f; animation: spin 0.8s linear infinite; border: 2px solid transparent; border-top-color: #fff; }
        @keyframes popIn { 0% { transform: scale(0); } 70% { transform: scale(1.2); } 100% { transform: scale(1); } }
        .otp-status-indicator .status-label { font-size: 11px; color: #666; display: none; }
        .otp-status-indicator .status-label.show { display: inline; }
        .otp-status-indicator .status-label.success { color: #27ae60; }
        .otp-status-indicator .status-label.error { color: #e74c3c; }
        .modal-footer { margin-top: 6px; padding-top: 8px; border-top: 1px solid #eee; display: flex; justify-content: center; gap: 10px; }
        .btn-cancel { background: #f0f0f0; color: #555; border: none; padding: 3px 16px; border-radius: 4px; cursor: pointer; font-size: 11px; transition: background 0.3s; }
        .btn-cancel:hover { background: #e0e0e0; }

        body.dark-mode .otp-modal { background: #1a1a1a; color: #fff; }
        body.dark-mode .otp-modal h3 { color: #f1c40f; }
        body.dark-mode .otp-modal .modal-subtitle { color: #bbb; }
        body.dark-mode .otp-modal .otp-item-name { background: #2c2c2c; color: #f1c40f; border-color: #444; }
        body.dark-mode .otp-box { background: #2c2c2c; border-color: #555; color: #fff; }
        body.dark-mode .otp-box:focus { border-color: #f1c40f; background: #333; box-shadow: 0 0 0 2px rgba(241,196,15,0.2); }
        body.dark-mode .otp-box.has-value { border-color: #f1c40f; background: #2a3a2a; }
        body.dark-mode .modal-footer { border-top-color: #333; }
        body.dark-mode .btn-cancel { background: #333; color: #ccc; }
        body.dark-mode .btn-resend { color: #f1c40f; border-color: #f1c40f; }
        body.dark-mode .otp-modal .otp-status.info { background: #1a3a2a; color: #8fdfb0; }

        body.dark-mode .adv-modal { background: #1a1a1a; color: #fff; }
        body.dark-mode .adv-modal h3 { color: #f1c40f; }
        body.dark-mode .adv-modal .flow-from { background: #3a1a1a; border-color: #7a2a2a; color: #ff8a8a; }
        body.dark-mode .adv-modal .flow-to { background: #1a3a2a; border-color: #2d6a4f; color: #8fdfb0; }
        body.dark-mode .adv-modal .warning-list { background: #2a2410; color: #f1c40f; }
        body.dark-mode .adv-modal .btn-cancel-adv { background: #333; color: #ccc; }

        body.dark-mode .view-modal { background: #1a1a1a; color: #fff; }
        body.dark-mode .view-modal h3 { color: #f1c40f; }
        body.dark-mode .view-modal .readonly-banner { background: #1a2c3a; color: #8fc4e8; }
        body.dark-mode .view-modal table th { background: #2c2c2c; color: #f1c40f; border-color: #444; }
        body.dark-mode .view-modal table td { color: #eee; border-color: #333; }
        body.dark-mode .view-modal .stat-box { background: #2c2c2c; }
        body.dark-mode .view-modal .stat-box .num { color: #f1c40f; }
        body.dark-mode .view-modal .stat-box .lbl { color: #bbb; }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .item-list li { border-bottom-color: #333; color: #fff; }
        body.dark-mode .item-list li:hover { background: #2c2c2c; }
        body.dark-mode input, body.dark-mode textarea, body.dark-mode select { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .badge-current { background: #1a7a3a; }
        body.dark-mode .badge-code { background: #333; color: #f1c40f; }
        body.dark-mode .badge-archived { background: #1a2c4a; color: #6ba8ff; }
        body.dark-mode .badge-planned { background: #2c2c2c; color: #bbb; }
        body.dark-mode .badge-next { background: #4a3a10; color: #f1c40f; }
        body.dark-mode .badge-sy { background: #4a3a10; color: #f1c40f; }

        @media (max-width: 768px) {
            .admin-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .otp-modal { padding: 14px 14px 10px 14px; max-width: 380px; }
            .otp-box { width: 32px; height: 34px; font-size: 15px; }
            .otp-boxes-container { gap: 5px; }
        }
        @media (max-width: 480px) {
            .otp-box { width: 28px; height: 30px; font-size: 13px; }
            .otp-boxes-container { gap: 3px; }
            .otp-modal { max-width: 340px; }
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
        <p>CLEARANCE SYSTEM | Admin Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <a href="?logout=1" class="logout-btn" style="text-decoration:none;">Logout</a>
</div>

<div class="dashboard-wrapper">
    <div class="admin-sidebar">
        <div class="sidebar-header">
            <h3>Admin: <?php echo htmlspecialchars($admin); ?></h3>
            <p>System Administrator</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?adminsection=dashboard" class="<?php echo $adminSection == 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a></li>

            <li class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class="fas fa-cogs"></i> Maintenance
                </a>
                <ul class="dropdown-menu">
                    <li><a href="?adminsection=courses" class="<?php echo $adminSection == 'courses' ? 'active' : ''; ?>">
                        <i class="fas fa-book"></i> Courses
                    </a></li>
                    <li><a href="?adminsection=offices" class="<?php echo $adminSection == 'offices' ? 'active' : ''; ?>">
                        <i class="fas fa-building"></i> Offices
                    </a></li>
                    <li><a href="?adminsection=sections" class="<?php echo $adminSection == 'sections' ? 'active' : ''; ?>">
                        <i class="fas fa-layer-group"></i> Sections
                    </a></li>
                    <li><a href="?adminsection=school_years" class="<?php echo $adminSection == 'school_years' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar"></i> School Years
                    </a></li>
                    <li><a href="?adminsection=semesters" class="<?php echo $adminSection == 'semesters' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Semesters
                    </a></li>
                </ul>
            </li>

            <li><a href="?adminsection=reports" class="<?php echo $adminSection == 'reports' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>

            <li><a href="?adminsection=history" class="<?php echo $adminSection == 'history' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Clearance History
            </a></li>

            <li><a href="?adminsection=activity" class="<?php echo $adminSection == 'activity' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Activity Log
            </a></li>

            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="?logout=1">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Manage Semesters</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- CURRENT SEMESTER STATUS CARD -->
        <?php
        $currentSemForCard = $conn->query("SELECT * FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
        ?>
        <div class="content-card" style="border-left: 5px solid #27ae60;">
            <div class="content-card-header" style="background: #f0f9f4;">
                <i class="fas fa-play-circle" style="color:#27ae60;"></i> Currently Active Semester
            </div>
            <div class="content-card-body">
                <?php if ($currentSemForCard): ?>
                    <div style="font-size: 15px; font-weight: 600; color: #1b4d3e; margin-bottom: 6px;">
                        <?php echo htmlspecialchars($currentSemForCard['semester_name']); ?>
                        <span class="badge-code"><?php echo htmlspecialchars($currentSemForCard['semester_code']); ?></span>
                    </div>
                    <div style="font-size: 12px; color: #666;">
                        All student and office clearance activity is currently tagged to this semester.
                        <br><strong style="color:#b9770e;">Advancing will auto-create the next semester in sequence.</strong>
                    </div>
                <?php else: ?>
                    <div style="color: #856404; background: #fff3cd; padding: 10px; border-radius: 4px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        No active semester. Set a current semester below to begin clearance.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ADD SEMESTER -->
        <div class="content-card">
            <div class="content-card-header">Add New Semester</div>
            <div class="content-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="filter-row">
                        <div class="form-group" style="flex:1;">
                            <label>Semester Name</label>
                            <input type="text" name="semester_name" placeholder="First Semester" required>
                        </div>
                        <div class="form-group" style="flex:0 0 120px;">
                            <label>Semester Code</label>
                            <input type="text" name="semester_code" placeholder="1st Sem" required>
                        </div>
                        <?php if ($hasSYCol && $hasSchoolYearsTable && !empty($syList)): ?>
                        <div class="form-group" style="flex:1;">
                            <label>School Year</label>
                            <select name="school_year_id" required>
                                <option value="">Select School Year</option>
                                <?php foreach ($syList as $sy): ?>
                                    <option value="<?php echo intval($sy['id']); ?>">
                                        <?php echo htmlspecialchars($sy['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">Add Semester</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- SEMESTER LIST -->
        <div class="content-card">
            <div class="content-card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <span>Semester List</span>
                <?php if ($hasSYCol && $hasSchoolYearsTable && !empty($syList)): ?>
                <form method="GET" style="display:flex; gap:8px; align-items:center; margin:0;">
                    <input type="hidden" name="adminsection" value="semesters">
                    <label style="font-size:11px; margin:0;">Filter by School Year:</label>
                    <select name="sy" onchange="this.form.submit()" style="width:auto; min-width:160px; padding:4px 8px; font-size:12px;">
                        <option value="0">— All —</option>
                        <?php foreach ($syList as $sy): ?>
                            <option value="<?php echo intval($sy['id']); ?>" <?php echo $filter_sy == $sy['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sy['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>
            <div class="content-card-body">
                <ul class="item-list">
                    <?php
                    if ($semesters && $semesters->num_rows > 0):
                        while ($sem = $semesters->fetch_assoc()):
                            $isCurrent   = intval($sem['is_current']) === 1;
                            $isArchived  = !empty($sem['archived_at']);
                            $isNext      = ($nextSemId === intval($sem['id']));
                            $isRestorable = ($mostRecentArchivedId === intval($sem['id']));
                            $syLabel = $sem['school_year_label'] ?? null;
                    ?>
                    <li>
                        <div style="flex: 1; min-width: 220px;">
                            <strong><?php echo htmlspecialchars($sem['semester_name']); ?></strong>
                            <span class="badge-code"><?php echo htmlspecialchars($sem['semester_code']); ?></span>
                            <?php if (!empty($syLabel)): ?>
                                <span class="badge-sy"><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($syLabel); ?></span>
                            <?php endif; ?>

                            <?php if ($isCurrent): ?>
                                <span class="badge-current"><i class="fas fa-check-circle"></i> Currently Active</span>
                            <?php elseif ($isArchived): ?>
                                <span class="badge-archived">
                                    <i class="fas fa-archive"></i> Archived · <?php echo date('M d, Y', strtotime($sem['archived_at'])); ?>
                                </span>
                            <?php elseif ($isNext): ?>
                                <span class="badge-next"><i class="fas fa-arrow-right"></i> Next in Sequence</span>
                            <?php else: ?>
                                <span class="badge-planned"><i class="far fa-clock"></i> Not Active Yet</span>
                            <?php endif; ?>
                        </div>

                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <?php if (!$isCurrent): ?>
                                <button type="button" class="btn btn-view btn-sm" onclick="openViewModal(<?php echo intval($sem['id']); ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            <?php endif; ?>

                            <?php if ($isArchived && $isRestorable): ?>
                                <button type="button" class="btn btn-warning btn-sm"
                                    onclick="openRollbackConfirm(<?php echo intval($sem['id']); ?>, '<?php echo addslashes($sem['semester_name'] . ' (' . $sem['semester_code'] . ')'); ?>', <?php echo $currentSemForCard ? "'" . addslashes($currentSemForCard['semester_name'] . ' (' . $currentSemForCard['semester_code'] . ')') . "'" : 'null'; ?>)">
                                    <i class="fas fa-undo"></i> Restore
                                </button>
                            <?php endif; ?>

                            <?php if (!$isCurrent): ?>
                                <button type="button" class="btn btn-success btn-sm"
                                    onclick="openSetCurrentConfirm(<?php echo intval($sem['id']); ?>, '<?php echo addslashes($sem['semester_name'] . ' (' . $sem['semester_code'] . ')'); ?>', <?php echo $currentSemForCard ? "'" . addslashes($currentSemForCard['semester_name'] . ' (' . $currentSemForCard['semester_code'] . ')') . "'" : 'null'; ?>)">
                                    <i class="fas fa-flag-checkered"></i> Set as Current
                                </button>
                            <?php endif; ?>

                            <?php if (!$isCurrent): ?>
                                <button type="button" class="btn btn-danger btn-sm"
                                    onclick="openOTPModal('semesters', <?php echo intval($sem['id']); ?>, '<?php echo addslashes($sem['semester_name'] . ' (' . $sem['semester_code'] . ')'); ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <li style="text-align:center;">No semesters found. Add your first semester above.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- CONFIRMATION MODAL (Set Current / Restore)   -->
<!-- ============================================ -->
<div class="adv-modal-overlay" id="advModal">
    <div class="adv-modal">
        <h3 id="advTitle">Confirm Semester Change</h3>

        <div class="flow-row">
            <div class="flow-box flow-from" id="advFromBox">—</div>
            <div class="flow-arrow"><i class="fas fa-arrow-right"></i></div>
            <div class="flow-box flow-to" id="advToBox">—</div>
        </div>

        <div class="warning-list" id="advWarning">
            <strong><i class="fas fa-exclamation-triangle"></i> What will happen:</strong>
            <ul id="advWarningList"></ul>
        </div>

        <div class="modal-buttons">
            <button type="button" class="btn-cancel-adv" onclick="closeAdvModal()">Cancel</button>
            <button type="button" class="btn-continue" id="advContinueBtn" onclick="proceedToOTP()">
                Continue → Send OTP
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- VIEW MODAL (read-only)                       -->
<!-- ============================================ -->
<div class="view-modal-overlay" id="viewModal">
    <div class="view-modal">
        <h3 id="viewTitle">Semester Details</h3>
        <div class="readonly-banner">
            <i class="fas fa-eye"></i> <strong>Read-only view.</strong>
            This semester is archived or planned. No changes can be made from here.
        </div>
        <div id="viewBody">
            <div style="text-align:center; padding:20px; color:#999;">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>
        <button type="button" class="close-btn-view" onclick="closeViewModal()">Close</button>
    </div>
</div>

<!-- ============================================ -->
<!-- OTP VERIFICATION MODAL                       -->
<!-- ============================================ -->
<div class="otp-modal-overlay" id="otpModal">
    <div class="otp-modal">
        <button class="close-btn" onclick="closeOTPModal()">&times;</button>
        <h3><i class="fas fa-shield-alt" style="color:#f1c40f;"></i> <span id="otpTitle">Verify Action</span></h3>
        <p class="modal-subtitle" id="otpSubtitle">Enter the 6-digit OTP sent to your email.</p>

        <div id="otpStatus" class="otp-status"></div>

        <label class="otp-item-label">Action:</label>
        <div class="otp-item-name" id="otpItemName">-</div>

        <div class="otp-boxes-container" id="otpBoxesContainer">
            <input type="text" class="otp-box" id="otpBox1" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox2" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox3" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox4" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox5" maxlength="1" autocomplete="off">
            <input type="text" class="otp-box" id="otpBox6" maxlength="1" autocomplete="off">
        </div>

        <div class="otp-actions-row">
            <span class="otp-timer">
                <i class="fas fa-clock"></i> <span class="countdown" id="otpCountdown">2:00</span>
            </span>
            <button class="btn-resend" id="resendBtn" onclick="resendOTP()">
                <i class="fas fa-redo"></i> Resend
            </button>
        </div>

        <div class="otp-verify-row">
            <button class="btn-verify" id="verifyBtn" onclick="verifyOTP()">
                Verify
            </button>
            <div class="otp-status-indicator" id="statusIndicator">
                <span class="circle" id="statusCircle"></span>
                <span class="status-label" id="statusLabel"></span>
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeOTPModal()">Cancel</button>
        </div>

        <input type="hidden" id="otpCodeHidden">
    </div>
</div>

<!-- HIDDEN FORM for semester set-current/rollback POST -->
<form id="semesterActionForm" method="POST" style="display:none;">
    <input type="hidden" name="action" id="semAction">
    <input type="hidden" name="target_semester_id" id="semTargetId">
    <input type="hidden" name="reason" id="semReason" value="">
    <input type="hidden" name="otp_code" id="semOtpCode">
</form>

<script>
    // ============================================
    // DARK MODE
    // ============================================
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
        if (btn) btn.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
    }

    // ============================================
    // CONFIRMATION MODAL — SET CURRENT / RESTORE
    // ============================================
    let pendingAction = {
        type: null,
        target_id: 0,
        target_name: '',
        from_name: ''
    };

    function openSetCurrentConfirm(targetId, targetName, currentName) {
        pendingAction = {
            type: 'advance',
            target_id: targetId,
            target_name: targetName,
            from_name: currentName || 'No current semester'
        };

        document.getElementById('advTitle').textContent = 'Set as Current Semester';
        document.getElementById('advFromBox').textContent = pendingAction.from_name;
        document.getElementById('advToBox').textContent = targetName;
        document.getElementById('advWarningList').innerHTML = `
            <li>The current semester will be <strong>archived</strong>.</li>
            <li>The selected semester will become the <strong>currently active</strong> semester.</li>
            <li><strong>All clearances</strong> will be archived into the old semester's history.</li>
            <li>Every student will start <strong>fresh with pending status</strong> in the new semester.</li>
            <li>Old feed posts are <strong>archived</strong>.</li>
            <li>This action requires <strong>OTP verification</strong>.</li>
        `;
        document.getElementById('advContinueBtn').classList.remove('rollback');
        document.getElementById('advContinueBtn').textContent = 'Continue → Send OTP';

        document.getElementById('advModal').classList.add('active');
    }

    function openRollbackConfirm(targetId, targetName, currentName) {
        pendingAction = {
            type: 'rollback',
            target_id: targetId,
            target_name: targetName,
            from_name: currentName || 'No current semester'
        };

        document.getElementById('advTitle').textContent = 'Restore Archived Semester';
        document.getElementById('advFromBox').textContent = pendingAction.from_name;
        document.getElementById('advToBox').textContent = targetName;
        document.getElementById('advWarningList').innerHTML = `
            <li>The current semester will be <strong>deactivated</strong>.</li>
            <li>The selected <strong>archived</strong> semester will become <strong>currently active</strong>.</li>
            <li>Its archived status will be <strong>cleared</strong>.</li>
            <li>Clearance records from the previous active semester remain safe.</li>
            <li>This action requires <strong>OTP verification</strong>.</li>
        `;
        document.getElementById('advContinueBtn').classList.add('rollback');
        document.getElementById('advContinueBtn').textContent = 'Continue → Send OTP';

        document.getElementById('advModal').classList.add('active');
    }

    function closeAdvModal() {
        document.getElementById('advModal').classList.remove('active');
        pendingAction = { type: null, target_id: 0, target_name: '', from_name: '' };
    }

    // ============================================
    // VIEW MODAL (read-only)
    // ============================================
    function openViewModal(semesterId) {
        document.getElementById('viewBody').innerHTML =
            '<div style="text-align:center; padding:20px; color:#999;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
        document.getElementById('viewModal').classList.add('active');

        fetch('?adminsection=semesters&ajax=semester_summary&id=' + encodeURIComponent(semesterId))
            .then(r => r.json())
            .then(data => {
                if (!data || !data.ok) {
                    document.getElementById('viewBody').innerHTML =
                        '<div style="color:#c0392b; text-align:center;">Could not load semester details.</div>';
                    return;
                }
                let statusLabel = 'Planned';
                let statusColor = '#856404';
                if (data.is_current) { statusLabel = 'Currently Active'; statusColor = '#1e8449'; }
                else if (data.archived_at) { statusLabel = 'Archived'; statusColor = '#1967d2'; }

                document.getElementById('viewTitle').textContent = data.name + ' (' + data.code + ')';

                let archiveRow = '';
                if (data.archived_at) {
                    archiveRow = `
                        <tr><td><strong>Archived At</strong></td><td>${escapeHtml(data.archived_at)}</td></tr>
                        <tr><td><strong>Archived By</strong></td><td>${escapeHtml(data.archived_by || '—')}</td></tr>
                        <tr><td><strong>Reason</strong></td><td>${escapeHtml(data.archived_reason || '—')}</td></tr>
                    `;
                }

                document.getElementById('viewBody').innerHTML = `
                    <div class="stats-grid">
                        <div class="stat-box"><div class="num">${data.approved}</div><div class="lbl">Approved</div></div>
                        <div class="stat-box"><div class="num" style="color:#c0392b;">${data.declined}</div><div class="lbl">Declined</div></div>
                        <div class="stat-box"><div class="num" style="color:#b9770e;">${data.pending}</div><div class="lbl">Pending</div></div>
                        <div class="stat-box"><div class="num">${data.feed_count}</div><div class="lbl">Feed Posts</div></div>
                    </div>
                    <table>
                        <tr><td><strong>Semester</strong></td><td>${escapeHtml(data.name)}</td></tr>
                        <tr><td><strong>Code</strong></td><td>${escapeHtml(data.code)}</td></tr>
                        <tr><td><strong>School Year</strong></td><td>${escapeHtml(data.school_year)}</td></tr>
                        <tr><td><strong>Status</strong></td><td><span style="color:${statusColor}; font-weight:600;">${statusLabel}</span></td></tr>
                        <tr><td><strong>Created</strong></td><td>${escapeHtml(data.created_at)}</td></tr>
                        <tr><td><strong>Total Offices</strong></td><td>${data.total_offices}</td></tr>
                        ${archiveRow}
                    </table>
                `;
            })
            .catch(() => {
                document.getElementById('viewBody').innerHTML =
                    '<div style="color:#c0392b; text-align:center;">Network error.</div>';
            });
    }

    function closeViewModal() {
        document.getElementById('viewModal').classList.remove('active');
    }

    function escapeHtml(t) {
        if (t === null || t === undefined) return '';
        const d = document.createElement('div');
        d.textContent = String(t);
        return d.innerHTML;
    }

    // ============================================
    // OTP MODAL
    // ============================================
    let otpTimerInterval = null;
    let otpCountdownSeconds = 120;
    let currentOtpContext = { action_type: '', item_name: '' };
    let isVerifying = false;

    function getOtpBoxes() {
        return [
            document.getElementById('otpBox1'),
            document.getElementById('otpBox2'),
            document.getElementById('otpBox3'),
            document.getElementById('otpBox4'),
            document.getElementById('otpBox5'),
            document.getElementById('otpBox6')
        ];
    }
    function getOTPValue() {
        return getOtpBoxes().map(b => b.value).join('');
    }
    function isOTPComplete() {
        return getOtpBoxes().every(b => b.value.length === 1 && /^\d$/.test(b.value));
    }
    function updateVerifyButton() {
        const btn = document.getElementById('verifyBtn');
        if (isOTPComplete() && !isVerifying && otpCountdownSeconds > 0) btn.classList.add('active');
        else btn.classList.remove('active');
    }
    function setStatusIndicator(circleClass, labelText, labelClass) {
        const circle = document.getElementById('statusCircle');
        const label = document.getElementById('statusLabel');
        circle.className = 'circle';
        label.className = 'status-label';
        if (circleClass) circle.classList.add('show', circleClass);
        else circle.classList.remove('show');
        if (labelText) {
            label.textContent = labelText;
            label.classList.add('show');
            if (labelClass) label.classList.add(labelClass);
        } else {
            label.classList.remove('show');
            label.textContent = '';
        }
    }
    function clearStatusIndicator() { setStatusIndicator(null, null, null); }

    function handleOtpInput(e, currentIndex) {
        const boxes = getOtpBoxes();
        const box = boxes[currentIndex];
        const val = e.target.value;
        if (val && !/^\d$/.test(val)) { box.value = ''; return; }
        if (val) { box.classList.add('has-value'); box.classList.remove('error-shake'); }
        else { box.classList.remove('has-value'); }
        if (val && currentIndex < 5) boxes[currentIndex + 1].focus();
        document.getElementById('otpCodeHidden').value = getOTPValue();
        updateVerifyButton();
        if (val && currentIndex === 5 && isOTPComplete()) setTimeout(() => verifyOTP(), 300);
    }
    function handleOtpKeydown(e, currentIndex) {
        const boxes = getOtpBoxes();
        const box = boxes[currentIndex];
        if (e.key === 'Backspace' && !box.value && currentIndex > 0) {
            boxes[currentIndex - 1].focus();
            boxes[currentIndex - 1].value = '';
            boxes[currentIndex - 1].classList.remove('has-value');
            updateVerifyButton();
        }
        if (e.key === 'Enter') { e.preventDefault(); verifyOTP(); }
    }
    function handleOtpPaste(e) {
        e.preventDefault();
        const paste = (e.clipboardData || window.clipboardData).getData('text');
        const digits = paste.replace(/\D/g, '').slice(0, 6);
        const boxes = getOtpBoxes();
        for (let i = 0; i < digits.length && i < 6; i++) {
            boxes[i].value = digits[i];
            boxes[i].classList.add('has-value');
        }
        const nextIndex = Math.min(digits.length, 5);
        if (nextIndex < 6) boxes[nextIndex].focus();
        document.getElementById('otpCodeHidden').value = getOTPValue();
        updateVerifyButton();
        if (digits.length === 6 && isOTPComplete()) setTimeout(() => verifyOTP(), 300);
    }
    function focusFirstBox() {
        const boxes = getOtpBoxes();
        setTimeout(() => boxes[0].focus(), 350);
    }

    function openOTPModal(table, itemId, itemName) {
        currentOtpContext = { action_type: 'delete', item_name: itemName };
        document.getElementById('otpTitle').textContent = 'Verify Delete';
        document.getElementById('otpSubtitle').textContent = 'Enter the 6-digit OTP sent to your email.';
        document.getElementById('otpItemName').textContent = itemName;

        window.__deleteTarget = { table: table, item_id: itemId };

        resetOtpModal();
        document.getElementById('otpModal').classList.add('active');
        focusFirstBox();
        sendOTP('delete', table, itemId, itemName);
    }

    function proceedToOTP() {
        document.getElementById('advModal').classList.remove('active');

        const isRollback = pendingAction.type === 'rollback';
        currentOtpContext = {
            action_type: pendingAction.type,
            item_name: pendingAction.target_name
        };

        document.getElementById('otpTitle').textContent = isRollback ? 'Verify Restore' : 'Verify Set as Current';
        document.getElementById('otpSubtitle').textContent = isRollback
            ? 'Enter the OTP to confirm restoring this archived semester.'
            : 'Enter the OTP to confirm setting this semester as current.';
        document.getElementById('otpItemName').textContent =
            pendingAction.from_name + '  →  ' + pendingAction.target_name;

        resetOtpModal();
        document.getElementById('otpModal').classList.add('active');
        focusFirstBox();
        sendOTP(pendingAction.type, '', pendingAction.target_id, pendingAction.target_name);
    }

    function resetOtpModal() {
        const boxes = getOtpBoxes();
        boxes.forEach(box => {
            box.value = '';
            box.className = 'otp-box';
            box.disabled = false;
            box.style.borderColor = '';
            box.style.background = '';
        });
        document.getElementById('otpCodeHidden').value = '';
        document.getElementById('otpStatus').className = 'otp-status';
        document.getElementById('otpStatus').style.display = 'none';

        const verifyBtn = document.getElementById('verifyBtn');
        verifyBtn.classList.remove('active');
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = 'Verify';
        isVerifying = false;

        document.getElementById('resendBtn').disabled = false;
        clearStatusIndicator();
    }

    function closeOTPModal() {
        document.getElementById('otpModal').classList.remove('active');
        if (otpTimerInterval) { clearInterval(otpTimerInterval); otpTimerInterval = null; }
        document.getElementById('otpStatus').className = 'otp-status';
        document.getElementById('otpStatus').style.display = 'none';
        isVerifying = false;
        clearStatusIndicator();
        pendingAction = { type: null, target_id: 0, target_name: '', from_name: '' };
        window.__deleteTarget = null;
    }

    function sendOTP(actionType, table, itemId, itemName) {
        const statusDiv = document.getElementById('otpStatus');
        statusDiv.className = 'otp-status info';
        statusDiv.style.display = 'block';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending OTP...';
        setStatusIndicator('loading', 'Sending', '');

        let body = '';
        if (actionType === 'delete') {
            body = 'action=send_delete_otp&table=' + encodeURIComponent(table) + '&item_id=' + itemId + '&item_name=' + encodeURIComponent(itemName);
        } else {
            body = 'action=send_semester_otp&otp_type=' + encodeURIComponent(actionType) + '&target_id=' + itemId + '&item_name=' + encodeURIComponent(itemName);
        }

        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                statusDiv.className = 'otp-status success';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                setStatusIndicator('success', 'Sent', 'success');
                setTimeout(() => clearStatusIndicator(), 2500);
                startOTPTimer();
                updateVerifyButton();
                focusFirstBox();
            } else {
                statusDiv.className = 'otp-status error';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                setStatusIndicator('error', 'Failed', 'error');
                getOtpBoxes().forEach(box => box.disabled = true);
            }
        })
        .catch(() => {
            statusDiv.className = 'otp-status error';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Failed to send OTP.';
            setStatusIndicator('error', 'Error', 'error');
        });
    }

    function startOTPTimer() {
        if (otpTimerInterval) clearInterval(otpTimerInterval);
        otpCountdownSeconds = 120;
        updateOTPTimerDisplay();
        otpTimerInterval = setInterval(function() {
            otpCountdownSeconds--;
            updateOTPTimerDisplay();
            updateVerifyButton();
            if (otpCountdownSeconds <= 0) {
                clearInterval(otpTimerInterval);
                otpTimerInterval = null;
                const statusDiv = document.getElementById('otpStatus');
                statusDiv.className = 'otp-status error';
                statusDiv.style.display = 'block';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> OTP expired. Resend.';
                getOtpBoxes().forEach(box => box.disabled = true);
                document.getElementById('resendBtn').disabled = false;
                updateVerifyButton();
            }
        }, 1000);
    }

    function updateOTPTimerDisplay() {
        const m = Math.floor(otpCountdownSeconds / 60);
        const s = otpCountdownSeconds % 60;
        const el = document.getElementById('otpCountdown');
        el.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        el.style.color = otpCountdownSeconds < 30 ? '#e74c3c' : '#27ae60';
    }

    function verifyOTP() {
        if (isVerifying) return;
        const otpCode = getOTPValue();
        const statusDiv = document.getElementById('otpStatus');
        const boxes = getOtpBoxes();

        if (otpCode.length !== 6 || !/^\d{6}$/.test(otpCode)) {
            boxes.forEach(box => {
                if (!box.value) {
                    box.classList.add('error-shake');
                    setTimeout(() => box.classList.remove('error-shake'), 500);
                }
            });
            statusDiv.className = 'otp-status error';
            statusDiv.style.display = 'block';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Enter all 6 digits.';
            return;
        }

        isVerifying = true;
        const verifyBtn = document.getElementById('verifyBtn');
        verifyBtn.classList.remove('active');
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<span class="spinner"></span>';
        setStatusIndicator('loading', 'Verifying', '');
        boxes.forEach(box => box.classList.add('loading'));

        statusDiv.className = 'otp-status info';
        statusDiv.style.display = 'block';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

        const formData = new FormData();

        if (currentOtpContext.action_type === 'delete') {
            formData.append('action', 'verify_delete_otp');
            formData.append('otp_code', otpCode);
            formData.append('table', window.__deleteTarget.table);
            formData.append('item_id', window.__deleteTarget.item_id);
        } else {
            formData.append('action', 'verify_semester_action');
            formData.append('otp_code', otpCode);
            formData.append('semester_action', currentOtpContext.action_type);
            formData.append('target_semester_id', pendingAction.target_id);
            formData.append('reason', '');
        }

        fetch('', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            boxes.forEach(box => box.classList.remove('loading'));
            if (data.success) {
                statusDiv.className = 'otp-status success';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message + ' Refreshing...';
                setStatusIndicator('success', 'Verified!', 'success');
                boxes.forEach(box => {
                    box.style.borderColor = '#27ae60';
                    box.style.background = '#d4edda';
                });
                if (otpTimerInterval) { clearInterval(otpTimerInterval); otpTimerInterval = null; }
                setTimeout(() => window.location.reload(), 1400);
            } else {
                statusDiv.className = 'otp-status error';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                setStatusIndicator('error', 'Invalid', 'error');
                boxes.forEach(box => {
                    box.classList.add('error-shake');
                    setTimeout(() => box.classList.remove('error-shake'), 500);
                });
                verifyBtn.innerHTML = 'Verify';
                verifyBtn.disabled = false;
                updateVerifyButton();
                isVerifying = false;
            }
        })
        .catch(() => {
            boxes.forEach(box => box.classList.remove('loading'));
            statusDiv.className = 'otp-status error';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Verification failed.';
            setStatusIndicator('error', 'Error', 'error');
            verifyBtn.innerHTML = 'Verify';
            verifyBtn.disabled = false;
            updateVerifyButton();
            isVerifying = false;
        });
    }

    function resendOTP() {
        const statusDiv = document.getElementById('otpStatus');
        statusDiv.className = 'otp-status info';
        statusDiv.style.display = 'block';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending new OTP...';
        setStatusIndicator('loading', 'Resending', '');

        const boxes = getOtpBoxes();
        boxes.forEach(box => { box.value = ''; box.className = 'otp-box'; box.disabled = false; });
        document.getElementById('otpCodeHidden').value = '';
        document.getElementById('resendBtn').disabled = true;

        const verifyBtn = document.getElementById('verifyBtn');
        verifyBtn.classList.remove('active');
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = 'Verify';
        isVerifying = false;
        clearStatusIndicator();

        let body = '';
        if (currentOtpContext.action_type === 'delete') {
            body = 'action=resend_delete_otp&table=' + encodeURIComponent(window.__deleteTarget.table) + '&item_id=' + window.__deleteTarget.item_id + '&item_name=' + encodeURIComponent(currentOtpContext.item_name);
        } else {
            body = 'action=send_semester_otp&otp_type=' + encodeURIComponent(currentOtpContext.action_type) + '&target_id=' + pendingAction.target_id + '&item_name=' + encodeURIComponent(currentOtpContext.item_name);
        }

        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('resendBtn').disabled = false;
            if (data.success) {
                statusDiv.className = 'otp-status success';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                setStatusIndicator('success', 'Sent!', 'success');
                setTimeout(() => clearStatusIndicator(), 2000);
                startOTPTimer();
                updateVerifyButton();
                focusFirstBox();
            } else {
                statusDiv.className = 'otp-status error';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                setStatusIndicator('error', 'Failed', 'error');
                boxes.forEach(box => box.disabled = true);
            }
        })
        .catch(() => {
            document.getElementById('resendBtn').disabled = false;
            statusDiv.className = 'otp-status error';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Failed to resend.';
            setStatusIndicator('error', 'Error', 'error');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const boxes = getOtpBoxes();
        boxes.forEach((box, index) => {
            box.addEventListener('input', e => handleOtpInput(e, index));
            box.addEventListener('keydown', e => handleOtpKeydown(e, index));
            box.addEventListener('paste', e => handleOtpPaste(e));
            box.addEventListener('keypress', e => {
                if (!/^\d$/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete' && e.key !== 'Tab') {
                    e.preventDefault();
                }
            });
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') { closeOTPModal(); closeAdvModal(); closeViewModal(); }
        });
    });

    window.onclick = function(e) {
        const otpModal = document.getElementById('otpModal');
        const advModal = document.getElementById('advModal');
        const viewModal = document.getElementById('viewModal');
        if (e.target === otpModal) closeOTPModal();
        if (e.target === advModal) closeAdvModal();
        if (e.target === viewModal) closeViewModal();
    };
</script>

</body>
</html>