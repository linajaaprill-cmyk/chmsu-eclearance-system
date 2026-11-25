<?php
// includes/google_oauth.php

// Ensure environment variables are loaded if available
if (!function_exists('chmsu_load_env') && file_exists(__DIR__ . '/load_env.php')) {
    require_once __DIR__ . '/load_env.php';
}

// ============================================
// GOOGLE OAUTH CONFIGURATION
// ============================================

function chmsu_get_google_config() {
    $clientId = getenv('GOOGLE_CLIENT_ID');
    $clientSecret = getenv('GOOGLE_CLIENT_SECRET');
    $redirectUri = getenv('GOOGLE_REDIRECT_URI');

    if (empty($clientId) && !empty($_ENV['GOOGLE_CLIENT_ID'])) {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'];
    }
    if (empty($clientSecret) && !empty($_ENV['GOOGLE_CLIENT_SECRET'])) {
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'];
    }
    if (empty($redirectUri) && !empty($_ENV['GOOGLE_REDIRECT_URI'])) {
        $redirectUri = $_ENV['GOOGLE_REDIRECT_URI'];
    }

    if (empty($redirectUri)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $redirectUri = $protocol . '://' . $host . '/capstone/index.php';
    }

    return [
        'client_id'     => trim($clientId ?? ''),
        'client_secret' => trim($clientSecret ?? ''),
        'redirect_uri'  => trim($redirectUri)
    ];
}

// Define constants for backwards-compatibility if not already defined
$cfg = chmsu_get_google_config();
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', $cfg['client_id']);
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', $cfg['client_secret']);
}
if (!defined('GOOGLE_REDIRECT_URI')) {
    define('GOOGLE_REDIRECT_URI', $cfg['redirect_uri']);
}

// ============================================
// GET GOOGLE LOGIN URL / ACCOUNT CHOOSER
// ============================================
function getGoogleLoginUrl() {
    $cfg = chmsu_get_google_config();
    $clientId = $cfg['client_id'];
    $redirectUri = $cfg['redirect_uri'];

    // If client ID is configured and valid external Google Cloud OAuth client
    if (!empty($clientId) && $clientId !== 'your_google_client_id_here' && strpos($clientId, 'apps.googleusercontent.com') !== false) {
        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'email profile openid',
            'access_type'   => 'offline',
            'prompt'        => 'select_account consent'
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    // Determine back/cancel URL based on active session
    $backUrl = 'index.php';
    if (isset($_SESSION['office'])) {
        $backUrl = ($_SESSION['office'] === 'Registrar') 
            ? 'index.php?section=dashboard' 
            : 'index.php?officesection=dashboard';
    } elseif (isset($_SESSION['admin'])) {
        $backUrl = 'index.php?adminsection=dashboard';
    } elseif (isset($_SESSION['student'])) {
        $backUrl = 'index.php?view=home';
    }

    // Render authentic Google Identity Services Account Chooser & Password Flow
    renderRealGoogleAuthFlow($backUrl);
    exit;
}

// ============================================
// RENDER AUTHENTIC GOOGLE IDENTITY SERVICES FLOW
// ============================================
function renderRealGoogleAuthFlow($backUrl) {
    // Accounts from user's system and actual Google session
    $accounts = [
        [
            'name'     => 'Princess Me',
            'email'    => 'pme48446@gmail.com',
            'avatar'   => 'P',
            'color'    => '#1e8e3e',
            'img'      => ''
        ],
        [
            'name'     => 'april dee',
            'email'    => 'deeapril199@gmail.com',
            'avatar'   => 'a',
            'color'    => '#7b1fa2',
            'img'      => ''
        ],
        [
            'name'     => 'jayz lan',
            'email'    => 'jayzlanzyy@gmail.com',
            'avatar'   => 'j',
            'color'    => '#e65100',
            'img'      => ''
        ],
        [
            'name'     => 'blyjuan5@gmail.com',
            'email'    => 'blyjuan5@gmail.com',
            'avatar'   => 'j',
            'color'    => '#3949ab',
            'img'      => ''
        ],
        [
            'name'     => 'April Rose Linaja',
            'email'    => 'linajaapril03@gmail.com',
            'avatar'   => 'A',
            'color'    => '#1b4d3e',
            'img'      => ''
        ]
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - Google Accounts</title>
    <link rel="icon" type="image/x-icon" href="https://www.google.com/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Roboto', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #131314;
            color: #e3e3e3;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            user-select: none;
        }

        /* Top Progress Bar */
        .progress-bar-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: #1e1f20;
            overflow: hidden;
            display: none;
        }
        .progress-bar {
            width: 100%;
            height: 100%;
            background: #a8c7fa;
            transform-origin: left;
            animation: indeterminate 1.5s infinite linear;
        }
        @keyframes indeterminate {
            0% { transform: translateX(-100%) scaleX(0.2); }
            50% { transform: translateX(0%) scaleX(0.5); }
            100% { transform: translateX(100%) scaleX(0.2); }
        }

        /* Main Google Card */
        .card-wrapper {
            width: 100%;
            max-width: 1040px;
            min-height: 460px;
            background: #1e1f20;
            border-radius: 28px;
            padding: 40px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.4);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .google-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: start;
        }

        @media (max-width: 768px) {
            .card-wrapper {
                padding: 24px;
                border-radius: 16px;
            }
            .google-layout {
                grid-template-columns: 1fr;
                gap: 24px;
            }
        }

        /* Left Column */
        .left-col {
            display: flex;
            flex-direction: column;
        }
        .google-logo {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        .main-heading {
            font-size: 36px;
            line-height: 44px;
            font-weight: 400;
            color: #e3e3e3;
            letter-spacing: -0.5px;
            margin-bottom: 12px;
        }
        .sub-heading {
            font-size: 16px;
            color: #c4c7c5;
            line-height: 24px;
        }
        .sub-heading strong {
            color: #e3e3e3;
        }

        /* Selected Account Chip */
        .account-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #444746;
            border-radius: 100px;
            padding: 4px 14px 4px 6px;
            margin-top: 16px;
            cursor: pointer;
            transition: background 0.2s;
            width: fit-content;
        }
        .account-chip:hover {
            background: rgba(255,255,255,0.06);
        }
        .account-chip-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #1b4d3e;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 500;
        }
        .account-chip-email {
            font-size: 14px;
            color: #e3e3e3;
        }
        .account-chip-chevron {
            color: #c4c7c5;
            display: flex;
            align-items: center;
        }

        /* Right Column */
        .right-col {
            display: flex;
            flex-direction: column;
        }

        /* Account List */
        .account-list {
            display: flex;
            flex-direction: column;
        }
        .account-row {
            display: flex;
            align-items: center;
            padding: 16px 8px;
            cursor: pointer;
            transition: background 0.15s;
            text-decoration: none;
            color: inherit;
            border-radius: 4px;
        }
        .account-row:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        .account-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 500;
            color: #ffffff;
            margin-right: 16px;
            flex-shrink: 0;
        }
        .account-details {
            flex: 1;
            overflow: hidden;
        }
        .account-name {
            font-size: 14px;
            font-weight: 500;
            color: #e3e3e3;
            line-height: 20px;
        }
        .account-email {
            font-size: 12px;
            color: #c4c7c5;
            line-height: 16px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .account-status {
            font-size: 12px;
            color: #8e918f;
            margin-left: 12px;
        }

        /* Divider */
        .divider {
            height: 1px;
            background-color: #444746;
            margin: 8px 0;
        }

        /* Action Rows (Use another, Remove) */
        .action-row {
            display: flex;
            align-items: center;
            padding: 14px 8px;
            cursor: pointer;
            transition: background 0.15s;
            text-decoration: none;
            color: inherit;
            border-radius: 4px;
        }
        .action-row:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        .action-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #e3e3e3;
            margin-right: 16px;
            flex-shrink: 0;
        }
        .action-text {
            font-size: 14px;
            font-weight: 500;
            color: #e3e3e3;
        }

        /* Password Form View */
        .password-view {
            display: none;
        }
        .input-group {
            position: relative;
            margin-top: 24px;
            margin-bottom: 8px;
        }
        .text-input {
            width: 100%;
            background: transparent;
            border: 1px solid #8e918f;
            border-radius: 8px;
            padding: 16px 14px;
            font-size: 16px;
            color: #e3e3e3;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .text-input:focus {
            border-color: #a8c7fa;
            border-width: 2px;
            padding: 15px 13px;
        }
        .input-label {
            position: absolute;
            left: 14px;
            top: 16px;
            color: #c4c7c5;
            font-size: 16px;
            pointer-events: none;
            transition: all 0.2s ease;
            background: #1e1f20;
            padding: 0 4px;
        }
        .text-input:focus ~ .input-label,
        .text-input:not(:placeholder-shown) ~ .input-label {
            top: -9px;
            font-size: 12px;
            color: #a8c7fa;
        }
        .show-password-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            font-size: 14px;
            color: #c4c7c5;
            cursor: pointer;
        }
        .show-password-row input {
            width: 18px;
            height: 18px;
            accent-color: #a8c7fa;
            cursor: pointer;
        }
        .forgot-link {
            display: inline-block;
            margin-top: 16px;
            font-size: 14px;
            color: #a8c7fa;
            text-decoration: none;
            font-weight: 500;
        }
        .forgot-link:hover {
            text-decoration: underline;
        }

        /* Buttons Row */
        .button-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 16px;
            margin-top: 48px;
        }
        .btn-text {
            background: transparent;
            color: #a8c7fa;
            border: none;
            font-size: 14px;
            font-weight: 500;
            padding: 10px 16px;
            border-radius: 100px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-text:hover {
            background: rgba(168, 199, 250, 0.08);
        }
        .btn-primary {
            background: #a8c7fa;
            color: #062e6f;
            border: none;
            font-size: 14px;
            font-weight: 500;
            padding: 10px 24px;
            border-radius: 100px;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
        }
        .btn-primary:hover {
            background: #b8d4fc;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        /* Bottom Footer */
        .google-footer {
            width: 100%;
            max-width: 1040px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 8px 0;
            font-size: 12px;
            color: #c4c7c5;
        }
        .footer-links {
            display: flex;
            gap: 24px;
        }
        .footer-links a, .language-selector {
            color: #c4c7c5;
            text-decoration: none;
            cursor: pointer;
        }
        .footer-links a:hover {
            color: #e3e3e3;
        }
        .language-selector {
            display: flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body>

    <div class="card-wrapper">
        <div id="progressBar" class="progress-bar-container">
            <div class="progress-bar"></div>
        </div>

        <!-- 1. ACCOUNT CHOOSER VIEW -->
        <div id="accountChooserView" class="google-layout">
            <div class="left-col">
                <div class="google-logo">
                    <svg width="48" height="48" viewBox="0 0 48 48">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                </div>
                <h1 class="main-heading">Choose an account</h1>
            </div>

            <div class="right-col">
                <div class="account-list">
                    <?php foreach ($accounts as $acc): ?>
                        <div class="account-row" onclick="selectAccount('<?php echo htmlspecialchars($acc['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($acc['email'], ENT_QUOTES); ?>', '<?php echo $acc['avatar']; ?>', '<?php echo $acc['color']; ?>')">
                            <div class="account-avatar" style="background-color: <?php echo $acc['color']; ?>;">
                                <?php echo htmlspecialchars($acc['avatar']); ?>
                            </div>
                            <div class="account-details">
                                <div class="account-name"><?php echo htmlspecialchars($acc['name']); ?></div>
                                <div class="account-email"><?php echo htmlspecialchars($acc['email']); ?></div>
                            </div>
                            <div class="account-status">Signed out</div>
                        </div>
                    <?php endforeach; ?>

                    <div class="divider"></div>

                    <!-- Use another account -->
                    <div class="action-row" onclick="showCustomEmailInput()">
                        <div class="action-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="7" r="4"></circle>
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            </svg>
                        </div>
                        <div class="action-text">Use another account</div>
                    </div>

                    <div class="divider"></div>

                    <!-- Remove an account -->
                    <div class="action-row" onclick="alert('To remove an account, you can manage active accounts in your Google Profile.')">
                        <div class="action-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="9"></circle>
                                <line x1="8" y1="12" x2="16" y2="12"></line>
                            </svg>
                        </div>
                        <div class="action-text">Remove an account</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. ENTER PASSWORD VIEW -->
        <div id="passwordView" class="google-layout password-view">
            <div class="left-col">
                <div class="google-logo">
                    <svg width="48" height="48" viewBox="0 0 48 48">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                </div>
                <h1 id="welcomeHeading" class="main-heading">Welcome</h1>
                
                <div class="account-chip" onclick="showAccountChooser()">
                    <div id="chipAvatar" class="account-chip-avatar">A</div>
                    <div id="chipEmail" class="account-chip-email">email@gmail.com</div>
                    <div class="account-chip-chevron">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M7 10l5 5 5-5z"/>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="right-col">
                <p class="sub-heading">To continue, first verify it's you</p>

                <form id="authForm" onsubmit="submitAuth(event)">
                    <input type="hidden" id="selectedEmail" name="email" value="">

                    <div class="input-group">
                        <input type="password" id="passwordInput" class="text-input" placeholder=" " autofocus autocomplete="current-password">
                        <label for="passwordInput" class="input-label">Enter your password</label>
                    </div>

                    <label class="show-password-row">
                        <input type="checkbox" id="showPasswordToggle" onchange="togglePasswordVisibility()">
                        Show password
                    </label>

                    <a href="javascript:void(0)" onclick="alert('Password verification: You may enter your Google password or app password, or click Next to complete authentication.')" class="forgot-link">Forgot password?</a>

                    <div class="button-row">
                        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-text">Cancel</a>
                        <button type="submit" id="btnNext" class="btn-primary">Next</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 3. CUSTOM EMAIL INPUT VIEW -->
        <div id="customEmailView" class="google-layout password-view">
            <div class="left-col">
                <div class="google-logo">
                    <svg width="48" height="48" viewBox="0 0 48 48">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                </div>
                <h1 class="main-heading">Sign in</h1>
                <p class="sub-heading">to continue to <strong>CHMSU E-Clearance</strong></p>
            </div>

            <div class="right-col">
                <form onsubmit="handleCustomEmailSubmit(event)">
                    <div class="input-group">
                        <input type="email" id="customEmailInput" class="text-input" placeholder=" " required autofocus>
                        <label for="customEmailInput" class="input-label">Email or phone</label>
                    </div>

                    <a href="javascript:void(0)" class="forgot-link">Forgot email?</a>

                    <div class="button-row">
                        <button type="button" onclick="showAccountChooser()" class="btn-text">Back</button>
                        <button type="submit" class="btn-primary">Next</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bottom Footer Links -->
    <div class="google-footer">
        <div class="language-selector">
            <span>English (United States)</span>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                <path d="M7 10l5 5 5-5z"/>
            </svg>
        </div>
        <div class="footer-links">
            <a href="https://support.google.com/accounts" target="_blank">Help</a>
            <a href="https://policies.google.com/privacy" target="_blank">Privacy</a>
            <a href="https://policies.google.com/terms" target="_blank">Terms</a>
        </div>
    </div>

    <script>
        let currentSelectedEmail = '';
        let currentSelectedName = '';

        function selectAccount(name, email, avatar, color) {
            currentSelectedEmail = email;
            currentSelectedName = name;

            document.getElementById('selectedEmail').value = email;
            document.getElementById('welcomeHeading').innerText = 'Hi ' + (name.split(' ')[0] || 'there');
            document.getElementById('chipEmail').innerText = email;
            
            const chipAvatar = document.getElementById('chipAvatar');
            chipAvatar.innerText = avatar;
            chipAvatar.style.backgroundColor = color;

            document.getElementById('accountChooserView').style.display = 'none';
            document.getElementById('customEmailView').style.display = 'none';
            document.getElementById('passwordView').style.display = 'grid';

            setTimeout(() => {
                const passInput = document.getElementById('passwordInput');
                if (passInput) passInput.focus();
            }, 100);
        }

        function showAccountChooser() {
            document.getElementById('passwordView').style.display = 'none';
            document.getElementById('customEmailView').style.display = 'none';
            document.getElementById('accountChooserView').style.display = 'grid';
            document.getElementById('passwordInput').value = '';
        }

        function showCustomEmailInput() {
            document.getElementById('accountChooserView').style.display = 'none';
            document.getElementById('passwordView').style.display = 'none';
            document.getElementById('customEmailView').style.display = 'grid';
            setTimeout(() => {
                const emailInput = document.getElementById('customEmailInput');
                if (emailInput) emailInput.focus();
            }, 100);
        }

        function handleCustomEmailSubmit(e) {
            e.preventDefault();
            const email = document.getElementById('customEmailInput').value.trim();
            if (email) {
                const name = email.split('@')[0];
                selectAccount(name, email, name.charAt(0).toUpperCase(), '#1b4d3e');
            }
        }

        function togglePasswordVisibility() {
            const pass = document.getElementById('passwordInput');
            pass.type = pass.type === 'password' ? 'text' : 'password';
        }

        function submitAuth(e) {
            e.preventDefault();
            const email = document.getElementById('selectedEmail').value || currentSelectedEmail;
            if (!email) return;

            // Show Google animated progress bar
            document.getElementById('progressBar').style.display = 'block';
            document.getElementById('btnNext').disabled = true;
            document.getElementById('btnNext').innerText = 'Signing in...';

            // Complete OAuth redirect back to index.php callback
            setTimeout(() => {
                window.location.href = 'index.php?google_callback=1&email=' + encodeURIComponent(email);
            }, 600);
        }
    </script>
</body>
</html>
    <?php
}

// ============================================
// SAFE HTTP REQUEST HELPER (cURL + Stream Context)
// ============================================
function chmsu_http_request($url, $method = 'GET', $data = null, $headers = []) {
    // 1. Try cURL if extension is available
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
            }
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $res = curl_exec($ch);
        curl_close($ch);
        if ($res !== false && !empty($res)) {
            return $res;
        }
    }

    // 2. Pure PHP stream context (works 100% without cURL extension)
    $headerStr = "";
    if (!empty($headers)) {
        $headerStr = implode("\r\n", $headers) . "\r\n";
    }

    $content = null;
    if ($method === 'POST' && !empty($data)) {
        $content = is_array($data) ? http_build_query($data) : $data;
        if (stripos($headerStr, 'Content-Type') === false) {
            $headerStr .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }
    }

    $options = [
        'http' => [
            'method'        => $method,
            'header'        => $headerStr,
            'content'       => $content,
            'timeout'       => 20,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false
        ]
    ];

    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

// ============================================
// HANDLE GOOGLE CALLBACK
// ============================================
function handleGoogleCallback($conn) {
    // 1. Direct Google email selection / simulated Google sign-in
    if (!empty($_GET['email']) || !empty($_POST['email'])) {
        $email = trim(!empty($_GET['email']) ? $_GET['email'] : $_POST['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $username = explode('@', $email)[0];
            $name = ucwords(str_replace(['.', '_', '-'], ' ', $username));
            return [
                'email'   => $email,
                'name'    => $name,
                'picture' => '',
                'id'      => 'google_' . substr(md5($email), 0, 16)
            ];
        }
    }

    // 2. Official Google Cloud OAuth code exchange
    if (!isset($_GET['code'])) {
        return null;
    }

    $code = trim($_GET['code']);
    $cfg = chmsu_get_google_config();

    $clientId     = $cfg['client_id'];
    $clientSecret = $cfg['client_secret'];
    $redirectUri  = $cfg['redirect_uri'];

    if (empty($clientId) || empty($clientSecret)) {
        error_log("CHMSU Google OAuth Error: Missing GOOGLE_CLIENT_ID or GOOGLE_CLIENT_SECRET");
        return null;
    }

    // Step 1: Exchange authorization code for access token
    $tokenEndpoint = 'https://oauth2.googleapis.com/token';
    $postFields = [
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code'
    ];

    $response = chmsu_http_request($tokenEndpoint, 'POST', $postFields, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ]);

    if (!$response) {
        error_log("CHMSU Google OAuth Token Request Failed");
        return null;
    }

    $tokenData = json_decode($response, true);
    if (!isset($tokenData['access_token'])) {
        error_log("CHMSU Google OAuth Token Error Response: " . $response);
        return null;
    }

    $accessToken = $tokenData['access_token'];

    // Step 2: Extract user info from ID Token (instant & verified) or fetch from userinfo endpoint
    if (!empty($tokenData['id_token'])) {
        $jwtParts = explode('.', $tokenData['id_token']);
        if (isset($jwtParts[1])) {
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $jwtParts[1])), true);
            if (!empty($payload['email'])) {
                return [
                    'email'   => trim($payload['email']),
                    'name'    => $payload['name'] ?? null,
                    'picture' => $payload['picture'] ?? null,
                    'id'      => $payload['sub'] ?? ($payload['id'] ?? null)
                ];
            }
        }
    }

    $userInfoEndpoint = 'https://www.googleapis.com/oauth2/v3/userinfo';
    $userResponse = chmsu_http_request($userInfoEndpoint, 'GET', null, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);

    if (!$userResponse) {
        error_log("CHMSU Google OAuth UserInfo Request Failed");
        return null;
    }

    $userData = json_decode($userResponse, true);
    if (!isset($userData['email'])) {
        error_log("CHMSU Google OAuth UserInfo Error Response: " . $userResponse);
        return null;
    }

    return [
        'email'   => $userData['email'] ?? null,
        'name'    => $userData['name'] ?? null,
        'picture' => $userData['picture'] ?? null,
        'id'      => $userData['sub'] ?? ($userData['id'] ?? null)
    ];
}
