<?php
// includes/google_oauth.php

require_once __DIR__ . '/load_env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Google\Client;
use Google\Service\Oauth2;

// ============================================
// GOOGLE OAUTH CONFIGURATION
// Resolved at runtime via helper functions
// ============================================

function getGoogleClientId() {
    $id = getenv('GOOGLE_CLIENT_ID');
    if (empty($id)) {
        // Fallback: reload .env explicitly
        if (function_exists('chmsu_load_env')) {
            chmsu_load_env(__DIR__ . '/../.env');
        }
        $id = getenv('GOOGLE_CLIENT_ID');
    }
    return $id;
}

function getGoogleClientSecret() {
    $secret = getenv('GOOGLE_CLIENT_SECRET');
    if (empty($secret)) {
        if (function_exists('chmsu_load_env')) {
            chmsu_load_env(__DIR__ . '/../.env');
        }
        $secret = getenv('GOOGLE_CLIENT_SECRET');
    }
    return $secret;
}

function getGoogleRedirectUri() {
    $uri = getenv('GOOGLE_REDIRECT_URI');
    if (empty($uri)) {
        return 'http://localhost/capstone/index.php?google_callback=1';
    }
    return $uri;
}

// ============================================
// GET GOOGLE CLIENT
// ============================================
function getGoogleClient() {
    $clientId     = getGoogleClientId();
    $clientSecret = getGoogleClientSecret();
    $redirectUri  = getGoogleRedirectUri();

    if (empty($clientId) || empty($clientSecret)) {
        throw new Exception("Google OAuth credentials not configured. Check .env file.");
    }

    $client = new Client();
    $client->setClientId($clientId);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri($redirectUri);
    $client->addScope('email');
    $client->addScope('profile');
    $client->addScope('openid');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // FIX: Force IPv4 + disable SSL for localhost + longer timeouts
    $httpClient = new \GuzzleHttp\Client([
        'verify' => false,
        'timeout' => 60,
        'connect_timeout' => 30,
        'curl' => [
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_DNS_CACHE_TIMEOUT => 120,
            CURLOPT_TCP_KEEPALIVE => 1,
        ],
    ]);
    $client->setHttpClient($httpClient);

    return $client;
}

// ============================================
// GET GOOGLE LOGIN URL
// ============================================
function getGoogleLoginUrl() {
    $client = getGoogleClient();
    return $client->createAuthUrl();
}

// ============================================
// HANDLE GOOGLE CALLBACK
// ============================================
function handleGoogleCallback($conn) {
    if (!isset($_GET['code'])) {
        return null;
    }

    $client = getGoogleClient();
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        error_log("Google OAuth token error: " . ($token['error_description'] ?? $token['error']));
        return null;
    }

    $client->setAccessToken($token);

    $oauth2 = new Oauth2($client);
    $userInfo = $oauth2->userinfo->get();

    return [
        'email'   => $userInfo->getEmail(),
        'name'    => $userInfo->getName(),
        'picture' => $userInfo->getPicture(),
        'id'      => $userInfo->getId()
    ];
}