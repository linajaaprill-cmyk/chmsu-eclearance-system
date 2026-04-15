<?php
$totalOffices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c'];
$clearance_filter_course = isset($_GET['clearance_course']) ? $_GET['clearance_course'] : '';
$clearance_filter_year = isset($_GET['clearance_year']) ? $_GET['clearance_year'] : '';
$clearance_filter_section = isset($_GET['clearance_section']) ? $_GET['clearance_section'] : '';
?>

<div class="header">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Registrar Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="registrar-sidebar">
        <div class="sidebar-header"><h3>Registrar Portal</h3></div>
        <ul class="sidebar-menu">
            <li><a href="?section=masterlist"><i class="fas fa-users"></i> Master List</a></li>
            <li><a href="?section=clearance" class="active"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?section=requirements"><i class="fas fa-tasks"></i> Requirements</a></li>
            <li><a href="?section=submissions"><i class="fas fa-inbox"></i> Submissions</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;"><a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
        <div style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f;">
            Storage: <?php echo formatFileSize(getTotalUploadSize()); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px;">
                <div style="width:<?php echo min((getTotalUploadSize() / (100 * 1024 * 1024)) * 100, 100); ?>%; height:100%; background:#f1c40f;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>Clearance Status</h2>
            <span style="font-size: 11px;"><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success"><?php echo $success; ?></div><?php endif; ?>
        
        <div class="toggle-buttons" style="display:flex; gap:8px; margin-bottom:15px;">
            <button class="btn btn-primary" onclick="showClearanceStatus('completed')">Complete</button>
            <button class="btn btn-secondary" onclick="showClearanceStatus('incomplete')">Incomplete</button>
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
                    <div><a href="?section=clearance" class="btn btn-back btn-sm">Clear</a></div>
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
            <div class="course-group" style="margin-bottom:12px;">
                <div class="course-header" style="background:#34495e; color:white; padding:6px 10px; font-size:11px;"><?php echo $course; ?> - Complete</div>
                <table class="plain-table">
                    <thead>
                        <tr><th>ID</th><th>Name</th><th>Year</th><th>Section</th><th>Status</th><th>Certificate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($c = $completed->fetch_assoc()): ?>
                        <tr data-name="<?php echo strtolower($c['chmsu_name']); ?>">
                            <td><code style="font-size:10px;"><?php echo $c['chmsu_student_id']; ?></code></td>
                            <td><?php echo $c['chmsu_name']; ?></td>
                            <td><?php echo $c['chmsu_year']; ?></td>
                            <td><?php echo $c['chmsu_section']; ?></td>
                            <td><span class="status-badge status-approved">Complete</span></td>
                            <td><a href="?print_certificate=<?php echo $c['chmsu_student_id']; ?>" target="_blank" class="btn btn-primary btn-sm">Print Certificate</a></td>
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
            <div class="course-group" style="margin-bottom:12px;">
                <div class="course-header" style="background:#e74c3c; color:white; padding:6px 10px; font-size:11px;"><?php echo $course; ?> - Incomplete</div>
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
                            <td><code style="font-size:10px;"><?php echo $i['chmsu_student_id']; ?></code></td>
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