
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| CUSTOMER AUTHENTICATION
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}

$customer_id = (int) $_SESSION['customer_id'];

/*
|--------------------------------------------------------------------------
| SECURITY AND HELPERS
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| CUSTOMER DETAILS
|--------------------------------------------------------------------------
*/
$userStmt = $conn->prepare("
    SELECT fullname, username, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

$userStmt->bind_param("i", $customer_id);
$userStmt->execute();

$customer = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$customer || $customer['status'] !== 'Active') {
    session_destroy();
    header("Location: login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| DEVICE INFORMATION
|--------------------------------------------------------------------------
*/
$deviceStmt = $conn->prepare("
    SELECT COUNT(*) AS total_devices
    FROM customer_devices
    WHERE user_id = ?
      AND status = 'Active'
");

$deviceStmt->bind_param("i", $customer_id);
$deviceStmt->execute();

$deviceCount = (int) (
    $deviceStmt->get_result()->fetch_assoc()['total_devices'] ?? 0
);

$deviceStmt->close();

/*
|--------------------------------------------------------------------------
| LOAD CUSTOMER VOUCHERS
|--------------------------------------------------------------------------
| Voucher status values:
| Inactive, Active, Used, Expired
|--------------------------------------------------------------------------
*/
$voucherSql = "
    SELECT
        v.id,
        v.voucher_code,
        v.status,
        v.created_at,
        v.expiry_date,

        p.package_name,
        p.speed,
        p.package_type,
        p.duration_value,
        p.duration_days,
        p.price,

        COALESCE(pdl.max_devices, 1) AS max_devices,

        (
            SELECT COUNT(*)
            FROM device_connections dc
            WHERE dc.voucher_id = v.id
              AND dc.connection_status = 'Active'
        ) AS active_connections,

        (
            SELECT dc.connection_status
            FROM device_connections dc
            WHERE dc.voucher_id = v.id
            ORDER BY dc.id DESC
            LIMIT 1
        ) AS latest_connection_status

    FROM vouchers v

    LEFT JOIN packages p
        ON p.id = v.package_id

    LEFT JOIN package_device_limits pdl
        ON pdl.package_id = v.package_id

    WHERE v.user_id = ?

    ORDER BY v.id DESC
";

$voucherStmt = $conn->prepare($voucherSql);
$voucherStmt->bind_param("i", $customer_id);
$voucherStmt->execute();

$result = $voucherStmt->get_result();

$vouchers = [];

while ($row = $result->fetch_assoc()) {
    $vouchers[] = $row;
}

$voucherStmt->close();

/*
|--------------------------------------------------------------------------
| VOUCHER STATISTICS
|--------------------------------------------------------------------------
*/
$totalVouchers = count($vouchers);
$activeVouchers = 0;
$inactiveVouchers = 0;
$usedVouchers = 0;
$expiredVouchers = 0;

$currentTime = time();

foreach ($vouchers as $voucher) {
    $status = $voucher['status'];

    $isExpiredByDate = (
        !empty($voucher['expiry_date']) &&
        strtotime($voucher['expiry_date']) !== false &&
        strtotime($voucher['expiry_date']) < $currentTime
    );

    if ($status === 'Expired' || ($status === 'Active' && $isExpiredByDate)) {
        $expiredVouchers++;
    } elseif ($status === 'Active') {
        $activeVouchers++;
    } elseif ($status === 'Inactive') {
        $inactiveVouchers++;
    } elseif ($status === 'Used') {
        $usedVouchers++;
    }
}

/*
|--------------------------------------------------------------------------
| VOUCHER DISPLAY STATUS
|--------------------------------------------------------------------------
*/
function displayVoucherStatus(array $voucher): string {
    $status = $voucher['status'];

    if (
        $status === 'Active' &&
        !empty($voucher['expiry_date'])
    ) {
        $expiry = strtotime($voucher['expiry_date']);

        if ($expiry !== false && $expiry < time()) {
            return 'Expired';
        }
    }

    return $status;
}

function statusClass(string $status): string {
    switch ($status) {
        case 'Active':
            return 'success';

        case 'Inactive':
            return 'warning';

        case 'Used':
            return 'secondary';

        case 'Expired':
            return 'danger';

        default:
            return 'secondary';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Vouchers | HOTNET</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --hotnet-orange: #ff6b00;
            --hotnet-dark: #172033;
            --hotnet-light: #fff7f0;
            --hotnet-border: #f0e5dc;
        }

        body {
            background: #f5f7fb;
            color: var(--hotnet-dark);
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: #fff;
            box-shadow: 0 3px 15px rgba(0,0,0,.06);
        }

        .brand {
            color: var(--hotnet-orange) !important;
            font-size: 25px;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .nav-link {
            color: #4b5563;
            font-weight: 600;
        }

        .nav-link:hover,
        .nav-link.active {
            color: var(--hotnet-orange);
        }

        .page-heading {
            font-weight: 800;
        }

        .page-subtitle {
            color: #718096;
        }

        .summary-card {
            background: #fff;
            border: 1px solid var(--hotnet-border);
            border-radius: 16px;
            padding: 22px;
            height: 100%;
            box-shadow: 0 5px 18px rgba(0,0,0,.035);
        }

        .summary-icon {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: var(--hotnet-light);
            color: var(--hotnet-orange);
            font-size: 21px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: 800;
            margin-top: 12px;
        }

        .summary-label {
            color: #718096;
            font-size: 13px;
            font-weight: 600;
        }

        .voucher-card {
            background: #fff;
            border: 1px solid var(--hotnet-border);
            border-radius: 17px;
            overflow: hidden;
            box-shadow: 0 5px 18px rgba(0,0,0,.035);
            height: 100%;
            transition: .2s ease;
        }

        .voucher-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,.08);
        }

        .voucher-top {
            background: linear-gradient(120deg, #ff6b00, #ff9b42);
            color: #fff;
            padding: 20px;
        }

        .voucher-code {
            font-size: 19px;
            font-weight: 800;
            letter-spacing: 1px;
            word-break: break-word;
        }

        .voucher-body {
            padding: 20px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 0;
            border-bottom: 1px solid #f1f1f1;
            font-size: 14px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #718096;
        }

        .detail-value {
            font-weight: 700;
            text-align: right;
        }

        .status-badge {
            border-radius: 30px;
            padding: 7px 12px;
            font-size: 11px;
            font-weight: 800;
        }

        .connection-box {
            background: #f8fafc;
            border-radius: 10px;
            padding: 12px;
            font-size: 13px;
            margin-top: 12px;
        }

        .btn-hotnet {
            background: var(--hotnet-orange);
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: 11px 17px;
            font-weight: 700;
        }

        .btn-hotnet:hover {
            background: #e85f00;
            color: #fff;
        }

        .btn-outline-hotnet {
            border: 1px solid var(--hotnet-orange);
            color: var(--hotnet-orange);
            border-radius: 9px;
            font-weight: 700;
        }

        .btn-outline-hotnet:hover {
            background: var(--hotnet-orange);
            color: #fff;
        }

        .empty-state {
            background: #fff;
            border: 1px dashed #f2b98e;
            border-radius: 18px;
            padding: 55px 20px;
            text-align: center;
        }

        .empty-icon {
            font-size: 55px;
            color: #ffb16f;
        }

        footer {
            background: #172033;
            color: #cbd5e1;
            padding: 25px 0;
            margin-top: 55px;
        }

        footer strong {
            color: #ff9b42;
        }

        @media (max-width: 576px) {
            .summary-card {
                padding: 16px;
            }

            .voucher-code {
                font-size: 16px;
            }

            .page-heading {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container">

        <a class="navbar-brand brand" href="dashboard.php">
            <i class="bi bi-wifi"></i> HOTNET
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#customerNavbar"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="customerNavbar">

            <ul class="navbar-nav ms-auto align-items-lg-center">

                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">Dashboard</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="buy_package.php">Buy Package</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="my_vouchers.php">My Vouchers</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="devices.php">My Devices</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="my_payments.php">Payments</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="profile.php">Profile</a>
                </li>

                <li class="nav-item ms-lg-2">
                    <a class="btn btn-outline-danger btn-sm px-3" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </li>

            </ul>

        </div>
    </div>
</nav>

<main class="container py-5">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>
            <h2 class="page-heading mb-1">My Vouchers</h2>

            <p class="page-subtitle mb-0">
                Welcome, <?= h($customer['fullname']) ?>.
                View and manage your HOTNET voucher information.
            </p>
        </div>

        <a href="buy_package.php" class="btn btn-hotnet">
            <i class="bi bi-plus-circle"></i> Buy Package
        </a>

    </div>

    <!-- VOUCHER STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="bi bi-ticket-perforated"></i>
                </div>

                <div class="summary-number">
                    <?= $totalVouchers ?>
                </div>

                <div class="summary-label">Total Vouchers</div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="bi bi-check-circle"></i>
                </div>

                <div class="summary-number text-success">
                    <?= $activeVouchers ?>
                </div>

                <div class="summary-label">Active Vouchers</div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <div class="summary-number text-warning">
                    <?= $inactiveVouchers ?>
                </div>

                <div class="summary-label">Inactive Vouchers</div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="bi bi-clock-history"></i>
                </div>

                <div class="summary-number text-danger">
                    <?= $expiredVouchers + $usedVouchers ?>
                </div>

                <div class="summary-label">Used / Expired</div>
            </div>
        </div>

    </div>

    <!-- DEVICE INFORMATION -->

    <div class="alert alert-info border-0 rounded-3 mb-4">
        <i class="bi bi-phone"></i>

        <strong>Registered devices:</strong>
        <?= $deviceCount ?>

        <a href="devices.php" class="alert-link ms-2">
            Manage devices
        </a>

        <div class="small mt-1">
            Device limits depend on your purchased package.
        </div>
    </div>

    <!-- VOUCHER LIST -->

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Your Voucher List</h4>

        <span class="text-muted small">
            <?= $totalVouchers ?> voucher(s)
        </span>
    </div>

    <?php if (empty($vouchers)): ?>

        <div class="empty-state">

            <div class="empty-icon">
                <i class="bi bi-ticket-perforated"></i>
            </div>

            <h4 class="fw-bold mt-3">No Vouchers Yet</h4>

            <p class="text-muted">
                You have not received any vouchers.
                Purchase a package to get started.
            </p>

            <a href="buy_package.php" class="btn btn-hotnet mt-2">
                <i class="bi bi-wifi"></i> Browse Packages
            </a>

        </div>

    <?php else: ?>

        <div class="row g-4">

            <?php foreach ($vouchers as $voucher): ?>

                <?php
                    $displayStatus = displayVoucherStatus($voucher);
                    $badgeClass = statusClass($displayStatus);

                    $connectionStatus = $voucher['latest_connection_status']
                        ?? 'Not Connected';

                    if ($voucher['active_connections'] > 0) {
                        $connectionStatus = 'Connected';
                    } elseif ($connectionStatus === 'Not Connected') {
                        $connectionStatus = 'Not Connected';
                    }

                    $expiryText = 'Not specified';

                    if (!empty($voucher['expiry_date'])) {
                        $expiryTimestamp = strtotime($voucher['expiry_date']);

                        if ($expiryTimestamp !== false) {
                            $expiryText = date('d M Y, h:i A', $expiryTimestamp);
                        }
                    }

                    $createdText = 'Not specified';

                    if (!empty($voucher['created_at'])) {
                        $createdTimestamp = strtotime($voucher['created_at']);

                        if ($createdTimestamp !== false) {
                            $createdText = date('d M Y', $createdTimestamp);
                        }
                    }
                ?>

                <div class="col-md-6 col-xl-4">

                    <div class="voucher-card">

                        <div class="voucher-top">

                            <div class="d-flex justify-content-between align-items-center mb-3">

                                <span class="small fw-semibold">
                                    <i class="bi bi-wifi"></i>
                                    HOTNET VOUCHER
                                </span>

                                <span class="badge bg-light text-dark status-badge">
                                    <?= h($displayStatus) ?>
                                </span>

                            </div>

                            <div class="voucher-code">
                                <?= h($voucher['voucher_code']) ?>
                            </div>

                            <div class="small mt-2 opacity-75">
                                <?= h($voucher['package_name'] ?? 'WiFi Package') ?>
                            </div>

                        </div>

                        <div class="voucher-body">

                            <div class="detail-row">
                                <span class="detail-label">Package</span>

                                <span class="detail-value">
                                    <?= h($voucher['package_name'] ?? 'N/A') ?>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Speed</span>

                                <span class="detail-value">
                                    <?= h($voucher['speed'] ?? 'N/A') ?>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Package Type</span>

                                <span class="detail-value">
                                    <?= h($voucher['package_type'] ?? 'N/A') ?>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Price</span>

                                <span class="detail-value">
                                    KSh <?= number_format((float)($voucher['price'] ?? 0), 2) ?>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Maximum Devices</span>

                                <span class="detail-value">
                                    <?= (int)$voucher['max_devices'] ?>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Purchased</span>

                                <span class="detail-value">
                                    <?= h($createdText) ?>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Expires</span>

                                <span class="detail-value">
                                    <?= h($expiryText) ?>
                                </span>
                            </div>

                            <div class="connection-box">

                                <div class="d-flex justify-content-between align-items-center">

                                    <span class="text-muted">
                                        <i class="bi bi-router"></i>
                                        Connection
                                    </span>

                                    <strong>
                                        <?= h($connectionStatus) ?>
                                    </strong>

                                </div>

                                <div class="small text-muted mt-1">
                                    This status reflects recorded HOTNET
                                    connection sessions.
                                </div>

                            </div>

                            <div class="mt-3 d-flex gap-2">

                                <button
                                    type="button"
                                    class="btn btn-outline-hotnet btn-sm flex-fill"
                                    onclick="copyVoucher('<?= h($voucher['voucher_code']) ?>')"
                                >
                                    <i class="bi bi-clipboard"></i>
                                    Copy Code
                                </button>

                                <a
                                    href="../hotspot/index.php"
                                    class="btn btn-hotnet btn-sm flex-fill"
                                >
                                    <i class="bi bi-wifi"></i>
                                    Hotspot Login
                                </a>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <div class="alert alert-warning border-0 rounded-3 mt-4">

        <i class="bi bi-info-circle"></i>

        <strong>Important:</strong>

        Voucher information is displayed from your HOTNET database.
        Opening the hotspot login page does not guarantee an internet
        connection. Actual automatic connection, roaming and router
        control require a configured hotspot service and compatible
        router hardware.

    </div>

</main>

<footer>
    <div class="container text-center">

        <div class="fw-bold mb-1">
            <strong>HOTNET</strong> WiFi Billing System
        </div>

        <div class="small">
            Customer Support: 0718347038
        </div>

        <div class="small mt-2">
            &copy; <?= date('Y') ?> HOTNET. All Rights Reserved.
        </div>

    </div>
</footer>

<script>
function copyVoucher(code) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(code)
            .then(() => alert('Voucher code copied successfully.'))
            .catch(() => fallbackCopy(code));
    } else {
        fallbackCopy(code);
    }
}

function fallbackCopy(code) {
    const field = document.createElement('textarea');

    field.value = code;
    document.body.appendChild(field);

    field.select();

    try {
        document.execCommand('copy');
        alert('Voucher code copied successfully.');
    } catch (error) {
        alert('Unable to copy automatically. Please copy: ' + code);
    }

    document.body.removeChild(field);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
