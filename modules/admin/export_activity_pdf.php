<?php
// modules/admin/export_activity_pdf.php
// Standalone Activity Log PDF download (admin only). Opened directly by the Export PDF button.

// Same session folder fix used by index.php (Windows XAMPP)
if (!file_exists('C:/xampp/tmp/sessions')) {
    @mkdir('C:/xampp/tmp/sessions', 0777, true);
}
@session_save_path('C:/xampp/tmp/sessions');

// Run from the project root so config paths (uploads/) resolve like index.php
chdir(__DIR__ . '/../..');

require_once __DIR__ . '/../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin'])) {
    header('Location: ../../index.php');
    exit;
}

@set_time_limit(300);
@ini_set('memory_limit', '512M');

$tcpdf_paths = [
    __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php',
    'C:/xampp/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php'
];
foreach ($tcpdf_paths as $tcpdf_path) {
    if (file_exists($tcpdf_path)) { require_once $tcpdf_path; break; }
}

while (ob_get_level()) { ob_end_clean(); }

if (!class_exists('TCPDF')) {
    header('Content-Type: text/plain');
    echo 'PDF library (TCPDF) not found in the vendor folder.';
    exit;
}

$rows = [];
$res = $conn->query("SELECT user_id, user_type, action, ip_address, created_at FROM chmsu_activity_log ORDER BY created_at DESC");
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }

$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('CHMSU E-Clearance System');
$pdf->SetAuthor('CHMSU');
$pdf->SetTitle('System Activity Log');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 12);
$pdf->SetFont('helvetica', '', 8);
$pdf->AddPage();

$head = '<div style="text-align:center;">'
      . '<div style="font-size:15pt;font-weight:bold;">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>'
      . '<div style="font-size:9pt;">Talisay City, Negros Occidental, Philippines</div>'
      . '<div style="font-size:12pt;font-weight:bold;">SYSTEM ACTIVITY LOG</div></div><br>'
      . '<table cellpadding="2" style="font-size:8pt;"><tr>'
      . '<td><b>Date Generated:</b> ' . date('F d, Y h:i A') . '</td>'
      . '<td style="text-align:right;"><b>Total Records:</b> ' . count($rows) . '</td></tr></table><br>';
$pdf->writeHTML($head, true, false, true, false, '');

$th = '<tr style="background-color:#dfe8e4;font-weight:bold;">'
    . '<th width="18%">User</th><th width="10%">Type</th><th width="40%">Action</th>'
    . '<th width="12%">IP Address</th><th width="20%">Date/Time</th></tr>';

if (empty($rows)) {
    $pdf->writeHTML('<table border="1" cellpadding="5"><tr><td style="text-align:center;">No activity records found.</td></tr></table>', true, false, true, false, '');
} else {
    foreach (array_chunk($rows, 150) as $chunk) {
        $t = '<table border="1" cellpadding="3"><thead>' . $th . '</thead><tbody>';
        foreach ($chunk as $r) {
            $t .= '<tr>'
                . '<td width="18%">' . htmlspecialchars((string)$r['user_id']) . '</td>'
                . '<td width="10%">' . htmlspecialchars((string)$r['user_type']) . '</td>'
                . '<td width="40%">' . htmlspecialchars((string)$r['action']) . '</td>'
                . '<td width="12%">' . htmlspecialchars((string)$r['ip_address']) . '</td>'
                . '<td width="20%">' . date('M d, Y H:i:s', strtotime($r['created_at'])) . '</td>'
                . '</tr>';
        }
        $t .= '</tbody></table>';
        $pdf->writeHTML($t, true, false, true, false, '');
    }
}

$pdf->Ln(3);
$pdf->writeHTML('<div style="text-align:center;font-size:7pt;font-style:italic;">This is a system-generated report. No signature is required.</div>', true, false, true, false, '');
$pdf->Output('Activity_Log_' . date('Ymd') . '.pdf', 'D');
exit;