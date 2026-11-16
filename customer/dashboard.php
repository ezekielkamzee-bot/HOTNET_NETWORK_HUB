
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

mysqli_set_charset($conn, "utf8mb4");

/* =====================================================
   CUSTOMER AUTHENTICATION
===================================================== */

if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit;
}

$customer_id = (int) $_SESSION['customer_id'];

/* =====================================================
   HELPER
===================================================== */

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =====================================================
   CUSTOMER DETAILS
===================================================== */

$stmt = $conn->prepare(
    "SELECT id, fullname, username, phone, status, created_at
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer || $customer['status'] !== 'Active') {
    session_unset();
    session_destroy();

    header("Location: login.php");
    exit;
}

/* =====================================================
   VOUCHER STATISTICS
===================================================== */

$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total_vouchers,

        SUM(
            CASE
                WHEN status = 'Active'
                 AND (expiry_date IS NULL OR expiry_date >= NOW())
                THEN 1 ELSE 0
            END
        ) AS active_vouchers,

        SUM(
            CASE WHEN status = 'Used'
            THEN 1 ELSE 0 END
        ) AS used_vouchers,

        SUM(
            CASE
                WHEN status = 'Expired'
                  OR (status = 'Active' AND expiry_date < NOW())
                THEN 1 ELSE 0
            END
        ) AS expired_vouchers

     FROM vouchers
     WHERE user_id = ?"
);

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$voucher_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_vouchers = (int)($voucher_stats['total_vouchers'] ?? 0);
$active_vouchers = (int)($voucher_stats['active_vouchers'] ?? 0);
$used_vouchers = (int)($voucher_stats['used_vouchers'] ?? 0);
$expired_vouchers = (int)($voucher_stats['expired_vouchers'] ?? 0);

/* =====================================================
   LATEST ACTIVE VOUCHER
===================================================== */

$stmt = $conn->prepare(
    "SELECT
        v.id,
        v.voucher_code,
        v.status,
        v.expiry_date,
        v.created_at,
        p.package_name,
        p.speed,
        p.package_type

     FROM vouchers v

     LEFT JOIN packages p
        ON p.id = v.package_id

     WHERE v.user_id = ?
       AND v.status = 'Active'
       AND (v.expiry_date IS NULL OR v.expiry_date >= NOW())

     ORDER BY v.created_at DESC
     LIMIT 1"
);

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$active_voucher = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* =====================================================
   PAYMENT STATISTICS
===================================================== */

$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total_payments,

        SUM(
            CASE WHEN payment_status = 'Paid'
            THEN 1 ELSE 0 END
        ) AS paid_payments,

        SUM(
            CASE WHEN payment_status = 'Pending'
            THEN 1 ELSE 0 END
        ) AS pending_payments,

        SUM(
            CASE WHEN payment_status = 'Failed'
            THEN 1 ELSE 0 END
        ) AS failed_payments,

        COALESCE(
            SUM(
                CASE WHEN payment_status = 'Paid'
                THEN amount ELSE 0 END
            ), 0
        ) AS total_spent

     FROM payments
     WHERE user_id = ?"
);

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$payment_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_payments = (int)($payment_stats['total_payments'] ?? 0);
$paid_payments = (int)($payment_stats['paid_payments'] ?? 0);
$pending_payments = (int)($payment_stats['pending_payments'] ?? 0);
$failed_payments = (int)($payment_stats['failed_payments'] ?? 0);
$total_spent = (float)($payment_stats['total_spent'] ?? 0);

/* =====================================================
   REGISTERED DEVICE STATISTICS
===================================================== */

$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total_devices,
        SUM(
            CASE WHEN status = 'Active'
            THEN 1 ELSE 0 END
        ) AS active_devices

     FROM customer_devices
     WHERE user_id = ?"
);

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$device_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_devices = (int)($device_stats['total_devices'] ?? 0);
$active_devices = (int)($device_stats['active_devices'] ?? 0);

/* =====================================================
   RECENT PAYMENTS
===================================================== */

$stmt = $conn->prepare(
    "SELECT
        pay.id,
        pay.amount,
        pay.transaction_code,
        pay.mpesa_receipt_number,
        pay.payment_status,
        pay.payment_date,
        pay.payment_method,
        pay.customer_phone,
        p.package_name

     FROM payments pay

     LEFT JOIN packages p
        ON p.id = pay.package_id

     WHERE pay.user_id = ?

     ORDER BY pay.payment_date DESC
     LIMIT 5"
);

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$payment_result = $stmt->get_result();

$recent_payments = [];

while ($row = $payment_result->fetch_assoc()) {
    $recent_payments[] = $row;
}

$stmt->close();

/* =====================================================
   DISPLAY CUSTOMER NAME
===================================================== */

$customer_name = $customer['fullname'] ?: $customer['username'];

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Customer Dashboard | HOTNET WiFi</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>

:root {
    --orange: #f97316;
    --dark-orange: #c2410c;
    --light-orange: #fff7ed;
    --dark: #292524;
    --muted: #78716c;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f8fafc;
    color: var(--dark);
    font-family: Arial, sans-serif;
}

/* NAVIGATION */

.navbar {
    background: linear-gradient(110deg, #c2410c, #f97316);
    padding: 15px 0;
    box-shadow: 0 3px 15px rgba(0,0,0,.12);
}

.navbar-brand {
    color: white !important;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: 1px;
}

.nav-link {
    color: white !important;
    font-weight: 600;
    font-size: 14px;
}

.nav-link:hover {
    color: #ffedd5 !important;
}

/* WELCOME HEADER */

.welcome-section {
    background: linear-gradient(120deg, #9a3412, #ea580c, #f97316);
    color: white;
    padding: 35px;
    border-radius: 18px;
    box-shadow: 0 8px 25px rgba(234,88,12,.18);
}

.welcome-section h2 {
    font-weight: 800;
}

.welcome-section p {
    color: #ffedd5;
}

/* STATISTICS */

.stat-card {
    background: white;
    border-radius: 16px;
    padding: 22px;
    height: 100%;
    border: 1px solid #f1f5f9;
    box-shadow: 0 5px 18px rgba(0,0,0,.04);
    transition: .2s;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(0,0,0,.08);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 13px;
    background: var(--light-orange);
    color: var(--orange);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 23px;
    margin-bottom: 14px;
}

.stat-number {
    font-size: 29px;
    font-weight: 800;
    color: var(--dark-orange);
}

.stat-label {
    color: var(--muted);
    font-size: 14px;
}

/* CONTENT CARDS */

.content-card {
    background: white;
    border-radius: 17px;
    padding: 25px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 5px 20px rgba(0,0,0,.04);
    height: 100%;
}

.section-title {
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 20px;
}

/* VOUCHER */

.voucher-box {
    background: var(--light-orange);
    border: 1px dashed var(--orange);
    padding: 20px;
    border-radius: 13px;
}

.voucher-code {
    color: var(--dark-orange);
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 1px;
    overflow-wrap: anywhere;
}

/* QUICK LINKS */

.quick-link {
    background: white;
    border: 1px solid #e7e5e4;
    border-radius: 13px;
    padding: 18px;
    text-decoration: none;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: .2s;
    height: 100%;
}

.quick-link:hover {
    border-color: var(--orange);
    background: var(--light-orange);
    color: var(--dark-orange);
    transform: translateY(-2px);
}

.quick-link-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    background: var(--light-orange);
    color: var(--orange);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

/* BUTTONS */

.btn-hotnet {
    background: var(--orange);
    border: none;
    color: white;
    border-radius: 9px;
    padding: 10px 17px;
    font-weight: 700;
}

.btn-hotnet:hover {
    background: var(--dark-orange);
    color: white;
}

/* TABLE */

.table thead th {
    background: #fff7ed;
    color: #9a3412;
    font-size: 13px;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
    font-size: 14px;
}

.payment-status {
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
}

/* FOOTER */

footer {
    background: #292524;
    color: #e7e5e4;
    margin-top: 50px;
    padding: 25px;
}

footer small {
    color: #a8a29e;
}

/* MOBILE */

@media(max-width: 768px) {

    .welcome-section {
        padding: 25px 20px;
    }

    .welcome-section h2 {
        font-size: 23px;
    }

    .content-card {
        padding: 18px;
    }

    .navbar-brand {
        font-size: 20px;
    }

}

</style>

</head>

<body>

<!-- ==================================================
     NAVIGATION
================================================== -->

<nav class="navbar navbar-expand-lg">

<div class="container">

<a class="navbar-brand" href="dashboard.php">
<i class="bi bi-wifi"></i> HOTNET
</a>

<button class="navbar-toggler bg-light"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#customerNavbar">

<span class="navbar-toggler-icon"></span>

</button>

<div class="collapse navbar-collapse" id="customerNavbar">

<ul class="navbar-nav ms-auto">

<li class="nav-item">
<a class="nav-link" href="dashboard.php">
<i class="bi bi-speedometer2"></i> Dashboard
</a>
</li>

<li class="nav-item">
<a class="nav-link" href="buy_package.php">
<i class="bi bi-bag"></i> Buy Package
</a>
</li>

<li class="nav-item">
<a class="nav-link" href="my_vouchers.php">
<i class="bi bi-ticket-perforated"></i> My Vouchers
</a>
</li>

<li class="nav-item">
<a class="nav-link" href="devices.php">
<i class="bi bi-laptop"></i> My Devices
</a>
</li>

<li class="nav-item">
<a class="nav-link" href="my_payments.php">
<i class="bi bi-wallet2"></i> Payments
</a>
</li>

<li class="nav-item">
<a class="nav-link" href="profile.php">
<i class="bi bi-person-circle"></i> Profile
</a>
</li>

<li class="nav-item">
<a class="nav-link" href="logout.php">
<i class="bi bi-box-arrow-right"></i> Logout
</a>
</li>

</ul>

</div>
</div>

</nav>


<div class="container py-4">

<!-- ==================================================
     WELCOME
================================================== -->

<div class="welcome-section mb-4">

<div class="row align-items-center">

<div class="col-md-8">

<h2>
Welcome, <?php echo h($customer_name); ?>!
</h2>

<p class="mb-0">
Manage your HOTNET account, purchase WiFi packages,
view vouchers and manage your registered devices.
</p>

</div>

<div class="col-md-4 text-md-end mt-3 mt-md-0">

<a href="buy_package.php" class="btn btn-light fw-bold">

<i class="bi bi-plus-circle"></i>
Buy WiFi Package

</a>

</div>

</div>

</div>


<!-- ==================================================
     ACCOUNT STATISTICS
================================================== -->

<h4 class="section-title mb-3">
Account Overview
</h4>

<div class="row g-3 mb-4">


<div class="col-6 col-lg-3">

<div class="stat-card">

<div class="stat-icon">
<i class="bi bi-ticket-perforated"></i>
</div>

<div class="stat-number">
<?php echo $total_vouchers; ?>
</div>

<div class="stat-label">
Total Vouchers
</div>

</div>

</div>


<div class="col-6 col-lg-3">

<div class="stat-card">

<div class="stat-icon">
<i class="bi bi-check-circle"></i>
</div>

<div class="stat-number">
<?php echo $active_vouchers; ?>
</div>

<div class="stat-label">
Active Vouchers
</div>

</div>

</div>


<div class="col-6 col-lg-3">

<div class="stat-card">

<div class="stat-icon">
<i class="bi bi-laptop"></i>
</div>

<div class="stat-number">
<?php echo $active_devices; ?>
</div>

<div class="stat-label">
Active Registered Devices
</div>

</div>

</div>


<div class="col-6 col-lg-3">

<div class="stat-card">

<div class="stat-icon">
<i class="bi bi-wallet2"></i>
</div>

<div class="stat-number">
KSh <?php echo number_format($total_spent, 2); ?>
</div>

<div class="stat-label">
Total Successful Payments
</div>

</div>

</div>

</div>


<!-- ==================================================
     QUICK ACCESS
================================================== -->

<h4 class="section-title mb-3">
Quick Access
</h4>

<div class="row g-3 mb-4">


<div class="col-6 col-lg-3">

<a href="buy_package.php" class="quick-link">

<div class="quick-link-icon">
<i class="bi bi-wifi"></i>
</div>

<div>
<strong>Buy Package</strong>
<div class="small text-muted">Purchase WiFi</div>
</div>

</a>

</div>


<div class="col-6 col-lg-3">

<a href="my_vouchers.php" class="quick-link">

<div class="quick-link-icon">
<i class="bi bi-ticket-perforated"></i>
</div>

<div>
<strong>My Vouchers</strong>
<div class="small text-muted">View your vouchers</div>
</div>

</a>

</div>


<div class="col-6 col-lg-3">

<a href="devices.php" class="quick-link">

<div class="quick-link-icon">
<i class="bi bi-phone"></i>
</div>

<div>
<strong>My Devices</strong>
<div class="small text-muted">Manage devices</div>
</div>

</a>

</div>


<div class="col-6 col-lg-3">

<a href="my_payments.php" class="quick-link">

<div class="quick-link-icon">
<i class="bi bi-receipt"></i>
</div>

<div>
<strong>My Payments</strong>
<div class="small text-muted">Payment history</div>
</div>

</a>

</div>

</div>


<!-- ==================================================
     ACTIVE VOUCHER
================================================== -->

<div class="row g-4 mb-4">

<div class="col-lg-7">

<div class="content-card">

<h4 class="section-title">
<i class="bi bi-ticket-perforated text-warning"></i>
My Active Voucher
</h4>


<?php if ($active_voucher): ?>

<div class="voucher-box">

<div class="text-muted small mb-2">
YOUR VOUCHER CODE
</div>

<div class="voucher-code mb-3">
<?php echo h($active_voucher['voucher_code']); ?>
</div>

<div class="row g-3">

<div class="col-6">

<div class="small text-muted">
Package
</div>

<strong>
<?php echo h($active_voucher['package_name'] ?? 'WiFi Package'); ?>
</strong>

</div>

<div class="col-6">

<div class="small text-muted">
Speed
</div>

<strong>
<?php echo h($active_voucher['speed'] ?? 'Not specified'); ?>
</strong>

</div>

<div class="col-6">

<div class="small text-muted">
Package Type
</div>

<strong>
<?php echo h($active_voucher['package_type'] ?? 'Not specified'); ?>
</strong>

</div>

<div class="col-6">

<div class="small text-muted">
Expiry Date
</div>

<strong>

<?php

if ($active_voucher['expiry_date']) {
    echo h(date(
        'd M Y H:i',
        strtotime($active_voucher['expiry_date'])
    ));
} else {
    echo 'Not specified';
}

?>

</strong>

</div>

</div>

<div class="mt-3">

<span class="badge bg-success">
Voucher Active
</span>

</div>

</div>

<div class="small text-muted mt-3">

Voucher status confirms account entitlement.
It does not independently confirm that a router connection
is currently established.

</div>

<a href="my_vouchers.php" class="btn btn-hotnet mt-3">
View All Vouchers
</a>

<?php else: ?>

<div class="text-center py-4">

<i class="bi bi-ticket-perforated display-3 text-secondary"></i>

<h5 class="mt-3">No Active Voucher</h5>

<p class="text-muted">
You currently have no active, unexpired voucher.
</p>

<a href="buy_package.php" class="btn btn-hotnet">
Purchase WiFi Package
</a>

</div>

<?php endif; ?>

</div>

</div>


<!-- ==================================================
     PAYMENT SUMMARY
================================================== -->

<div class="col-lg-5">

<div class="content-card">

<h4 class="section-title">
<i class="bi bi-wallet2 text-warning"></i>
Payment Summary
</h4>

<div class="d-flex justify-content-between mb-3">

<span class="text-muted">Total Payments</span>

<strong><?php echo $total_payments; ?></strong>

</div>

<div class="d-flex justify-content-between mb-3">

<span class="text-muted">Successful</span>

<strong class="text-success">
<?php echo $paid_payments; ?>
</strong>

</div>

<div class="d-flex justify-content-between mb-3">

<span class="text-muted">Pending</span>

<strong class="text-warning">
<?php echo $pending_payments; ?>
</strong>

</div>

<div class="d-flex justify-content-between mb-3">

<span class="text-muted">Failed</span>

<strong class="text-danger">
<?php echo $failed_payments; ?>
</strong>

</div>

<hr>

<div class="text-muted small">
Total Successful Payments
</div>

<div class="stat-number">
KSh <?php echo number_format($total_spent, 2); ?>
</div>

<a href="my_payments.php" class="btn btn-hotnet w-100 mt-3">
View Payment History
</a>

</div>

</div>

</div>


<!-- ==================================================
     RECENT PAYMENTS
================================================== -->

<div class="content-card">

<div class="d-flex justify-content-between align-items-center mb-3">

<h4 class="section-title mb-0">
<i class="bi bi-clock-history text-warning"></i>
Recent Payments
</h4>

<a href="my_payments.php"
   class="text-decoration-none fw-bold"
   style="color:#ea580c;">

View All

</a>

</div>


<?php if (empty($recent_payments)): ?>

<div class="text-center py-4">

<i class="bi bi-receipt display-3 text-secondary"></i>

<p class="text-muted mt-3">
You have not made any payments yet.
</p>

<a href="buy_package.php" class="btn btn-hotnet">
Buy Your First Package
</a>

</div>

<?php else: ?>


<div class="table-responsive">

<table class="table table-hover align-middle">

<thead>

<tr>

<th>Package</th>
<th>Amount</th>
<th>Transaction</th>
<th>Status</th>
<th>Date</th>

</tr>

</thead>

<tbody>

<?php foreach ($recent_payments as $payment): ?>

<tr>

<td>
<strong>
<?php echo h($payment['package_name'] ?? 'WiFi Package'); ?>
</strong>
</td>

<td>
KSh <?php echo number_format((float)$payment['amount'], 2); ?>
</td>

<td>

<?php

$reference = $payment['mpesa_receipt_number']
    ?: $payment['transaction_code'];

echo h($reference ?: 'Not available');

?>

</td>

<td>

<?php if ($payment['payment_status'] === 'Paid'): ?>

<span class="badge bg-success payment-status">
Paid
</span>

<?php elseif ($payment['payment_status'] === 'Pending'): ?>

<span class="badge bg-warning text-dark payment-status">
Pending
</span>

<?php else: ?>

<span class="badge bg-danger payment-status">
Failed
</span>

<?php endif; ?>

</td>

<td class="small">
<?php echo h(date(
    'd M Y H:i',
    strtotime($payment['payment_date'])
)); ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>


<!-- ==================================================
     CONNECTION NOTICE
================================================== -->

<div class="alert alert-warning mt-4">

<strong>
<i class="bi bi-info-circle-fill"></i>
HOTNET Connection Status
</strong>

<p class="mb-0 mt-2 small">

Your customer account, vouchers and device registrations
are managed here. Router connection, force reconnection
and roaming features will be activated when the router
integration is configured. The current router mode is
Simulation.

</p>

</div>


</div>


<!-- ==================================================
     FOOTER
================================================== -->

<footer class="text-center">

<h5 class="fw-bold">
<i class="bi bi-wifi"></i> HOTNET WiFi
</h5>

<p class="mb-1">
Reliable WiFi. Simple Payments. Easy Connectivity.
</p>

<small>
Customer Support: 0718347038
</small>

</footer>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
