<?php
$office = $_SESSION['office'];
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentOfficeSection = 'clearance';

// Toggle between active and processed view
$view_mode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'pending';

// ============================================
// CREATE CLEARANCE STATUS TABLE IF NOT EXISTS
// ============================================
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_clearance_status (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    office_name VARCHAR(100) NOT NULL,
    status ENUM('clear', 'unclear', 'pending') DEFAULT 'pending',
    note TEXT,
    updated_by VARCHAR(100),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_status (student_id, office_name),
    INDEX idx_student (student_id),
    INDEX idx_office (office_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ============================================
// HANDLE APPROVE/DECLINE ACTIONS
// ============================================
if (isset($_POST['action'])) {
    if ($_POST['action'] == 'approve_selected' && isset($_POST['selected_students'])) {
        $selected = $_POST['selected_students'];
        foreach ($selected as $student_id) {
            $student_id = sanitize($student_id);
            $conn->query("INSERT INTO chmsu_clearance_status (student_id, office_name, status, updated_by) 
                          VALUES ('$student_id', '$office', 'clear', '$office')
                          ON DUPLICATE KEY UPDATE status = 'clear', note = NULL, updated_by = '$office', updated_at = CURRENT_TIMESTAMP");
            
            $studentInfo = $conn->query("SELECT chmsu_full_name, email FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
            if ($studentInfo) {
                $conn->query("INSERT INTO chmsu_notifications 
                              (user_type, user_id, student_id, office_name, title, message, type, link) 
                              VALUES 
                              ('student', '$student_id', '$student_id', '$office', 
                               '✅ Cleared by $office', 'Your clearance status has been approved by $office', 'clearance', '?view=clearance')");
            }
        }
        logActivity($conn, $office, 'office', "Approved " . count($selected) . " students");
        $success = count($selected) . " student(s) approved successfully!";
    }
    
    if ($_POST['action'] == 'decline_selected' && isset($_POST['selected_students'])) {
        $selected = $_POST['selected_students'];
        $reason = isset($_POST['decline_reason']) ? sanitize($_POST['decline_reason']) : 'Requirement not met';
        foreach ($selected as $student_id) {
            $student_id = sanitize($student_id);
            $conn->query("INSERT INTO chmsu_clearance_status (student_id, office_name, status, note, updated_by) 
                          VALUES ('$student_id', '$office', 'unclear', '$reason', '$office')
                          ON DUPLICATE KEY UPDATE status = 'unclear', note = '$reason', updated_by = '$office', updated_at = CURRENT_TIMESTAMP");
            
            $studentInfo = $conn->query("SELECT chmsu_full_name, email FROM chmsu_students_master WHERE chmsu_student_id='$student_id'")->fetch_assoc();
            if ($studentInfo) {
                $conn->query("INSERT INTO chmsu_notifications 
                              (user_type, user_id, student_id, office_name, title, message, type, link) 
                              VALUES 
                              ('student', '$student_id', '$student_id', '$office', 
                               '❌ Declined by $office', 'Your clearance status has been declined by $office. Reason: $reason', 'clearance', '?view=clearance')");
            }
        }
        logActivity($conn, $office, 'office', "Declined " . count($selected) . " students");
        $success = count($selected) . " student(s) declined successfully!";
    }
    
    // ============================================
    // REVERT ACTION (Move back to pending)
    // ============================================
    if ($_POST['action'] == 'revert_selected' && isset($_POST['selected_students'])) {
        $selected = $_POST['selected_students'];
        foreach ($selected as $student_id) {
            $student_id = sanitize($student_id);
            $conn->query("DELETE FROM chmsu_clearance_status WHERE student_id='$student_id' AND office_name='$office'");
        }
        logActivity($conn, $office, 'office', "Reverted " . count($selected) . " students to pending");
        $success = count($selected) . " student(s) reverted to pending!";
    }
}

// ============================================
// GET MENTIONED STUDENTS FROM POSTS - FIXED
// ============================================
$mentioned_students = array();
$feed_posts = $conn->query("SELECT content, student_id FROM chmsu_feed WHERE office_name='$office' ORDER BY created_at DESC");
while ($post = $feed_posts->fetch_assoc()) {
    if (!empty($post['student_id'])) {
        $mentioned_students[] = $post['student_id'];
    }
    
    if (!empty($post['content']) && strpos($post['content'], '|||') !== false) {
        $parts = explode('|||', $post['content']);
        foreach ($parts as $index => $part) {
            if ($index === 0) continue;
            
            $part = trim($part);
            if (empty($part)) continue;
            
            $studentMatch = $conn->query("SELECT chmsu_student_id FROM chmsu_students_master 
                                          WHERE chmsu_full_name = '" . $conn->real_escape_string($part) . "'
                                          OR CONCAT(chmsu_last_name, ', ', chmsu_first_name, ', ', chmsu_middle_name) = '" . $conn->real_escape_string($part) . "'
                                          LIMIT 1");
            if ($studentMatch && $studentMatch->num_rows > 0) {
                $mentioned_students[] = $studentMatch->fetch_assoc()['chmsu_student_id'];
            }
        }
    }
}
$mentioned_students = array_values(array_unique($mentioned_students));

// ============================================
// GET ALL STUDENTS WITH THEIR CLEARANCE STATUS
// ============================================
$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';
$filter_section = isset($_GET['filter_section']) ? $_GET['filter_section'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$search_term = isset($_GET['search_term']) ? $_GET['search_term'] : '';

$where = "WHERE s.is_archived = 0";
if ($filter_year) $where .= " AND s.chmsu_year = '$filter_year'";
if ($filter_section) $where .= " AND s.chmsu_section = '$filter_section'";
if ($search_term) $where .= " AND (s.chmsu_last_name LIKE '%$search_term%' OR s.chmsu_first_name LIKE '%$search_term%' OR s.chmsu_full_name LIKE '%$search_term%')";

if ($view_mode == 'pending') {
    $where .= " AND (c.status IS NULL OR c.status = 'pending')";
} else {
    $where .= " AND c.status IN ('clear', 'unclear')";
    if ($filter_status) $where .= " AND c.status = '$filter_status'";
}

$students = $conn->query("SELECT s.*, 
                          c.status as clearance_status, 
                          c.note as clearance_note,
                          c.updated_at as clearance_date,
                          (SELECT COUNT(*) FROM chmsu_feed WHERE office_name='$office' AND student_id=s.chmsu_student_id) as mentioned_count
                          FROM chmsu_students_master s
                          LEFT JOIN chmsu_clearance_status c ON s.chmsu_student_id = c.student_id AND c.office_name = '$office'
                          $where
                          ORDER BY s.chmsu_year ASC, s.chmsu_section ASC, s.chmsu_last_name ASC");

$sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections ORDER BY section_name");

$years = [1, 2, 3, 4];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - <?php echo strtoupper($office); ?> Clearance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; background: #f5f5f5; font-size: 14px; }
        
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
        }
        .header-logo img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 20px; font-weight: normal; }
        .header-title p { font-size: 11px; opacity: 0.8; margin-top: 3px; }
        .dark-mode-toggle { background: transparent; border: 1px solid rgba(255,255,255,0.3); color: white; padding: 6px 12px; cursor: pointer; margin-left: auto; }
        .logout-btn { background: #e74c3c; color: white; padding: 6px 15px; border: none; cursor: pointer; }
        
        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }
        
        .office-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .office-sidebar::-webkit-scrollbar { width: 5px; }
        .office-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .office-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .office-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .office-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .office-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
        }
        .office-sidebar .sidebar-menu a:hover,
        .office-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px;
            background: #f5f5f5;
            min-height: calc(100vh - 73px);
        }
        .dashboard-header-bar {
            background: white;
            padding: 15px 20px;
            border: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }
        
        .view-toggle {
            display: flex;
            gap: 0;
            background: #f0f0f0;
            border-radius: 30px;
            padding: 4px;
            border: 2px solid #1b4d3e;
        }
        .view-toggle a {
            padding: 8px 20px;
            text-decoration: none;
            color: #1b4d3e;
            font-size: 13px;
            font-weight: bold;
            border-radius: 30px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .view-toggle a.active {
            background: #1b4d3e;
            color: white;
        }
        .view-toggle a:hover:not(.active) {
            background: #e0e0e0;
        }
        
        .content-card {
            background: white;
            border: 1px solid #ddd;
            margin-bottom: 20px;
            border-radius: 8px;
            overflow: hidden;
        }
        .content-card-header {
            background: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
            font-weight: normal;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .content-card-body { padding: 15px; }
        
        .filter-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 15px;
        }
        .filter-row select, .filter-row input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            min-width: 120px;
        }
        .btn-filter {
            background: #1b4d3e;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-reset {
            background: #7f8c8d;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
        }
        
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #219a52; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-primary:hover { background: #2d6a4f; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
        .btn-back { background: #7f8c8d; color: white; }
        .btn-back:hover { background: #6c7a7d; }
        .btn-undo { background: #f39c12; color: white; }
        .btn-undo:hover { background: #e67e22; }
        
        .clearance-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .clearance-table th, .clearance-table td {
            border: 1px solid #ddd;
            padding: 8px 10px;
            text-align: left;
            vertical-align: middle;
        }
        .clearance-table th {
            background: #1b4d3e;
            color: white;
            font-weight: normal;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .clearance-table tr:nth-child(even) { background: #fafafa; }
        .clearance-table tr:hover { background: #f0f0f0; }
        .clearance-table input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }
        .status-cleared { background: #27ae60; color: white; }
        .status-unclear { background: #e74c3c; color: white; }
        .status-pending { background: #f39c12; color: white; }
        
        .mentioned-badge {
            background: #3498db;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 9px;
            display: inline-block;
            margin-left: 4px;
        }
        
        .action-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            margin-bottom: 15px;
        }
        .action-bar .select-all {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* ===== ON/OFF TOGGLE SWITCH (not a checkbox) ===== */
        .toggle-switch {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 12px 5px 8px;
            background: #e0e0e0;
            border-radius: 20px;
            font-size: 11px;
            cursor: pointer;
            border: 1px solid #ccc;
            user-select: none;
            transition: all 0.3s;
        }
        .toggle-switch .toggle-track {
            width: 34px;
            height: 18px;
            background: #999;
            border-radius: 20px;
            position: relative;
            transition: background 0.3s;
            flex-shrink: 0;
        }
        .toggle-switch .toggle-track::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 14px;
            height: 14px;
            background: white;
            border-radius: 50%;
            transition: left 0.3s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .toggle-switch.active {
            background: #27ae60;
            border-color: #27ae60;
            color: white;
        }
        .toggle-switch.active .toggle-track {
            background: rgba(255,255,255,0.5);
        }
        .toggle-switch.active .toggle-track::after {
            left: 18px;
        }
        
        .decline-reason-input {
            display: none;
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .decline-reason-input.show {
            display: block;
        }
        .decline-reason-input textarea {
            width: 100%;
            min-height: 60px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
        .info-message { background: #d1ecf1; color: #0c5460; padding: 10px; margin-bottom: 15px; border-left: 3px solid #17a2b8; }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .empty-state i {
            font-size: 48px;
            display: block;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .clearance-table th { background: #2c2c2c; color: #fff; border-color: #444; }
        body.dark-mode .clearance-table td { border-color: #333; color: #fff; }
        body.dark-mode .clearance-table tr:nth-child(even) { background: #1a1a1a; }
        body.dark-mode .clearance-table tr:hover { background: #2c2c2c; }
        body.dark-mode .filter-row select,
        body.dark-mode .filter-row input { background: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .action-bar { border-bottom-color: #444; }
        body.dark-mode .decline-reason-input { background: #2c2c2c; }
        body.dark-mode .decline-reason-input textarea { background: #1a1a1a; border-color: #444; color: #fff; }
        body.dark-mode .view-toggle { background: #2c2c2c; }
        body.dark-mode .view-toggle a { color: #f1c40f; }
        body.dark-mode .view-toggle a.active { background: #f1c40f; color: #000; }
        body.dark-mode .toggle-switch { background: #2c2c2c; border-color: #444; color: #ddd; }
        body.dark-mode .toggle-switch.active { background: #27ae60; border-color: #27ae60; color: #fff; }
        
        @media (max-width: 768px) {
            .office-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .clearance-table { font-size: 10px; }
            .clearance-table th, .clearance-table td { padding: 5px; }
            .view-toggle a { padding: 6px 12px; font-size: 11px; }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | <?php echo strtoupper($office); ?> Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
            <p>Clearance Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?officesection=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?officesection=feed"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li><a href="?officesection=clearance" class="active"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?officesection=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
        <div style="padding: 12px 20px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px; border-radius: 2px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f; border-radius: 2px;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Clearance Management</h2>
            <div class="view-toggle">
                <a href="?officesection=clearance&view_mode=pending" class="<?php echo $view_mode == 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Pending
                </a>
                <a href="?officesection=clearance&view_mode=processed" class="<?php echo $view_mode == 'processed' ? 'active' : ''; ?>">
                    <i class="fas fa-check-double"></i> Processed
                </a>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($mentioned_students) && $view_mode == 'pending'): ?>
            <div class="info-message">
                <i class="fas fa-info-circle"></i> 
                <strong><?php echo count($mentioned_students); ?> student(s)</strong> have been mentioned in posts. Toggle ON to enable selecting them.
            </div>
        <?php endif; ?>
        
        <div class="content-card">
            <div class="content-card-header">
                <span>
                    <i class="fas fa-users"></i> 
                    <?php echo $view_mode == 'pending' ? 'Pending Students' : 'Processed Students (Approved / Declined)'; ?>
                </span>
                <span style="font-size: 11px; color: #666;">
                    <?php echo $view_mode == 'pending' ? 'Select students and approve or decline' : 'Students already processed'; ?>
                </span>
            </div>
            <div class="content-card-body">
                <form method="GET" id="filterForm">
                    <input type="hidden" name="officesection" value="clearance">
                    <input type="hidden" name="view_mode" value="<?php echo $view_mode; ?>">
                    <div class="filter-row">
                        <div class="form-group">
                            <input type="text" name="search_term" placeholder="Search by name..." value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="form-group">
                            <select name="filter_year" onchange="this.form.submit()">
                                <option value="">All Years</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>th Year
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <select name="filter_section" onchange="this.form.submit()">
                                <option value="">All Sections</option>
                                <?php 
                                $sections->data_seek(0);
                                while ($s = $sections->fetch_assoc()): ?>
                                    <option value="<?php echo $s['section_name']; ?>" <?php echo $filter_section == $s['section_name'] ? 'selected' : ''; ?>>
                                        <?php echo $s['section_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php if ($view_mode == 'processed'): ?>
                        <div class="form-group">
                            <select name="filter_status" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <option value="clear" <?php echo $filter_status == 'clear' ? 'selected' : ''; ?>>Approved</option>
                                <option value="unclear" <?php echo $filter_status == 'unclear' ? 'selected' : ''; ?>>Declined</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div>
                            <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filter</button>
                            <a href="?officesection=clearance&view_mode=<?php echo $view_mode; ?>" class="btn-reset"><i class="fas fa-times"></i> Clear</a>
                        </div>
                    </div>
                </form>
                
                <?php if ($view_mode == 'pending'): ?>
                <form method="POST" id="clearanceForm" onsubmit="return validateForm()">
                    <input type="hidden" name="filter_year" value="<?php echo $filter_year; ?>">
                    <input type="hidden" name="filter_section" value="<?php echo $filter_section; ?>">
                    <input type="hidden" name="filter_status" value="<?php echo $filter_status; ?>">
                    <input type="hidden" name="search_term" value="<?php echo htmlspecialchars($search_term); ?>">
                    
                    <div class="action-bar">
                        <div class="select-all">
                            <input type="checkbox" id="selectAll" onclick="toggleAllCheckboxes()">
                            <label for="selectAll">Select All</label>
                        </div>
                        <button type="submit" name="action" value="approve_selected" class="btn btn-success">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="btn btn-danger" onclick="toggleDeclineReason()">
                            <i class="fas fa-times"></i> Decline
                        </button>
                        
                        <!-- ON/OFF TOGGLE for mentioned students -->
                        <div class="toggle-switch" id="mentionToggle" onclick="toggleMentionSelectable()">
                            <div class="toggle-track"></div>
                            <span><i class="fas fa-at"></i> Mentioned</span>
                        </div>
                        
                        <span style="font-size: 11px; color: #666; margin-left: 10px;">
                            <span id="selectedCount">0</span> selected
                        </span>
                    </div>
                    
                    <div class="decline-reason-input" id="declineReasonDiv">
                        <label><strong>Reason for Declination:</strong></label>
                        <textarea name="decline_reason" id="declineReason" placeholder="Enter reason for declining these students..."></textarea>
                        <button type="submit" name="action" value="decline_selected" class="btn btn-danger" style="margin-top: 5px;">
                            <i class="fas fa-times"></i> Confirm Decline
                        </button>
                        <button type="button" class="btn btn-sm btn-back" onclick="toggleDeclineReason()" style="margin-top: 5px;">Cancel</button>
                    </div>
                </form>
                <?php else: ?>
                <form method="POST" id="revertForm" onsubmit="return validateRevertForm()">
                    <input type="hidden" name="filter_year" value="<?php echo $filter_year; ?>">
                    <input type="hidden" name="filter_section" value="<?php echo $filter_section; ?>">
                    <input type="hidden" name="filter_status" value="<?php echo $filter_status; ?>">
                    <input type="hidden" name="search_term" value="<?php echo htmlspecialchars($search_term); ?>">
                    
                    <div class="action-bar" style="border-top: 2px solid #f39c12; border-bottom: 2px solid #f39c12; padding-top: 10px; padding-bottom: 10px;">
                        <div class="select-all">
                            <input type="checkbox" id="selectAllProcessed" onclick="toggleAllCheckboxesProcessed()">
                            <label for="selectAllProcessed">Select All</label>
                        </div>
                        <button type="submit" name="action" value="revert_selected" class="btn btn-undo">
                            <i class="fas fa-undo"></i> Undo Selected (Back to Pending)
                        </button>
                        <span style="font-size: 11px; color: #666; margin-left: 10px;">
                            <span id="selectedCountProcessed">0</span> selected
                        </span>
                        <span style="font-size: 11px; color: #e67e22; margin-left: 10px;">
                            <i class="fas fa-info-circle"></i> Undo will move students back to Pending list
                        </span>
                    </div>
                </form>
                <?php endif; ?>
                
                <div style="overflow-x: auto; max-height: 600px; overflow-y: auto; margin-top: 15px;">
                    <table class="clearance-table" id="clearanceTable">
                        <thead>
                            <tr>
                                <?php if ($view_mode == 'pending'): ?>
                                <th style="width: 30px;"><input type="checkbox" id="selectAllHeader" onclick="toggleAllCheckboxes()"></th>
                                <?php else: ?>
                                <th style="width: 30px;"><input type="checkbox" id="selectAllProcessedHeader" onclick="toggleAllCheckboxesProcessed()"></th>
                                <?php endif; ?>
                                <th>Student ID</th>
                                <th>Last Name</th>
                                <th>First Name</th>
                                <th>Course</th>
                                <th>Year</th>
                                <th>Section</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rowCount = 0;
                            
                            if ($students && $students->num_rows > 0):
                                while ($s = $students->fetch_assoc()):
                                    $isMentioned = in_array($s['chmsu_student_id'], $mentioned_students);
                                    $status = $s['clearance_status'] ?: 'pending';
                                    $statusClass = $status == 'clear' ? 'status-cleared' : ($status == 'unclear' ? 'status-unclear' : 'status-pending');
                                    $rowCount++;
                            ?>
                            <tr data-student-id="<?php echo $s['chmsu_student_id']; ?>">
                                <?php if ($view_mode == 'pending'): ?>
                                <td>
                                    <input type="checkbox" name="selected_students[]" value="<?php echo $s['chmsu_student_id']; ?>" 
                                           class="student-checkbox <?php echo $isMentioned ? 'mentioned-checkbox' : ''; ?>" 
                                           form="clearanceForm" 
                                           data-mentioned="<?php echo $isMentioned ? '1' : '0'; ?>"
                                           <?php echo $isMentioned ? 'disabled' : ''; ?>
                                           onchange="updateSelectedCount()">
                                    <?php if ($isMentioned): ?>
                                        <span class="mentioned-badge" title="Mentioned in post - locked by default">📌</span>
                                    <?php endif; ?>
                                </td>
                                <?php else: ?>
                                <td>
                                    <input type="checkbox" name="selected_students[]" value="<?php echo $s['chmsu_student_id']; ?>" 
                                           class="student-checkbox-processed" form="revertForm"
                                           onchange="updateSelectedCountProcessed()">
                                </td>
                                <?php endif; ?>
                                <td><code><?php echo $s['chmsu_student_id']; ?></code></td>
                                <td><?php echo htmlspecialchars($s['chmsu_last_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['chmsu_first_name']); ?></td>
                                <td><?php echo $s['chmsu_course']; ?></td>
                                <td><?php echo $s['chmsu_year']; ?></td>
                                <td><?php echo htmlspecialchars($s['chmsu_section']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo $status == 'clear' ? 'Approved' : ($status == 'unclear' ? 'Declined' : 'Pending'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($status == 'unclear' && !empty($s['clearance_note'])): ?>
                                        <span style="color: #e74c3c; font-size: 10px;"><?php echo htmlspecialchars($s['clearance_note']); ?></span>
                                    <?php elseif ($status == 'clear'): ?>
                                        <span style="color: #27ae60; font-size: 10px;">✓ Approved</span>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 10px;">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="9" class="empty-state">
                                    <i class="fas fa-<?php echo $view_mode == 'pending' ? 'check-circle' : 'inbox'; ?>"></i>
                                    <?php echo $view_mode == 'pending' ? 'No pending students. All students have been processed!' : 'No processed students yet.'; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 10px; font-size: 11px; color: #666;">
                    <span id="totalCount"><?php echo $rowCount; ?></span> 
                    <?php echo $view_mode == 'pending' ? 'pending' : 'processed'; ?> students
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const isDarkMode = document.body.classList.contains('dark-mode');
    localStorage.setItem('darkMode', isDarkMode);
    const btn = document.querySelector('.dark-mode-toggle');
    if (btn) {
        btn.innerHTML = isDarkMode ? '<i class="fas fa-sun"></i> Light Mode' : '<i class="fas fa-moon"></i> Dark Mode';
    }
}

if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
    const btn = document.querySelector('.dark-mode-toggle');
    if (btn) {
        btn.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
    }
}

function confirmLogout() {
    if(confirm('Are you sure you want to logout?')) {
        window.location.href = '?logout=1';
    }
}

// ===== MENTION TOGGLE (ON/OFF LOGO STYLE) =====
let mentionEnabled = false;

function toggleMentionSelectable() {
    mentionEnabled = !mentionEnabled;
    const toggle = document.getElementById('mentionToggle');
    const mentionedCheckboxes = document.querySelectorAll('.mentioned-checkbox');
    
    if (mentionEnabled) {
        toggle.classList.add('active');
        mentionedCheckboxes.forEach(cb => {
            cb.disabled = false;
        });
    } else {
        toggle.classList.remove('active');
        mentionedCheckboxes.forEach(cb => {
            cb.disabled = true;
            cb.checked = false;
        });
    }
    updateSelectedCount();
}

// ===== PENDING VIEW FUNCTIONS =====
function toggleAllCheckboxes() {
    const headerCheckbox = document.getElementById('selectAllHeader');
    const checkboxes = document.querySelectorAll('.student-checkbox');
    const isChecked = headerCheckbox.checked;
    
    checkboxes.forEach(cb => {
        // Only toggle checkboxes that are NOT disabled
        if (!cb.disabled) {
            cb.checked = isChecked;
        }
    });
    
    const mainSelectAll = document.getElementById('selectAll');
    if (mainSelectAll) mainSelectAll.checked = isChecked;
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.student-checkbox:checked');
    const enabledCheckboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
    const sc = document.getElementById('selectedCount');
    if (sc) sc.textContent = checkboxes.length;
    
    const allChecked = enabledCheckboxes.length > 0 && checkboxes.length === enabledCheckboxes.length;
    const headerCheckbox = document.getElementById('selectAllHeader');
    const mainSelectAll = document.getElementById('selectAll');
    if (headerCheckbox) headerCheckbox.checked = allChecked;
    if (mainSelectAll) mainSelectAll.checked = allChecked;
}

// ===== PROCESSED VIEW FUNCTIONS =====
function toggleAllCheckboxesProcessed() {
    const headerCheckbox = document.getElementById('selectAllProcessedHeader');
    const checkboxes = document.querySelectorAll('.student-checkbox-processed');
    const isChecked = headerCheckbox.checked;
    
    checkboxes.forEach(cb => {
        cb.checked = isChecked;
    });
    
    const mainSelectAll = document.getElementById('selectAllProcessed');
    if (mainSelectAll) mainSelectAll.checked = isChecked;
    updateSelectedCountProcessed();
}

function updateSelectedCountProcessed() {
    const checkboxes = document.querySelectorAll('.student-checkbox-processed:checked');
    const total = document.querySelectorAll('.student-checkbox-processed').length;
    const sc = document.getElementById('selectedCountProcessed');
    if (sc) sc.textContent = checkboxes.length;
    
    const allChecked = checkboxes.length > 0 && checkboxes.length === total;
    const headerCheckbox = document.getElementById('selectAllProcessedHeader');
    const mainSelectAll = document.getElementById('selectAllProcessed');
    if (headerCheckbox) headerCheckbox.checked = allChecked;
    if (mainSelectAll) mainSelectAll.checked = allChecked;
}

// ===== SHARED FUNCTIONS =====
function toggleDeclineReason() {
    const div = document.getElementById('declineReasonDiv');
    div.classList.toggle('show');
    if (div.classList.contains('show')) {
        document.getElementById('declineReason').focus();
    }
}

function validateForm() {
    const checkboxes = document.querySelectorAll('.student-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one student.');
        return false;
    }
    return true;
}

function validateRevertForm() {
    const checkboxes = document.querySelectorAll('.student-checkbox-processed:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one student to undo.');
        return false;
    }
    return confirm('Are you sure you want to undo the decision for ' + checkboxes.length + ' selected student(s)?\n\nThey will be moved back to the Pending list.');
}

document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
    updateSelectedCountProcessed();
    
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
    
    document.querySelectorAll('.student-checkbox-processed').forEach(cb => {
        cb.addEventListener('change', updateSelectedCountProcessed);
    });
});
</script>

</body>
</html>