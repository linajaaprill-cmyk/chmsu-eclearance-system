<?php
// ============================================================
// modules/registrar/clearance_status.php
// Registrar Clearance Status — Reuses the EXACT same office clearance interface
// Only difference: session office is ensured to be "Registrar"
// ADDED: 3 Yes/No verification columns (SHS SF10, JOTR, PSA Birth) with Select All
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure the Registrar is logged in as an office
if (!isset($_SESSION['office']) || $_SESSION['office'] != 'Registrar') {
    header("Location: ../index.php");
    exit;
}

// Force office context (in case it was unset somehow)
$_SESSION['office'] = 'Registrar';

// Set section flag for sidebar highlighting
$currentSection = 'clearance_status';
$currentOfficeSection = 'clearance';

// ============================================================
// ADDED — VERIFICATION TABLE (SHS SF10 / JOTR / PSA Birth = Yes or No)
// ============================================================
$conn->query("CREATE TABLE IF NOT EXISTS chmsu_registrar_verification (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    shs_sf10 TINYINT(1) NOT NULL DEFAULT 0,
    jotr TINYINT(1) NOT NULL DEFAULT 0,
    psa_birth TINYINT(1) NOT NULL DEFAULT 0,
    updated_by VARCHAR(100) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (!function_exists('rv_out')) {
    function rv_out($data) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
        echo '@@REGJSON@@' . json_encode($data);
        exit;
    }
}

// ADDED — AJAX: save one student / save many (Select All)
if (isset($_POST['action']) && in_array($_POST['action'], ['reg_verify_save', 'reg_verify_bulk'], true)) {
    $rv_fields = ['shs_sf10' => 'SHS SF10', 'jotr' => 'JOTR', 'psa_birth' => 'PSA Birth'];
    $rv_field = isset($_POST['field']) ? (string)$_POST['field'] : '';
    if (!isset($rv_fields[$rv_field])) {
        rv_out(['success' => false, 'message' => 'Invalid field']);
    }
    $rv_value = (intval($_POST['value'] ?? 0) === 1) ? 1 : 0;

    $rv_stmt = $conn->prepare("INSERT INTO chmsu_registrar_verification (student_id, `$rv_field`, updated_by)
                               VALUES (?, ?, 'Registrar')
                               ON DUPLICATE KEY UPDATE `$rv_field` = VALUES(`$rv_field`), updated_by = 'Registrar'");
    if (!$rv_stmt) {
        rv_out(['success' => false, 'message' => 'Database error']);
    }

    $rv_ids = [];
    if ($_POST['action'] === 'reg_verify_save') {
        $rv_ids[] = trim((string)($_POST['student_id'] ?? ''));
    } else {
        $decoded = json_decode((string)($_POST['ids'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $one) { $rv_ids[] = trim((string)$one); }
        }
    }

    $rv_saved = 0;
    foreach ($rv_ids as $rv_sid) {
        if ($rv_sid === '' || !preg_match('/^[A-Za-z0-9._\-]{1,50}$/', $rv_sid)) continue;
        $rv_stmt->bind_param('si', $rv_sid, $rv_value);
        if ($rv_stmt->execute()) $rv_saved++;
    }
    $rv_stmt->close();

    if ($rv_saved > 0 && function_exists('logActivity')) {
        logActivity($conn, 'Registrar', 'office',
            ($rv_value ? 'Verified ' : 'Unverified ') . $rv_fields[$rv_field] . ' for ' . $rv_saved . ' student(s)');
    }
    rv_out(['success' => true, 'saved' => $rv_saved]);
}

// ============================================================
// 1. CALCULATE WHICH STUDENTS ARE READY FOR THE REGISTRAR
// ============================================================
$total_other_offices = $conn->query("SELECT COUNT(*) as c FROM chmsu_offices WHERE office_name != 'Registrar'")->fetch_assoc()['c'];

$ready_students = [];
$students_query = $conn->query("SELECT chmsu_student_id FROM chmsu_students_master WHERE is_archived = 0");
while ($s = $students_query->fetch_assoc()) {
    $sid = $s['chmsu_student_id'];
    $cleared_others = $conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE student_id='$sid' AND office_name != 'Registrar' AND status='clear'")->fetch_assoc()['c'];
    if ($cleared_others >= $total_other_offices && $total_other_offices > 0) {
        $ready_students[] = $sid;
    }
}

// ============================================================
// 2. CAPTURE THE OFFICE CLEARANCE OUTPUT
// ============================================================
ob_start();
require __DIR__ . '/../office/clearance.php';
$office_clearance_html = ob_get_clean();

// ============================================================
// 3. INJECT THE LOCK LOGIC DIRECTLY INTO THE HTML
// ============================================================
$office_clearance_html = preg_replace_callback(
    '/(<form[^>]*>)(.*?)(<\/form>)/is',
    function($matches) use ($ready_students) {
        $form_open = $matches[1];
        $form_content = $matches[2];
        $form_close = $matches[3];

        $student_id = null;
        if (preg_match('/name="(approve|decline)"\s+value="([^"]+)"/i', $form_content, $id_match)) {
            $student_id = $id_match[2];
        }

        if ($student_id !== null && !in_array($student_id, $ready_students)) {
            $form_content = preg_replace('/<button([^>]*)>/i', '<button$1 disabled style="opacity: 0.5; cursor: not-allowed; pointer-events: none;" title="Pending other offices">', $form_content);
            $note = '<div style="color: #e74c3c; font-size: 10px; margin-bottom: 4px; font-weight: bold;"><i class="fas fa-lock"></i> Pending other offices</div>';
            $form_content = preg_replace('/(<button[^>]*>)/i', $note . '$1', $form_content, 1);
        }

        return $form_open . $form_content . $form_close;
    },
    $office_clearance_html
);

// ============================================================
// 4. REWRITE URLS SO THE REGISTRAR ROUTER WORKS
// ============================================================
$office_clearance_html = str_replace(
    '?officesection=clearance',
    '?section=clearance_status',
    $office_clearance_html
);

$office_clearance_html = str_replace(
    '<input type="hidden" name="officesection" value="clearance">',
    '<input type="hidden" name="section" value="clearance_status">',
    $office_clearance_html
);

// ============================================================
// 5. BUILD THE REGISTRAR SIDEBAR
// ============================================================
$chat_unread_count = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='Registrar'")->fetch_assoc()['total'] ?: 0;

$registrar_sidebar = '
    <div class="office-sidebar">
        <div class="sidebar-header">
            <h3>Registrar Portal</h3>
            <p>Student Records Management</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="?section=masterlist"><i class="fas fa-users"></i> Master List</a></li>
            <li><a href="?section=clearance"><i class="fas fa-clipboard-check"></i> Clearance</a></li>
            <li><a href="?section=clearance_status" class="active"><i class="fas fa-user-check"></i> Clearance Status</a></li>
            <li><a href="?section=reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?section=archived"><i class="fas fa-archive"></i> Archive</a></li>
            <li><a href="?section=promotion"><i class="fas fa-arrow-up"></i> Student Promotion</a></li>
            <li><a href="?section=note"><i class="fas fa-rss"></i> Feed Updates</a></li>
            <li>
                <a href="?section=chat" class="chat-link">
                    <i class="fas fa-comment-dots"></i> Messages
                    ' . ($chat_unread_count > 0 ? '<span class="chat-badge">' . $chat_unread_count . '</span>' : '') . '
                </a>
            </li>
            <li style="margin-top: 20px; border-top: 1px solid #2d6a4f;">
                <a href="#" onclick="confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
        <div style="padding: 12px 20px; font-size: 10px; color: #ddd; border-top: 1px solid #2d6a4f; margin-top: 20px;">
            Storage: ' . formatFileSize(getTotalUploadSize()) . ' / 100MB
            <div style="width:100%; height:3px; background:#2d6a4f; margin-top:4px; border-radius:2px;">
                <div style="width:' . min((getTotalUploadSize() / (100 * 1024 * 1024)) * 100, 100) . '%; height:100%; background:#f1c40f; border-radius:2px;"></div>
            </div>
        </div>
    </div>';

// ============================================================
// 6. SWAP SIDEBAR & OUTPUT
// ============================================================
$office_clearance_html = preg_replace(
    '/<div class="office-sidebar">.*?(?=<div class="main-content">)/s',
    $registrar_sidebar,
    $office_clearance_html,
    1
);

// ============================================================
// 7. ADDED — SHS SF10 / JOTR / PSA BIRTH (Yes / No) COLUMNS + SELECT ALL
// ============================================================
$rv_saved_rows = [];
$rv_res = $conn->query("SELECT student_id, shs_sf10, jotr, psa_birth FROM chmsu_registrar_verification");
if ($rv_res) {
    while ($rv_row = $rv_res->fetch_assoc()) {
        $rv_saved_rows[$rv_row['student_id']] = [
            'shs_sf10'  => intval($rv_row['shs_sf10']),
            'jotr'      => intval($rv_row['jotr']),
            'psa_birth' => intval($rv_row['psa_birth'])
        ];
    }
}
$rv_json = json_encode(
    (object)$rv_saved_rows,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$rv_block = <<<'RVBLOCK'
<style>
    /* Clean, professional column styling matching the table header exactly */
    th.rv-col, td.rv-col { 
        text-align: center; 
        vertical-align: middle; 
        min-width: 90px; 
        padding: 8px 5px;
        border-left: 1px solid #2d6a4f;
        border-right: 1px solid #2d6a4f;
    }
    th.rv-col {
        background-color: #1b4d3e; /* Matches the main table header green */
        color: #ffffff;
        font-weight: normal;
    }
    th.rv-col .rv-title { 
        font-size: 11px; 
        font-weight: bold; 
        line-height: 1.2; 
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    th.rv-col .rv-all { 
        display: inline-flex; 
        align-items: center; 
        gap: 4px; 
        font-size: 10px; 
        font-weight: normal; 
        cursor: pointer;
        background: rgba(255,255,255,0.2);
        color: white;
        padding: 2px 6px;
        border-radius: 10px;
        transition: 0.2s;
    }
    th.rv-col .rv-all:hover { background: rgba(255,255,255,0.35); }
    th.rv-col .rv-all input { margin: 0; width: 13px; height: 13px; cursor: pointer; accent-color: #f1c40f; }
    
    td.rv-col .rv-cell { 
        display: inline-flex; 
        align-items: center; 
        gap: 6px; 
        cursor: pointer; 
        font-size: 12px; 
        background: #f9f9f9;
        padding: 4px 10px;
        border-radius: 6px;
        border: 1px solid #eee;
        transition: 0.2s;
    }
    td.rv-col .rv-cell:hover { background: #eef7f2; border-color: #c8e6c9; }
    
    td.rv-col input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: #1b4d3e; margin: 0; }
    
    .rv-txt { font-weight: bold; min-width: 24px; text-align: left; }
    .rv-txt.rv-yes { color: #1e8449; }
    .rv-txt.rv-no { color: #999; }
    .rv-busy { opacity: .55; pointer-events: none; }
    
    /* Dark mode adjustments */
    body.dark-mode th.rv-col { background-color: #2c2c2c; color: #f1c40f; border-color: #444; }
    body.dark-mode td.rv-col { border-color: #333; }
    body.dark-mode td.rv-col .rv-cell { background: #1a1a1a; border-color: #333; }
    body.dark-mode td.rv-col .rv-cell:hover { background: #2c2c2c; }
    body.dark-mode .rv-txt.rv-yes { color: #6fdc9b; }
    body.dark-mode .rv-txt.rv-no { color: #aaa; }
    body.dark-mode td.rv-col input[type="checkbox"], body.dark-mode th.rv-col input[type="checkbox"] { accent-color: #f1c40f; }
    body.dark-mode th.rv-col .rv-all { background: #444; color: #ddd; }
    body.dark-mode th.rv-col .rv-all:hover { background: #555; }
</style>
<script>
(function () {
    var DATA = __RV_DATA__;
    var FIELDS = [
        { key: 'shs_sf10',  label: 'SHS SF10' },
        { key: 'jotr',      label: 'JOTR' },
        { key: 'psa_birth', label: 'PSA Birth' }
    ];

    function escAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function rvPost(fd) {
        return fetch(window.location.href, { method: 'POST', body: fd })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var i = t.lastIndexOf('@@REGJSON@@');
                if (i < 0) throw new Error('Invalid server response');
                return JSON.parse(t.slice(i + 11));
            });
    }

    function init() {
        // Find the main table. We no longer rely on buttons, so it works even when locked.
        var table = document.querySelector('.clearance-table') || document.querySelector('table');
        if (!table) return;

        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        var rows = tbody.querySelectorAll('tr');
        if (!rows.length) return;

        if (table.dataset.rvDone) return;
        table.dataset.rvDone = '1';

        if (table.parentElement && getComputedStyle(table.parentElement).overflowX === 'visible') {
            table.parentElement.style.overflowX = 'auto';
        }

        // Header cells
        var headRow = table.querySelector('thead tr') || table.querySelector('tr');
        var anchorTh = (headRow && headRow.cells.length > 1) ? headRow.cells[headRow.cells.length - 1] : null;
        
        FIELDS.forEach(function (f) {
            var th = document.createElement('th');
            th.className = 'rv-col';
            th.innerHTML =
                '<div class="rv-title">' + f.label + '</div>' +
                '<label class="rv-all"><input type="checkbox" class="rv-all-cb" data-field="' + f.key + '"> <span>All</span></label>';
            if (headRow) {
                if (anchorTh) headRow.insertBefore(th, anchorTh); else headRow.appendChild(th);
            }
        });

        // Data cells
        var validRows = 0;
        rows.forEach(function (tr) {
            if (tr.querySelector('.empty-state') || tr.cells.length < 3) return;
            
            var idCell = tr.cells[1];
            if (!idCell) return;
            var studentId = idCell.textContent.trim();
            
            if (!studentId) return;
            validRows++;

            var anchorTd = tr.cells[tr.cells.length - 1];
            
            FIELDS.forEach(function (f) {
                var on = DATA[studentId] && String(DATA[studentId][f.key]) === '1';
                var td = document.createElement('td');
                td.className = 'rv-col';
                td.innerHTML =
                    '<label class="rv-cell">' +
                    '<input type="checkbox" class="rv-cb" data-field="' + f.key + '" data-id="' + escAttr(studentId) + '"' + (on ? ' checked' : '') + '>' +
                    '<span class="rv-txt ' + (on ? 'rv-yes' : 'rv-no') + '">' + (on ? 'Yes' : 'No') + '</span>' +
                    '</label>';
                if (anchorTd) tr.insertBefore(td, anchorTd); else tr.appendChild(td);
            });
        });

        if (validRows === 0) return;

        function setLabel(cb) {
            var t = cb.nextElementSibling;
            if (!t) return;
            t.textContent = cb.checked ? 'Yes' : 'No';
            t.className = 'rv-txt ' + (cb.checked ? 'rv-yes' : 'rv-no');
        }
        function visibleBoxes(field) {
            return Array.prototype.filter.call(
                table.querySelectorAll('.rv-cb[data-field="' + field + '"]'),
                function (cb) { return cb.closest('tr').offsetParent !== null; }
            );
        }
        function syncHeader(field) {
            var head = table.querySelector('.rv-all-cb[data-field="' + field + '"]');
            if (!head) return;
            var boxes = visibleBoxes(field);
            var n = boxes.length, c = boxes.filter(function (b) { return b.checked; }).length;
            head.checked = n > 0 && c === n;
            head.indeterminate = c > 0 && c < n;
        }
        FIELDS.forEach(function (f) { syncHeader(f.key); });

        table.addEventListener('change', function (e) {
            var cb = e.target;
            if (cb.classList.contains('rv-cb')) {
                var want = cb.checked;
                
                // Ask for confirmation when manually clicking a single box
                var fieldName = cb.dataset.field === 'shs_sf10' ? 'SHS SF10' : (cb.dataset.field === 'jotr' ? 'JOTR' : 'PSA Birth');
                var confirmMsg = want 
                    ? 'Are you sure you want to verify this student\'s ' + fieldName + ' as PASSED?'
                    : 'Are you sure you want to unverify this student\'s ' + fieldName + '?';
                
                if (!confirm(confirmMsg)) {
                    cb.checked = !want; // revert visually
                    return;
                }

                setLabel(cb);
                syncHeader(cb.dataset.field);
                cb.classList.add('rv-busy');

                var fd = new FormData();
                fd.append('action', 'reg_verify_save');
                fd.append('student_id', cb.dataset.id);
                fd.append('field', cb.dataset.field);
                fd.append('value', want ? 1 : 0);
                rvPost(fd).then(function (res) {
                    cb.classList.remove('rv-busy');
                    if (!res || res.success !== true) throw new Error('save failed');
                }).catch(function () {
                    cb.classList.remove('rv-busy');
                    cb.checked = !want; 
                    setLabel(cb);
                    syncHeader(cb.dataset.field);
                    alert('Could not save. Please try again.');
                });
                return;
            }

            if (cb.classList.contains('rv-all-cb')) {
                var field = cb.dataset.field;
                var want2 = cb.checked;
                var boxes = visibleBoxes(field);
                var changed = [];
                boxes.forEach(function (b) {
                    if (b.checked !== want2) { b.checked = want2; setLabel(b); changed.push(b); }
                });
                cb.indeterminate = false;
                if (!changed.length) return;

                var fieldNameAll = field === 'shs_sf10' ? 'SHS SF10' : (field === 'jotr' ? 'JOTR' : 'PSA Birth');
                var confirmMsgAll = want2 
                    ? 'Are you sure you want to verify ' + fieldNameAll + ' for all ' + changed.length + ' selected students?'
                    : 'Are you sure you want to unverify ' + fieldNameAll + ' for all ' + changed.length + ' selected students?';
                
                if (!confirm(confirmMsgAll)) {
                    // revert visually
                    changed.forEach(function (b) { b.checked = !want2; setLabel(b); });
                    syncHeader(field);
                    return;
                }

                changed.forEach(function (b) { b.classList.add('rv-busy'); });
                var fd2 = new FormData();
                fd2.append('action', 'reg_verify_bulk');
                fd2.append('field', field);
                fd2.append('value', want2 ? 1 : 0);
                fd2.append('ids', JSON.stringify(changed.map(function (b) { return b.dataset.id; })));
                rvPost(fd2).then(function (res) {
                    changed.forEach(function (b) { b.classList.remove('rv-busy'); });
                    if (!res || res.success !== true) throw new Error('save failed');
                }).catch(function () {
                    changed.forEach(function (b) { b.classList.remove('rv-busy'); b.checked = !want2; setLabel(b); });
                    syncHeader(field);
                    alert('Could not save. Please try again.');
                });
            }
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>
RVBLOCK;
$rv_block = str_replace('__RV_DATA__', $rv_json, $rv_block);

$rv_pos = strripos($office_clearance_html, '</body>');
if ($rv_pos !== false) {
    $office_clearance_html = substr_replace($office_clearance_html, $rv_block, $rv_pos, 0);
} else {
    $office_clearance_html .= $rv_block;
}

echo $office_clearance_html;