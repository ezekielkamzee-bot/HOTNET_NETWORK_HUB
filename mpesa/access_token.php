<?php

/*
|--------------------------------------------------------------------------
| M-Pesa Access Token
|--------------------------------------------------------------------------
| Project: HOTNET
| File: mpesa/access_token.php
| Environment: Sandbox
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';


/*
|--------------------------------------------------------------------------
| Generate M-Pesa Access Token
|--------------------------------------------------------------------------
*/

function getMpesaAccessToken()
{
    // Check cURL availability
    if (!function_exists('curl_init')) {
        throw new Exception(
            'PHP cURL extension is not enabled.'
        );
    }

    // Retrieve credentials
    $consumerKey = MPESA_CONSUMER_KEY;
    $consumerSecret = MPESA_CONSUMER_SECRET;

    // Validate credentials
    if (
        empty($consumerKey) ||
        empty($consumerSecret) ||
        strpos($consumerKey, 'PASTE_YOUR_') === 0 ||
        strpos($consumerSecret, 'PASTE_YOUR_') === 0
    ) {
        throw new Exception(
            'M-Pesa Consumer Key or Consumer Secret is missing.'
        );
    }

    // Encode credentials
    $credentials = base64_encode(
        $consumerKey . ':' . $consumerSecret
    );

    // Initialize cURL
    $ch = curl_init();

    if ($ch === false) {
        throw new Exception(
            'Unable to initialize M-Pesa connection.'
        );
    }

    // Configure request
    curl_setopt_array($ch, [

        CURLOPT_URL => MPESA_OAUTH_URL,

        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/json'
        ],

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_TIMEOUT => 30,

        CURLOPT_CONNECTTIMEOUT => 15,

        CURLOPT_SSL_VERIFYPEER => true,

        CURLOPT_SSL_VERIFYHOST => 2

    ]);

    // Execute request
    $response = curl_exec($ch);

    // Check connection errors
    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        throw new Exception(
            'M-Pesa connection error: ' . $error
        );
    }

    // Retrieve HTTP status
    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    // Close connection
    curl_close($ch);

    // Decode JSON response
    $data = json_decode(
        $response,
        true
    );

    // Validate JSON
    if (!is_array($data)) {

        throw new Exception(
            'Invalid M-Pesa response. HTTP Status: ' .
            $httpCode . ' | ' .
            json_last_error_msg()
        );
    }

    // Check HTTP status
    if ($httpCode < 200 || $httpCode >= 300) {

        throw new Exception(
            'M-Pesa authentication failed. HTTP Status: ' .
            $httpCode
        );
    }

    // Check access token
    if (empty($data['access_token'])) {

        throw new Exception(
            'M-Pesa access token was not returned.'
        );
    }

    // Return access token
    return $data['access_token'];
}
