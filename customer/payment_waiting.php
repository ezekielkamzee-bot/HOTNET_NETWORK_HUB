
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| PAYMENT ACCESS TOKEN
|--------------------------------------------------------------------------
*/
$token = $_GET['token'] ?? '';

if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(403);
    exit('Invalid payment access link.');
}

$tokenHash = hash('sha256', $token);

/*
|--------------------------------------------------------------------------
| LOAD PAYMENT
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT
        p.id,
        p.user_id,
        p.customer_name,
        p.amount,
        p.payment_status,
        p.mpesa_receipt_number,
        p.voucher_id,
        pk.package_name
    FROM payments p
    LEFT JOIN packages pk ON pk.id = p.package_id
    WHERE p.payment_access_token_hash = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to check payment.');
}

$stmt->bind_param('s', $tokenHash);
$stmt->execute();

$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    http_response_code(404);
    exit('Payment record not found.');
}

$status = $payment['payment_status'];

/*
|--------------------------------------------------------------------------
| PAID PAYMENT
|--------------------------------------------------------------------------
*/
if ($status === 'Paid') {

    if (empty($payment['user_id']) || empty($payment['voucher_id'])) {
        exit('Payment received, but voucher assignment is incomplete. Contact support.');
    }

    $customerId = (int)$payment['user_id'];
    $voucherId  = (int)$payment['voucher_id'];

    /*
    |--------------------------------------------------------------------------
    | VERIFY CUSTOMER
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT id, fullname, username, status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer || $customer['status'] !== 'Active') {
        exit('Customer account is not active. Contact support.');
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY VOUCHER
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT id, voucher_code, user_id, status, expiry_date
        FROM vouchers
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param('ii', $voucherId, $customerId);
    $stmt->execute();
    $voucher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        !$voucher ||
        $voucher['status'] !== 'Active' ||
        empty($voucher['voucher_code']) ||
        (strtotime($voucher['expiry_date']) !== false &&
         strtotime($voucher['expiry_date']) < time())
    ) {
        exit('Your voucher is not active or has expired.');
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER SESSION
    |--------------------------------------------------------------------------
    */
    session_regenerate_id(true);

    $_SESSION['customer_id'] = $customerId;
    $_SESSION['customer_name'] = $customer['fullname'];
    $_SESSION['customer_username'] = $customer['username'];

    /*
    |--------------------------------------------------------------------------
    | CHECK DEVICE BINDING SETTING
    |--------------------------------------------------------------------------
    */
    $deviceBindingEnabled = false;

    $stmt = $conn->prepare("
    SELECT setting_value
    FROM hotspot_settings
    WHERE setting_key = 'device_binding_enabled'
    LIMIT 1
");

$stmt->execute();
$setting = $stmt->get_result()->fetch_assoc();
$stmt->close();

$deviceBindingEnabled = isset($setting['setting_value'])
    ? ((int)$setting['setting_value'] === 1)
    : true;

    /*
    |--------------------------------------------------------------------------
    | VERIFY REGISTERED DEVICE COOKIE
    |--------------------------------------------------------------------------
    */
    $deviceToken = $_COOKIE['hotnet_device_token'] ?? '';
    $validDevice = false;

    if (
        $deviceBindingEnabled &&
        is_string($deviceToken) &&
        preg_match('/^[a-f0-9]{64}$/', $deviceToken)
    ) {
        $deviceHash = hash('sha256', $deviceToken);

        $stmt = $conn->prepare("
            SELECT id
            FROM customer_devices
            WHERE user_id = ?
              AND device_token_hash = ?
              AND status = 'Active'
            LIMIT 1
        ");

        $stmt->bind_param('is', $customerId, $deviceHash);
        $stmt->execute();

        $registeredDevice = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $validDevice = (bool)$registeredDevice;
    }

    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC HOTSPOT AUTHENTICATION
    |--------------------------------------------------------------------------
    */
    if (!$deviceBindingEnabled || $validDevice) {

        $_SESSION['pending_hotspot_voucher'] = $voucher['voucher_code'];

        if ($deviceBindingEnabled) {
            $_SESSION['pending_hotspot_device_token'] = $deviceToken;
        } else {
            unset($_SESSION['pending_hotspot_device_token']);
        }

        $_SESSION['device_auth_csrf'] = bin2hex(random_bytes(32));

        $csrfToken = $_SESSION['device_auth_csrf'];
        $voucherCode = $voucher['voucher_code'];
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Connecting - HOTNET</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    background: #fff4e8;
                    text-align: center;
                    padding: 60px 15px;
                }
                .box {
                    max-width: 420px;
                    margin: auto;
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    box-shadow: 0 4px 18px rgba(0,0,0,.08);
                }
                h2 { color: #f07800; }
            </style>
        </head>
        <body>
            <div class="box">
                <h2>HOTNET</h2>
                <h3>Payment successful!</h3>
                <p>Your voucher is ready.</p>
                <p>Connecting your device. Please wait...</p>

                <form id="connectForm"
                      method="POST"
                      action="../hotspot/device_authentication.php">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= h($csrfToken) ?>">

                    <input type="hidden"
                           name="voucher_code"
                           value="<?= h($voucherCode) ?>">

                    <noscript>
                        <button type="submit">Connect now</button>
                    </noscript>
                </form>
            </div>

            <script>
                document.getElementById('connectForm').submit();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DEVICE NOT REGISTERED: SEND CUSTOMER TO DASHBOARD
    |--------------------------------------------------------------------------
    */
    $_SESSION['hotnet_notice'] =
        'Payment successful. Register this device before connecting to the hotspot.';

    header('Location: devices.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| PENDING PAYMENT
|--------------------------------------------------------------------------
*/
if ($status === 'Pending') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="refresh" content="5">
        <title>Payment Processing - HOTNET</title>
    </head>
    <body style="font-family:Arial;text-align:center;padding:60px 15px;">
        <h2 style="color:#f07800;">HOTNET</h2>
        <h3>Waiting for M-Pesa confirmation</h3>
        <p>Please check your phone and complete the M-Pesa prompt.</p>
        <p>This page will check again automatically.</p>
    </body>
    </html>
    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| FAILED OR OTHER PAYMENT STATUS
|--------------------------------------------------------------------------
*/
if ($status === 'Failed') {
    $message = 'Payment was not completed.';
} else {
    $message = 'Payment status could not be confirmed. Please contact support.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Status - HOTNET</title>
</head>
<body style="font-family:Arial;text-align:center;padding:60px 15px;">
    <h2 style="color:#f07800;">HOTNET</h2>
    <h3><?= h($message) ?></h3>
    <p>Please try again or contact support.</p>
    <a href="buy_package.php">Return to packages</a>
</body>
</html>
