<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| HOTNET ROAMING MANAGEMENT
|--------------------------------------------------------------------------
| File: hotspot/roaming.php
| Purpose: View and record customer roaming events.
|--------------------------------------------------------------------------
*/

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| ADMIN ACCESS
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['admin'])) {
    header('Location: ../auth/login.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$eventType = trim($_GET['event_type'] ?? '');

$allowedEvents = [
    'Connected',
    'Roamed',
    'Reconnected',
    'Disconnected'
];

if (!in_array($eventType, $allowedEvents, true)) {
    $eventType = '';
}

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$perPage = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| BUILD FILTER CONDITIONS
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(
        u.fullname LIKE ?
        OR u.phone LIKE ?
        OR v.voucher_code LIKE ?
        OR cd.device_name LIKE ?
        OR ap.access_point_name LIKE ?
    )";

    $term = "%{$search}%";

    $params = array_merge(
        $params,
        [$term, $term, $term, $term, $term]
    );

    $types .= 'sssss';
}

if ($eventType !== '') {
    $where[] = "re.event_type = ?";
    $params[] = $eventType;
    $types .= 's';
}

$whereSQL = count($where) > 0
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'connected' => 0,
    'roamed' => 0,
    'reconnected' => 0,
    'disconnected' => 0
];

$statsResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(event_type = 'Connected') AS connected,
        SUM(event_type = 'Roamed') AS roamed,
        SUM(event_type = 'Reconnected') AS reconnected,
        SUM(event_type = 'Disconnected') AS disconnected
    FROM roaming_events
");

if ($statsResult) {
    $stats = $statsResult->fetch_assoc();
}

/*
|--------------------------------------------------------------------------
| TOTAL FILTERED RECORDS
|--------------------------------------------------------------------------
*/

$countSQL = "
    SELECT COUNT(*) AS total
    FROM roaming_events re
    LEFT JOIN device_connections dc
        ON re.connection_id = dc.id
    LEFT JOIN users u
        ON dc.user_id = u.id
    LEFT JOIN vouchers v
        ON dc.voucher_id = v.id
    LEFT JOIN customer_devices cd
        ON re.device_id = cd.id
    LEFT JOIN access_points ap
        ON re.to_access_point_id = ap.id
    $whereSQL
";

$countStmt = $conn->prepare($countSQL);

if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();

$totalRecords = (int) (
    $countStmt->get_result()->fetch_assoc()['total'] ?? 0
);

$totalPages = max(1, (int) ceil($totalRecords / $perPage));

/*
|--------------------------------------------------------------------------
| FETCH ROAMING EVENTS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        re.id,
        re.connection_id,
        re.event_type,
        re.event_description,
        re.occurred_at,

        u.fullname,
        u.phone,

        v.voucher_code,

        cd.device_name,
        cd.device_type,

        old_ap.access_point_name AS from_access_point,
        new_ap.access_point_name AS to_access_point

    FROM roaming_events re

    LEFT JOIN device_connections dc
        ON re.connection_id = dc.id

    LEFT JOIN users u
        ON dc.user_id = u.id

    LEFT JOIN vouchers v
        ON dc.voucher_id = v.id

    LEFT JOIN customer_devices cd
        ON re.device_id = cd.id

    LEFT JOIN access_points old_ap
        ON re.from_access_point_id = old_ap.id

    LEFT JOIN access_points new_ap
        ON re.to_access_point_id = new_ap.id

    $whereSQL

    ORDER BY re.occurred_at DESC

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

$finalParams = $params;
$finalTypes = $types . 'ii';

$finalParams[] = $perPage;
$finalParams[] = $offset;

$stmt->bind_param($finalTypes, ...$finalParams);
$stmt->execute();

$events = $stmt->get_result();

/*
|--------------------------------------------------------------------------
| EVENT BADGE
|--------------------------------------------------------------------------
*/

function eventBadge($event)
{
    $classes = [
        'Connected' => 'connected',
        'Roamed' => 'roamed',
        'Reconnected' => 'reconnected',
        'Disconnected' => 'disconnected'
    ];

    $class = $classes[$event] ?? 'unknown';

    return '<span class="event-badge ' . $class . '">' .
        h($event) .
        '</span>';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>HOTNET | Roaming Management</title>

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

        .topbar {
            background: linear-gradient(
                120deg,
                #14532d,
                #166534,
                #22c55e
            );

            color: white;
            padding: 22px 28px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .12);
        }

        .topbar h3 {
            font-weight: 800;
            margin: 0;
        }

        .topbar p {
            margin: 5px 0 0;
            color: #dcfce7;
        }

        .main-container {
            padding: 28px;
            max-width: 1600px;
            margin: auto;
        }

        .navigation {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 25px;
        }

        .navigation a {
            text-decoration: none;
            background: white;
            color: var(--forest);
            border: 1px solid #bbf7d0;
            padding: 10px 15px;
            border-radius: 9px;
            font-weight: 600;
        }

        .navigation a:hover,
        .navigation a.active {
            background: var(--forest);
            color: white;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 22px;
            border: 1px solid #dcfce7;
            box-shadow: 0 5px 18px rgba(20, 83, 45, .06);
            height: 100%;
        }

        .stat-card .icon {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: var(--light-green);
            color: var(--forest);
            font-size: 20px;
            margin-bottom: 13px;
        }

        .stat-card h3 {
            font-size: 27px;
            font-weight: 800;
            margin: 0;
        }

        .stat-card p {
            color: #64748b;
            margin: 5px 0 0;
        }

        .content-card {
            background: white;
            border-radius: 15px;
            padding: 23px;
            margin-top: 25px;
            border: 1px solid #dcfce7;
            box-shadow: 0 5px 18px rgba(20, 83, 45, .05);
        }

        .content-card h5 {
            color: var(--forest);
            font-weight: 800;
        }

        .form-control,
        .form-select {
            border-radius: 9px;
            padding: 11px;
            border-color: #bbf7d0;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 .2rem rgba(34, 197, 94, .12);
        }

        .btn-green {
            background: var(--forest);
            color: white;
            border: none;
            border-radius: 9px;
            padding: 11px 18px;
            font-weight: 700;
        }

        .btn-green:hover {
            background: #166534;
            color: white;
        }

        .table-responsive {
            border-radius: 10px;
        }

        table {
            min-width: 900px;
        }

        thead th {
            background: #14532d !important;
            color: white !important;
            padding: 14px !important;
            white-space: nowrap;
        }

        tbody td {
            padding: 13px !important;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #f0fdf4;
        }

        .event-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .connected {
            background: #dcfce7;
            color: #166534;
        }

        .roamed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .reconnected {
            background: #fef3c7;
            color: #92400e;
        }

        .disconnected {
            background: #fee2e2;
            color: #991b1b;
        }

        .unknown {
            background: #e2e8f0;
            color: #334155;
        }

        .location {
            color: #166534;
            font-weight: 600;
        }

        .pagination .page-link {
            color: var(--forest);
            border-color: #bbf7d0;
        }

        .pagination .active .page-link {
            background: var(--forest);
            border-color: var(--forest);
            color: white;
        }

        .empty-state {
            padding: 45px 15px;
            text-align: center;
            color: #64748b;
        }

        .empty-state i {
            font-size: 42px;
            color: #86efac;
            margin-bottom: 15px;
        }

        .footer {
            text-align: center;
            padding: 25px;
            color: #64748b;
        }

        @media(max-width: 768px) {

            .main-container {
                padding: 15px;
            }

            .topbar {
                padding: 20px 15px;
            }

            .content-card {
                padding: 15px;
            }

        }

    </style>

</head>

<body>

<!-- HEADER -->

<header class="topbar">

    <div class="container-fluid">

        <h3>
            <i class="fa-solid fa-leaf"></i>
            HOTNET ROAMING MANAGEMENT
        </h3>

        <p>
            Manage customer movement between WiFi access points.
        </p>

    </div>

</header>


<main class="main-container">

    <!-- NAVIGATION -->

    <nav class="navigation">

        <a href="../admin/dashboard.php">
            <i class="fa-solid fa-gauge"></i>
            Dashboard
        </a>

        <a href="../admin/router_management.php">
            <i class="fa-solid fa-network-wired"></i>
            Routers
        </a>

        <a href="../admin/device_connections.php">
            <i class="fa-solid fa-mobile-screen"></i>
            Connections
        </a>

        <a href="../admin/roaming_management.php">
            <i class="fa-solid fa-route"></i>
            Roaming
        </a>

        <a href="session_monitor.php">
            <i class="fa-solid fa-chart-line"></i>
            Sessions
        </a>

        <a href="index.php">
            <i class="fa-solid fa-wifi"></i>
            Hotspot
        </a>

    </nav>


    <!-- STATISTICS -->

    <section class="row g-3">

        <div class="col-6 col-lg">

            <div class="stat-card">

                <div class="icon">
                    <i class="fa-solid fa-list"></i>
                </div>

                <h3><?= number_format((int) $stats['total']) ?></h3>

                <p>Total Events</p>

            </div>

        </div>


        <div class="col-6 col-lg">

            <div class="stat-card">

                <div class="icon">
                    <i class="fa-solid fa-wifi"></i>
                </div>

                <h3><?= number_format((int) $stats['connected']) ?></h3>

                <p>Connected</p>

            </div>

        </div>


        <div class="col-6 col-lg">

            <div class="stat-card">

                <div class="icon">
                    <i class="fa-solid fa-route"></i>
                </div>

                <h3><?= number_format((int) $stats['roamed']) ?></h3>

                <p>Roamed</p>

            </div>

        </div>


        <div class="col-6 col-lg">

            <div class="stat-card">

                <div class="icon">
                    <i class="fa-solid fa-rotate"></i>
                </div>

                <h3><?= number_format((int) $stats['reconnected']) ?></h3>

                <p>Reconnected</p>

            </div>

        </div>


        <div class="col-6 col-lg">

            <div class="stat-card">

                <div class="icon">
                    <i class="fa-solid fa-power-off"></i>
                </div>

                <h3><?= number_format((int) $stats['disconnected']) ?></h3>

                <p>Disconnected</p>

            </div>

        </div>

    </section>


    <!-- SEARCH AND FILTER -->

    <section class="content-card">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <h5>
                <i class="fa-solid fa-filter"></i>
                Search Roaming Records
            </h5>

            <span class="text-muted small">
                <?= number_format($totalRecords) ?> matching records
            </span>

        </div>


        <form method="GET" class="row g-3">

            <div class="col-md-6">

                <label class="form-label fw-bold">
                    Search
                </label>

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Customer, phone, voucher or device..."
                    value="<?= h($search) ?>"
                >

            </div>


            <div class="col-md-4">

                <label class="form-label fw-bold">
                    Event Type
                </label>

                <select name="event_type" class="form-select">

                    <option value="">All Events</option>

                    <?php foreach ($allowedEvents as $event): ?>

                        <option
                            value="<?= h($event) ?>"
                            <?= $eventType === $event ? 'selected' : '' ?>
                        >

                            <?= h($event) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-2 d-flex align-items-end">

                <button type="submit" class="btn btn-green w-100">

                    <i class="fa-solid fa-search"></i>
                    Search

                </button>

            </div>

        </form>

    </section>


    <!-- ROAMING HISTORY -->

    <section class="content-card">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <h5>
                <i class="fa-solid fa-clock-rotate-left"></i>
                Roaming Activity History
            </h5>

            <a href="roaming.php" class="btn btn-outline-success btn-sm">

                <i class="fa-solid fa-refresh"></i>
                Reset Filters

            </a>

        </div>


        <div class="table-responsive">

            <table class="table table-bordered table-hover align-middle">

                <thead>

                    <tr>

                        <th>#</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Voucher</th>
                        <th>Device</th>
                        <th>Event</th>
                        <th>From Access Point</th>
                        <th>To Access Point</th>
                        <th>Date and Time</th>

                    </tr>

                </thead>


                <tbody>

                    <?php if ($events->num_rows > 0): ?>

                        <?php $number = $offset + 1; ?>

                        <?php while ($event = $events->fetch_assoc()): ?>

                            <tr>

                                <td><?= $number++ ?></td>

                                <td>

                                    <?= h($event['fullname'] ?: 'Unknown customer') ?>

                                </td>

                                <td>

                                    <?= h($event['phone'] ?: 'N/A') ?>

                                </td>

                                <td>

                                    <span class="fw-bold">

                                        <?= h($event['voucher_code'] ?: 'N/A') ?>

                                    </span>

                                </td>

                                <td>

                                    <?= h($event['device_name'] ?: 'Unknown device') ?>

                                    <?php if (!empty($event['device_type'])): ?>

                                        <br>

                                        <small class="text-muted">

                                            <?= h($event['device_type']) ?>

                                        </small>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?= eventBadge($event['event_type']) ?>

                                </td>

                                <td class="location">

                                    <?= h($event['from_access_point'] ?: '—') ?>

                                </td>

                                <td class="location">

                                    <?= h($event['to_access_point'] ?: '—') ?>

                                </td>

                                <td>

                                    <?= h(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime($event['occurred_at'])
                                        )
                                    ) ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="9">

                                <div class="empty-state">

                                    <i class="fa-solid fa-leaf"></i>

                                    <h5>No roaming records found</h5>

                                    <p>
                                        Roaming events will appear here when
                                        hotspot activity is recorded.
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

            <nav class="mt-4">

                <ul class="pagination justify-content-center flex-wrap">

                    <?php

                    $queryParams = [
                        'search' => $search,
                        'event_type' => $eventType
                    ];

                    ?>

                    <?php if ($page > 1): ?>

                        <li class="page-item">

                            <a
                                class="page-link"
                                href="?<?= h(http_build_query(
                                    array_merge($queryParams, ['page' => $page - 1])
                                )) ?>"
                            >

                                Previous

                            </a>

                        </li>

                    <?php endif; ?>


                    <?php for (
                        $i = max(1, $page - 2);
                        $i <= min($totalPages, $page + 2);
                        $i++
                    ): ?>

                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">

                            <a
                                class="page-link"
                                href="?<?= h(http_build_query(
                                    array_merge($queryParams, ['page' => $i])
                                )) ?>"
                            >

                                <?= $i ?>

                            </a>

                        </li>

                    <?php endfor; ?>


                    <?php if ($page < $totalPages): ?>

                        <li class="page-item">

                            <a
                                class="page-link"
                                href="?<?= h(http_build_query(
                                    array_merge($queryParams, ['page' => $page + 1])
                                )) ?>"
                            >

                                Next

                            </a>

                        </li>

                    <?php endif; ?>

                </ul>

            </nav>

        <?php endif; ?>

    </section>


    <div class="footer">

        <i class="fa-solid fa-leaf text-success"></i>

        HOTNET WiFi Management System

        <br>

        <small>
            Roaming management operates in simulation mode until router hardware
            is connected and configured.
        </small>

    </div>

</main>

</body>
</html>