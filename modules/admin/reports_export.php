<?php
/**
 * CHMSU E-Clearance System — Admin Reports Export Handler
 * Standalone endpoint: modules/admin/reports_export.php
 * - Removed PDF.
 * - Removed School Years.
 * - Only exports 2 Semesters max.
 * - Portrait A4 layout with repeating header.
 */

// ============================================
// FIX FOR WINDOWS SESSION PERMISSION ERROR
// ============================================
if (!file_exists('C:/xampp/tmp/sessions')) {
    @mkdir('C:/xampp/tmp/sessions', 0777, true);
}
@session_save_path('C:/xampp/tmp/sessions');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify admin session
if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Forbidden');
}

$admin = $_SESSION['admin'];

/* ---------------- Helpers ---------------- */
function adm_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function adm_year_label($y) {
    $map = ['1' => '1st Year', '2' => '2nd Year', '3' => '3rd Year', '4' => '4th Year', '5' => '5th Year'];
    return isset($map[(string)$y]) ? $map[(string)$y] : ('Year ' . $y);
}

/* ---------------- Data queries ---------------- */
$totalCourses        = $conn->query("SELECT COUNT(*) as c FROM chmsu_courses")->fetch_assoc()['c'];
$totalOffices        = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];
$totalSections       = $conn->query("SELECT COUNT(*) as c FROM chmsu_course_sections")->fetch_assoc()['c'];
$totalAdminAccounts  = $conn->query("SELECT COUNT(*) as c FROM chmsu_admin_users")->fetch_assoc()['c'];
$totalOfficeAccounts = $conn->query("SELECT COUNT(*) as c FROM chmsu_auth_roles")->fetch_assoc()['c'];

$courses_data = [];
$courses = $conn->query("SELECT course_code, course_name FROM chmsu_courses ORDER BY course_code");
while ($row = $courses->fetch_assoc()) $courses_data[] = $row;

$offices_data = [];
$offices = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
while ($row = $offices->fetch_assoc()) $offices_data[] = $row;

$sections_data = [];
$sections = $conn->query("SELECT course, year, section_name FROM chmsu_course_sections ORDER BY course, year, section_name");
while ($row = $sections->fetch_assoc()) $sections_data[] = $row;

// Fetch only 2 semesters max
$semesters_data = [];
$semesters = $conn->query("SELECT semester_name, semester_code, is_current FROM chmsu_semesters ORDER BY id DESC LIMIT 2");
while ($row = $semesters->fetch_assoc()) $semesters_data[] = $row;

/* ---------------- Official letterhead (same on every page) ---------------- */
function adm_report_header($logo_url) {
    $h  = '<table class="header-table"><tr>';
    $h .= '<td class="logo-cell"><img src="' . adm_h($logo_url) . '" width="76" height="76" alt="CHMSU Logo"></td>';
    $h .= '<td class="text-cell">';
    $h .= '<div class="univ">CARLOS HILADO MEMORIAL<br>STATE UNIVERSITY</div>';
    $h .= '<div class="addr">Talisay City, Negros Occidental, Philippines</div>';
    $h .= '<div class="unit">OFFICE OF THE SYSTEM ADMINISTRATOR - E-CLEARANCE SYSTEM</div>';
    $h .= '</td>';
    $h .= '<td class="edge-cell">&nbsp;</td>';
    $h .= '</tr></table>';
    // Double line separator
    $h .= '<div class="header-line-container"><div class="header-line"></div></div>';
    return $h;
}

/* ---------------- Report HTML generator ---------------- */
function adm_report_html($mode, array $d) {
    $refNo = 'CHMSU-ECS/ADM-SYS/' . date('Ymd-His');
    $word  = ($mode === 'word');
    $excel = ($mode === 'excel');
    $logo_url = 'https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0';

    // STRICT BLACK & WHITE STYLING + EXACT HEADER LAYOUT
    $css = '
        ' . ($word ? '@page Section1 { size: 21.0cm 29.7cm; margin: 3.9cm 1.5cm 1.8cm 1.5cm; mso-header-margin: 0.8cm; mso-footer-margin: 0.8cm; mso-header: h1; mso-footer: f1; } div.Section1 { page: Section1; }' :
                     '@page { size: A4 portrait; margin: 1.5cm; }') . '
        body { font-family: "Times New Roman", Times, serif; color: #000; font-size: 11pt; line-height: 1.3; margin: ' . ($word ? '0' : '16px') . '; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* LETTERHEAD (repeats on every page) */
        .header-table { width: 100%; border-collapse: collapse; margin: 0; border: none; table-layout: fixed; }
        .header-table td { border: none; padding: 0; vertical-align: middle; }
        .header-table .logo-cell { width: 12%; text-align: left; }
        .header-table .logo-cell img { width: 76px; height: 76px; object-fit: contain; display: block; }
        .header-table .text-cell { width: 76%; text-align: center; }
        .header-table .edge-cell { width: 12%; }

        .univ { font-size: 19pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.5px; margin: 0 0 3px 0; line-height: 1.15; }
        .addr { font-size: 10pt; margin: 0 0 2px 0; }
        .unit { font-size: 11pt; font-weight: bold; text-transform: uppercase; margin: 0; letter-spacing: 0; white-space: nowrap; }

        /* DOUBLE LINE SEPARATOR */
        .header-line-container { width: 100%; margin: 4px 0 12px 0; }
        .header-line { border-top: 2px solid #000; border-bottom: 1px solid #000; height: 2px; width: 100%; }
        ' . ($excel ? '.header-table td { border-bottom: 3px double #000; }' : '') . '

        .rtitle { text-align: center; font-size: 14pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin: 15px 0 2px 0; }
        .rsub { text-align: center; font-size: 10pt; font-style: italic; margin-bottom: 12px; }

        /* Compact Black & White Table Styles */
        table { border-collapse: collapse; border-spacing: 0; width: 100%; margin: 0 0 8px 0; table-layout: fixed; }
        td, th { border: 1px solid #000; padding: 3px 5px; font-size: 9.5pt; line-height: 1.15; vertical-align: middle; word-wrap: break-word; color: #000; }
        th { background: #fff; color: #000; text-align: center; font-weight: bold; }
        td.lbl { background: #fff; font-weight: bold; width: 18%; }
        td.c { text-align: center; }
        td.r { text-align: right; }

        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .sec { font-size: 11.5pt; font-weight: bold; text-transform: uppercase; color: #000; margin: 15px 0 5px 0; border-bottom: 1px solid #000; padding-bottom: 2px; page-break-after: avoid; }

        .footer { text-align: center; font-size: 8.5pt; color: #000; border-top: 1px solid #000; margin-top: 8px; padding-top: 5px; }';

    /* ---------- Report body ---------- */
    $b = '';
    $b .= '<div class="rtitle">Admin System Management Report</div>'
        . '<div class="rsub">Courses, Offices, Sections, and Semesters</div>';

    $pairs = [
        ['Report No.', $refNo],
        ['Date Generated', date('F d, Y')],
        ['Generated By', 'System Administrator (' . $d['admin'] . ')'],
        ['Time Generated', date('h:i A')],
        ['Classification', 'Official / Confidential'],
    ];
    $b .= '<table>';
    for ($i = 0; $i < count($pairs); $i += 2) {
        // Handle odd number of pairs safely
        $val1 = isset($pairs[$i+1]) ? $pairs[$i+1][0] : '';
        $val2 = isset($pairs[$i+1]) ? $pairs[$i+1][1] : '';
        
        $b .= '<tr><td class="lbl">' . adm_h($pairs[$i][0]) . '</td><td>' . adm_h($pairs[$i][1]) . '</td>'
            . '<td class="lbl">' . adm_h($val1) . '</td><td>' . adm_h($val2) . '</td></tr>';
    }
    $b .= '</table>';

    $b .= '<div class="sec">I. System Summary</div><table><tr><th style="width:60%">Metric</th><th>Value</th></tr>';
    $sum = [
        ['Total Courses', $d['courses']], ['Total Offices', $d['offices']], ['Total Sections', $d['sections']],
        ['Admin Accounts', $d['admins']], ['Office Accounts', $d['officeAccts']],
    ];
    foreach ($sum as $r) $b .= '<tr><td>' . adm_h($r[0]) . '</td><td class="r"><b>' . adm_h($r[1]) . '</b></td></tr>';
    $b .= '</table>';

    $b .= '<div class="sec">II. Courses List (' . count($d['courses_data']) . ')</div>'
        . '<table><thead><tr><th style="width:8%">#</th><th style="width:25%">Course Code</th><th>Course Name</th></tr></thead><tbody>';
    $i = 1;
    foreach ($d['courses_data'] as $r) $b .= '<tr><td class="c">' . $i++ . '</td><td>' . adm_h($r['course_code']) . '</td><td>' . adm_h($r['course_name']) . '</td></tr>';
    if (!$d['courses_data']) $b .= '<tr><td class="c" colspan="3">No records found.</td></tr>';
    $b .= '</tbody></table>';

    $b .= '<div class="sec">III. Offices List (' . count($d['offices_data']) . ')</div>'
        . '<table><thead><tr><th style="width:8%">#</th><th>Office Name</th></tr></thead><tbody>';
    $i = 1;
    foreach ($d['offices_data'] as $r) $b .= '<tr><td class="c">' . $i++ . '</td><td>' . adm_h($r['office_name']) . '</td></tr>';
    if (!$d['offices_data']) $b .= '<tr><td class="c" colspan="2">No records found.</td></tr>';
    $b .= '</tbody></table>';

    $b .= '<div class="sec">IV. Sections List (' . count($d['sections_data']) . ')</div>'
        . '<table><thead><tr><th style="width:8%">#</th><th style="width:25%">Course</th><th style="width:25%">Year Level</th><th>Section Name</th></tr></thead><tbody>';
    $i = 1;
    foreach ($d['sections_data'] as $r) $b .= '<tr><td class="c">' . $i++ . '</td><td>' . adm_h($r['course']) . '</td><td>' . adm_h(adm_year_label($r['year'])) . '</td><td>' . adm_h($r['section_name']) . '</td></tr>';
    if (!$d['sections_data']) $b .= '<tr><td class="c" colspan="4">No records found.</td></tr>';
    $b .= '</tbody></table>';

    $b .= '<div class="sec">V. Semesters List (' . count($d['sem_data']) . ')</div>'
        . '<table><thead><tr><th style="width:8%">#</th><th>Semester Name</th><th style="width:20%">Code</th><th style="width:20%">Status</th></tr></thead><tbody>';
    $i = 1;
    foreach ($d['sem_data'] as $r) $b .= '<tr><td class="c">' . $i++ . '</td><td>' . adm_h($r['semester_name']) . '</td><td class="c">' . adm_h($r['semester_code']) . '</td><td class="c">' . ($r['is_current'] ? 'Current' : '') . '</td></tr>';
    if (!$d['sem_data']) $b .= '<tr><td class="c" colspan="4">No records found.</td></tr>';
    $b .= '</tbody></table>';

    $b .= '<div class="footer">This is a computer-generated report. No signature is required.<br>Report No. ' . adm_h($refNo)
        . ' &middot; CHMSU E-Clearance System &middot; Confidential &mdash; for official university use only.</div>';

    /* ---------- Assemble the document per format ---------- */
    $header = adm_report_header($logo_url);

    if ($word) {
        $h = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">'
           . '<head><meta charset="UTF-8"><title>' . adm_h($d['filename']) . '</title>'
           . '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->'
           . '<style>' . $css . '</style></head><body>';
    } elseif ($excel) {
        $h = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">'
           . '<head><meta charset="UTF-8"><title>' . adm_h($d['filename']) . '</title>'
           . '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Report</x:Name>'
           . '<x:WorksheetOptions><x:Print><x:ValidPrinterInfo/><x:PaperSizeIndex>9</x:PaperSizeIndex><x:Orientation>Portrait</x:Orientation></x:Print></x:WorksheetOptions>'
           . '</x:ExcelWorksheet></x:ExcelWorksheets>'
           . '<x:ExcelNames><x:ExcelName><x:Name>Print_Titles</x:Name><x:SheetIndex>1</x:SheetIndex><x:Formula>=Report!$1:$1</x:Formula></x:ExcelName></x:ExcelNames>'
           . '</x:ExcelWorkbook></xml><![endif]-->'
           . '<style>' . $css . '</style></head><body>';
    } else {
        $h = '<html><head><meta charset="UTF-8"><title>' . adm_h($d['filename']) . '</title><style>' . $css . '</style></head><body>';
    }

    if ($word) {
        // Letterhead + page numbers are real Word header/footer, so they appear on EVERY page.
        $h .= '<div class="Section1">' . $b
            . "<div style='mso-element:header' id=\"h1\">" . $header . '</div>'
            . "<div style='mso-element:footer' id=\"f1\"><p class=\"MsoFooter\" style=\"text-align:center;font-size:8.5pt;margin:0;font-family:'Times New Roman'\">"
            . 'Report No. ' . adm_h($refNo) . ' &middot; Confidential &mdash; for official university use only &middot; Page '
            . "<span style='mso-field-code:\" PAGE \"'></span> of <span style='mso-field-code:\" NUMPAGES \"'></span></p></div>"
            . '</div>';
    } else {
        // Excel: letterhead is row 1 (also set as the print-title row, so it repeats on every printed page)
        $h .= $header . $b;
    }

    return $h . '</body></html>';
}

/* ---------------- Build payload ---------------- */
$reportData = [
    'admin' => $admin,
    'courses' => $totalCourses, 'offices' => $totalOffices, 'sections' => $totalSections,
    'admins' => $totalAdminAccounts, 'officeAccts' => $totalOfficeAccounts,
    'courses_data' => $courses_data, 'offices_data' => $offices_data, 'sections_data' => $sections_data,
    'sem_data' => $semesters_data,
    'filename' => 'Admin_System_Management_Report_' . date('Ymd'),
];

/* ---------------- Send the file ---------------- */
$type = isset($_GET['export_type']) ? $_GET['export_type'] : '';

// Clean any output buffers before sending headers
while (ob_get_level()) { ob_end_clean(); }

if ($type === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $reportData['filename'] . '.xls"');
    header('Cache-Control: max-age=0');
    echo adm_report_html('excel', $reportData);
    exit;
}
if ($type === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $reportData['filename'] . '.doc"');
    header('Cache-Control: max-age=0');
    echo adm_report_html('word', $reportData);
    exit;
}

http_response_code(400);
exit('Invalid export_type');