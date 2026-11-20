<?php
// includes/google_oauth.php

require_once __DIR__ . '/../vendor/autoload.php';

use Google\Client;
use Google\Service\Oauth2;

// ============================================
// GOOGLE OAUTH CONFIGURATION
// ============================================

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID'));
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET'));
define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost/capstone/index.php?google_callback=1');

// ============================================
// GET GOOGLE CLIENT
// ============================================
function getGoogleClient() {
    $client = new Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setRedirectUri(GOOGLE_REDIRECT_URI);
    $client->addScope('email');
    $client->addScope('profile');
    $client->addScope('openid');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');
    
    // FIX: Disable SSL verification for localhost
    $client->setHttpClient(new \GuzzleHttp\Client([
        'verify' => false
    ]));
    
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
        return null;
    }
    
    $client->setAccessToken($token);
    
    $oauth2 = new Oauth2($client);
    $userInfo = $oauth2->userinfo->get();
    
    return [
        'email' => $userInfo->getEmail(),
        'name' => $userInfo->getName(),
        'picture' => $userInfo->getPicture(),
        'id' => $userInfo->getId()
    ];
}