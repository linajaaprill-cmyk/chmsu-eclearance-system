<?php
$office = $_SESSION['office'];
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);
$currentOfficeSection = 'submissions';
?>

<div class="header">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | <?php echo strtoupper($office); ?> Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3><?php echo htmlspecialchars($office); ?> Portal</h3>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?officesection=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?officesection=requirements"><i class="fas fa-tasks"></i> Requirements</a></li>
            <li><a href="?officesection=submissions" class="active"><i class="fas fa-inbox"></i> Submissions</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;"><a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
        <div class="storage-info">
            <i class="fas fa-database"></i> Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px;">
                <div style="width:<?php echo $storage_percent; ?>%; height:100%; background:#f1c40f;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2><?php echo htmlspecialchars($office); ?> - Submissions</h2>
            <span style="font-size:11px;"><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success"><?php echo $success; ?></div><?php endif; ?>
        
        <div class="content-card">
            <div class="content-card-header">
                <span><i class="fas fa-inbox"></i> Student Submissions</span>
            </div>
            <div class="content-card-body">
                <div class="filter-row">
                    <div class="form-group" style="flex:0 0 150px;">
                        <select id="courseFilterOffice" onchange="filterOfficeSubmissionsByCourse()">
                            <option value="">All Courses</option>
                            <?php $courses = $conn->query("SELECT * FROM chmsu_courses ORDER BY course_code"); while($c=$courses->fetch_assoc()): ?>
                            <option value="<?php echo $c['course_code']; ?>"><?php echo $c['course_code']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:0 0 120px;">
                        <select id="yearFilterOffice" onchange="filterOfficeSubmissionsByYear()">
                            <option value="">All Years</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:0 0 120px;">
                        <select id="sectionFilterOffice" onchange="filterOfficeSubmissionsBySection()">
                            <option value="">All Sections</option>
                            <?php $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections ORDER BY section_name"); while($s=$sections->fetch_assoc()): ?>
                            <option value="<?php echo $s['section_name']; ?>"><?php echo $s['section_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <input type="text" class="search-box" id="subSearchOffice" placeholder="Search student by name..." onkeyup="searchOfficeSubmissions()" style="margin-bottom:0;">
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="plain-table" id="officeSubmissionsTable">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>ID</th>
                                <th>Course</th>
                                <th>Year</th>
                                <th>Section</th>
                                <th>Requirement</th>
                                <th>Status</th>
                                <th>File</th>
                                <th>Comments</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $subs = $conn->query("SELECT s.*, u.chmsu_name, u.chmsu_course, u.chmsu_year, u.chmsu_section, r.chmsu_title, r.chmsu_id as req_id
                                                  FROM chmsu_submissions s
                                                  JOIN chmsu_user_accounts u ON s.chmsu_student_id=u.chmsu_student_id
                                                  JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id
                                                  WHERE r.chmsu_office='$office'
                                                  ORDER BY u.chmsu_course, u.chmsu_year, u.chmsu_section, u.chmsu_name");
                            if ($subs->num_rows > 0):
                                while($sub=$subs->fetch_assoc()):
                                    $unread_count = $conn->query("SELECT COUNT(*) as c FROM chmsu_comment_notifications 
                                                                  WHERE requirement_id={$sub['req_id']} 
                                                                  AND student_id='{$sub['chmsu_student_id']}'
                                                                  AND office_name='$office'
                                                                  AND is_read=0")->fetch_assoc()['c'];
                                    
                                    $total_count = $conn->query("SELECT COUNT(*) as c FROM chmsu_private_comments 
                                                                  WHERE requirement_id={$sub['req_id']} 
                                                                  AND student_id='{$sub['chmsu_student_id']}'")->fetch_assoc()['c'];
                            ?>
                            <tr data-name="<?php echo strtolower($sub['chmsu_name']); ?>" 
                                data-course="<?php echo $sub['chmsu_course']; ?>"
                                data-year="<?php echo $sub['chmsu_year']; ?>"
                                data-section="<?php echo $sub['chmsu_section']; ?>">
                                <td><?php echo htmlspecialchars($sub['chmsu_name']); ?>On
                                <td><code style="font-size:10px;"><?php echo $sub['chmsu_student_id']; ?></code>On
                                <td><?php echo $sub['chmsu_course']; ?>On
                                <td><?php echo $sub['chmsu_year']; ?>On
                                <td><?php echo htmlspecialchars($sub['chmsu_section']); ?>On
                                <td><?php echo htmlspecialchars($sub['chmsu_title']); ?>On
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($sub['chmsu_status']); ?>">
                                        <?php echo $sub['chmsu_status']; ?>
                                    </span>
                                  On
                                <td>
                                    <?php if($sub['chmsu_file_path'] && file_exists($sub['chmsu_file_path'])): ?>
                                    <button class="btn btn-primary btn-sm" onclick="openFullscreenViewer('<?php echo $sub['chmsu_file_path']; ?>','<?php echo $sub['chmsu_file_type']; ?>','<?php echo $sub['chmsu_file_name']; ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php else: ?>
                                        <span class="hint-text">No file</span>
                                    <?php endif; ?>
                                  On
                                <td style="text-align: center;">
                                    <div class="comment-icon" onclick="showConversation(<?php echo $sub['req_id']; ?>, '<?php echo $sub['chmsu_student_id']; ?>', '<?php echo addslashes($sub['chmsu_name']); ?>')">
                                        <i class="fas fa-comment" style="color: <?php echo $total_count > 0 ? '#1b4d3e' : '#999'; ?>; font-size: 16px;"></i>
                                        <?php if ($unread_count > 0): ?>
                                            <span class="comment-count"><?php echo $unread_count; ?></span>
                                        <?php elseif ($total_count > 0): ?>
                                            <span class="comment-count" style="background: #1b4d3e;"><?php echo $total_count; ?></span>
                                        <?php endif; ?>
                                    </div>
                                  On
                                 <td>
                                    <?php if($sub['chmsu_status']=='Submitted'): ?>
                                    <div class="action-buttons">
                                        <button class="btn btn-success btn-sm" onclick="approveSubmission(<?php echo $sub['chmsu_id']; ?>)">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button class="btn btn-decline btn-sm" onclick="rejectSubmission(<?php echo $sub['chmsu_id']; ?>)">
                                            <i class="fas fa-times"></i> Decline
                                        </button>
                                        <button class="btn btn-primary btn-sm" onclick="showCommentModal(<?php echo $sub['req_id']; ?>, '<?php echo $sub['chmsu_student_id']; ?>')">
                                            <i class="fas fa-comment"></i> Comment
                                        </button>
                                    </div>
                                    <?php elseif($sub['chmsu_status']!='Submitted'): ?>
                                    <div class="action-buttons">
                                        <button class="btn btn-revert btn-sm" onclick="revertAction(<?php echo $sub['chmsu_id']; ?>)">
                                            <i class="fas fa-undo"></i> Take Back
                                        </button>
                                        <button class="btn btn-primary btn-sm" onclick="showCommentModal(<?php echo $sub['req_id']; ?>, '<?php echo $sub['chmsu_student_id']; ?>')">
                                            <i class="fas fa-comment"></i> Comment
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                  On
                              ?
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="10" style="text-align:center;">No submissions found. On
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
    <div class="modal">
        <h4 id="conversationTitle">Conversation</h4>
        <div id="conversationComments" class="comments-list" style="max-height: 300px; overflow-y: auto; margin-bottom: 15px;"></div>
        <div class="comment-input" style="display: flex; gap: 5px;">
            <input type="text" id="conversationComment" placeholder="Type your reply..." style="flex: 1;">
            <button class="btn btn-primary btn-sm" onclick="sendReply()">Send</button>
        </div>
        <div class="modal-buttons">
            <button class="btn btn-back btn-sm" onclick="closeConversationModal()">Close</button>
        </div>
    </div>
</div>

<!-- Comment Modal -->
<div class="modal-overlay" id="commentModal">
    <div class="modal">
        <h4>Add Private Comment</h4>
        <form method="POST" id="commentForm">
            <input type="hidden" name="action" value="add_office_private_comment">
            <input type="hidden" name="req_id" id="commentReqId">
            <input type="hidden" name="student_id" id="commentStudentId">
            <textarea name="comment" style="width:100%; min-height:60px; margin:8px 0; padding:6px;" placeholder="Enter comment..." required></textarea>
            <div class="modal-buttons">
                <button type="button" class="btn btn-back btn-sm" onclick="closeCommentModal()">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Post</button>
            </div>
        </form>
    </div>
</div>