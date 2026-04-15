<?php
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$selectedOffice = isset($_GET['office']) ? $_GET['office'] : '';
$selectedReq = isset($_GET['req']) ? intval($_GET['req']) : 0;

$selectedRequirement = null;
$submission = null;
$comments = null;

if ($selectedReq > 0 && $selectedOffice) {
    $selectedRequirement = $conn->query("SELECT * FROM chmsu_requirements 
                                        WHERE chmsu_id=$selectedReq 
                                        AND chmsu_office='$selectedOffice'
                                        AND chmsu_course='{$student['chmsu_course']}' 
                                        AND chmsu_year='{$student['chmsu_year']}'
                                        AND chmsu_section='{$student['chmsu_section']}'")->fetch_assoc();
    
    if ($selectedRequirement) {
        $deadline_passed = $selectedRequirement['chmsu_deadline'] && strtotime($selectedRequirement['chmsu_deadline']) < time();
        $submission = $conn->query("SELECT * FROM chmsu_submissions 
                                   WHERE chmsu_requirement_id={$selectedRequirement['chmsu_id']} 
                                   AND chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
        
        $comments = $conn->query("SELECT * FROM chmsu_private_comments 
                                 WHERE requirement_id={$selectedRequirement['chmsu_id']} 
                                 AND student_id='{$_SESSION['student']}'
                                 ORDER BY created_at DESC");
        
        $conn->query("UPDATE chmsu_comment_notifications SET is_read=1 
                     WHERE requirement_id={$selectedRequirement['chmsu_id']} 
                     AND student_id='{$_SESSION['student']}'");
    }
}

$reqs = $conn->query("SELECT * FROM chmsu_requirements 
                     WHERE chmsu_office='$selectedOffice' 
                     AND chmsu_course='{$student['chmsu_course']}' 
                     AND chmsu_year='{$student['chmsu_year']}'
                     AND chmsu_section='{$student['chmsu_section']}'
                     ORDER BY chmsu_deadline ASC");
?>

<div class="header">
    <div class="header-logo">
        <img src="https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0" alt="CHMSU Logo">
    </div>
    <div class="header-title">
        <h1>CARLOS HILADO MEMORIAL STATE UNIVERSITY</h1>
        <p>CLEARANCE SYSTEM | Student Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="fas fa-moon"></i> Dark Mode
    </button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="dark-sidebar">
        <div class="sidebar-header">
            <h3><?php echo $master['chmsu_full_name']; ?></h3>
            <p><?php echo $student['chmsu_course']; ?> <?php echo $student['chmsu_year']; ?><?php echo $student['chmsu_section']; ?></p>
        </div>
        
        <ul class="sidebar-menu">
            <li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=home"><i class="fas fa-home"></i> Home</a></li>
            <li style="border-bottom: none; padding: 8px 12px; color: #dddddd; font-size: 10px;">OFFICES</li>
            <?php
            $offices_result = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
            while ($office_row = $offices_result->fetch_assoc()):
                $office = $office_row['office_name'];
                $statusQuery = $conn->query("SELECT s.chmsu_status FROM chmsu_submissions s JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id WHERE r.chmsu_office = '$office' AND s.chmsu_student_id = '{$_SESSION['student']}' ORDER BY s.chmsu_id DESC LIMIT 1");
                $statusClass = 'status-dot-pending';
                $nameClass = '';
                if ($statusQuery->num_rows > 0) {
                    $status = $statusQuery->fetch_assoc()['chmsu_status'];
                    if ($status == 'Approved') $statusClass = 'status-dot-approved';
                    elseif ($status == 'Declined') { $statusClass = 'status-dot-declined'; $nameClass = 'rejected-name'; }
                }
                $countQuery = $conn->query("SELECT COUNT(*) as c FROM chmsu_requirements WHERE chmsu_office='$office' AND chmsu_course='{$student['chmsu_course']}' AND chmsu_year='{$student['chmsu_year']}' AND chmsu_section='{$student['chmsu_section']}'");
                $count = $countQuery->fetch_assoc()['c'];
            ?>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=office&office=<?php echo urlencode($office); ?>" class="<?php echo ($selectedOffice == $office) ? 'active' : ''; ?> <?php echo $nameClass; ?>">
                    <span class="office-status-dot <?php echo $statusClass; ?>"></span>
                    <span><?php echo $office; ?></span>
                    <span class="office-count"><?php echo $count; ?></span>
                </a>
            </li>
            <?php endwhile; ?>
            <li style="margin-top: 15px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout(); return false;"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="info-bar">
            <div class="info-bar-item"><strong>ID:</strong> <?php echo $student['chmsu_student_id']; ?></div>
            <div class="info-bar-item"><strong>Name:</strong> <?php echo $master['chmsu_full_name']; ?></div>
            <div class="info-bar-item"><strong>Course/Year/Section:</strong> <?php echo $student['chmsu_course']; ?> <?php echo $student['chmsu_year']; ?><?php echo $student['chmsu_section']; ?></div>
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="font-size: 16px;"><?php echo $selectedOffice; ?> Requirements</h2>
            <button class="btn btn-back btn-sm" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>?view=home'">Back to Home</button>
        </div>
        
        <div class="requirements-container">
            <div class="requirements-list">
                <?php if ($reqs->num_rows == 0): ?>
                    <p style="font-size: 11px; color: #666;">No requirements from this office.</p>
                <?php else: ?>
                    <?php while ($req = $reqs->fetch_assoc()): 
                        $req_submission = $conn->query("SELECT * FROM chmsu_submissions WHERE chmsu_requirement_id={$req['chmsu_id']} AND chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
                        $is_active = ($selectedReq == $req['chmsu_id']);
                        $unreadComments = $conn->query("SELECT COUNT(*) as c FROM chmsu_comment_notifications WHERE requirement_id={$req['chmsu_id']} AND student_id='{$_SESSION['student']}' AND is_read=0")->fetch_assoc()['c'];
                    ?>
                    <div class="requirement-item <?php echo $is_active ? 'active' : ''; ?>" 
                         onclick="window.location.href='?view=office&office=<?php echo urlencode($selectedOffice); ?>&req=<?php echo $req['chmsu_id']; ?>'"
                         style="position: relative;">
                        <div class="requirement-header">
                            <div>
                                <div class="requirement-title"><?php echo $req['chmsu_title']; ?></div>
                                <div class="requirement-meta">
                                    <?php if ($req['chmsu_deadline']): ?>
                                        Due: <?php echo date('M d, Y H:i', strtotime($req['chmsu_deadline'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($req_submission): ?>
                                <span class="requirement-badge requirement-badge-<?php echo strtolower($req_submission['chmsu_status']); ?>">
                                    <?php echo $req_submission['chmsu_status']; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($req['chmsu_file_path'] && file_exists($req['chmsu_file_path'])): ?>
                        <div class="requirement-attachment" onclick="event.stopPropagation(); openFullscreenViewer('<?php echo $req['chmsu_file_path']; ?>', '<?php echo $req['chmsu_file_type']; ?>', '<?php echo $req['chmsu_file_name']; ?>')">
                            <i class="fas <?php echo getFileIcon($req['chmsu_file_type']); ?>" style="color: <?php echo getFileColor($req['chmsu_file_type']); ?>"></i>
                            <span><?php echo $req['chmsu_file_name']; ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($unreadComments > 0): ?>
                            <span class="notification-dot" style="position: absolute; top: 5px; right: 5px;"></span>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($selectedRequirement): ?>
            <div class="submission-panel">
                <div class="panel-header">
                    <h3><?php echo $selectedRequirement['chmsu_title']; ?></h3>
                    <div class="deadline">
                        <?php if ($selectedRequirement['chmsu_deadline']): ?>
                            <span class="deadline-box <?php echo $deadline_passed ? 'deadline-passed' : ''; ?>">
                                <i class="far fa-clock"></i> Due: <?php echo date('M d, Y H:i', strtotime($selectedRequirement['chmsu_deadline'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="panel-content">
                    <?php if ($selectedRequirement['chmsu_description']): ?>
                    <div style="background: #f8f9fa; padding: 8px; border-radius: 4px; margin-bottom: 12px; font-size: 11px;">
                        <?php echo nl2br($selectedRequirement['chmsu_description']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($selectedRequirement['chmsu_file_path'] && file_exists($selectedRequirement['chmsu_file_path'])): ?>
                    <div class="attachment-list">
                        <h4>Attachments</h4>
                        <div class="attachment-item" onclick="openFullscreenViewer('<?php echo $selectedRequirement['chmsu_file_path']; ?>', '<?php echo $selectedRequirement['chmsu_file_type']; ?>', '<?php echo $selectedRequirement['chmsu_file_name']; ?>')">
                            <div class="attachment-icon" style="color: <?php echo getFileColor($selectedRequirement['chmsu_file_type']); ?>">
                                <i class="fas <?php echo getFileIcon($selectedRequirement['chmsu_file_type']); ?>"></i>
                            </div>
                            <div>
                                <div class="attachment-name"><?php echo $selectedRequirement['chmsu_file_name']; ?></div>
                                <div class="attachment-size"><?php echo file_exists($selectedRequirement['chmsu_file_path']) ? formatFileSize(filesize($selectedRequirement['chmsu_file_path'])) : ''; ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($submission): ?>
                        <div class="submitted-work">
                            <h4>Your work</h4>
                            <?php if ($submission['chmsu_file_path'] && file_exists($submission['chmsu_file_path'])): ?>
                            <div class="submitted-file" onclick="openFullscreenViewer('<?php echo $submission['chmsu_file_path']; ?>', '<?php echo $submission['chmsu_file_type']; ?>', '<?php echo $submission['chmsu_file_name']; ?>')">
                                <i class="fas <?php echo getFileIcon($submission['chmsu_file_type']); ?>" style="color: <?php echo getFileColor($submission['chmsu_file_type']); ?>"></i>
                                <span><?php echo $submission['chmsu_file_name']; ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($submission['chmsu_link']): ?>
                            <a href="<?php echo $submission['chmsu_link']; ?>" target="_blank" class="submitted-link">
                                <i class="fas fa-link"></i>
                                <span><?php echo $submission['chmsu_link']; ?></span>
                            </a>
                            <?php endif; ?>
                            <?php if ($submission['chmsu_text']): ?>
                            <div class="submitted-text"><?php echo nl2br($submission['chmsu_text']); ?></div>
                            <?php endif; ?>
                            <div class="status-section">
                                <span class="status-badge status-<?php echo strtolower($submission['chmsu_status']); ?>">
                                    <?php echo $submission['chmsu_status']; ?>
                                </span>
                                <?php if ($submission['chmsu_status'] == 'Submitted' && !$deadline_passed): ?>
                                <button class="unsubmit-btn" onclick="confirmUnsubmit(<?php echo $submission['chmsu_id']; ?>)">Unsubmit</button>
                                <?php endif; ?>
                            </div>
                            <?php if ($submission['chmsu_status'] == 'Declined' && !empty($submission['chmsu_reject_reason'])): ?>
                            <div class="rejection-reason-box">
                                <strong><i class="fas fa-exclamation-triangle"></i> Reason for Declination:</strong>
                                <?php echo $submission['chmsu_reject_reason']; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php elseif (!$deadline_passed): ?>
                        <div>
                            <h4>Your work</h4>
                            <div class="submission-tabs">
                                <span class="submission-tab active" onclick="showSubmissionTab('file')">File</span>
                                <span class="submission-tab" onclick="showSubmissionTab('link')">Link</span>
                                <span class="submission-tab" onclick="showSubmissionTab('text')">Text</span>
                            </div>
                            <form method="POST" enctype="multipart/form-data" onsubmit="return validateSubmitForm()">
                                <input type="hidden" name="action" value="submit_req">
                                <input type="hidden" name="req_id" value="<?php echo $selectedRequirement['chmsu_id']; ?>">
                                <div id="file-tab" class="submission-tab-content">
                                    <div class="file-upload-area" onclick="document.getElementById('file-input').click()">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Click to upload</p>
                                        <p style="font-size: 8px; color: #999;">PDF, DOC, XLS, PPT, Images (Max 10MB)</p>
                                        <input type="file" id="file-input" name="file" style="display: none;" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" onchange="handleFileSelect(this)">
                                    </div>
                                    <div id="file-preview" class="file-preview"></div>
                                </div>
                                <div id="link-tab" class="submission-tab-content hidden">
                                    <div class="link-input"><input type="url" name="link" id="link-input" placeholder="https://example.com"></div>
                                </div>
                                <div id="text-tab" class="submission-tab-content hidden">
                                    <div class="text-input"><textarea name="text" id="text-input" placeholder="Type your submission here..."></textarea></div>
                                </div>
                                <button type="submit" class="submit-btn">Submit</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="deadline-box deadline-passed" style="padding: 8px; text-align: center;">
                            <i class="fas fa-lock"></i> Deadline passed
                        </div>
                    <?php endif; ?>
                    
                    <div class="comments-section" id="commentsSection">
                        <h4>
                            Private comments
                            <button class="comment-toggle-btn" onclick="toggleComments()">
                                <i class="fas fa-chevron-down"></i> Toggle Comments
                            </button>
                            <?php 
                            $unreadCount = $conn->query("SELECT COUNT(*) as c FROM chmsu_comment_notifications WHERE requirement_id={$selectedRequirement['chmsu_id']} AND student_id='{$_SESSION['student']}' AND is_read=0")->fetch_assoc()['c'];
                            if ($unreadCount > 0): 
                            ?>
                            <span class="comment-count" style="position: relative; top: auto; right: auto; display: inline-block; margin-left: 5px;"><?php echo $unreadCount; ?></span>
                            <?php endif; ?>
                        </h4>
                        <div class="comments-list">
                            <?php if ($comments && $comments->num_rows > 0): ?>
                                <?php while ($comment = $comments->fetch_assoc()): 
                                    $isUnread = $comment['is_read'] == 0;
                                ?>
                                <div class="comment-item <?php echo $isUnread ? 'unread' : ''; ?>">
                                    <?php if ($comment['created_by'] == 'office'): ?>
                                    <span class="comment-badge" style="display: inline-block; background: #1b4d3e; color: white; padding: 1px 4px; border-radius: 2px; font-size: 8px; margin-bottom: 3px;"><?php echo $selectedOffice; ?></span>
                                    <?php endif; ?>
                                    <div class="comment-header">
                                        <span class="comment-author"><?php echo $comment['created_by'] == 'student' ? 'You' : $selectedOffice; ?></span>
                                        <span class="comment-date"><?php echo date('M d, Y', strtotime($comment['created_at'])); ?></span>
                                    </div>
                                    <div class="comment-text"><?php echo nl2br($comment['comment']); ?></div>
                                </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <p style="font-size: 10px; color: #666; text-align: center; padding: 10px;">No comments yet</p>
                            <?php endif; ?>
                        </div>
                        <form method="POST" class="comment-input">
                            <input type="hidden" name="action" value="add_private_comment">
                            <input type="hidden" name="req_id" value="<?php echo $selectedRequirement['chmsu_id']; ?>">
                            <input type="text" name="comment" placeholder="Add a private comment..." required>
                            <button type="submit">Post</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>