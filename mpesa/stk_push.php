<?php

/*
|--------------------------------------------------------------------------
| M-Pesa STK Push
|--------------------------------------------------------------------------
| File: network/mpesa/stk_push.php
| Environment: Safaricom Daraja Sandbox
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_token.php';


/*
|--------------------------------------------------------------------------
| Generate M-Pesa Password
|--------------------------------------------------------------------------
*/

function generateMpesaPassword($timestamp)
{
    return base64_encode(
        MPESA_SHORTCODE .
        MPESA_PASSKEY .
        $timestamp
    );
}


/*
|--------------------------------------------------------------------------
| Generate M-Pesa Timestamp
|--------------------------------------------------------------------------
*/

function generateMpesaTimestamp()
{
    return date('YmdHis');
}


/*
|--------------------------------------------------------------------------
| Format Kenyan Phone Number
|--------------------------------------------------------------------------
*/

function formatMpesaPhone($phone)
{
    $phone = trim((string) $phone);

    $phone = preg_replace('/[^0-9+]/', '', $phone);

    if (substr($phone, 0, 1) === '+') {

        $phone = substr($phone, 1);

    } elseif (substr($phone, 0, 1) === '0') {

        $phone = '254' . substr($phone, 1);

    } elseif (preg_match('/^[17][0-9]{8}$/', $phone)) {

        $phone = '254' . $phone;
    }

    return $phone;
}


/*
|--------------------------------------------------------------------------
| Validate Kenyan Phone Number
|--------------------------------------------------------------------------
*/

function isValidMpesaPhone($phone)
{
    return preg_match('/^254[17][0-9]{8}$/', $phone) === 1;
}


/*
|--------------------------------------------------------------------------
| Send M-Pesa STK Push
|--------------------------------------------------------------------------
*/

function sendStkPush(
    $phone,
    $amount,
    $accountReference,
    $description = 'WiFi Payment'
) {

    /*
    |--------------------------------------------------------------------------
    | Check Required PHP Extensions
    |--------------------------------------------------------------------------
    */

    if (!function_exists('curl_init')) {

        throw new Exception(
            'PHP cURL extension is not enabled.'
        );
    }

    if (!function_exists('getMpesaAccessToken')) {

        throw new Exception(
            'M-Pesa access token function is unavailable.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Format Phone Number
    |--------------------------------------------------------------------------
    */

    $phone = formatMpesaPhone($phone);


    /*
    |--------------------------------------------------------------------------
    | Validate Phone Number
    |--------------------------------------------------------------------------
    */

    if (!isValidMpesaPhone($phone)) {

        throw new Exception(
            'Invalid Kenyan M-Pesa phone number. ' .
            'Use 0712345678, 0112345678 or 254712345678.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Amount
    |--------------------------------------------------------------------------
    */

    if (!is_numeric($amount)) {

        throw new Exception(
            'Invalid payment amount.'
        );
    }

    $amount = (int) round((float) $amount);

    if ($amount <= 0) {

        throw new Exception(
            'Payment amount must be greater than zero.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Account Reference
    |--------------------------------------------------------------------------
    */

    $accountReference = trim((string) $accountReference);

    if ($accountReference === '') {

        throw new Exception(
            'Payment account reference is required.'
        );
    }

    $accountReference = substr(
        $accountReference,
        0,
        12
    );


    /*
    |--------------------------------------------------------------------------
    | Clean Transaction Description
    |--------------------------------------------------------------------------
    */

    $description = trim((string) $description);

    if ($description === '') {

        $description = 'WiFi Payment';
    }

    $description = substr(
        $description,
        0,
        13
    );


    /*
    |--------------------------------------------------------------------------
    | Generate Timestamp
    |--------------------------------------------------------------------------
    */

    $timestamp = generateMpesaTimestamp();


    /*
    |--------------------------------------------------------------------------
    | Generate Password
    |--------------------------------------------------------------------------
    */

    $password = generateMpesaPassword($timestamp);


    /*
    |--------------------------------------------------------------------------
    | Get Access Token
    |--------------------------------------------------------------------------
    */

    $accessToken = getMpesaAccessToken();

    if (empty($accessToken)) {

        throw new Exception(
            'Unable to obtain M-Pesa access token.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Prepare STK Push Request
    |--------------------------------------------------------------------------
    */

    $requestData = [

        'BusinessShortCode' => MPESA_SHORTCODE,

        'Password' => $password,

        'Timestamp' => $timestamp,

        'TransactionType' => 'CustomerPayBillOnline',

        'Amount' => $amount,

        'PartyA' => $phone,

        'PartyB' => MPESA_SHORTCODE,

        'PhoneNumber' => $phone,

        'CallBackURL' => MPESA_CALLBACK_URL,

        'AccountReference' => $accountReference,

        'TransactionDesc' => $description

    ];


    /*
    |--------------------------------------------------------------------------
    | Convert Request To JSON
    |--------------------------------------------------------------------------
    */

    $jsonData = json_encode($requestData);

    if ($jsonData === false) {

        throw new Exception(
            'Unable to prepare M-Pesa request: ' .
            json_last_error_msg()
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Initialize cURL
    |--------------------------------------------------------------------------
    */

    $ch = curl_init();

    if ($ch === false) {

        throw new Exception(
            'Unable to initialize M-Pesa connection.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Configure cURL
    |--------------------------------------------------------------------------
    */

    curl_setopt_array($ch, [

        CURLOPT_URL => MPESA_STK_URL,

        CURLOPT_HTTPHEADER => [

            'Authorization: Bearer ' . $accessToken,

            'Content-Type: application/json'

        ],

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => $jsonData,

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_TIMEOUT => 30,

        CURLOPT_CONNECTTIMEOUT => 15,

        CURLOPT_SSL_VERIFYPEER => true,

        CURLOPT_SSL_VERIFYHOST => 2

    ]);


    /*
    |--------------------------------------------------------------------------
    | Send STK Push Request
    |--------------------------------------------------------------------------
    */

    $response = curl_exec($ch);


    /*
    |--------------------------------------------------------------------------
    | Check Connection Errors
    |--------------------------------------------------------------------------
    */

    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        throw new Exception(
            'M-Pesa connection error: ' . $error
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Get HTTP Status Code
    |--------------------------------------------------------------------------
    */

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );


    /*
    |--------------------------------------------------------------------------
    | Close cURL Connection
    |--------------------------------------------------------------------------
    */

    curl_close($ch);


    /*
    |--------------------------------------------------------------------------
    | Decode M-Pesa Response
    |--------------------------------------------------------------------------
    */

    $data = json_decode($response, true);


    /*
    |--------------------------------------------------------------------------
    | Validate JSON Response
    |--------------------------------------------------------------------------
    */

    if (!is_array($data)) {

        throw new Exception(
            'Invalid response received from M-Pesa.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Check HTTP Status
    |--------------------------------------------------------------------------
    */

    if ($httpCode < 200 || $httpCode >= 300) {

        $message =
            $data['errorMessage']
            ?? $data['ResponseDescription']
            ?? $data['error_description']
            ?? $data['message']
            ?? 'M-Pesa STK Push failed.';

        throw new Exception(
            'M-Pesa STK Push failed (HTTP ' .
            $httpCode . '): ' . $message
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Check M-Pesa Response Code
    |--------------------------------------------------------------------------
    */

    if (
        isset($data['ResponseCode']) &&
        (string) $data['ResponseCode'] !== '0'
    ) {

        $message =
            $data['ResponseDescription']
            ?? 'M-Pesa rejected the STK Push request.';

        throw new Exception(
            'M-Pesa STK Push failed: ' . $message
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Return Successful Response
    |--------------------------------------------------------------------------
    */

    return $data;
}