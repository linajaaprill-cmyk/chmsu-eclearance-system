<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU E-Clearance System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Hide Windows activation messages */
        .osano-cm-window,
        .activation-message,
        .watermark,
        [class*="activate"],
        [id*="activate"] {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            height: 0 !important;
            overflow: hidden !important;
        }
        body::before,
        body::after {
            display: none !important;
            content: none !important;
        }
        .plain-table td,
        .plain-table th {
            padding: 8px 10px;
            vertical-align: middle;
        }
        
        /* ============================================ */
        /* HEADER LOGO (REMOVED / HIDDEN)               */
        /* ============================================ */
        .header-logo {
            display: none !important;
        }
        
        .header {
            padding: 12px 20px;
            display: flex;
            align-items: center;
        }
        .header-title {
            margin-left: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .header-title h1 {
            margin: 0;
            line-height: 1.2;
        }
        .header-title p {
            margin: 2px 0 0 0;
            line-height: 1.2;
        }

        /* ============================================ */
        /* HEADER ICON BUTTONS (History + Help + Msgs)   */
        /* ============================================ */
        .hdr-icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin-left: 10px;
            padding: 6px 10px;
            border: 1px solid rgba(255,255,255,0.35);
            border-radius: 4px;
            background: transparent;
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s;
            vertical-align: middle;
            font-family: 'Times New Roman', Times, serif;
        }
        .hdr-icon-btn:hover {
            background: rgba(255,255,255,0.18);
            border-color: rgba(255,255,255,0.55);
            color: #ffffff;
        }
        .hdr-icon-btn.active {
            background: rgba(255,255,255,0.22);
            border-color: #f1c40f;
        }

        /* Small badge for unread messages */
        .hdr-icon-btn .hdr-icon-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: #e74c3c;
            color: #ffffff;
            font-size: 9px;
            font-weight: bold;
            padding: 1px 6px;
            border-radius: 10px;
            min-width: 16px;
            text-align: center;
            border: 2px solid #1b4d3e;
            line-height: 1.2;
        }

        /* Hover name tooltip */
        .hdr-icon-btn .hdr-icon-name {
            position: absolute;
            top: 110%;
            left: 50%;
            transform: translateX(-50%) translateY(-4px);
            background: #0f3b2f;
            color: #ffffff;
            font-family: 'Times New Roman', Times, serif;
            font-size: 11px;
            font-weight: normal;
            padding: 4px 10px;
            border-radius: 4px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s, transform 0.2s, visibility 0.2s;
            pointer-events: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            z-index: 10001;
        }
        .hdr-icon-btn .hdr-icon-name::before {
            content: "";
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-bottom-color: #0f3b2f;
        }
        .hdr-icon-btn:hover .hdr-icon-name {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }

        /* ============================================ */
        /* HELP PANEL (slides in from right)             */
        /* ============================================ */
        .help-panel-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            z-index: 99998;
            backdrop-filter: blur(2px);
        }
        .help-panel-overlay.show { display: block; }

        .help-panel {
            position: fixed;
            top: 0; right: 0;
            width: 480px;
            max-width: 94%;
            height: 100%;
            background: #ffffff;
            box-shadow: -8px 0 30px rgba(0,0,0,0.25);
            z-index: 99999;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            font-family: 'Times New Roman', Times, serif;
        }
        .help-panel.show {
            transform: translateX(0);
        }

        .help-panel-header {
            background: #1b4d3e;
            color: white;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .help-panel-header h3 {
            font-size: 16px;
            font-weight: normal;
            margin: 0;
        }
        .help-panel-header p {
            font-size: 11px;
            opacity: 0.8;
            margin: 2px 0 0 0;
        }
        .help-panel-close {
            background: transparent;
            border: none;
            color: white;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
        }

        .help-panel-body {
            padding: 18px 20px;
            overflow-y: auto;
            flex: 1;
        }

        .help-section {
            margin-bottom: 22px;
        }
        .help-section h4 {
            font-size: 13px;
            color: #1b4d3e;
            margin: 0 0 8px 0;
            padding-bottom: 6px;
            border-bottom: 1px solid #e0e0e0;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .help-section p {
            font-size: 12.5px;
            color: #444;
            line-height: 1.65;
            margin: 0 0 8px 0;
        }
        .help-section ul {
            padding-left: 20px;
            margin: 0 0 8px 0;
        }
        .help-section li {
            font-size: 12.5px;
            color: #444;
            line-height: 1.65;
            margin-bottom: 5px;
        }
        .help-section strong {
            color: #1b4d3e;
        }
        .help-step {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: flex-start;
        }
        .help-step-num {
            flex-shrink: 0;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #1b4d3e;
            color: white;
            font-size: 11px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .help-step-text {
            font-size: 12.5px;
            color: #444;
            line-height: 1.6;
            padding-top: 2px;
        }
        .help-tip {
            background: #f0f8ff;
            border-left: 3px solid #1b4d3e;
            padding: 8px 12px;
            font-size: 12px;
            color: #333;
            margin: 10px 0;
            border-radius: 4px;
        }
        .help-tip strong { color: #1b4d3e; }

        .help-footer-tip {
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px dashed #ccc;
            font-size: 11px;
            color: #888;
            text-align: center;
            line-height: 1.5;
        }

        /* Dark mode */
        body.dark-mode .help-panel {
            background: #1a1a1a;
            color: #fff;
        }
        body.dark-mode .help-panel-header {
            background: #0f3b2f;
        }
        body.dark-mode .help-section h4 {
            color: #f1c40f;
            border-bottom-color: #333;
        }
        body.dark-mode .help-section p,
        body.dark-mode .help-section li,
        body.dark-mode .help-step-text {
            color: #ccc;
        }
        body.dark-mode .help-section strong {
            color: #f1c40f;
        }
        body.dark-mode .help-tip {
            background: #1a2c3a;
            color: #ddd;
            border-left-color: #f1c40f;
        }
        body.dark-mode .help-tip strong { color: #f1c40f; }
        body.dark-mode .help-step-num {
            background: #f1c40f;
            color: #000;
        }
        body.dark-mode .help-footer-tip {
            border-top-color: #333;
            color: #999;
        }

        @media (max-width: 640px) {
            .hdr-icon-btn {
                margin-left: 6px;
                padding: 6px 8px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>

<?php
// ============================================
// ROLE DETECTION — for History + Help + Messages
// ============================================
$__hdr_history_url = '';
$__hdr_on_history  = false;
$__hdr_role_label  = 'User';
$__hdr_role_key    = '';

if (function_exists('getHistoryUrl')) {
    $__hdr_history_url = getHistoryUrl();
}

if (isset($_SESSION['admin'])) {
    if (!$__hdr_history_url) $__hdr_history_url = '?adminsection=history';
    $__hdr_role_label = 'Admin';
    $__hdr_role_key   = 'admin';
    // HIDE HISTORY FOR ADMIN
    $__hdr_history_url = '';
} elseif (isset($_SESSION['office'])) {
    if ($_SESSION['office'] === 'Registrar') {
        if (!$__hdr_history_url) $__hdr_history_url = '?section=history';
        $__hdr_role_label = 'Registrar';
        $__hdr_role_key   = 'registrar';
    } else {
        if (!$__hdr_history_url) $__hdr_history_url = '?officesection=history';
        $__hdr_role_label = 'Office';
        $__hdr_role_key   = 'office';
    }
} elseif (isset($_SESSION['student'])) {
    if (!$__hdr_history_url) $__hdr_history_url = '?view=history';
    $__hdr_role_label = 'Student';
    $__hdr_role_key   = 'student';
}

if (function_exists('isHistoryActive')) {
    $__hdr_on_history = isHistoryActive();
} else {
    if (isset($_GET['adminsection'])   && $_GET['adminsection']  === 'history') $__hdr_on_history = true;
    if (isset($_GET['officesection'])  && $_GET['officesection'] === 'history') $__hdr_on_history = true;
    if (isset($_GET['section'])        && $_GET['section']       === 'history') $__hdr_on_history = true;
    if (isset($_GET['view'])           && $_GET['view']          === 'history') $__hdr_on_history = true;
}

$__hdr_show_help = ($__hdr_role_key !== '');

// ============================================
// MESSAGES — every role except Admin
// ============================================
$__hdr_messages_url      = '';
$__hdr_on_messages       = false;
$__hdr_messages_unread   = 0;
$__hdr_show_messages     = false;

if ($__hdr_role_key === 'student') {
    $__hdr_messages_url = '?view=chat';
    $__hdr_on_messages  = (isset($_GET['view']) && $_GET['view'] === 'chat');
    $__hdr_show_messages = true;
} elseif ($__hdr_role_key === 'office') {
    $__hdr_messages_url = '?officesection=chat';
    $__hdr_on_messages  = (isset($_GET['officesection']) && $_GET['officesection'] === 'chat');
    $__hdr_show_messages = true;
} elseif ($__hdr_role_key === 'registrar') {
    $__hdr_messages_url = '?section=chat';
    $__hdr_on_messages  = (isset($_GET['section']) && $_GET['section'] === 'chat');
    $__hdr_show_messages = true;
}
// Admin → no messages (as requested)

// ============================================
// UNREAD MESSAGES COUNT (for badge)
// ============================================
if ($__hdr_show_messages && isset($conn)) {
    if ($__hdr_role_key === 'student') {
        $sid = $_SESSION['student'];
        $q = $conn->query("SELECT SUM(student_unread) as total FROM chmsu_chat_conversations WHERE student_id='$sid'");
        if ($q) $__hdr_messages_unread = intval($q->fetch_assoc()['total']);
    } elseif ($__hdr_role_key === 'office' || $__hdr_role_key === 'registrar') {
        $on = $_SESSION['office'];
        $on_esc = $conn->real_escape_string($on);
        $q = $conn->query("SELECT SUM(office_unread) as total FROM chmsu_chat_conversations WHERE office_name='$on_esc'");
        if ($q) $__hdr_messages_unread = intval($q->fetch_assoc()['total']);
    }
}
?>

<?php if ($__hdr_history_url || $__hdr_show_messages || $__hdr_show_help || $__hdr_role_key === 'admin'): ?>
<script>
(function() {
    if (window.__hdrIconBtnsWired) return;
    window.__hdrIconBtnsWired = true;

    document.addEventListener('DOMContentLoaded', function() {
        var HISTORY_URL     = <?php echo json_encode($__hdr_history_url); ?>;
        var ON_HISTORY      = <?php echo $__hdr_on_history ? 'true' : 'false'; ?>;
        var ROLE_LABEL      = <?php echo json_encode($__hdr_role_label); ?>;

        var MESSAGES_URL    = <?php echo json_encode($__hdr_messages_url); ?>;
        var ON_MESSAGES     = <?php echo $__hdr_on_messages ? 'true' : 'false'; ?>;
        var MESSAGES_UNREAD = <?php echo intval($__hdr_messages_unread); ?>;
        var SHOW_MESSAGES   = <?php echo $__hdr_show_messages ? 'true' : 'false'; ?>;

        var SHOW_HISTORY    = <?php echo $__hdr_history_url ? 'true' : 'false'; ?>;

        var headers = document.querySelectorAll('.header');
        if (!headers.length) return;

        headers.forEach(function(hdr) {
            if (hdr.querySelector('.hdr-history-icon')) return;

            var titleBlock = hdr.querySelector('.header-title');
            var insertAfter = null; // last inserted element

            // ============================================
            // 1. HISTORY ICON
            // ============================================
            if (SHOW_HISTORY) {
                var h = document.createElement('a');
                h.href  = HISTORY_URL;
                h.title = 'History (' + ROLE_LABEL + ')';
                h.className = 'hdr-icon-btn hdr-history-icon' + (ON_HISTORY ? ' active' : '');
                h.innerHTML = '<i class="fas fa-history"></i>' +
                              '<span class="hdr-icon-name">History</span>';

                if (titleBlock && titleBlock.parentNode === hdr) {
                    if (titleBlock.nextSibling) {
                        hdr.insertBefore(h, titleBlock.nextSibling);
                    } else {
                        hdr.appendChild(h);
                    }
                } else {
                    hdr.appendChild(h);
                }
                insertAfter = h;
            }

            // ============================================
            // 1.5 ADMIN-ONLY REPORTS BUTTON
            // ============================================
            <?php if ($__hdr_role_key === 'admin'): ?>
            (function(){
                var rep = document.createElement('a');
                rep.href  = '?adminsection=reports';
                rep.title = 'Reports (Admin)';
                rep.className = 'hdr-icon-btn hdr-reports-icon' 
                             + (window.location.search.indexOf('adminsection=reports') !== -1 ? ' active' : '');
                rep.innerHTML = '<i class="fas fa-chart-bar"></i>' +
                               '<span class="hdr-icon-name">Reports</span>';

                if (insertAfter && insertAfter.parentNode === hdr) {
                    if (insertAfter.nextSibling) {
                        hdr.insertBefore(rep, insertAfter.nextSibling);
                    } else {
                        hdr.appendChild(rep);
                    }
                } else if (titleBlock && titleBlock.parentNode === hdr) {
                    if (titleBlock.nextSibling) {
                        hdr.insertBefore(rep, titleBlock.nextSibling);
                    } else {
                        hdr.appendChild(rep);
                    }
                } else {
                    hdr.appendChild(rep);
                }
                insertAfter = rep;
            })();
            <?php endif; ?>

            // ============================================
            // 1.6 ADMIN-ONLY PASSWORD RESET BUTTON
            // ============================================
            <?php if ($__hdr_role_key === 'admin'): ?>
            (function(){
                var pw = document.createElement('a');
                pw.href  = '?adminsection=password_resets';
                pw.title = 'Student Passwords (Admin)';
                pw.className = 'hdr-icon-btn hdr-passwords-icon'
                             + (window.location.search.indexOf('adminsection=password_resets') !== -1 ? ' active' : '');
                pw.innerHTML = '<i class="fas fa-key"></i>' +
                               '<span class="hdr-icon-name">Student Passwords</span>';

                if (insertAfter && insertAfter.parentNode === hdr) {
                    if (insertAfter.nextSibling) {
                        hdr.insertBefore(pw, insertAfter.nextSibling);
                    } else {
                        hdr.appendChild(pw);
                    }
                } else if (titleBlock && titleBlock.parentNode === hdr) {
                    if (titleBlock.nextSibling) {
                        hdr.insertBefore(pw, titleBlock.nextSibling);
                    } else {
                        hdr.appendChild(pw);
                    }
                } else {
                    hdr.appendChild(pw);
                }
                insertAfter = pw;
            })();
            <?php endif; ?>

            // ============================================
            // 1.7 OFFICE / REGISTRAR CHANGE PASSWORD BUTTON
            // ============================================
            <?php if ($__hdr_role_key === 'office' || $__hdr_role_key === 'registrar'): ?>
            (function(){
                var cp = document.createElement('button');
                cp.type = 'button';
                cp.title = 'Change Password (' + ROLE_LABEL + ')';
                cp.className = 'hdr-icon-btn hdr-changepw-icon';
                cp.innerHTML = '<i class="fas fa-key"></i>' +
                               '<span class="hdr-icon-name">Change Password</span>';
                cp.onclick = window.__openChangePwModal;

                if (insertAfter && insertAfter.parentNode === hdr) {
                    if (insertAfter.nextSibling) {
                        hdr.insertBefore(cp, insertAfter.nextSibling);
                    } else {
                        hdr.appendChild(cp);
                    }
                } else if (titleBlock && titleBlock.parentNode === hdr) {
                    if (titleBlock.nextSibling) {
                        hdr.insertBefore(cp, titleBlock.nextSibling);
                    } else {
                        hdr.appendChild(cp);
                    }
                } else {
                    hdr.appendChild(cp);
                }
                insertAfter = cp;
            })();
            <?php endif; ?>

            // ============================================
            // 2. MESSAGES ICON (except Admin)
            // ============================================
            if (SHOW_MESSAGES && MESSAGES_URL) {
                var m = document.createElement('a');
                m.href  = MESSAGES_URL;
                m.title = 'Messages (' + ROLE_LABEL + ')';
                m.className = 'hdr-icon-btn hdr-messages-icon' + (ON_MESSAGES ? ' active' : '');

                var badgeHTML = '';
                if (MESSAGES_UNREAD > 0) {
                    var badgeText = MESSAGES_UNREAD > 99 ? '99+' : MESSAGES_UNREAD;
                    badgeHTML = '<span class="hdr-icon-badge" id="hdrMessagesBadge">' + badgeText + '</span>';
                } else {
                    badgeHTML = '<span class="hdr-icon-badge" id="hdrMessagesBadge" style="display:none;">0</span>';
                }

                m.innerHTML = '<i class="fas fa-comment-dots"></i>' +
                              '<span class="hdr-icon-name">Messages</span>' +
                              badgeHTML;

                if (insertAfter && insertAfter.parentNode === hdr) {
                    if (insertAfter.nextSibling) {
                        hdr.insertBefore(m, insertAfter.nextSibling);
                    } else {
                        hdr.appendChild(m);
                    }
                } else if (titleBlock && titleBlock.parentNode === hdr) {
                    if (titleBlock.nextSibling) {
                        hdr.insertBefore(m, titleBlock.nextSibling);
                    } else {
                        hdr.appendChild(m);
                    }
                } else {
                    hdr.appendChild(m);
                }
                insertAfter = m;
            }
        });

        // ============================================
        // LIVE UNREAD BADGE UPDATE (every 15s)
        // Only for messages shown
        // ============================================
        <?php if ($__hdr_show_messages): ?>
        function refreshMessagesBadge() {
            var badge = document.getElementById('hdrMessagesBadge');
            if (!badge) return;

            var formData = new FormData();
            formData.append('action', 'get_unread_count');

            var url = <?php
                if ($__hdr_role_key === 'student')        echo json_encode('?view=chat');
                elseif ($__hdr_role_key === 'office')     echo json_encode('?officesection=chat');
                elseif ($__hdr_role_key === 'registrar')  echo json_encode('?section=chat');
                else                                       echo json_encode('');
            ?>;
            if (!url) return;

            fetch(url, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && typeof data.total !== 'undefined') {
                        var n = parseInt(data.total) || 0;
                        if (n > 0) {
                            badge.textContent = (n > 99 ? '99+' : n);
                            badge.style.display = '';
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                })
                .catch(function() { /* silent */ });
        }

        setInterval(refreshMessagesBadge, 15000);
        <?php endif; ?>
    });
})();
</script>
<?php endif; ?>


<?php if ($__hdr_show_help): ?>
<!-- ============================================ -->
<!-- HELP BUTTON — icon only, hover shows "Help"  -->
<!-- Panel content changes per role               -->
<!-- ============================================ -->
<script>
(function() {
    if (window.__hdrHelpBtnWired) return;
    window.__hdrHelpBtnWired = true;

    document.addEventListener('DOMContentLoaded', function() {
        var ROLE = <?php echo json_encode($__hdr_role_key); ?>;
        var ROLE_LABEL = <?php echo json_encode($__hdr_role_label); ?>;

        var HELP_CONTENT = {
            admin: {
                title: 'Admin Manual',
                subtitle: 'System Administrator Guide',
                sections: [
                    {
                        h: 'What you can do',
                        items: [
                            'Manage <strong>courses, offices, sections, school years, and semesters</strong>.',
                            'View <strong>reports</strong> and the <strong>activity log</strong>.',
                            'Access <strong>clearance history</strong> across all semesters.'
                        ]
                    },
                    {
                        h: 'Common Tasks',
                        steps: [
                            'Go to <strong>Maintenance &rarr; Courses</strong> to add a new course.',
                            'Go to <strong>Maintenance &rarr; Semesters</strong> to set the current semester.',
                            'Click <strong>Set as Current</strong> on a semester to activate it (requires OTP).',
                            'Use <strong>History</strong> to see past semesters and student records.'
                        ]
                    },
                    {
                        h: 'Safety Tips',
                        tips: [
                            'Deleting items requires <strong>OTP verification</strong> sent to your Gmail.',
                            'Once you advance to a new semester, old records are <strong>archived</strong> but can be restored.',
                            'Make sure your Gmail is connected from the dashboard first.'
                        ]
                    }
                ]
            },
            office: {
                title: 'Office Manual',
                subtitle: 'Office Portal Guide',
                sections: [
                    {
                        h: 'What you can do',
                        items: [
                            'Approve or decline student <strong>clearance</strong>.',
                            'Post <strong>advisories</strong> and mention students, sections, or courses.',
                            'Chat with students individually or by <strong>section group</strong>.',
                            'View your <strong>clearance history</strong> for past semesters.'
                        ]
                    },
                    {
                        h: 'Common Tasks',
                        steps: [
                            'Open <strong>Clearance</strong> to approve/decline pending students.',
                            'Post announcements via <strong>Feed Updates</strong> &mdash; pick Students / Sections / Courses mode.',
                            'Mentioned students receive <strong>in-app + Gmail</strong> notifications.',
                            'Use <strong>Messages</strong> to reply to student inquiries.'
                        ]
                    },
                    {
                        h: 'Safety Tips',
                        tips: [
                            'Posts can be deleted with <strong>OTP verification</strong>.',
                            'Approving a student locks their clearance for the current semester.',
                            'Keep descriptions clear and use mentions so students get notified.'
                        ]
                    }
                ]
            },
            registrar: {
                title: 'Registrar Manual',
                subtitle: 'Registrar Portal Guide',
                sections: [
                    {
                        h: 'What you can do',
                        items: [
                            'Manage the <strong>master list</strong> of students.',
                            'Add, import, and archive students.',
                            'Promote students to the next year level.',
                            'View <strong>reports</strong> and <strong>clearance history</strong>.'
                        ]
                    },
                    {
                        h: 'Common Tasks',
                        steps: [
                            'Go to <strong>Master List</strong> to add or import students.',
                            'Use <strong>Promotion</strong> at the end of the school year to move students up.',
                            'Check <strong>Clearance</strong> to review student status across offices.',
                            'Export lists from Reports as Excel or Word.'
                        ]
                    },
                    {
                        h: 'Safety Tips',
                        tips: [
                            'Always verify student details before adding or importing.',
                            'Archived students are hidden from active lists but can be restored.',
                            'Keep student IDs consistent (auto-generated on add).'
                        ]
                    }
                ]
            },
            student: {
                title: 'Student Manual',
                subtitle: 'Student Portal Guide',
                sections: [
                    {
                        h: 'What you can do',
                        items: [
                            'Check your <strong>clearance progress</strong> per office.',
                            'View advisories and announcements in the <strong>Updates Feed</strong>.',
                            'Chat with offices in <strong>Messages</strong>.',
                            'View past semester clearance in <strong>History</strong>.'
                        ]
                    },
                    {
                        h: 'Common Tasks',
                        steps: [
                            'Open <strong>My Clearance</strong> to see which offices you still need.',
                            'Check the <strong>Updates Feed</strong> for advisories and mentions.',
                            'Reply to offices through <strong>Messages</strong>.',
                            'Once fully cleared, download your <strong>Certificate</strong> from clearance page.'
                        ]
                    },
                    {
                        h: 'Safety Tips',
                        tips: [
                            'Keep your password safe &mdash; don\'t share it with anyone.',
                            'A mentioned advisory means an office needs your attention.',
                            'Check your notifications regularly so you don\'t miss deadlines.'
                        ]
                    }
                ]
            }
        };

        var CONTENT = HELP_CONTENT[ROLE] || HELP_CONTENT.student;

        function buildPanelHTML() {
            var html = '';
            CONTENT.sections.forEach(function(sec) {
                html += '<div class="help-section">';
                html += '<h4>' + sec.h + '</h4>';

                if (sec.items) {
                    html += '<ul>';
                    sec.items.forEach(function(it) { html += '<li>' + it + '</li>'; });
                    html += '</ul>';
                }
                if (sec.steps) {
                    sec.steps.forEach(function(st, i) {
                        html += '<div class="help-step">' +
                                    '<div class="help-step-num">' + (i + 1) + '</div>' +
                                    '<div class="help-step-text">' + st + '</div>' +
                                '</div>';
                    });
                }
                if (sec.tips) {
                    sec.tips.forEach(function(t) {
                        html += '<div class="help-tip">' + t + '</div>';
                    });
                }
                html += '</div>';
            });
            html += '<div class="help-footer-tip">CHMSU E-Clearance System &bull; Need more help? Contact your system administrator.</div>';
            return html;
        }

        function ensureHelpPanel() {
            if (document.getElementById('helpPanel')) return;

            var overlay = document.createElement('div');
            overlay.id = 'helpPanelOverlay';
            overlay.className = 'help-panel-overlay';
            overlay.onclick = window.closeHelpPanel;

            var panel = document.createElement('aside');
            panel.id = 'helpPanel';
            panel.className = 'help-panel';
            panel.innerHTML =
                '<header class="help-panel-header">' +
                    '<div>' +
                        '<h3><i class="fas fa-question-circle"></i> ' + CONTENT.title + '</h3>' +
                        '<p>' + CONTENT.subtitle + '</p>' +
                    '</div>' +
                    '<button type="button" class="help-panel-close" onclick="closeHelpPanel()">&times;</button>' +
                '</header>' +
                '<div class="help-panel-body">' + buildPanelHTML() + '</div>';

            document.body.appendChild(overlay);
            document.body.appendChild(panel);
        }

        window.openHelpPanel = function() {
            ensureHelpPanel();
            var overlay = document.getElementById('helpPanelOverlay');
            var panel = document.getElementById('helpPanel');
            if (!panel || !overlay) return;
            overlay.classList.add('show');
            panel.classList.add('show');
        };

        window.closeHelpPanel = function() {
            var overlay = document.getElementById('helpPanelOverlay');
            var panel = document.getElementById('helpPanel');
            if (!panel || !overlay) return;
            panel.classList.remove('show');
            overlay.classList.remove('show');
        };

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var panel = document.getElementById('helpPanel');
                if (panel && panel.classList.contains('show')) {
                    window.closeHelpPanel();
                }
            }
        });

        // Inject HELP button into every header
        var headers = document.querySelectorAll('.header');
        headers.forEach(function(hdr) {
            if (hdr.querySelector('.hdr-help-icon')) return;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.title = 'Help (' + ROLE_LABEL + ')';
            btn.className = 'hdr-icon-btn hdr-help-icon';
            btn.innerHTML = '<i class="fas fa-question-circle"></i>' +
                            '<span class="hdr-icon-name">Help</span>';
            btn.onclick = window.openHelpPanel;

            // Insert after Messages → History → title
            var anchor = hdr.querySelector('.hdr-messages-icon')
                       || hdr.querySelector('.hdr-history-icon');

            if (anchor && anchor.parentNode === hdr) {
                if (anchor.nextSibling) {
                    hdr.insertBefore(btn, anchor.nextSibling);
                } else {
                    hdr.appendChild(btn);
                }
            } else {
                var titleBlock = hdr.querySelector('.header-title');
                if (titleBlock && titleBlock.parentNode === hdr) {
                    if (titleBlock.nextSibling) {
                        hdr.insertBefore(btn, titleBlock.nextSibling);
                    } else {
                        hdr.appendChild(btn);
                    }
                } else {
                    hdr.appendChild(btn);
                }
            }
        });
    });
})();
</script>
<?php endif; ?>

<?php if ($__hdr_role_key === 'office' || $__hdr_role_key === 'registrar'): ?>
<!-- ============================================ -->
<!-- CHANGE PASSWORD MODAL (office/registrar)     -->
<!-- ============================================ -->
<script>
(function(){
    if (window.__hdrChangePwWired) return;
    window.__hdrChangePwWired = true;

    var AJAX_URL = <?php echo json_encode($_SERVER['PHP_SELF'] . '?ajax_change_password=1'); ?>;

    function ensureModal() {
        if (document.getElementById('changePwOverlay')) return;

        var style = document.createElement('style');
        style.textContent = `
            .cpw-overlay {
                display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5); z-index: 99998;
                align-items: center; justify-content: center;
                backdrop-filter: blur(3px);
            }
            .cpw-overlay.show { display: flex; }
            .cpw-modal {
                background: #fff; border-radius: 10px; width: 92%; max-width: 440px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                overflow: hidden; font-family: 'Times New Roman', Times, serif;
                animation: cpwSlide 0.25s ease;
            }
            @keyframes cpwSlide {
                from { opacity: 0; transform: translateY(-15px) scale(0.97); }
                to   { opacity: 1; transform: translateY(0) scale(1); }
            }
            .cpw-header {
                background: #1b4d3e; color: white; padding: 14px 18px;
                display: flex; justify-content: space-between; align-items: center;
            }
            .cpw-header h3 { margin: 0; font-size: 16px; font-weight: normal; }
            .cpw-close {
                background: transparent; border: none; color: white;
                font-size: 22px; cursor: pointer; line-height: 1;
            }
            .cpw-body { padding: 20px; }
            .cpw-step { display: none; }
            .cpw-step.active { display: block; }
            .cpw-label {
                display: block; font-size: 12px; color: #555;
                margin-bottom: 5px; font-weight: 600;
            }
            .cpw-input-wrap { position: relative; margin-bottom: 14px; }
            .cpw-input {
                width: 100%; padding: 10px 42px 10px 12px;
                border: 1px solid #ccc; border-radius: 6px;
                font-size: 14px; font-family: inherit; box-sizing: border-box;
            }
            .cpw-input:focus { border-color: #1b4d3e; outline: none; box-shadow: 0 0 0 3px rgba(27,77,62,0.1); }
            .cpw-eye {
                position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
                cursor: pointer; font-size: 16px; color: #888; user-select: none;
            }
            .cpw-eye.active { color: #1b4d3e; }
            .cpw-hint { font-size: 11px; color: #888; margin-top: -8px; margin-bottom: 12px; line-height: 1.5; }
            .cpw-btn {
                width: 100%; background: #1b4d3e; color: white; border: none;
                padding: 11px; border-radius: 6px; font-size: 14px;
                font-family: inherit; font-weight: 600; cursor: pointer;
                transition: background 0.2s;
            }
            .cpw-btn:hover:not(:disabled) { background: #2d6a4f; }
            .cpw-btn:disabled { background: #ccc; cursor: not-allowed; }
            .cpw-btn-secondary { background: #e4e6eb; color: #333; }
            .cpw-btn-secondary:hover:not(:disabled) { background: #d0d2d5; }
            .cpw-msg {
                padding: 10px 14px; border-radius: 6px; font-size: 13px;
                margin-bottom: 14px;
            }
            .cpw-msg.error   { background: #f8d7da; color: #721c24; border-left: 3px solid #dc3545; }
            .cpw-msg.success { background: #d4edda; color: #155724; border-left: 3px solid #28a745; }
            .cpw-info-line {
                font-size: 12px; color: #555; text-align: center;
                margin-bottom: 14px; line-height: 1.5;
            }
            .cpw-otp-input {
                width: 100%; padding: 12px; text-align: center;
                font-size: 20px; letter-spacing: 6px; font-weight: 600;
                border: 1px solid #ccc; border-radius: 6px;
                font-family: 'Courier New', monospace;
                margin-bottom: 12px; box-sizing: border-box;
            }
            .cpw-otp-input:focus { border-color: #1b4d3e; outline: none; box-shadow: 0 0 0 3px rgba(27,77,62,0.1); }
            .cpw-resend-row {
                text-align: center; font-size: 12px; color: #666;
                margin-bottom: 12px;
            }
            .cpw-resend-row a {
                color: #1b4d3e; text-decoration: none; font-weight: 600; cursor: pointer;
            }
            .cpw-resend-row a:hover { text-decoration: underline; }
            .cpw-resend-row a.disabled { color: #ccc; pointer-events: none; }
            body.dark-mode .cpw-modal { background: #242526; color: #e4e6eb; }
            body.dark-mode .cpw-body { background: #242526; }
            body.dark-mode .cpw-label { color: #e4e6eb; }
            body.dark-mode .cpw-input,
            body.dark-mode .cpw-otp-input {
                background: #3e4042; color: #e4e6eb; border-color: #4a4c4e;
            }
            body.dark-mode .cpw-btn-secondary { background: #3e4042; color: #e4e6eb; }
            body.dark-mode .cpw-info-line { color: #b0b3b8; }
            body.dark-mode .cpw-resend-row { color: #b0b3b8; }
        `;
        document.head.appendChild(style);

        var ov = document.createElement('div');
        ov.id = 'changePwOverlay';
        ov.className = 'cpw-overlay';
        ov.onclick = function(e){ if (e.target === ov) window.__closeChangePwModal(); };

        ov.innerHTML = `
            <div class="cpw-modal" onclick="event.stopPropagation()">
                <div class="cpw-header">
                    <h3>Change Password</h3>
                    <button type="button" class="cpw-close" onclick="window.__closeChangePwModal()">&times;</button>
                </div>
                <div class="cpw-body">

                    <!-- STEP 1: Old / New / Confirm -->
                    <div class="cpw-step active" id="cpwStep1">
                        <div id="cpwMsg1"></div>

                        <label class="cpw-label">Current Password</label>
                        <div class="cpw-input-wrap">
                            <input type="password" id="cpwOldPw" class="cpw-input" placeholder="Enter current password" autocomplete="current-password">
                            <span class="cpw-eye" onclick="window.__cpwToggle('cpwOldPw', this)">&#128065;</span>
                        </div>

                        <label class="cpw-label">New Password</label>
                        <div class="cpw-input-wrap">
                            <input type="password" id="cpwNewPw" class="cpw-input" placeholder="Min 8 characters" autocomplete="new-password">
                            <span class="cpw-eye" onclick="window.__cpwToggle('cpwNewPw', this)">&#128065;</span>
                        </div>
                        <div class="cpw-hint">At least 8 characters, with both letters and numbers.</div>

                        <label class="cpw-label">Confirm New Password</label>
                        <div class="cpw-input-wrap">
                            <input type="password" id="cpwConfirmPw" class="cpw-input" placeholder="Re-type new password" autocomplete="new-password">
                            <span class="cpw-eye" onclick="window.__cpwToggle('cpwConfirmPw', this)">&#128065;</span>
                        </div>

                        <button type="button" class="cpw-btn" id="cpwStep1Btn" onclick="window.__cpwRequestOTP()">Change</button>
                    </div>

                    <!-- STEP 2: OTP -->
                    <div class="cpw-step" id="cpwStep2">
                        <div id="cpwMsg2"></div>
                        <div class="cpw-info-line" id="cpwOtpInfo">
                            Enter the 6-digit code sent to your Gmail.
                        </div>

                        <input type="text" id="cpwOtpCode" class="cpw-otp-input" maxlength="6" inputmode="numeric" pattern="[0-9]*" placeholder="000000">

                        <div class="cpw-resend-row">
                            Didn't receive it? <a id="cpwResendBtn" onclick="window.__cpwResendOTP()">Resend OTP</a>
                        </div>

                        <button type="button" class="cpw-btn" id="cpwStep2Btn" onclick="window.__cpwVerifyOTP()">Verify &amp; Change Password</button>
                        <button type="button" class="cpw-btn cpw-btn-secondary" style="margin-top:8px;" onclick="window.__cpwBackToStep1()">Back</button>
                    </div>

                    <!-- STEP 3: Done -->
                    <div class="cpw-step" id="cpwStep3">
                        <div class="cpw-msg success" id="cpwMsg3">Password changed successfully!</div>
                        <button type="button" class="cpw-btn" onclick="window.__closeChangePwModal()">Done</button>
                    </div>

                </div>
            </div>
        `;
        document.body.appendChild(ov);
    }

    window.__openChangePwModal = function() {
        ensureModal();
        document.getElementById('cpwStep1').classList.add('active');
        document.getElementById('cpwStep2').classList.remove('active');
        document.getElementById('cpwStep3').classList.remove('active');
        document.getElementById('cpwOldPw').value = '';
        document.getElementById('cpwNewPw').value = '';
        document.getElementById('cpwConfirmPw').value = '';
        document.getElementById('cpwOtpCode').value = '';
        document.getElementById('cpwMsg1').innerHTML = '';
        document.getElementById('cpwMsg2').innerHTML = '';
        document.getElementById('changePwOverlay').classList.add('show');
        setTimeout(function(){ document.getElementById('cpwOldPw').focus(); }, 100);
    };

    window.__closeChangePwModal = function() {
        var ov = document.getElementById('changePwOverlay');
        if (ov) ov.classList.remove('show');
    };

    window.__cpwToggle = function(inputId, el) {
        var inp = document.getElementById(inputId);
        if (!inp) return;
        if (inp.type === 'password') { inp.type = 'text'; el.classList.add('active'); }
        else                         { inp.type = 'password'; el.classList.remove('active'); }
    };

    function showMsg(step, type, text) {
        var el = document.getElementById('cpwMsg' + step);
        if (el) el.innerHTML = text ? '<div class="cpw-msg ' + type + '">' + text + '</div>' : '';
    }

    window.__cpwRequestOTP = function() {
        var oldPw = document.getElementById('cpwOldPw').value;
        var newPw = document.getElementById('cpwNewPw').value;
        var cnfPw = document.getElementById('cpwConfirmPw').value;

        if (!oldPw) { showMsg(1, 'error', 'Please enter your current password.'); return; }
        if (newPw.length < 8) { showMsg(1, 'error', 'New password must be at least 8 characters.'); return; }
        if (!/[A-Za-z]/.test(newPw) || !/[0-9]/.test(newPw)) {
            showMsg(1, 'error', 'New password must contain both letters and numbers.'); return;
        }
        if (newPw !== cnfPw) { showMsg(1, 'error', 'New passwords do not match.'); return; }

        var btn = document.getElementById('cpwStep1Btn');
        btn.disabled = true; btn.textContent = 'Sending OTP...';
        showMsg(1, '', '');

        var fd = new FormData();
        fd.append('action', 'request_password_change');
        fd.append('old_password', oldPw);
        fd.append('new_password', newPw);
        fd.append('confirm_password', cnfPw);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false; btn.textContent = 'Change';
                if (data.success) {
                    document.getElementById('cpwStep1').classList.remove('active');
                    document.getElementById('cpwStep2').classList.add('active');
                    var info = document.getElementById('cpwOtpInfo');
                    if (data.masked_email) {
                        info.textContent = 'Enter the 6-digit code sent to ' + data.masked_email;
                    }
                    document.getElementById('cpwOtpCode').value = '';
                    setTimeout(function(){ document.getElementById('cpwOtpCode').focus(); }, 100);
                    startResendCooldown();
                } else {
                    showMsg(1, 'error', data.message || 'Request failed.');
                }
            })
            .catch(function(){
                btn.disabled = false; btn.textContent = 'Change';
                showMsg(1, 'error', 'Network error. Please try again.');
            });
    };

    var resendTimer = null;
    function startResendCooldown() {
        var link = document.getElementById('cpwResendBtn');
        if (!link) return;
        var secs = 30;
        link.classList.add('disabled');
        link.textContent = 'Resend OTP (30s)';
        if (resendTimer) clearInterval(resendTimer);
        resendTimer = setInterval(function(){
            secs--;
            if (secs <= 0) {
                clearInterval(resendTimer); resendTimer = null;
                link.classList.remove('disabled');
                link.textContent = 'Resend OTP';
            } else {
                link.textContent = 'Resend OTP (' + secs + 's)';
            }
        }, 1000);
    }

    window.__cpwResendOTP = function() {
        var link = document.getElementById('cpwResendBtn');
        if (link.classList.contains('disabled')) return;
        showMsg(2, '', '');
        var fd = new FormData();
        fd.append('action', 'resend_password_otp');

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.success) {
                    showMsg(2, 'success', data.message || 'OTP resent.');
                    startResendCooldown();
                } else {
                    showMsg(2, 'error', data.message || 'Failed to resend OTP.');
                }
            })
            .catch(function(){ showMsg(2, 'error', 'Network error.'); });
    };

    window.__cpwVerifyOTP = function() {
        var otp = document.getElementById('cpwOtpCode').value.trim();
        if (!/^\d{6}$/.test(otp)) {
            showMsg(2, 'error', 'Please enter the 6-digit OTP.');
            return;
        }
        var btn = document.getElementById('cpwStep2Btn');
        btn.disabled = true; btn.textContent = 'Verifying...';
        showMsg(2, '', '');

        var fd = new FormData();
        fd.append('action', 'verify_password_otp');
        fd.append('otp_code', otp);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false; btn.textContent = 'Verify & Change Password';
                if (data.success) {
                    document.getElementById('cpwStep2').classList.remove('active');
                    document.getElementById('cpwStep3').classList.add('active');
                    showMsg(3, 'success', data.message || 'Password changed successfully!');
                } else {
                    showMsg(2, 'error', data.message || 'Verification failed.');
                }
            })
            .catch(function(){
                btn.disabled = false; btn.textContent = 'Verify & Change Password';
                showMsg(2, 'error', 'Network error. Please try again.');
            });
    };

    window.__cpwBackToStep1 = function() {
        document.getElementById('cpwStep2').classList.remove('active');
        document.getElementById('cpwStep1').classList.add('active');
        showMsg(2, '', '');
    };

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            var ov = document.getElementById('changePwOverlay');
            if (ov && ov.classList.contains('show')) window.__closeChangePwModal();
        }
    });

    document.addEventListener('input', function(e){
        if (e.target && e.target.id === 'cpwOtpCode') {
            e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
        }
    });
})();
</script>
<?php endif; ?>

<!-- ============================================================ -->
<!-- UNIVERSAL IN-SCREEN VERIFICATION MODAL (Centered Popup)      -->
<!-- Replaces top "localhost says" dialogs with a center modal    -->
<!-- ============================================================ -->
<style>
@keyframes chmsuPopIn {
    0% { transform: scale(0.9); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}
#chmsuModalOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: 99999999;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
    padding: 16px;
    box-sizing: border-box;
}
#chmsuModalCard {
    background: #ffffff;
    border-radius: 12px;
    width: 100%;
    max-width: 420px;
    padding: 24px 22px;
    text-align: center;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.28);
    font-family: 'Times New Roman', Times, serif;
    animation: chmsuPopIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    box-sizing: border-box;
}
body.dark-mode #chmsuModalCard {
    background: #242526;
    color: #e4e6eb;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.6);
}
#chmsuModalIconWrap {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: #eef7f2;
    color: #1b4d3e;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin: 0 auto 14px auto;
}
body.dark-mode #chmsuModalIconWrap {
    background: #1f3328;
    color: #f1c40f;
}
#chmsuModalTitle {
    margin: 0 0 10px 0;
    font-size: 18px;
    color: #1b4d3e;
    font-weight: bold;
}
body.dark-mode #chmsuModalTitle {
    color: #f1c40f;
}
#chmsuModalMessage {
    margin: 0 0 22px 0;
    font-size: 14px;
    color: #444;
    line-height: 1.55;
    word-break: break-word;
}
body.dark-mode #chmsuModalMessage {
    color: #ccc;
}
.chmsu-modal-btn {
    padding: 9px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
    font-family: inherit;
    transition: background 0.15s, opacity 0.15s;
}
#chmsuModalCancelBtn {
    border: 1px solid #d1d5db;
    background: #f3f4f6;
    color: #374151;
}
#chmsuModalCancelBtn:hover {
    background: #e5e7eb;
}
body.dark-mode #chmsuModalCancelBtn {
    background: #3a3b3c;
    border-color: #555;
    color: #eee;
}
#chmsuModalConfirmBtn {
    border: none;
    background: #1b4d3e;
    color: #ffffff;
}
#chmsuModalConfirmBtn:hover {
    background: #2d6a4f;
}
body.dark-mode #chmsuModalConfirmBtn {
    background: #f1c40f;
    color: #000;
}
</style>

<div id="chmsuModalOverlay" role="dialog" aria-modal="true">
    <div id="chmsuModalCard">
        <div id="chmsuModalIconWrap">
            <i id="chmsuModalIcon" class="fas fa-question-circle"></i>
        </div>
        <h3 id="chmsuModalTitle">Confirmation</h3>
        <p id="chmsuModalMessage"></p>
        <div id="chmsuModalActions" style="display: flex; justify-content: center; gap: 12px;">
            <button type="button" id="chmsuModalCancelBtn" class="chmsu-modal-btn">Cancel</button>
            <button type="button" id="chmsuModalConfirmBtn" class="chmsu-modal-btn">Confirm</button>
        </div>
    </div>
</div>

<script>
(function() {
    var overlay = document.getElementById('chmsuModalOverlay');
    var card = document.getElementById('chmsuModalCard');
    var titleEl = document.getElementById('chmsuModalTitle');
    var msgEl = document.getElementById('chmsuModalMessage');
    var iconEl = document.getElementById('chmsuModalIcon');
    var cancelBtn = document.getElementById('chmsuModalCancelBtn');
    var confirmBtn = document.getElementById('chmsuModalConfirmBtn');

    var currentCallback = null;

    function openModal(options) {
        if (!overlay) overlay = document.getElementById('chmsuModalOverlay');
        if (!overlay) return;

        titleEl.textContent = options.title || 'Verification';
        msgEl.textContent = options.message || '';
        
        if (options.isAlert) {
            cancelBtn.style.display = 'none';
            confirmBtn.textContent = options.confirmText || 'OK';
            iconEl.className = options.icon || 'fas fa-info-circle';
        } else {
            cancelBtn.style.display = '';
            cancelBtn.textContent = options.cancelText || 'Cancel';
            confirmBtn.textContent = options.confirmText || 'Confirm';
            iconEl.className = options.icon || 'fas fa-question-circle';
        }

        currentCallback = options.onConfirm || null;
        overlay.style.display = 'flex';
        confirmBtn.focus();
    }

    function closeModal() {
        if (overlay) overlay.style.display = 'none';
        currentCallback = null;
    }

    if (cancelBtn) cancelBtn.onclick = closeModal;
    if (confirmBtn) {
        confirmBtn.onclick = function() {
            var cb = currentCallback;
            closeModal();
            if (typeof cb === 'function') cb();
        };
    }

    if (overlay) {
        overlay.onclick = function(e) {
            if (e.target === overlay) closeModal();
        };
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay && overlay.style.display === 'flex') {
            closeModal();
        }
    });

    // Public in-screen functions
    window.chmsuConfirm = function(message, onConfirm, onCancel, options) {
        openModal({
            title: (options && options.title) || 'Confirmation',
            message: message,
            isAlert: false,
            confirmText: (options && options.confirmText) || 'Confirm',
            cancelText: (options && options.cancelText) || 'Cancel',
            icon: (options && options.icon) || 'fas fa-question-circle',
            onConfirm: onConfirm
        });
    };

    window.chmsuAlert = function(message, onOk, options) {
        openModal({
            title: (options && options.title) || 'Notice',
            message: message,
            isAlert: true,
            confirmText: (options && options.confirmText) || 'OK',
            icon: (options && options.icon) || 'fas fa-info-circle',
            onConfirm: onOk
        });
    };

    // Override confirmLogout globally
    window.confirmLogout = function() {
        window.chmsuConfirm('Are you sure you want to logout?', function() {
            window.location.href = '?logout=1';
        }, null, { title: 'Confirm Logout', icon: 'fas fa-sign-out-alt' });
    };

    // Intercept clicks on links and buttons with confirm() or logout
    document.addEventListener('click', function(e) {
        var el = e.target.closest('a, button, input[type="submit"]');
        if (!el) return;

        if (el.__chmsu_confirmed) {
            delete el.__chmsu_confirmed;
            return;
        }

        var onclickAttr = el.getAttribute('onclick') || '';
        var isLogoutLink = (el.tagName === 'A' && el.href && el.href.indexOf('logout=1') !== -1);
        var hasConfirm = onclickAttr.indexOf('confirm(') !== -1;

        if (hasConfirm || isLogoutLink) {
            e.preventDefault();
            e.stopImmediatePropagation();

            var msg = 'Are you sure you want to proceed?';
            if (isLogoutLink) {
                msg = 'Are you sure you want to logout?';
            } else {
                var m = onclickAttr.match(/confirm\(\s*(['"])(.*?)\1\s*\)/);
                if (m && m[2]) msg = m[2];
            }

            window.chmsuConfirm(msg, function() {
                el.__chmsu_confirmed = true;
                if (el.tagName === 'A' && el.href) {
                    window.location.href = el.href;
                } else if (el.type === 'submit' && el.form) {
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    if (el.name) h.name = el.name;
                    if (el.value) h.value = el.value;
                    el.form.appendChild(h);
                    el.form.submit();
                } else {
                    el.click();
                }
            });
            return false;
        }
    }, true);

    // Intercept form submissions with onsubmit confirm()
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form) return;

        if (form.__chmsu_confirmed) {
            delete form.__chmsu_confirmed;
            return;
        }

        var onsubmitAttr = form.getAttribute('onsubmit') || '';
        if (onsubmitAttr.indexOf('confirm(') !== -1) {
            e.preventDefault();
            e.stopImmediatePropagation();

            var msg = 'Are you sure you want to proceed?';
            var m = onsubmitAttr.match(/confirm\(\s*(['"])(.*?)\1\s*\)/);
            if (m && m[2]) msg = m[2];

            window.chmsuConfirm(msg, function() {
                form.__chmsu_confirmed = true;
                form.submit();
            });
            return false;
        }
    }, true);
})();
</script>