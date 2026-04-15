<?php
$student = $conn->query("SELECT * FROM chmsu_user_accounts WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$master = $conn->query("SELECT * FROM chmsu_students_master WHERE chmsu_student_id='{$_SESSION['student']}'")->fetch_assoc();
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

// Get all offices with their statuses
$offices_result = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
$offices = [];
$officeStatuses = [];
$officeCounts = [];
$officeUnreadComments = [];
$totalOffices = 0;
$approvedCount = 0;

while ($office_row = $offices_result->fetch_assoc()) {
    $office = $office_row['office_name'];
    $offices[] = $office;
    $totalOffices++;
    
    // Count requirements for this office
    $countQuery = $conn->query("SELECT COUNT(*) as c FROM chmsu_requirements 
                                WHERE chmsu_office='$office' 
                                AND chmsu_course='{$student['chmsu_course']}' 
                                AND chmsu_year='{$student['chmsu_year']}'
                                AND chmsu_section='{$student['chmsu_section']}'");
    $officeCounts[$office] = $countQuery->fetch_assoc()['c'];
    
    // Get status for this office
    $statusQuery = $conn->query("SELECT s.chmsu_status 
                                 FROM chmsu_submissions s
                                 JOIN chmsu_requirements r ON s.chmsu_requirement_id = r.chmsu_id
                                 WHERE r.chmsu_office = '$office'
                                 AND s.chmsu_student_id = '{$_SESSION['student']}'
                                 ORDER BY s.chmsu_id DESC LIMIT 1");
    if ($statusQuery->num_rows > 0) {
        $statusRow = $statusQuery->fetch_assoc();
        $officeStatuses[$office] = $statusRow['chmsu_status'];
        if ($statusRow['chmsu_status'] == 'Approved') {
            $approvedCount++;
        }
    } else {
        $officeStatuses[$office] = 'Pending';
    }
    
    // Count unread comments
    $unreadQuery = $conn->query("SELECT COUNT(*) as c FROM chmsu_comment_notifications 
                                 WHERE student_id='{$_SESSION['student']}'
                                 AND office_name='$office'
                                 AND is_read=0");
    $officeUnreadComments[$office] = $unreadQuery->fetch_assoc()['c'];
}

// Check if all offices are approved
$allApproved = ($approvedCount == $totalOffices && $totalOffices > 0);
$totalRequirements = array_sum($officeCounts);
$completedRequirements = 0;

// Count completed requirements
foreach ($offices as $office) {
    if (isset($officeStatuses[$office]) && $officeStatuses[$office] == 'Approved') {
        $completedRequirements += $officeCounts[$office];
    }
}

$progressPercent = ($totalRequirements > 0) ? round(($completedRequirements / $totalRequirements) * 100) : 0;
?>

<div class="header">
    <div class="header-logo" style="display: none;"></div>
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
            <h3><?php echo htmlspecialchars($master['chmsu_full_name']); ?></h3>
            <p><?php echo htmlspecialchars($student['chmsu_course']); ?> <?php echo $student['chmsu_year']; ?><?php echo htmlspecialchars($student['chmsu_section']); ?></p>
        </div>
        
        <ul class="sidebar-menu">
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=home" class="active">
                    <i class="fas fa-home"></i> Home
                </a>
            </li>
            <li style="border-bottom: none; padding: 8px 12px; color: #dddddd; font-size: 10px;">OFFICES</li>
            <?php foreach ($offices as $office): 
                $statusClass = '';
                $nameClass = '';
                $isApproved = false;
                $isDeclined = false;
                
                if (isset($officeStatuses[$office])) {
                    if ($officeStatuses[$office] == 'Approved') {
                        $statusClass = 'status-dot-approved';
                        $isApproved = true;
                    } elseif ($officeStatuses[$office] == 'Declined') {
                        $statusClass = 'status-dot-declined';
                        $nameClass = 'rejected-name';
                        $isDeclined = true;
                    } else {
                        $statusClass = 'status-dot-pending';
                    }
                }
                $hasUnread = isset($officeUnreadComments[$office]) && $officeUnreadComments[$office] > 0;
            ?>
            <li>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=office&office=<?php echo urlencode($office); ?>" 
                   class="<?php echo $nameClass; ?>" style="position: relative;">
                    <span class="office-status-dot <?php echo $statusClass; ?>"></span>
                    <span><?php echo htmlspecialchars($office); ?></span>
                    <span class="office-count"><?php echo $officeCounts[$office]; ?></span>
                    <?php if ($isApproved): ?>
                        <i class="fas fa-check-circle" style="color: #27ae60; margin-left: 5px; font-size: 10px;"></i>
                    <?php elseif ($isDeclined): ?>
                        <i class="fas fa-times-circle" style="color: #e74c3c; margin-left: 5px; font-size: 10px;"></i>
                    <?php endif; ?>
                    <?php if ($hasUnread): ?>
                        <span class="notification-dot" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%);"></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <li style="margin-top: 15px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout(); return false;">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
        
        <div class="storage-info">
            <i class="fas fa-database"></i> Storage: <?php echo formatFileSize($storage_used); ?> / 100MB
            <div style="width: 100%; height: 3px; background: #2d6a4f; border-radius: 2px; margin-top: 4px;">
                <div style="width: <?php echo $storage_percent; ?>%; height: 100%; background: #f1c40f; border-radius: 2px;"></div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="info-bar">
            <div class="info-bar-item"><strong>ID:</strong> <?php echo htmlspecialchars($student['chmsu_student_id']); ?></div>
            <div class="info-bar-item"><strong>Name:</strong> <?php echo htmlspecialchars($master['chmsu_full_name']); ?></div>
            <div class="info-bar-item"><strong>Course/Year/Section:</strong> <?php echo htmlspecialchars($student['chmsu_course']); ?> <?php echo $student['chmsu_year']; ?><?php echo htmlspecialchars($student['chmsu_section']); ?></div>
            <div class="info-bar-item"><strong>Storage:</strong> <?php echo formatFileSize($storage_used); ?> / 100MB</div>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- Clearance Progress Bar -->
        <div class="content-card" style="margin-bottom: 20px;">
            <div class="content-card-header">
                <span><i class="fas fa-chart-line"></i> Clearance Progress</span>
                <span><?php echo $approvedCount; ?> / <?php echo $totalOffices; ?> Offices Approved</span>
            </div>
            <div class="content-card-body">
                <div style="margin-bottom: 10px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span>Overall Progress</span>
                        <span><?php echo $progressPercent; ?>%</span>
                    </div>
                    <div style="width: 100%; height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden;">
                        <div style="width: <?php echo $progressPercent; ?>%; height: 100%; background: #27ae60; border-radius: 5px;"></div>
                    </div>
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                    <?php foreach ($offices as $office): 
                        $status = isset($officeStatuses[$office]) ? $officeStatuses[$office] : 'Pending';
                        $statusColor = '';
                        if ($status == 'Approved') $statusColor = '#27ae60';
                        elseif ($status == 'Declined') $statusColor = '#e74c3c';
                        else $statusColor = '#f39c12';
                    ?>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 10px; height: 10px; background: <?php echo $statusColor; ?>; border-radius: 50%;"></div>
                        <span style="font-size: 10px;"><?php echo htmlspecialchars($office); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <h2 style="margin-bottom: 15px; font-size: 16px;">My Clearance</h2>
        <div class="office-grid">
            <?php foreach ($offices as $office): 
                $statusClass = '';
                $statusTextClass = 'status-text-pending';
                $nameClass = '';
                $isClickable = true;
                
                if (isset($officeStatuses[$office])) {
                    if ($officeStatuses[$office] == 'Approved') {
                        $statusClass = 'status-approved';
                        $statusTextClass = 'status-text-approved';
                    } elseif ($officeStatuses[$office] == 'Declined') {
                        $statusClass = 'status-declined';
                        $statusTextClass = 'status-text-declined';
                        $nameClass = 'rejected-name';
                    }
                }
                $hasUnread = isset($officeUnreadComments[$office]) && $officeUnreadComments[$office] > 0;
            ?>
            <div class="office-card <?php echo $statusClass; ?> <?php echo $nameClass; ?>" 
                 onclick="window.location.href='?view=office&office=<?php echo urlencode($office); ?>'" 
                 style="position: relative; cursor: pointer;">
                <h4><?php echo htmlspecialchars($office); ?></h4>
                <span class="count"><?php echo $officeCounts[$office]; ?> Requirements</span>
                <span class="status <?php echo $statusTextClass; ?>"><?php echo $officeStatuses[$office]; ?></span>
                <?php if ($hasUnread): ?>
                    <span class="notification-dot" style="position: absolute; top: 10px; right: 10px;"></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- CERTIFICATE SECTION - Only shows when ALL offices are approved -->
        <?php if ($allApproved): ?>
        <div class="content-card" style="margin-top: 20px; border: 2px solid #27ae60;">
            <div class="content-card-header" style="background: #27ae60; color: white;">
                <span><i class="fas fa-certificate"></i> Clearance Certificate</span>
                <span><i class="fas fa-check-circle"></i> COMPLETED</span>
            </div>
            <div class="content-card-body" style="text-align: center; padding: 20px;">
                <i class="fas fa-trophy" style="font-size: 48px; color: #f1c40f; margin-bottom: 10px;"></i>
                <h3 style="color: #27ae60; margin-bottom: 10px;">Congratulations!</h3>
                <p>You have successfully completed all clearance requirements for all offices.</p>
                <p style="margin-bottom: 15px;">You are now eligible to receive your Certificate of Completion.</p>
                <a href="?print_certificate=<?php echo $_SESSION['student']; ?>" target="_blank" class="btn btn-success" style="padding: 10px 20px; font-size: 14px;">
                    <i class="fas fa-print"></i> Print Certificate
                </a>
            </div>
        </div>
        <?php elseif ($approvedCount > 0): ?>
        <div class="content-card" style="margin-top: 20px; background: #f8f9fa;">
            <div class="content-card-header">
                <span><i class="fas fa-info-circle"></i> Certificate Status</span>
            </div>
            <div class="content-card-body" style="text-align: center; padding: 15px;">
                <i class="fas fa-lock" style="font-size: 36px; color: #f39c12; margin-bottom: 10px;"></i>
                <p>Certificate will be available when <strong>ALL offices</strong> have approved your requirements.</p>
                <p>Currently approved: <strong><?php echo $approvedCount; ?> / <?php echo $totalOffices; ?></strong> offices</p>
                <div style="width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; margin-top: 10px;">
                    <div style="width: <?php echo ($approvedCount / $totalOffices) * 100; ?>%; height: 100%; background: #f39c12; border-radius: 4px;"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>