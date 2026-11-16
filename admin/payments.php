<?php
session_start();

/*
|--------------------------------------------------------------------------
| HOTNET PAYMENT MANAGEMENT
|--------------------------------------------------------------------------
| File: admin/payments.php
| Database: wifi_billing
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

if (!function_exists('hotnetPayEscape')) {
    function hotnetPayEscape($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

function hotnetPayMoney($amount)
{
    return 'KSh ' . number_format((float) $amount, 2);
}

function hotnetPayStatusClass($status)
{
    switch (strtolower((string) $status)) {
        case 'paid':
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
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedStatuses = ['Paid', 'Pending', 'Failed'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$statsSql = "
    SELECT
        COUNT(*) AS total_payments,

        SUM(
            CASE
                WHEN payment_status = 'Paid' THEN 1
                ELSE 0
            END
        ) AS paid_count,

        SUM(
            CASE
                WHEN payment_status = 'Pending' THEN 1
                ELSE 0
            END
        ) AS pending_count,

        SUM(
            CASE
                WHEN payment_status = 'Failed' THEN 1
                ELSE 0
            END
        ) AS failed_count,

        SUM(
            CASE
                WHEN payment_status = 'Paid' THEN amount
                ELSE 0
            END
        ) AS total_revenue,

        SUM(
            CASE
                WHEN payment_status = 'Paid'
                AND DATE(payment_date) = CURDATE()
                THEN amount
                ELSE 0
            END
        ) AS today_revenue,

        SUM(
            CASE
                WHEN payment_status = 'Paid'
                AND MONTH(payment_date) = MONTH(CURDATE())
                AND YEAR(payment_date) = YEAR(CURDATE())
                THEN amount
                ELSE 0
            END
        ) AS monthly_revenue

    FROM payments
";

$statsResult = mysqli_query($conn, $statsSql);

if (!$statsResult) {
    die("Could not load payment statistics: " .
        hotnetPayEscape(mysqli_error($conn)));
}

$stats = mysqli_fetch_assoc($statsResult);

/*
|--------------------------------------------------------------------------
| MONTHLY REVENUE CHART
|--------------------------------------------------------------------------
*/

$chartSql = "
    SELECT
        DATE_FORMAT(payment_date, '%Y-%m') AS payment_month,
        SUM(amount) AS revenue

    FROM payments

    WHERE payment_status = 'Paid'
      AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)

    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')

    ORDER BY payment_month ASC
";

$chartResult = mysqli_query($conn, $chartSql);

$chartLabels = [];
$chartValues = [];

if ($chartResult) {
    while ($chartRow = mysqli_fetch_assoc($chartResult)) {

        $chartLabels[] = date(
            'M Y',
            strtotime($chartRow['payment_month'] . '-01')
        );

        $chartValues[] = (float) $chartRow['revenue'];
    }
}

/*
|--------------------------------------------------------------------------
| BUILD PAYMENT FILTERS
|--------------------------------------------------------------------------
*/

$whereSql = " WHERE 1 = 1 ";

$filterTypes = '';
$filterParams = [];

if ($search !== '') {

    $whereSql .= "
        AND (
            p.transaction_code LIKE ?
            OR p.mpesa_receipt_number LIKE ?
            OR p.checkout_request_id LIKE ?
            OR p.customer_phone LIKE ?
            OR p.customer_name LIKE ?
            OR u.fullname LIKE ?
            OR u.username LIKE ?
            OR pk.package_name LIKE ?
        )
    ";

    $searchTerm = "%{$search}%";

    $filterTypes .= 'ssssssss';

    for ($i = 0; $i < 8; $i++) {
        $filterParams[] = $searchTerm;
    }
}

if ($statusFilter !== '') {

    $whereSql .= " AND p.payment_status = ? ";

    $filterTypes .= 's';
    $filterParams[] = $statusFilter;
}

if ($dateFrom !== '') {

    $whereSql .= " AND DATE(p.payment_date) >= ? ";

    $filterTypes .= 's';
    $filterParams[] = $dateFrom;
}

if ($dateTo !== '') {

    $whereSql .= " AND DATE(p.payment_date) <= ? ";

    $filterTypes .= 's';
    $filterParams[] = $dateTo;
}

/*
|--------------------------------------------------------------------------
| FETCH PAYMENT HISTORY
|--------------------------------------------------------------------------
*/

$paymentsSql = "
    SELECT
        p.*,
        u.fullname,
        u.username,
        pk.package_name,
        v.voucher_code

    FROM payments p

    LEFT JOIN users u
        ON p.user_id = u.id

    LEFT JOIN packages pk
        ON p.package_id = pk.id

    LEFT JOIN vouchers v
        ON p.voucher_id = v.id

    {$whereSql}

    ORDER BY p.payment_date DESC, p.id DESC
";

$paymentsStmt = mysqli_prepare($conn, $paymentsSql);

if (!$paymentsStmt) {
    die("Could not prepare payment history query.");
}

if ($filterTypes !== '') {

    $bindParams = [];
    $bindParams[] = $filterTypes;

    foreach ($filterParams as $key => $value) {
        $bindParams[] = &$filterParams[$key];
    }

    call_user_func_array(
        'mysqli_stmt_bind_param',
        array_merge([$paymentsStmt], $bindParams)
    );
}

mysqli_stmt_execute($paymentsStmt);

$paymentsResult = mysqli_stmt_get_result($paymentsStmt);

if (!$paymentsResult) {
    die("Could not retrieve payment records.");
}

/*
|--------------------------------------------------------------------------
| CSV EXPORT
|--------------------------------------------------------------------------
*/

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $exportSql = "
        SELECT
            p.id,
            p.customer_name,
            p.customer_phone,
            u.fullname,
            u.username,
            pk.package_name,
            v.voucher_code,
            p.amount,
            p.transaction_code,
            p.mpesa_receipt_number,
            p.payment_method,
            p.payment_status,
            p.payment_date

        FROM payments p

        LEFT JOIN users u
            ON p.user_id = u.id

        LEFT JOIN packages pk
            ON p.package_id = pk.id

        LEFT JOIN vouchers v
            ON p.voucher_id = v.id

        {$whereSql}

        ORDER BY p.payment_date DESC
    ";

    $exportStmt = mysqli_prepare($conn, $exportSql);

    if (!$exportStmt) {
        die("Could not prepare CSV export.");
    }

    if ($filterTypes !== '') {

        $bindParams = [];
        $bindParams[] = $filterTypes;

        foreach ($filterParams as $key => $value) {
            $bindParams[] = &$filterParams[$key];
        }

        call_user_func_array(
            'mysqli_stmt_bind_param',
            array_merge([$exportStmt], $bindParams)
        );
    }

    mysqli_stmt_execute($exportStmt);

    $exportResult = mysqli_stmt_get_result($exportStmt);

    header('Content-Type: text/csv; charset=utf-8');

    header(
        'Content-Disposition: attachment; filename=HOTNET_payments_' .
        date('Ymd_His') .
        '.csv'
    );

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Payment ID',
        'Customer',
        'Phone',
        'Username',
        'Package',
        'Voucher',
        'Amount',
        'Transaction Code',
        'M-Pesa Receipt',
        'Payment Method',
        'Payment Status',
        'Payment Date'
    ]);

    while ($row = mysqli_fetch_assoc($exportResult)) {

        fputcsv($output, [
            $row['id'],
            $row['fullname'] ?: $row['customer_name'],
            $row['customer_phone'],
            $row['username'],
            $row['package_name'],
            $row['voucher_code'],
            $row['amount'],
            $row['transaction_code'],
            $row['mpesa_receipt_number'],
            $row['payment_method'],
            $row['payment_status'],
            $row['payment_date']
        ]);
    }

    fclose($output);

    mysqli_stmt_close($exportStmt);
    mysqli_stmt_close($paymentsStmt);

    exit();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>HOTNET | Payment Management</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>

        :root {
            --hotnet-orange: #ff6500;
            --hotnet-yellow: #ffb000;
            --hotnet-red: #e52b00;
            --hotnet-dark: #100d0b;
            --hotnet-card: #1d1916;
            --hotnet-background: #100e0c;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: var(--hotnet-background);
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

        /* FIRE HERO */

        .fire-hero {
            position: relative;
            overflow: hidden;
            min-height: 240px;
            display: flex;
            align-items: center;
            border-radius: 20px;
            padding: 35px;
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
            box-shadow: 0 0 30px rgba(255, 75, 0, 0.12);
        }

        .fire-content {
            position: relative;
            z-index: 3;
        }

        .fire-title {
            font-size: clamp(30px, 5vw, 48px);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;

            background: linear-gradient(
                90deg,
                #fff,
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
            animation-delay: 0.2s;
        }

        .flame-two {
            right: 27%;
            height: 105px;
            width: 45px;
            animation-delay: 0.6s;
        }

        .flame-three {
            right: 13%;
            height: 115px;
            width: 45px;
            animation-delay: 0.4s;
        }

        .flame-four {
            right: 35%;
            height: 90px;
            width: 35px;
            animation-delay: 0.8s;
        }

        @keyframes flameMove {
            0% {
                transform: scaleY(0.85) rotate(-5deg);
                opacity: 0.55;
            }

            50% {
                transform: scaleY(1.15) rotate(5deg);
                opacity: 0.9;
            }

            100% {
                transform: scaleY(0.95) rotate(-3deg);
                opacity: 0.65;
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
            font-size: 24px;
            font-weight: 800;
            margin-top: 12px;
            color: white;
        }

        .stat-label {
            color: #aaa;
            font-size: 13px;
        }

        /* CONTENT CARDS */

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
            background: linear-gradient(
                90deg,
                #e63e00,
                #f57c00
            );

            color: white;
            box-shadow: 0 0 15px rgba(255, 101, 0, 0.35);
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

        .form-label {
            color: #ddd;
        }

        /* TABLE */

        .table-responsive {
            border-radius: 10px;
        }

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

        .payment-code {
            font-size: 12px;
            font-weight: 700;
            color: #ff9b4a;
        }

        .badge {
            padding: 8px 11px;
            border-radius: 20px;
        }

        .empty-state {
            padding: 50px 15px;
            text-align: center;
            color: #aaa;
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
                font-size: 19px;
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
                    <a class="nav-link active" href="payments.php">
                        Payments
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="mpesa_callbacks.php">
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


<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="container-fluid px-3 px-md-4 py-4">


    <!-- FIRE HERO -->

    <section class="fire-hero">

        <div class="fire-glow"></div>

        <div class="flame flame-one"></div>
        <div class="flame flame-two"></div>
        <div class="flame flame-three"></div>
        <div class="flame flame-four"></div>

        <div class="fire-content">

            <div class="mb-2">
                <span class="badge bg-warning text-dark">
                    HOTNET ADMINISTRATION
                </span>
            </div>

            <h1 class="fire-title">
                Payment Management
            </h1>

            <p class="fire-subtitle">
                Monitor M-Pesa transactions, customer payments,
                revenue and payment history from one dashboard.
            </p>

            <div class="d-flex flex-wrap gap-2 mt-3">

                <span class="badge bg-dark border border-warning">
                    <i class="bi bi-lightning-charge-fill text-warning"></i>
                    Automatic M-Pesa Processing
                </span>

                <span class="badge bg-dark border border-danger">
                    <i class="bi bi-fire text-danger"></i>
                    HOTNET System
                </span>

            </div>

        </div>

    </section>


    <!-- PAGE HEADING -->

    <div class="mb-4">

        <h3 class="fw-bold">
            <i class="bi bi-wallet2 orange-text"></i>
            Payments Overview
        </h3>

        <p class="text-secondary">
            View and track all customer payment transactions.
        </p>

    </div>


    <!-- =====================================================
         PAYMENT STATISTICS
    ====================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-receipt"></i>
                </div>

                <div class="stat-label mt-3">
                    Total Payments
                </div>

                <div class="stat-number">
                    <?= number_format((int) $stats['total_payments']) ?>
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-cash-stack"></i>
                </div>

                <div class="stat-label mt-3">
                    Total Revenue
                </div>

                <div class="stat-number">
                    <?= hotnetPayEscape(
                        hotnetPayMoney($stats['total_revenue'] ?? 0)
                    ) ?>
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-calendar-check"></i>
                </div>

                <div class="stat-label mt-3">
                    Today's Revenue
                </div>

                <div class="stat-number">
                    <?= hotnetPayEscape(
                        hotnetPayMoney($stats['today_revenue'] ?? 0)
                    ) ?>
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>

                <div class="stat-label mt-3">
                    Monthly Revenue
                </div>

                <div class="stat-number">
                    <?= hotnetPayEscape(
                        hotnetPayMoney($stats['monthly_revenue'] ?? 0)
                    ) ?>
                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         PAYMENT STATUS CARDS
    ====================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-md-4">

            <div class="stat-card">

                <div class="stat-label">
                    Paid Payments
                </div>

                <h2 class="text-success fw-bold mt-2">
                    <?= number_format((int) $stats['paid_count']) ?>
                </h2>

                <small class="text-secondary">
                    Successfully confirmed payments
                </small>

            </div>

        </div>


        <div class="col-md-4">

            <div class="stat-card">

                <div class="stat-label">
                    Pending Payments
                </div>

                <h2 class="text-warning fw-bold mt-2">
                    <?= number_format((int) $stats['pending_count']) ?>
                </h2>

                <small class="text-secondary">
                    Awaiting payment confirmation
                </small>

            </div>

        </div>


        <div class="col-md-4">

            <div class="stat-card">

                <div class="stat-label">
                    Failed Payments
                </div>

                <h2 class="text-danger fw-bold mt-2">
                    <?= number_format((int) $stats['failed_count']) ?>
                </h2>

                <small class="text-secondary">
                    Unsuccessful transactions
                </small>

            </div>

        </div>

    </div>


    <!-- =====================================================
         REVENUE CHART
    ====================================================== -->

    <div class="content-card">

        <div class="d-flex justify-content-between align-items-center mb-4">

            <h5 class="section-title mb-0">
                <i class="bi bi-bar-chart-line orange-text"></i>
                Monthly Revenue
            </h5>

            <span class="badge bg-warning text-dark">
                Last 12 Months
            </span>

        </div>

        <div style="height: 300px;">

            <canvas id="revenueChart"></canvas>

        </div>

    </div>


    <!-- =====================================================
         SEARCH AND FILTERS
    ====================================================== -->

    <div class="content-card">

        <h5 class="section-title mb-3">

            <i class="bi bi-funnel orange-text"></i>

            Search Payment Records

        </h5>

        <form method="GET" action="payments.php">

            <div class="row g-3">

                <div class="col-md-4">

                    <label class="form-label">
                        Search Transaction
                    </label>

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Customer, phone, receipt..."
                        value="<?= hotnetPayEscape($search) ?>"
                    >

                </div>


                <div class="col-md-2">

                    <label class="form-label">
                        Status
                    </label>

                    <select name="status" class="form-select">

                        <option value="">
                            All Statuses
                        </option>

                        <?php foreach ($allowedStatuses as $status): ?>

                            <option
                                value="<?= hotnetPayEscape($status) ?>"
                                <?= $statusFilter === $status ? 'selected' : '' ?>
                            >
                                <?= hotnetPayEscape($status) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-2">

                    <label class="form-label">
                        From Date
                    </label>

                    <input
                        type="date"
                        name="date_from"
                        class="form-control"
                        value="<?= hotnetPayEscape($dateFrom) ?>"
                    >

                </div>


                <div class="col-md-2">

                    <label class="form-label">
                        To Date
                    </label>

                    <input
                        type="date"
                        name="date_to"
                        class="form-control"
                        value="<?= hotnetPayEscape($dateTo) ?>"
                    >

                </div>


                <div class="col-md-2 d-flex align-items-end">

                    <button
                        type="submit"
                        class="btn btn-hotnet w-100"
                    >

                        <i class="bi bi-search"></i>
                        Search

                    </button>

                </div>

            </div>


            <div class="d-flex flex-wrap gap-2 mt-3">

                <a
                    href="payments.php"
                    class="btn btn-outline-light btn-sm"
                >

                    <i class="bi bi-arrow-clockwise"></i>
                    Reset Filters

                </a>


                <a
                    href="payments.php?<?= http_build_query([
                        'search' => $search,
                        'status' => $statusFilter,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'export' => 'csv'
                    ]) ?>"
                    class="btn btn-success btn-sm"
                >

                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    Export CSV

                </a>

            </div>

        </form>

    </div>


    <!-- =====================================================
         PAYMENT HISTORY TABLE
    ====================================================== -->

    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">

            <h5 class="section-title mb-0">

                <i class="bi bi-clock-history orange-text"></i>

                Payment History

            </h5>

            <span class="badge bg-warning text-dark">
                HOTNET Records
            </span>

        </div>


        <div class="table-responsive">

            <table class="table table-hover">

                <thead>

                    <tr>

                        <th>#</th>
                        <th>Customer</th>
                        <th>Package</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Transaction / Receipt</th>
                        <th>Voucher</th>
                        <th>Status</th>
                        <th>Date</th>

                    </tr>

                </thead>


                <tbody>

                    <?php if (mysqli_num_rows($paymentsResult) > 0): ?>

                        <?php $number = 1; ?>

                        <?php while ($payment = mysqli_fetch_assoc($paymentsResult)): ?>

                            <tr>

                                <td>
                                    <?= $number++ ?>
                                </td>


                                <td>

                                    <strong>

                                        <?= hotnetPayEscape(
                                            $payment['fullname']
                                            ?: $payment['customer_name']
                                            ?: 'Guest Customer'
                                        ) ?>

                                    </strong>

                                    <div class="small text-secondary">

                                        <?= hotnetPayEscape(
                                            $payment['customer_phone'] ?? ''
                                        ) ?>

                                    </div>

                                    <div class="small text-secondary">

                                        <?= hotnetPayEscape(
                                            $payment['username'] ?? ''
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <?= hotnetPayEscape(
                                        $payment['package_name'] ?? 'N/A'
                                    ) ?>

                                </td>


                                <td class="fw-bold text-warning">

                                    <?= hotnetPayEscape(
                                        hotnetPayMoney($payment['amount'])
                                    ) ?>

                                </td>


                                <td>

                                    <?= hotnetPayEscape(
                                        $payment['payment_method'] ?? 'N/A'
                                    ) ?>

                                </td>


                                <td>

                                    <div class="payment-code">

                                        <?= hotnetPayEscape(
                                            $payment['mpesa_receipt_number']
                                            ?: $payment['transaction_code']
                                            ?: 'N/A'
                                        ) ?>

                                    </div>

                                    <?php if (!empty($payment['checkout_request_id'])): ?>

                                        <small class="text-secondary">
                                            M-Pesa checkout recorded
                                        </small>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= hotnetPayEscape(
                                        $payment['voucher_code'] ?? 'Not assigned'
                                    ) ?>

                                </td>


                                <td>

                                    <span class="badge bg-<?= hotnetPayStatusClass(
                                        $payment['payment_status']
                                    ) ?>">

                                        <?= hotnetPayEscape(
                                            $payment['payment_status']
                                        ) ?>

                                    </span>

                                </td>


                                <td class="small text-secondary">

                                    <?= !empty($payment['payment_date'])
                                        ? hotnetPayEscape(
                                            date(
                                                'd M Y, H:i',
                                                strtotime($payment['payment_date'])
                                            )
                                        )
                                        : 'N/A'
                                    ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="9">

                                <div class="empty-state">

                                    <i class="bi bi-receipt display-3 orange-text"></i>

                                    <h5 class="mt-3 text-white">
                                        No Payment Records Found
                                    </h5>

                                    <p>
                                        Try changing your search or filters.
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
| MONTHLY REVENUE CHART
|--------------------------------------------------------------------------
*/

const chartLabels = <?= json_encode($chartLabels) ?>;
const chartValues = <?= json_encode($chartValues) ?>;

const revenueCanvas = document.getElementById('revenueChart');

new Chart(revenueCanvas, {

    type: 'bar',

    data: {

        labels: chartLabels,

        datasets: [{

            label: 'Paid Revenue (KSh)',

            data: chartValues,

            backgroundColor: '#ff6500',

            hoverBackgroundColor: '#ffb000',

            borderRadius: 7,

            borderSkipped: false

        }]

    },

    options: {

        responsive: true,

        maintainAspectRatio: false,

        plugins: {

            legend: {
                display: false
            }

        },

        scales: {

            x: {

                ticks: {
                    color: '#ddd'
                },

                grid: {
                    color: 'rgba(255,255,255,0.05)'
                }

            },

            y: {

                beginAtZero: true,

                ticks: {

                    color: '#ddd',

                    callback: function(value) {
                        return 'KSh ' + value;
                    }

                },

                grid: {
                    color: 'rgba(255,255,255,0.08)'
                }

            }

        }

    }

});

</script>

</body>
</html>

<?php

mysqli_stmt_close($paymentsStmt);

?>