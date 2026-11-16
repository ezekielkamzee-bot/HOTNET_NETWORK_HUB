<?php
session_start();

/*
|--------------------------------------------------------------------------
| HOTNET M-PESA CALLBACK MONITOR
|--------------------------------------------------------------------------
| File: admin/mpesa_callbacks.php
| Database table: mpesa_callback_inbox
| Theme: HOTNET Burning Fire
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

if (!function_exists('hotnetCallbackEscape')) {
    function hotnetCallbackEscape($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

function hotnetCallbackStatusClass($status)
{
    switch (strtolower((string) $status)) {
        case 'processed':
            return 'success';

        case 'pending':
            return 'warning';

        case 'failed':
            return 'danger';

        default:
            return 'secondary';
    }
}

/*
|--------------------------------------------------------------------------
| CALLBACK STATISTICS
|--------------------------------------------------------------------------
*/

$statsSql = "
    SELECT
        COUNT(*) AS total_callbacks,

        SUM(
            CASE
                WHEN processing_status = 'Pending'
                THEN 1 ELSE 0
            END
        ) AS pending_callbacks,

        SUM(
            CASE
                WHEN processing_status = 'Processed'
                THEN 1 ELSE 0
            END
        ) AS processed_callbacks,

        SUM(
            CASE
                WHEN processing_status = 'Failed'
                THEN 1 ELSE 0
            END
        ) AS failed_callbacks

    FROM mpesa_callback_inbox
";

$statsResult = mysqli_query($conn, $statsSql);

if (!$statsResult) {
    die(
        'Unable to load callback statistics. Check the mpesa_callback_inbox table.'
    );
}

$stats = mysqli_fetch_assoc($statsResult);

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$allowedStatuses = [
    'Pending',
    'Processed',
    'Failed'
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$perPage = 25;

$page = max(1, (int) ($_GET['page'] ?? 1));

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| BUILD SEARCH QUERY
|--------------------------------------------------------------------------
*/

$whereSql = " WHERE 1 = 1 ";

$filterTypes = '';
$filterParams = [];

if ($search !== '') {

    $whereSql .= "
        AND (
            checkout_request_id LIKE ?
            OR merchant_request_id LIKE ?
            OR last_error LIKE ?
            OR callback_payload LIKE ?
        )
    ";

    $searchTerm = "%{$search}%";

    $filterTypes .= 'ssss';

    $filterParams[] = $searchTerm;
    $filterParams[] = $searchTerm;
    $filterParams[] = $searchTerm;
    $filterParams[] = $searchTerm;
}

if ($statusFilter !== '') {

    $whereSql .= " AND processing_status = ? ";

    $filterTypes .= 's';
    $filterParams[] = $statusFilter;
}

/*
|--------------------------------------------------------------------------
| COUNT FILTERED CALLBACKS
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM mpesa_callback_inbox
    {$whereSql}
";

$countStmt = mysqli_prepare($conn, $countSql);

if (!$countStmt) {
    die('Unable to prepare callback count query.');
}

if ($filterTypes !== '') {

    $bindParams = [$filterTypes];

    foreach ($filterParams as $key => $value) {
        $bindParams[] = &$filterParams[$key];
    }

    call_user_func_array(
        'mysqli_stmt_bind_param',
        array_merge([$countStmt], $bindParams)
    );
}

mysqli_stmt_execute($countStmt);

$countResult = mysqli_stmt_get_result($countStmt);

$countData = mysqli_fetch_assoc($countResult);

$totalFiltered = (int) ($countData['total'] ?? 0);

mysqli_stmt_close($countStmt);

$totalPages = max(
    1,
    (int) ceil($totalFiltered / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| FETCH CALLBACK RECORDS
|--------------------------------------------------------------------------
*/

$callbacksSql = "
    SELECT
        id,
        checkout_request_id,
        merchant_request_id,
        callback_payload,
        processing_status,
        attempts,
        last_error,
        received_at,
        processed_at

    FROM mpesa_callback_inbox

    {$whereSql}

    ORDER BY received_at DESC, id DESC

    LIMIT ? OFFSET ?
";

$callbacksStmt = mysqli_prepare($conn, $callbacksSql);

if (!$callbacksStmt) {
    die('Unable to prepare callback history query.');
}

$finalTypes = $filterTypes . 'ii';

$finalParams = $filterParams;

$finalParams[] = $perPage;
$finalParams[] = $offset;

$bindParams = [$finalTypes];

foreach ($finalParams as $key => $value) {
    $bindParams[] = &$finalParams[$key];
}

call_user_func_array(
    'mysqli_stmt_bind_param',
    array_merge([$callbacksStmt], $bindParams)
);

mysqli_stmt_execute($callbacksStmt);

$callbacksResult = mysqli_stmt_get_result($callbacksStmt);

/*
|--------------------------------------------------------------------------
| PRESERVE FILTERS IN PAGINATION
|--------------------------------------------------------------------------
*/

$paginationParams = [
    'search' => $search,
    'status' => $statusFilter
];

$paginationQuery = http_build_query(
    array_filter(
        $paginationParams,
        function ($value) {
            return $value !== '';
        }
    )
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>HOTNET | M-Pesa Callbacks</title>

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
            --hotnet-orange: #ff6500;
            --hotnet-yellow: #ffb000;
            --hotnet-dark: #100d0b;
            --hotnet-card: #1d1916;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: #100e0c;
            color: #f5f5f5;
            font-family: Arial, sans-serif;
        }

        /* NAVIGATION */

        .navbar {
            background: #090807;
            border-bottom: 1px solid rgba(255, 101, 0, 0.35);
            padding: 15px 20px;
        }

        .navbar-brand {
            font-size: 25px;
            font-weight: 900;
            letter-spacing: 2px;
            color: white !important;
        }

        .navbar-brand span {
            color: var(--hotnet-orange);
        }

        .navbar .nav-link {
            color: #ddd;
            font-size: 14px;
            margin: 0 5px;
        }

        .navbar .nav-link:hover,
        .navbar .nav-link.active {
            color: var(--hotnet-orange);
        }

        /* FIRE HEADER */

        .fire-hero {
            position: relative;
            overflow: hidden;
            min-height: 220px;
            display: flex;
            align-items: center;
            padding: 35px;
            border-radius: 20px;
            margin-bottom: 30px;

            background:
                radial-gradient(
                    ellipse at bottom,
                    rgba(255, 74, 0, 0.45),
                    transparent 65%
                ),
                linear-gradient(
                    120deg,
                    #090807,
                    #251006,
                    #100d0b
                );

            border: 1px solid rgba(255, 101, 0, 0.4);
        }

        .fire-content {
            position: relative;
            z-index: 3;
        }

        .fire-title {
            font-size: clamp(28px, 5vw, 45px);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;

            background: linear-gradient(
                90deg,
                white,
                #ffb000,
                #ff5500
            );

            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .fire-subtitle {
            color: #ddd;
            max-width: 600px;
        }

        .fire-glow {
            position: absolute;
            right: 8%;
            bottom: -100px;
            width: 280px;
            height: 250px;

            background: radial-gradient(
                ellipse,
                rgba(255, 77, 0, 0.7),
                rgba(255, 120, 0, 0.15),
                transparent 70%
            );

            filter: blur(15px);
            animation: fireGlow 2s ease-in-out infinite alternate;
        }

        .flame {
            position: absolute;
            bottom: -35px;
            width: 55px;
            height: 130px;
            border-radius: 50% 50% 35% 35%;

            background: linear-gradient(
                to top,
                #ff2600,
                #ff6a00,
                #ffc000,
                transparent
            );

            filter: blur(7px);
            opacity: 0.7;
            transform-origin: bottom center;
            animation: flameMove 1.5s ease-in-out infinite alternate;
        }

        .flame-one {
            right: 20%;
            height: 150px;
        }

        .flame-two {
            right: 27%;
            height: 105px;
            width: 45px;
            animation-delay: 0.5s;
        }

        .flame-three {
            right: 13%;
            height: 115px;
            width: 45px;
            animation-delay: 0.8s;
        }

        @keyframes flameMove {
            from {
                transform: scaleY(0.85) rotate(-5deg);
                opacity: 0.55;
            }

            to {
                transform: scaleY(1.15) rotate(5deg);
                opacity: 0.9;
            }
        }

        @keyframes fireGlow {
            from {
                transform: scale(0.9);
                opacity: 0.6;
            }

            to {
                transform: scale(1.15);
                opacity: 1;
            }
        }

        /* STATISTICS */

        .stat-card {
            height: 100%;
            padding: 22px;
            border-radius: 15px;

            background: linear-gradient(
                145deg,
                #25201c,
                #15110e
            );

            border: 1px solid rgba(255, 101, 0, 0.18);

            box-shadow: 0 5px 18px rgba(0, 0, 0, 0.3);

            transition: 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--hotnet-orange);
            box-shadow: 0 0 20px rgba(255, 101, 0, 0.18);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: rgba(255, 101, 0, 0.13);
            color: var(--hotnet-orange);
            font-size: 23px;
        }

        .stat-number {
            font-size: 27px;
            font-weight: 800;
            margin-top: 12px;
            color: white;
        }

        .stat-label {
            color: #aaa;
            font-size: 13px;
        }

        /* CONTENT */

        .content-card {
            background: var(--hotnet-card);
            border: 1px solid rgba(255, 101, 0, 0.15);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.25);
        }

        .section-title {
            font-weight: 800;
            color: white;
        }

        .orange-text {
            color: var(--hotnet-orange);
        }

        /* FORMS */

        .form-control,
        .form-select {
            background: #100e0c;
            border: 1px solid #493126;
            color: white;
            border-radius: 8px;
            padding: 10px 12px;
        }

        .form-control:focus,
        .form-select:focus {
            background: #100e0c;
            color: white;
            border-color: var(--hotnet-orange);
            box-shadow: 0 0 0 0.2rem rgba(255, 101, 0, 0.15);
        }

        .form-control::placeholder {
            color: #999;
        }

        .form-select option {
            background: #1d1916;
            color: white;
        }

        /* BUTTONS */

        .btn-hotnet {
            background: linear-gradient(
                90deg,
                #ff4500,
                #ff8c00
            );

            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            padding: 10px 18px;
        }

        .btn-hotnet:hover {
            color: white;
            box-shadow: 0 0 15px rgba(255, 101, 0, 0.35);
        }

        /* TABLE */

        .table {
            color: #eee;
            margin-bottom: 0;
        }

        .table thead th {
            background: #2a1b13;
            color: #ff9b4a;
            font-size: 12px;
            text-transform: uppercase;
            white-space: nowrap;
            padding: 15px;
            border-bottom: 1px solid #59321c;
        }

        .table tbody td {
            background: #1d1916;
            color: #eee;
            padding: 14px;
            vertical-align: middle;
            font-size: 13px;
            border-color: #332820;
        }

        .table-hover tbody tr:hover td {
            background: #302117;
            color: white;
        }

        .table-responsive {
            border-radius: 10px;
        }

        .request-id {
            color: #ff9b4a;
            font-size: 12px;
            font-weight: 700;
            overflow-wrap: anywhere;
            max-width: 200px;
        }

        .error-text {
            color: #ff7777;
            max-width: 220px;
            overflow-wrap: anywhere;
        }

        /* PAYLOAD */

        .payload-box {
            background: #0c0a09;
            color: #ffbf8b;
            border: 1px solid #493126;
            border-radius: 10px;
            padding: 15px;
            max-height: 350px;
            overflow: auto;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font-size: 12px;
        }

        .modal-content {
            background: #1d1916;
            color: white;
            border: 1px solid #59321c;
            border-radius: 15px;
        }

        .modal-header {
            border-bottom-color: #493126;
        }

        .modal-footer {
            border-top-color: #493126;
        }

        /* PAGINATION */

        .page-link {
            background: #1d1916;
            color: #ff9b4a;
            border-color: #493126;
        }

        .page-item.active .page-link {
            background: var(--hotnet-orange);
            border-color: var(--hotnet-orange);
            color: white;
        }

        .page-link:hover {
            background: #302117;
            color: white;
        }

        /* FOOTER */

        .footer {
            background: #080706;
            border-top: 1px solid rgba(255, 101, 0, 0.3);
            padding: 25px;
            text-align: center;
            color: #bbb;
            margin-top: 40px;
        }

        .footer strong {
            color: var(--hotnet-orange);
        }

        @media (max-width: 768px) {

            .fire-hero {
                padding: 25px;
                min-height: 210px;
            }

            .content-card {
                padding: 15px;
            }

            .stat-number {
                font-size: 22px;
            }

            .flame {
                opacity: 0.4;
            }

        }

    </style>

</head>

<body>

<!-- =========================================================
     NAVIGATION
========================================================= -->

<nav class="navbar navbar-expand-lg navbar-dark">

    <div class="container-fluid">

        <a class="navbar-brand" href="dashboard.php">
            HOT<span>NET</span>
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#hotnetNavbar"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="hotnetNavbar">

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
                    <a class="nav-link active" href="mpesa_callbacks.php">
                        M-Pesa Callbacks
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link text-warning" href="../auth/logout.php">
                        Logout
                    </a>
                </li>

            </ul>

        </div>

    </div>

</nav>


<div class="container-fluid px-3 px-md-4 py-4">

    <!-- FIRE HERO -->

    <section class="fire-hero">

        <div class="fire-glow"></div>

        <div class="flame flame-one"></div>
        <div class="flame flame-two"></div>
        <div class="flame flame-three"></div>

        <div class="fire-content">

            <span class="badge bg-warning text-dark mb-2">
                HOTNET PAYMENT SYSTEM
            </span>

            <h1 class="fire-title">
                M-Pesa Callbacks
            </h1>

            <p class="fire-subtitle">
                Monitor Safaricom Daraja callback notifications,
                payment processing status and callback errors.
            </p>

            <span class="badge bg-dark border border-warning">
                <i class="bi bi-shield-check text-warning"></i>
                Callback Monitoring
            </span>

        </div>

    </section>


    <!-- PAGE TITLE -->

    <div class="mb-4">

        <h3 class="fw-bold">
            <i class="bi bi-broadcast orange-text"></i>
            Callback Overview
        </h3>

        <p class="text-secondary">
            Track incoming M-Pesa callback notifications.
        </p>

    </div>


    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-inbox"></i>
                </div>

                <div class="stat-label mt-3">
                    Total Callbacks
                </div>

                <div class="stat-number">
                    <?= number_format((int) $stats['total_callbacks']) ?>
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <div class="stat-label mt-3">
                    Pending
                </div>

                <div class="stat-number text-warning">
                    <?= number_format((int) $stats['pending_callbacks']) ?>
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-check-circle"></i>
                </div>

                <div class="stat-label mt-3">
                    Processed
                </div>

                <div class="stat-number text-success">
                    <?= number_format((int) $stats['processed_callbacks']) ?>
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>

                <div class="stat-label mt-3">
                    Failed
                </div>

                <div class="stat-number text-danger">
                    <?= number_format((int) $stats['failed_callbacks']) ?>
                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         CALLBACK INFORMATION
    ====================================================== -->

    <div class="content-card">

        <h5 class="section-title mb-3">

            <i class="bi bi-info-circle orange-text"></i>

            How Callback Processing Works

        </h5>

        <div class="row g-3">

            <div class="col-md-4">

                <div class="p-3 rounded-3 border border-secondary h-100">

                    <h6 class="text-warning">
                        1. Callback Received
                    </h6>

                    <p class="small text-secondary mb-0">
                        Safaricom sends a payment notification to
                        your configured callback URL.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="p-3 rounded-3 border border-secondary h-100">

                    <h6 class="text-warning">
                        2. Callback Stored
                    </h6>

                    <p class="small text-secondary mb-0">
                        HOTNET stores the callback payload in the
                        callback inbox for processing and auditing.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="p-3 rounded-3 border border-secondary h-100">

                    <h6 class="text-warning">
                        3. Payment Processed
                    </h6>

                    <p class="small text-secondary mb-0">
                        The callback processor updates the payment
                        and creates or activates the customer's voucher
                        after successful confirmation.
                    </p>

                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         SEARCH AND FILTERS
    ====================================================== -->

    <div class="content-card">

        <h5 class="section-title mb-3">

            <i class="bi bi-funnel orange-text"></i>

            Search Callback Records

        </h5>

        <form method="GET" action="mpesa_callbacks.php">

            <div class="row g-3">

                <div class="col-md-6">

                    <label class="form-label">
                        Search
                    </label>

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Checkout ID, merchant ID or error..."
                        value="<?= hotnetCallbackEscape($search) ?>"
                    >

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Processing Status
                    </label>

                    <select name="status" class="form-select">

                        <option value="">
                            All Statuses
                        </option>

                        <?php foreach ($allowedStatuses as $status): ?>

                            <option
                                value="<?= hotnetCallbackEscape($status) ?>"
                                <?= $statusFilter === $status ? 'selected' : '' ?>
                            >
                                <?= hotnetCallbackEscape($status) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-3 d-flex align-items-end">

                    <button
                        type="submit"
                        class="btn btn-hotnet w-100"
                    >

                        <i class="bi bi-search"></i>
                        Search Callbacks

                    </button>

                </div>

            </div>


            <div class="mt-3">

                <a
                    href="mpesa_callbacks.php"
                    class="btn btn-outline-light btn-sm"
                >

                    <i class="bi bi-arrow-clockwise"></i>
                    Clear Filters

                </a>

            </div>

        </form>

    </div>


    <!-- =====================================================
         CALLBACK HISTORY
    ====================================================== -->

    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">

            <h5 class="section-title mb-0">

                <i class="bi bi-clock-history orange-text"></i>

                Callback History

            </h5>

            <span class="badge bg-warning text-dark">

                <?= number_format($totalFiltered) ?> Records

            </span>

        </div>


        <div class="table-responsive">

            <table class="table table-hover">

                <thead>

                    <tr>

                        <th>ID</th>
                        <th>Checkout Request ID</th>
                        <th>Merchant Request ID</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Error</th>
                        <th>Received</th>
                        <th>Processed</th>
                        <th>Payload</th>

                    </tr>

                </thead>


                <tbody>

                    <?php if (mysqli_num_rows($callbacksResult) > 0): ?>

                        <?php while ($callback = mysqli_fetch_assoc($callbacksResult)): ?>

                            <tr>

                                <td>
                                    <?= (int) $callback['id'] ?>
                                </td>


                                <td>

                                    <div class="request-id">

                                        <?= hotnetCallbackEscape(
                                            $callback['checkout_request_id']
                                            ?: 'Not available'
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <div class="request-id">

                                        <?= hotnetCallbackEscape(
                                            $callback['merchant_request_id']
                                            ?: 'Not available'
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <span class="badge bg-<?= hotnetCallbackStatusClass(
                                        $callback['processing_status']
                                    ) ?>">

                                        <?= hotnetCallbackEscape(
                                            $callback['processing_status']
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <span class="badge bg-dark border border-secondary">

                                        <?= (int) $callback['attempts'] ?>

                                    </span>

                                </td>


                                <td>

                                    <?php if (!empty($callback['last_error'])): ?>

                                        <div class="error-text">

                                            <?= hotnetCallbackEscape(
                                                $callback['last_error']
                                            ) ?>

                                        </div>

                                    <?php else: ?>

                                        <span class="text-secondary">
                                            None
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td class="small text-secondary">

                                    <?= !empty($callback['received_at'])
                                        ? hotnetCallbackEscape(
                                            date(
                                                'd M Y H:i',
                                                strtotime($callback['received_at'])
                                            )
                                        )
                                        : 'N/A'
                                    ?>

                                </td>


                                <td class="small text-secondary">

                                    <?= !empty($callback['processed_at'])
                                        ? hotnetCallbackEscape(
                                            date(
                                                'd M Y H:i',
                                                strtotime($callback['processed_at'])
                                            )
                                        )
                                        : 'Not processed'
                                    ?>

                                </td>


                                <td>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-warning"
                                        data-bs-toggle="modal"
                                        data-bs-target="#payloadModal"
                                        data-payload="<?= hotnetCallbackEscape(
                                            $callback['callback_payload']
                                        ) ?>"
                                    >

                                        <i class="bi bi-code-square"></i>
                                        View

                                    </button>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="9">

                                <div class="text-center py-5">

                                    <i class="bi bi-inbox display-3 orange-text"></i>

                                    <h5 class="mt-3 text-white">
                                        No Callback Records Found
                                    </h5>

                                    <p class="text-secondary">
                                        Callback records will appear here
                                        when notifications are received.
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

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">

                        <a
                            class="page-link"
                            href="?<?= $paginationQuery ?>&page=<?= max(1, $page - 1) ?>"
                        >
                            Previous
                        </a>

                    </li>


                    <?php

                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    ?>


                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>

                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">

                            <a
                                class="page-link"
                                href="?<?= $paginationQuery ?>&page=<?= $i ?>"
                            >
                                <?= $i ?>
                            </a>

                        </li>

                    <?php endfor; ?>


                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">

                        <a
                            class="page-link"
                            href="?<?= $paginationQuery ?>&page=<?= min($totalPages, $page + 1) ?>"
                        >
                            Next
                        </a>

                    </li>

                </ul>

            </nav>

        <?php endif; ?>

    </div>


    <!-- PROCESSING INFORMATION -->

    <div class="alert alert-dark border border-warning">

        <h6 class="text-warning">

            <i class="bi bi-exclamation-triangle"></i>

            Callback Retry Information

        </h6>

        <p class="small mb-2">

            This page is a monitoring dashboard. It does not manually
            retry callbacks or change payment statuses.

        </p>

        <p class="small mb-0">

            Your existing command-line retry script can be run from
            the project directory using:

        </p>

        <code class="text-warning">
            php mpesa\retry_callbacks.php
        </code>

    </div>

</div>


<!-- =========================================================
     CALLBACK PAYLOAD MODAL
========================================================= -->

<div
    class="modal fade"
    id="payloadModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-code-square orange-text"></i>

                    M-Pesa Callback Payload

                </h5>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <div class="modal-body">

                <pre
                    class="payload-box"
                    id="callbackPayload"
                >No callback payload selected.</pre>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-hotnet"
                    data-bs-dismiss="modal"
                >
                    Close
                </button>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     FOOTER
========================================================= -->

<footer class="footer">

    <h4 class="fw-bold">
        HOT<strong>NET</strong>
    </h4>

    <p class="mb-1">
        WiFi Billing and Customer Management System
    </p>

    <p class="small">
        Customer Support: 0718347038
    </p>

    <p class="small mb-0">
        &copy; <?= date('Y') ?> HOTNET. All Rights Reserved.
    </p>

</footer>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/*
|--------------------------------------------------------------------------
| DISPLAY CALLBACK PAYLOAD
|--------------------------------------------------------------------------
*/

const payloadModal = document.getElementById('payloadModal');

payloadModal.addEventListener('show.bs.modal', function(event) {

    const button = event.relatedTarget;

    const payload = button.getAttribute('data-payload');

    const payloadElement =
        document.getElementById('callbackPayload');

    try {

        const parsedPayload = JSON.parse(payload);

        payloadElement.textContent =
            JSON.stringify(parsedPayload, null, 4);

    } catch (error) {

        payloadElement.textContent =
            payload || 'No callback payload available.';

    }

});

</script>

</body>
</html>

<?php

mysqli_stmt_close($callbacksStmt);

?>