<?php
session_start();

include('../config/database.php');

$message = "";

/* ------------------------------------------------
   IF CUSTOMER IS ALREADY LOGGED IN
------------------------------------------------ */

if (isset($_SESSION['customer_id'])) {
    header("Location: dashboard.php");
    exit();
}

/* ------------------------------------------------
   CUSTOMER LOGIN
------------------------------------------------ */

if (isset($_POST['login'])) {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {

        $message = "Please enter your username and password.";

    } else {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, fullname, username, password, status
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "s",
                $username
            );

            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);

            if ($result && mysqli_num_rows($result) === 1) {

                $user = mysqli_fetch_assoc($result);

                /* Check account status */

                if (($user['status'] ?? 'Active') !== 'Active') {

                    $message = "Your account is inactive. Please contact the administrator.";

                }

                /* Check password */

                elseif (
                    !password_verify(
                        $password,
                        $user['password']
                    )
                ) {

                    $message = "Invalid username or password.";

                }

                /* Successful login */

                else {

                    session_regenerate_id(true);

                    $_SESSION['customer_id'] = $user['id'];
                    $_SESSION['customer_name'] = $user['fullname'];
                    $_SESSION['customer_username'] = $user['username'];

                    header("Location: dashboard.php");
                    exit();
                }

            } else {

                $message = "Invalid username or password.";
            }

            mysqli_stmt_close($stmt);

        } else {

            $message = "Unable to process login. Please try again.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Customer Login - WiFi Billing System</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            min-height: 100vh;
            background: linear-gradient(
                135deg,
                #0d6efd,
                #6610f2
            );

            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            width: 100%;
            max-width: 430px;
            border: none;
            border-radius: 15px;
            overflow: hidden;
        }

        .login-header {
            padding: 30px 20px;
            background: #ffffff;
            text-align: center;
        }

        .wifi-icon {
            font-size: 45px;
            margin-bottom: 10px;
        }

        .login-body {
            padding: 30px;
        }

        .form-control {
            min-height: 48px;
        }

        .login-button {
            min-height: 48px;
            font-weight: 600;
        }

        .footer-text {
            font-size: 13px;
        }

    </style>

</head>

<body>

<div class="container px-3">

    <div class="card shadow-lg login-card mx-auto">

        <!-- HEADER -->

        <div class="login-header">

            <div class="wifi-icon">
                📶
            </div>

            <h2 class="mb-1">
                WiFi Billing System
            </h2>

            <p class="text-muted mb-0">
                Customer Portal
            </p>

        </div>


        <!-- LOGIN BODY -->

        <div class="login-body">

            <h4 class="text-center mb-4">
                Customer Login
            </h4>


            <!-- MESSAGE -->

            <?php if ($message !== "") { ?>

                <div
                    class="alert alert-danger"
                    role="alert"
                >

                    <?php
                    echo htmlspecialchars(
                        $message,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>

                </div>

            <?php } ?>


            <!-- LOGIN FORM -->

            <form method="POST">

                <!-- USERNAME -->

                <div class="mb-3">

                    <label
                        for="username"
                        class="form-label"
                    >
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Enter your username"
                        autocomplete="username"
                        required
                    >

                </div>


                <!-- PASSWORD -->

                <div class="mb-4">

                    <label
                        for="password"
                        class="form-label"
                    >
                        Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                </div>


                <!-- LOGIN BUTTON -->

                <button
                    type="submit"
                    name="login"
                    class="btn btn-primary w-100 login-button"
                >

                    Login

                </button>

            </form>


            <!-- ADMIN LINK -->

            <div class="text-center mt-4">

                <a
                    href="../auth/login.php"
                    class="text-decoration-none"
                >
                    Administrator Login
                </a>

            </div>

        </div>


        <!-- FOOTER -->

        <div class="card-footer text-center bg-white">

            <span class="text-muted footer-text">

                WiFi Billing System &copy;
                <?php echo date("Y"); ?>

            </span>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>