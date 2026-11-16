<?php
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| HOTNET DASHBOARD HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

if (!function_exists('hotnetDashboardEscape')) {
    function hotnetDashboardEscape($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

function hotnetDashboardNumber($conn, $sql, $field = 'total')
{
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);

    return isset($row[$field]) ? (float) $row[$field] : 0;
}

function hotnetDashboardRows($conn, $sql)
{
    $rows = [];

    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/*
|--------------------------------------------------------------------------
| CUSTOMER STATISTICS
|--------------------------------------------------------------------------
*/

$users = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM users"
);

$activeUsers = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE status = 'Active'"
);

$inactiveUsers = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE status = 'Inactive'"
);

/*
|--------------------------------------------------------------------------
| PACKAGE STATISTICS
|--------------------------------------------------------------------------
*/

$packages = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM packages"
);

/*
|--------------------------------------------------------------------------
| VOUCHER STATISTICS
|--------------------------------------------------------------------------
*/

$vouchers = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM vouchers"
);

$activeVouchers = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM vouchers
     WHERE status = 'Active'
     AND (expiry_date IS NULL OR expiry_date >= NOW())"
);

$usedVouchers = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM vouchers
     WHERE status = 'Used'"
);

$expiredVouchers = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM vouchers
     WHERE status = 'Expired'
     OR (status = 'Active' AND expiry_date < NOW())"
);

/*
|--------------------------------------------------------------------------
| PAYMENT STATISTICS
|--------------------------------------------------------------------------
*/

$payments = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM payments"
);

$paidPayments = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM payments
     WHERE payment_status = 'Paid'"
);

$pendingPayments = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM payments
     WHERE payment_status = 'Pending'"
);

$failedPayments = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM payments
     WHERE payment_status = 'Failed'"
);

/*
|--------------------------------------------------------------------------
| REVENUE STATISTICS
|--------------------------------------------------------------------------
| Only confirmed Paid payments count as revenue.
|--------------------------------------------------------------------------
*/

$totalRevenue = hotnetDashboardNumber(
    $conn,
    "SELECT COALESCE(SUM(amount), 0) AS total
     FROM payments
     WHERE payment_status = 'Paid'"
);

$todayRevenue = hotnetDashboardNumber(
    $conn,
    "SELECT COALESCE(SUM(amount), 0) AS total
     FROM payments
     WHERE payment_status = 'Paid'
     AND DATE(payment_date) = CURDATE()"
);

$monthlyRevenue = hotnetDashboardNumber(
    $conn,
    "SELECT COALESCE(SUM(amount), 0) AS total
     FROM payments
     WHERE payment_status = 'Paid'
     AND MONTH(payment_date) = MONTH(CURDATE())
     AND YEAR(payment_date) = YEAR(CURDATE())"
);

/*
|--------------------------------------------------------------------------
| SESSION STATISTICS
|--------------------------------------------------------------------------
*/

$sessions = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM sessions"
);

/*
|--------------------------------------------------------------------------
| DEVICE STATISTICS
|--------------------------------------------------------------------------
*/

$registeredDevices = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM customer_devices"
);

$activeDevices = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM customer_devices
     WHERE status = 'Active'"
);

$blockedDevices = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM customer_devices
     WHERE status = 'Blocked'"
);

/*
|--------------------------------------------------------------------------
| CONNECTION STATISTICS
|--------------------------------------------------------------------------
*/

$totalConnections = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM device_connections"
);

$activeConnections = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM device_connections
     WHERE connection_status = 'Active'"
);

/*
|--------------------------------------------------------------------------
| ROUTER STATISTICS
|--------------------------------------------------------------------------
*/

$totalRouters = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM router_nodes"
);

$enabledRouters = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM router_nodes
     WHERE is_enabled = 1"
);

$totalAccessPoints = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM access_points"
);

/*
|--------------------------------------------------------------------------
| M-PESA CALLBACK STATISTICS
|--------------------------------------------------------------------------
*/

$totalCallbacks = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total FROM mpesa_callback_inbox"
);

$pendingCallbacks = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM mpesa_callback_inbox
     WHERE processing_status = 'Pending'"
);

$processedCallbacks = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM mpesa_callback_inbox
     WHERE processing_status = 'Processed'"
);

$failedCallbacks = hotnetDashboardNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM mpesa_callback_inbox
     WHERE processing_status = 'Failed'"
);

/*
|--------------------------------------------------------------------------
| RECENT PAYMENTS
|--------------------------------------------------------------------------
*/

$recentPayments = hotnetDashboardRows(
    $conn,
    "SELECT
        p.id,
        p.customer_name,
        p.customer_phone,
        p.amount,
        p.transaction_code,
        p.mpesa_receipt_number,
        p.payment_status,
        p.payment_date,
        u.fullname,
        packages.package_name

     FROM payments p

     LEFT JOIN users u
        ON p.user_id = u.id

     LEFT JOIN packages
        ON p.package_id = packages.id

     ORDER BY p.id DESC

     LIMIT 7"
);

/*
|--------------------------------------------------------------------------
| MONTHLY REVENUE CHART
|--------------------------------------------------------------------------
*/

$monthlyChart = hotnetDashboardRows(
    $conn,
    "SELECT
        DATE_FORMAT(payment_date, '%b %Y') AS month_name,
        SUM(amount) AS revenue,
        YEAR(payment_date) AS revenue_year,
        MONTH(payment_date) AS revenue_month

     FROM payments

     WHERE payment_status = 'Paid'
     AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)

     GROUP BY YEAR(payment_date), MONTH(payment_date)

     ORDER BY revenue_year ASC, revenue_month ASC"
);

$chartLabels = [];
$chartValues = [];

foreach ($monthlyChart as $chartRow) {
    $chartLabels[] = $chartRow['month_name'];
    $chartValues[] = (float) $chartRow['revenue'];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>HOTNET Green Dashboard</title>

    <!-- BOOTSTRAP -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- BOOTSTRAP ICONS -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- CHART JS -->

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>

        :root {
            --forest: #123d26;
            --forest-dark: #092b19;
            --leaf: #258a45;
            --leaf-light: #e3f5e8;
            --fresh-green: #43a85d;
            --lime: #a6d96a;
            --white: #ffffff;
            --text-dark: #25352a;
            --muted: #718078;
            --background: #f2f8f3;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text-dark);
            font-family: Arial, Helvetica, sans-serif;
        }

        /* =====================================================
           VEGETATION HEADER
        ===================================================== */

        .vegetation-header {
            position: relative;
            overflow: hidden;
            min-height: 270px;
            padding: 35px;
            border-radius: 20px;

            background:
                linear-gradient(
                    100deg,
                    rgba(5, 35, 19, .96),
                    rgba(16, 82, 42, .85),
                    rgba(24, 105, 51, .78)
                );

            color: white;
            box-shadow: 0 8px 25px rgba(14, 65, 33, .18);
            margin-bottom: 28px;
        }

        .vegetation-header::before {
            content: "";
            position: absolute;
            width: 330px;
            height: 330px;
            right: 8%;
            top: -130px;
            border-radius: 50%;
            background: rgba(134, 204, 113, .16);
            filter: blur(25px);
        }

        .vegetation-header::after {
            content: "";
            position: absolute;
            width: 250px;
            height: 250px;
            right: -50px;
            bottom: -150px;
            border-radius: 50%;
            background: rgba(170, 226, 113, .25);
            filter: blur(20px);
        }

        .leaf-decoration {
            position: absolute;
            right: 8%;
            bottom: 15px;
            font-size: 150px;
            color: rgba(190, 235, 170, .13);
            transform: rotate(-20deg);
            pointer-events: none;
        }

        .leaf-decoration.second {
            right: 24%;
            bottom: 5px;
            font-size: 100px;
            transform: rotate(25deg);
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .hotnet-logo {
            color: #a7e17b;
            font-size: 34px;
            font-weight: 900;
            letter-spacing: 3px;
        }

        .hotnet-logo span {
            color: white;
        }

        .header-tagline {
            color: #d8f1dc;
            max-width: 650px;
            line-height: 1.7;
        }

        /* =====================================================
           NAVIGATION
        ===================================================== */

        .hotnet-navbar {
            background: var(--forest);
            padding: 12px 20px;
            border-radius: 13px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .hotnet-navbar a {
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 10px;
            border-radius: 7px;
            transition: .2s;
        }

        .hotnet-navbar a:hover {
            background: var(--leaf);
        }

        .hotnet-navbar .brand {
            color: #b8ec91;
            font-size: 17px;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .navbar-links {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        /* =====================================================
           SECTION TITLES
        ===================================================== */

        .section-title {
            font-size: 19px;
            font-weight: 800;
            margin-bottom: 18px;
            color: var(--forest);
        }

        .section-title i {
            color: var(--leaf);
            margin-right: 8px;
        }

        /* =====================================================
           DASHBOARD PANELS
        ===================================================== */

        .content-panel {
            background: white;
            padding: 24px;
            border-radius: 17px;
            box-shadow: 0 4px 15px rgba(15, 75, 36, .06);
            margin-bottom: 25px;
            border: 1px solid #e8f1e9;
        }

        /* =====================================================
           STATISTICS CARDS
        ===================================================== */

        .dashboard-card {
            height: 100%;
            background: white;
            border: 1px solid #e5efe6;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 13px rgba(15, 75, 36, .05);
            transition: .25s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 22px rgba(15, 75, 36, .12);
            border-color: #a9d9b3;
        }

        .dashboard-card .card-body {
            padding: 22px;
        }

        .stat-icon {
            width: 49px;
            height: 49px;
            border-radius: 13px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: var(--leaf-light);
            color: var(--leaf);
            font-size: 23px;
            margin-bottom: 15px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }

        .stat-number {
            color: var(--forest);
            font-size: 29px;
            font-weight: 900;
            margin: 5px 0;
        }

        .stat-link {
            display: block;
            height: 100%;
            text-decoration: none;
            color: inherit;
        }

        .stat-link:hover {
            color: inherit;
        }

        .stat-note {
            color: #87958a;
            font-size: 12px;
        }

        /* =====================================================
           QUICK ACTIONS
        ===================================================== */

        .quick-action {
            height: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            border: 1px solid #e2ece3;
            border-radius: 12px;
            text-decoration: none;
            background: #fbfefb;
            color: var(--forest);
            font-size: 13px;
            font-weight: 800;
            transition: .2s;
        }

        .quick-action i {
            color: var(--leaf);
            font-size: 22px;
        }

        .quick-action:hover {
            background: var(--leaf);
            color: white;
            border-color: var(--leaf);
            transform: translateY(-3px);
        }

        .quick-action:hover i {
            color: white;
        }

        /* =====================================================
           BUTTONS
        ===================================================== */

        .hotnet-btn {
            display: inline-block;
            text-decoration: none;
            background: var(--leaf);
            color: white;
            border: none;
            padding: 10px 17px;
            border-radius: 9px;
            font-weight: 800;
            font-size: 13px;
            transition: .2s;
        }

        .hotnet-btn:hover {
            background: var(--forest);
            color: white;
        }

        /* =====================================================
           TABLE
        ===================================================== */

        .table thead th {
            background: #e9f6eb;
            color: var(--forest);
            border: none;
            padding: 14px;
            font-size: 12px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 13px;
            vertical-align: middle;
            font-size: 13px;
        }

        .table tbody tr:hover {
            background: #f5fbf5;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
        }

        .status-paid {
            background: #d8f5e1;
            color: #16753b;
        }

        .status-pending {
            background: #fff2d5;
            color: #946200;
        }

        .status-failed {
            background: #ffe0e0;
            color: #ae2424;
        }

        /* =====================================================
           OVERVIEW
        ===================================================== */

        .overview-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid #eaf0eb;
            font-size: 14px;
        }

        .overview-item:last-child {
            border-bottom: none;
        }

        .overview-item strong {
            font-size: 16px;
        }

        /* =====================================================
           NATURE NOTICE
        ===================================================== */

        .nature-notice {
            background: #edf8ed;
            border-left: 5px solid var(--leaf);
            padding: 16px;
            border-radius: 9px;
            color: #315c3a;
            font-size: 13px;
            line-height: 1.7;
        }

        /* =====================================================
           FOOTER
        ===================================================== */

        .hotnet-footer {
            background: var(--forest);
            color: #d7eadb;
            padding: 30px 15px;
            border-radius: 16px;
            text-align: center;
            margin-top: 25px;
        }

        .hotnet-footer .footer-logo {
            color: #a7e17b;
            font-size: 25px;
            font-weight: 900;
            letter-spacing: 2px;
        }

        .hotnet-footer .footer-logo span {
            color: white;
        }

        /* =====================================================
           RESPONSIVE DESIGN
        ===================================================== */

        @media (max-width: 768px) {

            .vegetation-header {
                padding: 25px 20px;
            }

            .hotnet-logo {
                font-size: 27px;
            }

            .stat-number {
                font-size: 24px;
            }

            .content-panel {
                padding: 16px;
            }

            .leaf-decoration {
                font-size: 100px;
                right: 5%;
            }

            .hotnet-navbar {
                align-items: flex-start;
            }

        }

    </style>

</head>

<body>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- =====================================================
         VEGETATION HEADER
    ====================================================== -->

    <div class="vegetation-header">

        <div class="leaf-decoration">
            <i class="bi bi-tree-fill"></i>
        </div>

        <div class="leaf-decoration second">
            <i class="bi bi-flower1"></i>
        </div>

        <div class="header-content">

            <div class="hotnet-logo">
                HOT<span>NET</span>
            </div>

            <h2 class="fw-bold mt-3">
                WiFi Billing Dashboard
            </h2>

            <p class="header-tagline">
                Welcome to HOTNET Network Management System.
                Manage customers, internet packages, vouchers,
                M-Pesa payments and network devices from one place.
            </p>

            <div class="d-flex flex-wrap gap-3 mt-3">

                <span>
                    <i class="bi bi-person-circle"></i>
                    Administrator:
                    <strong>
                        <?php
                        echo hotnetDashboardEscape($_SESSION['admin']);
                        ?>
                    </strong>
                </span>

                <span>
                    <i class="bi bi-calendar3"></i>
                    <?php echo date('l, d F Y'); ?>
                </span>

                <span>
                    <i class="bi bi-clock"></i>
                    <?php echo date('H:i:s'); ?>
                </span>

            </div>

        </div>

    </div>

    <!-- =====================================================
         ADMIN NAVIGATION
    ====================================================== -->

    <nav class="hotnet-navbar">

        <a href="dashboard.php" class="brand">
            <i class="bi bi-tree-fill"></i>
            HOTNET
        </a>

        <div class="navbar-links">

            <a href="dashboard.php">
                <i class="bi bi-speedometer2"></i>
                Dashboard
            </a>

            <a href="users.php">
                <i class="bi bi-people"></i>
                Customers
            </a>

            <a href="packages.php">
                <i class="bi bi-box-seam"></i>
                Packages
            </a>

            <a href="vouchers.php">
                <i class="bi bi-ticket-perforated"></i>
                Vouchers
            </a>

            <a href="payments.php">
                <i class="bi bi-credit-card"></i>
                Payments
            </a>

            <a href="mpesa_callbacks.php">
                <i class="bi bi-phone"></i>
                M-Pesa
            </a>

            <a href="reports.php">
                <i class="bi bi-bar-chart"></i>
                Reports
            </a>

            <a href="../auth/logout.php">
                <i class="bi bi-box-arrow-right"></i>
                Logout
            </a>

        </div>

    </nav>

    <!-- =====================================================
         QUICK ACTIONS
    ====================================================== -->

    <div class="content-panel">

        <h5 class="section-title">
            <i class="bi bi-lightning-charge-fill"></i>
            Quick Actions
        </h5>

        <div class="row g-3">

            <div class="col-6 col-md-4 col-lg-2">
                <a href="users.php" class="quick-action">
                    <i class="bi bi-people-fill"></i>
                    Customers
                </a>
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <a href="packages.php" class="quick-action">
                    <i class="bi bi-box-seam"></i>
                    Packages
                </a>
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <a href="vouchers.php" class="quick-action">
                    <i class="bi bi-ticket-perforated"></i>
                    Vouchers
                </a>
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <a href="payments.php" class="quick-action">
                    <i class="bi bi-cash-stack"></i>
                    Payments
                </a>
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <a href="mpesa_callbacks.php" class="quick-action">
                    <i class="bi bi-phone"></i>
                    M-Pesa Callbacks
                </a>
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <a href="reports.php" class="quick-action">
                    <i class="bi bi-bar-chart-line"></i>
                    Reports
                </a>
            </div>

        </div>

    </div>

    <!-- =====================================================
         MAIN STATISTICS
    ====================================================== -->

    <h5 class="section-title">
        <i class="bi bi-speedometer2"></i>
        Main System Statistics
    </h5>

    <div class="row g-3 mb-4">

        <div class="col-6 col-md-4 col-xl-3">
            <a href="users.php" class="stat-link">
                <div class="dashboard-card">
                    <div class="card-body">

                        <div class="stat-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>

                        <div class="stat-label">
                            Total Customers
                        </div>

                        <div class="stat-number">
                            <?php echo number_format($users); ?>
                        </div>

                        <div class="stat-note">
                            Registered customer accounts
                        </div>

                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-4 col-xl-3">
            <a href="packages.php" class="stat-link">
                <div class="dashboard-card">
                    <div class="card-body">

                        <div class="stat-icon">
                            <i class="bi bi-box-seam"></i>
                        </div>

                        <div class="stat-label">
                            Total Packages
                        </div>

                        <div class="stat-number">
                            <?php echo number_format($packages); ?>
                        </div>

                        <div class="stat-note">
                            Internet packages
                        </div>

                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-4 col-xl-3">
            <a href="vouchers.php" class="stat-link">
                <div class="dashboard-card">
                    <div class="card-body">

                        <div class="stat-icon">
                            <i class="bi bi-ticket-perforated-fill"></i>
                        </div>

                        <div class="stat-label">
                            Total Vouchers
                        </div>

                        <div class="stat-number">
                            <?php echo number_format($vouchers); ?>
                        </div>

                        <div class="stat-note">
                            Generated vouchers
                        </div>

                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-4 col-xl-3">
            <a href="payments.php" class="stat-link">
                <div class="dashboard-card">
                    <div class="card-body">

                        <div class="stat-icon">
                            <i class="bi bi-credit-card-fill"></i>
                        </div>

                        <div class="stat-label">
                            Total Payments
                        </div>

                        <div class="stat-number">
                            <?php echo number_format($payments); ?>
                        </div>

                        <div class="stat-note">
                            All payment records
                        </div>

                    </div>
                </div>
            </a>
        </div>

    </div>

    <!-- =====================================================
         REVENUE OVERVIEW
    ====================================================== -->

    <h5 class="section-title">
        <i class="bi bi-cash-coin"></i>
        Revenue Overview
    </h5>

    <div class="row g-3 mb-4">

        <div class="col-12 col-md-4">
            <div class="dashboard-card">
                <div class="card-body">

                    <div class="stat-icon">
                        <i class="bi bi-wallet2"></i>
                    </div>

                    <div class="stat-label">
                        Total Paid Revenue
                    </div>

                    <div class="stat-number text-success">
                        KSh <?php echo number_format($totalRevenue, 2); ?>
                    </div>

                    <div class="stat-note">
                        Confirmed paid transactions only
                    </div>

                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="dashboard-card">
                <div class="card-body">

                    <div class="stat-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>

                    <div class="stat-label">
                        Today's Revenue
                    </div>

                    <div class="stat-number">
                        KSh <?php echo number_format($todayRevenue, 2); ?>
                    </div>

                    <div class="stat-note">
                        Paid transactions today
                    </div>

                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="dashboard-card">
                <div class="card-body">

                    <div class="stat-icon">
                        <i class="bi bi-calendar-month"></i>
                    </div>

                    <div class="stat-label">
                        This Month's Revenue
                    </div>

                    <div class="stat-number">
                        KSh <?php echo number_format($monthlyRevenue, 2); ?>
                    </div>

                    <div class="stat-note">
                        Confirmed monthly revenue
                    </div>

                </div>
            </div>
        </div>

    </div>

    <!-- =====================================================
         CUSTOMER AND PAYMENT STATUS
    ====================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-12 col-lg-6">

            <div class="content-panel h-100">

                <h5 class="section-title">
                    <i class="bi bi-person-check-fill"></i>
                    Customer Status
                </h5>

                <div class="overview-item">
                    <span>Active Customers</span>
                    <strong class="text-success">
                        <?php echo number_format($activeUsers); ?>
                    </strong>
                </div>

                <div class="overview-item">
                    <span>Inactive Customers</span>
                    <strong class="text-danger">
                        <?php echo number_format($inactiveUsers); ?>
                    </strong>
                </div>

                <div class="overview-item">
                    <span>Total Customers</span>
                    <strong>
                        <?php echo number_format($users); ?>
                    </strong>
                </div>

            </div>

        </div>

        <div class="col-12 col-lg-6">

            <div class="content-panel h-100">

                <h5 class="section-title">
                    <i class="bi bi-receipt"></i>
                    Payment Status
                </h5>

                <div class="overview-item">
                    <span>Paid Payments</span>
                    <strong class="text-success">
                        <?php echo number_format($paidPayments); ?>
                    </strong>
                </div>

                <div class="overview-item">
                    <span>Pending Payments</span>
                    <strong class="text-warning">
                        <?php echo number_format($pendingPayments); ?>
                    </strong>
                </div>

                <div class="overview-item">
                    <span>Failed Payments</span>
                    <strong class="text-danger">
                        <?php echo number_format($failedPayments); ?>
                    </strong>
                </div>

            </div>

        </div>

    </div>

    <!-- =====================================================
         VOUCHER OVERVIEW
    ====================================================== -->

    <div class="content-panel">

        <h5 class="section-title">
            <i class="bi bi-ticket-detailed"></i>
            Voucher Overview
        </h5>

        <div class="row g-3">

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">All Vouchers</div>
                        <div class="stat-number">
                            <?php echo number_format($vouchers); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Active Vouchers</div>
                        <div class="stat-number text-success">
                            <?php echo number_format($activeVouchers); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Used Vouchers</div>
                        <div class="stat-number text-primary">
                            <?php echo number_format($usedVouchers); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Expired Vouchers</div>
                        <div class="stat-number text-danger">
                            <?php echo number_format($expiredVouchers); ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- =====================================================
         DEVICE AND NETWORK OVERVIEW
    ====================================================== -->

    <div class="content-panel">

        <h5 class="section-title">
            <i class="bi bi-router-fill"></i>
            Device and Network Overview
        </h5>

        <div class="row g-3">

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Registered Devices</div>
                        <div class="stat-number">
                            <?php echo number_format($registeredDevices); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Active Devices</div>
                        <div class="stat-number text-success">
                            <?php echo number_format($activeDevices); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Blocked Devices</div>
                        <div class="stat-number text-danger">
                            <?php echo number_format($blockedDevices); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Active Connections</div>
                        <div class="stat-number text-success">
                            <?php echo number_format($activeConnections); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Total Connections</div>
                        <div class="stat-number">
                            <?php echo number_format($totalConnections); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Router Nodes</div>
                        <div class="stat-number">
                            <?php echo number_format($totalRouters); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Enabled Routers</div>
                        <div class="stat-number text-success">
                            <?php echo number_format($enabledRouters); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Access Points</div>
                        <div class="stat-number">
                            <?php echo number_format($totalAccessPoints); ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="nature-notice mt-3">

            <strong>
                <i class="bi bi-info-circle-fill"></i>
                Router Simulation Mode
            </strong>

            <p class="mb-0 mt-1">
                HOTNET currently uses simulation mode because
                MikroTik hardware is not connected. These figures
                represent database records and do not confirm
                real internet connections.
            </p>

        </div>

    </div>

    <!-- =====================================================
         M-PESA CALLBACK MONITOR
    ====================================================== -->

    <div class="content-panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <h5 class="section-title mb-0">
                <i class="bi bi-phone-fill"></i>
                M-Pesa Callback Monitor
            </h5>

            <a href="mpesa_callbacks.php" class="hotnet-btn">
                View Callbacks
                <i class="bi bi-arrow-right"></i>
            </a>

        </div>

        <div class="row g-3">

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Total Callbacks</div>
                        <div class="stat-number">
                            <?php echo number_format($totalCallbacks); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Pending</div>
                        <div class="stat-number text-warning">
                            <?php echo number_format($pendingCallbacks); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Processed</div>
                        <div class="stat-number text-success">
                            <?php echo number_format($processedCallbacks); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="stat-label">Failed</div>
                        <div class="stat-number text-danger">
                            <?php echo number_format($failedCallbacks); ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- =====================================================
         SESSION OVERVIEW
    ====================================================== -->

    <div class="content-panel">

        <h5 class="section-title">
            <i class="bi bi-clock-history"></i>
            Session Overview
        </h5>

        <div class="dashboard-card">

            <div class="card-body d-flex justify-content-between align-items-center">

                <div>
                    <div class="stat-label">
                        Total Recorded Sessions
                    </div>

                    <div class="stat-number">
                        <?php echo number_format($sessions); ?>
                    </div>

                    <div class="stat-note">
                        Session records stored in the database
                    </div>
                </div>

                <div class="stat-icon">
                    <i class="bi bi-wifi"></i>
                </div>

            </div>

        </div>

    </div>

    <!-- =====================================================
         MONTHLY REVENUE CHART
    ====================================================== -->

    <div class="content-panel">

        <h5 class="section-title">
            <i class="bi bi-bar-chart-line-fill"></i>
            Monthly Revenue Performance
        </h5>

        <div style="height: 320px;">
            <canvas id="revenueChart"></canvas>
        </div>

    </div>

    <!-- =====================================================
         RECENT PAYMENTS
    ====================================================== -->

    <div class="content-panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <h5 class="section-title mb-0">
                <i class="bi bi-clock-history"></i>
                Recent Payments
            </h5>

            <a href="payments.php" class="hotnet-btn">
                View All Payments
            </a>

        </div>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Package</th>
                        <th>Amount</th>
                        <th>Reference</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (!empty($recentPayments)): ?>

                    <?php foreach ($recentPayments as $payment): ?>

                        <?php

                        $status = $payment['payment_status'] ?? 'Pending';

                        $statusClass = 'status-pending';

                        if ($status === 'Paid') {
                            $statusClass = 'status-paid';
                        } elseif ($status === 'Failed') {
                            $statusClass = 'status-failed';
                        }

                        $customerName = $payment['fullname']
                            ?: ($payment['customer_name'] ?: 'Guest Customer');

                        $reference = $payment['mpesa_receipt_number']
                            ?: $payment['transaction_code'];

                        ?>

                        <tr>

                            <td>
                                <strong>
                                    <?php
                                    echo hotnetDashboardEscape($customerName);
                                    ?>
                                </strong>
                            </td>

                            <td>
                                <?php
                                echo hotnetDashboardEscape(
                                    $payment['customer_phone'] ?? '-'
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo hotnetDashboardEscape(
                                    $payment['package_name'] ?? 'Unknown'
                                );
                                ?>
                            </td>

                            <td>
                                <strong>
                                    KSh
                                    <?php
                                    echo number_format(
                                        (float) $payment['amount'],
                                        2
                                    );
                                    ?>
                                </strong>
                            </td>

                            <td>
                                <?php
                                echo hotnetDashboardEscape(
                                    $reference ?: '-'
                                );
                                ?>
                            </td>

                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php
                                    echo hotnetDashboardEscape($status);
                                    ?>
                                </span>
                            </td>

                            <td>
                                <?php

                                echo !empty($payment['payment_date'])
                                    ? date(
                                        'd M Y H:i',
                                        strtotime($payment['payment_date'])
                                    )
                                    : '-';

                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No payment records found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- =====================================================
         SYSTEM INFORMATION
    ====================================================== -->

    <div class="content-panel">

        <h5 class="section-title">
            <i class="bi bi-tree-fill"></i>
            HOTNET System Overview
        </h5>

        <p>
            HOTNET is a WiFi billing and network management system
            designed to manage customers, internet packages,
            vouchers, payments, devices and network activity.
        </p>

        <p>
            Successful M-Pesa transactions are confirmed through
            the callback system. This dashboard is for monitoring
            and reporting; it does not provide manual payment recording.
        </p>

        <div class="nature-notice">

            <strong>
                <i class="bi bi-headset"></i>
                HOTNET Customer Support
            </strong>

            <p class="mb-0 mt-1">
                Contact:
                <strong>0718347038</strong>
            </p>

        </div>

    </div>

    <!-- =====================================================
         FOOTER
    ====================================================== -->

    <footer class="hotnet-footer">

        <div class="footer-logo">
            HOT<span>NET</span>
        </div>

        <p class="mt-2 mb-1">
            WiFi Billing and Network Management System
        </p>

        <p class="mb-0">
            &copy; <?php echo date('Y'); ?> HOTNET.
            All rights reserved.
        </p>

        <small>
            Growing a better connected network.
        </small>

    </footer>

</div>

<!-- =====================================================
     JAVASCRIPT
====================================================== -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/*
|--------------------------------------------------------------------------
| MONTHLY REVENUE CHART
|--------------------------------------------------------------------------
*/

const chartLabels = <?php echo json_encode($chartLabels); ?>;
const chartValues = <?php echo json_encode($chartValues); ?>;

const revenueCanvas = document.getElementById('revenueChart');

new Chart(revenueCanvas, {

    type: 'bar',

    data: {

        labels: chartLabels,

        datasets: [{

            label: 'Paid Revenue (KSh)',

            data: chartValues,

            backgroundColor: 'rgba(37, 138, 69, 0.75)',

            borderColor: '#258a45',

            borderWidth: 2,

            borderRadius: 8

        }]

    },

    options: {

        responsive: true,

        maintainAspectRatio: false,

        plugins: {

            legend: {
                display: true
            },

            tooltip: {

                callbacks: {

                    label: function(context) {

                        return 'KSh ' +
                            Number(context.raw).toLocaleString();

                    }

                }

            }

        },

        scales: {

            y: {

                beginAtZero: true,

                ticks: {

                    callback: function(value) {

                        return 'KSh ' +
                            Number(value).toLocaleString();

                    }

                }

            }

        }

    }

});

</script>

</body>
</html>