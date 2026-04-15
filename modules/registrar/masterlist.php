<?php
$filter_course = isset($_GET['filter_course']) ? $_GET['filter_course'] : '';
$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';
$filter_section = isset($_GET['filter_section']) ? $_GET['filter_section'] : '';
?>

<div class="header">
    <div class="header-logo" style="display: none;"></div>
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
            <li><a href="?section=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?section=masterlist" class="active"><i class="fas fa-users"></i> Master List</a></li>
            <li><a href="?section=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
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
            <h2>Master Student List</h2>
            <span style="font-size: 11px;"><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="content-card">
            <div class="content-card-header">
                <span>All Students</span>
                <div style="display: flex; gap: 5px;">
                    <button class="btn btn-primary btn-sm" onclick="toggleForm('import-student-form')"><i class="fas fa-upload"></i> Import Excel</button>
                    <button class="btn btn-primary btn-sm" onclick="toggleForm('add-student-form')"><i class="fas fa-plus"></i> Add Student</button>
                </div>
            </div>
            <div class="content-card-body">
                <div id="import-student-form" class="hidden" style="background:#f8f9fa; padding:12px; margin-bottom:12px;">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import_students">
                        <div style="display: flex; gap: 10px; align-items: flex-end;">
                            <div class="form-group" style="flex: 1;">
                                <label>Excel File (.xls, .xlsx, .csv)</label>
                                <input type="file" name="excel_file" accept=".xls,.xlsx,.csv" required>
                                <small>Format: Last Name, First Name, Middle Name, Birthdate (YYYY-MM-DD), Course, Year, Section</small>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Import</button>
                            <button type="button" class="btn btn-back btn-sm" onclick="toggleForm('import-student-form')">Cancel</button>
                        </div>
                    </form>
                </div>
                
                <div id="add-student-form" class="hidden" style="background:#f8f9fa; padding:12px; margin-bottom:12px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_master">
                        <div class="filter-row">
                            <div class="form-group"><label>Last Name</label><input type="text" name="last_name" id="lastName" required oninput="updateGeneratedID()"></div>
                            <div class="form-group"><label>First Name</label><input type="text" name="first_name" id="firstName" required oninput="updateGeneratedID()"></div>
                            <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name" id="middleName" required oninput="updateGeneratedID()"></div>
                        </div>
                        <div class="filter-row">
                            <div class="form-group"><label>Birthdate</label><input type="date" name="birthdate" id="birthdate" required onchange="updateGeneratedID()"></div>
                            <div class="form-group"><label>Generated ID</label><div id="generatedID" style="background:#1b4d3e; color:white; padding:6px; border-radius:4px; text-align:center; font-size:11px;">---</div></div>
                        </div>
                        <div class="filter-row">
                            <div class="form-group">
                                <label>Course</label>
                                <select name="course" required>
                                    <option value="">Select Course</option>
                                    <?php $courses = $conn->query("SELECT * FROM chmsu_courses"); while($c=$courses->fetch_assoc()): ?>
                                    <option value="<?php echo $c['course_code']; ?>"><?php echo $c['course_code']; ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Year</label>
                                <select name="year" required>
                                    <option value="">Select Year</option>
                                    <option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Section</label>
                                <select name="section" required>
                                    <option value="">Select Section</option>
                                    <?php $sections = $conn->query("SELECT * FROM chmsu_course_sections"); while($s=$sections->fetch_assoc()): ?>
                                    <option value="<?php echo $s['section_name']; ?>"><?php echo $s['section_name']; ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Add Student</button>
                        <button type="button" class="btn btn-back btn-sm" onclick="toggleForm('add-student-form')">Cancel</button>
                    </form>
                </div>
                
                <form method="GET">
                    <input type="hidden" name="section" value="masterlist">
                    <div class="filter-row">
                        <div class="form-group">
                            <select name="filter_course" onchange="this.form.submit()">
                                <option value="">All Courses</option>
                                <?php $courses = $conn->query("SELECT * FROM chmsu_courses"); while($c=$courses->fetch_assoc()): ?>
                                <option value="<?php echo $c['course_code']; ?>" <?php echo $filter_course==$c['course_code']?'selected':''; ?>><?php echo $c['course_code']; ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <select name="filter_year" onchange="this.form.submit()">
                                <option value="">All Years</option>
                                <option value="1" <?php echo $filter_year=='1'?'selected':''; ?>>1st</option>
                                <option value="2" <?php echo $filter_year=='2'?'selected':''; ?>>2nd</option>
                                <option value="3" <?php echo $filter_year=='3'?'selected':''; ?>>3rd</option>
                                <option value="4" <?php echo $filter_year=='4'?'selected':''; ?>>4th</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <select name="filter_section" onchange="this.form.submit()">
                                <option value="">All Sections</option>
                                <?php $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections"); while($s=$sections->fetch_assoc()): ?>
                                <option value="<?php echo $s['section_name']; ?>" <?php echo $filter_section==$s['section_name']?'selected':''; ?>><?php echo $s['section_name']; ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div><a href="?section=masterlist" class="btn btn-back btn-sm">Clear Filters</a></div>
                    </div>
                </form>
                
                <div class="export-buttons">
                    <a href="?export=excel&course=<?php echo $filter_course; ?>&year=<?php echo $filter_year; ?>&section=<?php echo $filter_section; ?>" class="btn btn-excel">Export Excel</a>
                    <a href="?export=word&course=<?php echo $filter_course; ?>&year=<?php echo $filter_year; ?>&section=<?php echo $filter_section; ?>" class="btn btn-word">Export Word</a>
                </div>
                
                <input type="text" class="search-box" id="masterSearch" placeholder="Search student by name..." onkeyup="searchMasterList()">
                
                <div class="table-responsive">
                    <table class="plain-table" id="masterTable">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Last Name</th>
                                <th>First Name</th>
                                <th>Middle Name</th>
                                <th>Course</th>
                                <th>Year</th>
                                <th>Section</th>
                                <th>Birthdate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $where = array();
                            if($filter_course) $where[] = "chmsu_course='$filter_course'";
                            if($filter_year) $where[] = "chmsu_year='$filter_year'";
                            if($filter_section) $where[] = "chmsu_section='$filter_section'";
                            $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
                            $students = $conn->query("SELECT * FROM chmsu_students_master $where_sql ORDER BY chmsu_course, chmsu_year, chmsu_section, chmsu_last_name");
                            if($students->num_rows > 0):
                                while($s = $students->fetch_assoc()):
                            ?>
                            <tr data-name="<?php echo strtolower($s['chmsu_full_name']); ?>">
                                <td><code><?php echo $s['chmsu_student_id']; ?></code></td>
                                <td><?php echo htmlspecialchars($s['chmsu_last_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['chmsu_first_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['chmsu_middle_name']); ?></td>
                                <td><?php echo $s['chmsu_course']; ?></td>
                                <td><?php echo $s['chmsu_year']; ?></td>
                                <td><?php echo htmlspecialchars($s['chmsu_section']); ?></td>
                                <td><?php echo date('F d, Y', strtotime($s['chmsu_birthdate'])); ?></td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="8" style="text-align: center;">No students found</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>