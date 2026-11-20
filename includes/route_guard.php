<?php
/**
 * includes/route_guard.php
 * 
 * Route guard for removed requirements/submissions features.
 * Redirects legacy URLs to the new notes/feed system.
 * 
 * Include this file at the VERY TOP of index.php (after auth, before routing).
 * It does NOT modify any existing behavior — only redirects dead routes.
 */

if (!isset($conn)) {
    return; // Safety: only run inside index.php context
}

// ============================================
// 1. BLOCK REQUIREMENTS / SUBMISSIONS ROUTES
// ============================================
$currentView        = $_GET['view']          ?? '';
$currentSection     = $_GET['section']       ?? '';
$currentOfficeSec   = $_GET['officesection'] ?? '';
$currentAdminSec    = $_GET['adminsection']  ?? '';

// --- Registrar: requirements/submissions → note ---
if (isset($_SESSION['office']) && $_SESSION['office'] === 'Registrar') {
    if (in_array($currentSection, ['requirements', 'submissions'], true)) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?section=note');
        exit;
    }
}

// --- Office: requirements/submissions → note ---
if (isset($_SESSION['office']) && $_SESSION['office'] !== 'Registrar') {
    if (in_array($currentOfficeSec, ['requirements', 'submissions'], true)) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?officesection=note');
        exit;
    }
}

// --- Student: legacy clearance/requirements → feed ---
if (isset($_SESSION['student'])) {
    if (in_array($currentView, ['requirements', 'submissions'], true)) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?view=feed');
        exit;
    }
}

// ============================================
// 2. CERTIFICATE GUARD (clearance check)
// ============================================
if (isset($_GET['print_certificate']) && isset($_SESSION['student'])) {
    $sid = sanitize($_GET['print_certificate']);

    if (!function_exists('hasAllClearanceApproved') || !hasAllClearanceApproved($conn, $sid)) {
        // Not cleared → bounce back to home with a flag
        header('Location: ' . $_SERVER['PHP_SELF'] . '?view=home&clearance_incomplete=1');
        exit;
    }
    // Cleared → let index.php continue to modules/certificate.php
}