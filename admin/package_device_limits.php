<?php
session_start();

/*
|--------------------------------------------------------------------------
| HOTNET - PACKAGE DEVICE LIMITS
|--------------------------------------------------------------------------
| File: admin/package_device_limits.php
|
| Features:
| - View all WiFi packages.
| - Set maximum devices per package.
| - Allow or restrict multiple devices.
| - Save settings using existing database tables.
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['package_limits_csrf'])) {
    $_SESSION['package_limits_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['package_limits_csrf'];

$success = '';
$errors = [];

/*
|--------------------------------------------------------------------------
| DEFAULT DEVICE LIMIT
|--------------------------------------------------------------------------
*/

$defaultMaxDevices = 1;

$defaultQuery = "
    SELECT setting_value
    FROM hotspot_settings
    WHERE setting_key = 'default_max_devices'
    LIMIT 1
";

$defaultResult = $conn->query($defaultQuery);

if ($defaultResult && $defaultResult->num_rows > 0) {
    $storedDefault = (int)$defaultResult->fetch_assoc()['setting_value'];

    if ($storedDefault >= 1 && $storedDefault <= 50) {
        $defaultMaxDevices = $storedDefault;
    }
}

/*
|--------------------------------------------------------------------------
| SAVE PACKAGE DEVICE LIMIT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errors[] = 'Invalid security token. Refresh the page and try again.';

    } else {

        $packageId = filter_var(
            $_POST['package_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $maxDevices = filter_var(
            $_POST['max_devices'] ?? null,
            FILTER_VALIDATE_INT
        );

        // An unchecked checkbox is not submitted by the browser.
        $allowMultiple = isset($_POST['allow_multi_device']) ? '1' : '0';

        /*
        |--------------------------------------------------------------------------
        | VALIDATE INPUT
        |--------------------------------------------------------------------------
        */

        if ($packageId === false || $packageId < 1) {
            $errors[] = 'Invalid package selected.';
        }

        if ($maxDevices === false || $maxDevices < 1 || $maxDevices > 50) {
            $errors[] = 'Maximum devices must be between 1 and 50.';
        }

        if ($allowMultiple === '1' && $maxDevices !== false && $maxDevices < 2) {
            $errors[] = 'Set the maximum devices to at least 2 to allow multiple devices.';
        }

        /*
        |--------------------------------------------------------------------------
        | CONFIRM PACKAGE EXISTS
        |--------------------------------------------------------------------------
        */

        if (empty($errors)) {

            $packageCheck = $conn->prepare("
                SELECT id
                FROM packages
                WHERE id = ?
                LIMIT 1
            ");

            if (!$packageCheck) {
                $errors[] = 'Could not validate the selected package.';

            } else {

                $packageCheck->bind_param('i', $packageId);
                $packageCheck->execute();

                $packageExists = $packageCheck->get_result()->num_rows > 0;

                $packageCheck->close();

                if (!$packageExists) {
                    $errors[] = 'The selected package does not exist.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SAVE PACKAGE LIMIT
        |--------------------------------------------------------------------------
        */

        if (empty($errors)) {

            try {

                $conn->begin_transaction();

                $saveQuery = "
                    INSERT INTO package_device_limits
                        (
                            package_id,
                            max_devices,
                            allow_multi_device,
                            created_at,
                            updated_at
                        )
                    VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)

                    ON DUPLICATE KEY UPDATE
                        max_devices = VALUES(max_devices),
                        allow_multi_device = VALUES(allow_multi_device),
                        updated_at = CURRENT_TIMESTAMP
                ";

                $saveStmt = $conn->prepare($saveQuery);

                if (!$saveStmt) {
                    throw new Exception('Could not prepare the save query.');
                }

                $saveStmt->bind_param(
                    'iis',
                    $packageId,
                    $maxDevices,
                    $allowMultiple
                );

                if (!$saveStmt->execute()) {
                    throw new Exception('Could not save package device limits.');
                }

                $saveStmt->close();

                $conn->commit();

                $success = 'Package device limits updated successfully.';

                // Refresh security token after successful update.
                $_SESSION['package_limits_csrf'] = bin2hex(random_bytes(32));
                $csrfToken = $_SESSION['package_limits_csrf'];

            } catch (Throwable $e) {

                $conn->rollback();

                $errors[] = 'The package settings could not be saved. Please check the database.';

                error_log('HOTNET package limits error: ' . $e->getMessage());
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD PACKAGE DEVICE LIMITS
|--------------------------------------------------------------------------
*/

$packages = [];

$packageQuery = "
    SELECT
        p.id,
        p.package_name,
        p.speed,
        p.package_type,
        p.duration_value,
        p.duration_days,
        p.price,
        p.status,

        pdl.max_devices,
        pdl.allow_multi_device

    FROM packages p

    LEFT JOIN package_device_limits pdl
        ON p.id = pdl.package_id

    ORDER BY p.id DESC
";

$packageResult = $conn->query($packageQuery);

if ($packageResult) {

    while ($row = $packageResult->fetch_assoc()) {

        if ($row['max_devices'] === null) {
            $row['max_devices'] = $defaultMaxDevices;
        }

        if ($row['allow_multi_device'] === null) {
            $row['allow_multi_device'] = 0;
        }

        $packages[] = $row;
    }

} else {

    $errors[] = 'Could not load packages from the database.';
}

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$totalPackages = count($packages);
$multiplePackages = 0;
$singlePackages = 0;
$inactivePackages = 0;

foreach ($packages as $package) {

    if ((int)$package['allow_multi_device'] === 1) {
        $multiplePackages++;
    } else {
        $singlePackages++;
    }

    if ($package['status'] !== 'Active') {
        $inactivePackages++;
    }
}

$conn->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Package Device Limits | HOTNET</title>

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
            --forest: #14532d;
            --green: #198754;
            --leaf: #65a30d;
            --light-green: #ecfdf3;
            --dark: #183326;
        }

        body {
            background: #f2f8f2;
            color: #263b30;
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: linear-gradient(
                120deg,
                #14532d,
                #198754,
                #4d7c0f
            );

            box-shadow: 0 4px 15px rgba(20, 83, 45, .18);
        }

        .navbar-brand {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .navbar .nav-link {
            color: white !important;
            font-weight: 600;
            margin-left: 8px;
        }

        .navbar .nav-link:hover {
            color: #d9f99d !important;
        }

        .page-header {
            background:
                linear-gradient(
                    120deg,
                    rgba(20, 83, 45, .95),
                    rgba(25, 135, 84, .88)
                ),
                url('https://images.unsplash.com/photo-1448375240586-882707db888b?auto=format&fit=crop&w=1600&q=80');

            background-size: cover;
            background-position: center;

            color: white;
            padding: 35px;
            border-radius: 18px;
            margin-bottom: 25px;
        }

        .page-header h2 {
            font-weight: 800;
        }

        .stat-card {
            background: white;
            border: none;
            border-radius: 16px;
            padding: 22px;
            height: 100%;
            box-shadow: 0 5px 18px rgba(0, 0, 0, .045);
            transition: .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
            margin-bottom: 13px;
        }

        .green-icon {
            background: #dcfce7;
            color: #15803d;
        }

        .blue-icon {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .orange-icon {
            background: #ffedd5;
            color: #c2410c;
        }

        .purple-icon {
            background: #ede9fe;
            color: #6d28d9;
        }

        .stat-card h3 {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
            margin: 0;
        }

        .stat-card p {
            color: #718078;
            margin: 5px 0 0;
        }

        .main-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 5px 18px rgba(0, 0, 0, .045);
            margin-bottom: 25px;
        }

        .section-title {
            color: var(--forest);
            font-weight: 800;
        }

        .package-card {
            background: #fbfefb;
            border: 1px solid #e2eee5;
            border-radius: 14px;
            padding: 20px;
            height: 100%;
        }

        .package-name {
            color: var(--forest);
            font-size: 18px;
            font-weight: 800;
        }

        .package-meta {
            color: #718078;
            font-size: 13px;
        }

        .price {
            font-size: 21px;
            font-weight: 800;
            color: #198754;
        }

        .form-label {
            color: #315440;
            font-weight: 700;
        }

        .form-control {
            border-radius: 10px;
            padding: 11px;
            border-color: #dce9df;
        }

        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 .2rem rgba(25, 135, 84, .12);
        }

        .form-check-input {
            width: 42px;
            height: 22px;
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: #198754;
            border-color: #198754;
        }

        .btn-green {
            background: linear-gradient(120deg, #166534, #198754);
            color: white;
            border: none;
            border-radius: 9px;
            padding: 10px 16px;
            font-weight: 700;
        }

        .btn-green:hover {
            background: #14532d;
            color: white;
        }

        .status-badge {
            padding: 6px 11px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 800;
        }

        .info-panel {
            background: #eff6ff;
            border: 1px solid #dbeafe;
            color: #1e40af;
            padding: 16px;
            border-radius: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 45px 15px;
            color: #718078;
        }

        .empty-state i {
            font-size: 45px;
            color: #86b99a;
        }

        footer {
            background: #14532d;
            color: white;
            padding: 22px;
            margin-top: 35px;
            text-align: center;
        }

        @media(max-width: 768px) {

            .page-header {
                padding: 25px 18px;
            }

            .main-card {
                padding: 16px;
            }

        }

    </style>

</head>

<body>

<!-- NAVIGATION -->

<nav class="navbar navbar-expand-lg navbar-dark">

    <div class="container-fluid px-lg-4">

        <a class="navbar-brand" href="dashboard.php">
            <i class="bi bi-tree-fill"></i> HOTNET
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mainNavbar"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">

            <ul class="navbar-nav ms-auto">

                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="packages.php">
                        <i class="bi bi-box"></i> Packages
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="vouchers.php">
                        <i class="bi bi-ticket-perforated"></i> Vouchers
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="router_management.php">
                        <i class="bi bi-router"></i> Routers
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="hotspot_settings.php">
                        <i class="bi bi-gear"></i> Settings
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="package_device_limits.php">
                        <i class="bi bi-phone"></i> Device Limits
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="../auth/logout.php">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </li>

            </ul>

        </div>

    </div>

</nav>


<main class="container-fluid px-lg-4 py-4">

    <!-- PAGE HEADER -->

    <section class="page-header">

        <div class="row align-items-center">

            <div class="col-lg-8">

                <h2>
                    <i class="bi bi-phone"></i>
                    Package Device Limits
                </h2>

                <p class="mb-0">
                    Control how many phones, laptops, tablets and smart TVs
                    can use each HOTNET WiFi package.
                </p>

            </div>

            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">

                <span class="badge bg-light text-success p-2">
                    <i class="bi bi-shield-check"></i>
                    Device Access Control
                </span>

            </div>

        </div>

    </section>


    <!-- ALERTS -->

    <?php if ($success !== ''): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="bi bi-check-circle-fill"></i>

            <?= h($success) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if (!empty($errors)): ?>

        <div class="alert alert-danger">

            <strong>
                <i class="bi bi-exclamation-triangle-fill"></i>
                Please check the following:
            </strong>

            <ul class="mb-0 mt-2">

                <?php foreach ($errors as $error): ?>

                    <li><?= h($error) ?></li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <!-- STATISTICS -->

    <section class="row g-3 mb-4">

        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon green-icon">
                    <i class="bi bi-box-seam"></i>
                </div>

                <h3><?= number_format($totalPackages) ?></h3>

                <p>Total Packages</p>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon blue-icon">
                    <i class="bi bi-phone"></i>
                </div>

                <h3><?= number_format($singlePackages) ?></h3>

                <p>Single-Device Packages</p>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon purple-icon">
                    <i class="bi bi-phone-fill"></i>
                </div>

                <h3><?= number_format($multiplePackages) ?></h3>

                <p>Multiple-Device Packages</p>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon orange-icon">
                    <i class="bi bi-pause-circle"></i>
                </div>

                <h3><?= number_format($inactivePackages) ?></h3>

                <p>Inactive Packages</p>

            </div>

        </div>

    </section>


    <!-- INFORMATION -->

    <div class="info-panel mb-4">

        <i class="bi bi-info-circle-fill"></i>

        <strong>Important:</strong>

        Each package can have its own device limit. For example, a package
        can allow one phone while another package allows a phone, laptop
        and tablet.

        Device limits are stored separately from your package information.

    </div>


    <!-- PACKAGE LIST -->

    <section class="main-card">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

            <div>

                <h5 class="section-title mb-1">
                    <i class="bi bi-list-check"></i>
                    Configure Package Devices
                </h5>

                <p class="text-muted small mb-0">
                    Save each package's device settings separately.
                </p>

            </div>

            <a href="packages.php" class="btn btn-outline-success">

                <i class="bi bi-box"></i>
                Manage Packages

            </a>

        </div>


        <?php if (!empty($packages)): ?>

            <div class="row g-4">

                <?php foreach ($packages as $package): ?>

                    <div class="col-12 col-md-6 col-xl-4">

                        <div class="package-card">

                            <!-- PACKAGE DETAILS -->

                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">

                                <div>

                                    <div class="package-name">
                                        <?= h($package['package_name']) ?>
                                    </div>

                                    <div class="package-meta">
                                        <?= h($package['speed']) ?> Mbps
                                    </div>

                                </div>

                                <?php if ($package['status'] === 'Active'): ?>

                                    <span class="badge bg-success status-badge">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary status-badge">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </div>


                            <div class="price mb-1">

                                KSh <?= number_format((float)$package['price'], 2) ?>

                            </div>

                            <div class="package-meta mb-3">

                                <?= h($package['package_type']) ?> Package

                                <?php if (!empty($package['duration_value'])): ?>

                                    · <?= h($package['duration_value']) ?>

                                <?php endif; ?>

                            </div>


                            <hr>


                            <!-- DEVICE LIMIT FORM -->

                            <form method="POST" action="package_device_limits.php">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= h($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="package_id"
                                    value="<?= (int)$package['id'] ?>"
                                >


                                <div class="mb-3">

                                    <label class="form-label">

                                        Maximum Allowed Devices

                                    </label>

                                    <input
                                        type="number"
                                        name="max_devices"
                                        class="form-control"
                                        min="1"
                                        max="50"
                                        value="<?= (int)$package['max_devices'] ?>"
                                        required
                                    >

                                    <small class="text-muted">
                                        Between 1 and 50 devices.
                                    </small>

                                </div>


                                <div class="form-check form-switch mb-3">

                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        name="allow_multi_device"
                                        id="multi_<?= (int)$package['id'] ?>"
                                        value="1"
                                        <?= (int)$package['allow_multi_device'] === 1 ? 'checked' : '' ?>
                                    >

                                    <label
                                        class="form-check-label fw-bold"
                                        for="multi_<?= (int)$package['id'] ?>"
                                    >
                                        Allow Multiple Devices
                                    </label>

                                </div>


                                <div class="small text-muted mb-3">

                                    <?php if ((int)$package['allow_multi_device'] === 1): ?>

                                        <i class="bi bi-check-circle-fill text-success"></i>

                                        Multiple-device access is enabled.

                                    <?php else: ?>

                                        <i class="bi bi-lock-fill text-secondary"></i>

                                        Multiple-device access is disabled.

                                    <?php endif; ?>

                                </div>


                                <button type="submit" class="btn btn-green w-100">

                                    <i class="bi bi-save"></i>
                                    Save Device Limit

                                </button>

                            </form>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <i class="bi bi-box-seam"></i>

                <h5 class="mt-3">No packages found</h5>

                <p>
                    Create a WiFi package before configuring its device limit.
                </p>

                <a href="packages.php" class="btn btn-green">
                    <i class="bi bi-plus-circle"></i>
                    Create Package
                </a>

            </div>

        <?php endif; ?>

    </section>


    <!-- DEVICE INFORMATION -->

    <section class="main-card">

        <h5 class="section-title mb-3">

            <i class="bi bi-lightbulb"></i>

            How Package Device Limits Work

        </h5>

        <div class="row g-3">

            <div class="col-md-4">

                <div class="p-3 rounded-3 bg-light h-100">

                    <i class="bi bi-phone fs-3 text-success"></i>

                    <h6 class="fw-bold mt-2">
                        Single Device
                    </h6>

                    <p class="small text-muted mb-0">
                        A package configured for one device is intended
                        for one registered customer device.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="p-3 rounded-3 bg-light h-100">

                    <i class="bi bi-laptop fs-3 text-success"></i>

                    <h6 class="fw-bold mt-2">
                        Multiple Devices
                    </h6>

                    <p class="small text-muted mb-0">
                        A package can permit several registered devices,
                        depending on its configured limit.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="p-3 rounded-3 bg-light h-100">

                    <i class="bi bi-shield-check fs-3 text-success"></i>

                    <h6 class="fw-bold mt-2">
                        Device Security
                    </h6>

                    <p class="small text-muted mb-0">
                        Registered device tokens can help HOTNET identify
                        customer devices during future hotspot authentication.
                    </p>

                </div>

            </div>

        </div>

        <div class="alert alert-warning mt-4 mb-0">

            <i class="bi bi-exclamation-triangle-fill"></i>

            <strong>Hardware note:</strong>

            Saving these limits configures the database. Enforcing them
            during actual WiFi connections requires the hotspot
            authentication service and router integration.

        </div>

    </section>

</main>


<footer>

    <strong>HOTNET WiFi Management System</strong>

    <div class="small mt-2">
        Green Technology | Smart Connectivity | Customer Management
    </div>

    <div class="small mt-1">
        Support: 0718347038
    </div>

</footer>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>