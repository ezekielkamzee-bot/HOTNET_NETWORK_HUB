<?php
session_start();

/*
|--------------------------------------------------------------------------
| HOTNET DEVICE CONNECTION MANAGEMENT
|--------------------------------------------------------------------------
| File: admin/device_connections.php
| Mode: Simulation
|
| Features:
| - View customer connections
| - Search and filter connections
| - Monitor connection status
| - View device and voucher information
| - Revoke active simulated connections
| - View connection history
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

function countConnections($conn, $status = null)
{
    if ($status !== null) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM device_connections
            WHERE connection_status = ?
        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("s", $status);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return (int)($row['total'] ?? 0);
    }

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM device_connections
    ");

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int)($row['total'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['connection_csrf'])) {
    $_SESSION['connection_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['connection_csrf'];

/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

$successMessage = $_SESSION['connection_success'] ?? '';
$errorMessage = $_SESSION['connection_error'] ?? '';

unset(
    $_SESSION['connection_success'],
    $_SESSION['connection_error']
);

/*
|--------------------------------------------------------------------------
| REVOKE CONNECTION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {

        $_SESSION['connection_error'] =
            "Security verification failed. Please try again.";

        header("Location: device_connections.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'revoke_connection') {

        $connectionId = (int)($_POST['connection_id'] ?? 0);

        if ($connectionId <= 0) {

            $_SESSION['connection_error'] =
                "Invalid connection selected.";

        } else {

            $conn->begin_transaction();

            try {

                /*
                |--------------------------------------------------------------------------
                | FIND ACTIVE CONNECTION
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT
                        id,
                        user_id,
                        device_id,
                        access_point_id
                    FROM device_connections
                    WHERE id = ?
                    AND connection_status = 'Active'
                    AND connection_mode = 'Simulation'
                    LIMIT 1
                    FOR UPDATE
                ");

                if (!$stmt) {
                    throw new Exception("Unable to prepare connection lookup.");
                }

                $stmt->bind_param("i", $connectionId);
                $stmt->execute();

                $connection = $stmt->get_result()->fetch_assoc();

                $stmt->close();

                if (!$connection) {

                    throw new Exception(
                        "The active simulated connection was not found."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | UPDATE CONNECTION
                |--------------------------------------------------------------------------
                */

                $disconnectReason = "Revoked by administrator";

                $update = $conn->prepare("
                    UPDATE device_connections
                    SET
                        connection_status = 'Revoked',
                        ended_at = NOW(),
                        last_seen_at = NOW(),
                        disconnect_reason = ?
                    WHERE id = ?
                    AND connection_status = 'Active'
                    AND connection_mode = 'Simulation'
                ");

                if (!$update) {
                    throw new Exception("Unable to prepare connection update.");
                }

                $update->bind_param(
                    "si",
                    $disconnectReason,
                    $connectionId
                );

                if (!$update->execute() || $update->affected_rows !== 1) {

                    $update->close();

                    throw new Exception(
                        "The connection could not be revoked."
                    );
                }

                $update->close();

                /*
                |--------------------------------------------------------------------------
                | RECORD ROAMING / CONNECTION EVENT
                |--------------------------------------------------------------------------
                */

                $eventType = "Disconnected";

                $eventDescription =
                    "Simulated connection revoked by administrator.";

                $deviceId = !empty($connection['device_id'])
                    ? (int)$connection['device_id']
                    : null;

                $accessPointId = !empty($connection['access_point_id'])
                    ? (int)$connection['access_point_id']
                    : null;

                $event = $conn->prepare("
                    INSERT INTO roaming_events
                    (
                        connection_id,
                        device_id,
                        from_access_point_id,
                        event_type,
                        event_description,
                        occurred_at
                    )
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");

                if (!$event) {
                    throw new Exception("Unable to prepare connection event.");
                }

                $event->bind_param(
                    "iiiss",
                    $connectionId,
                    $deviceId,
                    $accessPointId,
                    $eventType,
                    $eventDescription
                );

                if (!$event->execute()) {

                    $event->close();

                    throw new Exception(
                        "Unable to record the connection event."
                    );
                }

                $event->close();

                $conn->commit();

                $_SESSION['connection_success'] =
                    "Simulated connection revoked successfully.";

            } catch (Throwable $e) {

                $conn->rollback();

                error_log(
                    "HOTNET connection revocation error: " . $e->getMessage()
                );

                $_SESSION['connection_error'] = $e->getMessage();
            }
        }

        header("Location: device_connections.php");
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| CONNECTION STATISTICS
|--------------------------------------------------------------------------
*/

$totalConnections = countConnections($conn);

$activeConnections = countConnections($conn, 'Active');

$disconnectedConnections = countConnections($conn, 'Disconnected');

$expiredConnections = countConnections($conn, 'Expired');

$revokedConnections = countConnections($conn, 'Revoked');

/*
|--------------------------------------------------------------------------
| SEARCH AND FILTER
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

$filterStatus = trim($_GET['status'] ?? '');

$allowedStatuses = [
    '',
    'Active',
    'Disconnected',
    'Expired',
    'Revoked',
    'Failed'
];

if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$limit = 15;

$page = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$types = '';
$params = [];

/*
|--------------------------------------------------------------------------
| STATUS FILTER
|--------------------------------------------------------------------------
*/

if ($filterStatus !== '') {

    $where[] = "dc.connection_status = ?";

    $types .= 's';

    $params[] = $filterStatus;
}

/*
|--------------------------------------------------------------------------
| SEARCH FILTER
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where[] = "(
        u.fullname LIKE ?
        OR u.phone LIKE ?
        OR cd.device_name LIKE ?
        OR cd.device_type LIKE ?
        OR v.voucher_code LIKE ?
        OR p.package_name LIKE ?
        OR dc.ip_address LIKE ?
        OR ap.access_point_name LIKE ?
    )";

    $searchValue = '%' . $search . '%';

    $types .= 'ssssssss';

    for ($i = 0; $i < 8; $i++) {
        $params[] = $searchValue;
    }
}

/*
|--------------------------------------------------------------------------
| BUILD WHERE CLAUSE
|--------------------------------------------------------------------------
*/

$whereSql = '';

if (!empty($where)) {
    $whereSql = ' WHERE ' . implode(' AND ', $where);
}

/*
|--------------------------------------------------------------------------
| COUNT FILTERED CONNECTIONS
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM device_connections dc

    LEFT JOIN users u
        ON dc.user_id = u.id

    LEFT JOIN customer_devices cd
        ON dc.device_id = cd.id

    LEFT JOIN vouchers v
        ON dc.voucher_id = v.id

    LEFT JOIN packages p
        ON dc.package_id = p.id

    LEFT JOIN access_points ap
        ON dc.access_point_id = ap.id

    $whereSql
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    die("Unable to prepare connection count query.");
}

if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();

$countResult = $countStmt->get_result();

$filteredTotal = (int)(
    $countResult->fetch_assoc()['total'] ?? 0
);

$countStmt->close();

$totalPages = max(1, (int)ceil($filteredTotal / $limit));

$page = min($page, $totalPages);

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| FETCH CONNECTIONS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        dc.id,
        dc.user_id,
        dc.voucher_id,
        dc.package_id,
        dc.device_id,
        dc.router_node_id,
        dc.access_point_id,
        dc.connection_mode,
        dc.connection_status,
        dc.ip_address,
        dc.mac_address,
        dc.bytes_in,
        dc.bytes_out,
        dc.started_at,
        dc.ended_at,
        dc.last_seen_at,
        dc.disconnect_reason,

        u.fullname,
        u.phone,

        cd.device_name,
        cd.device_type,

        v.voucher_code,

        p.package_name,

        ap.access_point_name,

        rn.router_name

    FROM device_connections dc

    LEFT JOIN users u
        ON dc.user_id = u.id

    LEFT JOIN customer_devices cd
        ON dc.device_id = cd.id

    LEFT JOIN vouchers v
        ON dc.voucher_id = v.id

    LEFT JOIN packages p
        ON dc.package_id = p.id

    LEFT JOIN access_points ap
        ON dc.access_point_id = ap.id

    LEFT JOIN router_nodes rn
        ON dc.router_node_id = rn.id

    $whereSql

    ORDER BY dc.id DESC

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Unable to prepare connections query.");
}

$queryTypes = $types . 'ii';

$queryParams = $params;

$queryParams[] = $limit;
$queryParams[] = $offset;

$stmt->bind_param($queryTypes, ...$queryParams);

$stmt->execute();

$connections = $stmt->get_result();

/*
|--------------------------------------------------------------------------
| FORMAT BYTES
|--------------------------------------------------------------------------
*/

function formatBytes($bytes)
{
    $bytes = (float)$bytes;

    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $power = (int)floor(log($bytes, 1024));

    $power = min($power, count($units) - 1);

    return round($bytes / pow(1024, $power), 2) .
        ' ' . $units[$power];
}

/*
|--------------------------------------------------------------------------
| STATUS BADGE
|--------------------------------------------------------------------------
*/

function statusClass($status)
{
    switch ($status) {

        case 'Active':
            return 'status-active';

        case 'Disconnected':
            return 'status-disconnected';

        case 'Expired':
            return 'status-expired';

        case 'Revoked':
            return 'status-revoked';

        case 'Failed':
            return 'status-failed';

        default:
            return 'status-default';
    }
}

/*
|--------------------------------------------------------------------------
| PAGINATION URL
|--------------------------------------------------------------------------
*/

function pageUrl($pageNumber, $search, $status)
{
    return '?' . http_build_query([
        'page' => $pageNumber,
        'search' => $search,
        'status' => $status
    ]);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Device Connections | HOTNET</title>

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
            color: var(--dark);
            font-family: Arial, sans-serif;
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
            color: white !important;
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

            padding: 35px;
            border-radius: 18px;
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
            padding: 20px;
            height: 100%;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .05);
            transition: .2s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: var(--light-green);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 12px;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 800;
            color: var(--forest);
        }

        .content-card {
            background: white;
            border: none;
            border-radius: 16px;
            padding: 23px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .045);
        }

        .section-title {
            color: var(--forest);
            font-weight: 800;
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
            padding: 10px 12px;
        }

        .table th {
            background: #edf7ef;
            color: var(--forest);
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
            font-size: 13px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-active {
            background: #d8f3df;
            color: #176b35;
        }

        .status-disconnected {
            background: #e8edf1;
            color: #52616b;
        }

        .status-expired {
            background: #fff0d4;
            color: #966200;
        }

        .status-revoked {
            background: #fce0e0;
            color: #a52828;
        }

        .status-failed {
            background: #f7dede;
            color: #842029;
        }

        .status-default {
            background: #eeeeee;
            color: #555555;
        }

        .simulation-notice {
            background: #eaf7ed;
            border-left: 5px solid var(--green);
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 22px;
        }

        .empty-state {
            padding: 35px;
            text-align: center;
            color: #789080;
        }

        .pagination .page-link {
            color: var(--forest);
        }

        .pagination .active .page-link {
            background: var(--green);
            border-color: var(--green);
            color: white;
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
                padding: 15px;
            }

            .stat-number {
                font-size: 23px;
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
                    <a class="nav-link" href="router_management.php">
                        Routers
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="device_connections.php">
                        Connections
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

    <!-- PAGE HEADER -->

    <div class="hero">

        <h2>
            <i class="bi bi-wifi"></i>
            Device Connection Management
        </h2>

        <p class="mb-2">
            Monitor customer devices, connection activity and network sessions.
        </p>

        <span class="badge bg-light text-success">
            SIMULATION MODE
        </span>

    </div>


    <!-- FLASH MESSAGES -->

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
            Simulation connection monitoring
        </h6>

        <p class="mb-0">
            This page displays connection records stored in HOTNET.
            Revoking a simulated connection updates its database status.
            It does not disconnect a physical WiFi device because MikroTik
            hardware is not yet connected.
        </p>

    </div>


    <!-- STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-4 col-xl">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-hdd-network"></i>
                </div>

                <div class="stat-number">
                    <?= $totalConnections ?>
                </div>

                <div class="text-muted">
                    Total Connections
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-4 col-xl">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-wifi"></i>
                </div>

                <div class="stat-number">
                    <?= $activeConnections ?>
                </div>

                <div class="text-muted">
                    Active
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-4 col-xl">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-wifi-off"></i>
                </div>

                <div class="stat-number">
                    <?= $disconnectedConnections ?>
                </div>

                <div class="text-muted">
                    Disconnected
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-4 col-xl">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-clock-history"></i>
                </div>

                <div class="stat-number">
                    <?= $expiredConnections ?>
                </div>

                <div class="text-muted">
                    Expired
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-4 col-xl">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-shield-x"></i>
                </div>

                <div class="stat-number">
                    <?= $revokedConnections ?>
                </div>

                <div class="text-muted">
                    Revoked
                </div>

            </div>

        </div>

    </div>


    <!-- SEARCH AND FILTER -->

    <div class="content-card">

        <h5 class="section-title mb-3">
            <i class="bi bi-search"></i>
            Search Connections
        </h5>

        <form method="GET">

            <div class="row g-3">

                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Search
                    </label>

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Customer, phone, device, voucher, package..."
                        value="<?= h($search) ?>"
                    >

                </div>


                <div class="col-md-3">

                    <label class="form-label fw-semibold">
                        Connection Status
                    </label>

                    <select
                        name="status"
                        class="form-select"
                    >

                        <option value="">All Statuses</option>

                        <?php foreach ($allowedStatuses as $status): ?>

                            <?php if ($status !== ''): ?>

                                <option
                                    value="<?= h($status) ?>"
                                    <?= $filterStatus === $status ? 'selected' : '' ?>
                                >
                                    <?= h($status) ?>
                                </option>

                            <?php endif; ?>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-3 d-flex align-items-end gap-2">

                    <button
                        type="submit"
                        class="btn btn-hotnet flex-fill"
                    >
                        <i class="bi bi-search"></i>
                        Search
                    </button>

                    <a
                        href="device_connections.php"
                        class="btn btn-outline-secondary"
                    >
                        Reset
                    </a>

                </div>

            </div>

        </form>

    </div>


    <!-- CONNECTION TABLE -->

    <div class="content-card">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <h5 class="section-title mb-0">
                <i class="bi bi-list-ul"></i>
                Customer Connections
            </h5>

            <span class="badge bg-success">
                <?= $filteredTotal ?> Records Found
            </span>

        </div>


        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Device</th>
                        <th>Voucher</th>
                        <th>Package</th>
                        <th>Access Point</th>
                        <th>IP Address</th>
                        <th>Data Used</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Action</th>
                    </tr>

                </thead>

                <tbody>

                <?php if ($connections && $connections->num_rows > 0): ?>

                    <?php while ($row = $connections->fetch_assoc()): ?>

                        <?php
                            $totalData =
                                (float)$row['bytes_in'] +
                                (float)$row['bytes_out'];
                        ?>

                        <tr>

                            <td class="fw-bold">
                                #<?= (int)$row['id'] ?>
                            </td>


                            <td>

                                <div class="fw-semibold">
                                    <?= h($row['fullname'] ?: 'Unknown Customer') ?>
                                </div>

                                <small class="text-muted">
                                    <?= h($row['phone'] ?: '-') ?>
                                </small>

                            </td>


                            <td>

                                <div class="fw-semibold">
                                    <?= h($row['device_name'] ?: 'Unknown Device') ?>
                                </div>

                                <small class="text-muted">
                                    <?= h($row['device_type'] ?: 'Other') ?>
                                </small>

                            </td>


                            <td>
                                <?= h($row['voucher_code'] ?: '-') ?>
                            </td>


                            <td>
                                <?= h($row['package_name'] ?: '-') ?>
                            </td>


                            <td>
                                <?= h($row['access_point_name'] ?: '-') ?>
                            </td>


                            <td>
                                <?= h($row['ip_address'] ?: '-') ?>
                            </td>


                            <td class="fw-semibold">
                                <?= h(formatBytes($totalData)) ?>
                            </td>


                            <td>
                                <span class="badge bg-success">
                                    <?= h($row['connection_mode']) ?>
                                </span>
                            </td>


                            <td>

                                <span class="status-badge <?= h(statusClass($row['connection_status'])) ?>">
                                    <?= h($row['connection_status']) ?>
                                </span>

                            </td>


                            <td>
                                <?= h($row['started_at'] ?: '-') ?>
                            </td>


                            <td>

                                <?php if (
                                    $row['connection_status'] === 'Active'
                                    && $row['connection_mode'] === 'Simulation'
                                ): ?>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        data-bs-toggle="modal"
                                        data-bs-target="#revokeModal"
                                        data-connection-id="<?= (int)$row['id'] ?>"
                                        data-customer="<?= h($row['fullname'] ?: 'Unknown Customer') ?>"
                                    >

                                        <i class="bi bi-shield-x"></i>
                                        Revoke

                                    </button>

                                <?php else: ?>

                                    <span class="text-muted small">
                                        No action
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="12">

                            <div class="empty-state">

                                <i class="bi bi-wifi-off fs-1"></i>

                                <h6 class="mt-3">
                                    No connections found
                                </h6>

                                <p class="mb-0">
                                    No records match your search or filter.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- PAGINATION -->

        <?php if ($totalPages > 1): ?>

            <nav class="mt-3">

                <ul class="pagination justify-content-center flex-wrap">

                    <?php if ($page > 1): ?>

                        <li class="page-item">

                            <a
                                class="page-link"
                                href="<?= h(pageUrl($page - 1, $search, $filterStatus)) ?>"
                            >
                                Previous
                            </a>

                        </li>

                    <?php endif; ?>


                    <?php for (
                        $number = max(1, $page - 2);
                        $number <= min($totalPages, $page + 2);
                        $number++
                    ): ?>

                        <li class="page-item <?= $number === $page ? 'active' : '' ?>">

                            <a
                                class="page-link"
                                href="<?= h(pageUrl($number, $search, $filterStatus)) ?>"
                            >
                                <?= $number ?>
                            </a>

                        </li>

                    <?php endfor; ?>


                    <?php if ($page < $totalPages): ?>

                        <li class="page-item">

                            <a
                                class="page-link"
                                href="<?= h(pageUrl($page + 1, $search, $filterStatus)) ?>"
                            >
                                Next
                            </a>

                        </li>

                    <?php endif; ?>

                </ul>

            </nav>

        <?php endif; ?>

    </div>


    <!-- CONNECTION INFORMATION -->

    <div class="content-card">

        <h5 class="section-title">
            <i class="bi bi-info-circle"></i>
            Connection Management Information
        </h5>

        <div class="row g-3">

            <div class="col-md-4">

                <div class="p-3 rounded bg-light h-100">

                    <h6 class="fw-bold text-success">
                        <i class="bi bi-shield-check"></i>
                        Connection Security
                    </h6>

                    <p class="small mb-0">
                        Administrators can revoke active simulated connections.
                        All changes are protected by a CSRF token.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="p-3 rounded bg-light h-100">

                    <h6 class="fw-bold text-success">
                        <i class="bi bi-phone"></i>
                        Device Management
                    </h6>

                    <p class="small mb-0">
                        Device details are retrieved from registered HOTNET
                        customer devices.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="p-3 rounded bg-light h-100">

                    <h6 class="fw-bold text-success">
                        <i class="bi bi-router"></i>
                        Future MikroTik Integration
                    </h6>

                    <p class="small mb-0">
                        Router API integration can later support actual
                        connection monitoring and disconnection.
                    </p>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- REVOKE CONFIRMATION MODAL -->

<div
    class="modal fade"
    id="revokeModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title text-danger">
                        <i class="bi bi-shield-x"></i>
                        Revoke Connection
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>


                <div class="modal-body">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= h($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="revoke_connection"
                    >

                    <input
                        type="hidden"
                        name="connection_id"
                        id="revokeConnectionId"
                    >

                    <p>
                        Are you sure you want to revoke this simulated
                        connection?
                    </p>

                    <p class="fw-bold text-success" id="revokeCustomerName"></p>

                    <div class="alert alert-warning mb-0">

                        This action changes the connection status to
                        <strong>Revoked</strong> and records the event.

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-danger"
                    >
                        Confirm Revocation
                    </button>

                </div>

            </form>

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

<script>

/*
|--------------------------------------------------------------------------
| REVOKE CONNECTION MODAL
|--------------------------------------------------------------------------
*/

const revokeModal = document.getElementById('revokeModal');

revokeModal.addEventListener('show.bs.modal', function (event) {

    const button = event.relatedTarget;

    const connectionId = button.getAttribute('data-connection-id');

    const customerName = button.getAttribute('data-customer');

    document.getElementById('revokeConnectionId').value = connectionId;

    document.getElementById('revokeCustomerName').textContent =
        'Customer: ' + customerName;

});

</script>

</body>
</html>

<?php
$stmt->close();
?>