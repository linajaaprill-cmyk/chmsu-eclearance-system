<?php
/**
 * Shared Landscape TCPDF Renderer matching CHMSU PDF 3 Layout & Customizer Settings
 */

function chmsu_pdf_sanitize_hex($val, $default) {
    $val = trim((string)$val);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
        return strtolower($val);
    }
    return $default;
}

function chmsu_pdf_hex_to_rgb($hex) {
    $hex = ltrim($hex, '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    ];
}

function chmsu_pdf_hex_tint($hex, $alpha = 0.16) {
    list($r, $g, $b) = chmsu_pdf_hex_to_rgb($hex);
    $tr = (int)round($r * $alpha + 255 * (1 - $alpha));
    $tg = (int)round($g * $alpha + 255 * (1 - $alpha));
    $tb = (int)round($b * $alpha + 255 * (1 - $alpha));
    return sprintf('#%02x%02x%02x', $tr, $tg, $tb);
}

function chmsu_pdf_get_settings() {
    $clamp = function($val, $min, $max, $def) {
        $v = is_numeric($val) ? (float)$val : $def;
        return max($min, min($max, $v));
    };
    return [
        'body_size'      => $clamp($_GET['body_size']   ?? 9,  7, 16, 9),
        'table_size'     => $clamp($_GET['table_size']  ?? 9,  6, 14, 9),
        'school_size'    => $clamp($_GET['school_size'] ?? 11, 8, 18, 11),
        'title_size'     => $clamp($_GET['title_size']  ?? 14, 10, 22, 14),
        'footer_size'    => $clamp($_GET['footer_size'] ?? 8,  6, 12, 8),
        'font_color'     => chmsu_pdf_sanitize_hex($_GET['font_color'] ?? '#000000', '#000000'),
        'primary_color'  => chmsu_pdf_sanitize_hex($_GET['primary_color'] ?? '#15803d', '#15803d'),
        'accent_color'   => chmsu_pdf_sanitize_hex($_GET['accent_color'] ?? '#15803d', '#15803d'),
        'font_bold'      => !empty($_GET['font_bold']) && $_GET['font_bold'] === '1',
        'font_italic'    => !empty($_GET['font_italic']) && $_GET['font_italic'] === '1',
        'font_underline' => !empty($_GET['font_underline']) && $_GET['font_underline'] === '1',
        'orientation'    => (isset($_GET['orientation']) && strtoupper($_GET['orientation']) === 'P') ? 'P' : 'L',
        'paper_size'     => (isset($_GET['paper_size']) && in_array(strtoupper($_GET['paper_size']), ['A4', 'LONG', 'LETTER'], true)) ? strtoupper($_GET['paper_size']) : 'A4',
    ];
}

function chmsu_pdf_build_css($cfg) {
    $primary = $cfg['primary_color'];
    $tint    = chmsu_pdf_hex_tint($cfg['accent_color'], 0.16);
    $fg      = $cfg['font_color'];
    $bSize   = $cfg['body_size'] . 'pt';
    $tSize   = $cfg['table_size'] . 'pt';

    $tdStyle = "color:{$fg};font-size:{$tSize};";
    if ($cfg['font_bold'])      $tdStyle .= "font-weight:bold;";
    if ($cfg['font_italic'])    $tdStyle .= "font-style:italic;";
    if ($cfg['font_underline']) $tdStyle .= "text-decoration:underline;";

    return "
        body { font-family: helvetica, sans-serif; font-size: {$bSize}; color: {$fg}; line-height: 1.3; }
        .section-banner { background-color: {$primary}; color: #ffffff; font-weight: bold; font-size: 10.5pt; padding: 6px 10px; margin: 10px 0 6px 0; }
        .group-label { color: {$primary}; font-weight: bold; font-size: {$bSize}; margin: 8px 0 3px 0; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.data th { background-color: {$tint}; color: #111111; font-weight: bold; border: 1px solid #4b5563; padding: 4px 6px; font-size: {$tSize}; }
        table.data td { border: 1px solid #6b7280; padding: 4px 6px; {$tdStyle} }
        table.summary { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.summary th { background-color: {$tint}; color: #111111; font-weight: bold; border: 1px solid #4b5563; padding: 4px 8px; text-align: left; font-size: {$tSize}; }
        table.summary td { border: 1px solid #6b7280; padding: 4px 8px; {$tdStyle} }
        .spacer { height: 8px; }
    ";
}

function chmsu_pdf_render($htmlBody, $reportTitle, $subtitle, $preparedBy, $filename, $cfg) {
    if (file_exists(__DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php')) {
        require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
    } elseif (file_exists('C:/xampp/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php')) {
        require_once 'C:/xampp/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php';
    } else {
        exit('TCPDF library not found.');
    }

    if (!class_exists('CHMSUReportPDF')) {
        class CHMSUReportPDF extends TCPDF {
            public $cfg = [];
            public $repTitle = '';
            public $repSubtitle = '';
            public $repPreparedBy = '';
            public $repLogoUrl = '';

            public function Header() {
                $primaryRgb = chmsu_pdf_hex_to_rgb($this->cfg['primary_color'] ?? '#15803d');
                $pageW = $this->getPageWidth();
                $margin = 12;

                // Centered logo if reachable (non-blocking short check)
                $y = 7;
                if (!empty($this->repLogoUrl)) {
                    $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
                    $imgData = @file_get_contents($this->repLogoUrl, false, $ctx);
                    if ($imgData !== false) {
                        $logoSize = 14;
                        $this->Image('@' . $imgData, ($pageW - $logoSize) / 2, $y, $logoSize, $logoSize);
                        $y += $logoSize + 1.5;
                    }
                }

                // Page Number in Header (top-right on every page)
                $this->SetY(7);
                $this->SetFont('helvetica', 'I', $this->cfg['footer_size'] ?? 8);
                $this->SetTextColor(75, 85, 99);
                $this->Cell(0, 5, 'Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');

                // School Name
                $this->SetY($y);
                $this->SetFont('helvetica', 'B', $this->cfg['school_size'] ?? 11);
                $this->SetTextColor(17, 17, 17);
                $this->Cell(0, 5, 'Carlos Hilado Memorial State University | CHMSU E-Clearance System', 0, 1, 'C');

                // Report Title
                $this->SetFont('helvetica', 'B', $this->cfg['title_size'] ?? 14);
                $this->SetTextColor(0, 0, 0);
                $this->Cell(0, 6.5, strtoupper($this->repTitle), 0, 1, 'C');

                // Subtitle
                if (!empty($this->repSubtitle)) {
                    $this->SetFont('helvetica', '', 8.5);
                    $this->SetTextColor(75, 85, 99);
                    $this->Cell(0, 4.5, $this->repSubtitle, 0, 1, 'C');
                }

                // Primary Color Divider Line
                $lineY = $this->GetY() + 2;
                $this->SetDrawColor($primaryRgb[0], $primaryRgb[1], $primaryRgb[2]);
                $this->SetLineWidth(0.7);
                $this->Line($margin, $lineY, $pageW - $margin, $lineY);
            }

            public function Footer() {
                $this->SetY(-11);
                $this->SetFont('helvetica', 'I', $this->cfg['footer_size'] ?? 8);
                $this->SetTextColor(60, 60, 60);
                $text = 'Generated: ' . date('F d, Y h:i A')
                      . ' — Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages()
                      . ' — Prepared by: ' . $this->repPreparedBy;
                $this->Cell(0, 6, $text, 0, 0, 'C');
            }
        }
    }

    while (ob_get_level()) { ob_end_clean(); }

    $orient = $cfg['orientation'] ?? 'L';
    $pSize  = $cfg['paper_size'] ?? 'A4';
    $tcpdfFormat = 'A4';
    if ($pSize === 'LONG') {
        $tcpdfFormat = [215.9, 330.2]; // 8.5 x 13 in
    } elseif ($pSize === 'LETTER') {
        $tcpdfFormat = 'LETTER';
    }

    $pdf = new CHMSUReportPDF($orient, 'mm', $tcpdfFormat, true, 'UTF-8', false);
    $pdf->cfg = $cfg;
    $pdf->repTitle = $reportTitle;
    $pdf->repSubtitle = $subtitle;
    $pdf->repPreparedBy = $preparedBy;
    $pdf->repLogoUrl = 'https://tse4.mm.bing.net/th/id/OIP._MzqqLuZngO_bDcppKr1wAHaHa?pid=Api&h=220&P=0';

    $pdf->SetCreator('CHMSU E-Clearance System');
    $pdf->SetAuthor($preparedBy);
    $pdf->SetTitle($reportTitle);
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(12, 42, 12);
    $pdf->SetHeaderMargin(6);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->SetFont('helvetica', '', $cfg['body_size']);
    $pdf->AddPage();

    $css = chmsu_pdf_build_css($cfg);
    $fullHtml = '<style>' . $css . '</style>' . $htmlBody;
    $pdf->writeHTML($fullHtml, true, false, true, false, '');
    $pdf->Output($filename, 'D');
    exit;
}
