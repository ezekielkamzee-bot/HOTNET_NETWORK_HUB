
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN FOR MANUAL M-PESA VERIFICATION
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['verify_mpesa_csrf'])) {
    $_SESSION['verify_mpesa_csrf'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| PAGE VARIABLES
|--------------------------------------------------------------------------
*/

$error = '';

$packageId = isset($_GET['package_id'])
    ? (int)$_GET['package_id']
    : (int)($_POST['package_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| LOAD PACKAGE
|--------------------------------------------------------------------------
*/

if ($packageId <= 0) {
    exit('Invalid package selected. Return to the packages page.');
}

$stmt = $conn->prepare("
    SELECT
        id,
        package_name,
        speed,
        package_type,
        duration_value,
        duration_days,
        price
    FROM packages
    WHERE id = ?
      AND status = 'Active'
    LIMIT 1
");

$stmt->bind_param('i', $packageId);
$stmt->execute();

$package = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$package) {
    exit('The selected package is unavailable.');
}

/*
|--------------------------------------------------------------------------
| NORMALIZE KENYAN PHONE NUMBER
|--------------------------------------------------------------------------
*/

function normalizeHotnetPhone($phone)
{
    $phone = preg_replace('/\D/', '', $phone);

    if (strlen($phone) === 10 && $phone[0] === '0') {
        $phone = '254' . substr($phone, 1);
    } elseif (strlen($phone) === 9 &&
        ($phone[0] === '7' || $phone[0] === '1')) {
        $phone = '254' . $phone;
    }

    if (!preg_match('/^254[17][0-9]{8}$/', $phone)) {
        return false;
    }

    return $phone;
}

/*
|--------------------------------------------------------------------------
| PROCESS STK PUSH FORM
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'stk_push') {

    $mpesaPhone = normalizeHotnetPhone(
        $_POST['mpesa_phone'] ?? ''
    );

    $amount = (int)$package['price'];

    if (!$mpesaPhone) {

        $error = 'Enter a valid Kenyan M-Pesa phone number.';

    } elseif ($amount < 1) {

        $error = 'Invalid package amount.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | CHECK EXISTING PENDING PAYMENT
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT id
            FROM payments
            WHERE customer_phone = ?
              AND package_id = ?
              AND payment_status = 'Pending'
            LIMIT 1
        ");

        $stmt->bind_param('si', $mpesaPhone, $packageId);
        $stmt->execute();

        $existingPayment = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($existingPayment) {

            $error = 'You already have a pending payment for this package. Check your previous payment first.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | CREATE SECURE PAYMENT TOKEN
            |--------------------------------------------------------------------------
            */

            $accessToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $accessToken);

            $temporaryReference = 'PENDING-' .
                bin2hex(random_bytes(12));

            /*
            |--------------------------------------------------------------------------
            | SAVE PENDING PAYMENT
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                INSERT INTO payments (
                    user_id,
                    customer_name,
                    customer_phone,
                    package_id,
                    voucher_id,
                    amount,
                    transaction_code,
                    payment_method,
                    payment_status,
                    payment_access_token_hash
                ) VALUES (
                    NULL,
                    'M-Pesa Customer',
                    ?,
                    ?,
                    NULL,
                    ?,
                    ?,
                    'M-Pesa',
                    'Pending',
                    ?
                )
            ");

            $stmt->bind_param(
                'siiss',
                $mpesaPhone,
                $packageId,
                $amount,
                $temporaryReference,
                $tokenHash
            );

            if (!$stmt->execute()) {

                $error = 'Unable to create payment record. Please try again.';

                $stmt->close();

            } else {

                $paymentId = $stmt->insert_id;

                $stmt->close();

                /*
                |--------------------------------------------------------------------------
                | INITIATE M-PESA STK PUSH
                |--------------------------------------------------------------------------
                */

                try {

                    require_once __DIR__ . '/../mpesa/stk_push.php';

                    $accountReference = 'HOTNET' . $paymentId;

                    $stkResponse = sendStkPush(
                        $mpesaPhone,
                        $amount,
                        $accountReference,
                        'WiFi Package Payment'
                    );

                    $responseCode = (string)(
                        $stkResponse['ResponseCode'] ?? ''
                    );

                    $checkoutRequestID =
                        $stkResponse['CheckoutRequestID'] ?? '';

                    $merchantRequestID =
                        $stkResponse['MerchantRequestID'] ?? '';

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK STK RESPONSE
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $responseCode === '0' &&
                        !empty($checkoutRequestID) &&
                        !empty($merchantRequestID)
                    ) {

                        /*
                        |--------------------------------------------------------------------------
                        | SAVE M-PESA REQUEST IDS
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $conn->prepare("
                            UPDATE payments
                            SET
                                checkout_request_id = ?,
                                merchant_request_id = ?
                            WHERE id = ?
                        ");

                        $stmt->bind_param(
                            'ssi',
                            $checkoutRequestID,
                            $merchantRequestID,
                            $paymentId
                        );

                        $stmt->execute();
                        $stmt->close();

                        /*
                        |--------------------------------------------------------------------------
                        | PROCESS ANY EARLY CALLBACK
                        |--------------------------------------------------------------------------
                        */

                        try {

                            $pdo = new PDO(
                                "mysql:host={$host};dbname={$db};charset=utf8mb4",
                                $user,
                                $pass,
                                [
                                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                                    PDO::ATTR_EMULATE_PREPARES => false
                                ]
                            );

                            require_once __DIR__ .
                                '/../mpesa/callback_processor.php';

                            processPendingMpesaCallbacks(
                                $pdo,
                                $checkoutRequestID
                            );

                        } catch (Throwable $callbackError) {

                            error_log(
                                'HOTNET early callback processing error: ' .
                                $callbackError->getMessage()
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | REDIRECT TO PAYMENT WAITING PAGE
                        |--------------------------------------------------------------------------
                        */

                        header(
                            'Location: payment_waiting.php?token=' .
                            urlencode($accessToken)
                        );

                        exit;

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | MARK FAILED STK REQUEST
                        |--------------------------------------------------------------------------
                        */

                        $failureMessage =
                            $stkResponse['errorMessage'] ??
                            $stkResponse['ResponseDescription'] ??
                            'M-Pesa STK Push could not be started.';

                        $stmt = $conn->prepare("
                            UPDATE payments
                            SET
                                payment_status = 'Failed',
                                result_description = ?
                            WHERE id = ?
                              AND payment_status = 'Pending'
                        ");

                        $stmt->bind_param(
                            'si',
                            $failureMessage,
                            $paymentId
                        );

                        $stmt->execute();
                        $stmt->close();

                        $error = $failureMessage;
                    }

                } catch (Throwable $e) {

                    error_log(
                        'HOTNET STK Push error: ' . $e->getMessage()
                    );

                    $failureMessage = 'Unable to initiate M-Pesa payment. Please try again.';

                    $stmt = $conn->prepare("
                        UPDATE payments
                        SET
                            payment_status = 'Failed',
                            result_description = ?
                        WHERE id = ?
                          AND payment_status = 'Pending'
                    ");

                    $stmt->bind_param(
                        'si',
                        $failureMessage,
                        $paymentId
                    );

                    $stmt->execute();
                    $stmt->close();

                    $error = $failureMessage;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>HOTNET | Make Payment</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f5f5f5;
            font-family: Arial, sans-serif;
        }

        .payment-container {
            max-width: 550px;
            margin: 45px auto;
        }

        .hotnet-header {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            padding: 25px;
            text-align: center;
            border-radius: 12px 12px 0 0;
        }

        .payment-card {
            background: white;
            padding: 25px;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .package-summary {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            padding: 18px;
            border-radius: 10px;
            margin-bottom: 22px;
        }

        .package-price {
            color: #ea580c;
            font-size: 27px;
            font-weight: bold;
        }

        .btn-hotnet {
            background: #f97316;
            color: white;
            border: none;
            padding: 13px;
            font-weight: bold;
        }

        .btn-hotnet:hover {
            background: #ea580c;
            color: white;
        }

        .manual-section {
            border-top: 1px solid #eee;
            margin-top: 28px;
            padding-top: 25px;
        }

        .manual-section h5 {
            color: #ea580c;
            font-weight: bold;
        }

        .form-control {
            padding: 12px;
        }

    </style>

</head>

<body>

<div class="container payment-container">

    <div class="hotnet-header">

        <h2 class="fw-bold mb-1">HOTNET</h2>

        <p class="mb-0">Secure WiFi Package Payment</p>

    </div>

    <div class="payment-card">

        <div class="package-summary">

            <h5 class="fw-bold">
                <?= h($package['package_name']) ?>
            </h5>

            <p class="mb-1">
                Speed: <?= h($package['speed']) ?>
            </p>

            <p class="mb-1">
                Package type: <?= h($package['package_type']) ?>
            </p>

            <p class="mb-2">
                Duration: <?= h($package['duration_value']) ?>
                <?= h($package['package_type']) ?>
            </p>

            <div class="package-price">
                KSh <?= number_format((float)$package['price'], 2) ?>
            </div>

        </div>

        <?php if ($error !== ''): ?>

            <div class="alert alert-danger">
                <?= h($error) ?>
            </div>

        <?php endif; ?>

        <!-- AUTOMATIC M-PESA STK PUSH -->

        <h5 class="fw-bold mb-3">
            Pay Using M-Pesa
        </h5>

        <form method="POST" action="">

            <input
                type="hidden"
                name="package_id"
                value="<?= (int)$package['id'] ?>"
            >

            <input
                type="hidden"
                name="action"
                value="stk_push"
            >

            <div class="mb-3">

                <label class="form-label fw-bold">
                    M-Pesa Phone Number
                </label>

                <input
                    type="tel"
                    name="mpesa_phone"
                    class="form-control"
                    placeholder="07XXXXXXXX or 2547XXXXXXXX"
                    maxlength="13"
                    required
                >

                <small class="text-muted">
                    Enter the number that will receive the M-Pesa prompt.
                </small>

            </div>

            <button
                type="submit"
                class="btn btn-hotnet w-100"
            >
                Send M-Pesa STK Push
            </button>

        </form>

        <!-- MANUAL TRANSACTION CODE VERIFICATION -->

        <div class="manual-section">

            <h5>Already Paid?</h5>

            <p class="text-muted">
                If automatic payment confirmation did not work,
                enter the transaction code from your M-Pesa message.
            </p>

            <form
                method="POST"
                action="verify_mpesa.php"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['verify_mpesa_csrf']) ?>"
                >

                <div class="mb-3">

                    <label class="form-label fw-bold">
                        M-Pesa Transaction Code
                    </label>

                    <input
                        type="text"
                        name="mpesa_receipt_number"
                        class="form-control text-uppercase"
                        placeholder="Enter transaction code"
                        minlength="6"
                        maxlength="20"
                        pattern="[A-Za-z0-9]{6,20}"
                        autocomplete="off"
                        required
                    >

                </div>

                <button
                    type="submit"
                    class="btn btn-outline-success w-100 fw-bold"
                >
                    Verify My Payment
                </button>

                <small class="text-muted d-block mt-2 text-center">
                    No phone number required for manual verification.
                </small>

            </form>

        </div>

        <div class="text-center mt-4">

            <a
                href="buy_package.php"
                class="text-decoration-none"
                style="color:#ea580c;"
            >
                ← Back to Packages
            </a>

        </div>

        <p class="text-center text-muted mt-4 mb-0">
            HOTNET WiFi Support: 0718347038
        </p>

    </div>

</div>

</body>
</html>
