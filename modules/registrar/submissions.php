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
            <li><a href="?section=requirements"><i class="fas fa-tasks"></i> Requirements</a></li>
            <li><a href="?section=submissions" class="active"><i class="fas fa-inbox"></i> Submissions</a></li>
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
            <h2>Student Submissions</h2>
            <span style="font-size: 11px;"><?php echo date('F d, Y'); ?></span>
        </div>
        
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success"><?php echo $success; ?></div><?php endif; ?>
        
        <div class="content-card">
            <div class="content-card-header">
                <span>Submissions</span>
            </div>
            <div class="content-card-body">
                <div class="filter-row">
                    <div class="form-group" style="flex:0 0 150px;">
                        <select id="courseFilter" onchange="filterSubmissionsByCourse()">
                            <option value="">All Courses</option>
                            <?php $courses = $conn->query("SELECT * FROM chmsu_courses"); while($c=$courses->fetch_assoc()): ?>
                            <option value="<?php echo $c['course_code']; ?>"><?php echo $c['course_code']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:0 0 120px;">
                        <select id="yearFilter" onchange="filterSubmissionsByYear()">
                            <option value="">All Years</option>
                            <option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:0 0 120px;">
                        <select id="sectionFilter" onchange="filterSubmissionsBySection()">
                            <option value="">All Sections</option>
                            <?php $sections = $conn->query("SELECT DISTINCT section_name FROM chmsu_course_sections"); while($s=$sections->fetch_assoc()): ?>
                            <option value="<?php echo $s['section_name']; ?>"><?php echo $s['section_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <input type="text" class="search-box" id="subSearch" placeholder="Search student..." onkeyup="searchSubmissions()" style="margin-bottom:0;">
                    </div>
                </div>
                
                <table class="plain-table" id="submissionsTable">
                    <thead>
                        <tr><th>Student</th><th>ID</th><th>Course</th><th>Year</th><th>Section</th><th>Requirement</th><th>Status</th><th>File</th><th>Comments</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $subs = $conn->query("SELECT s.*, u.chmsu_name, u.chmsu_course, u.chmsu_year, u.chmsu_section, r.chmsu_title, r.chmsu_id as req_id
                                              FROM chmsu_submissions s
                                              JOIN chmsu_user_accounts u ON s.chmsu_student_id=u.chmsu_student_id
                                              JOIN chmsu_requirements r ON s.chmsu_requirement_id=r.chmsu_id
                                              WHERE r.chmsu_office='Registrar'
                                              ORDER BY u.chmsu_course, u.chmsu_year, u.chmsu_section, u.chmsu_name");
                        while($sub=$subs->fetch_assoc()):
                            $unread_count = $conn->query("SELECT COUNT(*) as c FROM chmsu_comment_notifications 
                                                          WHERE requirement_id={$sub['req_id']} 
                                                          AND student_id='{$sub['chmsu_student_id']}'
                                                          AND office_name='Registrar'
                                                          AND is_read=0")->fetch_assoc()['c'];
                            
                            $total_count = $conn->query("SELECT COUNT(*) as c FROM chmsu_private_comments 
                                                          WHERE requirement_id={$sub['req_id']} 
                                                          AND student_id='{$sub['chmsu_student_id']}'")->fetch_assoc()['c'];
                        ?>
                        <tr data-name="<?php echo strtolower($sub['chmsu_name']); ?>" 
                            data-course="<?php echo $sub['chmsu_course']; ?>"
                            data-year="<?php echo $sub['chmsu_year']; ?>"
                            data-section="<?php echo $sub['chmsu_section']; ?>">
                            <td><?php echo $sub['chmsu_name']; ?></td>
                            <td><code style="font-size:10px;"><?php echo $sub['chmsu_student_id']; ?></code></td>
                            <td><?php echo $sub['chmsu_course']; ?></td>
                            <td><?php echo $sub['chmsu_year']; ?></td>
                            <td><?php echo $sub['chmsu_section']; ?></td>
                            <td><?php echo $sub['chmsu_title']; ?></td>
                            <td><span class="status-badge status-<?php echo strtolower($sub['chmsu_status']); ?>"><?php echo $sub['chmsu_status']; ?></span></td>
                            <td>
                                <?php if($sub['chmsu_file_path'] && file_exists($sub['chmsu_file_path'])): ?>
                                <button class="btn btn-primary btn-sm" onclick="openFullscreenViewer('<?php echo $sub['chmsu_file_path']; ?>','<?php echo $sub['chmsu_file_type']; ?>','<?php echo $sub['chmsu_file_name']; ?>')">View</button>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <div class="comment-icon" onclick="showConversation(<?php echo $sub['req_id']; ?>, '<?php echo $sub['chmsu_student_id']; ?>', '<?php echo $sub['chmsu_name']; ?>')">
                                    <i class="fas fa-comment" style="color: <?php echo $total_count > 0 ? '#1b4d3e' : '#999'; ?>; font-size: 16px;"></i>
                                    <?php if ($unread_count > 0): ?>
                                        <span class="comment-count"><?php echo $unread_count; ?></span>
                                    <?php elseif ($total_count > 0): ?>
                                        <span class="comment-count" style="background: #1b4d3e;"><?php echo $total_count; ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if($sub['chmsu_status']=='Submitted'): ?>
                                <div class="action-buttons">
                                    <button class="btn btn-success btn-sm" onclick="approveSubmission(<?php echo $sub['chmsu_id']; ?>)">Approve</button>
                                    <button class="btn btn-decline btn-sm" onclick="rejectSubmission(<?php echo $sub['chmsu_id']; ?>)">Decline</button>
                                    <button class="btn btn-primary btn-sm" onclick="showCommentModal(<?php echo $sub['req_id']; ?>, '<?php echo $sub['chmsu_student_id']; ?>')">Comment</button>
                                </div>
                                <?php elseif($sub['chmsu_status']!='Submitted'): ?>
                                <div class="action-buttons">
                                    <button class="btn btn-revert btn-sm" onclick="revertAction(<?php echo $sub['chmsu_id']; ?>)">Take Back</button>
                                    <button class="btn btn-primary btn-sm" onclick="showCommentModal(<?php echo $sub['req_id']; ?>, '<?php echo $sub['chmsu_student_id']; ?>')">Comment</button>
                                </div>
                                <?php endif; ?>
                             </td>
                         </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
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