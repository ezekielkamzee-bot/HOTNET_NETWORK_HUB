
<?php

/*
|--------------------------------------------------------------------------
| HOTNET PENDING CALLBACK RETRY
|--------------------------------------------------------------------------
| This script retries callbacks saved in mpesa_callback_inbox.
| It is restricted to command-line execution for safety.
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied. Run this script from PowerShell.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/callback_processor.php';

date_default_timezone_set('Africa/Nairobi');

try {

    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $stmt = $pdo->query(
        "SELECT id, checkout_request_id
         FROM mpesa_callback_inbox
         WHERE processing_status = 'Pending'
         ORDER BY id ASC
         LIMIT 50"
    );

    $callbacks = $stmt->fetchAll();

    if (!$callbacks) {
        echo "No Pending callbacks found.\n";
        exit;
    }

    echo "Found " . count($callbacks) . " Pending callback(s).\n";

    foreach ($callbacks as $callback) {

        $checkoutRequestID = $callback['checkout_request_id'];

        if (!$checkoutRequestID) {
            echo "Skipping inbox ID {$callback['id']}: missing checkout ID.\n";
            continue;
        }

        echo "Retrying inbox ID {$callback['id']}...\n";

        processPendingMpesaCallbacks(
            $pdo,
            $checkoutRequestID
        );

    }

    echo "\nRetry process completed.\n";

} catch (Throwable $e) {

    error_log(
        'HOTNET pending callback retry error: ' .
        $e->getMessage()
    );

    echo "Retry process failed. Check the PHP error log.\n";
}
