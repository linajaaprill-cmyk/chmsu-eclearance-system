<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/clearance_history.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin'])) {
    header("Location: ../index.php");
    exit;
}

$admin = $_SESSION['admin'];
$adminSection = 'history';
$storage_used = getTotalUploadSize();
$storage_percent = min(($storage_used / (100 * 1024 * 1024)) * 100, 100);

$currentSem = getCurrentSemester($conn);
$semesters = getHistorySemesters($conn);
$selected_sem_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : ($currentSem['id'] ?? 0);
$selectedSem = getSemesterById($conn, $selected_sem_id);

$totalOffices = getTotalOfficesCount($conn);

$semesterSummaries = [];
foreach ($semesters as $sem) {
    $sid = intval($sem['id']);
    $totalStudents = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0")->fetch_assoc()['c']);

    $fullyCleared = 0;
    $partially = 0;
    $none = 0;
    $studentsQ = $conn->query("SELECT chmsu_student_id FROM chmsu_students_master WHERE is_archived = 0");
    while ($s = $studentsQ->fetch_assoc()) {
        $cleared = countStudentClearedOffices($conn, $s['chmsu_student_id'], $sid);
        if ($cleared >= $totalOffices && $totalOffices > 0) $fullyCleared++;
        elseif ($cleared > 0) $partially++;
        else $none++;
    }
    $sem['total_students'] = $totalStudents;
    $sem['fully_cleared'] = $fullyCleared;
    $sem['partially'] = $partially;
    $sem['none'] = $none;
    $sem['rate'] = $totalStudents > 0 ? round(($fullyCleared / $totalStudents) * 100, 1) : 0;
    $semesterSummaries[] = $sem;
}

// Office performance for selected semester
$officePerf = [];
if ($selectedSem) {
    $sid = intval($selectedSem['id']);
    $officesQ = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
    while ($o = $officesQ->fetch_assoc()) {
        $on = $o['office_name'];
        $on_esc = $conn->real_escape_string($on);

        $approved = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$on_esc' AND semester_id=$sid AND status='clear'")->fetch_assoc()['c']);
        $declined = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$on_esc' AND semester_id=$sid AND status='unclear'")->fetch_assoc()['c']);
        $pending = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_students_master s LEFT JOIN chmsu_clearance_status cs ON s.chmsu_student_id=cs.student_id AND cs.office_name='$on_esc' AND cs.semester_id=$sid WHERE s.is_archived=0 AND (cs.status IS NULL OR cs.status='pending')")->fetch_assoc()['c']);

        $officePerf[] = [
            'office' => $on,
            'approved' => $approved,
            'declined' => $declined,
            'pending' => $pending,
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU - Clearance History (Admin)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; background: #f5f5f5; font-size: 14px; }
        .header { background: #1b4d3e; color: white; padding: 15px 25px; display: flex; align-items: center; gap: 20px; position: sticky; top: 0; z-index: 1000; }
        .header-logo img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; }
        .header-title h1 { font-size: 20px; font-weight: normal; }
        .header-title p { font-size: 11px; opacity: 0.8; margin-top: 3px; }
        .dark-mode-toggle { background: transparent; border: 1px solid rgba(255,255,255,0.3); color: white; padding: 6px 12px; cursor: pointer; margin-left: auto; }
        .logout-btn { background: #e74c3c; color: white; padding: 6px 15px; border: none; cursor: pointer; }

        .dashboard-wrapper { display: flex; min-height: calc(100vh - 73px); }
        .admin-sidebar { width: 260px; background: #1b4d3e; color: white; position: fixed; height: calc(100vh - 73px); overflow-y: auto; }
        .admin-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .admin-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .admin-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-menu li.dropdown { border-bottom: none; }
        .admin-sidebar .sidebar-menu a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 13px; transition: all 0.3s; }
        .admin-sidebar .sidebar-menu a:hover, .admin-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        .admin-sidebar .sidebar-menu .dropdown-menu { background: #0f3b2f; }
        .admin-sidebar .sidebar-menu .dropdown-menu a { padding: 10px 20px 10px 35px; font-size: 12px; }

        .main-content { flex: 1; margin-left: 260px; padding: 20px; background: #f5f5f5; min-height: calc(100vh - 73px); }
        .dashboard-header-bar { background: white; padding: 15px 20px; border: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .dashboard-header-bar h2 { font-size: 18px; font-weight: normal; color: #1b4d3e; }

        .semester-card { background: white; border: 1px solid #ddd; border-left: 5px solid #1b4d3e; margin-bottom: 15px; overflow: hidden; }
        .semester-card.current { border-left-color: #f1c40f; }
        .semester-card.cleared { border-left-color: #27ae60; }
        .semester-card-header { background: #f8f9fa; padding: 14px 18px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .semester-title { font-size: 15px; font-weight: 600; color: #1b4d3e; }
        .semester-badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-active { background: #fff8e1; color: #b9770e; }
        .badge-archived { background: #e8f0fe; color: #1967d2; }
        .semester-card-body { padding: 16px 18px; }

        .stat-row { display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 14px; }
        .stat-item { display: flex; flex-direction: column; }
        .stat-num { font-size: 22px; font-weight: 700; color: #1b4d3e; }
        .stat-num.green { color: #27ae60; }
        .stat-num.orange { color: #f39c12; }
        .stat-num.red { color: #e74c3c; }
        .stat-lbl { font-size: 11px; color: #777; text-transform: uppercase; letter-spacing: 0.4px; }

        .filter-bar { background: white; border: 1px solid #ddd; padding: 14px 18px; margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-bar label { display: block; font-size: 11px; color: #555; margin-bottom: 4px; }
        .filter-bar select { padding: 6px 10px; border: 1px solid #ddd; font-size: 12px; min-width: 200px; }

        table.perf { width: 100%; border-collapse: collapse; font-size: 12px; }
        table.perf th { background: #1b4d3e; color: white; padding: 10px; text-align: left; }
        table.perf td { padding: 8px; border-bottom: 1px solid #eee; }

        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block; font-family: 'Times New Roman', Times, serif; }
        .btn-primary { background: #1b4d3e; color: white; }
        .btn-primary:hover { background: #2d6a4f; }
        .btn-sm { padding: 5px 12px; font-size: 11px; }

        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .semester-card,
        body.dark-mode .filter-bar { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .semester-card-header { background: #2c2c2c; border-color: #333; }
        body.dark-mode .semester-title { color: #f1c40f; }
        body.dark-mode .stat-num { color: #f1c40f; }
        body.dark-mode table.perf th { background: #2c2c2c; }
        body.dark-mode table.perf td { border-color: #333; color: #fff; }
        body.dark-mode .filter-bar select { background: #2c2c2c; border-color: #444; color: #fff; }

        @media (max-width: 768px) {
            .admin-sidebar { width: 100%; position: relative; height: auto; }
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
        <p>CLEARANCE SYSTEM | Admin Portal - Clearance History</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <div class="admin-sidebar">
        <div class="sidebar-header">
            <h3>Admin: <?php echo htmlspecialchars($admin); ?></h3>
            <p>System Administrator</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?adminsection=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle"><i class="fas fa-cogs"></i> Maintenance</a>
                <ul class="dropdown-menu">
                    <li><a href="?adminsection=courses"><i class="fas fa-book"></i> Courses</a></li>
                    <li><a href="?adminsection=offices"><i class="fas fa-building"></i> Offices</a></li>
                    <li><a href="?adminsection=sections"><i class="fas fa-layer-group"></i> Sections</a></li>
                    <li><a href="?adminsection=school_years"><i class="fas fa-calendar"></i> School Years</a></li>
                    <li><a href="?adminsection=semesters"><i class="fas fa-clock"></i> Semesters</a></li>
                </ul>
            </li>
            <li><a href="?adminsection=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?adminsection=history" class="active"><i class="fas fa-history"></i> Clearance History</a></li>
            <li><a href="?adminsection=activity"><i class="fas fa-list-alt"></i> Activity Log</a></li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="?logout=1" onclick="return confirm('Are you sure you want to logout?');"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2><i class="fas fa-history"></i> Clearance History — Administrator</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>

        <?php if (empty($semesterSummaries)): ?>
            <div class="semester-card">
                <div class="semester-card-body" style="text-align:center; padding:40px; color:#999;">
                    <i class="fas fa-inbox" style="font-size:40px; display:block; margin-bottom:10px; color:#ccc;"></i>
                    <p>No semesters have been created yet.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($semesterSummaries as $sem):
                $is_current = intval($sem['is_current']) === 1;
                $cardClass = 'semester-card' . ($is_current ? ' current' : ' cleared');
                $badge = $is_current
                    ? '<span class="semester-badge badge-active">Currently Active</span>'
                    : '<span class="semester-badge badge-archived">Archived</span>';
            ?>
            <div class="<?php echo $cardClass; ?>">
                <div class="semester-card-header">
                    <div class="semester-title">
                        <?php echo htmlspecialchars($sem['semester_name']); ?>
                        <?php if (!empty($sem['school_year'])): ?> · <?php echo htmlspecialchars($sem['school_year']); ?><?php endif; ?>
                    </div>
                    <?php echo $badge; ?>
                </div>
                <div class="semester-card-body">
                    <div class="stat-row">
                        <div class="stat-item"><span class="stat-num"><?php echo $sem['total_students']; ?></span><span class="stat-lbl">Total Students</span></div>
                        <div class="stat-item"><span class="stat-num green"><?php echo $sem['fully_cleared']; ?></span><span class="stat-lbl">Fully Cleared</span></div>
                        <div class="stat-item"><span class="stat-num orange"><?php echo $sem['partially']; ?></span><span class="stat-lbl">Partial</span></div>
                        <div class="stat-item"><span class="stat-num red"><?php echo $sem['none']; ?></span><span class="stat-lbl">Not Cleared</span></div>
                        <div class="stat-item"><span class="stat-num green"><?php echo $sem['rate']; ?>%</span><span class="stat-lbl">Completion Rate</span></div>
                    </div>

                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="?adminsection=history&semester_id=<?php echo $sem['id']; ?>&view=performance" class="btn btn-primary btn-sm">
                            <i class="fas fa-chart-line"></i> Office Performance
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (isset($_GET['view']) && $_GET['view'] === 'performance' && $selectedSem): ?>
        <div class="semester-card" style="margin-top: 20px;">
            <div class="semester-card-header">
                <div class="semester-title">
                    <i class="fas fa-building"></i>
                    <?php
                    $selSchoolYear = '';
                    foreach ($semesterSummaries as $ss) {
                        if (intval($ss['id']) === intval($selectedSem['id'])) { $selSchoolYear = $ss['school_year'] ?? ''; break; }
                    }
                    ?>
                    Office Performance — <?php echo htmlspecialchars($selectedSem['semester_name']); ?><?php if (!empty($selSchoolYear)): ?> · <?php echo htmlspecialchars($selSchoolYear); ?><?php endif; ?>
                </div>
            </div>
            <div class="semester-card-body">
                <table class="perf">
                    <thead>
                        <tr>
                            <th>Office</th>
                            <th>Approved</th>
                            <th>Declined</th>
                            <th>Not Processed</th>
                            <th>Approval Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($officePerf)): ?>
                            <tr><td colspan="5" style="padding:20px; text-align:center; color:#999;">No offices found.</td></tr>
                        <?php else: foreach ($officePerf as $p):
                            $total_actions = $p['approved'] + $p['declined'];
                            $rate = $total_actions > 0 ? round(($p['approved'] / $total_actions) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['office']); ?></strong></td>
                            <td style="color:#27ae60; font-weight:600;"><?php echo $p['approved']; ?></td>
                            <td style="color:#e74c3c; font-weight:600;"><?php echo $p['declined']; ?></td>
                            <td style="color:#f39c12; font-weight:600;"><?php echo $p['pending']; ?></td>
                            <td><strong><?php echo $rate; ?>%</strong></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        const isDarkMode = document.body.classList.contains('dark-mode');
        localStorage.setItem('darkMode', isDarkMode);
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) btn.innerHTML = isDarkMode ? '<i class="fas fa-sun"></i> Light Mode' : '<i class="fas fa-moon"></i> Dark Mode';
    }
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
        const btn = document.querySelector('.dark-mode-toggle');
        if (btn) btn.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
    }
    function confirmLogout() {
        if (confirm('Are you sure you want to logout?')) window.location.href = '?logout=1';
    }
</script>

</body>
</html>