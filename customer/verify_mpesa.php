
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: buy_package.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| VERIFY CSRF TOKEN
|--------------------------------------------------------------------------
*/

$csrfToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($csrfToken) ||
    empty($_SESSION['verify_mpesa_csrf']) ||
    !hash_equals($_SESSION['verify_mpesa_csrf'], $csrfToken)
) {
    http_response_code(403);
    exit('Security verification failed. Return to the packages page and try again.');
}

/*
|--------------------------------------------------------------------------
| BASIC ATTEMPT LIMIT
|--------------------------------------------------------------------------
*/

$now = time();

$attempts = $_SESSION['receipt_attempts'] ?? [];

$attempts = array_filter(
    $attempts,
    function ($timestamp) use ($now) {
        return ($now - $timestamp) < 600;
    }
);

if (count($attempts) >= 5) {
    http_response_code(429);
    exit('Too many attempts. Please wait 10 minutes before trying again.');
}

/*
|--------------------------------------------------------------------------
| GET M-PESA RECEIPT CODE
|--------------------------------------------------------------------------
*/

$receipt = strtoupper(
    trim((string)($_POST['mpesa_receipt_number'] ?? ''))
);

if (!preg_match('/^[A-Z0-9]{6,20}$/', $receipt)) {

    $error = 'Enter a valid M-Pesa transaction code.';

} else {

    $_SESSION['receipt_attempts'][] = $now;

    /*
    |--------------------------------------------------------------------------
    | FIND PAYMENT
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            id,
            user_id,
            voucher_id,
            payment_status,
            mpesa_receipt_number
        FROM payments
        WHERE mpesa_receipt_number = ?
        LIMIT 2
    ");

    if (!$stmt) {

        $error = 'Unable to verify payment. Please try again.';

    } else {

        $stmt->bind_param('s', $receipt);
        $stmt->execute();

        $result = $stmt->get_result();

        $payments = [];

        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | CHECK MATCH
        |--------------------------------------------------------------------------
        */

        if (count($payments) === 0) {

            $error = 'Transaction code not found. Check the code and try again.';

        } elseif (count($payments) > 1) {

            $error = 'This transaction requires administrator assistance.';

        } else {

            $payment = $payments[0];

            /*
            |--------------------------------------------------------------------------
            | REQUIRE CONFIRMED PAYMENT
            |--------------------------------------------------------------------------
            */

            if ($payment['payment_status'] !== 'Paid') {

                $error = 'This payment has not been confirmed by M-Pesa yet.';

            } elseif (
                empty($payment['user_id']) ||
                empty($payment['voucher_id'])
            ) {

                $error = 'Payment found, but voucher assignment is incomplete. Contact support.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | VERIFY CUSTOMER
                |--------------------------------------------------------------------------
                */

                $customerId = (int)$payment['user_id'];
                $voucherId = (int)$payment['voucher_id'];
                $paymentId = (int)$payment['id'];

                $stmt = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE id = ?
                      AND status = 'Active'
                    LIMIT 1
                ");

                $stmt->bind_param('i', $customerId);
                $stmt->execute();

                $customer = $stmt->get_result()->fetch_assoc();

                $stmt->close();

                /*
                |--------------------------------------------------------------------------
                | VERIFY VOUCHER
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT id
                    FROM vouchers
                    WHERE id = ?
                      AND user_id = ?
                      AND status = 'Active'
                      AND (
                          expiry_date IS NULL
                          OR expiry_date >= NOW()
                      )
                    LIMIT 1
                ");

                $stmt->bind_param('ii', $voucherId, $customerId);
                $stmt->execute();

                $voucher = $stmt->get_result()->fetch_assoc();

                $stmt->close();

                if (!$customer) {

                    $error = 'Customer account is unavailable. Contact support.';

                } elseif (!$voucher) {

                    $error = 'The voucher is inactive or has expired.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | GENERATE NEW SECURE PAYMENT ACCESS TOKEN
                    |--------------------------------------------------------------------------
                    */

                    $accessToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $accessToken);

                    $stmt = $conn->prepare("
                        UPDATE payments
                        SET payment_access_token_hash = ?
                        WHERE id = ?
                          AND user_id = ?
                          AND voucher_id = ?
                          AND payment_status = 'Paid'
                          AND mpesa_receipt_number = ?
                    ");

                    if (!$stmt) {

                        $error = 'Unable to complete verification.';

                    } else {

                        $stmt->bind_param(
                            'siiis',
                            $tokenHash,
                            $paymentId,
                            $customerId,
                            $voucherId,
                            $receipt
                        );

                        $saved = $stmt->execute();
                        $affectedRows = $stmt->affected_rows;

                        $stmt->close();

                        if (!$saved || $affectedRows !== 1) {

                            $error = 'Payment verification could not be completed.';

                        } else {

                            unset($_SESSION['receipt_attempts']);

                            /*
                            |--------------------------------------------------------------------------
                            | CONTINUE EXISTING HOTNET PAYMENT FLOW
                            |--------------------------------------------------------------------------
                            */

                            header(
                                'Location: payment_waiting.php?token=' .
                                urlencode($accessToken)
                            );

                            exit;
                        }
                    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Verification | HOTNET</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">

<div class="container py-5">

    <div class="card shadow-sm border-0 mx-auto"
         style="max-width:480px;">

        <div class="card-body p-4 text-center">

            <h2 class="fw-bold" style="color:#f97316;">
                HOTNET
            </h2>

            <h5 class="mt-3">Payment Verification</h5>

            <?php if ($error !== ''): ?>

                <div class="alert alert-danger mt-3">
                    <?= h($error) ?>
                </div>

            <?php endif; ?>

            <a href="buy_package.php"
               class="btn btn-warning text-white mt-3">
                Return to Packages
            </a>

        </div>
    </div>

</div>

</body>
</html>
