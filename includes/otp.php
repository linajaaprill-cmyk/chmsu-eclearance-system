<?php
// includes/otp.php

require_once __DIR__ . '/email.php';

// ============================================
// GENERATE OTP
// ============================================
function generateOTP($length = 6) {
    return str_pad(random_int(0, 999999), $length, '0', STR_PAD_LEFT);
}

// ============================================
// SEND OTP EMAIL - HARDCODED CREDENTIALS (WORKS)
// ============================================
function sendOTPEmail($admin_email, $otp_code, $action = 'delete') {
    $subject = "🔐 OTP Verification - CHMSU E-Clearance System";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: 'Times New Roman', Times, serif; color: #333; }
            .container { max-width: 500px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; }
            .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
            .content { padding: 20px; text-align: center; }
            .otp-box { font-size: 32px; font-weight: bold; color: #1b4d3e; background: #f5f5f5; padding: 15px; margin: 10px 0; letter-spacing: 5px; border: 2px solid #1b4d3e; border-radius: 4px; }
            .footer { text-align: center; font-size: 11px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; }
            .warning { color: #e74c3c; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>🔐 OTP Verification</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>Admin</strong>,</p>
                <p>You requested to perform a <strong>$action</strong> action in the CHMSU E-Clearance System.</p>
                <p>Enter the following One-Time Password (OTP):</p>
                <div class='otp-box'>$otp_code</div>
                <p>This OTP will expire in <strong>10 minutes</strong>.</p>
                <p class='warning'>⚠️ If you did not request this, please ignore this email.</p>
            </div>
            <div class='footer'>
                <p>CHMSU E-Clearance System &bull; This is an automated notification.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // CREDENTIALS FROM .env
    return sendEmail(
        $admin_email,                // 1: to_email
        'Admin',                     // 2: to_name
        $subject,                    // 3: subject
        $body,                       // 4: body
        true,                        // 5: is_html
        getenv('MAIL_USERNAME'),     // 6: from_email
        getenv('MAIL_PASSWORD')      // 7: from_password
    );
}

// ============================================
// SAVE OTP TO DATABASE - Set 10 minutes expiry
// ============================================
function saveOTP($conn, $admin_email, $otp_code, $action_type = 'delete', $item_id = null) {
    // Delete old unused OTPs for this admin
    $conn->query("DELETE FROM chmsu_otp WHERE admin_email='$admin_email' AND is_used=0");
    
    // Insert new OTP - 10 minutes expiry
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $stmt = $conn->prepare("INSERT INTO chmsu_otp (admin_email, otp_code, expires_at, action_type, item_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $admin_email, $otp_code, $expires_at, $action_type, $item_id);
    $stmt->execute();
    $stmt->close();
}

// ============================================
// VERIFY OTP - FIXED: Uses PHP time comparison, not MySQL NOW()
// ============================================
function verifyOTP($conn, $admin_email, $otp_code, $action_type = 'delete') {
    // Find the OTP without expiration check in MySQL
    $stmt = $conn->prepare("SELECT * FROM chmsu_otp 
                            WHERE admin_email = ? 
                            AND otp_code = ? 
                            AND action_type = ? 
                            AND is_used = 0 
                            ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("sss", $admin_email, $otp_code, $action_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return ['valid' => false];
    }
    
    $otp = $result->fetch_assoc();
    
    // Check if expired using PHP (not MySQL)
    $now = new DateTime();
    $expires = new DateTime($otp['expires_at']);
    
    if ($now > $expires) {
        return ['valid' => false];
    }
    
    // Mark as used
    $conn->query("UPDATE chmsu_otp SET is_used=1 WHERE id=" . $otp['id']);
    return ['valid' => true, 'item_id' => $otp['item_id']];
}

// ============================================
// RESEND OTP
// ============================================
function resendOTP($conn, $admin_email, $action_type = 'delete', $item_id = null) {
    $otp_code = generateOTP();
    saveOTP($conn, $admin_email, $otp_code, $action_type, $item_id);
    sendOTPEmail($admin_email, $otp_code, $action_type);
    return $otp_code;
}