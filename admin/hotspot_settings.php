<?php
session_start();

/*
|--------------------------------------------------------------------------
| HOTNET - HOTSPOT SYSTEM SETTINGS
|--------------------------------------------------------------------------
| File: admin/hotspot_settings.php
|
| Manages:
| - Router operating mode
| - Device binding
| - Customer roaming permissions
| - Default maximum devices
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
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['hotspot_settings_csrf'])) {
    $_SESSION['hotspot_settings_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['hotspot_settings_csrf'];

/*
|--------------------------------------------------------------------------
| DEFAULT SETTINGS
|--------------------------------------------------------------------------
*/

$settings = [
    'router_mode' => 'Simulation',
    'device_binding_enabled' => '1',
    'allow_roaming' => '1',
    'default_max_devices' => '1'
];

$descriptions = [
    'router_mode' => 'Operating mode used by the HOTNET hotspot system.',
    'device_binding_enabled' => 'Controls whether customer devices are registered and restricted.',
    'allow_roaming' => 'Controls whether device roaming is permitted.',
    'default_max_devices' => 'Default number of devices allowed per customer.'
];

$errors = [];
$success = '';

/*
|--------------------------------------------------------------------------
| LOAD EXISTING SETTINGS
|--------------------------------------------------------------------------
*/

$loadQuery = "
    SELECT setting_key, setting_value
    FROM hotspot_settings
";

$loadResult = $conn->query($loadQuery);

if ($loadResult) {
    while ($row = $loadResult->fetch_assoc()) {
        if (array_key_exists($row['setting_key'], $settings)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| SAVE SETTINGS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    } else {

        $routerMode = $_POST['router_mode'] ?? '';
        $deviceBinding = $_POST['device_binding_enabled'] ?? '';
        $allowRoaming = $_POST['allow_roaming'] ?? '';
        $maxDevices = filter_var(
            $_POST['default_max_devices'] ?? null,
            FILTER_VALIDATE_INT
        );

        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        if (!in_array($routerMode, ['Simulation', 'MikroTik'], true)) {
            $errors[] = 'Please select a valid router mode.';
        }

        if (!in_array($deviceBinding, ['0', '1'], true)) {
            $errors[] = 'Please select a valid device binding option.';
        }

        if (!in_array($allowRoaming, ['0', '1'], true)) {
            $errors[] = 'Please select a valid roaming option.';
        }

        if ($maxDevices === false || $maxDevices < 1 || $maxDevices > 50) {
            $errors[] = 'Maximum devices must be between 1 and 50.';
        }

        /*
        |--------------------------------------------------------------------------
        | SAVE TO DATABASE
        |--------------------------------------------------------------------------
        */

        if (empty($errors)) {

            $newSettings = [
                'router_mode' => $routerMode,
                'device_binding_enabled' => $deviceBinding,
                'allow_roaming' => $allowRoaming,
                'default_max_devices' => (string)$maxDevices
            ];

            try {

                $conn->begin_transaction();

                $saveQuery = "
                    INSERT INTO hotspot_settings
                        (setting_key, setting_value, description, updated_at)
                    VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE
                        setting_value = VALUES(setting_value),
                        description = VALUES(description),
                        updated_at = CURRENT_TIMESTAMP
                ";

                $saveStmt = $conn->prepare($saveQuery);

                if (!$saveStmt) {
                    throw new Exception('Could not prepare the settings query.');
                }

                foreach ($newSettings as $key => $value) {

                    $description = $descriptions[$key];

                    $saveStmt->bind_param(
                        'sss',
                        $key,
                        $value,
                        $description
                    );

                    if (!$saveStmt->execute()) {
                        throw new Exception('Could not save setting: ' . $key);
                    }
                }

                $saveStmt->close();

                $conn->commit();

                /*
                |--------------------------------------------------------------------------
                | UPDATE DISPLAYED SETTINGS
                |--------------------------------------------------------------------------
                */

                $settings = $newSettings;

                $success = 'HOTNET settings have been saved successfully.';

                /*
                |--------------------------------------------------------------------------
                | REFRESH CSRF TOKEN
                |--------------------------------------------------------------------------
                */

                $_SESSION['hotspot_settings_csrf'] = bin2hex(random_bytes(32));
                $csrfToken = $_SESSION['hotspot_settings_csrf'];

            } catch (Throwable $e) {

                $conn->rollback();

                $errors[] = 'Settings could not be saved. Please check your database configuration.';

                error_log('HOTNET settings error: ' . $e->getMessage());
            }
        }
    }
}

$conn->close();

/*
|--------------------------------------------------------------------------
| DISPLAY VALUES
|--------------------------------------------------------------------------
*/

$deviceBindingEnabled = $settings['device_binding_enabled'] === '1';
$roamingEnabled = $settings['allow_roaming'] === '1';
$simulationMode = $settings['router_mode'] === 'Simulation';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Hotspot Settings | HOTNET</title>

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

            padding: 35px;
            border-radius: 18px;
            color: white;
            margin-bottom: 25px;
        }

        .page-header h2 {
            font-weight: 800;
        }

        .settings-card {
            background: white;
            border: none;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 22px;
            box-shadow: 0 5px 18px rgba(0, 0, 0, .045);
        }

        .settings-title {
            color: var(--forest);
            font-weight: 800;
            margin-bottom: 8px;
        }

        .settings-description {
            color: #718078;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .setting-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #dcfce7;
            color: #166534;
            border-radius: 13px;
            font-size: 22px;
            flex-shrink: 0;
        }

        .setting-row {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .form-label {
            font-weight: 700;
            color: #315440;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 12px;
            border-color: #dce9df;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #198754;
            box-shadow: 0 0 0 .2rem rgba(25, 135, 84, .12);
        }

        .form-check-input {
            width: 44px;
            height: 23px;
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: #198754;
            border-color: #198754;
        }

        .form-check-input:focus {
            box-shadow: 0 0 0 .2rem rgba(25, 135, 84, .15);
        }

        .btn-green {
            background: linear-gradient(120deg, #166534, #198754);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 22px;
            font-weight: 700;
        }

        .btn-green:hover {
            background: #14532d;
            color: white;
        }

        .status-panel {
            background: #f0fdf4;
            border: 1px solid #d1fae5;
            border-radius: 13px;
            padding: 17px;
        }

        .status-panel strong {
            color: #14532d;
        }

        .info-panel {
            background: #eff6ff;
            border: 1px solid #dbeafe;
            color: #1e40af;
            padding: 16px;
            border-radius: 12px;
        }

        .warning-panel {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            padding: 16px;
            border-radius: 12px;
        }

        .save-bar {
            position: sticky;
            bottom: 12px;
            z-index: 10;
            background: rgba(255, 255, 255, .96);
            padding: 15px;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .12);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
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

            .settings-card {
                padding: 18px;
            }

            .save-bar {
                align-items: stretch;
                flex-direction: column;
            }

            .save-bar .btn {
                width: 100%;
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
                    <a class="nav-link" href="router_management.php">
                        <i class="bi bi-router"></i> Routers
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="device_connections.php">
                        <i class="bi bi-wifi"></i> Connections
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="roaming_management.php">
                        <i class="bi bi-arrow-left-right"></i> Roaming
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="hotspot_settings.php">
                        <i class="bi bi-gear"></i> Settings
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
                    <i class="bi bi-sliders"></i>
                    Hotspot System Settings
                </h2>

                <p class="mb-0">
                    Configure HOTNET router mode, device restrictions,
                    roaming permissions and hotspot behaviour.
                </p>

            </div>

            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">

                <span class="badge bg-light text-success p-2">
                    <i class="bi bi-shield-check"></i>
                    System Configuration
                </span>

            </div>

        </div>

    </section>


    <!-- ALERTS -->

    <?php if (!empty($success)): ?>

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
                Please correct the following:
            </strong>

            <ul class="mb-0 mt-2">

                <?php foreach ($errors as $error): ?>

                    <li><?= h($error) ?></li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <?php if ($simulationMode): ?>

        <div class="warning-panel mb-4">

            <i class="bi bi-info-circle-fill"></i>

            <strong>Simulation mode is active.</strong>

            HOTNET is currently using simulated router functionality.
            Selecting MikroTik mode does not connect a physical router.
            Router hardware and API integration must be configured separately.

        </div>

    <?php endif; ?>


    <form method="POST" action="hotspot_settings.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($csrfToken) ?>"
        >


        <!-- ROUTER SETTINGS -->

        <section class="settings-card">

            <div class="setting-row">

                <div class="setting-icon">
                    <i class="bi bi-router"></i>
                </div>

                <div class="flex-grow-1">

                    <h5 class="settings-title">
                        Router Configuration
                    </h5>

                    <p class="settings-description">
                        Choose the operating mode for your HOTNET hotspot system.
                    </p>

                    <label class="form-label" for="router_mode">
                        Router Operating Mode
                    </label>

                    <select
                        name="router_mode"
                        id="router_mode"
                        class="form-select"
                        required
                    >

                        <option
                            value="Simulation"
                            <?= $settings['router_mode'] === 'Simulation' ? 'selected' : '' ?>
                        >
                            Simulation Mode
                        </option>

                        <option
                            value="MikroTik"
                            <?= $settings['router_mode'] === 'MikroTik' ? 'selected' : '' ?>
                        >
                            MikroTik Mode
                        </option>

                    </select>

                    <small class="text-muted d-block mt-2">
                        Use Simulation Mode while testing without physical router hardware.
                    </small>

                </div>

            </div>

        </section>


        <!-- DEVICE BINDING -->

        <section class="settings-card">

            <div class="setting-row">

                <div class="setting-icon">
                    <i class="bi bi-phone"></i>
                </div>

                <div class="flex-grow-1">

                    <h5 class="settings-title">
                        Device Binding
                    </h5>

                    <p class="settings-description">
                        Control how customer devices are registered and managed.
                    </p>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            name="device_binding_enabled"
                            id="device_binding_enabled"
                            value="1"
                            <?= $deviceBindingEnabled ? 'checked' : '' ?>
                        >

                        <label
                            class="form-check-label fw-bold"
                            for="device_binding_enabled"
                        >
                            Enable Device Binding
                        </label>

                    </div>

                    <small class="text-muted d-block mt-2">
                        When enabled, HOTNET can use registered customer devices
                        when enforcing device limits.
                    </small>

                </div>

            </div>

        </section>


        <!-- ROAMING SETTINGS -->

        <section class="settings-card">

            <div class="setting-row">

                <div class="setting-icon">
                    <i class="bi bi-arrow-left-right"></i>
                </div>

                <div class="flex-grow-1">

                    <h5 class="settings-title">
                        Device Roaming
                    </h5>

                    <p class="settings-description">
                        Control whether roaming is permitted in the HOTNET system.
                    </p>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            name="allow_roaming"
                            id="allow_roaming"
                            value="1"
                            <?= $roamingEnabled ? 'checked' : '' ?>
                        >

                        <label
                            class="form-check-label fw-bold"
                            for="allow_roaming"
                        >
                            Allow Device Roaming
                        </label>

                    </div>

                    <small class="text-muted d-block mt-2">
                        This setting records your roaming preference.
                        Actual roaming requires compatible access points and router integration.
                    </small>

                </div>

            </div>

        </section>


        <!-- DEVICE LIMIT -->

        <section class="settings-card">

            <div class="setting-row">

                <div class="setting-icon">
                    <i class="bi bi-device-ssd"></i>
                </div>

                <div class="flex-grow-1">

                    <h5 class="settings-title">
                        Customer Device Limit
                    </h5>

                    <p class="settings-description">
                        Set the default number of devices allowed for each customer.
                    </p>

                    <label class="form-label" for="default_max_devices">
                        Default Maximum Devices
                    </label>

                    <input
                        type="number"
                        name="default_max_devices"
                        id="default_max_devices"
                        class="form-control"
                        min="1"
                        max="50"
                        value="<?= h($settings['default_max_devices']) ?>"
                        required
                    >

                    <small class="text-muted d-block mt-2">
                        Allowed range: 1 to 50 devices.
                        Individual package limits can be configured separately.
                    </small>

                </div>

            </div>

        </section>


        <!-- CURRENT CONFIGURATION -->

        <section class="settings-card">

            <h5 class="settings-title">
                <i class="bi bi-clipboard-check"></i>
                Current Configuration
            </h5>

            <p class="settings-description">
                Review the settings currently loaded by HOTNET.
            </p>

            <div class="status-panel">

                <div class="row g-3">

                    <div class="col-md-6">

                        <strong>Router Mode</strong>

                        <div class="mt-1">
                            <?= h($settings['router_mode']) ?>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <strong>Device Binding</strong>

                        <div class="mt-1">
                            <?= $deviceBindingEnabled ? 'Enabled' : 'Disabled' ?>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <strong>Roaming</strong>

                        <div class="mt-1">
                            <?= $roamingEnabled ? 'Allowed' : 'Not Allowed' ?>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <strong>Default Device Limit</strong>

                        <div class="mt-1">
                            <?= number_format((int)$settings['default_max_devices']) ?>
                            device(s)
                        </div>

                    </div>

                </div>

            </div>

        </section>


        <!-- SAVE BUTTON -->

        <div class="save-bar">

            <div>

                <strong class="text-success">
                    <i class="bi bi-shield-check"></i>
                    HOTNET Configuration
                </strong>

                <div class="small text-muted">
                    Save your changes to update the system settings.
                </div>

            </div>

            <button type="submit" class="btn btn-green">

                <i class="bi bi-save"></i>
                Save Settings

            </button>

        </div>

    </form>

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