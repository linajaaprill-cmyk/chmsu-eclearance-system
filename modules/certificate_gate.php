<?php
/**
 * modules/certificate_gate.php
 * 
 * Standalone certificate renderer with built-in clearance check.
 * Use this INSTEAD of modules/certificate.php by changing the include in index.php:
 *   include 'modules/certificate_gate.php';
 * 
 * Or leave index.php alone and this file is never called — safe.
 */

$student_id = sanitize($_GET['print_certificate'] ?? '');
if (!$student_id) { header('Location: index.php'); exit; }

// Clearance check
if (!hasAllClearanceApproved($conn, $student_id)) {
    header('Location: index.php?view=home&clearance_incomplete=1');
    exit;
}

$student = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
$user    = $conn->query("SELECT * FROM chmsu_user_accounts   WHERE chmsu_student_id='$student_id'")->fetch_assoc();

if (!$student) { header('Location: index.php'); exit; }

// Fetch clearance details per office
$offices = $conn->query("SELECT o.office_name, cs.status, cs.approved_at, cs.approved_by
                         FROM chmsu_offices o
                         LEFT JOIN chmsu_clearance_status cs
                           ON cs.office_name = o.office_name AND cs.student_id = '$student_id'
                         ORDER BY o.office_name ASC");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Certificate of Completion - <?= htmlspecialchars($student['chmsu_full_name']) ?></title>
    <style>
        body { font-family: 'Times New Roman', serif; padding: 40px; background: #fff; }
        .cert { max-width: 800px; margin: 0 auto; border: 8px double #1b4d3e; padding: 50px; text-align: center; }
        .cert h1 { font-size: 32px; color: #1b4d3e; margin-bottom: 8px; letter-spacing: 3px; }
        .cert h2 { font-size: 20px; margin: 20px 0 4px; }
        .cert p  { font-size: 16px; line-height: 1.6; margin: 8px 0; }
        .cert .name { font-size: 26px; font-weight: bold; color: #1b4d3e; margin: 20px 0; text-decoration: underline; }
        .cert table { margin: 30px auto; border-collapse: collapse; width: 80%; font-size: 14px; }
        .cert table th, .cert table td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
        .cert table th { background: #1b4d3e; color: #fff; }
        .cert .footer { margin-top: 50px; font-size: 12px; color: #666; }
        @media print { body { padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="cert">
        <h1>CHMSU</h1>
        <p>Central Philippines State University</p>
        <h2>CERTIFICATE OF COMPLETION</h2>
        <p>This certifies that</p>
        <div class="name"><?= htmlspecialchars($student['chmsu_full_name']) ?></div>
        <p>Student ID: <strong><?= htmlspecialchars($student['chmsu_student_id']) ?></strong></p>
        <p>Course: <strong><?= htmlspecialchars($student['chmsu_course'] ?? '-') ?></strong> &nbsp;|&nbsp;
           Year: <strong><?= htmlspecialchars($student['chmsu_year'] ?? '-') ?></strong> &nbsp;|&nbsp;
           Section: <strong><?= htmlspecialchars($student['chmsu_section'] ?? '-') ?></strong></p>
        <p>has completed all clearance requirements for the following offices:</p>
        <table>
            <tr><th>Office</th><th>Status</th><th>Approved On</th><th>Approved By</th></tr>
            <?php while ($o = $offices->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($o['office_name']) ?></td>
                <td><?= htmlspecialchars($o['status'] ?? 'N/A') ?></td>
                <td><?= $o['approved_at'] ? date('M d, Y', strtotime($o['approved_at'])) : '-' ?></td>
                <td><?= htmlspecialchars($o['approved_by'] ?? '-') ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
        <p>Issued on <strong><?= date('F d, Y') ?></strong></p>
        <div class="footer">
            This certificate is system-generated and valid only when printed from the CHMSU E-Clearance System.
        </div>
        <button class="no-print" onclick="window.print()" style="margin-top:20px;padding:10px 24px;background:#1b4d3e;color:#fff;border:none;border-radius:4px;cursor:pointer;">
            🖨️ Print Certificate
        </button>
    </div>
</body>
</html>