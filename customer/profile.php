
<?php
session_start();

require_once __DIR__ . "/../config/database.php";

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
| SECURITY HEADERS
|--------------------------------------------------------------------------
*/
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "success";

/*
|--------------------------------------------------------------------------
| GET CUSTOMER DETAILS
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT id, fullname, phone, username, status, created_at
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

/*
|--------------------------------------------------------------------------
| CHECK CUSTOMER ACCOUNT
|--------------------------------------------------------------------------
*/
if (!$customer || $customer['status'] !== 'Active') {
    session_unset();
    session_destroy();

    header("Location: login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| UPDATE PROFILE INFORMATION
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {

        $message = "Invalid request. Please refresh the page and try again.";
        $message_type = "danger";

    } else {

        $fullname = trim($_POST['fullname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = trim($_POST['username'] ?? '');

        if ($fullname === '' || $phone === '' || $username === '') {

            $message = "All fields are required.";
            $message_type = "danger";

        } elseif (!preg_match('/^[0-9+\s-]{9,15}$/', $phone)) {

            $message = "Please enter a valid phone number.";
            $message_type = "danger";

        } elseif (strlen($username) < 3) {

            $message = "Username must contain at least 3 characters.";
            $message_type = "danger";

        } else {

            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE USERNAME
            |--------------------------------------------------------------------------
            */
            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                AND id != ?
                LIMIT 1
            ");

            $check->bind_param("si", $username, $customer_id);
            $check->execute();

            $duplicate = $check->get_result()->num_rows > 0;
            $check->close();

            if ($duplicate) {

                $message = "This username is already being used.";
                $message_type = "danger";

            } else {

                /*
                |--------------------------------------------------------------------------
                | UPDATE CUSTOMER DETAILS
                |--------------------------------------------------------------------------
                */
                $update = $conn->prepare("
                    UPDATE users
                    SET fullname = ?, phone = ?, username = ?
                    WHERE id = ?
                ");

                $update->bind_param(
                    "sssi",
                    $fullname,
                    $phone,
                    $username,
                    $customer_id
                );

                if ($update->execute()) {
                    $message = "Your profile has been updated successfully.";
                } else {
                    $message = "Unable to update your profile. Please try again.";
                    $message_type = "danger";
                }

                $update->close();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | REFRESH CUSTOMER INFORMATION
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT id, fullname, phone, username, status, created_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $customer_id);
    $stmt->execute();

    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| VOUCHER STATISTICS
|--------------------------------------------------------------------------
*/
$voucher_stats = [
    'total' => 0,
    'active' => 0
];

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(
            CASE
                WHEN status = 'Active'
                AND (expiry_date IS NULL OR expiry_date > NOW())
                THEN 1
                ELSE 0
            END
        ) AS active
    FROM vouchers
    WHERE user_id = ?
");

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$voucher_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

/*
|--------------------------------------------------------------------------
| PAYMENT STATISTICS
|--------------------------------------------------------------------------
*/
$payment_stats = [
    'total' => 0,
    'paid' => 0,
    'revenue' => 0
];

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid,
        COALESCE(
            SUM(
                CASE
                    WHEN payment_status = 'Paid' THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS revenue
    FROM payments
    WHERE user_id = ?
");

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$payment_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile | HOTNET</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>

        :root {
            --hotnet-orange: #ff6b00;
            --hotnet-orange-dark: #e65c00;
            --hotnet-dark: #20242b;
            --hotnet-light: #fff4eb;
            --hotnet-gray: #f5f7fb;
        }

        html,
        body {
            min-height: 100%;
            margin: 0;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--hotnet-gray);
            color: var(--hotnet-dark);
            font-family: Arial, sans-serif;
        }

        /* NAVIGATION */

        .navbar {
            background: linear-gradient(110deg, #20242b, #343a40);
            padding: 14px 0;
        }

        .navbar-brand {
            color: white !important;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .navbar-brand span {
            color: var(--hotnet-orange);
        }

        .navbar .nav-link {
            color: #f1f1f1 !important;
            font-weight: 600;
            margin: 0 5px;
        }

        .navbar .nav-link:hover,
        .navbar .nav-link.active {
            color: #ff8b3d !important;
        }

        /* MAIN CONTENT */

        main {
            flex: 1 0 auto;
            width: 100%;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h2 {
            font-weight: 800;
            color: var(--hotnet-dark);
        }

        .page-heading p {
            color: #777;
        }

        /* CARDS */

        .profile-card,
        .stats-card {
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.06);
        }

        .profile-card {
            padding: 25px;
            height: 100%;
        }

        .stats-card {
            padding: 22px;
            height: 100%;
            border-left: 5px solid var(--hotnet-orange);
        }

        .profile-avatar {
            width: 85px;
            height: 85px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--hotnet-light);
            color: var(--hotnet-orange);
            border-radius: 50%;
            font-size: 38px;
            margin-bottom: 15px;
        }

        .section-title {
            font-weight: 800;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f1f1;
        }

        /* FORM */

        .form-label {
            font-weight: 700;
            color: #343a40;
        }

        .form-control {
            padding: 12px;
            border-radius: 9px;
            border: 1px solid #ddd;
        }

        .form-control:focus {
            border-color: var(--hotnet-orange);
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 0, 0.13);
        }

        /* BUTTON */

        .btn-hotnet {
            background: var(--hotnet-orange);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 9px;
            font-weight: 700;
        }

        .btn-hotnet:hover {
            background: var(--hotnet-orange-dark);
            color: white;
        }

        /* FOOTER */

        footer {
            margin-top: auto;
            width: 100%;
            flex-shrink: 0;
            background: #20242b;
            color: white;
            padding: 25px 0;
            text-align: center;
        }

        footer strong {
            color: var(--hotnet-orange);
        }

        footer .small {
            color: #ccc;
        }

        /* MOBILE */

        @media (max-width: 768px) {

            .page-heading h2 {
                font-size: 24px;
            }

            .profile-card {
                padding: 18px;
            }

        }

    </style>

</head>

<body>

<!-- NAVIGATION -->

<nav class="navbar navbar-expand-lg navbar-dark">

    <div class="container">

        <a class="navbar-brand" href="dashboard.php">
            HOT<span>NET</span>
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

            <ul class="navbar-nav ms-auto">

                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        <i class="bi bi-speedometer2"></i>
                        Dashboard
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="buy_package.php">
                        <i class="bi bi-wifi"></i>
                        Buy Package
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="my_vouchers.php">
                        <i class="bi bi-ticket-perforated"></i>
                        My Vouchers
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="devices.php">
                        <i class="bi bi-phone"></i>
                        My Devices
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="my_payments.php">
                        <i class="bi bi-credit-card"></i>
                        Payments
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="profile.php">
                        <i class="bi bi-person-circle"></i>
                        Profile
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i>
                        Logout
                    </a>
                </li>

            </ul>

        </div>

    </div>

</nav>


<!-- MAIN CONTENT -->

<main>

    <div class="container py-5">

        <div class="page-heading">

            <h2>
                <i class="bi bi-person-circle text-warning"></i>
                My Profile
            </h2>

            <p>
                Manage your HOTNET account information.
            </p>

        </div>


        <!-- NOTIFICATION -->

        <?php if ($message !== ''): ?>

            <div class="alert alert-<?= h($message_type) ?> alert-dismissible fade show">

                <i class="bi bi-info-circle-fill"></i>

                <?= h($message) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>


        <div class="row g-4">

            <!-- PROFILE INFORMATION -->

            <div class="col-lg-4">

                <div class="profile-card text-center">

                    <div class="profile-avatar mx-auto">

                        <i class="bi bi-person-fill"></i>

                    </div>

                    <h4 class="fw-bold mb-1">
                        <?= h($customer['fullname']) ?>
                    </h4>

                    <p class="text-muted mb-3">
                        @<?= h($customer['username']) ?>
                    </p>

                    <span class="badge bg-success px-3 py-2">
                        <?= h($customer['status']) ?> Account
                    </span>

                    <hr>

                    <div class="text-start">

                        <p>
                            <i class="bi bi-telephone-fill text-warning me-2"></i>
                            <?= h($customer['phone']) ?>
                        </p>

                        <p>
                            <i class="bi bi-calendar-check text-warning me-2"></i>
                            Joined:
                            <?= h(date('d M Y', strtotime($customer['created_at']))) ?>
                        </p>

                    </div>

                    <hr>

                    <h6 class="fw-bold mb-3">Account Overview</h6>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Total Vouchers</span>
                        <strong>
                            <?= (int)($voucher_stats['total'] ?? 0) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Active Vouchers</span>
                        <strong class="text-success">
                            <?= (int)($voucher_stats['active'] ?? 0) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Payments Made</span>
                        <strong>
                            <?= (int)($payment_stats['paid'] ?? 0) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Total Paid</span>
                        <strong class="text-success">
                            KSh <?= number_format((float)($payment_stats['revenue'] ?? 0), 2) ?>
                        </strong>
                    </div>

                </div>

            </div>


            <!-- UPDATE PROFILE FORM -->

            <div class="col-lg-8">

                <div class="profile-card">

                    <h5 class="section-title">

                        <i class="bi bi-pencil-square text-warning me-2"></i>

                        Update Personal Information

                    </h5>

                    <form method="POST" action="profile.php">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h($_SESSION['csrf_token']) ?>"
                        >

                        <div class="mb-3">

                            <label class="form-label">
                                Full Name
                            </label>

                            <input
                                type="text"
                                name="fullname"
                                class="form-control"
                                value="<?= h($customer['fullname']) ?>"
                                placeholder="Enter your full name"
                                required
                            >

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Phone Number
                            </label>

                            <input
                                type="text"
                                name="phone"
                                class="form-control"
                                value="<?= h($customer['phone']) ?>"
                                placeholder="Enter your phone number"
                                required
                            >

                        </div>

                        <div class="mb-4">

                            <label class="form-label">
                                Username
                            </label>

                            <input
                                type="text"
                                name="username"
                                class="form-control"
                                value="<?= h($customer['username']) ?>"
                                placeholder="Enter your username"
                                required
                            >

                        </div>

                        <button type="submit" class="btn btn-hotnet">

                            <i class="bi bi-save me-1"></i>

                            Save Changes

                        </button>

                    </form>

                </div>

            </div>

        </div>

    </div>

</main>


<!-- FOOTER -->

<footer>

    <div class="container">

        <div class="fw-bold mb-1">

            <strong>HOTNET</strong> WiFi Billing System

        </div>

        <div class="small">
            Reliable and affordable internet connectivity.
        </div>

        <div class="small mt-2">
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
