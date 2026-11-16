
<?php

require_once __DIR__ . '/config/database.php';

$sql = "
    SELECT
        id,
        package_name,
        package_type,
        price,
        status
    FROM packages
    WHERE status = 'Active'
    ORDER BY id ASC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Database error: " . htmlspecialchars(mysqli_error($conn)));
}

$packages = [];

while ($row = mysqli_fetch_assoc($result)) {
    $packages[] = $row;
}

mysqli_free_result($result);

$totalPackages = count($packages);

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>HOTNET | Active Packages</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        rel="stylesheet">

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f5f6fa;
            font-family: Arial, sans-serif;
            color: #333;
        }

        /* HEADER */

        .header {
            background: linear-gradient(135deg, #ff6b00, #ff9800);
            color: white;
            padding: 35px 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.12);
        }

        .brand {
            font-size: 30px;
            font-weight: 800;
            letter-spacing: 2px;
        }

        .header-subtitle {
            opacity: 0.9;
            margin-top: 8px;
        }

        /* MAIN CONTENT */

        .main-container {
            max-width: 1200px;
            margin: 35px auto;
            padding: 0 20px;
        }

        /* SUMMARY */

        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 22px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .summary-number {
            color: #ff6b00;
            font-size: 32px;
            font-weight: bold;
        }

        .summary-label {
            color: #777;
            font-size: 14px;
        }

        .summary-icon {
            width: 60px;
            height: 60px;
            background: #fff0e5;
            color: #ff6b00;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
        }

        /* SECTION TITLE */

        .section-title {
            font-size: 23px;
            font-weight: bold;
            margin-bottom: 22px;
        }

        /* PACKAGE CARDS */

        .package-card {
            background: white;
            border: none;
            border-radius: 16px;
            overflow: hidden;
            height: 100%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            transition: 0.3s ease;
        }

        .package-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 10px 25px rgba(255,107,0,0.15);
        }

        .package-top {
            background: linear-gradient(135deg, #ff6b00, #ff9800);
            color: white;
            padding: 22px;
        }

        .package-name {
            font-size: 21px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .package-id {
            font-size: 12px;
            opacity: 0.85;
        }

        .package-body {
            padding: 22px;
        }

        .package-price {
            font-size: 29px;
            font-weight: bold;
            color: #ff6b00;
            margin-bottom: 18px;
        }

        .package-detail {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        .package-detail:last-of-type {
            border-bottom: none;
        }

        .detail-label {
            color: #777;
        }

        .status {
            display: inline-block;
            background: #e4f8eb;
            color: #198754;
            padding: 6px 13px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .package-footer {
            padding: 0 22px 22px;
        }

        .package-footer a {
            display: block;
            text-decoration: none;
            text-align: center;
            background: #fff0e5;
            color: #ff6b00;
            padding: 11px;
            border-radius: 9px;
            font-weight: bold;
        }

        .package-footer a:hover {
            background: #ff6b00;
            color: white;
        }

        /* EMPTY STATE */

        .empty-state {
            background: white;
            border-radius: 15px;
            padding: 50px 20px;
            text-align: center;
            color: #777;
        }

        /* FOOTER */

        .footer {
            text-align: center;
            padding: 25px;
            color: #888;
            font-size: 13px;
            margin-top: 40px;
        }

        @media(max-width: 576px) {

            .brand {
                font-size: 25px;
            }

            .main-container {
                margin-top: 22px;
            }

            .summary-card {
                padding: 18px;
            }

        }

    </style>

</head>

<body>

<!-- HEADER -->

<header class="header">

    <div class="container">

        <div class="brand">
            <i class="fas fa-wifi"></i> HOTNET
        </div>

        <div class="header-subtitle">
            WiFi Billing System | Active Internet Packages
        </div>

    </div>

</header>

<!-- MAIN CONTENT -->

<div class="main-container">

    <!-- SUMMARY -->

    <div class="summary-card">

        <div>

            <div class="summary-label">
                TOTAL ACTIVE PACKAGES
            </div>

            <div class="summary-number">
                <?php echo $totalPackages; ?>
            </div>

            <small class="text-muted">
                Available packages in your database
            </small>

        </div>

        <div class="summary-icon">
            <i class="fas fa-box-open"></i>
        </div>

    </div>

    <!-- PACKAGE SECTION -->

    <h4 class="section-title">
        <i class="fas fa-layer-group text-warning"></i>
        Available Packages
    </h4>

    <?php if ($totalPackages > 0): ?>

        <div class="row g-4">

            <?php foreach ($packages as $package): ?>

                <div class="col-12 col-sm-6 col-lg-4">

                    <div class="package-card">

                        <div class="package-top">

                            <div class="package-id">
                                PACKAGE #<?php echo e($package['id']); ?>
                            </div>

                            <div class="package-name">
                                <?php echo e($package['package_name']); ?>
                            </div>

                        </div>

                        <div class="package-body">

                            <div class="package-price">
                                KSh <?php echo number_format((float)$package['price'], 2); ?>
                            </div>

                            <div class="package-detail">

                                <span class="detail-label">
                                    Package Type
                                </span>

                                <strong>
                                    <?php echo e($package['package_type']); ?>
                                </strong>

                            </div>

                            <div class="package-detail">

                                <span class="detail-label">
                                    Package ID
                                </span>

                                <strong>
                                    <?php echo e($package['id']); ?>
                                </strong>

                            </div>

                            <div class="package-detail">

                                <span class="detail-label">
                                    Availability
                                </span>

                                <span class="status">
                                    <i class="fas fa-check-circle"></i>
                                    Active
                                </span>

                            </div>

                        </div>

                        <div class="package-footer">

                            <a href="customer/payment.php?package_id=<?php echo (int)$package['id']; ?>">
                                <i class="fas fa-eye"></i>
                                View Payment Page
                            </a>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty-state">

            <i class="fas fa-box-open fa-3x mb-3"></i>

            <h5>No Active Packages</h5>

            <p>There are currently no active packages available.</p>

        </div>

    <?php endif; ?>

</div>

<!-- FOOTER -->

<footer class="footer">

    <strong>HOTNET WiFi Billing System</strong>

    <br>

    &copy; <?php echo date('Y'); ?> All Rights Reserved.

</footer>

</body>
</html>
