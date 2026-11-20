<?php
// includes/auth.php
// This file handles session start and basic authentication helpers.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if any user (admin, office, or student) is logged in.
 */
function isLoggedIn() {
    return isset($_SESSION['admin']) || isset($_SESSION['office']) || isset($_SESSION['student']);
}

/**
 * Force login if not authenticated.
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>