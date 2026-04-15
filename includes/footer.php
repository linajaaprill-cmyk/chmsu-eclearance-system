<!-- FULLSCREEN FILE VIEWER -->
<div class="fullscreen-viewer" id="fullscreenViewer">
    <div class="fullscreen-viewer-header">
        <h3 id="fullscreenTitle">File Preview</h3>
        <div>
            <button onclick="closeFullscreenViewer()" class="btn btn-back btn-sm">Close</button>
            <a id="fullscreenDownload" href="#" download class="btn btn-primary btn-sm">Download</a>
        </div>
    </div>
    <div class="fullscreen-viewer-content" id="fullscreenContent"></div>
</div>

<!-- CONFIRMATION MODAL -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <h4 id="confirmTitle">Confirm</h4>
        <p id="confirmMessage">Are you sure?</p>
        <div class="modal-buttons">
            <button class="btn btn-back btn-sm" onclick="closeConfirmModal()">Cancel</button>
            <button class="btn btn-danger btn-sm" id="confirmBtn">Confirm</button>
        </div>
    </div>
</div>

<script src="assets/js/script.js"></script>
</body>
</html>