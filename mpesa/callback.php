<?php

/*
|--------------------------------------------------------------------------
| HOTNET M-PESA CALLBACK RECEIVER
|--------------------------------------------------------------------------
| 1. Receives Safaricom callback JSON.
| 2. Validates the callback structure.
| 3. Saves the original callback in mpesa_callback_inbox.
| 4. Attempts to process the saved callback.
| 5. Preserves callbacks for later processing if necessary.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/callback_processor.php';

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| CALLBACK RESPONSE
|--------------------------------------------------------------------------
*/

function callbackResponse(
    int $httpStatus = 200,
    int $resultCode = 0,
    string $description = 'Callback received successfully'
) {

    if (!headers_sent()) {

        http_response_code($httpStatus);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode([
        'ResultCode' => $resultCode,
        'ResultDesc' => $description
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| READ ORIGINAL CALLBACK
|--------------------------------------------------------------------------
*/

$rawData = file_get_contents('php://input');

if ($rawData === false || trim($rawData) === '') {

    hotnetCallbackLog(
        'Empty callback request received.'
    );

    callbackResponse(
        400,
        1,
        'Empty callback request'
    );
}

/*
|--------------------------------------------------------------------------
| DECODE CALLBACK JSON
|--------------------------------------------------------------------------
*/

$data = json_decode($rawData, true);

if (!is_array($data)) {

    hotnetCallbackLog(
        'Invalid callback JSON received.'
    );

    callbackResponse(
        400,
        1,
        'Invalid callback JSON'
    );
}

/*
|--------------------------------------------------------------------------
| VALIDATE STK CALLBACK
|--------------------------------------------------------------------------
*/

$callback = $data['Body']['stkCallback'] ?? null;

if (!is_array($callback)) {

    hotnetCallbackLog(
        'STK callback information missing.'
    );

    callbackResponse(
        400,
        1,
        'STK callback information missing'
    );
}

/*
|--------------------------------------------------------------------------
| EXTRACT CALLBACK IDENTIFIERS
|--------------------------------------------------------------------------
*/

$checkoutRequestID = trim(
    (string)($callback['CheckoutRequestID'] ?? '')
);

$merchantRequestID = trim(
    (string)($callback['MerchantRequestID'] ?? '')
);

if ($checkoutRequestID === '') {

    hotnetCallbackLog(
        'Callback missing CheckoutRequestID.'
    );

    callbackResponse(
        400,
        1,
        'CheckoutRequestID missing'
    );
}

/*
|--------------------------------------------------------------------------
| CONNECT TO DATABASE
|--------------------------------------------------------------------------
*/

try {

    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (Throwable $e) {

    hotnetCallbackLog(
        'Callback database connection failed: ' .
        $e->getMessage()
    );

    callbackResponse(
        500,
        1,
        'Callback database connection failed'
    );
}

/*
|--------------------------------------------------------------------------
| SAVE CALLBACK BEFORE PROCESSING
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare(
        "INSERT INTO mpesa_callback_inbox
            (
                checkout_request_id,
                merchant_request_id,
                callback_payload,
                processing_status,
                attempts
            )
         VALUES (?, ?, ?, 'Pending', 0)"
    );

    $stmt->execute([
        $checkoutRequestID,

        $merchantRequestID !== ''
            ? $merchantRequestID
            : null,

        $rawData
    ]);

    $inboxID = (int)$pdo->lastInsertId();

    hotnetCallbackLog(
        "Callback saved successfully. " .
        "Inbox ID: {$inboxID}, " .
        "CheckoutRequestID: {$checkoutRequestID}"
    );

} catch (Throwable $e) {

    hotnetCallbackLog(
        'Unable to save callback in inbox: ' .
        $e->getMessage()
    );

    callbackResponse(
        500,
        1,
        'Callback could not be saved'
    );
}

/*
|--------------------------------------------------------------------------
| PROCESS SAVED CALLBACK
|--------------------------------------------------------------------------
*/

try {

    processPendingMpesaCallbacks(
        $pdo,
        $checkoutRequestID
    );

    hotnetCallbackLog(
        "Callback processing attempted for: " .
        $checkoutRequestID
    );

} catch (Throwable $e) {

    hotnetCallbackLog(
        'Callback processor could not run: ' .
        $e->getMessage()
    );

    /*
     * The original callback is already saved.
     * It remains available for a later retry.
     */
}

/*
|--------------------------------------------------------------------------
| ACKNOWLEDGE SAVED CALLBACK
|--------------------------------------------------------------------------
*/

callbackResponse(
    200,
    0,
    'Callback received successfully'
);

?>