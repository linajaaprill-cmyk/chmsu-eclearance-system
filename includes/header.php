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
        
        /* Remove any extra text */
        body::before,
        body::after {
            display: none !important;
            content: none !important;
        }
        
        /* Ensure table cells display correctly */
        .plain-table td,
        .plain-table th {
            padding: 8px 10px;
            vertical-align: middle;
        }
        
        /* Hide logo images */
        .header-logo {
            display: none !important;
        }
        
        /* Adjust header without logo */
        .header {
            padding: 12px 20px;
        }
        
        .header-title {
            margin-left: 0;
        }
    </style>
</head>
<body>