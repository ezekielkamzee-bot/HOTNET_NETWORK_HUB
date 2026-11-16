<?php
session_start();

/*
|--------------------------------------------------------------------------
| HOTNET - DEVICE ROAMING MANAGEMENT
|--------------------------------------------------------------------------
| File: admin/roaming_management.php
| Purpose:
| - Monitor device roaming events.
| - View connection and reconnection history.
| - Search and filter roaming records.
| - Display customer, device and access point details.
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
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function eventLabel($event)
{
    $labels = [
        'Connected'     => 'Connected',
        'Roamed'        => 'Roamed',
        'Reconnected'   => 'Reconnected',
        'Disconnected'  => 'Disconnected'
    ];

    return $labels[$event] ?? $event;
}

function eventClass($event)
{
    $classes = [
        'Connected'     => 'success',
        'Roamed'        => 'primary',
        'Reconnected'   => 'info',
        'Disconnected'  => 'secondary'
    ];

    return $classes[$event] ?? 'secondary';
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$eventFilter = trim($_GET['event'] ?? '');

$allowedEvents = [
    'Connected',
    'Roamed',
    'Reconnected',
    'Disconnected'
];

if (!in_array($eventFilter, $allowedEvents, true)) {
    $eventFilter = '';
}

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$limit = 15;

$page = max(1, (int)($_GET['page'] ?? 1));

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'connected' => 0,
    'roamed' => 0,
    'reconnected' => 0,
    'disconnected' => 0
];

$statsQuery = "
    SELECT
        COUNT(*) AS total,
        SUM(event_type = 'Connected') AS connected,
        SUM(event_type = 'Roamed') AS roamed,
        SUM(event_type = 'Reconnected') AS reconnected,
        SUM(event_type = 'Disconnected') AS disconnected
    FROM roaming_events
";

$statsResult = $conn->query($statsQuery);

if ($statsResult) {
    $stats = array_merge($stats, $statsResult->fetch_assoc());
}

/*
|--------------------------------------------------------------------------
| ACTIVE CONNECTIONS
|--------------------------------------------------------------------------
*/

$activeConnections = 0;

$activeQuery = "
    SELECT COUNT(*) AS total
    FROM device_connections
    WHERE connection_status = 'Active'
";

$activeResult = $conn->query($activeQuery);

if ($activeResult) {
    $activeConnections = (int)$activeResult->fetch_assoc()['total'];
}

/*
|--------------------------------------------------------------------------
| BUILD SEARCH QUERY
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];
$types = '';

if ($eventFilter !== '') {
    $where[] = "re.event_type = ?";
    $params[] = $eventFilter;
    $types .= 's';
}

if ($search !== '') {
    $where[] = "(
        u.fullname LIKE ?
        OR u.phone LIKE ?
        OR cd.device_name LIKE ?
        OR ap_from.access_point_name LIKE ?
        OR ap_to.access_point_name LIKE ?
        OR re.event_description LIKE ?
    )";

    $searchValue = "%{$search}%";

    for ($i = 0; $i < 6; $i++) {
        $params[] = $searchValue;
        $types .= 's';
    }
}

$whereSQL = '';

if (!empty($where)) {
    $whereSQL = 'WHERE ' . implode(' AND ', $where);
}

/*
|--------------------------------------------------------------------------
| TOTAL FILTERED RECORDS
|--------------------------------------------------------------------------
*/

$countSQL = "
    SELECT COUNT(*) AS total
    FROM roaming_events re
    LEFT JOIN customer_devices cd
        ON re.device_id = cd.id
    LEFT JOIN users u
        ON cd.user_id = u.id
    LEFT JOIN access_points ap_from
        ON re.from_access_point_id = ap_from.id
    LEFT JOIN access_points ap_to
        ON re.to_access_point_id = ap_to.id
    {$whereSQL}
";

$countStmt = $conn->prepare($countSQL);

if ($countStmt) {
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();

    $totalRecords = (int)$countStmt->get_result()->fetch_assoc()['total'];

    $countStmt->close();
} else {
    $totalRecords = 0;
}

$totalPages = max(1, (int)ceil($totalRecords / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

/*
|--------------------------------------------------------------------------
| FETCH ROAMING EVENTS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        re.id,
        re.connection_id,
        re.device_id,
        re.from_access_point_id,
        re.to_access_point_id,
        re.event_type,
        re.event_description,
        re.occurred_at,

        u.fullname,
        u.phone,

        cd.device_name,
        cd.device_type,

        ap_from.access_point_name AS from_access_point,
        ap_to.access_point_name AS to_access_point

    FROM roaming_events re

    LEFT JOIN customer_devices cd
        ON re.device_id = cd.id

    LEFT JOIN users u
        ON cd.user_id = u.id

    LEFT JOIN access_points ap_from
        ON re.from_access_point_id = ap_from.id

    LEFT JOIN access_points ap_to
        ON re.to_access_point_id = ap_to.id

    {$whereSQL}

    ORDER BY re.occurred_at DESC, re.id DESC

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

$events = [];

if ($stmt) {
    $queryTypes = $types . 'ii';

    $queryParams = $params;
    $queryParams[] = $limit;
    $queryParams[] = $offset;

    $stmt->bind_param($queryTypes, ...$queryParams);

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| RECENT ACCESS POINTS
|--------------------------------------------------------------------------
*/

$accessPoints = [];

$apQuery = "
    SELECT
        ap.id,
        ap.access_point_name,
        ap.identity_code,
        ap.location,
        ap.status
    FROM access_points ap
    ORDER BY ap.id DESC
    LIMIT 8
";

$apResult = $conn->query($apQuery);

if ($apResult) {
    while ($row = $apResult->fetch_assoc()) {
        $accessPoints[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| ROUTER MODE
|--------------------------------------------------------------------------
*/

$routerMode = 'Simulation';

$modeQuery = "
    SELECT setting_value
    FROM hotspot_settings
    WHERE setting_key = 'router_mode'
    LIMIT 1
";

$modeResult = $conn->query($modeQuery);

if ($modeResult && $modeResult->num_rows > 0) {
    $routerMode = $modeResult->fetch_assoc()['setting_value'];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Roaming Management | HOTNET</title>

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
            --soft-green: #f0fdf4;
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
            color: #fff !important;
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
                    rgba(20, 83, 45, .94),
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
            border: none;
            border-radius: 16px;
            padding: 22px;
            background: white;
            box-shadow: 0 5px 18px rgba(0, 0, 0, .05);
            height: 100%;
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
            margin-bottom: 14px;
        }

        .stat-card h3 {
            font-size: 28px;
            font-weight: 800;
            margin: 0;
            color: var(--dark);
        }

        .stat-card p {
            color: #718078;
            margin: 5px 0 0;
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

        .card-box {
            background: white;
            border-radius: 16px;
            padding: 23px;
            box-shadow: 0 5px 18px rgba(0, 0, 0, .045);
            margin-bottom: 25px;
        }

        .card-title {
            color: var(--forest);
            font-weight: 800;
            margin-bottom: 18px;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 11px 13px;
            border-color: #dce9df;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #198754;
            box-shadow: 0 0 0 .2rem rgba(25, 135, 84, .12);
        }

        .btn-green {
            background: linear-gradient(120deg, #166534, #198754);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 18px;
            font-weight: 700;
        }

        .btn-green:hover {
            background: #14532d;
            color: white;
        }

        .table thead th {
            background: #ecfdf3;
            color: #14532d;
            font-size: 13px;
            white-space: nowrap;
            padding: 14px;
        }

        .table tbody td {
            padding: 14px;
            vertical-align: middle;
        }

        .event-badge {
            border-radius: 30px;
            padding: 7px 12px;
            font-size: 11px;
            font-weight: 800;
        }

        .access-point {
            border: 1px solid #e2eee5;
            border-radius: 12px;
            padding: 13px;
            margin-bottom: 10px;
            background: #fbfefb;
        }

        .access-point strong {
            color: var(--forest);
        }

        .small-muted {
            font-size: 12px;
            color: #718078;
        }

        .empty-state {
            padding: 45px 15px;
            text-align: center;
            color: #718078;
        }

        .empty-state i {
            font-size: 45px;
            color: #86b99a;
        }

        .pagination .page-link {
            color: #166534;
            border-radius: 8px;
            margin: 0 3px;
        }

        .pagination .active .page-link {
            background: #166534;
            border-color: #166534;
            color: white;
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

            .card-box {
                padding: 16px;
            }

            .table {
                min-width: 900px;
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
                    <a class="nav-link" href="users.php">
                        <i class="bi bi-people"></i> Customers
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
                    <a class="nav-link" href="device_connections.php">
                        <i class="bi bi-wifi"></i> Connections
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="roaming_management.php">
                        <i class="bi bi-arrow-left-right"></i> Roaming
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
                    <i class="bi bi-arrow-left-right"></i>
                    Device Roaming Management
                </h2>

                <p class="mb-0">
                    Monitor customer device movement, reconnections and
                    access-point activity across HOTNET.
                </p>

            </div>

            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">

                <span class="badge bg-light text-success p-2">
                    <i class="bi bi-router"></i>
                    Router Mode: <?= h($routerMode) ?>
                </span>

            </div>

        </div>

    </section>


    <!-- STATISTICS -->

    <section class="row g-3 mb-4">

        <div class="col-6 col-lg-2">
            <div class="stat-card">

                <div class="stat-icon green-icon">
                    <i class="bi bi-arrow-left-right"></i>
                </div>

                <h3><?= number_format((int)$stats['total']) ?></h3>
                <p>Total Events</p>

            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="stat-card">

                <div class="stat-icon green-icon">
                    <i class="bi bi-wifi"></i>
                </div>

                <h3><?= number_format((int)$stats['connected']) ?></h3>
                <p>Connected Events</p>

            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="stat-card">

                <div class="stat-icon blue-icon">
                    <i class="bi bi-signpost-split"></i>
                </div>

                <h3><?= number_format((int)$stats['roamed']) ?></h3>
                <p>Roaming Events</p>

            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="stat-card">

                <div class="stat-icon purple-icon">
                    <i class="bi bi-arrow-repeat"></i>
                </div>

                <h3><?= number_format((int)$stats['reconnected']) ?></h3>
                <p>Reconnections</p>

            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="stat-card">

                <div class="stat-icon orange-icon">
                    <i class="bi bi-wifi-off"></i>
                </div>

                <h3><?= number_format((int)$stats['disconnected']) ?></h3>
                <p>Disconnections</p>

            </div>
        </div>

        <div class="col-6 col-lg-2">
            <div class="stat-card">

                <div class="stat-icon blue-icon">
                    <i class="bi bi-broadcast"></i>
                </div>

                <h3><?= number_format($activeConnections) ?></h3>
                <p>Active Connections</p>

            </div>
        </div>

    </section>


    <!-- SIMULATION NOTICE -->

    <?php if (strtolower($routerMode) === 'simulation'): ?>

        <div class="alert alert-info border-0 shadow-sm rounded-3">

            <i class="bi bi-info-circle-fill"></i>

            <strong>Simulation Mode:</strong>

            Roaming records are currently monitored using the HOTNET
            simulation database. Actual automatic WiFi roaming requires
            compatible router hardware and hotspot integration.

        </div>

    <?php endif; ?>


    <div class="row g-4">

        <!-- ROAMING EVENTS -->

        <div class="col-lg-9">

            <div class="card-box">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                    <h5 class="card-title mb-0">
                        <i class="bi bi-clock-history"></i>
                        Roaming Activity History
                    </h5>

                    <span class="badge bg-success-subtle text-success">
                        <?= number_format($totalRecords) ?> records
                    </span>

                </div>


                <!-- SEARCH AND FILTER -->

                <form method="GET" class="row g-2 mb-4">

                    <div class="col-md-6">

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            placeholder="Search customer, phone, device or access point..."
                            value="<?= h($search) ?>"
                        >

                    </div>

                    <div class="col-md-3">

                        <select name="event" class="form-select">

                            <option value="">All Event Types</option>

                            <?php foreach ($allowedEvents as $event): ?>

                                <option
                                    value="<?= h($event) ?>"
                                    <?= $eventFilter === $event ? 'selected' : '' ?>
                                >
                                    <?= h(eventLabel($event)) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-3 d-flex gap-2">

                        <button type="submit" class="btn btn-green flex-grow-1">
                            <i class="bi bi-search"></i> Search
                        </button>

                        <a href="roaming_management.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>

                    </div>

                </form>


                <!-- EVENTS TABLE -->

                <?php if (!empty($events)): ?>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead>

                                <tr>
                                    <th>#</th>
                                    <th>Customer</th>
                                    <th>Device</th>
                                    <th>Event</th>
                                    <th>From Access Point</th>
                                    <th>To Access Point</th>
                                    <th>Description</th>
                                    <th>Date & Time</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($events as $index => $event): ?>

                                    <tr>

                                        <td>
                                            <?= $offset + $index + 1 ?>
                                        </td>

                                        <td>

                                            <strong>
                                                <?= h($event['fullname'] ?: 'Unknown Customer') ?>
                                            </strong>

                                            <div class="small-muted">
                                                <?= h($event['phone'] ?: 'No phone recorded') ?>
                                            </div>

                                        </td>

                                        <td>

                                            <strong>
                                                <?= h($event['device_name'] ?: 'Unknown Device') ?>
                                            </strong>

                                            <div class="small-muted">
                                                <?= h($event['device_type'] ?: 'Not recorded') ?>
                                            </div>

                                        </td>

                                        <td>

                                            <span class="badge bg-<?= h(eventClass($event['event_type'])) ?> event-badge">

                                                <?= h(eventLabel($event['event_type'])) ?>

                                            </span>

                                        </td>

                                        <td>
                                            <?= h($event['from_access_point'] ?: 'N/A') ?>
                                        </td>

                                        <td>
                                            <?= h($event['to_access_point'] ?: 'N/A') ?>
                                        </td>

                                        <td>
                                            <span class="small-muted">
                                                <?= h($event['event_description'] ?: 'No description') ?>
                                            </span>
                                        </td>

                                        <td class="small-muted">
                                            <?= h($event['occurred_at']) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>


                    <!-- PAGINATION -->

                    <?php if ($totalPages > 1): ?>

                        <nav class="mt-4">

                            <ul class="pagination justify-content-center flex-wrap">

                                <?php
                                $queryParams = [
                                    'search' => $search,
                                    'event' => $eventFilter
                                ];
                                ?>

                                <?php if ($page > 1): ?>

                                    <li class="page-item">

                                        <a
                                            class="page-link"
                                            href="?<?= h(http_build_query(array_merge($queryParams, ['page' => $page - 1]))) ?>"
                                        >
                                            Previous
                                        </a>

                                    </li>

                                <?php endif; ?>


                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>

                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">

                                        <a
                                            class="page-link"
                                            href="?<?= h(http_build_query(array_merge($queryParams, ['page' => $p]))) ?>"
                                        >
                                            <?= $p ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>


                                <?php if ($page < $totalPages): ?>

                                    <li class="page-item">

                                        <a
                                            class="page-link"
                                            href="?<?= h(http_build_query(array_merge($queryParams, ['page' => $page + 1]))) ?>"
                                        >
                                            Next
                                        </a>

                                    </li>

                                <?php endif; ?>

                            </ul>

                        </nav>

                    <?php endif; ?>


                <?php else: ?>

                    <div class="empty-state">

                        <i class="bi bi-tree"></i>

                        <h5 class="mt-3">No roaming events found</h5>

                        <p>
                            Roaming and reconnection activity will appear here
                            when records are created.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- ACCESS POINTS SIDEBAR -->

        <div class="col-lg-3">

            <div class="card-box">

                <h5 class="card-title">
                    <i class="bi bi-broadcast-pin"></i>
                    Access Points
                </h5>

                <?php if (!empty($accessPoints)): ?>

                    <?php foreach ($accessPoints as $ap): ?>

                        <div class="access-point">

                            <div class="d-flex justify-content-between gap-2">

                                <strong>
                                    <?= h($ap['access_point_name']) ?>
                                </strong>

                                <?php if ($ap['status'] === 'Online'): ?>

                                    <span class="badge bg-success">Online</span>

                                <?php elseif ($ap['status'] === 'Simulation'): ?>

                                    <span class="badge bg-info text-dark">Simulation</span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">Offline</span>

                                <?php endif; ?>

                            </div>

                            <div class="small-muted mt-2">
                                Code: <?= h($ap['identity_code']) ?>
                            </div>

                            <div class="small-muted">
                                Location: <?= h($ap['location'] ?: 'Not specified') ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state py-3">

                        <i class="bi bi-broadcast"></i>

                        <p class="mt-2 mb-0">
                            No access points registered yet.
                        </p>

                    </div>

                <?php endif; ?>


                <a href="router_management.php" class="btn btn-green w-100 mt-3">

                    <i class="bi bi-gear"></i>
                    Manage Routers

                </a>

            </div>


            <!-- ROAMING INFORMATION -->

            <div class="card-box">

                <h6 class="card-title">
                    <i class="bi bi-info-circle"></i>
                    About Roaming
                </h6>

                <p class="small-muted">

                    Roaming allows a connected device to move between
                    compatible access points while maintaining network access.

                </p>

                <hr>

                <p class="small-muted mb-0">

                    HOTNET currently records roaming events in its database.
                    Actual automatic roaming will require router hardware
                    and a configured hotspot service.

                </p>

            </div>

        </div>

    </div>

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