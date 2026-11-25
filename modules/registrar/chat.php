<?php
// ============================================================
// modules/registrar/chat.php
// Registrar Chat — Reuses the EXACT same office chat interface
// Only difference: session office is ensured to be "Registrar"
// Everything else is 100% identical to modules/office/chat.php
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure the Registrar is logged in as an office
if (!isset($_SESSION['office']) || $_SESSION['office'] != 'Registrar') {
    header("Location: ../index.php");
    exit;
}

// Force office context (in case it was unset somehow)
$_SESSION['office'] = 'Registrar';

// Set section flag for sidebar highlighting
$currentSection = 'chat';
$currentOfficeSection = 'chat';

// Capture office chat output so we can inject the Registrar sidebar
ob_start();
require __DIR__ . '/../office/chat.php';
$office_chat_html = ob_get_clean();

// ============================================================
// Build the Registrar sidebar markup (uniform — same pattern
// used across all Registrar pages, so no visual blinking)
// ============================================================
$registrar_sidebar = '
    <div class="dark-sidebar">
        <div class="sidebar-header">
            <h3>Registrar Portal</h3>
            <p>Student Records Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?section=masterlist"><i class="fas fa-users"></i> Master List</a></li>
            <li><a href="?section=clearance_status"><i class="fas fa-user-check"></i> Clearance Status</a></li>
            <li><a href="?section=history"><i class="fas fa-history"></i> Clearance History</a></li>
            <li><a href="?section=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?section=archived"><i class="fas fa-archive"></i> Archive</a></li>
            <li><a href="?section=promotion"><i class="fas fa-arrow-up"></i> Student Promotion</a></li>
            <li><a href="?section=note"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li><a href="?section=chat" class="active"><i class="fas fa-comment-dots"></i> Messages</a></li>
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
// Swap the office sidebar with the Registrar sidebar.
// Uses an anchored regex: from `<div class="office-sidebar">`
// up to the point right before `<div class="main-content">`.
// This is robust against nested divs inside the sidebar, so
// the browser renders the Registrar sidebar on first paint
// and no scroll is needed.
// ============================================================
$office_chat_html = preg_replace(
    '/<div class="(office-sidebar|dark-sidebar)">.*?(?=<div class="main-content">)/s',
    $registrar_sidebar,
    $office_chat_html,
    1
);

echo $office_chat_html;