
<?php

/*
|--------------------------------------------------------------------------
| HOTNET PENDING M-PESA CALLBACK RETRY
|--------------------------------------------------------------------------
| Run from PowerShell:
| php mpesa/retry_callbacks.php
|
| This script is CLI-only for security.
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

    echo "HOTNET M-PESA CALLBACK RETRY\n";
    echo "============================\n";

    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM mpesa_callback_inbox
         WHERE processing_status = 'Pending'"
    );

    $pendingCount = (int)$stmt->fetchColumn();

    echo "Pending callbacks: {$pendingCount}\n";

    if ($pendingCount === 0) {
        echo "Nothing to process.\n";
        exit(0);
    }

    processPendingMpesaCallbacks($pdo);

    $stmt = $pdo->query(
        "SELECT
            processing_status,
            COUNT(*) AS total
         FROM mpesa_callback_inbox
         GROUP BY processing_status"
    );

    echo "\nCALLBACK SUMMARY\n";
    echo "----------------\n";

    while ($row = $stmt->fetch()) {
        echo $row['processing_status'] .
             ': ' . $row['total'] . "\n";
    }

    echo "\nRetry process completed.\n";
    echo "Check mpesa/callback_log.txt for details.\n";

} catch (Throwable $e) {

    echo "ERROR: " . $e->getMessage() . "\n";

    if (function_exists('hotnetCallbackLog')) {
        hotnetCallbackLog(
            'Callback retry script error: ' . $e->getMessage()
        );
    }

    exit(1);
}
