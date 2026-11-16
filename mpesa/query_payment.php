
<?php
/*
|--------------------------------------------------------------------------
| HOTNET - M-PESA STK QUERY DIAGNOSTIC
|--------------------------------------------------------------------------
| Usage:
| php mpesa/query_payment.php 10
|
| This checks an existing payment. It does not initiate a payment
| or change the payment status in the database.
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command-line access only.");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_token.php';
require_once __DIR__ . '/../config/database.php';

$paymentId = isset($argv[1]) ? (int)$argv[1] : 0;

if ($paymentId <= 0) {
    exit("Usage: php mpesa/query_payment.php PAYMENT_ID\n");
}

// Retrieve the existing CheckoutRequestID.
$sql = "
    SELECT id, checkout_request_id, payment_status
    FROM payments
    WHERE id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    exit("Database query preparation failed.\n");
}

mysqli_stmt_bind_param($stmt, "i", $paymentId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$payment = $result ? mysqli_fetch_assoc($result) : null;

mysqli_stmt_close($stmt);

if (!$payment) {
    exit("Payment record not found.\n");
}

if (empty($payment['checkout_request_id'])) {
    exit("This payment has no CheckoutRequestID. Cannot query it.\n");
}

echo "Payment ID: " . $paymentId . PHP_EOL;
echo "Current database status: " . $payment['payment_status'] . PHP_EOL;
echo "Sending STK Query to Safaricom...\n\n";

try {

    $accessToken = getMpesaAccessToken();

    $timestamp = date('YmdHis');

    $password = base64_encode(
        MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp
    );

    $payload = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $payment['checkout_request_id']
    ];

    $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception("Connection error: " . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new Exception("Safaricom returned an invalid response.");
    }

    echo "HTTP Status: " . $httpCode . PHP_EOL;
    echo "Safaricom response:\n";

    // Display only useful response fields.
    $safeFields = [
        'ResponseCode',
        'ResponseDescription',
        'ResultCode',
        'ResultDesc',
        'errorCode',
        'errorMessage'
    ];

    foreach ($safeFields as $field) {
        if (isset($data[$field])) {
            echo $field . ": " . $data[$field] . PHP_EOL;
        }
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        echo "\nThe query was not successful. The database was not changed.\n";
    }

    echo "\nDiagnostic completed. No payment status was changed.\n";

} catch (Throwable $e) {

    echo "QUERY ERROR: " . $e->getMessage() . PHP_EOL;
}
