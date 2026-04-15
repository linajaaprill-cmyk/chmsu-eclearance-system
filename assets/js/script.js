let confirmCallback = null;
let currentReqId = null;
let currentStudentId = null;
let commentsCollapsed = false;
let currentFileUrl = '';
let currentFileName = '';

// Dark Mode Toggle
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const isDarkMode = document.body.classList.contains('dark-mode');
    localStorage.setItem('darkMode', isDarkMode);
    
    document.querySelectorAll('.dark-mode-toggle').forEach(btn => {
        btn.innerHTML = isDarkMode ? '<i class="fas fa-sun"></i> Light Mode' : '<i class="fas fa-moon"></i> Dark Mode';
    });
}

// Load dark mode preference
if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
    document.querySelectorAll('.dark-mode-toggle').forEach(btn => {
        btn.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
    });
}

// Toggle password visibility
function togglePassword(id, icon) {
    const input = document.getElementById(id);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Form validations
function validateForm() {
    const pass = document.getElementById('regPass').value;
    const conf = document.getElementById('regConfirm').value;
    if (pass !== conf) {
        document.getElementById('matchError').classList.remove('hidden');
        return false;
    }
    document.getElementById('matchError').classList.add('hidden');
    return true;
}

function validateOfficeForm() {
    const pass = document.getElementById('officeRegPass').value;
    const conf = document.getElementById('officeRegConfirm').value;
    if (pass !== conf) {
        document.getElementById('officeMatchError').classList.remove('hidden');
        return false;
    }
    document.getElementById('officeMatchError').classList.add('hidden');
    return true;
}

function validateAdminForm() {
    const pass = document.getElementById('adminPass').value;
    const conf = document.getElementById('adminConfirm').value;
    if (pass !== conf) {
        document.getElementById('adminMatchError').classList.remove('hidden');
        return false;
    }
    document.getElementById('adminMatchError').classList.add('hidden');
    return true;
}

function validateSubmitForm() {
    const file = document.getElementById('file-input')?.files.length || 0;
    const link = document.getElementById('link-input')?.value.trim() || '';
    const text = document.getElementById('text-input')?.value.trim() || '';
    
    if (file === 0 && link === '' && text === '') {
        alert('Please provide a file, link, or text.');
        return false;
    }
    return confirm('Submit this requirement?');
}

// Toggle forms
function toggleForm(id) {
    const element = document.getElementById(id);
    if (element) {
        element.classList.toggle('hidden');
    }
}

// Submission tabs
function showSubmissionTab(tab) {
    const tabs = document.querySelectorAll('.submission-tab');
    const contents = document.querySelectorAll('.submission-tab-content');
    
    tabs.forEach(t => t.classList.remove('active'));
    contents.forEach(c => c.classList.add('hidden'));
    
    if (tab === 'file') {
        if (tabs[0]) tabs[0].classList.add('active');
        const fileTab = document.getElementById('file-tab');
        if (fileTab) fileTab.classList.remove('hidden');
    } else if (tab === 'link') {
        if (tabs[1]) tabs[1].classList.add('active');
        const linkTab = document.getElementById('link-tab');
        if (linkTab) linkTab.classList.remove('hidden');
    } else {
        if (tabs[2]) tabs[2].classList.add('active');
        const textTab = document.getElementById('text-tab');
        if (textTab) textTab.classList.remove('hidden');
    }
}

// File handling
function handleFileSelect(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const ext = file.name.split('.').pop().toLowerCase();
        const icons = {
            pdf: 'fa-file-pdf',
            doc: 'fa-file-word',
            docx: 'fa-file-word',
            xls: 'fa-file-excel',
            xlsx: 'fa-file-excel',
            ppt: 'fa-file-powerpoint',
            pptx: 'fa-file-powerpoint',
            jpg: 'fa-file-image',
            jpeg: 'fa-file-image',
            png: 'fa-file-image',
            gif: 'fa-file-image',
            txt: 'fa-file-alt'
        };
        const icon = icons[ext] || 'fa-file';
        const colors = {
            pdf: '#ea4335',
            doc: '#4285f4',
            docx: '#4285f4',
            xls: '#34a853',
            xlsx: '#34a853',
            ppt: '#fbbc04',
            pptx: '#fbbc04',
            jpg: '#4285f4',
            jpeg: '#4285f4',
            png: '#4285f4',
            gif: '#4285f4'
        };
        const color = colors[ext] || '#5f6368';
        
        const preview = document.getElementById('file-preview');
        if (preview) {
            preview.innerHTML = `
                <div style="display:flex; align-items:center; gap:6px; padding:6px; background:#f8f9fa; border-radius:4px; margin-top:6px;">
                    <i class="fas ${icon}" style="color:${color}; font-size:16px;"></i>
                    <span style="flex:1; font-size:10px;">${escapeHtml(file.name)}</span>
                    <span style="color:#666; font-size:9px;">${formatFileSize(file.size)}</span>
                    <i class="fas fa-times" style="color:#e74c3c; cursor:pointer;" onclick="removeFile()"></i>
                </div>
            `;
        }
    }
}

function removeFile() {
    const preview = document.getElementById('file-preview');
    const fileInput = document.getElementById('file-input');
    if (preview) preview.innerHTML = '';
    if (fileInput) fileInput.value = '';
}

function formatFileSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' bytes';
}

// Fullscreen File Viewer - COMPLETE FULL SCREEN VERSION
function getFullUrl(path) {
    if (path.startsWith('http')) return path;
    return window.location.origin + '/' + path;
}

function openFullscreenViewer(filePath, fileType, fileName) {
    currentFileUrl = filePath;
    currentFileName = fileName;
    
    const viewer = document.getElementById('fullscreenViewer');
    const content = document.getElementById('fullscreenContent');
    const title = document.getElementById('fullscreenTitle');
    const download = document.getElementById('fullscreenDownload');
    
    if (!viewer || !content) return;
    
    if (title) title.textContent = fileName;
    if (download) download.href = filePath;
    
    const type = fileType.toLowerCase();
    content.innerHTML = '';
    
    // Add loading indicator
    content.innerHTML = '<div style="text-align:center; padding:50px;"><i class="fas fa-spinner fa-spin" style="font-size:48px; color:#1b4d3e;"></i><p style="margin-top:10px;">Loading file...</p></div>';
    
    if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'].includes(type)) {
        // Image viewer - Full screen
        const img = new Image();
        img.onload = function() {
            content.innerHTML = `
                <div style="display:flex; align-items:center; justify-content:center; height:100%; width:100%; background:#0a0a0a;">
                    <img src="${filePath}" style="max-width:100%; max-height:calc(100vh - 120px); object-fit:contain; box-shadow:0 2px 10px rgba(0,0,0,0.3);">
                </div>
            `;
        };
        img.onerror = function() {
            content.innerHTML = `<div style="text-align:center; padding:50px;"><i class="fas fa-image" style="font-size:64px; color:#e74c3c;"></i><p style="margin-top:10px;">Could not load image</p><a href="${filePath}" download class="btn btn-primary">Download File</a></div>`;
        };
        img.src = filePath;
    } 
    else if (type === 'pdf') {
        // PDF viewer - Full screen
        content.innerHTML = `<iframe src="${filePath}#toolbar=1&navpanes=1&scrollbar=1&view=FitH" style="width:100%; height:calc(100vh - 120px); border:none; background:#fff;"></iframe>`;
    } 
    else if (type === 'txt') {
        // Text file viewer
        fetch(filePath)
            .then(response => response.text())
            .then(text => {
                content.innerHTML = `<pre style="background:#f5f5f5; padding:20px; border-radius:8px; overflow:auto; max-height:calc(100vh - 120px); text-align:left; font-family:'Courier New', monospace; font-size:13px; line-height:1.5; white-space:pre-wrap; word-wrap:break-word;">${escapeHtml(text)}</pre>`;
            })
            .catch(() => {
                content.innerHTML = `<div style="text-align:center; padding:50px;"><i class="fas fa-file-alt" style="font-size:64px; color:#e74c3c;"></i><p style="margin-top:10px;">Could not load text file</p><a href="${filePath}" download class="btn btn-primary">Download File</a></div>`;
            });
    } 
    else if (['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'].includes(type)) {
        // Office documents with viewer options
        const fullUrl = getFullUrl(filePath);
        content.innerHTML = `
            <div style="width:100%; height:calc(100vh - 120px); display:flex; flex-direction:column;">
                <div style="padding:12px; text-align:center; background:#f8f9fa; border-bottom:1px solid #ddd; display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
                    <button onclick="loadViewer('google', '${fullUrl}')" class="btn btn-primary btn-sm">
                        <i class="fab fa-google"></i> Google Viewer
                    </button>
                    <button onclick="loadViewer('microsoft', '${fullUrl}')" class="btn btn-primary btn-sm">
                        <i class="fab fa-microsoft"></i> Microsoft Viewer
                    </button>
                    <button onclick="window.open('${filePath}', '_blank')" class="btn btn-success btn-sm">
                        <i class="fas fa-download"></i> Download
                    </button>
                </div>
                <div id="viewer-container" style="flex:1; width:100%; min-height:500px; background:#fff;"></div>
            </div>
        `;
        setTimeout(() => loadViewer('google', fullUrl), 100);
    } 
    else if (type === 'link') {
        // External link
        content.innerHTML = `<div style="text-align:center; padding:50px;"><i class="fas fa-link" style="font-size:64px; color:#1b4d3e;"></i><p style="margin-top:10px;">External Link Submission</p><a href="${filePath}" target="_blank" class="btn btn-primary">Open Link</a></div>`;
    }
    else {
        // Other file types
        content.innerHTML = `<div style="text-align:center; padding:50px;"><i class="fas fa-file" style="font-size:64px; color:#1b4d3e;"></i><p style="margin-top:10px;">This file type cannot be previewed.</p><a href="${filePath}" download class="btn btn-primary">Download File</a></div>`;
    }
    
    // Show viewer full screen
    viewer.style.display = 'flex';
    viewer.style.position = 'fixed';
    viewer.style.top = '0';
    viewer.style.left = '0';
    viewer.style.width = '100%';
    viewer.style.height = '100%';
    viewer.style.zIndex = '9999';
    viewer.style.backgroundColor = '#fff';
}

function loadViewer(viewerType, fileUrl) {
    const container = document.getElementById('viewer-container');
    if (!container) return;
    
    if (viewerType === 'google') {
        container.innerHTML = `<iframe src="https://docs.google.com/gview?url=${encodeURIComponent(fileUrl)}&embedded=true" style="width:100%; height:100%; min-height:500px; border:none;"></iframe>`;
    } else if (viewerType === 'microsoft') {
        container.innerHTML = `<iframe src="https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(fileUrl)}" style="width:100%; height:100%; min-height:500px; border:none;"></iframe>`;
    }
}

function closeFullscreenViewer() {
    const viewer = document.getElementById('fullscreenViewer');
    if (viewer) {
        viewer.style.display = 'none';
    }
    const content = document.getElementById('fullscreenContent');
    if (content) {
        content.innerHTML = '';
    }
}

// Toggle fullscreen mode for the viewer
function toggleFullscreen() {
    const viewer = document.getElementById('fullscreenViewer');
    if (!viewer) return;
    
    if (screenfull.isEnabled) {
        screenfull.toggle(viewer);
    } else {
        // Fallback for browsers without screenfull support
        if (viewer.requestFullscreen) {
            viewer.requestFullscreen();
        } else if (viewer.webkitRequestFullscreen) {
            viewer.webkitRequestFullscreen();
        } else if (viewer.msRequestFullscreen) {
            viewer.msRequestFullscreen();
        }
    }
}

// Comments functions
function toggleComments() {
    const commentsSection = document.getElementById('commentsSection');
    if (commentsSection) {
        commentsCollapsed = !commentsCollapsed;
        if (commentsCollapsed) {
            commentsSection.classList.add('collapsed');
        } else {
            commentsSection.classList.remove('collapsed');
        }
    }
}

function showConversation(reqId, studentId, studentName) {
    currentReqId = reqId;
    currentStudentId = studentId;
    const title = document.getElementById('conversationTitle');
    if (title) title.textContent = `Conversation with ${studentName}`;
    
    fetch(`?get_comments=1&req_id=${reqId}&student_id=${studentId}`)
        .then(response => response.json())
        .then(comments => {
            const container = document.getElementById('conversationComments');
            if (!container) return;
            container.innerHTML = '';
            
            if (comments.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666; font-size: 11px;">No comments yet. Start the conversation!</p>';
            } else {
                comments.forEach(comment => {
                    const commentDiv = document.createElement('div');
                    commentDiv.className = 'comment-item' + (comment.is_read == 0 && comment.created_by == 'student' ? ' unread' : '');
                    commentDiv.innerHTML = `
                        <div class="comment-header">
                            <span class="comment-author">${escapeHtml(comment.created_by_display || (comment.created_by == 'office' ? 'Office' : 'Student'))}</span>
                            <span class="comment-date">${escapeHtml(comment.date)}</span>
                        </div>
                        <div class="comment-text">${escapeHtml(comment.comment)}</div>
                    `;
                    container.appendChild(commentDiv);
                });
            }
            
            const formData = new FormData();
            formData.append('action', 'mark_comments_read');
            formData.append('req_id', reqId);
            formData.append('student_id', studentId);
            
            fetch('', {
                method: 'POST',
                body: formData
            });
        });
    
    const modal = document.getElementById('conversationModal');
    if (modal) modal.style.display = 'flex';
}

function sendReply() {
    const commentInput = document.getElementById('conversationComment');
    const comment = commentInput?.value;
    if (!comment || !comment.trim()) return;
    
    const formData = new FormData();
    formData.append('action', 'add_office_private_comment');
    formData.append('req_id', currentReqId);
    formData.append('student_id', currentStudentId);
    formData.append('comment', comment);
    
    fetch('', {
        method: 'POST',
        body: formData
    }).then(() => {
        if (commentInput) commentInput.value = '';
        showConversation(currentReqId, currentStudentId, '');
    });
}

function closeConversationModal() {
    const modal = document.getElementById('conversationModal');
    if (modal) modal.style.display = 'none';
}

function showCommentModal(reqId, studentId) {
    const reqIdInput = document.getElementById('commentReqId');
    const studentIdInput = document.getElementById('commentStudentId');
    if (reqIdInput) reqIdInput.value = reqId;
    if (studentIdInput) studentIdInput.value = studentId;
    
    const modal = document.getElementById('commentModal');
    if (modal) modal.style.display = 'flex';
}

function closeCommentModal() {
    const modal = document.getElementById('commentModal');
    if (modal) modal.style.display = 'none';
}

// Confirmation Modal
function showConfirm(title, message, callback) {
    const titleEl = document.getElementById('confirmTitle');
    const messageEl = document.getElementById('confirmMessage');
    if (titleEl) titleEl.textContent = title;
    if (messageEl) messageEl.textContent = message;
    confirmCallback = callback;
    
    const modal = document.getElementById('confirmModal');
    if (modal) modal.style.display = 'flex';
}

function closeConfirmModal() {
    const modal = document.getElementById('confirmModal');
    if (modal) modal.style.display = 'none';
    confirmCallback = null;
}

// Setup confirm button
const confirmBtn = document.getElementById('confirmBtn');
if (confirmBtn) {
    confirmBtn.addEventListener('click', function() {
        if (confirmCallback) confirmCallback();
        closeConfirmModal();
    });
}

// Action functions
function confirmLogout() {
    showConfirm('Logout', 'Are you sure you want to logout?', () => window.location.href = '?logout=1');
}

function confirmUnsubmit(subId) {
    showConfirm('Unsubmit', 'Withdraw this submission?', () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="unsubmit"><input type="hidden" name="sub_id" value="${subId}">`;
        document.body.appendChild(form);
        form.submit();
    });
}

function confirmDeleteReq(reqId) {
    showConfirm('Delete', 'Delete this requirement?', () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="delete_requirement"><input type="hidden" name="req_id" value="${reqId}">`;
        document.body.appendChild(form);
        form.submit();
    });
}

function approveSubmission(subId) {
    showConfirm('Approve', 'Approve this submission?', () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="approve"><input type="hidden" name="sub_id" value="${subId}">`;
        document.body.appendChild(form);
        form.submit();
    });
}

function rejectSubmission(subId) {
    const reason = prompt('Reason for declination:');
    if (reason && reason.trim()) {
        showConfirm('Decline', 'Decline this submission?', () => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="reject"><input type="hidden" name="sub_id" value="${subId}"><input type="hidden" name="reason" value="${escapeHtml(reason)}">`;
            document.body.appendChild(form);
            form.submit();
        });
    }
}

function revertAction(subId) {
    showConfirm('Take Back', 'Revert this action? Submission will become pending.', () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="revert_action"><input type="hidden" name="sub_id" value="${subId}">`;
        document.body.appendChild(form);
        form.submit();
    });
}

function editDeadline(reqId, current) {
    let currentDate = '';
    if (current) {
        currentDate = current.replace('T', ' ').substring(0, 16);
    }
    const newDeadline = prompt('New deadline (YYYY-MM-DD HH:MM):', currentDate);
    if (newDeadline && newDeadline.trim()) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="set_deadline"><input type="hidden" name="req_id" value="${reqId}"><input type="hidden" name="deadline" value="${newDeadline.replace(' ', 'T')}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// Clearance status toggle
function showClearanceStatus(status) {
    const completedSection = document.getElementById('completed-section');
    const incompleteSection = document.getElementById('incomplete-section');
    
    if (status === 'completed') {
        if (completedSection) completedSection.classList.remove('hidden');
        if (incompleteSection) incompleteSection.classList.add('hidden');
    } else {
        if (completedSection) completedSection.classList.add('hidden');
        if (incompleteSection) incompleteSection.classList.remove('hidden');
    }
}

// Search and filter functions
function searchMasterList() {
    const search = document.getElementById('masterSearch')?.value.toLowerCase() || '';
    const rows = document.querySelectorAll('#masterTable tbody tr');
    rows.forEach(row => {
        const name = row.dataset.name;
        if (name && name.includes(search)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
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

function searchSubmissions() {
    const search = document.getElementById('subSearch')?.value.toLowerCase() || '';
    const course = document.getElementById('courseFilter')?.value || '';
    const year = document.getElementById('yearFilter')?.value || '';
    const section = document.getElementById('sectionFilter')?.value || '';
    
    const rows = document.querySelectorAll('#submissionsTable tbody tr');
    rows.forEach(row => {
        let show = true;
        if (search && row.dataset.name && !row.dataset.name.includes(search)) show = false;
        if (show && course && row.dataset.course !== course) show = false;
        if (show && year && row.dataset.year !== year) show = false;
        if (show && section && row.dataset.section !== section) show = false;
        row.style.display = show ? '' : 'none';
    });
}

function filterSubmissionsByCourse() { searchSubmissions(); }
function filterSubmissionsByYear() { searchSubmissions(); }
function filterSubmissionsBySection() { searchSubmissions(); }

function searchOfficeSubmissions() {
    const search = document.getElementById('subSearchOffice')?.value.toLowerCase() || '';
    const course = document.getElementById('courseFilterOffice')?.value || '';
    const year = document.getElementById('yearFilterOffice')?.value || '';
    const section = document.getElementById('sectionFilterOffice')?.value || '';
    
    const rows = document.querySelectorAll('#officeSubmissionsTable tbody tr');
    rows.forEach(row => {
        let show = true;
        if (search && row.dataset.name && !row.dataset.name.includes(search)) show = false;
        if (show && course && row.dataset.course !== course) show = false;
        if (show && year && row.dataset.year !== year) show = false;
        if (show && section && row.dataset.section !== section) show = false;
        row.style.display = show ? '' : 'none';
    });
}

function filterOfficeSubmissionsByCourse() { searchOfficeSubmissions(); }
function filterOfficeSubmissionsByYear() { searchOfficeSubmissions(); }
function filterOfficeSubmissionsBySection() { searchOfficeSubmissions(); }

function searchRequirements() {
    const search = document.getElementById('reqSearch')?.value.toLowerCase() || '';
    const rows = document.querySelectorAll('.plain-table tbody tr');
    rows.forEach(row => {
        const title = row.dataset.title;
        if (title && title.includes(search)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function searchClearanceList() {
    const search = document.getElementById('clearanceSearch')?.value.toLowerCase() || '';
    const rows = document.querySelectorAll('.course-group tbody tr');
    rows.forEach(row => {
        const name = row.cells[1]?.textContent.toLowerCase();
        if (name && name.includes(search)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Generate student ID
function updateGeneratedID() {
    const last = document.getElementById('lastName')?.value || '';
    const first = document.getElementById('firstName')?.value || '';
    const middle = document.getElementById('middleName')?.value || '';
    const birth = document.getElementById('birthdate')?.value || '';
    const generatedIdDiv = document.getElementById('generatedID');
    
    if (last && first && middle && birth && generatedIdDiv) {
        const li = last[0].toUpperCase();
        const fi = first[0].toUpperCase();
        const mi = middle[0].toUpperCase();
        const d = new Date(birth);
        const id = li + fi + mi +
            String(d.getMonth() + 1).padStart(2, '0') +
            String(d.getDate()).padStart(2, '0') +
            String(d.getFullYear()).slice(2) + '00';
        generatedIdDiv.textContent = id;
    } else if (generatedIdDiv) {
        generatedIdDiv.textContent = '---';
    }
}

// Export function
function exportActivity(type) {
    window.location.href = `?export_activity=${type}`;
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals when clicking outside
window.onclick = function(e) {
    if (e.target.classList && e.target.classList.contains('modal-overlay')) {
        e.target.style.display = 'none';
    }
    // Close fullscreen viewer when clicking outside content area
    if (e.target.classList && e.target.classList.contains('fullscreen-viewer')) {
        closeFullscreenViewer();
    }
};

// Close fullscreen viewer with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeFullscreenViewer();
        closeConversationModal();
        closeCommentModal();
        closeConfirmModal();
    }
});