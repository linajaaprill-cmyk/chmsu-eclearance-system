<?php
// modules/office/change_password.php
// Office Change Password with OTP Verification

if (!isset($_SESSION['office'])) { exit; }

require_once __DIR__ . '/../../includes/otp.php';

$office = $_SESSION['office'];

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// ============================================
// STEP 1: Verify old password, stage new password, send OTP
// ============================================
if ($action === 'request_password_change') {
    $old_pass     = $_POST['old_password']     ?? '';
    $new_pass     = $_POST['new_password']     ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (empty($old_pass) || empty($new_pass) || empty($confirm_pass)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }
    if (strlen($new_pass) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
        exit;
    }
    if (!preg_match('/[A-Za-z]/', $new_pass) || !preg_match('/[0-9]/', $new_pass)) {
        echo json_encode(['success' => false, 'message' => 'New password must contain both letters and numbers.']);
        exit;
    }
    if ($new_pass !== $confirm_pass) {
        echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
        exit;
    }
    if ($old_pass === $new_pass) {
        echo json_encode(['success' => false, 'message' => 'New password must be different from old password.']);
        exit;
    }

    // Fetch office record
    $office_esc = $conn->real_escape_string($office);
    $role = $conn->query("SELECT chmsu_role, chmsu_password, email FROM chmsu_auth_roles WHERE chmsu_role='$office_esc'")->fetch_assoc();
    if (!$role) {
        echo json_encode(['success' => false, 'message' => 'Office account not found.']);
        exit;
    }

    // Verify old password
    if (!password_verify($old_pass, $role['chmsu_password'])) {
        echo json_encode(['success' => false, 'message' => 'Old password is incorrect.']);
        exit;
    }

    // Must have Gmail connected
    if (empty($role['email'])) {
        echo json_encode(['success' => false, 'message' => 'Gmail is not connected. Please connect your Gmail first from the dashboard.']);
        exit;
    }

    // Stage new password (hashed) in session — only saved after OTP success
    $_SESSION['pwd_change_stage'] = [
        'office'     => $office,
        'new_hash'   => password_hash($new_pass, PASSWORD_DEFAULT),
        'email'      => $role['email'],
        'expires_at' => time() + 600, // 10 minutes
    ];

    // Generate + send OTP
    $otp_code = generateOTP();
    saveOTP($conn, $role['email'], $otp_code, 'password_change');
    sendOTPEmail($role['email'], $otp_code, 'password_change');

    // Mask email for display
    $email = $role['email'];
    $parts = explode('@', $email);
    $name  = $parts[0];
    $domain = $parts[1] ?? '';
    $masked = substr($name, 0, 2) . str_repeat('•', max(0, strlen($name) - 2)) . '@' . $domain;

    echo json_encode([
        'success' => true,
        'message' => 'OTP sent to your Gmail.',
        'masked_email' => $masked,
    ]);
    exit;
}

// ============================================
// STEP 2: Resend OTP
// ============================================
if ($action === 'resend_password_otp') {
    if (empty($_SESSION['pwd_change_stage'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
        exit;
    }
    $stage = $_SESSION['pwd_change_stage'];
    if (time() > $stage['expires_at']) {
        unset($_SESSION['pwd_change_stage']);
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
        exit;
    }

    $otp_code = generateOTP();
    saveOTP($conn, $stage['email'], $otp_code, 'password_change');
    sendOTPEmail($stage['email'], $otp_code, 'password_change');

    echo json_encode(['success' => true, 'message' => 'OTP resent to your Gmail.']);
    exit;
}

// ============================================
// STEP 3: Verify OTP and finalize password change
// ============================================
if ($action === 'verify_password_otp') {
    if (empty($_SESSION['pwd_change_stage'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
        exit;
    }
    $stage = $_SESSION['pwd_change_stage'];
    if (time() > $stage['expires_at']) {
        unset($_SESSION['pwd_change_stage']);
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
        exit;
    }

    $otp_code = trim($_POST['otp_code'] ?? '');
    if (empty($otp_code)) {
        echo json_encode(['success' => false, 'message' => 'Please enter the OTP.']);
        exit;
    }

    $result = verifyOTP($conn, $stage['email'], $otp_code, 'password_change');
    if (!$result || empty($result['valid'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP.']);
        exit;
    }

    // Apply the password change
    $office_esc = $conn->real_escape_string($stage['office']);
    $new_hash   = $conn->real_escape_string($stage['new_hash']);

    $upd = $conn->query("UPDATE chmsu_auth_roles SET chmsu_password='$new_hash' WHERE chmsu_role='$office_esc'");
    if (!$upd) {
        echo json_encode(['success' => false, 'message' => 'Failed to update password: ' . $conn->error]);
        exit;
    }

    logActivity($conn, $stage['office'], 'office', 'Changed password via OTP verification');
    unset($_SESSION['pwd_change_stage']);

    echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
    exit;
}
?>