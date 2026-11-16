<?php
session_start();

/*
|--------------------------------------------------------------------------
| HOTNET DEVICE AUTHENTICATION
|--------------------------------------------------------------------------
| File: hotspot/device_authentication.php
|
| Responsibilities:
| 1. Validate the submitted voucher.
| 2. Verify customer and package status.
| 3. Verify registered device token.
| 4. Enforce package device limits.
| 5. Create or reuse a simulated connection.
| 6. Record connection activity.
| 7. Establish the hotspot session.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Read a setting from hotspot_settings.
 */
function getHotspotSetting(mysqli $conn, string $key, string $default = ''): string
{
    $stmt = $conn->prepare("
        SELECT setting_value
        FROM hotspot_settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    $stmt->bind_param("s", $key);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return $row ? (string) $row['setting_value'] : $default;
}

/**
 * Display an authentication error.
 */
function authenticationError(string $message): void
{
    $_SESSION['hotspot_auth_error'] = $message;
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['device_auth_csrf'])) {
    $_SESSION['device_auth_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['device_auth_csrf'];

/*
|--------------------------------------------------------------------------
| PENDING VOUCHER
|--------------------------------------------------------------------------
*/

$pendingVoucher = $_SESSION['pending_hotspot_voucher'] ?? '';

$errorMessage = $_SESSION['hotspot_auth_error'] ?? '';

unset($_SESSION['hotspot_auth_error']);

/*
|--------------------------------------------------------------------------
| AUTHENTICATION VARIABLES
|--------------------------------------------------------------------------
*/

$voucherCode = $pendingVoucher;

/*
|--------------------------------------------------------------------------
| PROCESS AUTHENTICATION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        |--------------------------------------------------------------------------
        | 1. VERIFY CSRF TOKEN
        |--------------------------------------------------------------------------
        */

        $submittedToken = $_POST['csrf_token'] ?? '';

        if (
            empty($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new Exception(
                "Your session has expired. Refresh the page and try again."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 2. GET VOUCHER CODE
        |--------------------------------------------------------------------------
        */

        $voucherCode = trim(
            $_POST['voucher_code'] ?? $pendingVoucher
        );

        if ($voucherCode === '') {
            throw new Exception("Please enter your WiFi voucher code.");
        }

        /*
        |--------------------------------------------------------------------------
        | 3. BEGIN DATABASE TRANSACTION
        |--------------------------------------------------------------------------
        */

        $conn->begin_transaction();

        /*
        |--------------------------------------------------------------------------
        | 4. FIND VOUCHER, CUSTOMER AND PACKAGE
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                v.id AS voucher_id,
                v.voucher_code,
                v.user_id,
                v.package_id,
                v.status AS voucher_status,
                v.expiry_date,

                u.fullname,
                u.username,
                u.status AS user_status,

                p.package_name,
                p.speed,
                p.package_type,
                p.status AS package_status

            FROM vouchers v

            INNER JOIN users u
                ON u.id = v.user_id

            INNER JOIN packages p
                ON p.id = v.package_id

            WHERE v.voucher_code = ?

            LIMIT 1

            FOR UPDATE
        ");

        $stmt->bind_param("s", $voucherCode);
        $stmt->execute();

        $result = $stmt->get_result();

        $voucher = $result->fetch_assoc();

        $stmt->close();

        if (!$voucher) {
            throw new Exception("Invalid WiFi voucher code.");
        }

        /*
        |--------------------------------------------------------------------------
        | 5. CHECK EXISTING CUSTOMER SESSION
        |--------------------------------------------------------------------------
        */

        $customerId = (int) $voucher['user_id'];

        if (
            isset($_SESSION['customer_id']) &&
            (int) $_SESSION['customer_id'] !== $customerId
        ) {
            throw new Exception(
                "This voucher belongs to a different customer account. "
                . "Please log out before connecting."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 6. VERIFY CUSTOMER STATUS
        |--------------------------------------------------------------------------
        */

        if ($voucher['user_status'] !== 'Active') {
            throw new Exception(
                "Your customer account is inactive. Please contact HOTNET support."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 7. VERIFY PACKAGE STATUS
        |--------------------------------------------------------------------------
        */

        if ($voucher['package_status'] !== 'Active') {
            throw new Exception(
                "This WiFi package is currently unavailable."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 8. VERIFY VOUCHER STATUS
        |--------------------------------------------------------------------------
        */

        if ($voucher['voucher_status'] !== 'Active') {
            throw new Exception(
                "This voucher is not active. Please check your voucher status."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 9. CHECK VOUCHER EXPIRY
        |--------------------------------------------------------------------------
        | NULL or empty expiry dates are allowed.
        | Only a non-empty expiry date that has passed is rejected.
        |--------------------------------------------------------------------------
        */

        if (
            $voucher['expiry_date'] !== null &&
            $voucher['expiry_date'] !== '' &&
            strtotime($voucher['expiry_date']) <= time()
        ) {
            throw new Exception(
                "Your WiFi voucher has expired."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 10. DEVICE BINDING SETTINGS
        |--------------------------------------------------------------------------
        */

        $deviceBindingEnabled = (int) getHotspotSetting(
            $conn,
            'device_binding_enabled',
            '1'
        );

        $defaultMaxDevices = (int) getHotspotSetting(
            $conn,
            'default_max_devices',
            '1'
        );

        if ($defaultMaxDevices < 1) {
            $defaultMaxDevices = 1;
        }

        /*
        |--------------------------------------------------------------------------
        | 11. VERIFY REGISTERED DEVICE
        |--------------------------------------------------------------------------
        */

        $deviceId = null;
        $deviceMacAddress = null;

        if ($deviceBindingEnabled === 1) {

            $deviceToken = $_SESSION['pending_hotspot_device_token'] ?? '';

            if (
                empty($deviceToken) ||
                !preg_match('/^[a-f0-9]{64}$/i', $deviceToken)
            ) {
                throw new Exception(
                    "A valid registered device token is required. "
                    . "Register your device from your customer dashboard."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | HASH DEVICE TOKEN
            |--------------------------------------------------------------------------
            */

            $deviceTokenHash = hash('sha256', $deviceToken);

            /*
            |--------------------------------------------------------------------------
            | FIND REGISTERED DEVICE
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    id,
                    user_id,
                    mac_address,
                    status

                FROM customer_devices

                WHERE user_id = ?
                AND device_token_hash = ?
                AND status = 'Active'

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->bind_param(
                "is",
                $customerId,
                $deviceTokenHash
            );

            $stmt->execute();

            $deviceResult = $stmt->get_result();

            $device = $deviceResult->fetch_assoc();

            $stmt->close();

            if (!$device) {
                throw new Exception(
                    "This device is not registered or has been deactivated. "
                    . "Please register an active device first."
                );
            }

            $deviceId = (int) $device['id'];

            $deviceMacAddress = $device['mac_address'];

            /*
            |--------------------------------------------------------------------------
            | UPDATE DEVICE LAST SEEN
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE customer_devices

                SET last_seen_at = NOW()

                WHERE id = ?
                AND user_id = ?
            ");

            $stmt->bind_param(
                "ii",
                $deviceId,
                $customerId
            );

            $stmt->execute();
            $stmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | 12. GET PACKAGE DEVICE LIMIT
        |--------------------------------------------------------------------------
        */

        $packageId = (int) $voucher['package_id'];

        $maxDevices = $defaultMaxDevices;

        $stmt = $conn->prepare("
            SELECT max_devices

            FROM package_device_limits

            WHERE package_id = ?

            LIMIT 1
        ");

        $stmt->bind_param("i", $packageId);
        $stmt->execute();

        $limitResult = $stmt->get_result();

        $limitRow = $limitResult->fetch_assoc();

        $stmt->close();

        if ($limitRow) {
            $maxDevices = (int) $limitRow['max_devices'];
        }

        if ($maxDevices < 1) {
            $maxDevices = 1;
        }

        /*
        |--------------------------------------------------------------------------
        | 13. DEVICE BINDING DISABLED
        |--------------------------------------------------------------------------
        */

        if ($deviceBindingEnabled !== 1) {
            $deviceId = null;
            $deviceMacAddress = null;
        }

        /*
        |--------------------------------------------------------------------------
        | 14. CHECK FOR AN EXISTING ACTIVE CONNECTION
        |--------------------------------------------------------------------------
        */

        if ($deviceId !== null) {

            $stmt = $conn->prepare("
                SELECT id

                FROM device_connections

                WHERE user_id = ?
                AND voucher_id = ?
                AND package_id = ?
                AND device_id = ?
                AND connection_status = 'Active'

                ORDER BY id DESC

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->bind_param(
                "iiii",
                $customerId,
                $voucher['voucher_id'],
                $packageId,
                $deviceId
            );

        } else {

            $stmt = $conn->prepare("
                SELECT id

                FROM device_connections

                WHERE user_id = ?
                AND voucher_id = ?
                AND package_id = ?
                AND device_id IS NULL
                AND connection_status = 'Active'

                ORDER BY id DESC

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->bind_param(
                "iii",
                $customerId,
                $voucher['voucher_id'],
                $packageId
            );
        }

        $stmt->execute();

        $connectionResult = $stmt->get_result();

        $existingConnection = $connectionResult->fetch_assoc();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | 15. REUSE OR CREATE CONNECTION
        |--------------------------------------------------------------------------
        */

        if ($existingConnection) {

            /*
            |--------------------------------------------------------------------------
            | REUSE EXISTING ACTIVE CONNECTION
            |--------------------------------------------------------------------------
            */

            $connectionId = (int) $existingConnection['id'];

        } else {

            /*
            |--------------------------------------------------------------------------
            | 16. COUNT ACTIVE DEVICES
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT COUNT(
                    DISTINCT COALESCE(device_id, 0)
                ) AS active_devices

                FROM device_connections

                WHERE user_id = ?
                AND package_id = ?
                AND connection_status = 'Active'
            ");

            $stmt->bind_param(
                "ii",
                $customerId,
                $packageId
            );

            $stmt->execute();

            $countResult = $stmt->get_result();

            $countRow = $countResult->fetch_assoc();

            $stmt->close();

            $activeDevices = (int) $countRow['active_devices'];

            /*
            |--------------------------------------------------------------------------
            | 17. ENFORCE DEVICE LIMIT
            |--------------------------------------------------------------------------
            */

            if ($activeDevices >= $maxDevices) {

                throw new Exception(
                    "Your package allows a maximum of "
                    . $maxDevices
                    . " active device(s). "
                    . "Disconnect another device before connecting."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 18. CREATE SIMULATED CONNECTION
            |--------------------------------------------------------------------------
            */

            $sessionId = bin2hex(random_bytes(24));

            $connectionMode = 'Simulation';

            $connectionStatus = 'Active';

            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

            $stmt = $conn->prepare("
                INSERT INTO device_connections (

                    user_id,
                    voucher_id,
                    package_id,
                    device_id,
                    session_id,
                    connection_mode,
                    connection_status,
                    ip_address,
                    mac_address,
                    started_at,
                    last_seen_at,
                    created_at

                ) VALUES (

                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW(),
                    NOW()

                )
            ");

            $stmt->bind_param(
                "iiiisssss",
                $customerId,
                $voucher['voucher_id'],
                $packageId,
                $deviceId,
                $sessionId,
                $connectionMode,
                $connectionStatus,
                $ipAddress,
                $deviceMacAddress
            );

            $stmt->execute();

            $connectionId = (int) $stmt->insert_id;

            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | 19. RECORD CONNECTION EVENT
            |--------------------------------------------------------------------------
            */

            $eventType = 'Connected';

            $eventDescription =
                'Customer connected successfully using voucher '
                . $voucher['voucher_code']
                . ' in Simulation mode.';

            $stmt = $conn->prepare("
                INSERT INTO roaming_events (

                    connection_id,
                    device_id,
                    event_type,
                    event_description,
                    occurred_at

                ) VALUES (

                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()

                )
            ");

            $stmt->bind_param(
                "iiss",
                $connectionId,
                $deviceId,
                $eventType,
                $eventDescription
            );

            $stmt->execute();
            $stmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | 20. COMMIT DATABASE TRANSACTION
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        /*
        |--------------------------------------------------------------------------
        | 21. CREATE HOTSPOT SESSION
        |--------------------------------------------------------------------------
        */

        session_regenerate_id(true);

        $_SESSION['customer_id'] = $customerId;

        $_SESSION['hotspot_voucher_id'] =
            (int) $voucher['voucher_id'];

        $_SESSION['hotspot_connection_id'] =
            $connectionId;

        $_SESSION['hotspot_device_id'] =
            $deviceId;

        /*
        |--------------------------------------------------------------------------
        | 22. CLEAR PENDING AUTHENTICATION DATA
        |--------------------------------------------------------------------------
        */

        unset(
            $_SESSION['pending_hotspot_voucher'],
            $_SESSION['pending_hotspot_device_token'],
            $_SESSION['device_auth_csrf'],
            $_SESSION['hotspot_auth_error']
        );

        /*
        |--------------------------------------------------------------------------
        | 23. REDIRECT TO CONNECTION STATUS
        |--------------------------------------------------------------------------
        */

        header("Location: device_status.php");
        exit();

    } catch (Throwable $e) {

        /*
        |--------------------------------------------------------------------------
        | ROLLBACK IF TRANSACTION IS ACTIVE
        |--------------------------------------------------------------------------
        */

        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // No further action required.
        }

        $errorMessage = $e->getMessage();

        /*
        |--------------------------------------------------------------------------
        | GENERATE A NEW CSRF TOKEN
        |--------------------------------------------------------------------------
        */

        $_SESSION['device_auth_csrf'] = bin2hex(random_bytes(32));

        $csrfToken = $_SESSION['device_auth_csrf'];
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>HOTNET - Device Authentication</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;

            background:
                linear-gradient(
                    rgba(4, 35, 22, 0.90),
                    rgba(5, 48, 30, 0.94)
                ),
                url('https://images.unsplash.com/photo-1448375240586-882707db888b');

            background-size: cover;
            background-position: center;
            background-attachment: fixed;

            font-family: Arial, sans-serif;
        }

        .authentication-card {
            width: 100%;
            max-width: 480px;

            padding: 35px;

            background: #ffffff;

            border-radius: 18px;

            box-shadow: 0 15px 45px rgba(0, 0, 0, 0.30);
        }

        .brand {
            text-align: center;
            margin-bottom: 25px;
        }

        .brand h1 {
            color: #146c43;
            font-weight: 800;
            letter-spacing: 2px;
            margin-bottom: 5px;
        }

        .brand p {
            color: #68756d;
            font-size: 14px;
        }

        .authentication-icon {
            width: 75px;
            height: 75px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin: 0 auto 18px;

            background: #e5f5eb;
            color: #146c43;

            border-radius: 18px;

            font-size: 35px;
        }

        .form-label {
            font-weight: 600;
            color: #254b37;
        }

        .form-control {
            padding: 13px;
            border-radius: 9px;
        }

        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.15);
        }

        .connect-button {
            width: 100%;
            padding: 13px;

            border: none;
            border-radius: 9px;

            background: #146c43;
            color: white;

            font-weight: bold;
            font-size: 16px;

            transition: 0.3s;
        }

        .connect-button:hover {
            background: #0d5131;
        }

        .security-note {
            margin-top: 22px;
            padding: 13px;

            background: #edf8f1;
            border-left: 4px solid #198754;

            border-radius: 7px;

            color: #315d43;
            font-size: 13px;
        }

        .footer {
            margin-top: 25px;
            text-align: center;
            color: #7b887f;
            font-size: 12px;
        }

    </style>

</head>

<body>

    <div class="authentication-card">

        <div class="brand">

            <h1>HOTNET</h1>

            <p>Reliable WiFi. Better Connectivity.</p>

        </div>

        <div class="authentication-icon">
            &#128274;
        </div>

        <h4 class="text-center mb-2">
            Device Authentication
        </h4>

        <p class="text-center text-muted mb-4">
            Verify your voucher and registered device to connect.
        </p>

        <?php if (!empty($errorMessage)): ?>

            <div class="alert alert-danger" role="alert">
                <?= e($errorMessage) ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <div class="mb-4">

                <label class="form-label">
                    WiFi Voucher Code
                </label>

                <input
                    type="text"
                    name="voucher_code"
                    class="form-control"
                    placeholder="Enter your voucher code"
                    value="<?= e($voucherCode) ?>"
                    required
                    autocomplete="off"
                >

            </div>

            <button
                type="submit"
                class="connect-button"
            >

                Connect to HOTNET

            </button>

        </form>

        <div class="security-note">

            <strong>Device Security</strong>

            <p class="mb-0 mt-1">
                HOTNET verifies registered devices before allowing
                a connection. Keep your device token private.
            </p>

        </div>

        <div class="footer">

            &copy; <?= date('Y') ?> HOTNET WiFi Billing System.

            <br>

            Secure Device Authentication

        </div>

    </div>

</body>

</html>