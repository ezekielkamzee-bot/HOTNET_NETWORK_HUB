<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| HOTNET SESSION MONITOR
|--------------------------------------------------------------------------
| File: hotspot/session_monitor.php
|
| Features:
| - Monitor active and disconnected sessions.
| - Display customer and device details.
| - Display voucher and package information.
| - Calculate connection duration.
| - Display recorded data usage.
|
| NOTE:
| Traffic figures are recorded values. Real-time network traffic
| requires MikroTik or another connected hotspot router.
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| SEARCH AND FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'All');

$allowedStatuses = [
    'All',
    'Active',
    'Disconnected',
    'Expired',
    'Revoked',
    'Failed'
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'All';
}

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$page = max(1, (int) ($_GET['page'] ?? 1));

$limit = 15;

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| SESSION STATISTICS
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'active' => 0,
    'disconnected' => 0,
    'expired' => 0,
    'revoked' => 0,
    'failed' => 0,
    'total_in' => 0,
    'total_out' => 0
];

$statsQuery = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(connection_status = 'Active'), 0) AS active,
        COALESCE(SUM(connection_status = 'Disconnected'), 0) AS disconnected,
        COALESCE(SUM(connection_status = 'Expired'), 0) AS expired,
        COALESCE(SUM(connection_status = 'Revoked'), 0) AS revoked,
        COALESCE(SUM(connection_status = 'Failed'), 0) AS failed,
        COALESCE(SUM(bytes_in), 0) AS total_in,
        COALESCE(SUM(bytes_out), 0) AS total_out
     FROM device_connections"
);

if ($statsQuery) {
    $stats = array_merge(
        $stats,
        mysqli_fetch_assoc($statsQuery)
    );
}

/*
|--------------------------------------------------------------------------
| BUILD SEARCH QUERY
|--------------------------------------------------------------------------
*/

$where = [];

$params = [];

$types = '';

if ($statusFilter !== 'All') {

    $where[] = "dc.connection_status = ?";

    $params[] = $statusFilter;

    $types .= 's';
}

if ($search !== '') {

    $where[] = "(
        u.fullname LIKE ?
        OR u.phone LIKE ?
        OR v.voucher_code LIKE ?
        OR p.package_name LIKE ?
        OR cd.device_name LIKE ?
        OR dc.ip_address LIKE ?
    )";

    $keyword = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {
        $params[] = $keyword;
    }

    $types .= 'ssssss';
}

$whereSQL = '';

if (!empty($where)) {
    $whereSQL = 'WHERE ' . implode(' AND ', $where);
}

/*
|--------------------------------------------------------------------------
| COUNT FILTERED RECORDS
|--------------------------------------------------------------------------
*/

$countSQL = "
    SELECT COUNT(*) AS total
    FROM device_connections dc
    LEFT JOIN users u ON u.id = dc.user_id
    LEFT JOIN vouchers v ON v.id = dc.voucher_id
    LEFT JOIN packages p ON p.id = dc.package_id
    LEFT JOIN customer_devices cd ON cd.id = dc.device_id
    $whereSQL
";

$countStmt = mysqli_prepare($conn, $countSQL);

if (!$countStmt) {
    die('Unable to prepare session count query.');
}

if (!empty($params)) {

    mysqli_stmt_bind_param(
        $countStmt,
        $types,
        ...$params
    );
}

mysqli_stmt_execute($countStmt);

$countResult = mysqli_stmt_get_result($countStmt);

$totalRecords = (int) mysqli_fetch_assoc($countResult)['total'];

mysqli_stmt_close($countStmt);

$totalPages = max(
    1,
    (int) ceil($totalRecords / $limit)
);

$page = min($page, $totalPages);

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| FETCH SESSION RECORDS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        dc.id,
        dc.user_id,
        dc.voucher_id,
        dc.package_id,
        dc.device_id,
        dc.connection_mode,
        dc.connection_status,
        dc.ip_address,
        dc.bytes_in,
        dc.bytes_out,
        dc.started_at,
        dc.ended_at,
        dc.last_seen_at,
        dc.disconnect_reason,

        u.fullname,
        u.phone,

        v.voucher_code,

        p.package_name,
        p.speed,

        cd.device_name,
        cd.device_type,

        ap.access_point_name,

        rn.router_name

    FROM device_connections dc

    LEFT JOIN users u
        ON u.id = dc.user_id

    LEFT JOIN vouchers v
        ON v.id = dc.voucher_id

    LEFT JOIN packages p
        ON p.id = dc.package_id

    LEFT JOIN customer_devices cd
        ON cd.id = dc.device_id

    LEFT JOIN access_points ap
        ON ap.id = dc.access_point_id

    LEFT JOIN router_nodes rn
        ON rn.id = dc.router_node_id

    $whereSQL

    ORDER BY dc.id DESC

    LIMIT ? OFFSET ?
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die('Unable to prepare session records query.');
}

$finalParams = $params;

$finalParams[] = $limit;
$finalParams[] = $offset;

$finalTypes = $types . 'ii';

mysqli_stmt_bind_param(
    $stmt,
    $finalTypes,
    ...$finalParams
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$sessions = [];

while ($row = mysqli_fetch_assoc($result)) {
    $sessions[] = $row;
}

mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| DATA FORMATTING
|--------------------------------------------------------------------------
*/

function formatBytes($bytes)
{
    $bytes = (float) $bytes;

    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $index = 0;

    while ($bytes >= 1024 && $index < 4) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, 2) . ' ' . $units[$index];
}

function formatDuration($start, $end = null)
{
    if (empty($start)) {
        return 'Not available';
    }

    try {

        $startTime = new DateTime($start);

        $endTime = $end
            ? new DateTime($end)
            : new DateTime();

        $difference = $startTime->diff($endTime);

        $parts = [];

        if ($difference->days > 0) {
            $parts[] = $difference->days . 'd';
        }

        if ($difference->h > 0) {
            $parts[] = $difference->h . 'h';
        }

        if ($difference->i > 0) {
            $parts[] = $difference->i . 'm';
        }

        if (empty($parts)) {
            $parts[] = $difference->s . 's';
        }

        return implode(' ', $parts);

    } catch (Throwable $error) {

        return 'Not available';
    }
}

function statusClass($status)
{
    switch ($status) {

        case 'Active':
            return 'active';

        case 'Disconnected':
            return 'disconnected';

        case 'Expired':
            return 'expired';

        case 'Revoked':
            return 'revoked';

        case 'Failed':
            return 'failed';

        default:
            return 'unknown';
    }
}

/*
|--------------------------------------------------------------------------
| PAGINATION URL
|--------------------------------------------------------------------------
*/

function pageUrl($pageNumber)
{
    $query = $_GET;

    $query['page'] = $pageNumber;

    return '?' . http_build_query($query);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>HOTNET Session Monitor</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f1f6f1;
            color: #263b2d;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 240px;
            background: #124b2b;
            padding: 25px 15px;
            color: white;
            overflow-y: auto;
        }

        .brand {
            text-align: center;
            margin-bottom: 35px;
        }

        .brand h2 {
            margin: 0;
            letter-spacing: 2px;
            color: #b9edc7;
        }

        .brand p {
            color: #d0e6d5;
            font-size: 12px;
        }

        .sidebar a {
            display: block;
            padding: 13px;
            margin-bottom: 7px;
            border-radius: 8px;
            text-decoration: none;
            color: #e5f3e9;
            font-size: 14px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #237545;
            color: white;
        }

        .main {
            margin-left: 240px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .topbar h1 {
            color: #176b3a;
            margin: 0;
        }

        .subtitle {
            color: #718078;
            margin-top: 7px;
        }

        .refresh {
            background: #176b3a;
            color: white;
            text-decoration: none;
            padding: 11px 17px;
            border-radius: 8px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 17px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border-left: 5px solid #23864c;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }

        .stat-card h3 {
            margin: 0;
            font-size: 13px;
            color: #718078;
        }

        .stat-card strong {
            display: block;
            font-size: 26px;
            margin-top: 12px;
            color: #176b3a;
        }

        .filters {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 22px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filters input,
        .filters select {
            padding: 12px;
            border: 1px solid #d4e0d6;
            border-radius: 8px;
            min-width: 190px;
            flex: 1;
        }

        .filters button {
            padding: 12px 22px;
            background: #176b3a;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        .table-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }

        .table-card h2 {
            color: #176b3a;
            margin-top: 0;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1250px;
        }

        th,
        td {
            padding: 13px 12px;
            border-bottom: 1px solid #e6eee7;
            text-align: left;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            background: #edf6ef;
            color: #315640;
            white-space: nowrap;
        }

        td strong {
            color: #244b35;
        }

        .muted {
            color: #819087;
            font-size: 12px;
            margin-top: 5px;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .active {
            background: #dff5e5;
            color: #176b3a;
        }

        .disconnected {
            background: #e9ecef;
            color: #56616b;
        }

        .expired {
            background: #fff0d6;
            color: #96620d;
        }

        .revoked,
        .failed {
            background: #fde4e4;
            color: #a12626;
        }

        .unknown {
            background: #eeeeee;
            color: #555555;
        }

        .empty {
            text-align: center;
            padding: 45px;
            color: #718078;
        }

        .pagination {
            display: flex;
            gap: 7px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .pagination a,
        .pagination span {
            padding: 9px 13px;
            border-radius: 7px;
            text-decoration: none;
            background: #edf6ef;
            color: #176b3a;
        }

        .pagination .current {
            background: #176b3a;
            color: white;
        }

        .notice {
            margin-top: 20px;
            padding: 15px;
            border-radius: 9px;
            background: #edf6ef;
            color: #385b43;
            font-size: 13px;
            line-height: 1.6;
        }

        @media (max-width: 850px) {

            .sidebar {
                position: static;
                width: 100%;
            }

            .main {
                margin-left: 0;
                padding: 18px;
            }

            .sidebar a {
                display: inline-block;
                margin-right: 5px;
            }
        }

    </style>

</head>

<body>

<aside class="sidebar">

    <div class="brand">

        <h2>HOTNET</h2>

        <p>WiFi Management</p>

    </div>

    <a href="../admin/dashboard.php">Dashboard</a>

    <a href="../admin/router_management.php">Router Management</a>

    <a href="../admin/device_connections.php">Device Connections</a>

    <a href="../admin/roaming_management.php">Roaming Management</a>

    <a href="session_monitor.php" class="active">Session Monitor</a>

    <a href="index.php">Hotspot Login</a>

    <a href="../admin/hotspot_settings.php">Hotspot Settings</a>

</aside>

<main class="main">

    <div class="topbar">

        <div>

            <h1>Session Monitor</h1>

            <p class="subtitle">
                Monitor HOTNET customer connection sessions.
            </p>

        </div>

        <a href="session_monitor.php" class="refresh">
            Refresh Sessions
        </a>

    </div>

    <section class="stats">

        <div class="stat-card">

            <h3>Total Sessions</h3>

            <strong><?= number_format((int) $stats['total']) ?></strong>

        </div>

        <div class="stat-card">

            <h3>Active Sessions</h3>

            <strong><?= number_format((int) $stats['active']) ?></strong>

        </div>

        <div class="stat-card">

            <h3>Disconnected</h3>

            <strong><?= number_format((int) $stats['disconnected']) ?></strong>

        </div>

        <div class="stat-card">

            <h3>Expired Sessions</h3>

            <strong><?= number_format((int) $stats['expired']) ?></strong>

        </div>

        <div class="stat-card">

            <h3>Data Received</h3>

            <strong><?= e(formatBytes($stats['total_in'])) ?></strong>

        </div>

        <div class="stat-card">

            <h3>Data Sent</h3>

            <strong><?= e(formatBytes($stats['total_out'])) ?></strong>

        </div>

    </section>

    <form method="GET" class="filters">

        <input
            type="text"
            name="search"
            placeholder="Search customer, voucher, device or IP"
            value="<?= e($search) ?>"
        >

        <select name="status">

            <?php foreach ($allowedStatuses as $status): ?>

                <option
                    value="<?= e($status) ?>"
                    <?= $statusFilter === $status ? 'selected' : '' ?>
                >
                    <?= e($status) ?>
                </option>

            <?php endforeach; ?>

        </select>

        <button type="submit">
            Search Sessions
        </button>

    </form>

    <section class="table-card">

        <h2>Connection Records</h2>

        <p class="subtitle">
            Showing <?= count($sessions) ?>
            of <?= number_format($totalRecords) ?> matching records.
        </p>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>ID</th>

                        <th>Customer</th>

                        <th>Device</th>

                        <th>Voucher</th>

                        <th>Package</th>

                        <th>Connection</th>

                        <th>IP Address</th>

                        <th>Started</th>

                        <th>Duration</th>

                        <th>Data Received</th>

                        <th>Data Sent</th>

                        <th>Last Activity</th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (empty($sessions)): ?>

                        <tr>

                            <td colspan="12" class="empty">

                                No connection records found.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($sessions as $session): ?>

                            <tr>

                                <td>
                                    #<?= (int) $session['id'] ?>
                                </td>

                                <td>

                                    <strong>
                                        <?= e($session['fullname'] ?? 'Unknown Customer') ?>
                                    </strong>

                                    <div class="muted">
                                        <?= e($session['phone'] ?? 'No phone') ?>
                                    </div>

                                </td>

                                <td>

                                    <strong>
                                        <?= e($session['device_name'] ?? 'Unregistered Device') ?>
                                    </strong>

                                    <div class="muted">
                                        <?= e($session['device_type'] ?? 'Unknown') ?>
                                    </div>

                                </td>

                                <td>
                                    <?= e($session['voucher_code'] ?? 'N/A') ?>
                                </td>

                                <td>

                                    <strong>
                                        <?= e($session['package_name'] ?? 'Unknown Package') ?>
                                    </strong>

                                    <div class="muted">
                                        <?= e($session['speed'] ?? 'N/A') ?>
                                    </div>

                                </td>

                                <td>

                                    <span class="badge <?= e(statusClass($session['connection_status'])) ?>">

                                        <?= e($session['connection_status']) ?>

                                    </span>

                                    <div class="muted">
                                        <?= e($session['connection_mode']) ?>
                                    </div>

                                </td>

                                <td>
                                    <?= e($session['ip_address'] ?? 'N/A') ?>
                                </td>

                                <td>
                                    <?= e($session['started_at'] ?? 'N/A') ?>
                                </td>

                                <td>

                                    <?= e(
                                        formatDuration(
                                            $session['started_at'],
                                            $session['ended_at']
                                        )
                                    ) ?>

                                </td>

                                <td>
                                    <?= e(formatBytes($session['bytes_in'])) ?>
                                </td>

                                <td>
                                    <?= e(formatBytes($session['bytes_out'])) ?>
                                </td>

                                <td>
                                    <?= e($session['last_seen_at'] ?? 'N/A') ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($totalPages > 1): ?>

            <div class="pagination">

                <?php if ($page > 1): ?>

                    <a href="<?= e(pageUrl($page - 1)) ?>">
                        Previous
                    </a>

                <?php endif; ?>

                <?php for (
                    $number = max(1, $page - 2);
                    $number <= min($totalPages, $page + 2);
                    $number++
                ): ?>

                    <?php if ($number === $page): ?>

                        <span class="current">
                            <?= $number ?>
                        </span>

                    <?php else: ?>

                        <a href="<?= e(pageUrl($number)) ?>">
                            <?= $number ?>
                        </a>

                    <?php endif; ?>

                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>

                    <a href="<?= e(pageUrl($page + 1)) ?>">
                        Next
                    </a>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </section>

    <div class="notice">

        <strong>HOTNET Monitoring Notice</strong><br>

        Session duration is calculated from recorded connection times.
        Data usage displays the values stored in the database.
        Actual live traffic monitoring requires a connected router
        such as MikroTik.

    </div>

</main>

</body>

</html>