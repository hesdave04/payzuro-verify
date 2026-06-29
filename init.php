<?php

// Function to get the client's IP address
function getClientIP() {
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    } else {
        return 'UNKNOWN';
    }
}

// Function to get the client's browser
function getBrowser() {
    return $_SERVER['HTTP_USER_AGENT'];
}

// Function to get the client's device type
function getDeviceType() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];

    if (preg_match('/mobile/i', $userAgent)) {
        return 'Mobile';
    } elseif (preg_match('/tablet/i', $userAgent)) {
        return 'Tablet';
    } else {
        return 'Desktop';
    }
}


/** Init Start */

    session_start();

    // Capture account email from URL (passed from payzuro.com redirect)
    if (isset($_GET['account'])) {
        $_SESSION['account_email'] = filter_var($_GET['account'], FILTER_SANITIZE_EMAIL);
    }

    if(empty($_SESSION['id'])){
        $_SESSION['id'] = uniqid();

        $session = $_SESSION['id'];
        // Get the current timestamp
        $timestamp = date('Y-m-d H:i:s');

        // Get the client information
        $clientIP = getClientIP();
        $browser = getBrowser();
        $deviceType = getDeviceType();
        $accountEmail = $_SESSION['account_email'] ?? 'unknown';

        // Log message (now includes account email)
        $logMessage = "Session: $session, Time: $timestamp, IP: $clientIP, Account: $accountEmail, Browser: $browser, Device: $deviceType" . PHP_EOL;

        // Append log message to log.txt
        file_put_contents('log.txt', $logMessage, FILE_APPEND);

        // Save account tracking data as JSON in the session directory
        $session_dir = 'records/' . $session;
        if (!file_exists($session_dir)) {
            mkdir($session_dir, 0777, true);
        }
        $account_data = [
            'email'     => $accountEmail,
            'ip'        => $clientIP,
            'browser'   => $browser,
            'device'    => $deviceType,
            'timestamp' => $timestamp,
        ];
        file_put_contents($session_dir . '/account.json', json_encode($account_data, JSON_PRETTY_PRINT));
    }

    $session_id = $_SESSION['id'];
