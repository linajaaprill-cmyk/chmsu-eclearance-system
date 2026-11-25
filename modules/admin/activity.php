<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin'])) {
    header("Location: ../index.php");
    exit;
}

$admin = $_SESSION['admin'];
$adminSection = 'activity';
$error = '';
$success = '';

// ============================================
// PDF EXPORT (self-contained in this file)
// ============================================
if (isset($_GET['export_activity']) && $_GET['export_activity'] === 'pdf') {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $tcpdf_paths = [
        __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php',
        'C:/xampp/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php'
    ];
    foreach ($tcpdf_paths as $tcpdf_path) {
        if (file_exists($tcpdf_path)) { require_once $tcpdf_path; break; }
    }

    while (ob_get_level()) { ob_end_clean(); }

    if (!class_exists('TCPDF')) {
        header('Content-Type: text/plain');
        echo 'PDF library (TCPDF) not found in the vendor folder.';
        exit;
    }

    $pdf_rows = [];
    $pdf_res = $conn->query("SELECT user_id, user_type, action, ip_address, created_at FROM chmsu_activity_log ORDER BY created_at DESC");
    if ($pdf_res) { while ($pr = $pdf_res->fetch_assoc()) { $pdf_rows[] = $pr; } }

    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('CHMSU E-Clearance System');
    $pdf->SetAuthor('CHMSU');
    $pdf->SetTitle('System Activity Log');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->AddPage();

    $head = '<div style="text-align:center;">'
          . '<div style="font-size:15pt;font-weight:bold;">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>'
          . '<div style="font-size:9pt;">Talisay City, Negros Occidental, Philippines</div>'
          . '<div style="font-size:12pt;font-weight:bold;">SYSTEM ACTIVITY LOG</div></div><br>'
          . '<table cellpadding="2" style="font-size:8pt;"><tr>'
          . '<td><b>Date Generated:</b> ' . date('F d, Y h:i A') . '</td>'
          . '<td style="text-align:right;"><b>Total Records:</b> ' . count($pdf_rows) . '</td></tr></table><br>';
    $pdf->writeHTML($head, true, false, true, false, '');

    $th = '<tr style="background-color:#dfe8e4;font-weight:bold;">'
        . '<th width="18%">User</th><th width="10%">Type</th><th width="40%">Action</th>'
        . '<th width="12%">IP Address</th><th width="20%">Date/Time</th></tr>';

    if (empty($pdf_rows)) {
        $pdf->writeHTML('<table border="1" cellpadding="5"><tr><td style="text-align:center;">No activity records found.</td></tr></table>', true, false, true, false, '');
    } else {
        foreach (array_chunk($pdf_rows, 150) as $chunk) {
            $t = '<table border="1" cellpadding="3"><thead>' . $th . '</thead><tbody>';
            foreach ($chunk as $r) {
                $t .= '<tr>'
                    . '<td width="18%">' . htmlspecialchars((string)$r['user_id']) . '</td>'
                    . '<td width="10%">' . htmlspecialchars((string)$r['user_type']) . '</td>'
                    . '<td width="40%">' . htmlspecialchars((string)$r['action']) . '</td>'
                    . '<td width="12%">' . htmlspecialchars((string)$r['ip_address']) . '</td>'
                    . '<td width="20%">' . date('M d, Y H:i:s', strtotime($r['created_at'])) . '</td>'
                    . '</tr>';
            }
            $t .= '</tbody></table>';
            $pdf->writeHTML($t, true, false, true, false, '');
        }
    }

    $pdf->Ln(3);
    $pdf->writeHTML('<div style="text-align:center;font-size:7pt;font-style:italic;">This is a system-generated report. No signature is required.</div>', true, false, true, false, '');
    $pdf->Output('Activity_Log_' . date('Ymd') . '.pdf', 'D');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System - Activity Log</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
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
        
        .admin-sidebar {
            width: 260px;
            background: #1b4d3e;
            color: white;
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
        }
        .admin-sidebar::-webkit-scrollbar { width: 5px; }
        .admin-sidebar::-webkit-scrollbar-track { background: #2d6a4f; }
        .admin-sidebar::-webkit-scrollbar-thumb { background: #f1c40f; border-radius: 5px; }
        .admin-sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-header h3 { font-size: 16px; font-weight: normal; }
        .admin-sidebar .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        .admin-sidebar .sidebar-menu { list-style: none; padding: 0; margin: 0; padding-bottom: 20px; }
        .admin-sidebar .sidebar-menu li { border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-menu li.dropdown { border-bottom: none; }
        .admin-sidebar .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
        }
        .admin-sidebar .sidebar-menu a:hover,
        .admin-sidebar .sidebar-menu a.active { background: #f1c40f; color: #000000; }
        .admin-sidebar .sidebar-menu .dropdown-menu {
            list-style: none;
            padding: 0;
            margin: 0;
            background: #0f3b2f;
            display: block;
        }
        .admin-sidebar .sidebar-menu .dropdown-menu li { border-bottom: 1px solid #2d6a4f; }
        .admin-sidebar .sidebar-menu .dropdown-menu a { padding: 10px 20px 10px 35px; font-size: 12px; }
        .admin-sidebar .sidebar-menu .dropdown-menu a:hover,
        .admin-sidebar .sidebar-menu .dropdown-menu a.active { background: #f1c40f; color: #000000; }
        .dropdown-toggle::after { display: none; }
        
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
        
        .search-box {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            margin-bottom: 15px;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        .btn-excel {
            background: #27ae60;
            color: white;
            padding: 6px 12px;
            border: none;
            cursor: pointer;
        }
        .btn-pdf {
            background: #e74c3c;
            color: white;
            padding: 6px 12px;
            border: none;
            cursor: pointer;
        }
        .btn-word {
            background: #1b4d3e;
            color: white;
            padding: 6px 12px;
            border: none;
            cursor: pointer;
        }
        
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
        .plain-table th {
            background: #f8f9fa;
            font-weight: normal;
        }
        
        body.dark-mode { background: #0a0a0a; }
        body.dark-mode .main-content { background: #0a0a0a; }
        body.dark-mode .dashboard-header-bar,
        body.dark-mode .content-card { background: #1a1a1a; border-color: #333; color: #fff; }
        body.dark-mode .dashboard-header-bar h2 { color: #f1c40f; }
        body.dark-mode .plain-table th { background: #2c2c2c; color: #fff; border-color: #444; }
        body.dark-mode .plain-table td { border-color: #333; color: #fff; }
        body.dark-mode .search-box { background: #2c2c2c; border-color: #444; color: #fff; }
        
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
        <p>CLEARANCE SYSTEM | Admin Portal</p>
    </div>
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">Dark Mode</button>
    <button onclick="confirmLogout()" class="logout-btn">Logout</button>
</div>

<div class="dashboard-wrapper">
    <!-- SIDEBAR - SAME FOR ALL ADMIN PAGES -->
    <div class="admin-sidebar">
        <div class="sidebar-header">
            <h3>Admin: <?php echo htmlspecialchars($admin); ?></h3>
            <p>System Administrator</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?adminsection=dashboard" class="<?php echo $adminSection == 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a></li>
            
            <li class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class="fas fa-cogs"></i> Maintenance
                </a>
                <ul class="dropdown-menu">
                    <li><a href="?adminsection=courses" class="<?php echo $adminSection == 'courses' ? 'active' : ''; ?>">
                        <i class="fas fa-book"></i> Courses
                    </a></li>
                    <li><a href="?adminsection=offices" class="<?php echo $adminSection == 'offices' ? 'active' : ''; ?>">
                        <i class="fas fa-building"></i> Offices
                    </a></li>
                    <li><a href="?adminsection=sections" class="<?php echo $adminSection == 'sections' ? 'active' : ''; ?>">
                        <i class="fas fa-layer-group"></i> Sections
                    </a></li>
                    <li><a href="?adminsection=school_years" class="<?php echo $adminSection == 'school_years' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar"></i> School Years
                    </a></li>
                    <li><a href="?adminsection=semesters" class="<?php echo $adminSection == 'semesters' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Semesters
                    </a></li>
                </ul>
            </li>
            
            <li><a href="?adminsection=reports" class="<?php echo $adminSection == 'reports' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            
            <li><a href="?adminsection=activity" class="active">
                <i class="fas fa-history"></i> Activity Log
            </a></li>
            
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header-bar">
            <h2>System Activity Log</h2>
            <span><?php echo date('F d, Y'); ?></span>
        </div>
        
        <div class="content-card">
            <div class="content-card-header">Complete Activity Log</div>
            <div class="content-card-body">
                <input type="text" class="search-box" id="activitySearch" placeholder="Search by user or action..." onkeyup="searchActivity()">
                
                <div class="export-buttons">
                    <a href="modules/admin/export_activity_pdf.php" id="exportPdfBtn" class="btn-pdf" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </a>
                </div>
                
                <div class="table-responsive">
                    <table class="plain-table" id="activityTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Type</th>
                                <th>Action</th>
                                <th>IP Address</th>
                                <th>Date/Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $logs = $conn->query("SELECT * FROM chmsu_activity_log ORDER BY created_at DESC");
                            if ($logs && $logs->num_rows > 0):
                                while ($log = $logs->fetch_assoc()):
                            ?>
                            <tr data-search="<?php echo strtolower($log['user_id'].' '.$log['action']); ?>">
                                <td><?php echo htmlspecialchars($log['user_id']); ?></td>
                                <td><?php echo $log['user_type']; ?></td>
                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                <td><?php echo $log['ip_address']; ?></td>
                                <td><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="5" style="text-align:center;">No activity records found.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
    
    function searchActivity() {
        const search = document.getElementById('activitySearch')?.value.toLowerCase() || '';
        const rows = document.querySelectorAll('#activityTable tbody tr');
        rows.forEach(row => {
            const searchData = row.dataset.search;
            if (searchData && searchData.includes(search)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    // Export Activity PDF via server-side TCPDF generator
    function exportActivity(type) {
        const search = document.getElementById('activitySearch')?.value.trim() || '';
        let url = 'modules/admin/export_activity_pdf.php';
        if (search) {
            url += '?search=' + encodeURIComponent(search);
        }
        window.location.href = url;
    }

    // Keep the Export PDF link in sync with the search bar
    document.getElementById('activitySearch')?.addEventListener('input', function() {
        const btn = document.getElementById('exportPdfBtn');
        if (btn) {
            const val = this.value.trim();
            btn.href = 'modules/admin/export_activity_pdf.php' + (val ? '?search=' + encodeURIComponent(val) : '');
        }
    });
</script>

</body>
</html>