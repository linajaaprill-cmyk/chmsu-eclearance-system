<?php
// includes/email.php

// ============================================
// REQUIRE PHPMailer
// ============================================
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ============================================
// CONFIGURATION
// ============================================
define('SMTP_HOST', getenv('MAIL_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', getenv('MAIL_PORT') ?: 587);
define('SMTP_USER', getenv('MAIL_USERNAME'));
define('SMTP_PASS', getenv('MAIL_PASSWORD'));
define('SMTP_FROM', getenv('MAIL_FROM') ?: getenv('MAIL_USERNAME'));
define('SMTP_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'CHMSU E-Clearance System');

// ============================================
// SEND EMAIL FUNCTION
// ============================================
function sendEmail($to_email, $to_name, $subject, $body, $is_html = true) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to_email, $to_name);

        // Content
        $mail->isHTML($is_html);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return ['success' => true, 'message' => 'Email sent successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $mail->ErrorInfo];
    }
}

// ============================================
// NOTIFICATION FUNCTIONS
// ============================================

function sendApprovalNotification($student_email, $student_name, $office, $requirement) {
    $subject = "✅ Requirement Approved - CHMSU Clearance";
    $body = "<html>
    <head>
        <style>
            body { font-family: 'Times New Roman', Times, serif; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; }
            .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
            .status-approved { background: #27ae60; color: white; padding: 5px 15px; border-radius: 5px; display: inline-block; }
            .footer { text-align: center; font-size: 11px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>CHMSU E-Clearance System</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>$student_name</strong>,</p>
                <p>Good news! Your requirement has been <span class='status-approved'>APPROVED</span>.</p>
                <p><strong>Office:</strong> $office</p>
                <p><strong>Requirement:</strong> $requirement</p>
                <p>You can check your clearance status anytime by logging into the system.</p>
                <p>Thank you!</p>
            </div>
            <div class='footer'>
                <p>CHMSU E-Clearance System &bull; This is an automated notification.</p>
            </div>
        </div>
    </body>
    </html>";
    return sendEmail($student_email, $student_name, $subject, $body);
}

function sendDeclineNotification($student_email, $student_name, $office, $requirement, $reason) {
    $subject = "❌ Requirement Declined - CHMSU Clearance";
    $body = "<html>
    <head>
        <style>
            body { font-family: 'Times New Roman', Times, serif; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; }
            .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
            .status-declined { background: #e74c3c; color: white; padding: 5px 15px; border-radius: 5px; display: inline-block; }
            .reason-box { background: #f8d7da; padding: 10px; border-left: 3px solid #e74c3c; margin: 10px 0; }
            .footer { text-align: center; font-size: 11px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>CHMSU E-Clearance System</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>$student_name</strong>,</p>
                <p>Your requirement has been <span class='status-declined'>DECLINED</span>.</p>
                <p><strong>Office:</strong> $office</p>
                <p><strong>Requirement:</strong> $requirement</p>
                <div class='reason-box'>
                    <strong>Reason:</strong> $reason
                </div>
                <p>Please address the issue and resubmit.</p>
                <p>Thank you.</p>
            </div>
            <div class='footer'>
                <p>CHMSU E-Clearance System &bull; This is an automated notification.</p>
            </div>
        </div>
    </body>
    </html>";
    return sendEmail($student_email, $student_name, $subject, $body);
}

function sendSubmissionNotification($office_email, $office_name, $student_name, $requirement) {
    $subject = "📝 New Submission - CHMSU Clearance";
    $body = "<html>
    <head>
        <style>
            body { font-family: 'Times New Roman', Times, serif; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; }
            .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
            .footer { text-align: center; font-size: 11px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>CHMSU E-Clearance System</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>$office_name</strong>,</p>
                <p>A student has submitted a new requirement for your review.</p>
                <p><strong>Student:</strong> $student_name</p>
                <p><strong>Requirement:</strong> $requirement</p>
                <p>Please log in to review and process this submission.</p>
                <p>Thank you.</p>
            </div>
            <div class='footer'>
                <p>CHMSU E-Clearance System &bull; This is an automated notification.</p>
            </div>
        </div>
    </body>
    </html>";
    return sendEmail($office_email, $office_name, $subject, $body);
}
?>