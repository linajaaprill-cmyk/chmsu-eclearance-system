<?php
// Increase time limit for bulk email operations
@set_time_limit(120);
@ini_set('max_execution_time', 120);

// Database configuration - LOCAL XAMPP VERSION ONLY
// DO NOT push to Render - use the SSL version there

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'chmsu_eclearance_campus_2024');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB max
define('UPLOAD_DIR', 'uploads/');

// Create upload directory if not exists
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

// Database connection (no SSL needed for local MySQL)
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>