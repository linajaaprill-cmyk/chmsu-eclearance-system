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
            <li><a href="?section=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?section=requirements" class="active"><i class="fas fa-tasks"></i> Requirements</a></li>
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
            <h2>Manage Requirements</h2>
            <span style="font-size: 11px;"><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success"><?php echo $success; ?></div><?php endif; ?>
        
        <div class="content-card">
            <div class="content-card-header">
                <span>Requirements</span>
                <button class="btn btn-primary btn-sm" onclick="toggleForm('add-req-form')"><i class="fas fa-plus"></i> Add</button>
            </div>
            <div class="content-card-body">
                <div id="add-req-form" class="hidden" style="background:#f8f9fa; padding:12px; margin-bottom:12px;">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add_requirement">
                        <div class="form-group"><label>Title</label><input type="text" name="title" required></div>
                        <div class="form-group"><label>Description</label><textarea name="description" style="min-height:50px;"></textarea></div>
                        <div class="filter-row">
                            <div class="form-group"><label>Course</label>
                                <select name="course" required>
                                    <option value="">Select</option>
                                    <?php $courses = $conn->query("SELECT * FROM chmsu_courses"); while($c=$courses->fetch_assoc()): ?>
                                    <option value="<?php echo $c['course_code']; ?>"><?php echo $c['course_code']; ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>Year</label>
                                <select name="year" required>
                                    <option value="">Select</option>
                                    <option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Section</label>
                                <select name="section" required>
                                    <option value="">Select</option>
                                    <?php $sections = $conn->query("SELECT * FROM chmsu_course_sections"); while($s=$sections->fetch_assoc()): ?>
                                    <option value="<?php echo $s['section_name']; ?>"><?php echo $s['section_name']; ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group"><label>Deadline</label><input type="datetime-local" name="deadline" required></div>
                        <div class="form-group"><label>File</label><input type="file" name="req_file"></div>
                        <button type="submit" class="btn btn-primary btn-sm">Add</button>
                        <button type="button" class="btn btn-back btn-sm" onclick="toggleForm('add-req-form')">Cancel</button>
                    </form>
                </div>
                
                <input type="text" class="search-box" id="reqSearch" placeholder="Search..." onkeyup="searchRequirements()">
                
                <table class="plain-table">
                    <thead>
                        <tr><th>Title</th><th>Course</th><th>Year</th><th>Section</th><th>Deadline</th><th>File</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $reqs = $conn->query("SELECT * FROM chmsu_requirements WHERE chmsu_office='Registrar' ORDER BY chmsu_course, chmsu_year, chmsu_section");
                        while($r=$reqs->fetch_assoc()):
                        ?>
                        <tr data-title="<?php echo strtolower($r['chmsu_title']); ?>">
                            <td><?php echo $r['chmsu_title']; ?></td>
                            <td><?php echo $r['chmsu_course']; ?></td>
                            <td><?php echo $r['chmsu_year']; ?></td>
                            <td><?php echo $r['chmsu_section']; ?></td>
                            <td><?php echo $r['chmsu_deadline'] ? date('M d, Y H:i',strtotime($r['chmsu_deadline'])) : ''; ?></td>
                            <td>
                                <?php if($r['chmsu_file_path'] && file_exists($r['chmsu_file_path'])): ?>
                                <button class="btn btn-primary btn-sm" onclick="openFullscreenViewer('<?php echo $r['chmsu_file_path']; ?>','<?php echo $r['chmsu_file_type']; ?>','<?php echo $r['chmsu_file_name']; ?>')">View</button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-primary btn-sm" onclick="editDeadline(<?php echo $r['chmsu_id']; ?>, '<?php echo $r['chmsu_deadline']; ?>')">Edit</button>
                                <button class="btn btn-danger btn-sm" onclick="confirmDeleteReq(<?php echo $r['chmsu_id']; ?>)">Delete</button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>