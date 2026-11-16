
<?php
session_start();

/*
|--------------------------------------------------------------------------
| PREVENT CACHED CUSTOMER PAGES
|--------------------------------------------------------------------------
*/
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

/*
|--------------------------------------------------------------------------
| DESTROY CUSTOMER SESSION
|--------------------------------------------------------------------------
*/
$_SESSION = [];

/*
|--------------------------------------------------------------------------
| REMOVE SESSION COOKIE
|--------------------------------------------------------------------------
*/
if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/*
|--------------------------------------------------------------------------
| DESTROY SESSION
|--------------------------------------------------------------------------
*/
session_destroy();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Logout | HOTNET</title>

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
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: var(--hotnet-dark);
        }

        /* NAVIGATION */

        .navbar {
            background: linear-gradient(110deg, #20242b, #343a40);
            padding: 15px 0;
        }

        .navbar-brand {
            color: white !important;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .navbar-brand span {
            color: var(--hotnet-orange);
        }

        /* MAIN CONTENT */

        main {
            flex: 1 0 auto;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 45px 15px;
        }

        .logout-card {
            background: white;
            width: 100%;
            max-width: 480px;
            padding: 45px 30px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        .logout-icon {
            width: 100px;
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            border-radius: 50%;
            background: var(--hotnet-light);
            color: var(--hotnet-orange);
            font-size: 48px;
        }

        .logout-card h2 {
            font-weight: 800;
            margin-bottom: 15px;
        }

        .logout-card p {
            color: #777;
            line-height: 1.7;
        }

        /* BUTTON */

        .btn-hotnet {
            background: var(--hotnet-orange);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 13px 25px;
            font-weight: 700;
            text-decoration: none;
            display: inline-block;
            transition: 0.2s;
        }

        .btn-hotnet:hover {
            background: var(--hotnet-orange-dark);
            color: white;
            transform: translateY(-2px);
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

        @media (max-width: 576px) {

            .logout-card {
                padding: 35px 20px;
            }

            .logout-card h2 {
                font-size: 24px;
            }

        }

    </style>

</head>

<body>

<!-- NAVIGATION -->

<nav class="navbar">

    <div class="container">

        <a class="navbar-brand" href="login.php">
            HOT<span>NET</span>
        </a>

    </div>

</nav>


<!-- MAIN CONTENT -->

<main>

    <div class="logout-card">

        <div class="logout-icon">

            <i class="bi bi-check-circle-fill"></i>

        </div>

        <h2>You Have Logged Out!</h2>

        <p>
            Your HOTNET customer session has been securely ended.
            Thank you for using our WiFi services.
        </p>

        <p>
            We look forward to serving you again.
        </p>

        <a href="login.php" class="btn-hotnet">

            <i class="bi bi-box-arrow-in-right me-2"></i>

            Login Again

        </a>

        <div class="mt-4">

            <small class="text-muted">
                Your account remains safe. You can log in again anytime.
            </small>

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

</body>
</html>
