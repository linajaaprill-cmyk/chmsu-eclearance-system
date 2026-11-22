<?php
/**
 * CHMSU E-Clearance System — Office Reports Export Handler
 * Standalone endpoint: modules/office/reports_export.php
 *
 * Output is PORTRAIT A4. PDF export has been removed.
 */

// FIX FOR WINDOWS SESSION PERMISSION ERROR
if (!file_exists('C:/xampp/tmp/sessions')) {
    @mkdir('C:/xampp/tmp/sessions', 0777, true);
}
@session_save_path('C:/xampp/tmp/sessions');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['office'])) {
    http_response_code(403);
    exit('Forbidden');
}

$office = $_SESSION['office'];

// Detect current semester
$current_sem_row = $conn->query("SELECT id FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
$current_sem_id  = $current_sem_row ? intval($current_sem_row['id']) : 0;
$sem_clause      = ($current_sem_id > 0) ? " AND cs.semester_id = $current_sem_id " : "";

// ============================================
// HELPERS
// ============================================
if (!function_exists('rpt_e')) {
    function rpt_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('rpt_ordinal')) {
    function rpt_ordinal($n) {
        $n = (int)$n;
        if ($n <= 0) return '-';
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]) . ' Year';
    }
}
if (!function_exists('rpt_status_label')) {
    function rpt_status_label($raw) {
        $raw = strtolower((string)$raw);
        if ($raw === 'clear') return 'Approved';
        if ($raw === 'unclear') return 'Declined';
        return 'Pending';
    }
}
if (!function_exists('rpt_fetch_rows')) {
    function rpt_fetch_rows($conn, $office, $type, $f, $sem_clause) {
        $office_esc = $conn->real_escape_string($office);
        
        $where = "m.is_archived = 0";

        if ($f['course'] !== '')  $where .= " AND UPPER(TRIM(m.chmsu_course)) = UPPER('" . $conn->real_escape_string($f['course']) . "')";
        if ($f['year'] !== '')    $where .= " AND m.chmsu_year = '" . $conn->real_escape_string($f['year']) . "'";
        if ($f['section'] !== '') $where .= " AND UPPER(TRIM(m.chmsu_section)) = UPPER('" . $conn->real_escape_string($f['section']) . "')";
        
        if ($f['from'] !== '')    $where .= " AND cs.updated_at >= '" . $f['from'] . " 00:00:00'";
        if ($f['to'] !== '')      $where .= " AND cs.updated_at <= '" . $f['to'] . " 23:59:59'";

        if ($type === 'approved')      $where .= " AND cs.status = 'clear'";
        elseif ($type === 'declined')  $where .= " AND cs.status = 'unclear'";
        elseif ($type === 'pending')   $where .= " AND (cs.status IS NULL OR cs.status = 'pending')";

        $sql = "SELECT m.chmsu_student_id AS sid, m.chmsu_last_name AS ln, m.chmsu_first_name AS fn,
                       m.chmsu_middle_name AS mn, m.chmsu_full_name AS full_name,
                       m.chmsu_course AS course, m.chmsu_year AS yr, m.chmsu_section AS sec,
                       cs.status AS st, cs.note AS note, cs.updated_at AS upd, cs.updated_by AS upd_by
                FROM chmsu_students_master m
                LEFT JOIN chmsu_clearance_status cs
                       ON cs.student_id = m.chmsu_student_id 
                       AND LOWER(cs.office_name) = LOWER('$office_esc')
                       $sem_clause
                WHERE $where
                ORDER BY m.chmsu_course ASC, CAST(m.chmsu_year AS UNSIGNED) ASC, m.chmsu_section ASC,
                         m.chmsu_last_name ASC, m.chmsu_first_name ASC";
        $rows = [];
        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $name = trim((string)$r['full_name']);
                if ($name === '') $name = trim($r['ln'] . ', ' . $r['fn'] . ' ' . $r['mn']);
                $r['name'] = $name;
                $r['label'] = rpt_status_label($r['st']);
                $rows[] = $r;
            }
            $res->free();
        }
        return $rows;
    }
}

// ============================================
// REQUEST: report type + filters
// ============================================
$type = isset($_GET['type']) ? $_GET['type'] : 'submissions';
if (!in_array($type, ['submissions', 'pending', 'approved', 'declined'], true)) $type = 'submissions';

$f = ['course' => '', 'year' => '', 'section' => '', 'from' => '', 'to' => ''];
if (!empty($_GET['course']))  $f['course']  = trim((string)$_GET['course']);
if (!empty($_GET['year']) && in_array((string)$_GET['year'], ['1', '2', '3', '4'], true)) $f['year'] = (string)$_GET['year'];
if (!empty($_GET['section'])) $f['section'] = trim((string)$_GET['section']);
$range_raw = isset($_GET['daterange']) ? trim((string)$_GET['daterange']) : '';
if ($range_raw !== '') {
    $parts = preg_split('/\s+to\s+/i', $range_raw);
    $d1 = $parts[0] ?? '';
    $d2 = $parts[1] ?? $d1;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) $f['from'] = $d1;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) $f['to'] = $d2;
}

$rows    = rpt_fetch_rows($conn, $office, $type, $f, $sem_clause);          // rows shown in this report
$allRows = rpt_fetch_rows($conn, $office, 'submissions', $f, $sem_clause);  // all statuses, for the summary

$totalStudents = count($allRows);
$approvedCount = 0; $pendingCount = 0; $declinedCount = 0;
foreach ($allRows as $r) {
    if ($r['label'] === 'Approved') $approvedCount++;
    elseif ($r['label'] === 'Declined') $declinedCount++;
    else $pendingCount++;
}
$approvalRate = ($totalStudents > 0) ? round(($approvedCount / $totalStudents) * 100, 1) : 0;

// Filter summary text
$filterParts = [];
if ($f['course'] !== '')  $filterParts[] = 'Course: ' . $f['course'];
if ($f['year'] !== '')    $filterParts[] = 'Year: ' . rpt_ordinal($f['year']);
if ($f['section'] !== '') $filterParts[] = 'Section: ' . $f['section'];
if ($f['from'] !== '')    $filterParts[] = 'Date Range: ' . $f['from'] . (($f['to'] !== '' && $f['to'] !== $f['from']) ? ' to ' . $f['to'] : '');
$filterSummaryText = !empty($filterParts) ? implode('  |  ', $filterParts) : 'All Records';

// Report definitions
$reportTitles = [
    'submissions' => 'All Students Clearance Report',
    'pending'     => 'Pending Clearance Report',
    'approved'    => 'Approved Clearance Report',
    'declined'    => 'Declined Clearance Report',
];
$reportTitle = $reportTitles[$type];

$remarksHead = ($type === 'declined') ? 'Reason' : 'Remarks';
if ($type === 'pending') {
    $columns = [
        ['no',     'No.',          5,  'center'],
        ['sid',    'School ID',    14, 'left'],
        ['name',   'Student Name', 31, 'left'],
        ['label',  'Status',       10, 'center'],
        ['note',   $remarksHead,   24, 'left'],
        ['upd',    'Date Updated', 16, 'left'],
    ];
} else {
    $columns = [
        ['no',     'No.',          5,  'center'],
        ['sid',    'School ID',    13, 'left'],
        ['name',   'Student Name', 26, 'left'],
        ['label',  'Status',       10, 'center'],
        ['note',   $remarksHead,   19, 'left'],
        ['upd',    'Date Updated', 16, 'left'],
        ['upd_by', 'Updated By',   11, 'left'],
    ];
}
$colCount = count($columns);

/* ---------------- Official letterhead ---------------- */
function off_report_header($logo_url, $unit_label) {
    $h  = '<table class="header-table"><tr>';
    $h .= '<td class="logo-cell"><img src="' . htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') . '" width="76" height="76" alt="CHMSU Logo"></td>';
    $h .= '<td class="text-cell">';
    $h .= '<div class="univ">CARLOS HILADO MEMORIAL<br>STATE UNIVERSITY</div>';
    $h .= '<div class="addr">Talisay City, Negros Occidental, Philippines</div>';
    $h .= '<div class="unit">' . htmlspecialchars($unit_label, ENT_QUOTES, 'UTF-8') . '</div>';
    $h .= '</td>';
    $h .= '<td class="edge-cell">&nbsp;</td>';
    $h .= '</tr></table>';
    $h .= '<div class="header-line-container"><div class="header-line"></div></div>';
    return $h;
}

$logo_url   = 'https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0';
$unit_label = (stripos($office, 'office') !== false)
    ? strtoupper($office) . ' - E-CLEARANCE SYSTEM'
    : 'OFFICE OF THE ' . strtoupper($office) . ' - E-CLEARANCE SYSTEM';

// STRICT BLACK & WHITE, PORTRAIT
$cssBase = 'body{font-family:"Times New Roman",Times,serif;font-size:10pt;color:#000;line-height:1.2;} '
    . '.header-table{width:100%;border-collapse:collapse;margin:0;border:none;table-layout:fixed;} '
    . '.header-table td{border:none;padding:0;vertical-align:middle;} '
    . '.header-table .logo-cell{width:12%;text-align:left;} '
    . '.header-table .logo-cell img{width:76px;height:76px;} '
    . '.header-table .text-cell{width:76%;text-align:center;} '
    . '.header-table .edge-cell{width:12%;} '
    . '.univ{font-size:19pt;font-weight:bold;text-transform:uppercase;letter-spacing:1.5px;margin:0 0 3px 0;line-height:1.15;color:#000;} '
    . '.addr{font-size:10pt;margin:0 0 2px 0;color:#000;} '
    . '.unit{font-size:11pt;font-weight:bold;text-transform:uppercase;margin:0;letter-spacing:0;white-space:nowrap;color:#000;} '
    . '.header-line-container{width:100%;margin:4px 0 10px 0;} '
    . '.header-line{border-top:2px solid #000;border-bottom:1px solid #000;height:2px;width:100%;} '
    . '.title{text-align:center;font-size:13pt;font-weight:bold;margin:8px 0 0 0;text-transform:uppercase;text-decoration:underline;color:#000;} '
    . '.meta{width:100%;margin:8px 0 10px 0;font-size:9pt;border-collapse:collapse;border:none;} '
    . '.meta td{border:none;padding:1px 0;color:#000;} '
    . 'table.data{width:100%;border-collapse:collapse;border-spacing:0;margin:0;} '
    . 'table.data th{border:1px solid #000;background-color:#fff;color:#000;font-weight:bold;text-align:center;font-size:8.5pt;padding:1px 4px;line-height:1.1;} '
    . 'table.data td{border:1px solid #000;padding:1px 4px;text-align:left;font-size:8.5pt;line-height:1.1;color:#000;} '
    . 'table.data th.group-header{background-color:#fff;color:#000;font-weight:bold;text-align:left;font-size:9pt;padding:3px 5px;} '
    . '.spacer{height:12px;line-height:12px;font-size:6pt;margin:0;} '
    . 'tr{page-break-inside:avoid;} thead{display:table-header-group;} '
    . 'table.summary{width:100%;border-collapse:collapse;margin-top:12px;font-size:9pt;} '
    . 'table.summary th{border:1px solid #000;background-color:#fff;color:#000;font-weight:bold;text-align:left;padding:1px 4px;} '
    . 'table.summary td{border:1px solid #000;padding:1px 4px;color:#000;} '
    . '.footer{text-align:center;margin-top:18px;font-size:8.5pt;font-style:italic;color:#000;}';

$exportType = isset($_GET['export_report']) ? $_GET['export_report'] : '';
while (ob_get_level()) { ob_end_clean(); }

$safeOffice = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $office), '_');
$baseName   = ($safeOffice !== '' ? $safeOffice : 'Office') . '_' . ucfirst($type) . '_Report_' . date('Ymd');
$isWord = false;

if ($exportType === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $baseName . '.xls"');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    // PORTRAIT A4 + repeating letterhead row
    $headXml = '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Report</x:Name>'
        . '<x:WorksheetOptions><x:Print><x:ValidPrinterInfo/><x:PaperSizeIndex>9</x:PaperSizeIndex><x:Orientation>Portrait</x:Orientation></x:Print></x:WorksheetOptions>'
        . '</x:ExcelWorksheet></x:ExcelWorksheets>'
        . '<x:ExcelNames><x:ExcelName><x:Name>Print_Titles</x:Name><x:SheetIndex>1</x:SheetIndex><x:Formula>=Report!$1:$1</x:Formula></x:ExcelName></x:ExcelNames>'
        . '</x:ExcelWorkbook></xml><![endif]-->';
    $css = $cssBase . ' .header-table td{border-bottom:3px double #000;} table.data td{mso-number-format:"\@";}';
} else if ($exportType === 'word') {
    $isWord = true;
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $baseName . '.doc"');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
    $headXml = '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->';
    // PORTRAIT A4; top margin leaves room for the repeating letterhead
    $css = '@page Section1{size:21.0cm 29.7cm;mso-page-orientation:portrait;margin:3.9cm 1.5cm 1.8cm 1.5cm;mso-header-margin:0.8cm;mso-footer-margin:0.8cm;mso-header:h1;mso-footer:f1;} div.Section1{page:Section1;} '
        . $cssBase
        . ' table.data{mso-table-lspace:0pt;mso-table-rspace:0pt;} table.data th{mso-line-height-rule:exactly;} table.data td{mso-line-height-rule:exactly;}';
} else if ($exportType === 'pdf') {
    $isPdf = true;
    $headXml = '';
    $css = $cssBase;
    ob_start();
} else {
    exit('Invalid export type');
}

echo '<head><meta charset="UTF-8"><title>' . rpt_e($reportTitle) . '</title>' . $headXml . '<style>' . $css . '</style></head><body>';

if ($isWord) { echo '<div class="Section1">'; }
else         { echo off_report_header($logo_url, $unit_label); }

echo '<div class="title">' . rpt_e($reportTitle) . '</div>';
echo '<table class="meta">'
    . '<tr><td width="20%"><strong>Office:</strong></td><td>' . rpt_e($office) . '</td></tr>'
    . '<tr><td><strong>Generated On:</strong></td><td>' . date('F d, Y h:i A') . '</td></tr>'
    . '<tr><td><strong>Generated By:</strong></td><td>' . rpt_e($office) . '</td></tr>'
    . '<tr><td><strong>Filters:</strong></td><td>' . rpt_e($filterSummaryText) . '</td></tr>'
    . '</table>';

$colHeaders = '<tr>';
foreach ($columns as $c) {
    $colHeaders .= '<th style="width:' . $c[2] . '%;">' . rpt_e($c[1]) . '</th>';
}
$colHeaders .= '</tr>';

function off_cell($key, $row, $num) {
    switch ($key) {
        case 'no':     return (string)$num;
        case 'sid':    return (string)$row['sid'];
        case 'name':   return (string)$row['name'];
        case 'label':  return (string)$row['label'];
        case 'note':   return (string)($row['note'] ?? '');
        case 'upd':    return !empty($row['upd']) ? date('m/d/Y h:i A', strtotime($row['upd'])) : '';
        case 'upd_by': return (string)($row['upd_by'] ?? '');
    }
    return '';
}

if (empty($rows)) {
    echo '<table class="data"><thead>' . $colHeaders . '</thead><tbody>';
    echo '<tr><td colspan="' . $colCount . '" style="text-align:center;padding:12px;">No records found.</td></tr>';
    echo '</tbody></table><div class="spacer">&nbsp;</div>';
} else {
    $num = 1;
    $lastGroup = '';
    $tableOpen = false;
    foreach ($rows as $row) {
        $groupKey = $row['course'] . '|' . $row['yr'] . '|' . $row['sec'];
        if ($groupKey !== $lastGroup) {
            if ($tableOpen) {
                echo '</tbody></table><div class="spacer">&nbsp;</div>';
            }
            $groupTitle = strtoupper($row['course']) . ' — ' . rpt_ordinal($row['yr']) . ' — SECTION ' . strtoupper($row['sec']);
            echo '<table class="data"><thead><tr><th colspan="' . $colCount . '" class="group-header">' . rpt_e($groupTitle) . '</th></tr>' . $colHeaders . '</thead><tbody>';
            $tableOpen = true;
            $lastGroup = $groupKey;
            $num = 1;
        }
        echo '<tr>';
        foreach ($columns as $c) {
            $align = ($c[3] === 'center') ? ' style="text-align:center;"' : '';
            echo '<td' . $align . '>' . rpt_e(off_cell($c[0], $row, $num)) . '</td>';
        }
        echo '</tr>';
        $num++;
    }
    if ($tableOpen) {
        echo '</tbody></table><div class="spacer">&nbsp;</div>';
    }
}

echo '<table class="summary"><tr><th colspan="2">Summary Statistics</th></tr>'
    . '<tr><td width="70%">Total Students</td><td width="30%" style="text-align:right;">' . $totalStudents . '</td></tr>'
    . '<tr><td>Approved</td><td style="text-align:right;">' . $approvedCount . '</td></tr>'
    . '<tr><td>Pending</td><td style="text-align:right;">' . $pendingCount . '</td></tr>'
    . '<tr><td>Declined</td><td style="text-align:right;">' . $declinedCount . '</td></tr>'
    . '<tr><td>Approval Rate</td><td style="text-align:right;">' . $approvalRate . '%</td></tr>'
    . '<tr><td>Records in this Report</td><td style="text-align:right;">' . count($rows) . '</td></tr>'
    . '</table>';
echo '<div class="footer">This is a system-generated report. No signature is required.</div>';

if ($isWord) {
    // Repeating header/footer (letterhead + page numbers on every page)
    echo "<div style='mso-element:header' id=\"h1\">" . off_report_header($logo_url, $unit_label) . '</div>';
    echo "<div style='mso-element:footer' id=\"f1\"><p class=\"MsoFooter\" style=\"text-align:center;font-size:8.5pt;margin:0;font-family:'Times New Roman'\">"
        . 'CHMSU E-Clearance System &middot; Confidential &mdash; for official university use only &middot; Page '
        . "<span style='mso-field-code:\" PAGE \"'></span> of <span style='mso-field-code:\" NUMPAGES \"'></span></p></div>";
    echo '</div>';
}
echo '</body></html>';

if (!empty($isPdf)) {
    $htmlContent = ob_get_clean();
    if (file_exists(__DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php')) {
        require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
    } elseif (file_exists('C:/xampp/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php')) {
        require_once 'C:/xampp/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php';
    }
    
    while (ob_get_level()) { ob_end_clean(); }
    
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('CHMSU E-Clearance System');
    $pdf->SetAuthor($office);
    $pdf->SetTitle($baseName);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(TRUE, 12);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->AddPage();
    $pdf->writeHTML($htmlContent, true, false, true, false, '');
    $pdf->Output($baseName . '.pdf', 'D');
    exit;
}

exit;