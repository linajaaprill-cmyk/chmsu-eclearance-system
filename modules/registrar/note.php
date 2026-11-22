<?php
// ============================================================
// modules/registrar/note.php
// Registrar Feed Updates / Notes — Reuses the EXACT same office notes interface
// SIDEBAR REPLACEMENT via PHP regex (no JS injection)
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure the Registrar is logged in as an office
if (!isset($_SESSION['office']) || $_SESSION['office'] != 'Registrar') {
    header("Location: ../index.php");
    exit;
}

// Force office context
$_SESSION['office'] = 'Registrar';

// Set section flag for sidebar highlighting
$currentSection = 'note';
$currentOfficeSection = 'note';

// Capture office notes output
ob_start();
require __DIR__ . '/../office/chmsu_notes.php';
$office_notes_html = ob_get_clean();

// ============================================================
// Build the FULL Registrar sidebar markup
// ============================================================
$registrar_sidebar = '
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3>Registrar Portal</h3>
            <p>Student Records Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?section=masterlist"><i class="fas fa-users"></i> Master List</a></li>
            <li><a href="?section=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?section=clearance_status"><i class="fas fa-user-check"></i> Clearance Status</a></li>
            <li><a href="?section=history"><i class="fas fa-history"></i> Clearance History</a></li>
            <li><a href="?section=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?section=archived"><i class="fas fa-archive"></i> Archive</a></li>
            <li><a href="?section=promotion"><i class="fas fa-arrow-up"></i> Student Promotion</a></li>
            <li><a href="?section=note" class="active"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li><a href="?section=chat"><i class="fas fa-comment-dots"></i> Messages</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
        <div style="padding: 12px 20px; font-size: 10px; color: #ddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: ' . formatFileSize(getTotalUploadSize()) . ' / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px; border-radius:2px;">
                <div style="width:' . min((getTotalUploadSize() / (100 * 1024 * 1024)) * 100, 100) . '%; height:100%; background:#f1c40f; border-radius:2px;"></div>
            </div>
        </div>
    </div>';

// ============================================================
// Swap the office sidebar with the Registrar sidebar via regex.
// The captured chmsu_notes.php page uses .office-sidebar class.
// ============================================================
$office_notes_html = preg_replace(
    '/<div class="office-sidebar">.*?(?=<div class="main-content">)/s',
    $registrar_sidebar,
    $office_notes_html,
    1
);

// Ensure all navigation links stay within Registrar portal (?section=...)
$office_notes_html = str_replace('?officesection=note', '?section=note', $office_notes_html);
$office_notes_html = str_replace('?officesection=dashboard', '?section=dashboard', $office_notes_html);
$office_notes_html = str_replace('?officesection=clearance', '?section=clearance', $office_notes_html);
$office_notes_html = str_replace('?officesection=reports', '?section=reports', $office_notes_html);
$office_notes_html = str_replace('?officesection=chat', '?section=chat', $office_notes_html);
$office_notes_html = str_replace('?officesection=history', '?section=history', $office_notes_html);
$office_notes_html = str_replace('name="officesection" value="note"', 'name="section" value="note"', $office_notes_html);

// Output the final page
echo $office_notes_html;
