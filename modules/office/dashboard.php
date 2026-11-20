<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/clearance_history.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['office'])) {
    header("Location: ../index.php");
    exit;
}

$office = $_SESSION['office'];
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentOfficeSection = 'dashboard';

// ============================================
// GET CURRENT SEMESTER
// ============================================
$currentSem = getCurrentSemester($conn);
$currentSemId = $currentSem ? intval($currentSem['id']) : 0;

// ============================================
// ENSURE TABLE EXISTS (Safe Migration)
// ============================================
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_clearance_status (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(50) NOT NULL,
    office_name VARCHAR(100) NOT NULL,
    status ENUM('clear', 'unclear', 'pending') DEFAULT 'pending',
    note TEXT,
    updated_by VARCHAR(100),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    semester_id INT(11) DEFAULT NULL,
    UNIQUE KEY unique_status (student_id, office_name, semester_id)
)");

// ============================================
// STATS & ANALYTICS (FILTERED BY CURRENT SEMESTER)
// ============================================
$clearCount = 0;
$unclearCount = 0;
$pendingCount = 0;
$totalActiveStudents = 0;
$clearancePercentage = 0;

if ($currentSemId > 0) {
    $totalActiveStudents = $conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0")->fetch_assoc()['c'];
    
    $clearCount = $conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$office' AND status='clear' AND semester_id=$currentSemId")->fetch_assoc()['c'];
    $unclearCount = $conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$office' AND status='unclear' AND semester_id=$currentSemId")->fetch_assoc()['c'];
    
    $pendingCount = $totalActiveStudents - ($clearCount + $unclearCount);
    if ($pendingCount < 0) $pendingCount = 0;
    
    $clearancePercentage = ($totalActiveStudents > 0) ? round(($clearCount / $totalActiveStudents) * 100, 1) : 0;
}

// Get recent activities
$recentActivities = $conn->query("SELECT * FROM chmsu_activity_log WHERE user_type='office' AND user_id='$office' ORDER BY created_at DESC LIMIT 8");

// ============================================
// GET OFFICE EMAIL INFO
// ============================================
$office_info = null;
$has_email = false;
$check_column = $conn->query("SHOW COLUMNS FROM chmsu_auth_roles LIKE 'email'");
if ($check_column && $check_column->num_rows > 0) {
    $office_result = $conn->query("SELECT chmsu_role as office_name, email, app_password FROM chmsu_auth_roles WHERE chmsu_role='$office'");
    if ($office_result && $office_result->num_rows > 0) {
        $office_info = $office_result->fetch_assoc();
        $has_email = !empty($office_info['email']);
    }
}

$chat_unread_count = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$office'")->fetch_assoc()['total'] ?: 0;

// ============================================
// BAR CHART DATA: Clearance by Year Level
// ============================================
$yearLabels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
$yearClearData = [0, 0, 0, 0];
$yearPendingData = [0, 0, 0, 0];

if ($currentSemId > 0) {
    $yearQuery = $conn->query("
        SELECT s.chmsu_year, 
               SUM(CASE WHEN cs.status = 'clear' THEN 1 ELSE 0 END) as cleared,
               SUM(CASE WHEN cs.status = 'clear' OR cs.status = 'unclear' THEN 0 ELSE 1 END) as pending
        FROM chmsu_students_master s
        LEFT JOIN chmsu_clearance_status cs 
            ON s.chmsu_student_id = cs.student_id 
            AND cs.office_name = '$office' 
            AND cs.semester_id = $currentSemId
        WHERE s.is_archived = 0
        GROUP BY s.chmsu_year
    ");
    
    if ($yearQuery) {
        while ($row = $yearQuery->fetch_assoc()) {
            $y = intval($row['chmsu_year']);
            if ($y >= 1 && $y <= 4) {
                $yearClearData[$y - 1] = intval($row['cleared']);
                $yearPendingData[$y - 1] = intval($row['pending']);
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - <?php echo strtoupper($office); ?> Dashboard</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Preline UI -->
    <script src="https://cdn.jsdelivr.net/npm/preline@latest/dist/preline.js"></script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        chmsu: '#1b4d3e',
                        chmsuLight: '#2d6a4f',
                        chmsuYellow: '#f1c40f'
                    }
                }
            }
        }
    </script>

    <style>
        /* 
          FIX: Exact same CSS for Sidebar to prevent blinking 
          between pages.
        */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; background: #f5f5f5; font-size: 14px; transition: background 0.3s; }

        .header {
            background: #1b4d3e;
            color: white;
            padding: 15px 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header-logo img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 20px; font-weight: normal; }
        .header-title p { font-size: 11px; opacity: 0.8; margin-top: 3px; }
        
        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }

        /* EXACT SAME SIDEBAR STYLES */
        .office-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1001;
        }
        .office-sidebar::-webkit-scrollbar { width: 5px; }
        .office-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .office-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }

        .office-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .office-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }

        .office-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; padding-bottom: 20px; }
        .office-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
            position: relative;
            pointer-events: auto;
            cursor: pointer;
        }
        .office-sidebar .sidebar-menu a:hover,
        .office-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }

        .office-sidebar .sidebar-menu a .chat-badge {
            background: #e74c3c;
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 10px;
            min-width: 20px;
            text-align: center;
            float: right;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.15); }
            100% { transform: scale(1); }
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px;
            background: #f5f5f5;
            min-height: calc(100vh - 73px);
        }

        @media (max-width: 768px) {
            .office-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 transition-colors duration-300 text-gray-800 dark:text-gray-200">

<!-- HEADER -->
<div class="header dark:bg-gray-800">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1 class="font-semibold tracking-wide">CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p class="uppercase tracking-widest text-gray-300">CLEARANCE SYSTEM | <?php echo strtoupper($office); ?> Portal</p>
    </div>
    
    <div class="ml-auto flex items-center gap-4">
        <button onclick="toggleDarkMode()" class="flex items-center gap-2 px-3 py-1.5 border border-gray-400 dark:border-gray-600 rounded hover:bg-[#2d6a4f] transition-colors text-sm font-medium">
            <i data-lucide="moon" class="w-4 h-4"></i> Dark Mode
        </button>
        <button onclick="confirmLogout()" class="flex items-center gap-2 px-4 py-1.5 bg-red-600 hover:bg-red-700 rounded text-white transition-colors text-sm font-medium shadow-sm">
            <i data-lucide="log-out" class="w-4 h-4"></i> Logout
        </button>
    </div>
</div>

<div class="dashboard-wrapper">
    <!-- SIDEBAR (Same size, structure & links to prevent blinking) -->
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
            <p>Clearance Management</p>
        </div>
        <ul class="sidebar-menu">
            <li>
                <a href="?officesection=dashboard" class="active">
                    <i class="fas fa-tachometer-alt w-5"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="?officesection=note">
                    <i class="fas fa-rss w-5"></i> Feed Updates
                </a>
            </li>
            <li>
                <a href="?officesection=clearance">
                    <i class="fas fa-clipboard-check w-5"></i> Clearance
                </a>
            </li>
            <li>
                <a href="?officesection=history">
                    <i class="fas fa-history w-5"></i> Clearance History
                </a>
            </li>
            <li>
                <a href="?officesection=reports">
                    <i class="fas fa-chart-bar w-5"></i> Reports
                </a>
            </li>
            <li>
                <a href="?officesection=chat" class="chat-link">
                    <i class="fas fa-comment w-5"></i> Messages
                    <?php if ($chat_unread_count > 0): ?>
                        <span class="chat-badge"><?php echo $chat_unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="mt-5 border-t border-[#2d6a4f]">
                <a href="#" onclick="confirmLogout(); return false;">
                    <i class="fas fa-sign-out-alt w-5"></i> Logout
                </a>
            </li>
        </ul>
        <div class="p-4 text-[10px] text-gray-300 border-t border-[#2d6a4f] mt-5">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div class="w-full h-1.5 bg-[#2d6a4f] mt-2 rounded-full overflow-hidden">
                <div class="h-full bg-yellow-400" style="width:<?php echo $storage_percent; ?>%"></div>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT (Using Tailwind & Preline) -->
    <div class="main-content">
        
        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-6">
            <div>
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">
                    <?php echo htmlspecialchars($office); ?> - Dashboard
                </h2>
                <?php if ($currentSem): ?>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 flex items-center gap-2">
                        <i data-lucide="calendar" class="w-4 h-4"></i>
                        Active Semester: <span class="font-bold text-chmsu dark:text-green-400"><?php echo htmlspecialchars($currentSem['semester_name'] . ' ' . (!empty($currentSem['school_year']) ? '· ' . $currentSem['school_year'] : '')); ?></span>
                    </p>
                <?php else: ?>
                    <p class="text-sm text-red-500 mt-1 flex items-center gap-2">
                        <i data-lucide="alert-triangle" class="w-4 h-4"></i> No active semester set. Stats will show 0.
                    </p>
                <?php endif; ?>
            </div>
            <span class="mt-3 sm:mt-0 text-sm font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 px-4 py-2 rounded-full shadow-inner">
                <?php echo date('F d, Y'); ?>
            </span>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($error) && $error): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r shadow-sm" role="alert">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>
        <?php if (isset($success) && $success): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r shadow-sm" role="alert">
                <p><?php echo $success; ?></p>
            </div>
        <?php endif; ?>

        <!-- EMAIL CONNECTION CARD -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-6 overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50 dark:bg-gray-800/50">
                <div class="flex items-center gap-2 font-bold text-chmsu dark:text-green-400 text-sm">
                    <i data-lucide="mail" class="w-4 h-4"></i>
                    <span>Email Connection</span>
                </div>
                <?php if ($has_email): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-green-100 text-green-700 text-xs font-bold">
                        <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Connected
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-red-100 text-red-700 text-xs font-bold">
                        <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i> Not Connected
                    </span>
                <?php endif; ?>
            </div>
            <div class="p-5">
                <?php if ($has_email && $office_info): ?>
                    <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4">
                        <div class="w-12 h-12 rounded-full flex items-center justify-center text-white text-xl font-bold shrink-0 shadow-md" style="background: <?php echo ['#1b4d3e', '#2d6a4f', '#f39c12', '#e74c3c', '#3498db', '#9b59b6'][rand(0, 5)]; ?>;">
                            <?php echo strtoupper(substr($office_info['email'], 0, 1)); ?>
                        </div>
                        <div class="flex-1 text-center sm:text-left">
                            <div class="text-base font-semibold text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($office_info['email']); ?></div>
                            <div class="text-sm text-green-600 dark:text-green-400 flex items-center justify-center sm:justify-start gap-2 mt-1">
                                <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span> Connected • OTP Enabled
                            </div>
                        </div>
                        <button onclick="openEmailModal()" class="mt-3 sm:mt-0 px-4 py-2 bg-chmsu hover:bg-chmsuLight text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2 shadow-sm">
                            <i data-lucide="pencil" class="w-4 h-4"></i> Change
                        </button>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <div class="w-16 h-16 mx-auto bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center text-gray-400 mb-4 shadow-inner">
                            <i data-lucide="mail-open" class="w-8 h-8"></i>
                        </div>
                        <h4 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-2">Connect Your Gmail Account</h4>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5 max-w-md mx-auto">Connect your Gmail to receive OTP codes and notifications for secure office actions.</p>
                        <button onclick="openEmailModal()" class="px-6 py-2.5 bg-[#4285F4] hover:bg-[#3367D6] text-white font-medium rounded-lg transition-colors inline-flex items-center gap-3 shadow-md">
                            <i class="fab fa-google text-lg"></i> Connect Gmail
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- STATS CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Cleared -->
            <div class="bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex items-center gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-lg bg-green-50 dark:bg-green-900/30 text-green-600 flex items-center justify-center shrink-0">
                    <i data-lucide="check-circle-2" class="w-6 h-6"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?php echo $clearCount; ?></div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider font-semibold mt-1">Cleared</div>
                </div>
            </div>
            
            <!-- Not Cleared -->
            <div class="bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex items-center gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-600 flex items-center justify-center shrink-0">
                    <i data-lucide="x-circle" class="w-6 h-6"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?php echo $unclearCount; ?></div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider font-semibold mt-1">Not Cleared</div>
                </div>
            </div>

            <!-- Pending -->
            <div class="bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex items-center gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-lg bg-orange-50 dark:bg-orange-900/30 text-orange-500 flex items-center justify-center shrink-0">
                    <i data-lucide="clock" class="w-6 h-6"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?php echo $pendingCount; ?></div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider font-semibold mt-1">Pending</div>
                </div>
            </div>

            <!-- Rate -->
            <div class="bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex items-center gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-500 flex items-center justify-center shrink-0">
                    <i data-lucide="trending-up" class="w-6 h-6"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?php echo $clearancePercentage; ?>%</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider font-semibold mt-1">Clearance Rate</div>
                </div>
            </div>
        </div>

        <!-- CHARTS & ACTIVITY ROW -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            
            <!-- Doughnut Chart -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex flex-col">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2 bg-gray-50 dark:bg-gray-800/50 rounded-t-xl">
                    <i data-lucide="pie-chart" class="w-4 h-4 text-chmsu dark:text-green-400"></i>
                    <span class="font-bold text-sm text-gray-700 dark:text-gray-200">Clearance Status</span>
                </div>
                <div class="p-5 flex-1 flex flex-col items-center justify-center">
                    <div class="relative w-48 h-48 mb-4">
                        <canvas id="clearanceStatusChart"></canvas>
                        <div class="absolute inset-0 flex items-center justify-center flex-col pointer-events-none">
                            <span class="text-3xl font-bold text-gray-800 dark:text-gray-100"><?php echo $clearancePercentage; ?>%</span>
                            <span class="text-xs text-gray-500 uppercase tracking-wide">Complete</span>
                        </div>
                    </div>
                    <div class="w-full space-y-2 mt-2">
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-[#27ae60]"></span>
                                <span class="text-gray-600 dark:text-gray-300">Cleared</span>
                            </div>
                            <span class="font-bold text-gray-800 dark:text-gray-100"><?php echo $clearCount; ?></span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-[#e74c3c]"></span>
                                <span class="text-gray-600 dark:text-gray-300">Unclear</span>
                            </div>
                            <span class="font-bold text-gray-800 dark:text-gray-100"><?php echo $unclearCount; ?></span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-[#f39c12]"></span>
                                <span class="text-gray-600 dark:text-gray-300">Pending</span>
                            </div>
                            <span class="font-bold text-gray-800 dark:text-gray-100"><?php echo $pendingCount; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- NEW BAR CHART -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex flex-col lg:col-span-2">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2 bg-gray-50 dark:bg-gray-800/50 rounded-t-xl">
                    <i data-lucide="bar-chart-2" class="w-4 h-4 text-chmsu dark:text-green-400"></i>
                    <span class="font-bold text-sm text-gray-700 dark:text-gray-200">Clearance Progress by Year Level</span>
                </div>
                <div class="p-5 flex-1 relative min-h-[300px]">
                    <canvas id="yearLevelChart"></canvas>
                </div>
            </div>
        </div>

        <!-- ACTIVITY ROW -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2 bg-gray-50 dark:bg-gray-800/50">
                <i data-lucide="history" class="w-4 h-4 text-chmsu dark:text-green-400"></i>
                <span class="font-bold text-sm text-gray-700 dark:text-gray-200">Recent Activity</span>
            </div>
            <div class="p-0">
                <?php if ($recentActivities && $recentActivities->num_rows > 0): ?>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php while ($log = $recentActivities->fetch_assoc()): ?>
                            <li class="p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors flex items-start gap-4">
                                <div class="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0 mt-0.5">
                                    <i data-lucide="activity" class="w-4 h-4 text-gray-500"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm text-gray-800 dark:text-gray-200">
                                        <span class="font-bold text-chmsu dark:text-green-400"><?php echo htmlspecialchars($log['user_id']); ?></span> 
                                        — <?php echo htmlspecialchars($log['action']); ?>
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex items-center gap-1">
                                        <i data-lucide="clock" class="w-3 h-3"></i> 
                                        <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                                    </p>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                <?php else: ?>
                    <div class="p-8 text-center text-gray-500">
                        <i data-lucide="inbox" class="w-12 h-12 mx-auto text-gray-300 mb-3"></i>
                        <p>No activity records found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- EMAIL MODAL -->
<div id="emailModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] hidden items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden transform transition-all">
        <div class="p-5 text-center relative border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-xl font-bold text-gray-800 dark:text-gray-200">Connect Your Email</h3>
            <button onclick="closeEmailModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <div class="p-6 text-center">
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
                Sign in with your Google account to connect your Gmail and receive OTP codes and notifications.
            </p>

            <button onclick="window.location.href='?officesection=dashboard&google_login=1'" class="w-full flex items-center justify-center gap-3 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 py-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors font-semibold shadow-sm">
                <svg class="w-5 h-5" viewBox="0 0 48 48">
                    <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/>
                    <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/>
                    <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/>
                    <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z"/>
                </svg>
                Sign in with Google
            </button>

            <div class="mt-6">
                <button type="button" onclick="closeEmailModal()" class="px-6 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();

    // Dark Mode Logic
    function toggleDarkMode() {
        document.documentElement.classList.toggle('dark');
        const isDarkMode = document.documentElement.classList.contains('dark');
        localStorage.setItem('darkMode', isDarkMode);
    }
    
    if (localStorage.getItem('darkMode') === 'true' || (!('darkMode' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }

    function confirmLogout() {
        if(confirm('Are you sure you want to logout?')) {
            window.location.href = '?logout=1';
        }
    }

    // Email Modal Logic
    function openEmailModal() { 
        document.getElementById('emailModal').classList.remove('hidden');
        document.getElementById('emailModal').classList.add('flex');
    }
    function closeEmailModal() { 
        document.getElementById('emailModal').classList.add('hidden');
        document.getElementById('emailModal').classList.remove('flex');
    }

    // Chart.js Configuration & Data
    const isDarkMode = document.documentElement.classList.contains('dark');
    Chart.defaults.color = isDarkMode ? '#9ca3af' : '#6b7280';
    Chart.defaults.font.family = "'Times New Roman', Times, serif";

    // 1. Doughnut Chart
    const ctxDoughnut = document.getElementById('clearanceStatusChart').getContext('2d');
    new Chart(ctxDoughnut, {
        type: 'doughnut',
        data: {
            labels: ['Cleared', 'Unclear', 'Pending'],
            datasets: [{
                data: [<?php echo $clearCount; ?>, <?php echo $unclearCount; ?>, <?php echo $pendingCount; ?>],
                backgroundColor: ['#27ae60', '#e74c3c', '#f39c12'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false
                }
            }
        }
    });

    // 2. Bar Chart (New Analytics)
    const ctxBar = document.getElementById('yearLevelChart').getContext('2d');
    new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($yearLabels); ?>,
            datasets: [
                {
                    label: 'Cleared',
                    data: <?php echo json_encode($yearClearData); ?>,
                    backgroundColor: '#27ae60',
                    borderRadius: 4,
                    barPercentage: 0.6,
                    categoryPercentage: 0.5
                },
                {
                    label: 'Pending / Unclear',
                    data: <?php echo json_encode($yearPendingData); ?>,
                    backgroundColor: '#f39c12',
                    borderRadius: 4,
                    barPercentage: 0.6,
                    categoryPercentage: 0.5
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        padding: 20
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    padding: 12,
                    cornerRadius: 8,
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                    grid: {
                        color: isDarkMode ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)',
                        drawBorder: false
                    }
                },
                x: {
                    grid: { display: false, drawBorder: false }
                }
            }
        }
    });
</script>

</body>
</html>