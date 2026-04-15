<?php
$office = $_SESSION['office'];
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentOfficeSection = 'submissions';

// Get filter values
$filter_course = isset($_GET['filter_course']) ? $_GET['filter_course'] : '';
$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';
$filter_section = isset($_GET['filter_section']) ? $_GET['filter_section'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Check if Dean's office
$isDean = ($office == 'Dean');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Submissions</title>
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
        .header-title h1 { font-size: 20px; font-weight: normal; font-family: 'Times New Roman', Times, serif; }
        .header-title p { font-size: 11px; opacity: 0.8; margin-top: 3px; }
        .student-count-badge {
            background: rgba(255,255,255,0.15);
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
            margin-right: 15px;
            backdrop-filter: blur(5px);
        }
        .dark-mode-toggle {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 6px 12px;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
        }
        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 15px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }
        .office-sidebar {
            width: 240px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .office-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .office-sidebar .sidebar-menu { list-style: none; padding: 0; }
        .office-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .office-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
        }
        .office-sidebar .sidebar-menu a:hover,
        .office-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        
        .main-content {
            flex: 1;
            margin-left: 240px;
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
        
        .filter-row {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
            background: white;
            padding: 15px;
            border: 1px solid #ddd;
        }
        .filter-row .form-group { margin-bottom: 0; }
        .filter-row select, .filter-row input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
            min-width: 120px;
        }
        .btn-filter {
            background: #1b4d3e;
            color: white;
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
        }
        .btn-reset {
            background: #7f8c8d;
            color: white;
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            text-decoration: none;
        }
        
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .content-card-body { padding: 15px; overflow-x: auto; }
        
        .plain-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .plain-table th, .plain-table td {
            border: 1px solid #ddd;
            padding: 10px 8px;
            text-align: left;
            vertical-align: middle;
        }
        .plain-table th {
            background: #1b4d3e;
            color: white;
            font-weight: normal;
        }
        .plain-table tr:nth-child(even) { background: #fafafa; }
        .plain-table tr:hover { background: #f0f0f0; }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }
        .status-submitted { background: #3498db; color: white; }
        .status-approved { background: #27ae60; color: white; }
        .status-declined { background: #e74c3c; color: white; }
        .status-pending { background: #f39c12; color: white; }
        
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .btn-sm { padding: 4px 8px; font-size: 10px; border: none; cursor: pointer; border-radius: 3px; }
        .btn-success { background: #27ae60; color: white; }
        .btn-decline { background: #e74c3c; color: white; }
        .btn-revert { background: #95a5a6; color: white; }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-sm:hover { opacity: 0.8; }
        
        .comment-icon {
            position: relative;
            display: inline-flex;
            cursor: pointer;
            color: #1b4d3e;
        }
        .comment-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #e74c3c;
            color: white;
            border-radius: 10px;
            padding: 2px 5px;
            font-size: 9px;
            min-width: 18px;
            text-align: center;
        }
        
        .no-submission {
            color: #999;
            font-style: italic;
        }
        
        .info-tooltip {
            background: #f0f0f0;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 11px;
            color: #666;
            margin-bottom: 15px;
            display: inline-block;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            max-width: 500px;
            width: 90%;
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .office-sidebar { width: 100%; position: relative; height: auto; }
            .main-content { margin-left: 0; }
            .plain-table { font-size: 10px; }
            .plain-table th, .plain-table td { padding: 5px; }
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
    <div class="student-count-badge" id="studentCountBadge">
        <i class="fas fa-users"></i>
        <span id="studentCount">Loading...</span>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?officesection=dashboard">Dashboard</a></li>
            <li><a href="?officesection=requirements">Requirements</a></li>
            <li><a href="?officesection=submissions" class="active">Submissions</a></li>
            <li><a href="?officesection=reports">Reports</a></li>
            <li><a href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
        <div class="storage-info" style="padding: 12px; font-size: 10px; color: #dddddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2><?php echo htmlspecialchars($office); ?> - Student Submissions</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($isDean): ?>
        <div class="info-tooltip">
            <i class="fas fa-info-circle"></i> Note: Actions for Dean's office are only available for students already approved by OSA.
        </div>
        <?php endif; ?>
        
        <!-- Filter Form -->
        <form method="GET" class="filter-row">
            <input type="hidden" name="officesection" value="submissions">
            <div class="form-group">
                <select name="filter_course">
                    <option value="">All Courses</option>
                    <?php $courses = $conn->query("SELECT * FROM chmsu_courses ORDER BY course_code"); while($c=$courses->fetch_assoc()): ?>
                    <option value="<?php echo $c['course_code']; ?>" <?php echo $filter_course==$c['course_code']?'selected':''; ?>><?php echo $c['course_code']; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <select name="filter_year">
                    <option value="">All Years</option>
                    <option value="1" <?php echo $filter_year=='1'?'selected':''; ?>>1st Year</option>
                    <option value="2" <?php echo $filter_year=='2'?'selected':''; ?>>2nd Year</option>
                    <option value="3" <?php echo $filter_year=='3'?'selected':''; ?>>3rd Year</option>
                    <option value="4" <?php echo $filter_year=='4'?'selected':''; ?>>4th Year</option>
                </select>
            </div>
            <div class="form-group">
                <select name="filter_section">
                    <option value="">All Sections</option>
                    <?php $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections ORDER BY section_name"); while($s=$sections->fetch_assoc()): ?>
                    <option value="<?php echo $s['section_name']; ?>" <?php echo $filter_section==$s['section_name']?'selected':''; ?>><?php echo $s['section_name']; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <input type="text" name="search" placeholder="Search student name..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <button type="submit" class="btn-filter">Filter</button>
            <a href="?officesection=submissions" class="btn-reset">Reset</a>
        </form>
        
        <div class="content-card">
            <div class="content-card-header">
                <span>Student Submissions</span>
                <span id="tableStudentCount"></span>
            </div>
            <div class="content-card-body">
                <div class="table-responsive">
                    <table class="plain-table" id="submissionsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student ID</th>
                                <th>Last Name</th>
                                <th>First Name</th>
                                <th>Course</th>
                                <th>Year</th>
                                <th>Section</th>
                                <th>Requirement</th>
                                <th>Status</th>
                                <th>Submission</th>
                                <th>Comments</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Build query to get ALL students from masterlist
                            $where = [];
                            if($filter_course) $where[] = "m.chmsu_course='$filter_course'";
                            if($filter_year) $where[] = "m.chmsu_year='$filter_year'";
                            if($filter_section) $where[] = "m.chmsu_section='$filter_section'";
                            if($search) $where[] = "(m.chmsu_last_name LIKE '%$search%' OR m.chmsu_first_name LIKE '%$search%' OR m.chmsu_full_name LIKE '%$search%')";
                            
                            $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
                            
                            // Get all students from masterlist
                            $students = $conn->query("SELECT m.* FROM chmsu_students_master m $where_sql ORDER BY m.chmsu_last_name ASC, m.chmsu_first_name ASC");
                            
                            $totalStudents = $students->num_rows;
                            $counter = 1;
                            
                            // Get the requirement for this office
                            $requirement = $conn->query("SELECT * FROM chmsu_requirements WHERE chmsu_office='$office' LIMIT 1")->fetch_assoc();
                            $requirement_id = $requirement ? $requirement['chmsu_id'] : null;
                            
                            // For Dean: Get students approved by OSA
                            $osaApprovedStudents = [];
                            if ($isDean && $requirement_id) {
                                $osaCheck = $conn->query("SELECT DISTINCT s.chmsu_student_id 
                                                          FROM chmsu_submissions s 
                                                          JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id 
                                                          WHERE r.chmsu_office = 'OSA' AND s.chmsu_status = 'Approved'");
                                while($row = $osaCheck->fetch_assoc()) {
                                    $osaApprovedStudents[] = $row['chmsu_student_id'];
                                }
                            }
                            
                            if($students->num_rows > 0):
                                while($student = $students->fetch_assoc()):
                                    $student_id = $student['chmsu_student_id'];
                                    
                                    // Get submission for this student
                                    $submission = null;
                                    if($requirement_id) {
                                        $subQuery = $conn->query("SELECT * FROM chmsu_submissions 
                                                                  WHERE chmsu_requirement_id=$requirement_id 
                                                                  AND chmsu_student_id='$student_id'");
                                        $submission = $subQuery->fetch_assoc();
                                    }
                                    
                                    $hasSubmitted = ($submission && $submission['chmsu_status'] != '');
                                    $status = $submission ? $submission['chmsu_status'] : 'Not Submitted';
                                    $statusClass = $submission ? 'status-' . strtolower($submission['chmsu_status']) : 'status-pending';
                                    
                                    // Check if actions should be shown
                                    $showActions = false;
                                    if($hasSubmitted && $submission['chmsu_status'] == 'Submitted') {
                                        if($isDean) {
                                            if(in_array($student_id, $osaApprovedStudents)) {
                                                $showActions = true;
                                            }
                                        } else {
                                            $showActions = true;
                                        }
                                    }
                                    
                                    // Get comment counts (always show comment icon for all students)
                                    $unread_count = 0;
                                    $total_count = 0;
                                    if($requirement_id) {
                                        $unread = $conn->query("SELECT COUNT(*) as c FROM chmsu_comment_notifications 
                                                                WHERE requirement_id=$requirement_id 
                                                                AND student_id='$student_id'
                                                                AND office_name='$office'
                                                                AND is_read=0");
                                        $unread_count = $unread->fetch_assoc()['c'];
                                        
                                        $total = $conn->query("SELECT COUNT(*) as c FROM chmsu_private_comments 
                                                              WHERE requirement_id=$requirement_id 
                                                              AND student_id='$student_id'");
                                        $total_count = $total->fetch_assoc()['c'];
                                    }
                            ?>
                            <tr data-student-id="<?php echo $student_id; ?>">
                                <td><?php echo $counter++; ?></td>
                                <td><code><?php echo $student_id; ?></code></td>
                                <td><?php echo htmlspecialchars($student['chmsu_last_name']); ?></td>
                                <td><?php echo htmlspecialchars($student['chmsu_first_name']); ?></td>
                                <td><?php echo $student['chmsu_course']; ?></td>
                                <td><?php echo $student['chmsu_year']; ?></td>
                                <td><?php echo htmlspecialchars($student['chmsu_section']); ?></td>
                                <td><?php echo $requirement ? htmlspecialchars($requirement['chmsu_title']) : 'No requirement'; ?></td>
                                <td>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo $status; ?>
                                    </span>
                                    <?php if($submission && $submission['chmsu_status'] == 'Declined' && !empty($submission['chmsu_reject_reason'])): ?>
                                        <div style="font-size:9px; margin-top:4px; color:#e74c3c;">
                                            Reason: <?php echo htmlspecialchars($submission['chmsu_reject_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                 </td>
                                 <td>
                                    <?php if($submission && $submission['chmsu_file_path'] && file_exists($submission['chmsu_file_path'])): ?>
                                        <button class="btn-sm btn-primary" onclick="openFullscreenViewer('<?php echo $submission['chmsu_file_path']; ?>','<?php echo $submission['chmsu_file_type']; ?>','<?php echo $submission['chmsu_file_name']; ?>')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    <?php elseif($submission && $submission['chmsu_link']): ?>
                                        <a href="<?php echo $submission['chmsu_link']; ?>" target="_blank" class="btn-sm btn-primary" style="text-decoration:none;">
                                            <i class="fas fa-link"></i> Link
                                        </a>
                                    <?php elseif($submission && $submission['chmsu_text']): ?>
                                        <button class="btn-sm btn-primary" onclick="alert('<?php echo addslashes($submission['chmsu_text']); ?>')">
                                            <i class="fas fa-file-alt"></i> View Text
                                        </button>
                                    <?php else: ?>
                                        <span class="no-submission">No submission</span>
                                    <?php endif; ?>
                                 </td>
                                <td style="text-align: center;">
                                    <?php if($requirement_id): ?>
                                    <div class="comment-icon" onclick="showConversation(<?php echo $requirement_id; ?>, '<?php echo $student_id; ?>', '<?php echo addslashes($student['chmsu_last_name'] . ', ' . $student['chmsu_first_name']); ?>')">
                                        <i class="fas fa-comment" style="color: <?php echo $total_count > 0 ? '#1b4d3e' : '#999'; ?>; font-size: 16px;"></i>
                                        <?php if ($unread_count > 0): ?>
                                            <span class="comment-count"><?php echo $unread_count; ?></span>
                                        <?php elseif ($total_count > 0): ?>
                                            <span class="comment-count" style="background: #1b4d3e;"><?php echo $total_count; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                        <span class="no-submission">No requirement</span>
                                    <?php endif; ?>
                                 </td>
                                 <td>
                                    <?php if($showActions && $requirement_id): ?>
                                        <div class="action-buttons">
                                            <button class="btn-sm btn-success" onclick="approveSubmission(<?php echo $submission['chmsu_id']; ?>)">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button class="btn-sm btn-decline" onclick="rejectSubmission(<?php echo $submission['chmsu_id']; ?>)">
                                                <i class="fas fa-times"></i> Decline
                                            </button>
                                            <button class="btn-sm btn-primary" onclick="showCommentModal(<?php echo $requirement_id; ?>, '<?php echo $student_id; ?>')">
                                                <i class="fas fa-comment"></i> Comment
                                            </button>
                                        </div>
                                    <?php elseif($hasSubmitted && $submission && $submission['chmsu_status'] != 'Submitted'): ?>
                                        <div class="action-buttons">
                                            <button class="btn-sm btn-revert" onclick="revertAction(<?php echo $submission['chmsu_id']; ?>)">
                                                <i class="fas fa-undo"></i> Take Back
                                            </button>
                                            <button class="btn-sm btn-primary" onclick="showCommentModal(<?php echo $requirement_id; ?>, '<?php echo $student_id; ?>')">
                                                <i class="fas fa-comment"></i> Comment
                                            </button>
                                        </div>
                                    <?php elseif($requirement_id): ?>
                                        <span class="no-submission">Waiting for submission</span>
                                    <?php else: ?>
                                        <span class="no-submission">No requirement</span>
                                    <?php endif; ?>
                                 </td>
                             </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                             <tr>
                                <td colspan="12" style="text-align:center;">No students found</td>
                             </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Conversation Modal -->
<div class="modal-overlay" id="conversationModal">
    <div class="modal-content">
        <h4 id="conversationTitle">Conversation</h4>
        <div id="conversationComments" style="max-height:300px; overflow-y:auto; margin-bottom:15px;"></div>
        <div style="display:flex; gap:5px;">
            <input type="text" id="conversationComment" placeholder="Type your reply..." style="flex:1; padding:8px;">
            <button class="btn-sm btn-primary" onclick="sendReply()">Send</button>
        </div>
        <div style="margin-top:15px; text-align:right;">
            <button class="btn-sm btn-back" onclick="closeConversationModal()">Close</button>
        </div>
    </div>
</div>

<!-- Comment Modal -->
<div class="modal-overlay" id="commentModal">
    <div class="modal-content">
        <h4>Add Private Comment</h4>
        <form method="POST" id="commentForm">
            <input type="hidden" name="action" value="add_office_private_comment">
            <input type="hidden" name="req_id" id="commentReqId">
            <input type="hidden" name="student_id" id="commentStudentId">
            <textarea name="comment" style="width:100%; min-height:80px; margin:10px 0; padding:8px;" placeholder="Enter comment..." required></textarea>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn-sm btn-back" onclick="closeCommentModal()">Cancel</button>
                <button type="submit" class="btn-sm btn-primary">Post</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentReqId = null;
    let currentStudentId = null;
    
    // Update student count in header
    function updateStudentCount() {
        const rows = document.querySelectorAll('#submissionsTable tbody tr:not(:has(td[colspan]))');
        const rowCount = rows.length;
        const countSpan = document.getElementById('studentCount');
        const tableCountSpan = document.getElementById('tableStudentCount');
        if (countSpan) countSpan.textContent = rowCount + ' students';
        if (tableCountSpan) tableCountSpan.textContent = 'Total: ' + rowCount + ' students';
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        updateStudentCount();
    });
    
    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        const isDarkMode = document.body.classList.contains('dark-mode');
        localStorage.setItem('darkMode', isDarkMode);
    }
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
    }
    
    function confirmLogout() {
        if(confirm('Are you sure you want to logout?')) {
            window.location.href = '?logout=1';
        }
    }
    
    function openFullscreenViewer(filePath, fileType, fileName) {
        window.open(filePath, '_blank');
    }
    
    function showConversation(reqId, studentId, studentName) {
        currentReqId = reqId;
        currentStudentId = studentId;
        document.getElementById('conversationTitle').textContent = 'Conversation with ' + studentName;
        
        fetch(`?get_comments=1&req_id=${reqId}&student_id=${studentId}`)
            .then(response => response.json())
            .then(comments => {
                const container = document.getElementById('conversationComments');
                container.innerHTML = '';
                if (comments.length === 0) {
                    container.innerHTML = '<p style="text-align:center; color:#666;">No comments yet.</p>';
                } else {
                    comments.forEach(comment => {
                        const div = document.createElement('div');
                        div.style.padding = '8px';
                        div.style.marginBottom = '8px';
                        div.style.background = '#f8f9fa';
                        div.style.borderLeft = '3px solid #1b4d3e';
                        div.innerHTML = `
                            <div style="display:flex; justify-content:space-between;">
                                <strong>${comment.created_by_display || (comment.created_by == 'office' ? 'Office' : 'Student')}</strong>
                                <small>${comment.date}</small>
                            </div>
                            <div style="margin-top:5px;">${comment.comment}</div>
                        `;
                        container.appendChild(div);
                    });
                }
                
                // Mark as read
                const formData = new FormData();
                formData.append('action', 'mark_comments_read');
                formData.append('req_id', reqId);
                formData.append('student_id', studentId);
                fetch('', { method: 'POST', body: formData });
            });
        document.getElementById('conversationModal').style.display = 'flex';
    }
    
    function sendReply() {
        const comment = document.getElementById('conversationComment').value;
        if (!comment.trim()) return;
        const formData = new FormData();
        formData.append('action', 'add_office_private_comment');
        formData.append('req_id', currentReqId);
        formData.append('student_id', currentStudentId);
        formData.append('comment', comment);
        fetch('', { method: 'POST', body: formData }).then(() => {
            document.getElementById('conversationComment').value = '';
            showConversation(currentReqId, currentStudentId, '');
        });
    }
    
    function closeConversationModal() {
        document.getElementById('conversationModal').style.display = 'none';
    }
    
    function showCommentModal(reqId, studentId) {
        document.getElementById('commentReqId').value = reqId;
        document.getElementById('commentStudentId').value = studentId;
        document.getElementById('commentModal').style.display = 'flex';
    }
    
    function closeCommentModal() {
        document.getElementById('commentModal').style.display = 'none';
    }
    
    function approveSubmission(subId) {
        if(confirm('Approve this submission?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="approve"><input type="hidden" name="sub_id" value="${subId}">`;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function rejectSubmission(subId) {
        const reason = prompt('Reason for declination:');
        if(reason && reason.trim()) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="reject"><input type="hidden" name="sub_id" value="${subId}"><input type="hidden" name="reason" value="${reason}">`;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function revertAction(subId) {
        if(confirm('Revert this action? Submission will become pending.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="revert_action"><input type="hidden" name="sub_id" value="${subId}">`;
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

</body>
</html>