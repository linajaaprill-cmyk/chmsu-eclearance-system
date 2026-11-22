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
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465); // Changed to 465 to bypass ISP blocks on port 587
define('SMTP_USER', 'linajaaprill@gmail.com');
define('SMTP_PASS', 'lniygbkmrpgbhcbk');
define('SMTP_FROM', 'linajaaprill@gmail.com');
define('SMTP_FROM_NAME', 'CHMSU E-Clearance System');

// ============================================
// SEND EMAIL FUNCTION
// Now accepts optional $from_email and $from_password.
// Added an optional $debug parameter (defaults to false so it doesn't break existing calls).
// ============================================
function sendEmail($to_email, $to_name, $subject, $body, $is_html = true, $from_email = null, $from_password = null, $debug = false, $attachment_path = null, $attachment_name = null, $embedded_cid = null) {
    // Use provided from credentials, otherwise fall back to defaults
    $use_from_email    = !empty($from_email)    ? $from_email    : SMTP_FROM;
    $use_from_password = !empty($from_password) ? $from_password : SMTP_PASS;

    $mail = new PHPMailer(true);

    try {
        if ($debug) {
            $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function($str, $level) {
                error_log("SMTP [$level]: $str");
            };
        }

        // Server settings
        $mail->isSMTP();
        $mail->Timeout       = 15;      // Increased send timeout
        $mail->SMTPKeepAlive = false;   // Changed to false for web requests to prevent throttling
        $mail->Host          = SMTP_HOST;
        $mail->SMTPAuth      = true;
        $mail->Username      = $use_from_email;
        $mail->Password      = $use_from_password;
        $mail->SMTPSecure    = PHPMailer::ENCRYPTION_SMTPS; // Changed to SMTPS for port 465
        $mail->Port          = SMTP_PORT;

        // Bypass SSL verification to prevent connection failures on local XAMPP
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // Recipients
        $mail->setFrom($use_from_email, SMTP_FROM_NAME);
        $mail->addAddress($to_email, $to_name);

        // Attachment / Embedded image support
        if (!empty($attachment_path) && file_exists($attachment_path)) {
            $att_display_name = !empty($attachment_name) ? $attachment_name : basename($attachment_path);
            if (!empty($embedded_cid)) {
                $mail->addEmbeddedImage($attachment_path, $embedded_cid, $att_display_name);
            } else {
                $mail->addAttachment($attachment_path, $att_display_name);
            }
        }

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
    $subject = "Requirement Approved - CHMSU Clearance";
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
    $subject = "Requirement Declined - CHMSU Clearance";
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
    $subject = "New Submission - CHMSU Clearance";
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

// ============================================
// MENTION NOTIFICATION (used by chmsu_notes.php)
// Sends a "you were mentioned" email to a student.
// This function was previously missing — now added.
// ============================================
function sendMentionNotification($student_email, $student_name, $office, $subject_text, $content, $section_text = '') {
    $subject = "URGENT: You were mentioned by $office - CHMSU";

    // Build the @mention chip and optional section text
    $mention_chip = "<span style='background:#f1c40f;padding:2px 10px;border-radius:12px;'>@" . htmlspecialchars($student_name) . "</span>";
    $section_html = !empty($section_text) ? htmlspecialchars($section_text) : '';

    $body = "
    <html><head><style>
        body { font-family: 'Times New Roman', Times, serif; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        .header { background: #1b4d3e; color: white; padding: 15px; text-align: center; }
        .alarm { background: #e74c3c; color: white; padding: 15px; text-align: center; font-size: 22px; font-weight: bold; border-radius: 4px; margin: 15px 0; }
        .subject-box { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #1b4d3e; }
        .note-box { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #f39c12; }
        .footer { text-align: center; padding: 15px; font-size: 12px; color: #666; border-top: 1px solid #ddd; margin-top: 20px; }
        .btn { display: inline-block; background: #1b4d3e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; }
    </style></head><body>
        <div class='container'>
            <div class='header'><h2>CHMSU E-Clearance System</h2></div>
            <div class='alarm'>YOU HAVE BEEN MENTIONED</div>
            <div class='content'>
                <p>Hello <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
                <p>You have been <strong>mentioned</strong> by <strong>" . htmlspecialchars($office) . "</strong>:</p>
                <div class='subject-box'><h3>" . htmlspecialchars($subject_text) . "</h3></div>
                <div class='note-box'><p>" . nl2br(htmlspecialchars($content)) . "</p></div>
                <p style='margin-top: 10px;'>
                    $mention_chip
                    $section_html
                </p>
                <p>Please login to your student portal to view this advisory.</p>
                <a href='http://" . $_SERVER['HTTP_HOST'] . "/index.php?view=feed' class='btn'>View Advisories</a>
            </div>
            <div class='footer'><p>CHMSU E-Clearance System</p></div>
        </div>
    </body></html>
    ";

    return sendEmail($student_email, $student_name, $subject, $body);
}

// ⛔ REMOVED: sendAdminLoginNotification() function