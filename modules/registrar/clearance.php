<?php
$totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];
$clearance_filter_course = isset($_GET['clearance_course']) ? $_GET['clearance_course'] : '';
$clearance_filter_year = isset($_GET['clearance_year']) ? $_GET['clearance_year'] : '';
$clearance_filter_section = isset($_GET['clearance_section']) ? $_GET['clearance_section'] : '';
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentSection = 'clearance';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Clearance Status</title>
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
        .dark-mode-toggle {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 6px 12px;
            cursor: pointer;
            margin-left: auto;
        }
        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 15px;
            border: none;
            cursor: pointer;
        }
        
        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }
        
        .registrar-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .registrar-sidebar::-webkit-scrollbar { width: 5px; }
        .registrar-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .registrar-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .registrar-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .registrar-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .registrar-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .registrar-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
        }
        .registrar-sidebar .sidebar-menu a:hover,
        .registrar-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
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
        }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }
        
        .content-card {
            background: white;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }
        .content-card-header {
            background: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
            font-weight: normal;
        }
        .content-card-body { padding: 15px; }
        
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 15px; }
        .form-group { margin-bottom: 0; }
        select, input { padding: 8px 12px; border: 1px solid #ddd; font-size: 12px; min-width: 120px; }
        .btn-filter { background: #1b4d3e; color: white; padding: 8px 15px; border: none; cursor: pointer; }
        .btn-reset { background: #7f8c8d; color: white; padding: 8px 15px; border: none; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
        .btn-primary { background: #1b4d3e; color: white; border: none; cursor: pointer; }
        .search-box { width: 100%; padding: 8px 12px; border: 1px solid #ddd; margin-bottom: 15px; }
        
        .toggle-buttons { display: flex; gap: 8px; margin-bottom: 15px; }
        .btn-toggle { background: #1b4d3e; color: white; padding: 8px 20px; border: none; cursor: pointer; }
        .btn-toggle-secondary { background: #7f8c8d; color: white; padding: 8px 20px; border: none; cursor: pointer; }
        
        .clearance-filters { background: #f8f9fa; padding: 12px; border-radius: 4px; margin-bottom: 15px; }
        
        .course-group { margin-bottom: 12px; }
        .course-header { background: #34495e; color: white; padding: 6px 10px; font-size: 11px; }
        .course-header.complete { background: #27ae60; }
        .course-header.incomplete { background: #e74c3c; }
        
        .plain-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .plain-table th, .plain-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .plain-table th { background: #f8f9fa; font-weight: normal; }
        
        .status-badge { padding: 4px 8px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-block; }
        .status-approved { background: #27ae60; color: white; }
        .status-pending { background: #f39c12; color: white; }
        
        .hidden { display: none; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-left: 3px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 3px solid #27ae60; }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .plain-table th { background: #2c2c2c; color: #fff; border-color: #444; }
        body.dark-mode .plain-table td { border-color: #333; color: #fff; }
        body.dark-mode .clearance-filters { background: #2c2c2c; }
        body.dark-mode select, body.dark-mode input { background: #2c2c2c; border-color: #444; color: #fff; }
        
        @media (max-width: 768px) {
            .registrar-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
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
        <p>CLEARANCE SYSTEM | Registrar Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <!-- ============================================ -->
    <!-- REGISTRAR SIDEBAR - UPDATED TO MATCH DASHBOARD -->
    <!-- ============================================ -->
    <div class="registrar-sidebar">
        <div class="sidebar-header">
            <h3>Registrar Portal</h3>
            <p>Student Records Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?section=masterlist"><i class="fas fa-users"></i> Master List</a></li>
            <li><a href="?section=clearance" class="active"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?section=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?section=archived"><i class="fas fa-archive"></i> Archive</a></li>
            <li><a href="?section=promotion"><i class="fas fa-arrow-up"></i> Student Promotion</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
        <div style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f;"></div>
            </div>
        </div>
    </div>
    <!-- ============================================ -->
    <!-- END OF SIDEBAR                               -->
    <!-- ============================================ -->
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Clearance Status</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success"><?php echo $success; ?></div><?php endif; ?>
        
        <div class="toggle-buttons">
            <button class="btn-toggle" onclick="showClearanceStatus('completed')">Complete</button>
            <button class="btn-toggle-secondary" onclick="showClearanceStatus('incomplete')">Incomplete</button>
        </div>
        
        <div class="clearance-filters">
            <form method="GET" id="clearanceFilterForm">
                <input type="hidden" name="section" value="clearance">
                <div class="filter-row">
                    <div class="form-group">
                        <select name="clearance_course" onchange="this.form.submit()">
                            <option value="">All Courses</option>
                            <?php $courses = $conn->query("SELECT * FROM chmsu_courses"); while($c=$courses->fetch_assoc()): ?>
                            <option value="<?php echo $c['course_code']; ?>" <?php echo $clearance_filter_course==$c['course_code']?'selected':''; ?>><?php echo $c['course_code']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <select name="clearance_year" onchange="this.form.submit()">
                            <option value="">All Years</option>
                            <option value="1" <?php echo $clearance_filter_year=='1'?'selected':''; ?>>1st</option>
                            <option value="2" <?php echo $clearance_filter_year=='2'?'selected':''; ?>>2nd</option>
                            <option value="3" <?php echo $clearance_filter_year=='3'?'selected':''; ?>>3rd</option>
                            <option value="4" <?php echo $clearance_filter_year=='4'?'selected':''; ?>>4th</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <select name="clearance_section" onchange="this.form.submit()">
                            <option value="">All Sections</option>
                            <?php $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections"); while($s=$sections->fetch_assoc()): ?>
                            <option value="<?php echo $s['section_name']; ?>" <?php echo $clearance_filter_section==$s['section_name']?'selected':''; ?>><?php echo $s['section_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div><a href="?section=clearance" class="btn-reset">Clear</a></div>
                </div>
            </form>
        </div>
        
        <input type="text" class="search-box" id="clearanceSearch" placeholder="Search student..." onkeyup="searchClearanceList()">
        
        <div id="completed-section" class="clearance-section">
            <?php
            $courses_list = $conn->query("SELECT course_code FROM chmsu_courses");
            while ($course_row = $courses_list->fetch_assoc()):
                $course = $course_row['course_code'];
                
                $where_conditions = [];
                if($clearance_filter_course) $where_conditions[] = "m.chmsu_course='$clearance_filter_course'";
                if($clearance_filter_year) $where_conditions[] = "m.chmsu_year='$clearance_filter_year'";
                if($clearance_filter_section) $where_conditions[] = "m.chmsu_section='$clearance_filter_section'";
                $where_sql = !empty($where_conditions) ? "AND " . implode(" AND ", $where_conditions) : "";
                
                $completed = $conn->query("SELECT s.chmsu_student_id, u.chmsu_name, m.chmsu_year, m.chmsu_section,
                                          (SELECT COUNT(DISTINCT r.chmsu_office) 
                                           FROM chmsu_submissions s2
                                           JOIN chmsu_requirements r ON s2.chmsu_requirement_id = r.chmsu_id
                                           WHERE s2.chmsu_student_id = s.chmsu_student_id 
                                           AND s2.chmsu_status = 'Approved') as approved_count
                                          FROM chmsu_user_accounts s
                                          JOIN chmsu_students_master m ON s.chmsu_student_id = m.chmsu_student_id
                                          LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                          WHERE m.chmsu_course = '$course' $where_sql
                                          HAVING approved_count = $totalOffices
                                          ORDER BY m.chmsu_year, m.chmsu_section, m.chmsu_last_name");
                if ($completed->num_rows > 0):
            ?>
            <div class="course-group">
                <div class="course-header complete"><?php echo $course; ?> - Complete</div>
                <table class="plain-table">
                    <thead>
                        <tr><th>ID</th><th>Name</th><th>Year</th><th>Section</th><th>Status</th><th>Certificate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($c = $completed->fetch_assoc()): ?>
                        <tr data-name="<?php echo strtolower($c['chmsu_name']); ?>">
                            <td><code><?php echo $c['chmsu_student_id']; ?></code></td>
                            <td><?php echo $c['chmsu_name']; ?></td>
                            <td><?php echo $c['chmsu_year']; ?></td>
                            <td><?php echo $c['chmsu_section']; ?></td>
                            <td><span class="status-badge status-approved">Complete</span></td>
                            <td><a href="?print_certificate=<?php echo $c['chmsu_student_id']; ?>" target="_blank" class="btn-primary btn-sm">Print Certificate</a></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; endwhile; ?>
        </div>
        
        <div id="incomplete-section" class="clearance-section hidden">
            <?php
            $courses_list = $conn->query("SELECT course_code FROM chmsu_courses");
            while ($course_row = $courses_list->fetch_assoc()):
                $course = $course_row['course_code'];
                
                $where_conditions = [];
                if($clearance_filter_course) $where_conditions[] = "m.chmsu_course='$clearance_filter_course'";
                if($clearance_filter_year) $where_conditions[] = "m.chmsu_year='$clearance_filter_year'";
                if($clearance_filter_section) $where_conditions[] = "m.chmsu_section='$clearance_filter_section'";
                $where_sql = !empty($where_conditions) ? "AND " . implode(" AND ", $where_conditions) : "";
                
                $incomplete = $conn->query("SELECT s.chmsu_student_id, u.chmsu_name, m.chmsu_year, m.chmsu_section,
                                           (SELECT COUNT(DISTINCT r.chmsu_office) 
                                            FROM chmsu_submissions s2
                                            JOIN chmsu_requirements r ON s2.chmsu_requirement_id = r.chmsu_id
                                            WHERE s2.chmsu_student_id = s.chmsu_student_id 
                                            AND s2.chmsu_status = 'Approved') as approved_count
                                           FROM chmsu_user_accounts s
                                           JOIN chmsu_students_master m ON s.chmsu_student_id = m.chmsu_student_id
                                           LEFT JOIN chmsu_user_accounts u ON s.chmsu_student_id = u.chmsu_student_id
                                           WHERE m.chmsu_course = '$course' $where_sql
                                           HAVING approved_count < $totalOffices OR approved_count IS NULL
                                           ORDER BY m.chmsu_year, m.chmsu_section, m.chmsu_last_name");
                if ($incomplete->num_rows > 0):
            ?>
            <div class="course-group">
                <div class="course-header incomplete"><?php echo $course; ?> - Incomplete</div>
                <table class="plain-table">
                    <thead>
                        <tr><th>ID</th><th>Name</th><th>Year</th><th>Section</th><th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($i = $incomplete->fetch_assoc()):
                            $approvedCount = $i['approved_count'] ?: 0;
                        ?>
                        <tr data-name="<?php echo strtolower($i['chmsu_name']); ?>">
                            <td><code><?php echo $i['chmsu_student_id']; ?></code></td>
                            <td><?php echo $i['chmsu_name']; ?></td>
                            <td><?php echo $i['chmsu_year']; ?></td>
                            <td><?php echo $i['chmsu_section']; ?></td>
                            <td><span class="status-badge status-pending"><?php echo $approvedCount; ?>/<?php echo $totalOffices; ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; endwhile; ?>
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
    
    function showClearanceStatus(status) {
        const completedSection = document.getElementById('completed-section');
        const incompleteSection = document.getElementById('incomplete-section');
        if (status === 'completed') {
            completedSection.classList.remove('hidden');
            incompleteSection.classList.add('hidden');
        } else {
            completedSection.classList.add('hidden');
            incompleteSection.classList.remove('hidden');
        }
    }
    
    function searchClearanceList() {
        const search = document.getElementById('clearanceSearch')?.value.toLowerCase() || '';
        const rows = document.querySelectorAll('.course-group tbody tr');
        rows.forEach(row => {
            const name = row.cells[1]?.textContent.toLowerCase();
            if (name && name.includes(search)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
</script>

</body>
</html>