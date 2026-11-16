
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatBytes($bytes)
{
    $bytes = max(0, (float)$bytes);

    if ($bytes < 1024) {
        return number_format($bytes) . ' B';
    }

    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    if ($bytes < 1073741824) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    return number_format($bytes / 1073741824, 2) . ' GB';
}

/*
|--------------------------------------------------------------------------
| CUSTOMER SESSION
|--------------------------------------------------------------------------
*/

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$voucherId = (int)($_SESSION['hotspot_voucher_id'] ?? 0);
$connectionId = (int)($_SESSION['hotspot_connection_id'] ?? 0);

if ($customerId <= 0) {
    header('Location: ../customer/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF TOKENS
|--------------------------------------------------------------------------
*/

$tokens = [
    'disconnect_csrf',
    'reconnect_csrf',
    'heartbeat_csrf_token'
];

foreach ($tokens as $token) {
    if (empty($_SESSION[$token])) {
        $_SESSION[$token] = bin2hex(random_bytes(32));
    }
}

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

$flashMessage = $_SESSION['disconnect_message'] ?? '';
$flashType = $_SESSION['disconnect_message_type'] ?? 'info';

unset(
    $_SESSION['disconnect_message'],
    $_SESSION['disconnect_message_type']
);

/*
|--------------------------------------------------------------------------
| CUSTOMER INFORMATION
|--------------------------------------------------------------------------
*/

$userStmt = $conn->prepare("
    SELECT id, fullname, phone, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

$userStmt->bind_param('i', $customerId);
$userStmt->execute();

$customer = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$customer || $customer['status'] !== 'Active') {
    session_unset();
    session_destroy();

    header('Location: ../customer/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CONNECTION INFORMATION
|--------------------------------------------------------------------------
*/

$connection = null;
$device = null;
$isConnected = false;

$statusMessage = 'No active hotspot connection found.';

if ($connectionId > 0 && $voucherId > 0) {

    $stmt = $conn->prepare("
        SELECT
            dc.*,
            v.voucher_code,
            v.status AS voucher_status,
            v.expiry_date,
            p.package_name,
            p.speed,
            p.package_type,
            ap.access_point_name,
            ap.location AS access_point_location
        FROM device_connections dc
        INNER JOIN vouchers v ON v.id = dc.voucher_id
        INNER JOIN packages p ON p.id = dc.package_id
        LEFT JOIN access_points ap ON ap.id = dc.access_point_id
        WHERE dc.id = ?
          AND dc.voucher_id = ?
          AND dc.user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'iii',
        $connectionId,
        $voucherId,
        $customerId
    );

    $stmt->execute();
    $connection = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($connection) {

        /*
        |--------------------------------------------------------------------------
        | CHECK CONNECTION AND VOUCHER EXPIRY
        |--------------------------------------------------------------------------
        */

        $expiryValid = empty($connection['expiry_date'])
            || strtotime($connection['expiry_date']) > time();

        $isConnected =
            $connection['connection_status'] === 'Active'
            && $connection['voucher_status'] === 'Active'
            && $expiryValid;

        $statusMessage = $isConnected
            ? 'Your HOTNET connection is active.'
            : 'Your connection is inactive or expired.';

        /*
        |--------------------------------------------------------------------------
        | DEVICE INFORMATION
        |--------------------------------------------------------------------------
        */

        if (!empty($connection['device_id'])) {

            $connectionDeviceId = (int)$connection['device_id'];

            $deviceStmt = $conn->prepare("
                SELECT
                    id,
                    device_name,
                    device_type,
                    status,
                    first_seen_at,
                    last_seen_at
                FROM customer_devices
                WHERE id = ?
                  AND user_id = ?
                LIMIT 1
            ");

            $deviceStmt->bind_param(
                'ii',
                $connectionDeviceId,
                $customerId
            );

            $deviceStmt->execute();
            $device = $deviceStmt->get_result()->fetch_assoc();
            $deviceStmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| CONNECTION DURATION
|--------------------------------------------------------------------------
*/

$duration = 'N/A';

if ($connection && !empty($connection['started_at'])) {

    $start = strtotime($connection['started_at']);

    $end = $isConnected
        ? time()
        : strtotime(
            $connection['ended_at'] ?: $connection['last_seen_at']
        );

    if ($start && $end && $end >= $start) {

        $seconds = $end - $start;

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $duration = $hours . ' hours ' . $minutes . ' minutes';
    }
}

$bytesIn = (float)($connection['bytes_in'] ?? 0);
$bytesOut = (float)($connection['bytes_out'] ?? 0);
$totalUsage = $bytesIn + $bytesOut;

/*
|--------------------------------------------------------------------------
| DISPLAY VOUCHER EXPIRY
|--------------------------------------------------------------------------
*/

$expiryDisplay = 'No expiry date';

if ($connection && !empty($connection['expiry_date'])) {
    $expiryTimestamp = strtotime($connection['expiry_date']);

    if ($expiryTimestamp !== false) {
        $expiryDisplay = date('d M Y, h:i A', $expiryTimestamp);
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>HOTNET | Device Status</title>

<meta
    name="heartbeat-csrf"
    content="<?= h($_SESSION['heartbeat_csrf_token']) ?>"
>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    rel="stylesheet"
>

<style>

:root {
    --forest: #14532d;
    --green: #16a34a;
    --light-green: #dcfce7;
    --background: #f0fdf4;
    --text: #183b28;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: var(--background);
    color: var(--text);
}

.header {
    background: linear-gradient(120deg,#14532d,#166534,#22c55e);
    color: white;
    padding: 25px;
}

.header h3 {
    font-weight: 800;
    margin: 0;
}

.header p {
    margin: 7px 0 0;
    color: #dcfce7;
}

.container-main {
    max-width: 1100px;
    margin: auto;
    padding: 30px 18px;
}

.navigation {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 25px;
}

.navigation a {
    background: white;
    border: 1px solid #bbf7d0;
    color: var(--forest);
    text-decoration: none;
    padding: 10px 15px;
    border-radius: 9px;
    font-weight: 600;
}

.navigation a:hover {
    background: var(--forest);
    color: white;
}

.status-card {
    background: white;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 5px 18px rgba(20,83,45,.07);
    border: 1px solid #dcfce7;
    margin-bottom: 22px;
}

.status-icon {
    width: 65px;
    height: 65px;
    border-radius: 18px;
    background: var(--light-green);
    color: var(--forest);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin-bottom: 15px;
}

.status-label {
    color: #64748b;
    font-size: 13px;
    margin-bottom: 5px;
}

.status-value {
    font-size: 16px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.status-active {
    background: #dcfce7;
    color: #166534;
    padding: 8px 13px;
    border-radius: 20px;
    font-weight: 700;
    display: inline-block;
}

.status-inactive {
    background: #fee2e2;
    color: #991b1b;
    padding: 8px 13px;
    border-radius: 20px;
    font-weight: 700;
    display: inline-block;
}

.section-title {
    font-weight: 800;
    color: var(--forest);
    margin-bottom: 20px;
}

.info-item {
    padding: 14px 0;
    border-bottom: 1px solid #ecfdf5;
}

.btn-green {
    background: var(--forest);
    color: white;
    border: none;
    padding: 12px 18px;
    border-radius: 9px;
    font-weight: 700;
    text-decoration: none;
    display: inline-block;
}

.btn-green:hover {
    background: #166534;
    color: white;
}

.btn-outline-green {
    border: 1px solid var(--forest);
    color: var(--forest);
    background: white;
    padding: 11px 18px;
    border-radius: 9px;
    font-weight: 700;
}

.btn-outline-green:hover {
    background: var(--forest);
    color: white;
}

.footer {
    text-align: center;
    padding: 25px;
    color: #64748b;
}

</style>

</head>

<body>

<header class="header">

    <div class="container">

        <h3>
            <i class="fa-solid fa-leaf"></i>
            HOTNET DEVICE STATUS
        </h3>

        <p>Monitor your registered device and WiFi connection.</p>

    </div>

</header>

<main class="container-main">

    <?php if ($flashMessage !== ''): ?>

        <div class="alert alert-<?= h($flashType) ?>">
            <?= h($flashMessage) ?>
        </div>

    <?php endif; ?>

    <nav class="navigation">

        <a href="../customer/dashboard.php">Dashboard</a>

        <a href="../customer/my_vouchers.php">My Vouchers</a>

        <a href="../customer/devices.php">My Devices</a>

        <a href="roaming.php">Roaming</a>

    </nav>

    <section class="status-card">

        <div class="status-icon">
            <i class="fa-solid fa-user"></i>
        </div>

        <h4 class="section-title">Customer Information</h4>

        <div class="row g-3">

            <div class="col-md-6">

                <div class="status-label">Customer Name</div>

                <div class="status-value">
                    <?= h($customer['fullname']) ?>
                </div>

            </div>

            <div class="col-md-6">

                <div class="status-label">Phone Number</div>

                <div class="status-value">
                    <?= h($customer['phone']) ?>
                </div>

            </div>

        </div>

    </section>

    <section class="status-card">

        <div class="status-icon">
            <i class="fa-solid fa-wifi"></i>
        </div>

        <h4 class="section-title">Internet Connection Status</h4>

        <div id="hotnet-connection-status">

            <?php if ($isConnected): ?>

                <span class="status-active">
                    <i class="fa-solid fa-circle-check"></i>
                    Connected
                </span>

            <?php else: ?>

                <span class="status-inactive">
                    <i class="fa-solid fa-circle-xmark"></i>
                    Disconnected
                </span>

            <?php endif; ?>

        </div>

        <p id="hotnet-connection-message" class="mt-3">
            <?= h($statusMessage) ?>
        </p>

        <?php if ($isConnected): ?>

            <form
                method="POST"
                action="disconnect.php"
                onsubmit="return confirm('Are you sure you want to disconnect?');"
                class="mt-3"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['disconnect_csrf']) ?>"
                >

                <button type="submit" class="btn-outline-green">
                    <i class="fa-solid fa-power-off"></i>
                    Disconnect
                </button>

            </form>

        <?php elseif ($voucherId > 0): ?>

            <form method="POST" action="auto_reconnect.php" class="mt-3">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['reconnect_csrf']) ?>"
                >

                <button type="submit" class="btn-green">
                    <i class="fa-solid fa-rotate"></i>
                    Reconnect
                </button>

            </form>

        <?php endif; ?>

    </section>

    <section class="status-card">

        <div class="status-icon">
            <i class="fa-solid fa-network-wired"></i>
        </div>

        <h4 class="section-title">Connection Details</h4>

        <?php if ($connection): ?>

            <div class="row g-3">

                <?php
                $details = [
                    'Package' => $connection['package_name'],
                    'Internet Speed' => $connection['speed'],
                    'Voucher Code' => $connection['voucher_code'],
                    'Voucher Expiry' => $expiryDisplay,
                    'IP Address' => $connection['ip_address'] ?: 'Not assigned',
                    'Connection Mode' => $connection['connection_mode'],
                    'Access Point' => $connection['access_point_name'] ?: 'Not assigned',
                    'Access Point Location' => $connection['access_point_location'] ?: 'Not available',
                    'Connection Started' => !empty($connection['started_at'])
                        ? date('d M Y, h:i A', strtotime($connection['started_at']))
                        : 'Not available',
                    'Connection Duration' => $duration
                ];
                ?>

                <?php foreach ($details as $label => $value): ?>

                    <div class="col-md-6">

                        <div class="info-item">

                            <div class="status-label">
                                <?= h($label) ?>
                            </div>

                            <div class="status-value">
                                <?= h($value) ?>
                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <p class="text-muted">
                No connection details are currently available.
            </p>

        <?php endif; ?>

    </section>

    <section class="status-card">

        <div class="status-icon">
            <i class="fa-solid fa-mobile-screen-button"></i>
        </div>

        <h4 class="section-title">Registered Device</h4>

        <?php if ($device): ?>

            <div class="row g-3">

                <div class="col-md-6">
                    <div class="status-label">Device Name</div>
                    <div class="status-value"><?= h($device['device_name']) ?></div>
                </div>

                <div class="col-md-6">
                    <div class="status-label">Device Type</div>
                    <div class="status-value"><?= h($device['device_type']) ?></div>
                </div>

                <div class="col-md-6">
                    <div class="status-label">Device Status</div>
                    <div class="status-value"><?= h($device['status']) ?></div>
                </div>

                <div class="col-md-6">
                    <div class="status-label">Last Seen</div>
                    <div class="status-value">
                        <?= h($device['last_seen_at'] ?: 'Not available') ?>
                    </div>
                </div>

            </div>

        <?php else: ?>

            <p class="text-muted">
                No registered device is associated with this connection.
            </p>

            <a href="../customer/devices.php" class="btn-green">
                Register Device
            </a>

        <?php endif; ?>

    </section>

    <section class="status-card">

        <div class="status-icon">
            <i class="fa-solid fa-chart-pie"></i>
        </div>

        <h4 class="section-title">Data Usage</h4>

        <div class="row g-3">

            <div class="col-md-4">
                <div class="status-label">Data Received</div>
                <div class="status-value"><?= h(formatBytes($bytesIn)) ?></div>
            </div>

            <div class="col-md-4">
                <div class="status-label">Data Sent</div>
                <div class="status-value"><?= h(formatBytes($bytesOut)) ?></div>
            </div>

            <div class="col-md-4">
                <div class="status-label">Total Recorded Usage</div>
                <div class="status-value text-success">
                    <?= h(formatBytes($totalUsage)) ?>
                </div>
            </div>

        </div>

        <small class="text-muted d-block mt-3">
            Traffic accounting requires router integration.
            These figures may remain zero in simulation mode.
        </small>

    </section>

    <div class="footer">

        <i class="fa-solid fa-leaf text-success"></i>
        HOTNET WiFi Management System

        <br>

        <small>Secure device and connection monitoring.</small>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="heartbeat.js"></script>

</body>
</html>
