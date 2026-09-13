<!-- ============================================ -->
<!-- SIMPLE DELETE CONFIRMATION MODAL - FIXED     -->
<!-- ============================================ -->

<style>
#deleteModalOverlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 999999;
    justify-content: center;
    align-items: center;
}

#deleteModalBox {
    background: #ffffff;
    max-width: 380px;
    width: 90%;
    padding: 25px 20px 20px 20px;
    border-radius: 8px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    text-align: center;
    font-family: 'Times New Roman', Times, serif;
}

#deleteModalBox h3 {
    font-size: 17px;
    color: #c0392b;
    margin-bottom: 8px;
    font-family: 'Times New Roman', Times, serif;
    font-weight: normal;
}

#deleteModalBox .step-msg {
    font-size: 15px;
    color: #333;
    padding: 12px 10px;
    margin-bottom: 18px;
    font-family: 'Times New Roman', Times, serif;
    min-height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fafafa;
    border-radius: 4px;
}

#deleteModalBox .btn-group {
    display: flex;
    gap: 10px;
    justify-content: center;
}

#deleteModalBox .btn-confirm {
    background: #e74c3c;
    color: #fff;
    padding: 8px 22px;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    font-family: 'Times New Roman', Times, serif;
}

#deleteModalBox .btn-confirm:hover {
    background: #c0392b;
}

#deleteModalBox .btn-cancel {
    background: #95a5a6;
    color: #fff;
    padding: 8px 18px;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    font-family: 'Times New Roman', Times, serif;
}

#deleteModalBox .btn-cancel:hover {
    background: #7f8c8d;
}

body.dark-mode #deleteModalBox {
    background: #1a1a1a;
}

body.dark-mode #deleteModalBox .step-msg {
    background: #2a2a2a;
    color: #fff;
}

body.dark-mode #deleteModalBox h3 {
    color: #e74c3c;
}

@media (max-width: 480px) {
    #deleteModalBox {
        max-width: 320px;
        padding: 20px 15px;
    }
    #deleteModalBox h3 {
        font-size: 15px;
    }
    #deleteModalBox .step-msg {
        font-size: 13px;
        min-height: 40px;
    }
}
</style>

<div id="deleteModalOverlay">
    <div id="deleteModalBox">
        <h3>⚠️ DELETE CONFIRMATION</h3>
        <div class="step-msg" id="stepMsg">
            Are you sure you want to delete this? ⚠️
        </div>
        <div class="btn-group">
            <button class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn-confirm" id="confirmBtn" onclick="nextDeleteStep()">Delete</button>
        </div>
    </div>
</div>

<script>
let deleteStep = 0;
let deleteFormId = null;

var deleteMessages = [
    'Are you sure you want to delete this? ⚠️',
    'Are you REALLY sure? This action CANNOT be undone! ⚠️',
    '⚠️ FINAL WARNING: This will delete ALL records!'
];

function confirmDelete(formId) {
    deleteFormId = formId;
    deleteStep = 0;
    document.getElementById('stepMsg').textContent = deleteMessages[0];
    document.getElementById('confirmBtn').textContent = 'Delete';
    document.getElementById('deleteModalOverlay').style.display = 'flex';
}

function nextDeleteStep() {
    deleteStep++;
    
    if (deleteStep >= 3) {
        closeDeleteModal();
        // SUBMIT THE FORM
        if (deleteFormId) {
            document.getElementById(deleteFormId).submit();
        }
        return;
    }
    
    document.getElementById('stepMsg').textContent = deleteMessages[deleteStep];
    document.getElementById('confirmBtn').textContent = deleteStep === 2 ? '⚠️ FINAL DELETE' : 'Yes, I\'m Sure';
}

function closeDeleteModal() {
    document.getElementById('deleteModalOverlay').style.display = 'none';
    deleteStep = 0;
    // DO NOT reset deleteFormId here - we need it for the submit
}

document.addEventListener('click', function(e) {
    if (e.target === document.getElementById('deleteModalOverlay')) {
        closeDeleteModal();
        deleteFormId = null;
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteModal();
        deleteFormId = null;
    }
});
</script>