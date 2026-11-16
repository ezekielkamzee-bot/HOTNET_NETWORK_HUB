<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| HOTNET AUTOMATIC RECONNECTION
|--------------------------------------------------------------------------
| Reconnects a customer using an existing active voucher.
| This version creates simulated connections because no MikroTik router
| is currently connected to the project.
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getReconnectSetting(mysqli $conn, string $key, string $default = ''): string
{
    $stmt = $conn->prepare(
        "SELECT setting_value
         FROM hotspot_settings
         WHERE setting_key = ?
         LIMIT 1"
    );

    $stmt->bind_param("s", $key);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return $row ? (string)$row['setting_value'] : $default;
}

/*
|--------------------------------------------------------------------------
| REQUIRE CUSTOMER LOGIN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['customer_id'])) {
    header("Location: ../customer/login.php");
    exit();
}

$customerId = (int)$_SESSION['customer_id'];

$voucherId = (int)($_SESSION['hotspot_voucher_id'] ?? 0);

$deviceId = $_SESSION['hotspot_device_id'] ?? null;

if ($deviceId !== null) {
    $deviceId = (int)$deviceId;

    if ($deviceId <= 0) {
        $deviceId = null;
    }
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['reconnect_csrf'])) {
    $_SESSION['reconnect_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['reconnect_csrf'];

$error = '';
$success = false;

$connectionId = 0;

/*
|--------------------------------------------------------------------------
| RECONNECTION REQUEST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['reconnect_csrf'], $submittedToken)
    ) {
        $error = "Your reconnection request has expired. Please try again.";

    } elseif ($voucherId <= 0) {

        $error = "No WiFi voucher is available for reconnection.";

    } else {

        try {

            $conn->begin_transaction();

            /*
            |--------------------------------------------------------------------------
            | LOAD VOUCHER AND VERIFY CUSTOMER OWNERSHIP
            |--------------------------------------------------------------------------
            */

            $voucherStmt = $conn->prepare(
                "SELECT
                    v.id AS voucher_id,
                    v.user_id,
                    v.package_id,
                    v.status AS voucher_status,
                    v.expiry_date,

                    u.status AS user_status,

                    p.status AS package_status,
                    p.package_name

                 FROM vouchers v

                 INNER JOIN users u
                    ON u.id = v.user_id

                 INNER JOIN packages p
                    ON p.id = v.package_id

                 WHERE v.id = ?
                 AND v.user_id = ?

                 LIMIT 1
                 FOR UPDATE"
            );

            $voucherStmt->bind_param(
                "ii",
                $voucherId,
                $customerId
            );

            $voucherStmt->execute();

            $voucher = $voucherStmt
                ->get_result()
                ->fetch_assoc();

            $voucherStmt->close();

            if (!$voucher) {
                throw new Exception(
                    "This voucher does not belong to your customer account."
                );
            }

            if ($voucher['voucher_status'] !== 'Active') {
                throw new Exception("Your voucher is not active.");
            }

            if (
                empty($voucher['expiry_date']) ||
                strtotime($voucher['expiry_date']) <= time()
            ) {
                throw new Exception("Your WiFi voucher has expired.");
            }

            if ($voucher['user_status'] !== 'Active') {
                throw new Exception("Your customer account is inactive.");
            }

            if ($voucher['package_status'] !== 'Active') {
                throw new Exception("Your WiFi package is inactive.");
            }

            $packageId = (int)$voucher['package_id'];

            /*
            |--------------------------------------------------------------------------
            | READ DEVICE BINDING SETTING
            |--------------------------------------------------------------------------
            */

            $bindingEnabled = (
                getReconnectSetting(
                    $conn,
                    'device_binding_enabled',
                    '1'
                ) === '1'
            );

            $defaultMaxDevices = max(
                1,
                (int)getReconnectSetting(
                    $conn,
                    'default_max_devices',
                    '1'
                )
            );

            /*
            |--------------------------------------------------------------------------
            | VERIFY REGISTERED DEVICE
            |--------------------------------------------------------------------------
            */

            if ($bindingEnabled) {

                if ($deviceId === null) {
                    throw new Exception(
                        "Your registered device could not be identified. Please reconnect from the original device."
                    );
                }

                $deviceStmt = $conn->prepare(
                    "SELECT id, status
                     FROM customer_devices
                     WHERE id = ?
                     AND user_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                $deviceStmt->bind_param(
                    "ii",
                    $deviceId,
                    $customerId
                );

                $deviceStmt->execute();

                $device = $deviceStmt
                    ->get_result()
                    ->fetch_assoc();

                $deviceStmt->close();

                if (!$device) {
                    throw new Exception(
                        "The registered device was not found."
                    );
                }

                if ($device['status'] !== 'Active') {
                    throw new Exception(
                        "This device is inactive or blocked."
                    );
                }

                $updateDevice = $conn->prepare(
                    "UPDATE customer_devices
                     SET last_seen_at = NOW()
                     WHERE id = ?
                     AND user_id = ?"
                );

                $updateDevice->bind_param(
                    "ii",
                    $deviceId,
                    $customerId
                );

                $updateDevice->execute();
                $updateDevice->close();

            } else {

                $deviceId = null;
            }

            /*
            |--------------------------------------------------------------------------
            | GET PACKAGE DEVICE LIMIT
            |--------------------------------------------------------------------------
            */

            $limitStmt = $conn->prepare(
                "SELECT max_devices
                 FROM package_device_limits
                 WHERE package_id = ?
                 LIMIT 1"
            );

            $limitStmt->bind_param("i", $packageId);
            $limitStmt->execute();

            $limitRow = $limitStmt
                ->get_result()
                ->fetch_assoc();

            $limitStmt->close();

            $maxDevices = $limitRow
                ? max(1, (int)$limitRow['max_devices'])
                : $defaultMaxDevices;

            if (!$bindingEnabled) {
                $maxDevices = 1;
            }

            /*
            |--------------------------------------------------------------------------
            | CHECK IF THIS DEVICE ALREADY HAS AN ACTIVE CONNECTION
            |--------------------------------------------------------------------------
            */

            if ($deviceId !== null) {

                $existingStmt = $conn->prepare(
                    "SELECT id
                     FROM device_connections
                     WHERE user_id = ?
                     AND voucher_id = ?
                     AND device_id = ?
                     AND connection_status = 'Active'
                     LIMIT 1"
                );

                $existingStmt->bind_param(
                    "iii",
                    $customerId,
                    $voucherId,
                    $deviceId
                );

            } else {

                $existingStmt = $conn->prepare(
                    "SELECT id
                     FROM device_connections
                     WHERE user_id = ?
                     AND voucher_id = ?
                     AND device_id IS NULL
                     AND connection_status = 'Active'
                     LIMIT 1"
                );

                $existingStmt->bind_param(
                    "ii",
                    $customerId,
                    $voucherId
                );
            }

            $existingStmt->execute();

            $existingConnection = $existingStmt
                ->get_result()
                ->fetch_assoc();

            $existingStmt->close();

            if ($existingConnection) {

                $connectionId = (int)$existingConnection['id'];

                $conn->commit();

                $success = true;

            } else {

                /*
                |--------------------------------------------------------------------------
                | COUNT ACTIVE DEVICES FOR THIS CUSTOMER AND PACKAGE
                |--------------------------------------------------------------------------
                */

                $countStmt = $conn->prepare(
                    "SELECT COUNT(DISTINCT COALESCE(device_id, 0)) AS total_devices
                     FROM device_connections
                     WHERE user_id = ?
                     AND package_id = ?
                     AND connection_status = 'Active'"
                );

                $countStmt->bind_param(
                    "ii",
                    $customerId,
                    $packageId
                );

                $countStmt->execute();

                $countRow = $countStmt
                    ->get_result()
                    ->fetch_assoc();

                $countStmt->close();

                $activeDevices = (int)$countRow['total_devices'];

                /*
                |--------------------------------------------------------------------------
                | CHECK WHETHER THE SAME DEVICE IS ACTIVE ON ANOTHER VOUCHER
                |--------------------------------------------------------------------------
                */

                if ($deviceId !== null) {

                    $sameDeviceStmt = $conn->prepare(
                        "SELECT id
                         FROM device_connections
                         WHERE user_id = ?
                         AND package_id = ?
                         AND device_id = ?
                         AND connection_status = 'Active'
                         LIMIT 1"
                    );

                    $sameDeviceStmt->bind_param(
                        "iii",
                        $customerId,
                        $packageId,
                        $deviceId
                    );

                } else {

                    $sameDeviceStmt = $conn->prepare(
                        "SELECT id
                         FROM device_connections
                         WHERE user_id = ?
                         AND package_id = ?
                         AND device_id IS NULL
                         AND connection_status = 'Active'
                         LIMIT 1"
                    );

                    $sameDeviceStmt->bind_param(
                        "ii",
                        $customerId,
                        $packageId
                    );
                }

                $sameDeviceStmt->execute();

                $sameDeviceActive = (
                    $sameDeviceStmt->get_result()->num_rows > 0
                );

                $sameDeviceStmt->close();

                if (
                    !$sameDeviceActive &&
                    $activeDevices >= $maxDevices
                ) {
                    throw new Exception(
                        "Your package has reached its maximum number of connected devices."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE NEW SIMULATED CONNECTION
                |--------------------------------------------------------------------------
                */

                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

                if ($deviceId !== null) {

                    $insertStmt = $conn->prepare(
                        "INSERT INTO device_connections (
                            user_id,
                            voucher_id,
                            package_id,
                            device_id,
                            connection_mode,
                            connection_status,
                            ip_address,
                            started_at,
                            last_seen_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            'Simulation',
                            'Active',
                            ?,
                            NOW(),
                            NOW()
                        )"
                    );

                    $insertStmt->bind_param(
                        "iiiis",
                        $customerId,
                        $voucherId,
                        $packageId,
                        $deviceId,
                        $ipAddress
                    );

                } else {

                    $insertStmt = $conn->prepare(
                        "INSERT INTO device_connections (
                            user_id,
                            voucher_id,
                            package_id,
                            device_id,
                            connection_mode,
                            connection_status,
                            ip_address,
                            started_at,
                            last_seen_at
                        ) VALUES (
                            ?, ?, ?, NULL,
                            'Simulation',
                            'Active',
                            ?,
                            NOW(),
                            NOW()
                        )"
                    );

                    $insertStmt->bind_param(
                        "iiis",
                        $customerId,
                        $voucherId,
                        $packageId,
                        $ipAddress
                    );
                }

                $insertStmt->execute();

                $connectionId = (int)$conn->insert_id;

                $insertStmt->close();

                /*
                |--------------------------------------------------------------------------
                | RECORD RECONNECTION EVENT
                |--------------------------------------------------------------------------
                */

                $eventDescription = 'Customer reconnected successfully in simulation mode.';

                if ($deviceId !== null) {

                    $eventStmt = $conn->prepare(
                        "INSERT INTO roaming_events (
                            connection_id,
                            device_id,
                            event_type,
                            event_description,
                            occurred_at
                        ) VALUES (
                            ?,
                            ?,
                            'Reconnected',
                            ?,
                            NOW()
                        )"
                    );

                    $eventStmt->bind_param(
                        "iis",
                        $connectionId,
                        $deviceId,
                        $eventDescription
                    );

                } else {

                    $eventStmt = $conn->prepare(
                        "INSERT INTO roaming_events (
                            connection_id,
                            event_type,
                            event_description,
                            occurred_at
                        ) VALUES (
                            ?,
                            'Reconnected',
                            ?,
                            NOW()
                        )"
                    );

                    $eventStmt->bind_param(
                        "is",
                        $connectionId,
                        $eventDescription
                    );
                }

                $eventStmt->execute();
                $eventStmt->close();

                $conn->commit();

                $success = true;
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE HOTSPOT SESSION
            |--------------------------------------------------------------------------
            */

            $_SESSION['customer_id'] = $customerId;
            $_SESSION['hotspot_voucher_id'] = $voucherId;
            $_SESSION['hotspot_connection_id'] = $connectionId;
            $_SESSION['hotspot_device_id'] = $deviceId;

            $_SESSION['reconnect_csrf'] = bin2hex(random_bytes(32));

            header("Location: device_status.php");
            exit();

        } catch (Throwable $exception) {

            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
                // No additional action required.
            }

            $error = $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>HOTNET Reconnection</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #052e16, #14532d, #166534);
            font-family: Arial, sans-serif;
        }

        .reconnect-card {
            width: 100%;
            max-width: 440px;
            padding: 32px;
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
            text-align: center;
        }

        .brand {
            color: #166534;
            font-size: 30px;
            font-weight: 800;
        }

        .description {
            color: #64748b;
            margin: 12px 0 25px;
        }

        .btn-hotnet {
            width: 100%;
            padding: 13px;
            color: white;
            background: #15803d;
            border: none;
            border-radius: 10px;
            font-weight: bold;
        }

        .btn-hotnet:hover {
            color: white;
            background: #166534;
        }
    </style>

</head>

<body>

<div class="reconnect-card">

    <div class="brand">HOTNET</div>

    <h4 class="mt-3">Reconnect Your Device</h4>

    <p class="description">
        Restore your connection using your existing active WiFi voucher.
    </p>

    <?php if ($error !== ''): ?>

        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <form method="POST" action="auto_reconnect.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <button type="submit" class="btn btn-hotnet">
            Reconnect Now
        </button>

    </form>

    <div class="mt-3">

        <a
            href="device_status.php"
            class="text-success text-decoration-none"
        >
            Return to Device Status
        </a>

    </div>

</div>

</body>
</html>