<?php
// modules/admin/password_resets.php
// Admin — Flag a student for password reset (admin does NOT set the password)

if (!isset($_SESSION['admin'])) { exit; }

$success = '';
$error   = '';
$done_flag_student = '';

// ============================================
// HANDLE: FLAG STUDENT FOR RESET
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'flag_reset') {
    $student_id = sanitize($_POST['student_id'] ?? '');

    if (empty($student_id)) {
        $error = "Student ID is required.";
    } else {
        $sid_esc = $conn->real_escape_string($student_id);

        $check = $conn->query("SELECT chmsu_student_id, chmsu_full_name
                               FROM chmsu_students_master
                               WHERE chmsu_student_id = '$sid_esc' AND is_archived = 0")->fetch_assoc();

        if (!$check) {
            $error = "Student ID not found in masterlist.";
        } else {
            $has_account = $conn->query("SELECT chmsu_id FROM chmsu_user_accounts
                                         WHERE chmsu_student_id = '$sid_esc'")->fetch_assoc();

            if (!$has_account) {
                $error = "Student <strong>" . htmlspecialchars($check['chmsu_full_name'])
                       . "</strong> does not have a user account yet. They must register first before their password can be reset.";
            } else {
                $existing_pending = $conn->query("SELECT id FROM chmsu_password_resets
                                                  WHERE student_id = '$sid_esc' AND is_used = 0
                                                  ORDER BY id DESC LIMIT 1")->fetch_assoc();

                if ($existing_pending) {
                    $success = "Student <strong>" . htmlspecialchars($check['chmsu_full_name'])
                             . "</strong> is already flagged for password reset. They will be prompted to set a new password on next login.";
                } else {
                    $admin_esc = $conn->real_escape_string($_SESSION['admin']);
                    $conn->query("INSERT INTO chmsu_password_resets
                                  (student_id, temp_password, reset_by, is_used)
                                  VALUES ('$sid_esc', '', '$admin_esc', 0)");

                    logActivity($conn, $_SESSION['admin'], 'admin', "Flagged student for password reset: $student_id");
                    $success = "Password reset flagged for <strong>" . htmlspecialchars($check['chmsu_full_name'])
                             . "</strong>. The student will be prompted to create a new password on their next login.";
                    $done_flag_student = $student_id;
                }
            }
        }
    }
}

// ============================================
// FILTERS: Course / Year / Section
// ============================================
$f_course  = isset($_GET['f_course'])  ? sanitize($_GET['f_course'])  : '';
$f_year    = isset($_GET['f_year'])    ? sanitize($_GET['f_year'])    : '';
$f_section = isset($_GET['f_section']) ? sanitize($_GET['f_section']) : '';

$where = "WHERE s.is_archived = 0";
if ($f_course  !== '') $where .= " AND s.chmsu_course  = '" . $conn->real_escape_string($f_course)  . "'";
if ($f_year    !== '') $where .= " AND s.chmsu_year    = '" . $conn->real_escape_string($f_year)    . "'";
if ($f_section !== '') $where .= " AND s.chmsu_section = '" . $conn->real_escape_string($f_section) . "'";

// Grouped / sorted: Course → Year → Section → Last Name → First Name
$students = $conn->query("
    SELECT s.chmsu_student_id, s.chmsu_last_name, s.chmsu_first_name, s.chmsu_middle_name,
           s.chmsu_full_name, s.chmsu_birthdate, s.chmsu_course, s.chmsu_year, s.chmsu_section,
           u.chmsu_id AS account_id,
           (SELECT COUNT(*) FROM chmsu_password_resets pr
             WHERE pr.student_id = s.chmsu_student_id AND pr.is_used = 0) AS pending_reset
    FROM chmsu_students_master s
    LEFT JOIN chmsu_user_accounts u ON u.chmsu_student_id = s.chmsu_student_id
    $where
    ORDER BY s.chmsu_course ASC,
             CAST(s.chmsu_year AS UNSIGNED) ASC,
             s.chmsu_section ASC,
             s.chmsu_last_name ASC,
             s.chmsu_first_name ASC
    LIMIT 500
");

$students_data = [];
if ($students) {
    while ($row = $students->fetch_assoc()) { $students_data[] = $row; }
}
$total_students = count($students_data);

// ============================================
// DROPDOWN OPTIONS
// ============================================
$courses_list = [];
$cq = $conn->query("SELECT DISTINCT chmsu_course FROM chmsu_students_master
                    WHERE is_archived = 0 AND chmsu_course <> ''
                    ORDER BY chmsu_course ASC");
if ($cq) while ($r = $cq->fetch_assoc()) $courses_list[] = $r['chmsu_course'];

$years_list = [];
$yq = $conn->query("SELECT DISTINCT chmsu_year FROM chmsu_students_master
                    WHERE is_archived = 0 AND chmsu_year <> ''
                    ORDER BY CAST(chmsu_year AS UNSIGNED) ASC");
if ($yq) while ($r = $yq->fetch_assoc()) $years_list[] = $r['chmsu_year'];

$sections_list = [];
if ($f_course !== '' && $f_year !== '') {
    $sc = $conn->real_escape_string($f_course);
    $sy = $conn->real_escape_string($f_year);
    $secq = $conn->query("SELECT section_name FROM chmsu_course_sections
                          WHERE course = '$sc' AND year = '$sy'
                          ORDER BY section_name ASC");
    if ($secq) while ($r = $secq->fetch_assoc()) $sections_list[] = $r['section_name'];
} else {
    $secq = $conn->query("SELECT DISTINCT chmsu_section FROM chmsu_students_master
                          WHERE is_archived = 0 AND chmsu_section <> ''
                          ORDER BY chmsu_section ASC");
    if ($secq) while ($r = $secq->fetch_assoc()) $sections_list[] = $r['chmsu_section'];
}
?>

<style>
    .pr-page { padding: 0; }
    .pr-card { background:#fff; border-radius:10px; padding:22px; box-shadow:0 2px 8px rgba(0,0,0,0.06); margin-bottom:18px; }
    .pr-card h3 { font-size:16px; color:#1b4d3e; margin:0 0 14px 0; }
    .pr-form-row { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:6px; }
    .pr-form-row label { display:block; font-size:12px; color:#555; margin-bottom:5px; font-weight:600; }
    .pr-form-row input { width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px; font-size:14px; font-family:inherit; box-sizing:border-box; }
    .pr-form-row input:focus { border-color:#1b4d3e; outline:none; box-shadow:0 0 0 3px rgba(27,77,62,0.1); }
    .pr-btn { background:#1b4d3e; color:#fff; border:none; padding:11px 20px; border-radius:6px; cursor:pointer; font-size:14px; font-family:inherit; font-weight:600; transition:background 0.2s; }
    .pr-btn:hover { background:#2d6a4f; }
    .pr-btn.secondary { background:#e4e6eb; color:#333; font-weight:600; padding:6px 12px; font-size:12px; }
    .pr-btn.secondary:hover { background:#d0d2d5; }
    .pr-btn.secondary:disabled { background:#ccc; color:#666; cursor:not-allowed; }
    .pr-btn.done { background:#28a745; color:#fff; cursor:default; }
    .pr-btn.done:hover { background:#28a745; }
    .pr-btn.pending-reset { background:#f0ad4e; color:#fff; cursor:not-allowed; }
    .pr-btn.pending-reset:hover { background:#f0ad4e; }
    .pr-alert { padding:14px 18px; border-radius:8px; margin-bottom:18px; font-size:14px; }
    .pr-alert.success { background:#d4edda; color:#155724; border-left:4px solid #28a745; }
    .pr-alert.error { background:#f8d7da; color:#721c24; border-left:4px solid #dc3545; }
    .pr-table { width:100%; border-collapse:collapse; font-size:13px; }
    .pr-table th { background:#1b4d3e; color:#fff; padding:11px 8px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:0.3px; position:sticky; top:0; z-index:2; }
    .pr-table td { padding:9px 8px; border-bottom:1px solid #eee; vertical-align:middle; }
    .pr-table tbody tr:hover td { background:#f8fdf9; }
    .pr-table-wrap { max-height:560px; overflow-y:auto; border:1px solid #e4e6eb; border-radius:8px; }

    /* Group header rows inside the table */
    .pr-table tbody tr.group-row td {
        background: #eaf3ee !important;
        color: #1b4d3e;
        font-weight: 700;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        padding: 10px 12px;
        border-top: 2px solid #1b4d3e;
        border-bottom: 1px solid #c7d8ce;
    }
    .pr-table tbody tr.group-row:hover td { background: #eaf3ee !important; }

    /* Filter bar */
    .filter-bar {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 12px;
        margin-bottom: 16px;
    }
    .filter-bar label { display:block; font-size:12px; color:#555; margin-bottom:5px; font-weight:600; }
    .filter-bar select {
        width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px;
        font-size:14px; font-family:inherit; background:#fff; box-sizing:border-box;
    }
    .filter-bar select:focus { border-color:#1b4d3e; outline:none; box-shadow:0 0 0 3px rgba(27,77,62,0.1); }
    .filter-actions { display: flex; align-items: flex-end; gap: 10px; }

    .hint { font-size:11px; color:#888; margin-top:6px; line-height:1.5; }
    .badge-pending { display:inline-block; background:#fff3cd; color:#856404; font-size:10px; font-weight:600; padding:2px 8px; border-radius:10px; margin-left:5px; vertical-align:middle; }
    .badge-active  { display:inline-block; background:#d4edda; color:#155724; font-size:10px; font-weight:600; padding:2px 8px; border-radius:10px; }
    .badge-none    { display:inline-block; background:#f8d7da; color:#721c24; font-size:10px; font-weight:600; padding:2px 8px; border-radius:10px; }
    .hdr-info-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px; }
    .hdr-info-row h2 { margin:0; font-size:20px; color:#1b4d3e; }
    .hdr-info-row .stat { font-size:13px; color:#666; background:#f0f2f5; padding:6px 14px; border-radius:20px; }

    /* Red Student ID for no-account rows */
    .sid-red { color: #dc3545 !important; font-weight: 700; }

    /* Section/Course/Year small text */
    .meta-col { font-size: 11.5px; color: #666; white-space: nowrap; }
    .meta-col strong { color: #1b4d3e; }

    /* Back button */
    .back-btn-row { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
    .back-btn {
        display: inline-flex; align-items: center; gap: 6px;
        background: #e4e6eb; color: #333; border: none;
        padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
        cursor: pointer; text-decoration: none; transition: background 0.2s; font-family: inherit;
    }
    .back-btn:hover { background: #d0d2d5; }

    body.dark-mode .pr-card { background:#242526; color:#e4e6eb; }
    body.dark-mode .pr-card h3 { color:#f1c40f; }
    body.dark-mode .hdr-info-row h2 { color:#f1c40f; }
    body.dark-mode .hdr-info-row .stat { background:#3e4042; color:#b0b3b8; }
    body.dark-mode .pr-form-row input { background:#3e4042; color:#e4e6eb; border-color:#4a4c4e; }
    body.dark-mode .filter-bar label { color:#b0b3b8; }
    body.dark-mode .filter-bar select { background:#3e4042; color:#e4e6eb; border-color:#4a4c4e; }
    body.dark-mode .pr-table th { background:#0f3b2f; }
    body.dark-mode .pr-table td { border-color:#3e4042; color:#e4e6eb; }
    body.dark-mode .pr-table tbody tr:hover td { background:#2d3a2f; }
    body.dark-mode .pr-btn.secondary { background:#3e4042; color:#e4e6eb; }
    body.dark-mode .back-btn { background: #3e4042; color: #e4e6eb; }
    body.dark-mode .back-btn:hover { background: #4a4c4e; }
    body.dark-mode .pr-table-wrap { border-color:#3e4042; }
    body.dark-mode .pr-table tbody tr.group-row td {
        background: #1f2e26 !important;
        color: #f1c40f;
        border-top-color: #f1c40f;
        border-bottom-color: #3e4042;
    }
    body.dark-mode .pr-table tbody tr.group-row:hover td { background: #1f2e26 !important; }
    body.dark-mode .meta-col { color: #b0b3b8; }
    body.dark-mode .meta-col strong { color: #f1c40f; }
</style>

<div class="pr-page">
    <div class="hdr-info-row">
        <h2>Student Password Management</h2>
        <span class="stat"><?php echo $total_students; ?> student<?php echo $total_students !== 1 ? 's' : ''; ?></span>
    </div>

    <?php if ($success): ?>
        <div class="pr-alert success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="pr-alert error"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Back button -->
    <div class="back-btn-row">
        <a href="?adminsection=dashboard" class="back-btn">&#8592; Back</a>
    </div>

    <div class="pr-card">
        <h3>Flag Student for Password Reset</h3>
        <form method="POST">
            <input type="hidden" name="action" value="flag_reset">
            <div class="pr-form-row">
                <div>
                    <label>Student ID</label>
                    <input type="text" name="student_id" placeholder="e.g. MJI03260500" required autocomplete="off">
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <button type="submit" class="pr-btn" style="width:100%;">
                        Flag for Reset
                    </button>
                </div>
            </div>
        </form>
        <p class="hint">
            When flagged, the student is prompted to set a new password on their next login.
            Students without an account must register first — their password cannot be reset.
        </p>
    </div>

    <div class="pr-card">
        <h3>Student Accounts</h3>

        <!-- FILTERS -->
        <form method="GET" id="filterForm">
            <input type="hidden" name="adminsection" value="password_resets">
            <div class="filter-bar">
                <div>
                    <label>Course</label>
                    <select name="f_course" onchange="document.getElementById('filterForm').submit();">
                        <option value="">All Courses</option>
                        <?php foreach ($courses_list as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $f_course === $c ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Year</label>
                    <select name="f_year" onchange="document.getElementById('filterForm').submit();">
                        <option value="">All Years</option>
                        <?php foreach ($years_list as $y): ?>
                            <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $f_year === $y ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($y); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Section</label>
                    <select name="f_section" onchange="document.getElementById('filterForm').submit();">
                        <option value="">All Sections</option>
                        <?php foreach ($sections_list as $sec): ?>
                            <option value="<?php echo htmlspecialchars($sec); ?>" <?php echo $f_section === $sec ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sec); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <?php if ($f_course !== '' || $f_year !== '' || $f_section !== ''): ?>
                        <a href="?adminsection=password_resets" class="pr-btn secondary"
                           style="text-decoration:none; display:inline-flex; align-items:center; padding:11px 20px;">
                            Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <div class="pr-table-wrap">
            <table class="pr-table">
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Last Name</th>
                        <th>First Name</th>
                        <th>Middle Name</th>
                        <th>Birthdate</th>
                        <th>Course</th>
                        <th>Year</th>
                        <th>Section</th>
                        <th>Account Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($students_data)): ?>
                    <tr><td colspan="10" style="text-align:center; padding:24px; color:#999;">
                        No students found<?php echo ($f_course || $f_year || $f_section) ? ' for the selected filters' : ''; ?>.
                    </td></tr>
                <?php else: ?>
                    <?php
                    $last_group = '';
                    foreach ($students_data as $s):
                        $has_account   = !empty($s['account_id']);
                        $is_pending    = intval($s['pending_reset']) > 0;
                        $just_flagged  = ($done_flag_student !== '' && $done_flag_student === $s['chmsu_student_id']);

                        // Group header
                        $group_key = $s['chmsu_course'] . '|' . $s['chmsu_year'] . '|' . $s['chmsu_section'];
                        $group_label = $s['chmsu_course'] . ' — Year ' . $s['chmsu_year'] . ' — Section ' . $s['chmsu_section'];
                    ?>
                        <?php if ($group_key !== $last_group): $last_group = $group_key; ?>
                            <tr class="group-row">
                                <td colspan="10"><?php echo htmlspecialchars($group_label); ?></td>
                            </tr>
                        <?php endif; ?>

                        <tr>
                            <td>
                                <strong class="<?php echo $has_account ? '' : 'sid-red'; ?>">
                                    <?php echo htmlspecialchars($s['chmsu_student_id']); ?>
                                </strong>
                                <?php if ($is_pending): ?>
                                    <span class="badge-pending">Pending Reset</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($s['chmsu_last_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['chmsu_first_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['chmsu_middle_name'] ?: '—'); ?></td>
                            <td><?php echo $s['chmsu_birthdate'] ? date('M d, Y', strtotime($s['chmsu_birthdate'])) : '—'; ?></td>
                            <td class="meta-col"><strong><?php echo htmlspecialchars($s['chmsu_course']); ?></strong></td>
                            <td class="meta-col">Year <?php echo htmlspecialchars($s['chmsu_year']); ?></td>
                            <td class="meta-col">Section <?php echo htmlspecialchars($s['chmsu_section']); ?></td>
                            <td>
                                <?php if ($has_account): ?>
                                    <span class="badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge-none">No Account</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$has_account): ?>
                                    <button type="button" class="pr-btn secondary"
                                            disabled title="Student has no account yet">
                                        Cannot Reset
                                    </button>
                                <?php elseif ($is_pending): ?>
                                    <button type="button" class="pr-btn pending-reset" disabled>
                                        Resetted
                                    </button>
                                <?php elseif ($just_flagged): ?>
                                    <button type="button" class="pr-btn done" disabled>
                                        Done Reset
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="pr-btn secondary"
                                            onclick="fillFlagForm('<?php echo addslashes($s['chmsu_student_id']); ?>')">
                                        Reset
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="hint" style="margin-top:10px;">
            <strong style="color:#c82333;">Red student ID</strong> = no account yet (cannot reset).
            <strong style="color:#856404;">Resetted</strong> = flagged but student hasn't set a new password yet.
            <strong style="color:#28a745;">Done Reset</strong> = just flagged on this page.
            Once the student completes the reset, the button becomes <strong>Reset</strong> again.
        </p>
    </div>
</div>

<script>
function fillFlagForm(studentId) {
    var form = document.querySelector('input[name="student_id"]');
    if (form) {
        form.value = studentId;
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        form.focus();
    }
}
</script>