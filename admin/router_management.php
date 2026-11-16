<?php
session_start();

/*
|--------------------------------------------------------------------------
| HOTNET ROUTER MANAGEMENT
|--------------------------------------------------------------------------
| File: admin/router_management.php
| Mode: Simulation
| Purpose:
| - Register simulated routers
| - Enable / disable routers
| - Register access points
| - Enable / disable access points
| - Monitor simulated connections
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is not available.");
}

$conn->set_charset("utf8mb4");

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableCount($conn, $sql)
{
    $result = $conn->query($sql);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int)($row['total'] ?? 0);
}

function redirectPage()
{
    header("Location: router_management.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['router_csrf'])) {
    $_SESSION['router_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['router_csrf'];

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

$successMessage = $_SESSION['router_success'] ?? '';
$errorMessage = $_SESSION['router_error'] ?? '';

unset($_SESSION['router_success'], $_SESSION['router_error']);

/*
|--------------------------------------------------------------------------
| HANDLE FORM SUBMISSIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {

        $_SESSION['router_error'] = "Invalid security token. Please try again.";
        redirectPage();
    }

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | REGISTER SIMULATED ROUTER
    |--------------------------------------------------------------------------
    */

    if ($action === 'add_router') {

        $routerName = trim($_POST['router_name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $apiPort = (int)($_POST['api_port'] ?? 8728);

        if ($routerName === '') {

            $_SESSION['router_error'] = "Please enter a router name.";

        } elseif ($apiPort < 1 || $apiPort > 65535) {

            $_SESSION['router_error'] = "Please enter a valid port number.";

        } else {

            /*
             * Simulation is used until MikroTik hardware is available.
             */

            $routerType = 'Simulation';
            $routerStatus = 'Simulation';
            $enabled = 1;

            $stmt = $conn->prepare("
                INSERT INTO router_nodes
                (
                    router_name,
                    router_type,
                    host,
                    api_port,
                    status,
                    is_enabled,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

            if ($stmt) {

                $stmt->bind_param(
                    "sssisi",
                    $routerName,
                    $routerType,
                    $host,
                    $apiPort,
                    $routerStatus,
                    $enabled
                );

                if ($stmt->execute()) {

                    $_SESSION['router_success'] =
                        "Simulated router registered successfully.";

                } else {

                    $_SESSION['router_error'] =
                        "Unable to register router: " . $stmt->error;
                }

                $stmt->close();

            } else {

                $_SESSION['router_error'] = "Could not prepare router registration.";
            }
        }

        redirectPage();
    }

    /*
    |--------------------------------------------------------------------------
    | ENABLE / DISABLE ROUTER
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle_router') {

        $routerId = (int)($_POST['router_id'] ?? 0);

        if ($routerId > 0) {

            $stmt = $conn->prepare("
                UPDATE router_nodes
                SET
                    is_enabled = IF(is_enabled = 1, 0, 1),
                    status = IF(is_enabled = 1, 'Offline', 'Simulation')
                WHERE id = ?
            ");

            if ($stmt) {

                $stmt->bind_param("i", $routerId);

                if ($stmt->execute() && $stmt->affected_rows > 0) {

                    $_SESSION['router_success'] =
                        "Router status updated successfully.";

                } else {

                    $_SESSION['router_error'] =
                        "Router was not found or its status could not be changed.";
                }

                $stmt->close();
            }
        }

        redirectPage();
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTER ACCESS POINT
    |--------------------------------------------------------------------------
    */

    if ($action === 'add_access_point') {

        $accessPointName = trim($_POST['access_point_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $routerId = (int)($_POST['router_node_id'] ?? 0);

        if ($accessPointName === '') {

            $_SESSION['router_error'] = "Please enter an access point name.";

        } else {

            /*
             * Verify selected router.
             */

            $validRouter = false;

            if ($routerId > 0) {

                $check = $conn->prepare("
                    SELECT id
                    FROM router_nodes
                    WHERE id = ?
                    AND is_enabled = 1
                    LIMIT 1
                ");

                if ($check) {

                    $check->bind_param("i", $routerId);
                    $check->execute();

                    $validRouter = $check->get_result()->num_rows > 0;

                    $check->close();
                }

            } else {

                $validRouter = true;
            }

            if (!$validRouter) {

                $_SESSION['router_error'] =
                    "Select an enabled router or choose no router.";

            } else {

                $identityCode = 'HOTNET-AP-' .
                    strtoupper(bin2hex(random_bytes(4)));

                $apStatus = 'Simulation';

                if ($routerId > 0) {

                    $stmt = $conn->prepare("
                        INSERT INTO access_points
                        (
                            router_node_id,
                            access_point_name,
                            identity_code,
                            location,
                            status,
                            created_at
                        )
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");

                    if ($stmt) {

                        $stmt->bind_param(
                            "issss",
                            $routerId,
                            $accessPointName,
                            $identityCode,
                            $location,
                            $apStatus
                        );
                    }

                } else {

                    $stmt = $conn->prepare("
                        INSERT INTO access_points
                        (
                            router_node_id,
                            access_point_name,
                            identity_code,
                            location,
                            status,
                            created_at
                        )
                        VALUES (NULL, ?, ?, ?, ?, NOW())
                    ");

                    if ($stmt) {

                        $stmt->bind_param(
                            "ssss",
                            $accessPointName,
                            $identityCode,
                            $location,
                            $apStatus
                        );
                    }
                }

                if (isset($stmt) && $stmt) {

                    if ($stmt->execute()) {

                        $_SESSION['router_success'] =
                            "Access point registered successfully. Code: " .
                            $identityCode;

                    } else {

                        $_SESSION['router_error'] =
                            "Unable to register access point: " . $stmt->error;
                    }

                    $stmt->close();

                } else {

                    $_SESSION['router_error'] =
                        "Could not prepare access point registration.";
                }
            }
        }

        redirectPage();
    }

    /*
    |--------------------------------------------------------------------------
    | ENABLE / DISABLE ACCESS POINT
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle_access_point') {

        $accessPointId = (int)($_POST['access_point_id'] ?? 0);

        if ($accessPointId > 0) {

            $stmt = $conn->prepare("
                UPDATE access_points
                SET status = IF(status = 'Offline', 'Simulation', 'Offline')
                WHERE id = ?
            ");

            if ($stmt) {

                $stmt->bind_param("i", $accessPointId);

                if ($stmt->execute() && $stmt->affected_rows > 0) {

                    $_SESSION['router_success'] =
                        "Access point status updated successfully.";

                } else {

                    $_SESSION['router_error'] =
                        "Access point was not found or could not be updated.";
                }

                $stmt->close();
            }
        }

        redirectPage();
    }
}

/*
|--------------------------------------------------------------------------
| DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/

$totalRouters = tableCount(
    $conn,
    "SELECT COUNT(*) AS total FROM router_nodes"
);

$enabledRouters = tableCount(
    $conn,
    "SELECT COUNT(*) AS total FROM router_nodes WHERE is_enabled = 1"
);

$totalAccessPoints = tableCount(
    $conn,
    "SELECT COUNT(*) AS total FROM access_points"
);

$activeAccessPoints = tableCount(
    $conn,
    "SELECT COUNT(*) AS total FROM access_points WHERE status = 'Simulation'"
);

$activeConnections = tableCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM device_connections
     WHERE connection_status = 'Active'
     AND connection_mode = 'Simulation'"
);

/*
|--------------------------------------------------------------------------
| FETCH ROUTERS
|--------------------------------------------------------------------------
*/

$routers = $conn->query("
    SELECT
        r.*,
        (
            SELECT COUNT(*)
            FROM access_points ap
            WHERE ap.router_node_id = r.id
        ) AS access_point_count
    FROM router_nodes r
    ORDER BY r.id DESC
");

/*
|--------------------------------------------------------------------------
| FETCH ACCESS POINTS
|--------------------------------------------------------------------------
*/

$accessPoints = $conn->query("
    SELECT
        ap.*,
        r.router_name
    FROM access_points ap
    LEFT JOIN router_nodes r
        ON ap.router_node_id = r.id
    ORDER BY ap.id DESC
");

/*
|--------------------------------------------------------------------------
| FETCH RECENT CONNECTIONS
|--------------------------------------------------------------------------
*/

$recentConnections = $conn->query("
    SELECT
        dc.id,
        dc.connection_status,
        dc.connection_mode,
        dc.ip_address,
        dc.started_at,
        u.fullname,
        ap.access_point_name
    FROM device_connections dc
    LEFT JOIN users u
        ON dc.user_id = u.id
    LEFT JOIN access_points ap
        ON dc.access_point_id = ap.id
    WHERE dc.connection_mode = 'Simulation'
    ORDER BY dc.id DESC
    LIMIT 10
");

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Router Management | HOTNET</title>

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
            --leaf: #22a447;
            --light-green: #e9f7ed;
            --background: #f2f8f3;
            --dark: #183526;
        }

        body {
            background: var(--background);
            font-family: Arial, sans-serif;
            color: var(--dark);
        }

        .navbar {
            background: linear-gradient(110deg, #103d25, #198754);
            box-shadow: 0 3px 12px rgba(0, 0, 0, .12);
        }

        .navbar-brand {
            font-weight: 800;
            letter-spacing: 1px;
        }

        .navbar .nav-link {
            color: #ffffff !important;
            font-weight: 500;
        }

        .hero {
            background:
                linear-gradient(
                    120deg,
                    rgba(12, 55, 29, .94),
                    rgba(25, 135, 84, .85)
                ),
                url('https://images.unsplash.com/photo-1448375240586-882707db888b?auto=format&fit=crop&w=1600&q=80');

            background-size: cover;
            background-position: center;

            border-radius: 18px;
            padding: 35px;
            color: white;
            margin-bottom: 25px;
        }

        .hero h2 {
            font-weight: 800;
        }

        .stat-card {
            background: white;
            border: none;
            border-radius: 16px;
            padding: 22px;
            height: 100%;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .05);
            transition: .2s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            background: var(--light-green);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
            margin-bottom: 14px;
        }

        .stat-number {
            font-size: 29px;
            font-weight: 800;
            color: var(--forest);
        }

        .content-card {
            background: white;
            border: none;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .045);
        }

        .section-title {
            color: var(--forest);
            font-weight: 800;
            margin-bottom: 18px;
        }

        .btn-hotnet {
            background: var(--green);
            color: white;
            border: none;
            font-weight: 600;
        }

        .btn-hotnet:hover {
            background: var(--forest);
            color: white;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 11px;
        }

        .table th {
            background: #edf7ef;
            color: var(--forest);
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-simulation {
            background: #e1f4e7;
            color: #176b35;
        }

        .status-offline {
            background: #fce8e8;
            color: #a52828;
        }

        .simulation-notice {
            background: #eaf7ed;
            border-left: 5px solid #198754;
            border-radius: 10px;
            padding: 17px;
            margin-bottom: 22px;
        }

        .empty-state {
            text-align: center;
            color: #789080;
            padding: 30px;
        }

        footer {
            background: #103d25;
            color: white;
            padding: 20px;
            margin-top: 35px;
            text-align: center;
        }

        @media(max-width: 768px) {
            .hero {
                padding: 24px;
            }

            .content-card {
                padding: 16px;
            }
        }

    </style>

</head>

<body>

<!-- NAVIGATION -->

<nav class="navbar navbar-expand-lg navbar-dark">

    <div class="container-fluid px-4">

        <a class="navbar-brand" href="dashboard.php">
            <i class="bi bi-tree-fill"></i>
            HOTNET
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#hotnetNav"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="hotnetNav">

            <ul class="navbar-nav ms-auto">

                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        Dashboard
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="users.php">
                        Customers
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="packages.php">
                        Packages
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="vouchers.php">
                        Vouchers
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="payments.php">
                        Payments
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="router_management.php">
                        Routers
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="../auth/logout.php">
                        Logout
                    </a>
                </li>

            </ul>

        </div>

    </div>

</nav>


<div class="container-fluid px-4 py-4">

    <!-- HERO -->

    <div class="hero">

        <h2>
            <i class="bi bi-router-fill"></i>
            Router Management
        </h2>

        <p class="mb-2">
            Manage HOTNET routers, access points and simulated network activity.
        </p>

        <span class="badge bg-light text-success">
            SIMULATION MODE
        </span>

    </div>


    <!-- MESSAGES -->

    <?php if ($successMessage): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="bi bi-check-circle-fill"></i>
            <?= h($successMessage) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($errorMessage): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <i class="bi bi-exclamation-circle-fill"></i>
            <?= h($errorMessage) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- SIMULATION NOTICE -->

    <div class="simulation-notice">

        <h6 class="fw-bold text-success">
            <i class="bi bi-info-circle-fill"></i>
            Network simulation is active
        </h6>

        <p class="mb-0">
            You do not need a physical MikroTik router to manage simulated
            router records and access points. Real WiFi authentication,
            internet distribution and automatic disconnection will require
            compatible router hardware and integration later.
        </p>

    </div>


    <!-- STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-router"></i>
                </div>

                <div class="stat-number">
                    <?= $totalRouters ?>
                </div>

                <div class="text-muted">
                    Registered Routers
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-check-circle"></i>
                </div>

                <div class="stat-number">
                    <?= $enabledRouters ?>
                </div>

                <div class="text-muted">
                    Enabled Routers
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-broadcast"></i>
                </div>

                <div class="stat-number">
                    <?= $totalAccessPoints ?>
                </div>

                <div class="text-muted">
                    Access Points
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-wifi"></i>
                </div>

                <div class="stat-number">
                    <?= $activeConnections ?>
                </div>

                <div class="text-muted">
                    Simulated Connections
                </div>

            </div>

        </div>

    </div>


    <!-- REGISTER ROUTER -->

    <div class="content-card">

        <h5 class="section-title">
            <i class="bi bi-plus-circle"></i>
            Register New Router
        </h5>

        <form method="POST">

            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <input type="hidden" name="action" value="add_router">

            <div class="row g-3">

                <div class="col-md-4">

                    <label class="form-label fw-semibold">
                        Router Name
                    </label>

                    <input
                        type="text"
                        name="router_name"
                        class="form-control"
                        placeholder="Example: HOTNET Main Router"
                        maxlength="100"
                        required
                    >

                </div>


                <div class="col-md-4">

                    <label class="form-label fw-semibold">
                        Router Host / IP (Optional)
                    </label>

                    <input
                        type="text"
                        name="host"
                        class="form-control"
                        placeholder="Example: 192.168.88.1"
                    >

                </div>


                <div class="col-md-2">

                    <label class="form-label fw-semibold">
                        API Port
                    </label>

                    <input
                        type="number"
                        name="api_port"
                        class="form-control"
                        value="8728"
                        min="1"
                        max="65535"
                        required
                    >

                </div>


                <div class="col-md-2 d-flex align-items-end">

                    <button
                        type="submit"
                        class="btn btn-hotnet w-100"
                    >
                        <i class="bi bi-plus-lg"></i>
                        Register
                    </button>

                </div>

            </div>

            <small class="text-muted d-block mt-2">
                New routers are registered in Simulation Mode.
                No MikroTik connection is attempted.
            </small>

        </form>

    </div>


    <!-- ROUTER LIST -->

    <div class="content-card">

        <h5 class="section-title">
            <i class="bi bi-hdd-network"></i>
            Registered Routers
        </h5>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>
                        <th>#</th>
                        <th>Router Name</th>
                        <th>Type</th>
                        <th>Host</th>
                        <th>Access Points</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>

                </thead>

                <tbody>

                <?php if ($routers && $routers->num_rows > 0): ?>

                    <?php while ($router = $routers->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <?= (int)$router['id'] ?>
                            </td>

                            <td class="fw-semibold">
                                <?= h($router['router_name']) ?>
                            </td>

                            <td>
                                <?= h($router['router_type']) ?>
                            </td>

                            <td>
                                <?= h($router['host'] ?: 'Not configured') ?>
                            </td>

                            <td>
                                <span class="badge bg-success">
                                    <?= (int)$router['access_point_count'] ?>
                                </span>
                            </td>

                            <td>

                                <?php if ((int)$router['is_enabled'] === 1): ?>

                                    <span class="status-badge status-simulation">
                                        Simulation
                                    </span>

                                <?php else: ?>

                                    <span class="status-badge status-offline">
                                        Offline
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="toggle_router"
                                    >

                                    <input
                                        type="hidden"
                                        name="router_id"
                                        value="<?= (int)$router['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-sm <?= (int)$router['is_enabled'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                    >

                                        <?= (int)$router['is_enabled'] === 1
                                            ? 'Disable'
                                            : 'Enable' ?>

                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="7">

                            <div class="empty-state">

                                <i class="bi bi-router fs-1"></i>

                                <p class="mt-2 mb-0">
                                    No routers registered yet.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- REGISTER ACCESS POINT -->

    <div class="content-card">

        <h5 class="section-title">
            <i class="bi bi-broadcast-pin"></i>
            Register Access Point
        </h5>

        <form method="POST">

            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <input type="hidden" name="action" value="add_access_point">

            <div class="row g-3">

                <div class="col-md-4">

                    <label class="form-label fw-semibold">
                        Access Point Name
                    </label>

                    <input
                        type="text"
                        name="access_point_name"
                        class="form-control"
                        placeholder="Example: HOTNET Ground Floor"
                        maxlength="100"
                        required
                    >

                </div>


                <div class="col-md-3">

                    <label class="form-label fw-semibold">
                        Location
                    </label>

                    <input
                        type="text"
                        name="location"
                        class="form-control"
                        placeholder="Example: Main Building"
                    >

                </div>


                <div class="col-md-3">

                    <label class="form-label fw-semibold">
                        Connect to Router
                    </label>

                    <select
                        name="router_node_id"
                        class="form-select"
                    >

                        <option value="0">
                            No Router Selected
                        </option>

                        <?php
                        $routerOptions = $conn->query("
                            SELECT id, router_name
                            FROM router_nodes
                            WHERE is_enabled = 1
                            ORDER BY router_name ASC
                        ");
                        ?>

                        <?php if ($routerOptions): ?>

                            <?php while ($option = $routerOptions->fetch_assoc()): ?>

                                <option value="<?= (int)$option['id'] ?>">
                                    <?= h($option['router_name']) ?>
                                </option>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </select>

                </div>


                <div class="col-md-2 d-flex align-items-end">

                    <button
                        type="submit"
                        class="btn btn-hotnet w-100"
                    >
                        <i class="bi bi-plus-lg"></i>
                        Add Point
                    </button>

                </div>

            </div>

        </form>

    </div>


    <!-- ACCESS POINT LIST -->

    <div class="content-card">

        <h5 class="section-title">
            <i class="bi bi-wifi"></i>
            Access Point Management
        </h5>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>
                        <th>#</th>
                        <th>Access Point</th>
                        <th>Identity Code</th>
                        <th>Location</th>
                        <th>Router</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>

                </thead>

                <tbody>

                <?php if ($accessPoints && $accessPoints->num_rows > 0): ?>

                    <?php while ($ap = $accessPoints->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <?= (int)$ap['id'] ?>
                            </td>

                            <td class="fw-semibold">
                                <?= h($ap['access_point_name']) ?>
                            </td>

                            <td>
                                <code><?= h($ap['identity_code']) ?></code>
                            </td>

                            <td>
                                <?= h($ap['location'] ?: 'Not specified') ?>
                            </td>

                            <td>
                                <?= h($ap['router_name'] ?: 'Not assigned') ?>
                            </td>

                            <td>

                                <?php if ($ap['status'] === 'Offline'): ?>

                                    <span class="status-badge status-offline">
                                        Offline
                                    </span>

                                <?php else: ?>

                                    <span class="status-badge status-simulation">
                                        Simulation
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="toggle_access_point"
                                    >

                                    <input
                                        type="hidden"
                                        name="access_point_id"
                                        value="<?= (int)$ap['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-sm <?= $ap['status'] === 'Offline' ? 'btn-outline-success' : 'btn-outline-danger' ?>"
                                    >

                                        <?= $ap['status'] === 'Offline'
                                            ? 'Enable'
                                            : 'Disable' ?>

                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="7">

                            <div class="empty-state">

                                <i class="bi bi-broadcast fs-1"></i>

                                <p class="mt-2 mb-0">
                                    No access points registered yet.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- CONNECTION MONITOR -->

    <div class="content-card">

        <h5 class="section-title">
            <i class="bi bi-activity"></i>
            Recent Simulated Connections
        </h5>

        <div class="table-responsive">

            <table class="table table-hover">

                <thead>

                    <tr>
                        <th>Connection ID</th>
                        <th>Customer</th>
                        <th>Access Point</th>
                        <th>IP Address</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Started</th>
                    </tr>

                </thead>

                <tbody>

                <?php if ($recentConnections && $recentConnections->num_rows > 0): ?>

                    <?php while ($connection = $recentConnections->fetch_assoc()): ?>

                        <tr>

                            <td>
                                #<?= (int)$connection['id'] ?>
                            </td>

                            <td>
                                <?= h($connection['fullname'] ?: 'Unknown') ?>
                            </td>

                            <td>
                                <?= h($connection['access_point_name'] ?: 'Not assigned') ?>
                            </td>

                            <td>
                                <?= h($connection['ip_address'] ?: 'Not assigned') ?>
                            </td>

                            <td>
                                <span class="badge bg-success">
                                    <?= h($connection['connection_mode']) ?>
                                </span>
                            </td>

                            <td>
                                <?= h($connection['connection_status']) ?>
                            </td>

                            <td>
                                <?= h($connection['started_at'] ?: '-') ?>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="7">

                            <div class="empty-state">

                                <i class="bi bi-activity fs-1"></i>

                                <p class="mt-2 mb-0">
                                    No simulated connections recorded yet.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- FOOTER -->

<footer>

    <h5 class="fw-bold">
        <i class="bi bi-tree-fill"></i>
        HOTNET WiFi Management
    </h5>

    <p class="mb-1">
        Green Technology. Reliable Connectivity.
    </p>

    <small>
        Support: 0718347038
    </small>

    <div class="mt-2">
        &copy; <?= date('Y') ?> HOTNET. All Rights Reserved.
    </div>

</footer>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>