<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin'])) {
    header("Location: ../index.php");
    exit;
}

$admin = $_SESSION['admin'];
$adminSection = 'school_years';
$error = '';
$success = '';

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_school_years (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_year VARCHAR(20) NOT NULL,
    is_current TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ============================================
// ADMIN DELETE OTP HANDLERS (ADDED FOR DELETE FUNCTION)
// ============================================
if (isset($_POST['action']) && isset($_SESSION['admin']) && in_array($_POST['action'], ['send_delete_otp', 'resend_delete_otp', 'verify_delete_otp'])) {

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    require_once __DIR__ . '/../../includes/otp.php';

    $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='" . $conn->real_escape_string($admin) . "'")->fetch_assoc();
    $email = ($admin_data && !empty($admin_data['email'])) ? $admin_data['email'] : '';

    if (!$email) {
        echo json_encode(['success' => false, 'message' => 'Gmail not connected. Please connect Gmail from the dashboard first.']);
        exit;
    }

    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;

    if ($_POST['action'] === 'send_delete_otp') {
        $otp_code = generateOTP();
        saveOTP($conn, $email, $otp_code, 'delete', $item_id);
        sendOTPEmail($email, $otp_code, 'delete');
        echo json_encode(['success' => true, 'message' => 'OTP sent to your Gmail']);
        exit;
    }

    if ($_POST['action'] === 'resend_delete_otp') {
        $otp_code = generateOTP();
        saveOTP($conn, $email, $otp_code, 'delete', $item_id);
        sendOTPEmail($email, $otp_code, 'delete');
        echo json_encode(['success' => true, 'message' => 'OTP resent']);
        exit;
    }

    if ($_POST['action'] === 'verify_delete_otp') {
        $otp_code = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';
        $table    = isset($_POST['table'])    ? sanitize($_POST['table']) : '';

        $result = verifyOTP($conn, $email, $otp_code, 'delete');

        if (!$result || empty($result['valid'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP']);
            exit;
        }

        $allowed_tables = [
            'school_years' => ['table' => 'chmsu_school_years', 'column' => 'id'],
        ];

        if (!isset($allowed_tables[$table])) {
            echo json_encode(['success' => false, 'message' => 'Invalid table']);
            exit;
        }

        $real_table = $allowed_tables[$table]['table'];
        $id_column  = $allowed_tables[$table]['column'];

        $exists = $conn->query("SELECT $id_column FROM $real_table WHERE $id_column = $item_id");
        if (!$exists || $exists->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Item not found (maybe already deleted).']);
            exit;
        }

        if ($conn->query("DELETE FROM $real_table WHERE $id_column = $item_id")) {
            logActivity($conn, $admin, 'admin', "Deleted $table ID: $item_id (OTP verified)");
            echo json_encode(['success' => true, 'message' => 'Item deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $conn->error]);
        }
        exit;
    }
}

// ============================================
// FORWARD CHANGE HANDLER (no OTP)
// ============================================
if (isset($_POST['action']) && $_POST['action'] === 'apply_school_year_forward') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    $target_id = isset($_POST['target_school_year_id']) ? intval($_POST['target_school_year_id']) : 0;

    if ($target_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid target school year.']);
        exit;
    }

    $target = $conn->query("SELECT * FROM chmsu_school_years WHERE id = $target_id LIMIT 1")->fetch_assoc();
    if (!$target) {
        echo json_encode(['success' => false, 'message' => 'Target school year not found.']);
        exit;
    }

    $current = $conn->query("SELECT * FROM chmsu_school_years WHERE is_current = 1 LIMIT 1")->fetch_assoc();
    $current_id = $current ? intval($current['id']) : 0;

    if ($current_id === $target_id) {
        echo json_encode(['success' => false, 'message' => 'This school year is already the current one.']);
        exit;
    }

    // Forward change = target has HIGHER id than current
    if ($current_id > 0 && $target_id < $current_id) {
        echo json_encode(['success' => false, 'message' => 'Backward change requires OTP.']);
        exit;
    }

    // Apply forward change
    $conn->query("UPDATE chmsu_school_years SET is_current = 0");
    $conn->query("UPDATE chmsu_school_years SET is_current = 1 WHERE id = $target_id");

    logActivity($conn, $admin, 'admin',
        "Set current school year (forward): " . $target['school_year']
        . " | From: " . ($current ? $current['school_year'] : 'none'));

    echo json_encode(['success' => true, 'message' => 'Current school year updated successfully!']);
    exit;
}

// ============================================
// BACKWARD CHANGE OTP HANDLERS (requires OTP)
// ============================================
if (isset($_POST['action']) && in_array($_POST['action'], ['send_school_year_otp', 'verify_school_year_action'])) {

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    require_once __DIR__ . '/../../includes/otp.php';

    $admin_data = $conn->query("SELECT email FROM chmsu_admin_users WHERE username='" . $conn->real_escape_string($admin) . "'")->fetch_assoc();
    $email = ($admin_data && !empty($admin_data['email'])) ? $admin_data['email'] : '';

    if (!$email) {
        echo json_encode(['success' => false, 'message' => 'Gmail not connected. Please connect Gmail from the dashboard first.']);
        exit;
    }

    // ---- SEND OTP ----
    if ($_POST['action'] === 'send_school_year_otp') {
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;
        $otp_code = generateOTP();
        saveOTP($conn, $email, $otp_code, 'set_school_year', $target_id);
        sendOTPEmail($email, $otp_code, 'set_school_year');
        echo json_encode(['success' => true, 'message' => 'OTP sent to your Gmail']);
        exit;
    }

    // ---- VERIFY + APPLY ----
    if ($_POST['action'] === 'verify_school_year_action') {
        $otp_code  = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';
        $target_id = isset($_POST['target_school_year_id']) ? intval($_POST['target_school_year_id']) : 0;

        if ($target_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid target school year.']);
            exit;
        }

        $result = verifyOTP($conn, $email, $otp_code, 'set_school_year');
        if (!$result || empty($result['valid'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP']);
            exit;
        }

        $target = $conn->query("SELECT * FROM chmsu_school_years WHERE id = $target_id LIMIT 1")->fetch_assoc();
        if (!$target) {
            echo json_encode(['success' => false, 'message' => 'Target school year not found.']);
            exit;
        }

        $current = $conn->query("SELECT * FROM chmsu_school_years WHERE is_current = 1 LIMIT 1")->fetch_assoc();
        $current_id = $current ? intval($current['id']) : 0;

        if ($current_id === $target_id) {
            echo json_encode(['success' => false, 'message' => 'This school year is already the current one.']);
            exit;
        }

        // Apply change
        $conn->query("UPDATE chmsu_school_years SET is_current = 0");
        $conn->query("UPDATE chmsu_school_years SET is_current = 1 WHERE id = $target_id");

        logActivity($conn, $admin, 'admin',
            "Set current school year (backward, OTP verified): " . $target['school_year']
            . " | From: " . ($current ? $current['school_year'] : 'none'));

        echo json_encode(['success' => true, 'message' => 'Current school year updated successfully!']);
        exit;
    }
}

// ============================================
// HANDLE ADD SCHOOL YEAR
// ============================================
if(isset($_POST['action'])) {
    if($_POST['action'] == 'add') {
        $school_year = sanitize($_POST['school_year']);

        $check = $conn->query("SELECT * FROM chmsu_school_years WHERE school_year='$school_year'");
        if($check && $check->num_rows > 0) {
            $error = "School year already exists!";
        } else {
            $conn->query("INSERT INTO chmsu_school_years (school_year) VALUES ('$school_year')");
            logActivity($conn, $admin, 'admin', "Added school year: $school_year");
            $success = "School year added successfully!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Manage School Years</title>
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
        input, textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }
        input:focus, textarea:focus { outline: none; border-color: #1b4d3e; }

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

        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .item-list {
            list-style: none;
            max-height: 400px;
            overflow-y: auto;
        }
        .item-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            gap: 15px;
            flex-wrap: wrap;
        }
        .item-list li:hover { background: #f5f5f5; }

        .badge-current {
            background: #27ae60;
            color: white;
            padding: 2px 10px;
            border-radius: 3px;
            font-size: 10px;
            display: inline-block;
        }
        .badge-inactive {
            background: #95a5a6;
            color: white;
            padding: 2px 10px;
            border-radius: 3px;
            font-size: 10px;
            display: inline-block;
        }

        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }

        /* ============================================ */
        /* LAYER 1 — WARNING / CONSEQUENCES MODAL       */
        /* ============================================ */
        .adv-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 99998;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        .adv-modal-overlay.active { display: flex; }
        .adv-modal {
            background: #fff;
            max-width: 480px;
            width: 92%;
            padding: 22px 26px;
            border-radius: 10px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.2);
            font-family: 'Times New Roman', Times, serif;
            max-height: 90vh;
            overflow-y: auto;
        }
        .adv-modal h3 {
            color: #1b4d3e;
            margin-bottom: 14px;
            font-weight: 600;
            font-size: 17px;
            text-align: center;
        }
        .adv-modal .flow-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .adv-modal .flow-box {
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 13px;
            text-align: center;
            min-width: 140px;
            max-width: 180px;
            word-wrap: break-word;
        }
        .adv-modal .flow-from {
            background: #fdecea;
            border: 1px solid #f5c6cb;
            color: #c0392b;
        }
        .adv-modal .flow-to {
            background: #d4efdf;
            border: 1px solid #c3e6cb;
            color: #1e8449;
        }
        .adv-modal .flow-arrow { color: #888; font-size: 20px; }
        .adv-modal .warning-list {
            background: #fff8e1;
            border-left: 4px solid #f39c12;
            padding: 12px 16px;
            margin-bottom: 16px;
            border-radius: 4px;
            font-size: 12px;
            line-height: 1.6;
        }
        .adv-modal .warning-list strong { color: #b9770e; display: block; margin-bottom: 6px; }
        .adv-modal .warning-list ul { padding-left: 20px; }
        .adv-modal .warning-list li { margin-bottom: 4px; }
        .adv-modal .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .adv-modal .btn-cancel-adv {
            padding: 8px 20px;
            background: #f0f0f0;
            color: #555;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }
        .adv-modal .btn-continue {
            padding: 8px 20px;
            background: #1b4d3e;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
            transition: background 0.3s;
        }
        .adv-modal .btn-continue:hover { background: #2d6a4f; }

        /* ============================================ */
        /* LAYER 2 — "ARE YOU SURE?" (FORWARD)          */
        /* ============================================ */
        .sure-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 99998;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        .sure-modal-overlay.active { display: flex; }
        .sure-modal {
            background: #fff;
            max-width: 420px;
            width: 92%;
            padding: 24px;
            border-radius: 10px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.2);
            font-family: 'Times New Roman', Times, serif;
            text-align: center;
        }
        .sure-modal .sure-icon {
            width: 60px; height: 60px;
            border-radius: 50%;
            background: #e8f5e9;
            color: #27ae60;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            margin: 0 auto 14px auto;
        }
        .sure-modal h3 {
            color: #1b4d3e;
            margin-bottom: 10px;
            font-weight: 600;
            font-size: 17px;
        }
        .sure-modal p {
            font-size: 13px;
            color: #555;
            margin-bottom: 6px;
            line-height: 1.5;
        }
        .sure-modal .sure-item {
            background: #f5f5f5;
            padding: 10px 14px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 600;
            color: #1b4d3e;
            margin: 12px 0 18px 0;
            border: 1px solid #e8e8e8;
            word-break: break-word;
        }
        .sure-modal .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .sure-modal .btn-cancel-sure {
            padding: 8px 22px;
            background: #f0f0f0;
            color: #555;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }
        .sure-modal .btn-yes {
            padding: 8px 22px;
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
            transition: background 0.3s;
        }
        .sure-modal .btn-yes:hover { background: #219a52; }
        .sure-modal .btn-yes:disabled { background: #ccc; cursor: not-allowed; }

        /* ============================================ */
        /* CONFIRM DELETE MODAL                         */
        /* ============================================ */
        .confirm-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 99998;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        .confirm-modal-overlay.active { display: flex; }
        .confirm-modal {
            background: #fff;
            max-width: 400px;
            width: 92%;
            padding: 22px 24px 18px 24px;
            border-radius: 10px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.2);
            font-family: 'Times New Roman', Times, serif;
            text-align: center;
        }
        .confirm-modal-icon {
            width: 56px; height: 56px;
            border-radius: 50%;
            background: #fdecea;
            color: #e74c3c;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px auto;
            font-size: 26px;
        }
        .confirm-modal h3 { color: #1b4d3e; margin-bottom: 8px; font-weight: 600; font-size: 17px; }
        .confirm-modal p { font-size: 13px; color: #666; margin-bottom: 6px; line-height: 1.5; }
        .confirm-modal .confirm-item-name {
            background: #f5f5f5;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            color: #1b4d3e;
            margin: 10px 0 18px 0;
            border: 1px solid #e8e8e8;
            word-break: break-word;
        }
        .confirm-modal .confirm-warning {
            font-size: 11px;
            color: #e74c3c;
            font-style: italic;
            margin-bottom: 16px;
        }
        .confirm-modal-buttons { display: flex; gap: 10px; justify-content: center; }
        .btn-confirm-cancel {
            padding: 8px 22px;
            background: #f0f0f0;
            color: #555;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }
        .btn-confirm-cancel:hover { background: #e0e0e0; }
        .btn-confirm-ok {
            padding: 8px 22px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
            transition: background 0.3s;
        }
        .btn-confirm-ok:hover { background: #c0392b; }

        /* ============================================ */
        /* LAYER 2 (BACKWARD) — OTP MODAL               */
        /* ============================================ */
        .otp-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 99999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        .otp-modal-overlay.active { display: flex; }
        .otp-modal {
            background: #fff;
            max-width: 420px;
            width: 92%;
            padding: 16px 20px 14px 20px;
            border-radius: 10px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.2);
            position: relative;
            animation: modalFadeIn 0.25s ease;
            font-family: 'Times New Roman', Times, serif;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-15px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .otp-modal .close-btn {
            position: absolute;
            top: 6px; right: 10px;
            cursor: pointer;
            font-size: 18px;
            color: #aaa;
            background: none;
            border: none;
            transition: color 0.2s;
            line-height: 1;
        }
        .otp-modal .close-btn:hover { color: #333; }
        .otp-modal h3 {
            color: #1b4d3e;
            margin-bottom: 2px;
            font-weight: 600;
            font-size: 15px;
            text-align: center;
        }
        .otp-modal .modal-subtitle {
            font-size: 11px;
            color: #888;
            margin-bottom: 10px;
            text-align: center;
            line-height: 1.3;
        }
        .otp-modal .otp-status {
            padding: 5px 8px;
            margin-bottom: 8px;
            border-radius: 4px;
            display: none;
            font-size: 11px;
            text-align: center;
        }
        .otp-modal .otp-status.success { background: #d4edda; color: #155724; display: block; border-left: 3px solid #27ae60; }
        .otp-modal .otp-status.error { background: #f8d7da; color: #721c24; display: block; border-left: 3px solid #e74c3c; }
        .otp-modal .otp-status.info { background: #f0f8ff; color: #1b4d3e; display: block; border-left: 3px solid #1b4d3e; }
        .otp-modal .otp-item-label {
            display: block;
            font-size: 10px;
            color: #666;
            margin-bottom: 2px;
            text-align: center;
        }
        .otp-modal .otp-item-name {
            background: #f5f5f5;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            color: #1b4d3e;
            margin-bottom: 10px;
            border: 1px solid #e8e8e8;
            text-align: center;
            word-wrap: break-word;
        }
        .otp-boxes-container {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 10px;
        }
        .otp-box {
            width: 38px;
            height: 40px;
            border: 2px solid #d0d0d0;
            border-radius: 6px;
            text-align: center;
            font-size: 18px;
            font-weight: 600;
            font-family: 'Times New Roman', Times, serif;
            background: #fafafa;
            transition: all 0.2s;
            color: #1b4d3e;
            outline: none;
        }
        .otp-box:focus { border-color: #1b4d3e; background: #fff; box-shadow: 0 0 0 2px rgba(27,77,62,0.12); }
        .otp-box.filled { border-color: #1b4d3e; background: #f0f7f5; }
        .otp-box.has-value { border-color: #1b4d3e; background: #eaf3ef; }
        .otp-box.loading {
            border-color: #f1c40f;
            background: #fef9e7;
            animation: pulse-border 1s infinite;
        }
        @keyframes pulse-border {
            0% { border-color: #f1c40f; }
            50% { border-color: #d4a800; }
            100% { border-color: #f1c40f; }
        }
        .otp-box.error-shake {
            animation: shake 0.35s ease;
            border-color: #e74c3c;
            background: #fde8e8;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }
        .otp-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 8px;
        }
        .otp-timer { font-size: 11px; color: #e74c3c; }
        .otp-timer .countdown { font-weight: 700; font-size: 13px; }
        .btn-resend {
            background: transparent;
            color: #1b4d3e;
            border: 1px solid #1b4d3e;
            padding: 2px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 10px;
            transition: all 0.3s;
        }
        .btn-resend:hover { background: #1b4d3e; color: white; }
        .btn-resend:disabled { opacity: 0.4; cursor: not-allowed; }
        .otp-verify-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .btn-verify {
            padding: 5px 18px;
            background: #1b4d3e;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
            opacity: 0.5;
            pointer-events: none;
            font-family: 'Times New Roman', Times, serif;
        }
        .btn-verify.active { opacity: 1; pointer-events: auto; }
        .btn-verify.active:hover { background: #2d6a4f; }
        .btn-verify:disabled { opacity: 0.5; pointer-events: none; }
        .btn-verify .spinner {
            display: inline-block;
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .otp-status-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 55px;
        }
        .otp-status-indicator .circle {
            width: 18px; height: 18px;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            color: white;
            font-weight: 700;
            flex-shrink: 0;
        }
        .otp-status-indicator .circle.show { display: flex; }
        .otp-status-indicator .circle.success { background: #27ae60; animation: popIn 0.3s ease; }
        .otp-status-indicator .circle.error { background: #e74c3c; animation: popIn 0.3s ease; }
        .otp-status-indicator .circle.loading {
            background: #f1c40f;
            animation: spin 0.8s linear infinite;
            border: 2px solid transparent;
            border-top-color: #fff;
        }
        @keyframes popIn {
            0% { transform: scale(0); }
            70% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        .otp-status-indicator .status-label { font-size: 11px; color: #666; display: none; }
        .otp-status-indicator .status-label.show { display: inline; }
        .otp-status-indicator .status-label.success { color: #27ae60; }
        .otp-status-indicator .status-label.error { color: #e74c3c; }
        .modal-footer {
            margin-top: 6px;
            padding-top: 8px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .btn-cancel {
            background: #f0f0f0;
            color: #555;
            border: none;
            padding: 3px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            transition: background 0.3s;
        }
        .btn-cancel:hover { background: #e0e0e0; }

        /* Dark mode */
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

        body.dark-mode .sure-modal { background: #1a1a1a; color: #fff; }
        body.dark-mode .sure-modal h3 { color: #f1c40f; }
        body.dark-mode .sure-modal p { color: #bbb; }
        body.dark-mode .sure-modal .sure-item { background: #2c2c2c; color: #f1c40f; border-color: #444; }
        body.dark-mode .sure-modal .btn-cancel-sure { background: #333; color: #ccc; }
        body.dark-mode .sure-modal .sure-icon { background: #1a3a2a; color: #f1c40f; }

        body.dark-mode .confirm-modal { background: #1a1a1a; color: #fff; }
        body.dark-mode .confirm-modal h3 { color: #f1c40f; }
        body.dark-mode .confirm-modal p { color: #bbb; }
        body.dark-mode .confirm-modal .confirm-item-name { background: #2c2c2c; color: #f1c40f; border-color: #444; }
        body.dark-mode .btn-confirm-cancel { background: #333; color: #ccc; }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .item-list li { border-bottom-color: #333; color: #fff; }
        body.dark-mode .item-list li:hover { background: #2c2c2c; }
        body.dark-mode input, body.dark-mode textarea { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .badge-current { background: #1a7a3a; }
        body.dark-mode .badge-inactive { background: #555; }

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
            <h2>Manage School Years</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="content-card">
            <div class="content-card-header">Add New School Year</div>
            <div class="content-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="filter-row">
                        <div class="form-group" style="flex:1;">
                            <label>School Year</label>
                            <input type="text" name="school_year" placeholder="2024-2025" required>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">Add School Year</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="content-card">
            <div class="content-card-header">School Year List</div>
            <div class="content-card-body">
                <ul class="item-list">
                    <?php
                    $school_years = $conn->query("SELECT * FROM chmsu_school_years ORDER BY id DESC");
                    if($school_years && $school_years->num_rows > 0):
                        while($sy = $school_years->fetch_assoc()):
                    ?>
                    <li>
                        <span>
                            <strong><?php echo htmlspecialchars($sy['school_year']); ?></strong>
                            <?php if($sy['is_current']): ?>
                                <span class="badge-current"><i class="fas fa-check-circle"></i> Current</span>
                            <?php endif; ?>
                        </span>
                        <span style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <?php if(!$sy['is_current']): ?>
                                <button type="button" class="btn btn-success btn-sm"
                                    onclick="openWarningLayer(<?php echo $sy['id']; ?>, '<?php echo addslashes($sy['school_year']); ?>')">
                                    <i class="fas fa-check"></i> Set Current
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-danger btn-sm"
                                onclick="openConfirmDelete('school_years', <?php echo $sy['id']; ?>, '<?php echo addslashes($sy['school_year']); ?>')">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </span>
                    </li>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <li style="text-align:center;">No school years found. Add your first school year above.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- LAYER 1 — WARNING / CONSEQUENCES MODAL       -->
<!-- ============================================ -->
<div class="adv-modal-overlay" id="warnModal">
    <div class="adv-modal">
        <h3>Confirm School Year Change</h3>

        <div class="flow-row">
            <div class="flow-box flow-from" id="warnFromBox">—</div>
            <div class="flow-arrow"><i class="fas fa-arrow-right"></i></div>
            <div class="flow-box flow-to" id="warnToBox">—</div>
        </div>

        <div class="warning-list">
            <strong><i class="fas fa-exclamation-triangle"></i> What will happen:</strong>
            <ul>
                <li>The current school year will be <strong>replaced</strong>.</li>
                <li>All reports and semesters will tag against the new school year.</li>
                <li>Existing clearance records remain intact.</li>
                <li id="warnOtpNote" style="display:none;">Because you are moving <strong>backward</strong>, an <strong>OTP verification</strong> will be required.</li>
            </ul>
        </div>

        <div class="modal-buttons">
            <button type="button" class="btn-cancel-adv" onclick="closeWarnModal()">Cancel</button>
            <button type="button" class="btn-continue" id="warnContinueBtn" onclick="proceedFromWarning()">
                Continue
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- LAYER 2 (FORWARD) — ARE YOU SURE? MODAL      -->
<!-- ============================================ -->
<div class="sure-modal-overlay" id="sureModal">
    <div class="sure-modal">
        <div class="sure-icon"><i class="fas fa-check-circle"></i></div>
        <h3>Are you sure?</h3>
        <p>You are about to set the following as the current school year:</p>
        <div class="sure-item" id="sureItemName">-</div>
        <p style="font-size:12px; color:#888;">This will end the current school year.</p>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel-sure" onclick="closeSureModal()">Cancel</button>
            <button type="button" class="btn-yes" id="sureYesBtn" onclick="applyForwardChange()">Yes, Set It</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- CONFIRM DELETE MODAL                         -->
<!-- ============================================ -->
<div class="confirm-modal-overlay" id="confirmModal">
    <div class="confirm-modal">
        <div class="confirm-modal-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h3>Are you sure?</h3>
        <p>You are about to delete the following item:</p>
        <div class="confirm-item-name" id="confirmItemName">-</div>
        <p class="confirm-warning">
            <i class="fas fa-info-circle"></i> This action cannot be undone. You will need to verify with OTP to proceed.
        </p>
        <div class="confirm-modal-buttons">
            <button class="btn-confirm-cancel" onclick="closeConfirmDelete()">Cancel</button>
            <button class="btn-confirm-ok" onclick="proceedToOTP()">OK, Proceed</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- LAYER 2 (BACKWARD) — OTP MODAL               -->
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
    // STATE
    // ============================================
    let pendingYear = {
        target_id: 0,
        target_name: '',
        from_id: 0,
        from_name: '',
        direction: 'forward'   // 'forward' or 'backward'
    };

    // Read current school year row from the DOM
    function getCurrentYearFromDom() {
        let from_id = 0, from_name = 'None';
        document.querySelectorAll('.item-list li').forEach(function (li) {
            if (li.querySelector('.badge-current')) {
                const strong = li.querySelector('strong');
                if (strong) from_name = strong.textContent.trim();
                const btn = li.querySelector('button[onclick*="openWarningLayer"]');
                // Fallback: try to find target id in the delete button's onclick
                const del = li.querySelector('button[onclick*="openConfirmDelete"]');
                if (del) {
                    const m = del.getAttribute('onclick').match(/,\s*(\d+)\s*,/);
                    if (m) from_id = parseInt(m[1], 10);
                }
            }
        });
        return { from_id: from_id, from_name: from_name };
    }

    // ============================================
    // LAYER 1 — WARNING MODAL
    // ============================================
    function openWarningLayer(targetId, targetName) {
        const cur = getCurrentYearFromDom();
        // Determine direction by numeric id comparison:
        // forward = higher id (newer year), backward = lower id (older year)
        let direction = 'forward';
        if (cur.from_id > 0 && targetId < cur.from_id) {
            direction = 'backward';
        }

        pendingYear = {
            target_id: targetId,
            target_name: targetName,
            from_id: cur.from_id,
            from_name: cur.from_name,
            direction: direction
        };

        document.getElementById('warnFromBox').textContent = cur.from_name;
        document.getElementById('warnToBox').textContent = targetName;

        const otpNote = document.getElementById('warnOtpNote');
        if (direction === 'backward') {
            otpNote.style.display = '';
        } else {
            otpNote.style.display = 'none';
        }

        document.getElementById('warnModal').classList.add('active');
    }

    function closeWarnModal() {
        document.getElementById('warnModal').classList.remove('active');
        pendingYear = { target_id: 0, target_name: '', from_id: 0, from_name: '', direction: 'forward' };
    }

    function proceedFromWarning() {
        document.getElementById('warnModal').classList.remove('active');

        if (pendingYear.direction === 'backward') {
            // Layer 2 = OTP
            setTimeout(function () {
                openOTPForSchoolYear(pendingYear.target_id, pendingYear.target_name);
            }, 150);
        } else {
            // Layer 2 = "Are you sure?"
            setTimeout(function () {
                document.getElementById('sureItemName').textContent = pendingYear.target_name;
                document.getElementById('sureModal').classList.add('active');
            }, 150);
        }
    }

    // ============================================
    // LAYER 2 (FORWARD) — "ARE YOU SURE?"
    // ============================================
    function closeSureModal() {
        document.getElementById('sureModal').classList.remove('active');
        pendingYear = { target_id: 0, target_name: '', from_id: 0, from_name: '', direction: 'forward' };
    }

    function applyForwardChange() {
        const btn = document.getElementById('sureYesBtn');
        btn.disabled = true;
        btn.textContent = 'Applying...';

        const formData = new FormData();
        formData.append('action', 'apply_school_year_forward');
        formData.append('target_school_year_id', pendingYear.target_id);

        fetch('', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to apply change.');
                    btn.disabled = false;
                    btn.textContent = 'Yes, Set It';
                }
            })
            .catch(() => {
                alert('Server error.');
                btn.disabled = false;
                btn.textContent = 'Yes, Set It';
            });
    }

    // ============================================
    // LAYER 2 (BACKWARD) — OTP
    // ============================================
    function openOTPForSchoolYear(targetId, targetName) {
        currentOtpContext = { action_type: 'set_school_year', item_name: targetName };
        document.getElementById('otpTitle').textContent = 'Verify Backward Change';
        document.getElementById('otpSubtitle').textContent = 'Enter the OTP to confirm rolling back to an older school year.';
        document.getElementById('otpItemName').textContent =
            pendingYear.from_name + '  →  ' + targetName;

        resetOtpModal();
        document.getElementById('otpModal').classList.add('active');
        focusFirstBox();
        sendOTP('set_school_year', '', targetId, targetName);
    }

    // ============================================
    // CONFIRM DELETE
    // ============================================
    let pendingDeleteData = { table: '', item_id: 0, item_name: '' };

    function openConfirmDelete(table, itemId, itemName) {
        pendingDeleteData = { table: table, item_id: itemId, item_name: itemName };
        document.getElementById('confirmItemName').textContent = itemName;
        document.getElementById('confirmModal').classList.add('active');
    }
    function closeConfirmDelete() {
        document.getElementById('confirmModal').classList.remove('active');
        pendingDeleteData = { table: '', item_id: 0, item_name: '' };
    }
    function proceedToOTP() {
        document.getElementById('confirmModal').classList.remove('active');
        const table = pendingDeleteData.table;
        const itemId = pendingDeleteData.item_id;
        const itemName = pendingDeleteData.item_name;
        setTimeout(function () { openOTPModal(table, itemId, itemName); }, 150);
    }

    // ============================================
    // OTP MODAL (shared — handles delete + school year backward)
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
    function getOTPValue() { return getOtpBoxes().map(b => b.value).join(''); }
    function isOTPComplete() { return getOtpBoxes().every(b => b.value.length === 1 && /^\d$/.test(b.value)); }
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
    function focusFirstBox() { setTimeout(() => getOtpBoxes()[0].focus(), 350); }

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

    function resetOtpModal() {
        getOtpBoxes().forEach(box => {
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
        pendingDeleteData = { table: '', item_id: 0, item_name: '' };
        window.__deleteTarget = null;
    }

    function sendOTP(actionType, table, itemId, itemName) {
        const statusDiv = document.getElementById('otpStatus');
        statusDiv.className = 'otp-status info';
        statusDiv.style.display = 'block';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending OTP...';
        setStatusIndicator('loading', 'Sending', '');

        let body;
        if (actionType === 'delete') {
            body = 'action=send_delete_otp&table=' + encodeURIComponent(table) + '&item_id=' + itemId + '&item_name=' + encodeURIComponent(itemName);
        } else {
            body = 'action=send_school_year_otp&target_id=' + itemId + '&item_name=' + encodeURIComponent(itemName);
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
        otpTimerInterval = setInterval(function () {
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
            formData.append('action', 'verify_school_year_action');
            formData.append('otp_code', otpCode);
            formData.append('target_school_year_id', pendingYear.target_id);
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

        let body;
        if (currentOtpContext.action_type === 'delete') {
            body = 'action=resend_delete_otp&table=' + encodeURIComponent(window.__deleteTarget.table) + '&item_id=' + window.__deleteTarget.item_id + '&item_name=' + encodeURIComponent(currentOtpContext.item_name);
        } else {
            body = 'action=send_school_year_otp&target_id=' + pendingYear.target_id + '&item_name=' + encodeURIComponent(currentOtpContext.item_name);
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

    document.addEventListener('DOMContentLoaded', function () {
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
            if (e.key === 'Escape') {
                closeOTPModal();
                closeConfirmDelete();
                closeWarnModal();
                closeSureModal();
            }
        });
    });

    window.onclick = function (e) {
        if (e.target === document.getElementById('otpModal')) closeOTPModal();
        if (e.target === document.getElementById('confirmModal')) closeConfirmDelete();
        if (e.target === document.getElementById('warnModal')) closeWarnModal();
        if (e.target === document.getElementById('sureModal')) closeSureModal();
    };
</script>

</body>
</html>