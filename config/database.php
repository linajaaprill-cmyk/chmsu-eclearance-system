<?php
// Database configuration
require_once __DIR__ . '/../includes/load_env.php';

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'chmsu_eclearance_campus_2024');
define('MAX_FILE_SIZE', 10 * 1024 * 1024);
define('UPLOAD_DIR', 'uploads/');

if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

// Database connection with SSL for TiDB
$conn = mysqli_init();
mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);

$connected = mysqli_real_connect(
    $conn,
    DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT,
    NULL,
    MYSQLI_CLIENT_SSL
);

if (!$connected) {
    die("Connection failed: " . mysqli_connect_error());
}

$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>