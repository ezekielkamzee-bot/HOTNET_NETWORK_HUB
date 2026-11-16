
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| CUSTOMER LOGIN CHECK
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}

$customer_id = (int) $_SESSION['customer_id'];

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function paymentStatusClass(string $status): string {
    switch (strtolower($status)) {
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
| VERIFY CUSTOMER
|--------------------------------------------------------------------------
*/
$userStmt = $conn->prepare("
    SELECT fullname, username, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

$userStmt->bind_param("i", $customer_id);
$userStmt->execute();

$customer = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$customer || $customer['status'] !== 'Active') {
    session_destroy();
    header("Location: login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| PAYMENT STATISTICS
|--------------------------------------------------------------------------
*/
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_payments,

        COALESCE(SUM(
            CASE
                WHEN payment_status = 'Paid' THEN amount
                ELSE 0
            END
        ), 0) AS total_paid,

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
        ) AS failed_count

    FROM payments
    WHERE user_id = ?
");

$statsStmt->bind_param("i", $customer_id);
$statsStmt->execute();

$stats = $statsStmt->get_result()->fetch_assoc();

$statsStmt->close();

$totalPayments = (int)($stats['total_payments'] ?? 0);
$totalPaid = (float)($stats['total_paid'] ?? 0);
$paidCount = (int)($stats['paid_count'] ?? 0);
$pendingCount = (int)($stats['pending_count'] ?? 0);
$failedCount = (int)($stats['failed_count'] ?? 0);

/*
|--------------------------------------------------------------------------
| LOAD CUSTOMER PAYMENT HISTORY
|--------------------------------------------------------------------------
*/
$paymentSql = "
    SELECT
        p.id,
        p.customer_name,
        p.customer_phone,
        p.amount,
        p.transaction_code,
        p.mpesa_receipt_number,
        p.checkout_request_id,
        p.merchant_request_id,
        p.result_description,
        p.payment_method,
        p.payment_status,
        p.payment_date,

        pkg.package_name,
        pkg.package_type,
        pkg.speed

    FROM payments p

    LEFT JOIN packages pkg
        ON pkg.id = p.package_id

    WHERE p.user_id = ?

    ORDER BY p.id DESC
";

$paymentStmt = $conn->prepare($paymentSql);
$paymentStmt->bind_param("i", $customer_id);
$paymentStmt->execute();

$paymentResult = $paymentStmt->get_result();

$payments = [];

while ($row = $paymentResult->fetch_assoc()) {
    $payments[] = $row;
}

$paymentStmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Payments | HOTNET</title>

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
            --hotnet-orange: #ff6b00;
            --hotnet-dark: #172033;
            --hotnet-light: #fff7f0;
            --hotnet-border: #f0e5dc;
        }

        body {
            background: #f5f7fb;
            color: var(--hotnet-dark);
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: #fff;
            box-shadow: 0 3px 15px rgba(0,0,0,.06);
        }

        .brand {
            color: var(--hotnet-orange) !important;
            font-size: 25px;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .nav-link {
            color: #4b5563;
            font-weight: 600;
        }

        .nav-link:hover,
        .nav-link.active {
            color: var(--hotnet-orange);
        }

        .page-heading {
            font-weight: 800;
        }

        .page-subtitle {
            color: #718096;
        }

        .summary-card {
            background: #fff;
            border: 1px solid var(--hotnet-border);
            border-radius: 16px;
            padding: 22px;
            height: 100%;
            box-shadow: 0 5px 18px rgba(0,0,0,.035);
        }

        .summary-icon {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: var(--hotnet-light);
            color: var(--hotnet-orange);
            font-size: 21px;
        }

        .summary-number {
            font-size: 27px;
            font-weight: 800;
            margin-top: 12px;
        }

        .summary-label {
            color: #718096;
            font-size: 13px;
            font-weight: 600;
        }

        .content-card {
            background: #fff;
            border: 1px solid var(--hotnet-border);
            border-radius: 17px;
            padding: 22px;
            box-shadow: 0 5px 18px rgba(0,0,0,.035);
        }

        .table thead th {
            background: #fff7f0;
            color: #68432b;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
            white-space: nowrap;
            padding: 15px;
        }

        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            font-size: 13px;
        }

        .table tbody tr:hover {
            background: #fffaf6;
        }

        .status-badge {
            border-radius: 30px;
            padding: 7px 12px;
            font-size: 11px;
            font-weight: 800;
        }

        .amount {
            color: #15803d;
            font-weight: 800;
            white-space: nowrap;
        }

        .reference {
            font-weight: 700;
            word-break: break-word;
        }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
        }

        .empty-icon {
            font-size: 55px;
            color: #ffb16f;
        }

        .btn-hotnet {
            background: var(--hotnet-orange);
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: 11px 17px;
            font-weight: 700;
        }

        .btn-hotnet:hover {
            background: #e85f00;
            color: #fff;
        }

        .btn-outline-hotnet {
            border: 1px solid var(--hotnet-orange);
            color: var(--hotnet-orange);
            border-radius: 9px;
            font-weight: 700;
        }

        .btn-outline-hotnet:hover {
            background: var(--hotnet-orange);
            color: #fff;
        }

        footer {
            background: #172033;
            color: #cbd5e1;
            padding: 25px 0;
            margin-top: 55px;
        }

        footer strong {
            color: #ff9b42;
        }

        @media (max-width: 576px) {
            .summary-card {
                padding: 16px;
            }

            .page-heading {
                font-size: 25px;
            }

            .content-card {
                padding: 14px;
            }
        }
    </style>

</head>

<body>

<!-- NAVIGATION -->

<nav class="navbar navbar-expand-lg sticky-top">

    <div class="container">

        <a class="navbar-brand brand" href="dashboard.php">
            <i class="bi bi-wifi"></i> HOTNET
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#customerNavbar"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="customerNavbar">

            <ul class="navbar-nav ms-auto align-items-lg-center">

                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">Dashboard</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="buy_package.php">Buy Package</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="my_vouchers.php">My Vouchers</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="devices.php">My Devices</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="my_payments.php">Payments</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="profile.php">Profile</a>
                </li>

                <li class="nav-item ms-lg-2">
                    <a class="btn btn-outline-danger btn-sm px-3" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </li>

            </ul>

        </div>

    </div>

</nav>

<main class="container py-5">

    <!-- PAGE HEADER -->

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>

            <h2 class="page-heading mb-1">
                My Payments
            </h2>

            <p class="page-subtitle mb-0">
                Welcome, <?= h($customer['fullname']) ?>.
                View your HOTNET payment history.
            </p>

        </div>

        <a href="buy_package.php" class="btn btn-hotnet">
            <i class="bi bi-plus-circle"></i> Buy Package
        </a>

    </div>

    <!-- PAYMENT STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">

            <div class="summary-card">

                <div class="summary-icon">
                    <i class="bi bi-receipt"></i>
                </div>

                <div class="summary-number">
                    <?= $totalPayments ?>
                </div>

                <div class="summary-label">
                    Total Transactions
                </div>

            </div>

        </div>

        <div class="col-6 col-lg-3">

            <div class="summary-card">

                <div class="summary-icon">
                    <i class="bi bi-cash-stack"></i>
                </div>

                <div class="summary-number text-success">
                    KSh <?= number_format($totalPaid, 2) ?>
                </div>

                <div class="summary-label">
                    Total Successful Payments
                </div>

            </div>

        </div>

        <div class="col-6 col-lg-3">

            <div class="summary-card">

                <div class="summary-icon">
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <div class="summary-number text-warning">
                    <?= $pendingCount ?>
                </div>

                <div class="summary-label">
                    Pending Payments
                </div>

            </div>

        </div>

        <div class="col-6 col-lg-3">

            <div class="summary-card">

                <div class="summary-icon">
                    <i class="bi bi-x-circle"></i>
                </div>

                <div class="summary-number text-danger">
                    <?= $failedCount ?>
                </div>

                <div class="summary-label">
                    Failed Payments
                </div>

            </div>

        </div>

    </div>

    <!-- PAYMENT HISTORY -->

    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">

            <div>
                <h4 class="fw-bold mb-1">
                    Transaction History
                </h4>

                <p class="text-muted small mb-0">
                    Your recorded package payment transactions.
                </p>
            </div>

            <span class="badge text-bg-light border">
                <?= $paidCount ?> successful
            </span>

        </div>

        <?php if (empty($payments)): ?>

            <div class="empty-state">

                <div class="empty-icon">
                    <i class="bi bi-receipt"></i>
                </div>

                <h4 class="fw-bold mt-3">
                    No Payments Found
                </h4>

                <p class="text-muted">
                    Your payment history will appear here after
                    you purchase a HOTNET package.
                </p>

                <a href="buy_package.php" class="btn btn-hotnet mt-2">
                    <i class="bi bi-wifi"></i> Browse Packages
                </a>

            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead>

                        <tr>
                            <th>Transaction</th>
                            <th>Package</th>
                            <th>Phone</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Receipt</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($payments as $payment): ?>

                            <?php
                                $status = $payment['payment_status'] ?? 'Unknown';

                                $paymentDate = 'Not recorded';

                                if (!empty($payment['payment_date'])) {
                                    $timestamp = strtotime($payment['payment_date']);

                                    if ($timestamp !== false) {
                                        $paymentDate = date(
                                            'd M Y, h:i A',
                                            $timestamp
                                        );
                                    }
                                }

                                $reference = $payment['transaction_code']
                                    ?: ($payment['checkout_request_id'] ?? '');

                                $receipt = $payment['mpesa_receipt_number']
                                    ?: 'Not available';
                            ?>

                            <tr>

                                <td>
                                    <div class="reference">
                                        <?= h($reference ?: 'Pending reference') ?>
                                    </div>

                                    <small class="text-muted">
                                        Payment #<?= (int)$payment['id'] ?>
                                    </small>
                                </td>

                                <td>
                                    <strong>
                                        <?= h($payment['package_name'] ?? 'Package') ?>
                                    </strong>

                                    <br>

                                    <small class="text-muted">
                                        <?= h($payment['package_type'] ?? '') ?>
                                        <?= !empty($payment['speed'])
                                            ? ' • ' . h($payment['speed'])
                                            : '' ?>
                                    </small>
                                </td>

                                <td>
                                    <?= h($payment['customer_phone'] ?? 'N/A') ?>
                                </td>

                                <td class="amount">
                                    KSh <?= number_format((float)$payment['amount'], 2) ?>
                                </td>

                                <td>
                                    <?= h($payment['payment_method'] ?? 'M-Pesa') ?>
                                </td>

                                <td>
                                    <?= h($paymentDate) ?>
                                </td>

                                <td>
                                    <span class="badge text-bg-<?= h(paymentStatusClass($status)) ?> status-badge">
                                        <?= h($status) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($receipt !== 'Not available'): ?>

                                        <span class="reference text-success">
                                            <?= h($receipt) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="text-muted small">
                                            Not available
                                        </span>

                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

    <!-- PAYMENT INFORMATION -->

    <div class="alert alert-info border-0 rounded-3 mt-4">

        <i class="bi bi-info-circle"></i>

        <strong>Payment information:</strong>

        Only transactions marked <strong>Paid</strong> are included
        in your successful payment total. Pending and failed
        transactions are displayed separately.

        If an M-Pesa receipt is not available, check the transaction
        status or contact HOTNET support.

    </div>

</main>

<!-- FOOTER -->

<footer>

    <div class="container text-center">

        <div class="fw-bold mb-1">
            <strong>HOTNET</strong> WiFi Billing System
        </div>

        <div class="small">
            Customer Support: 0718347038
        </div>

        <div class="small mt-2">
            &copy; <?= date('Y') ?> HOTNET. All Rights Reserved.
        </div>

    </div>

</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
