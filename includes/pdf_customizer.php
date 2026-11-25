<?php
/**
 * Reusable PDF Report Settings Customizer (Landscape + Sizes + Colors + Live Preview)
 * Included in Registrar Reports and Office Reports.
 */
$pdfPrepBy = isset($_SESSION['office']) ? $_SESSION['office'] . ' Office' : (isset($_SESSION['admin']) ? 'System Administrator' : 'CHMSU Office');
?>
<div id="pdfCustomizerCard" style="background:#fff; border:1px solid #d1d5db; border-radius:8px; margin-bottom:16px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); font-family: Arial, sans-serif;">
    <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:10px 16px; display:flex; align-items:center; justify-content:space-between; cursor:pointer;" onclick="togglePdfCustomizer()">
        <div style="display:flex; align-items:center; gap:8px; font-weight:bold; font-size:13px; color:#1f2937;">
            <i class="fas fa-sliders-h" style="color:#15803d;"></i>
            <span>PDF Report Settings (Size, Color &amp; Style — Landscape)</span>
        </div>
        <span id="pdfCustToggleIcon" style="font-size:12px; color:#6b7280;">
            <i class="fas fa-chevron-up"></i> Hide
        </span>
    </div>
    <div id="pdfCustomizerBody" style="padding:16px; display:block;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:16px; align-items:start;">
            <!-- LEFT: CONTROLS -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Body Font Size</label>
                    <select id="pdfBodySize" onchange="updatePdfPreview()" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:12px;">
                        <option value="8">8 pt</option>
                        <option value="9" selected>9 pt</option>
                        <option value="10">10 pt</option>
                        <option value="11">11 pt</option>
                        <option value="12">12 pt</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Font Color</label>
                    <input type="color" id="pdfFontColor" value="#000000" oninput="updatePdfPreview()" style="width:100%; height:32px; border:1px solid #d1d5db; border-radius:5px; padding:2px; cursor:pointer;">
                </div>

                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Table Data Size</label>
                    <select id="pdfTableSize" onchange="updatePdfPreview()" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:12px;">
                        <option value="7">7 pt</option>
                        <option value="8">8 pt</option>
                        <option value="9" selected>9 pt</option>
                        <option value="10">10 pt</option>
                        <option value="11">11 pt</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">School Name Size</label>
                    <select id="pdfSchoolSize" onchange="updatePdfPreview()" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:12px;">
                        <option value="9">9 pt</option>
                        <option value="10">10 pt</option>
                        <option value="11" selected>11 pt</option>
                        <option value="12">12 pt</option>
                        <option value="13">13 pt</option>
                        <option value="14">14 pt</option>
                    </select>
                </div>

                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Report Title Size</label>
                    <select id="pdfTitleSize" onchange="updatePdfPreview()" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:12px;">
                        <option value="11">11 pt</option>
                        <option value="12">12 pt</option>
                        <option value="13">13 pt</option>
                        <option value="14" selected>14 pt</option>
                        <option value="16">16 pt</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Footer Font Size</label>
                    <select id="pdfFooterSize" onchange="updatePdfPreview()" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:12px;">
                        <option value="7">7 pt</option>
                        <option value="8" selected>8 pt</option>
                        <option value="9">9 pt</option>
                        <option value="10">10 pt</option>
                    </select>
                </div>

                <div style="grid-column: span 2;">
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Font Style</label>
                    <div style="display:flex; gap:14px; padding:6px 10px; border:1px solid #e5e7eb; border-radius:5px; background:#f9fafb; font-size:12px;">
                        <label style="display:inline-flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" id="pdfFontBold" onchange="updatePdfPreview()"> <strong>Bold</strong></label>
                        <label style="display:inline-flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" id="pdfFontItalic" onchange="updatePdfPreview()"> <em>Italic</em></label>
                        <label style="display:inline-flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" id="pdfFontUnderline" onchange="updatePdfPreview()"> <u>Underline</u></label>
                    </div>
                </div>

                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Primary Color</label>
                    <input type="color" id="pdfPrimaryColor" value="#15803d" oninput="updatePdfPreview()" style="width:100%; height:32px; border:1px solid #d1d5db; border-radius:5px; padding:2px; cursor:pointer;">
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Accent Color</label>
                    <input type="color" id="pdfAccentColor" value="#15803d" oninput="updatePdfPreview()" style="width:100%; height:32px; border:1px solid #d1d5db; border-radius:5px; padding:2px; cursor:pointer;">
                </div>

                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Orientation</label>
                    <select id="pdfOrientation" onchange="updatePdfPreview()" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:12px;">
                        <option value="L" selected>Landscape</option>
                        <option value="P">Portrait</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:bold; color:#374151; margin-bottom:4px;">Paper Size</label>
                    <select id="pdfPaperSize" onchange="updatePdfPreview()" style="width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:12px;">
                        <option value="A4" selected>A4 (210 x 297 mm)</option>
                        <option value="LONG">Long (8.5 x 13 in)</option>
                        <option value="LETTER">Letter (8.5 x 11 in)</option>
                    </select>
                </div>

                <div style="grid-column: span 2; display:flex; align-items:center; justify-content:space-between; padding-top:4px;">
                    <span id="pdfOrientSummary" style="font-size:11px; color:#4b5563;"><i class="fas fa-file-pdf"></i> Layout: <strong>Landscape &bull; A4</strong></span>
                    <button type="button" onclick="resetPdfSettings()" style="background:#f3f4f6; border:1px solid #d1d5db; color:#374151; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer;">Reset Defaults</button>
                </div>
            </div>

            <!-- RIGHT: LIVE PREVIEW -->
            <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:12px;">
                <div style="font-size:10px; font-weight:bold; text-transform:uppercase; color:#6b7280; margin-bottom:6px; letter-spacing:0.5px;">Live Report Preview</div>
                <div id="pdfLivePreview" style="background:#fff; border:1px solid #d1d5db; padding:12px; border-radius:4px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                    <div id="prevSchool" style="text-align:center; font-weight:bold; font-size:11pt; color:#000;">Carlos Hilado Memorial State University | CHMSU E-Clearance</div>
                    <div id="prevTitle" style="text-align:center; font-weight:bold; font-size:14pt; color:#000; margin-top:2px;">CLEARANCE REPORT</div>
                    <div id="prevLine" style="height:2px; background:#15803d; margin:6px 0 8px 0;"></div>
                    <div id="prevBanner" style="background:#15803d; color:#fff; font-weight:bold; font-size:9.5pt; padding:4px 8px; margin-bottom:6px;">Office / Section Summary</div>
                    <table style="width:100%; border-collapse:collapse; font-size:9pt;" id="prevTable">
                        <thead>
                            <tr id="prevTableHead" style="background:#dcfce7; color:#000; font-weight:bold;">
                                <th style="border:1px solid #9ca3af; padding:3px 6px; text-align:center;">#</th>
                                <th style="border:1px solid #9ca3af; padding:3px 6px; text-align:left;">Student Name</th>
                                <th style="border:1px solid #9ca3af; padding:3px 6px; text-align:left;">Course / Year &amp; Sec</th>
                                <th style="border:1px solid #9ca3af; padding:3px 6px; text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="prevTableBody">
                            <tr>
                                <td style="border:1px solid #9ca3af; padding:3px 6px; text-align:center;">1</td>
                                <td style="border:1px solid #9ca3af; padding:3px 6px;">Dela Cruz, Juan</td>
                                <td style="border:1px solid #9ca3af; padding:3px 6px;">BSIS 4th Year-A</td>
                                <td style="border:1px solid #9ca3af; padding:3px 6px; text-align:center;">Approved</td>
                            </tr>
                        </tbody>
                    </table>
                    <div id="prevFooter" style="text-align:center; font-style:italic; font-size:8pt; color:#4b5563; margin-top:8px;">
                        Generated: <?php echo date('F d, Y h:i A'); ?> — Page 1/1 — Prepared by: <?php echo htmlspecialchars($pdfPrepBy); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePdfCustomizer() {
    var body = document.getElementById('pdfCustomizerBody');
    var icon = document.getElementById('pdfCustToggleIcon');
    if (body.style.display === 'none') {
        body.style.display = 'block';
        icon.innerHTML = '<i class="fas fa-chevron-up"></i> Hide';
    } else {
        body.style.display = 'none';
        icon.innerHTML = '<i class="fas fa-chevron-down"></i> Customize';
    }
}

function hexToTint(hex, alpha) {
    hex = hex.replace('#', '');
    if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
    var r = parseInt(hex.substring(0,2), 16) || 21;
    var g = parseInt(hex.substring(2,4), 16) || 128;
    var b = parseInt(hex.substring(4,6), 16) || 61;
    var tr = Math.round(r * alpha + 255 * (1 - alpha));
    var tg = Math.round(g * alpha + 255 * (1 - alpha));
    var tb = Math.round(b * alpha + 255 * (1 - alpha));
    return 'rgb(' + tr + ',' + tg + ',' + tb + ')';
}

function getPdfSettings() {
    return {
        body_size: document.getElementById('pdfBodySize').value,
        font_color: document.getElementById('pdfFontColor').value,
        table_size: document.getElementById('pdfTableSize').value,
        school_size: document.getElementById('pdfSchoolSize').value,
        title_size: document.getElementById('pdfTitleSize').value,
        footer_size: document.getElementById('pdfFooterSize').value,
        font_bold: document.getElementById('pdfFontBold').checked ? '1' : '0',
        font_italic: document.getElementById('pdfFontItalic').checked ? '1' : '0',
        font_underline: document.getElementById('pdfFontUnderline').checked ? '1' : '0',
        primary_color: document.getElementById('pdfPrimaryColor').value,
        accent_color: document.getElementById('pdfAccentColor').value,
        orientation: document.getElementById('pdfOrientation').value,
        paper_size: document.getElementById('pdfPaperSize').value
    };
}

function updatePdfPreview() {
    var s = getPdfSettings();
    try { localStorage.setItem('chmsu_pdf_settings', JSON.stringify(s)); } catch(e) {}

    var prevSchool = document.getElementById('prevSchool');
    var prevTitle = document.getElementById('prevTitle');
    var prevLine = document.getElementById('prevLine');
    var prevBanner = document.getElementById('prevBanner');
    var prevTable = document.getElementById('prevTable');
    var prevTableHead = document.getElementById('prevTableHead');
    var prevTableBody = document.getElementById('prevTableBody');
    var prevFooter = document.getElementById('prevFooter');

    prevSchool.style.fontSize = s.school_size + 'pt';
    prevTitle.style.fontSize = s.title_size + 'pt';
    prevLine.style.background = s.primary_color;
    prevBanner.style.background = s.primary_color;
    prevTableHead.style.background = hexToTint(s.accent_color, 0.18);
    prevTable.style.fontSize = s.table_size + 'pt';
    prevTableBody.style.color = s.font_color;
    prevTableBody.style.fontWeight = (s.font_bold === '1') ? 'bold' : 'normal';
    prevTableBody.style.fontStyle = (s.font_italic === '1') ? 'italic' : 'normal';
    prevTableBody.style.textDecoration = (s.font_underline === '1') ? 'underline' : 'none';
    prevFooter.style.fontSize = s.footer_size + 'pt';

    var orientLbl = (s.orientation === 'P') ? 'Portrait' : 'Landscape';
    var paperLbl = (s.paper_size === 'LONG') ? 'Long (8.5x13)' : (s.paper_size === 'LETTER' ? 'Letter' : 'A4');
    var sumEl = document.getElementById('pdfOrientSummary');
    if (sumEl) sumEl.innerHTML = '<i class="fas fa-file-pdf"></i> Layout: <strong>' + orientLbl + ' &bull; ' + paperLbl + '</strong>';
}

function resetPdfSettings() {
    document.getElementById('pdfBodySize').value = '9';
    document.getElementById('pdfFontColor').value = '#000000';
    document.getElementById('pdfTableSize').value = '9';
    document.getElementById('pdfSchoolSize').value = '11';
    document.getElementById('pdfTitleSize').value = '14';
    document.getElementById('pdfFooterSize').value = '8';
    document.getElementById('pdfFontBold').checked = false;
    document.getElementById('pdfFontItalic').checked = false;
    document.getElementById('pdfFontUnderline').checked = false;
    document.getElementById('pdfPrimaryColor').value = '#15803d';
    document.getElementById('pdfAccentColor').value = '#15803d';
    document.getElementById('pdfOrientation').value = 'L';
    document.getElementById('pdfPaperSize').value = 'A4';
    updatePdfPreview();
}

document.addEventListener('DOMContentLoaded', function() {
    try {
        var saved = JSON.parse(localStorage.getItem('chmsu_pdf_settings') || 'null');
        if (saved) {
            if (saved.body_size) document.getElementById('pdfBodySize').value = saved.body_size;
            if (saved.font_color) document.getElementById('pdfFontColor').value = saved.font_color;
            if (saved.table_size) document.getElementById('pdfTableSize').value = saved.table_size;
            if (saved.school_size) document.getElementById('pdfSchoolSize').value = saved.school_size;
            if (saved.title_size) document.getElementById('pdfTitleSize').value = saved.title_size;
            if (saved.footer_size) document.getElementById('pdfFooterSize').value = saved.footer_size;
            document.getElementById('pdfFontBold').checked = (saved.font_bold === '1');
            document.getElementById('pdfFontItalic').checked = (saved.font_italic === '1');
            document.getElementById('pdfFontUnderline').checked = (saved.font_underline === '1');
            if (saved.primary_color) document.getElementById('pdfPrimaryColor').value = saved.primary_color;
            if (saved.accent_color) document.getElementById('pdfAccentColor').value = saved.accent_color;
            if (saved.orientation) document.getElementById('pdfOrientation').value = saved.orientation;
            if (saved.paper_size) document.getElementById('pdfPaperSize').value = saved.paper_size;
        }
    } catch(e) {}
    updatePdfPreview();

    // Automatically attach current PDF settings to all PDF export links (Registrar, Office, Admin)
    document.querySelectorAll('a[href*="export_report=pdf"], a[href*="export_type=pdf"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            var s = getPdfSettings();
            var url = new URL(this.href, window.location.href);
            Object.keys(s).forEach(function(k) {
                url.searchParams.set(k, s[k]);
            });
            this.href = url.toString();
        });
    });
});
</script>
