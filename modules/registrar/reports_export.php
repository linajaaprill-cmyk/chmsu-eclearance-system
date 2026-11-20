<?php
/**
 * CHMSU E-Clearance System — Registrar Reports Export Handler
 * Standalone endpoint: modules/registrar/reports_export.php
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

if (!isset($_SESSION['office']) || $_SESSION['office'] != 'Registrar') {
    http_response_code(403);
    exit('Forbidden');
}

$totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];

// Read filters
$filter_course  = isset($_GET['filter_course'])  ? trim($_GET['filter_course'])  : '';
$filter_year    = isset($_GET['filter_year'])    ? trim($_GET['filter_year'])    : '';
$filter_section = isset($_GET['filter_section']) ? trim($_GET['filter_section']) : '';
$filter_type    = isset($_GET['filter_type'])    ? trim($_GET['filter_type'])    : '';
$filter_status  = isset($_GET['filter_status'])  ? trim($_GET['filter_status'])  : '';

// Build WHERE
$where = array();
$where[] = "m.is_archived = 0";

if ($filter_course !== '') {
    $where[] = "m.chmsu_course = '" . $conn->real_escape_string($filter_course) . "'";
}
if ($filter_year !== '') {
    $yearInt = intval($filter_year);
    if ($yearInt >= 1 && $yearInt <= 4) {
        $where[] = "m.chmsu_year = '$yearInt'";
    }
}
if ($filter_section !== '') {
    $where[] = "m.chmsu_section = '" . $conn->real_escape_string($filter_section) . "'";
}
if ($filter_type === 'regular') {
    $where[] = "m.is_irregular = 0";
} elseif ($filter_type === 'irregular') {
    $where[] = "m.is_irregular = 1";
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Sort strictly by Course, Year, Section, Last Name
$query = "SELECT 
            m.chmsu_student_id,
            CONCAT(m.chmsu_last_name, ', ', m.chmsu_first_name, ' ', m.chmsu_middle_name) as student_name,
            m.chmsu_last_name, m.chmsu_first_name, m.chmsu_year as year,
            m.chmsu_section as section, m.chmsu_course as course, m.is_irregular,
            (SELECT COUNT(DISTINCT cs.office_name) FROM chmsu_clearance_status cs WHERE cs.student_id = m.chmsu_student_id AND cs.status = 'clear') as offices_completed
          FROM chmsu_students_master m
          $where_sql
          ORDER BY m.chmsu_course ASC, CAST(m.chmsu_year AS UNSIGNED) ASC, m.chmsu_section ASC, m.chmsu_last_name ASC, m.chmsu_first_name ASC";

$result = $conn->query($query);
$students = array();
$totalApproved = 0;
$totalPending = 0;
$totalRegular = 0;
$totalIrregular = 0;

while ($row = $result->fetch_assoc()) {
    $officesCompleted = $row['offices_completed'] ?: 0;
    $clearanceStatus = ($officesCompleted >= $totalOffices) ? 'Approved' : 'Pending';
    
    if ($clearanceStatus === 'Approved') $totalApproved++;
    else $totalPending++;
    
    if ($filter_status === 'approved' && $clearanceStatus !== 'Approved') continue;
    if ($filter_status === 'pending'  && $clearanceStatus !== 'Pending')  continue;
    
    $studentType = ($row['is_irregular'] == 1) ? 'Irregular' : 'Regular';
    if ($studentType === 'Regular') $totalRegular++;
    else $totalIrregular++;
    
    $progress = $officesCompleted . ' / ' . $totalOffices;
    $yearDisplay = $row['year'];
    switch($row['year']) {
        case '1': $yearDisplay = '1st'; break;
        case '2': $yearDisplay = '2nd'; break;
        case '3': $yearDisplay = '3rd'; break;
        case '4': $yearDisplay = '4th'; break;
        default: $yearDisplay = $row['year'] . 'th';
    }
    
    $students[] = array(
        'id' => $row['chmsu_student_id'],
        'name' => $row['student_name'],
        'year' => $yearDisplay,
        'year_raw' => $row['year'],
        'section' => $row['section'],
        'course' => $row['course'],
        'type' => $studentType,
        'status' => $clearanceStatus,
        'progress' => $progress
    );
}

$totalStudents = count($students);
$completionRate = ($totalStudents > 0) ? round(($totalApproved / $totalStudents) * 100, 2) : 0;

$yearOrdinals = array('1' => '1st', '2' => '2nd', '3' => '3rd', '4' => '4th');
$filterSummaryParts = array();
if ($filter_course !== '')  $filterSummaryParts[] = 'Course: ' . $filter_course;
if ($filter_year !== '')    $filterSummaryParts[] = 'Year: ' . (isset($yearOrdinals[$filter_year]) ? $yearOrdinals[$filter_year] : $filter_year . 'th') . ' Year';
if ($filter_section !== '') $filterSummaryParts[] = 'Section: ' . $filter_section;
if ($filter_type !== '')    $filterSummaryParts[] = 'Type: ' . ucfirst($filter_type);
if ($filter_status !== '')  $filterSummaryParts[] = 'Status: ' . ucfirst($filter_status);
$filterSummaryText = !empty($filterSummaryParts) ? implode('  |  ', $filterSummaryParts) : 'All Records';

/* ---------------- Official letterhead (identical to the Admin report) ---------------- */
function reg_report_header($logo_url, $unit_label) {
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
$unit_label = 'OFFICE OF THE UNIVERSITY REGISTRAR - E-CLEARANCE SYSTEM';

// STRICT BLACK & WHITE: all text black, no colored fills; bold is used instead of green
$cssBase = 'body{font-family:"Times New Roman",Times,serif;font-size:11pt;color:#000;line-height:1.3;} '
    . '.header-table{width:100%;border-collapse:collapse;margin:0;border:none;table-layout:fixed;} '
    . '.header-table td{border:none;padding:0;vertical-align:middle;} '
    . '.header-table .logo-cell{width:12%;text-align:left;} '
    . '.header-table .logo-cell img{width:76px;height:76px;} '
    . '.header-table .text-cell{width:76%;text-align:center;} '
    . '.header-table .edge-cell{width:12%;} '
    . '.univ{font-size:19pt;font-weight:bold;text-transform:uppercase;letter-spacing:1.5px;margin:0 0 3px 0;line-height:1.15;color:#000;} '
    . '.addr{font-size:10pt;margin:0 0 2px 0;color:#000;} '
    . '.unit{font-size:11pt;font-weight:bold;text-transform:uppercase;margin:0;letter-spacing:0;white-space:nowrap;color:#000;} '
    . '.header-line-container{width:100%;margin:4px 0 12px 0;} '
    . '.header-line{border-top:2px solid #000;border-bottom:1px solid #000;height:2px;width:100%;} '
    . '.title{text-align:center;font-size:14pt;font-weight:bold;margin:12px 0 0 0;text-transform:uppercase;text-decoration:underline;color:#000;} '
    . '.meta{width:100%;margin:15px 0;font-size:10pt;border-collapse:collapse;border:none;} '
    . '.meta td{border:none;padding:2px 0;color:#000;} '
    . 'table.data{width:100%;border-collapse:collapse;border-spacing:0;margin:0;} '
    . 'table.data th{border:1px solid #000;background-color:#fff;color:#000;font-weight:bold;text-align:center;font-size:9.5pt;padding:2px 5px;line-height:1.15;} '
    . 'table.data td{border:1px solid #000;padding:2px 5px;text-align:left;font-size:9.5pt;line-height:1.15;color:#000;} '
    . 'table.data th.group-header{background-color:#fff;color:#000;font-weight:bold;text-align:left;font-size:10pt;padding:4px 6px;} '
    . '.spacer{height:16px;line-height:16px;font-size:8pt;margin:0;} '
    . 'tr{page-break-inside:avoid;} thead{display:table-header-group;} '
    . 'table.summary{width:100%;border-collapse:collapse;margin-top:20px;font-size:10pt;} '
    . 'table.summary th{border:1px solid #000;background-color:#fff;color:#000;font-weight:bold;text-align:left;padding:2px 5px;} '
    . 'table.summary td{border:1px solid #000;padding:2px 5px;color:#000;} '
    . '.footer{text-align:center;margin-top:30px;font-size:9pt;font-style:italic;color:#000;}';

$type = isset($_GET['export_report']) ? $_GET['export_report'] : '';
while (ob_get_level()) { ob_end_clean(); }

$isWord = false;

if ($type === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Clearance_Report_' . date('Ymd') . '.xls"');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    // Letterhead is row 1 and is set as the print-title row, so it repeats on every printed page
    $headXml = '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Report</x:Name>'
        . '<x:WorksheetOptions><x:Print><x:ValidPrinterInfo/><x:PaperSizeIndex>9</x:PaperSizeIndex></x:Print></x:WorksheetOptions>'
        . '</x:ExcelWorksheet></x:ExcelWorksheets>'
        . '<x:ExcelNames><x:ExcelName><x:Name>Print_Titles</x:Name><x:SheetIndex>1</x:SheetIndex><x:Formula>=Report!$1:$1</x:Formula></x:ExcelName></x:ExcelNames>'
        . '</x:ExcelWorkbook></xml><![endif]-->';
    $css = $cssBase . ' .header-table td{border-bottom:3px double #000;} table.data td{mso-number-format:"\@";}';
} else if ($type === 'word') {
    $isWord = true;
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="Clearance_Report_' . date('Ymd') . '.doc"');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
    $headXml = '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->';
    // Same page size as before (13in x 8.5in); top margin leaves room for the repeating letterhead
    $css = '@page Section1{size:13in 8.5in;mso-page-orientation:landscape;margin:1.75in 0.5in 0.7in 0.5in;mso-header-margin:0.35in;mso-footer-margin:0.35in;mso-header:h1;mso-footer:f1;} div.Section1{page:Section1;} '
        . $cssBase
        . ' table.data{mso-table-lspace:0pt;mso-table-rspace:0pt;} table.data th{mso-line-height-rule:exactly;} table.data td{mso-line-height-rule:exactly;}';
} else {
    exit('Invalid export type');
}

echo '<head><meta charset="UTF-8"><title>Clearance Report</title>' . $headXml . '<style>' . $css . '</style></head><body>';

if ($isWord) { echo '<div class="Section1">'; }
else         { echo reg_report_header($logo_url, $unit_label); }

echo '<div class="title">Clearance Completion Report</div>';
echo '<table class="meta"><tr><td width="20%"><strong>Generated On:</strong></td><td>' . date('F d, Y h:i A') . '</td></tr><tr><td><strong>Generated By:</strong></td><td>Registrar Office</td></tr><tr><td><strong>Filters:</strong></td><td>' . htmlspecialchars($filterSummaryText) . '</td></tr></table>';

// ADDED: Type column header (Regular / Irregular)
$colHeaders = '<tr><th style="width:5%;">No.</th><th style="width:14%;">School ID</th><th style="width:26%;">Student Name</th><th style="width:7%;">Year</th><th style="width:9%;">Section</th><th style="width:10%;">Course</th><th style="width:9%;">Type</th><th style="width:11%;">Status</th><th style="width:9%;">Progress</th></tr>';

if (empty($students)) {
    echo '<table class="data"><thead>' . $colHeaders . '</thead><tbody>';
    echo '<tr><td colspan="9" style="text-align:center;padding:15px;">No records found.</td></tr>';
    echo '</tbody></table>';
} else {
    $num = 1;
    $lastGroup = '';
    $tableOpen = false;
    foreach ($students as $row) {
        $groupKey = $row['course'] . '|' . $row['year_raw'] . '|' . $row['section'];
        
        // Each Course / Year / Section gets its OWN table, followed by a blank space
        if ($groupKey !== $lastGroup) {
            if ($tableOpen) {
                echo '</tbody></table><div class="spacer">&nbsp;</div>';
            }
            $yearSuffix = 'th';
            if ($row['year_raw'] == 1) $yearSuffix = 'st';
            elseif ($row['year_raw'] == 2) $yearSuffix = 'nd';
            elseif ($row['year_raw'] == 3) $yearSuffix = 'rd';
            
            $groupTitle = strtoupper($row['course']) . ' — ' . $row['year_raw'] . $yearSuffix . ' Year — SECTION ' . strtoupper($row['section']);
            echo '<table class="data"><thead><tr><th colspan="9" class="group-header">' . htmlspecialchars($groupTitle) . '</th></tr>' . $colHeaders . '</thead><tbody>';
            $tableOpen = true;
            $lastGroup = $groupKey;
            $num = 1; // numbering restarts for each class list
        }

        echo '<tr>';
        echo '<td style="text-align:center;">' . $num++ . '</td>';
        echo '<td>' . htmlspecialchars($row['id']) . '</td>';
        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['year']) . '</td>';
        echo '<td>' . htmlspecialchars($row['section']) . '</td>';
        echo '<td>' . htmlspecialchars($row['course']) . '</td>';
        // ADDED: Type column value (Regular / Irregular)
        echo '<td>' . htmlspecialchars($row['type']) . '</td>';
        echo '<td style="text-align:center;">' . htmlspecialchars($row['status']) . '</td>';
        echo '<td style="text-align:center;">' . htmlspecialchars($row['progress']) . '</td>';
        echo '</tr>';
    }
    if ($tableOpen) {
        echo '</tbody></table><div class="spacer">&nbsp;</div>';
    }
}
echo '<table class="summary"><tr><th colspan="2">Summary Statistics</th></tr><tr><td width="70%">Total Students</td><td width="30%" style="text-align:right;">' . $totalStudents . '</td></tr><tr><td>Approved (Completed Clearance)</td><td style="text-align:right;">' . $totalApproved . '</td></tr><tr><td>Pending (In Progress)</td><td style="text-align:right;">' . $totalPending . '</td></tr><tr><td>Regular Students</td><td style="text-align:right;">' . $totalRegular . '</td></tr><tr><td>Irregular Students</td><td style="text-align:right;">' . $totalIrregular . '</td></tr><tr><td>Clearance Completion Rate</td><td style="text-align:right;">' . $completionRate . '%</td></tr></table>';
echo '<div class="footer">This is a system-generated report. No signature is required.</div>';

if ($isWord) {
    // Real Word header/footer: the letterhead and page numbers appear on EVERY page
    echo "<div style='mso-element:header' id=\"h1\">" . reg_report_header($logo_url, $unit_label) . '</div>';
    echo "<div style='mso-element:footer' id=\"f1\"><p class=\"MsoFooter\" style=\"text-align:center;font-size:8.5pt;margin:0;font-family:'Times New Roman'\">"
        . 'CHMSU E-Clearance System &middot; Confidential &mdash; for official university use only &middot; Page '
        . "<span style='mso-field-code:\" PAGE \"'></span> of <span style='mso-field-code:\" NUMPAGES \"'></span></p></div>";
    echo '</div>';
}
echo '</body></html>';
exit;