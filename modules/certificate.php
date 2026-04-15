<!DOCTYPE html>
<html>
<head>
    <title>Certificate of Completion - CHMSU</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', serif;
            margin: 0;
            padding: 40px;
            background: #f0f0f0;
        }
        
        .certificate-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .certificate {
            background: white;
            border: 15px double #1b4d3e;
            padding: 50px;
            text-align: center;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .university-name {
            font-size: 28px;
            font-weight: bold;
            color: #1b4d3e;
            margin-bottom: 5px;
            letter-spacing: 2px;
        }
        
        .university-address {
            font-size: 14px;
            color: #555;
            margin-bottom: 10px;
        }
        
        hr {
            margin: 20px 0;
            border: 1px solid #1b4d3e;
        }
        
        .certificate-title {
            font-size: 42px;
            font-weight: bold;
            margin: 30px 0;
            letter-spacing: 8px;
            color: #1b4d3e;
            text-transform: uppercase;
        }
        
        .certificate-subtitle {
            font-size: 18px;
            margin-bottom: 30px;
            font-style: italic;
        }
        
        .certify-text {
            font-size: 16px;
            margin: 20px 0;
            line-height: 1.8;
        }
        
        .student-name {
            font-size: 32px;
            font-weight: bold;
            margin: 20px 0;
            text-decoration: underline;
            color: #1b4d3e;
            letter-spacing: 2px;
        }
        
        .student-details {
            font-size: 16px;
            margin: 15px 0;
            line-height: 1.8;
        }
        
        .student-details table {
            width: 80%;
            margin: 0 auto;
            border-collapse: collapse;
        }
        
        .student-details td {
            padding: 5px;
            text-align: left;
        }
        
        .student-details td.label {
            font-weight: bold;
            width: 40%;
        }
        
        .declaration {
            font-size: 16px;
            line-height: 1.8;
            margin: 30px 0;
        }
        
        .cleared-text {
            font-size: 24px;
            font-weight: bold;
            color: #27ae60;
            margin: 20px 0;
        }
        
        .signature-section {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
            gap: 40px;
        }
        
        .signature-box {
            flex: 1;
            text-align: center;
        }
        
        .signature-line {
            margin-top: 40px;
            border-top: 1px solid #333;
            width: 100%;
            padding-top: 5px;
        }
        
        .signature-name {
            font-weight: bold;
            margin-top: 5px;
        }
        
        .signature-title {
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }
        
        .date-issued {
            margin-top: 40px;
            text-align: center;
            font-size: 14px;
        }
        
        .btn-print, .btn-back {
            background: #1b4d3e;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .btn-print:hover, .btn-back:hover {
            background: #2d6a4f;
            transform: scale(1.02);
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .no-print {
                display: none !important;
            }
            .certificate {
                box-shadow: none;
                margin: 0;
                padding: 30px;
            }
        }
        
        @media (max-width: 768px) {
            .certificate {
                padding: 20px;
            }
            .university-name {
                font-size: 20px;
            }
            .certificate-title {
                font-size: 24px;
                letter-spacing: 4px;
            }
            .student-name {
                font-size: 22px;
            }
            .signature-section {
                flex-direction: column;
                gap: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="certificate-container">
        <div style="text-align: center;" class="no-print">
            <button class="btn-print" onclick="window.print()">
                🖨️ Print Certificate
            </button>
            <button class="btn-back" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>?view=home'">
                ← Back to Dashboard
            </button>
        </div>
        
        <div class="certificate">
            <div class="university-name">CARLOS HILADO MEMORIAL STATE UNIVERSITY</div>
            <div class="university-address">Talisay City, Negros Occidental, Philippines</div>
            <hr>
            
            <div class="certificate-title">CERTIFICATE OF COMPLETION</div>
            <div class="certificate-subtitle">Clearance Requirements</div>
            
            <div class="certify-text">
                THIS IS TO CERTIFY THAT
            </div>
            
            <div class="student-name"><?php echo strtoupper($student['chmsu_full_name']); ?></div>
            
            <div class="student-details">
                <table>
                    <tr>
                        <td class="label">Student ID Number:</td>
                        <td><?php echo $student['chmsu_student_id']; ?></td>
                    </tr>
                    <tr>
                        <td class="label">Course:</td>
                        <td><?php echo $student['chmsu_course']; ?></td>
                    </tr>
                    <tr>
                        <td class="label">Year Level:</td>
                        <td><?php echo $student['chmsu_year']; ?> Year</td>
                    </tr>
                    <tr>
                        <td class="label">Section:</td>
                        <td><?php echo $student['chmsu_section']; ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="declaration">
                has successfully completed all the clearance requirements<br>
                from the different offices of the university and is hereby declared
            </div>
            
            <div class="cleared-text">
                ✦ FULLY CLEARED ✦
            </div>
            
            <div class="declaration">
                for the Academic Year <?php echo date('Y'); ?> - <?php echo date('Y') + 1; ?>.
            </div>
            
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-name">DR. MA. TERESA L. MANALO</div>
                    <div class="signature-title">Registrar</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-name">DR. JULIUS A. SORIANO</div>
                    <div class="signature-title">Dean of Students</div>
                </div>
            </div>
            
            <div class="date-issued">
                Issued on this <?php echo date('jS'); ?> day of <?php echo date('F, Y'); ?>
            </div>
        </div>
    </div>
</body>
</html>