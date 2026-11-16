
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| HOTNET HOTSPOT LOGIN PAGE
|--------------------------------------------------------------------------
| File: hotspot/index.php
|
| Features:
| - Voucher login
| - Device token authentication
| - Active voucher checking
| - Device binding support
| - Green vegetation theme
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = '';
$voucher = '';

/*
|--------------------------------------------------------------------------
| LOAD DEVICE BINDING SETTING
|--------------------------------------------------------------------------
*/

$deviceBindingEnabled = 1;

$settingStmt = mysqli_prepare(
    $conn,
    "SELECT setting_value
     FROM hotspot_settings
     WHERE setting_key = 'device_binding_enabled'
     LIMIT 1"
);

if ($settingStmt) {
    mysqli_stmt_execute($settingStmt);

    $settingResult = mysqli_stmt_get_result($settingStmt);

    if ($settingRow = mysqli_fetch_assoc($settingResult)) {
        $deviceBindingEnabled = (int) $settingRow['setting_value'];
    }

    mysqli_stmt_close($settingStmt);
}

/*
|--------------------------------------------------------------------------
| PROCESS VOUCHER LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {

    $voucher = strtoupper(trim($_POST['voucher'] ?? ''));

    $deviceToken = trim($_POST['device_token'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | VALIDATE INPUT
    |--------------------------------------------------------------------------
    */

    if ($voucher === '') {

        $message = 'Please enter your voucher code.';
        $messageType = 'danger';

    } elseif (
        $deviceBindingEnabled === 1 &&
        $deviceToken === ''
    ) {

        $message = 'Please enter your registered device token.';
        $messageType = 'danger';

    } elseif (
        $deviceBindingEnabled === 1 &&
        strlen($deviceToken) > 512
    ) {

        $message = 'Invalid device token.';
        $messageType = 'danger';

    } else {

        /*
        |--------------------------------------------------------------------------
        | CHECK VOUCHER
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM vouchers
             WHERE voucher_code = ?
               AND status = 'Active'
               AND (
                    expiry_date IS NULL
                    OR expiry_date >= NOW()
               )
             LIMIT 1"
        );

        if (!$stmt) {

            $message = 'A database error occurred.';
            $messageType = 'danger';

        } else {

            mysqli_stmt_bind_param(
                $stmt,
                's',
                $voucher
            );

            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);

            if ($result && mysqli_num_rows($result) > 0) {

                /*
                |--------------------------------------------------------------------------
                | SAVE PENDING AUTHENTICATION DATA
                |--------------------------------------------------------------------------
                */

                $_SESSION['pending_hotspot_voucher'] = $voucher;

                /*
                |--------------------------------------------------------------------------
                | PASS DEVICE TOKEN TO AUTHENTICATION PAGE
                |--------------------------------------------------------------------------
                */

                if ($deviceBindingEnabled === 1) {

                    $_SESSION['pending_hotspot_device_token'] = $deviceToken;

                } else {

                    unset($_SESSION['pending_hotspot_device_token']);

                }

                mysqli_stmt_close($stmt);

                /*
                |--------------------------------------------------------------------------
                | REDIRECT TO DEVICE AUTHENTICATION
                |--------------------------------------------------------------------------
                */

                header('Location: device_authentication.php');

                exit();

            } else {

                $message = 'Invalid, inactive, or expired voucher.';
                $messageType = 'danger';

            }

            mysqli_stmt_close($stmt);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>HOTNET WiFi Login</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;

            background:
                linear-gradient(
                    rgba(5, 45, 25, 0.78),
                    rgba(5, 45, 25, 0.88)
                ),
                url('https://images.unsplash.com/photo-1448375240586-882707db888b?auto=format&fit=crop&w=1800&q=85')
                center/cover fixed;

            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-box {
            width: 100%;
            max-width: 420px;
            background: white;
            padding: 35px;
            border-radius: 18px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
        }

        .brand {
            margin-bottom: 25px;
        }

        .brand h1 {
            color: #176b3a;
            font-size: 34px;
            letter-spacing: 2px;
            margin: 0;
        }

        .brand p {
            color: #718078;
            margin-top: 8px;
        }

        .welcome {
            color: #315640;
            font-size: 15px;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            color: #244b35;
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            padding: 14px;
            border: 1px solid #cbd8ce;
            border-radius: 9px;
            font-size: 15px;
            outline: none;
        }

        input:focus {
            border-color: #23864c;
            box-shadow: 0 0 0 3px rgba(35, 134, 76, 0.12);
        }

        button {
            width: 100%;
            padding: 15px;
            background: #176b3a;
            color: white;
            border: none;
            border-radius: 9px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
        }

        button:hover {
            background: #0e4f29;
        }

        .message {
            padding: 13px;
            margin-bottom: 20px;
            border-radius: 8px;
            line-height: 1.5;
        }

        .success {
            background: #dff5e5;
            color: #176b3a;
        }

        .danger {
            background: #fde4e4;
            color: #a12626;
        }

        .info {
            background: #edf6ef;
            padding: 14px;
            border-radius: 8px;
            color: #385b43;
            font-size: 13px;
            line-height: 1.6;
            margin-top: 20px;
        }

        .footer {
            margin-top: 25px;
            color: #718078;
            font-size: 13px;
            line-height: 1.8;
        }

        .footer a {
            color: #176b3a;
            font-weight: bold;
            text-decoration: none;
        }

        @media (max-width: 480px) {

            .login-box {
                padding: 25px 20px;
            }

        }

    </style>

</head>

<body>

<div class="login-box">

    <div class="brand">

        <h1>HOTNET</h1>

        <p>Reliable WiFi. Connected Living.</p>

    </div>

    <div class="welcome">

        Welcome to HOTNET WiFi.

        Enter your voucher details below to begin authentication.

    </div>

    <?php if ($message !== ''): ?>

        <div class="message <?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>">

            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

        </div>

    <?php endif; ?>

    <form method="POST" action="">

        <div class="form-group">

            <label for="voucher">Voucher Code</label>

            <input
                type="text"
                id="voucher"
                name="voucher"
                placeholder="Enter your voucher code"
                value="<?= htmlspecialchars($voucher, ENT_QUOTES, 'UTF-8') ?>"
                maxlength="100"
                autocomplete="off"
                required
            >

        </div>

        <?php if ($deviceBindingEnabled === 1): ?>

            <div class="form-group">

                <label for="device_token">Registered Device Token</label>

                <input
                    type="password"
                    id="device_token"
                    name="device_token"
                    placeholder="Enter your registered device token"
                    autocomplete="off"
                    required
                >

            </div>

        <?php endif; ?>

        <button type="submit" name="login">

            Connect to HOTNET

        </button>

    </form>

    <div class="info">

        <strong>Device Security</strong><br>

        Device authentication helps protect your voucher
        from unauthorized use.

    </div>

    <div class="footer">

        HOTNET WiFi Management System<br>

        Support:
        <a href="tel:0718347038">0718347038</a>

    </div>

</div>

</body>

</html>
