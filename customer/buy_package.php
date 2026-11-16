
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| MANUAL M-PESA VERIFICATION CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['verify_mpesa_csrf'])) {
    $_SESSION['verify_mpesa_csrf'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| LOAD ACTIVE PACKAGES
|--------------------------------------------------------------------------
*/

$packages = [];
$pageError = '';

try {

    $sql = "
        SELECT
            id,
            package_name,
            speed,
            package_type,
            duration_value,
            duration_days,
            price
        FROM packages
        WHERE status = 'Active'
        ORDER BY price ASC
    ";

    $result = $conn->query($sql);

    if ($result) {

        while ($row = $result->fetch_assoc()) {
            $packages[] = $row;
        }

        $result->free();
    }

} catch (Throwable $e) {

    error_log('HOTNET package loading error: ' . $e->getMessage());

    $pageError = 'Unable to load packages. Please try again later.';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>HOTNET | Buy WiFi Packages</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: #fff;
            min-height: 100vh;

            background:
                radial-gradient(ellipse at bottom, #7c2d12 0%, transparent 55%),
                radial-gradient(ellipse at top, #ea580c 0%, transparent 50%),
                linear-gradient(135deg, #1c0803, #431407, #7c2d12);

            background-attachment: fixed;
        }

        /* NAVIGATION */

        .navbar-hotnet {
            background: rgba(20, 8, 3, 0.92);
            padding: 16px 0;
            border-bottom: 2px solid #f97316;
        }

        .brand {
            font-size: 27px;
            font-weight: 900;
            color: #fb923c;
            letter-spacing: 2px;
            text-decoration: none;
        }

        /* HERO */

        .hero {
            text-align: center;
            padding: 50px 15px 25px;
        }

        .hero h1 {
            font-size: 38px;
            font-weight: 900;
            color: #fff;
        }

        .hero h1 span {
            color: #fb923c;
        }

        .hero p {
            color: #fed7aa;
            font-size: 16px;
        }

        /* PACKAGE FILTERS */

        .filter-area {
            text-align: center;
            margin-bottom: 28px;
        }

        .filter-btn {
            border: 1px solid #fb923c;
            background: transparent;
            color: #fff;
            padding: 9px 17px;
            margin: 4px;
            border-radius: 25px;
            font-weight: bold;
            transition: 0.3s;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: #f97316;
            color: #fff;
        }

        /* SMALL PACKAGE CARDS */

        .package-card {
            background: rgba(255, 255, 255, 0.97);
            color: #292524;
            border-radius: 12px;
            overflow: hidden;
            height: 100%;
            max-width: 300px;
            margin: auto;
            border: 1px solid #fed7aa;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            transition: 0.3s;
        }

        .package-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.3);
        }

        .package-header {
            background: linear-gradient(135deg, #f97316, #c2410c);
            color: white;
            padding: 12px 10px;
            text-align: center;
        }

        .package-header h4 {
            font-size: 17px;
            font-weight: 900;
            margin: 0;
        }

        .package-body {
            padding: 12px;
            text-align: center;
        }

        .package-price {
            font-size: 23px;
            color: #ea580c;
            font-weight: 900;
            margin: 8px 0;
        }

        .package-detail {
            padding: 6px 0;
            font-size: 13px;
            border-bottom: 1px solid #eee;
            color: #57534e;
        }

        .package-detail:last-of-type {
            border-bottom: none;
        }

        .buy-btn {
            display: block;
            width: 100%;
            padding: 9px;
            margin-top: 12px;
            background: #f97316;
            color: white;
            border-radius: 7px;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            transition: 0.3s;
        }

        .buy-btn:hover {
            background: #c2410c;
            color: white;
        }

        /* MANUAL VERIFICATION */

        .manual-section {
            max-width: 600px;
            margin: 35px auto;
            padding: 25px 20px;
            background: white;
            color: #292524;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .manual-section h3 {
            color: #ea580c;
            font-weight: 900;
        }

        .manual-section p {
            color: #57534e;
        }

        .manual-section .form-control {
            padding: 12px;
        }

        .verify-btn {
            background: #16a34a;
            color: white;
            border: none;
            padding: 12px;
            width: 100%;
            border-radius: 8px;
            font-weight: bold;
        }

        .verify-btn:hover {
            background: #15803d;
        }

        .empty-message {
            text-align: center;
            color: #fff;
            padding: 30px;
        }

        /* FOOTER */

        footer {
            background: #140803;
            text-align: center;
            padding: 30px 20px;
            color: #fed7aa;
            margin-top: 40px;
            border-top: 2px solid #f97316;
        }

        footer h5 {
            font-size: 23px;
        }

        footer h6 {
            color: #fb923c;
            font-size: 17px;
        }

        .contact-number {
            font-size: 25px;
            color: white;
            font-weight: bold;
            margin-top: 10px;
        }

        footer hr {
            max-width: 450px;
            margin: 20px auto;
        }

        /* MOBILE */

        @media (max-width: 576px) {

            .hero h1 {
                font-size: 29px;
            }

            .package-card {
                max-width: 280px;
            }

            .filter-btn {
                padding: 8px 12px;
                font-size: 12px;
            }

        }

    </style>

</head>

<body>

<!-- NAVIGATION -->

<nav class="navbar-hotnet">

    <div class="container d-flex justify-content-between align-items-center">

        <a href="buy_package.php" class="brand">
            HOTNET
        </a>

        <a href="buy_package.php"
           class="btn btn-outline-warning">

            <i class="bi bi-wifi"></i>
            WiFi Packages

        </a>

    </div>

</nav>


<!-- HERO SECTION -->

<section class="hero">

    <div class="container">

        <h1>
            CONNECT WITH <span>HOTNET</span>
        </h1>

        <p>
            Choose your preferred WiFi package and enjoy reliable internet.
        </p>

    </div>

</section>


<!-- AVAILABLE PACKAGES -->

<section class="container pb-4">

    <div class="text-center mb-4">

        <h2 class="fw-bold">
            AVAILABLE WIFI PACKAGES
        </h2>

        <p class="text-warning">
            Select a package that suits your needs.
        </p>

    </div>


    <!-- PACKAGE FILTERS -->

    <div class="filter-area">

        <button class="filter-btn active" data-filter="All">
            All Packages
        </button>

        <button class="filter-btn" data-filter="Hourly">
            Hourly
        </button>

        <button class="filter-btn" data-filter="Daily">
            Daily
        </button>

        <button class="filter-btn" data-filter="Weekly">
            Weekly
        </button>

        <button class="filter-btn" data-filter="Monthly">
            Monthly
        </button>

    </div>


    <?php if ($pageError !== ''): ?>

        <div class="alert alert-danger">
            <?= h($pageError) ?>
        </div>

    <?php elseif (empty($packages)): ?>

        <div class="empty-message">

            <h4>No active packages available.</h4>

            <p>Please check again later.</p>

        </div>

    <?php else: ?>

        <div class="row g-3 justify-content-center"
             id="packageContainer">

            <?php foreach ($packages as $package): ?>

                <div class="col-12 col-sm-6 col-lg-4 package-item"
                     data-type="<?= h($package['package_type']) ?>">

                    <div class="package-card">

                        <div class="package-header">

                            <h4>
                                <?= h($package['package_name']) ?>
                            </h4>

                        </div>

                        <div class="package-body">

                            <div class="package-price">

                                KSh <?= number_format(
                                    (float)$package['price'],
                                    2
                                ) ?>

                            </div>

                            <div class="package-detail">

                                <i class="bi bi-speedometer2 text-warning"></i>

                                Speed:
                                <strong>
                                    <?= h($package['speed']) ?>
                                </strong>

                            </div>

                            <div class="package-detail">

                                <i class="bi bi-clock text-warning"></i>

                                Type:
                                <strong>
                                    <?= h($package['package_type']) ?>
                                </strong>

                            </div>

                            <div class="package-detail">

                                <i class="bi bi-calendar-check text-warning"></i>

                                Duration:
                                <strong>
                                    <?= h($package['duration_value']) ?>
                                    <?= h($package['package_type']) ?>
                                </strong>

                            </div>

                            <a
                                href="payment.php?package_id=<?= (int)$package['id'] ?>"
                                class="buy-btn"
                            >

                                <i class="bi bi-cart-check"></i>
                                BUY NOW

                            </a>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

        <div id="noPackages"
             class="empty-message"
             style="display:none;">

            <h4>No packages in this category.</h4>

        </div>

    <?php endif; ?>

</section>


<!-- MANUAL M-PESA VERIFICATION -->

<section class="container">

    <div class="manual-section text-center">

        <h3>
            <i class="bi bi-receipt"></i>
            ALREADY PAID?
        </h3>

        <p>
            If your M-Pesa payment was successful but automatic
            confirmation did not work, enter your transaction code below.
        </p>

        <form method="POST" action="verify_mpesa.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['verify_mpesa_csrf']) ?>"
            >

            <div class="mb-3 text-start">

                <label class="form-label fw-bold">
                    M-Pesa Transaction Code
                </label>

                <input
                    type="text"
                    name="mpesa_receipt_number"
                    class="form-control text-uppercase"
                    placeholder="Enter M-Pesa transaction code"
                    minlength="6"
                    maxlength="20"
                    pattern="[A-Za-z0-9]{6,20}"
                    autocomplete="off"
                    required
                >

            </div>

            <button type="submit" class="verify-btn">

                <i class="bi bi-shield-check"></i>
                VERIFY PAYMENT

            </button>

        </form>

        <small class="text-muted d-block mt-3">
            No phone number required for manual verification.
        </small>

    </div>

</section>


<!-- FOOTER WITH HOTNET SUPPORT -->

<footer>

    <h5 class="fw-bold text-warning">
        HOTNET WiFi
    </h5>

    <h6 class="fw-bold mt-3">
        NEED HELP?
    </h6>

    <p class="mb-2">
        Contact HOTNET support for assistance.
    </p>

    <div class="contact-number">

        <i class="bi bi-telephone-fill"></i>
        0718347038

    </div>

    <hr class="border-secondary">

    <small>
        &copy; <?= date('Y') ?> HOTNET. All rights reserved.
    </small>

</footer>


<!-- PACKAGE FILTER SCRIPT -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const filterButtons = document.querySelectorAll('.filter-btn');

    const packageItems = document.querySelectorAll('.package-item');

    const noPackages = document.getElementById('noPackages');

    filterButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const selectedType = this.dataset.filter;

            filterButtons.forEach(function (btn) {
                btn.classList.remove('active');
            });

            this.classList.add('active');

            let visibleCount = 0;

            packageItems.forEach(function (item) {

                const packageType = item.dataset.type;

                const showPackage =
                    selectedType === 'All' ||
                    packageType.toLowerCase() === selectedType.toLowerCase();

                item.style.display = showPackage ? '' : 'none';

                if (showPackage) {
                    visibleCount++;
                }

            });

            if (noPackages) {
                noPackages.style.display =
                    visibleCount === 0 ? 'block' : 'none';
            }

        });

    });

});

</script>

</body>
</html>
