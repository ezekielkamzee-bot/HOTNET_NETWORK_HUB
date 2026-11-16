<?php

/*
|--------------------------------------------------------------------------
| HOTNET M-PESA CALLBACK PROCESSOR
|--------------------------------------------------------------------------
| Responsibilities:
| 1. Process saved Safaricom callbacks.
| 2. Verify payment amount and phone number.
| 3. Find or create the customer account.
| 4. Generate or activate a voucher.
| 5. Update the payment record.
| 6. Prevent duplicate voucher generation.
| 7. Preserve callbacks for safe retry.
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| CALLBACK LOGGING
|--------------------------------------------------------------------------
*/

function hotnetCallbackLog($message)
{
    file_put_contents(
        __DIR__ . '/callback_log.txt',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

/*
|--------------------------------------------------------------------------
| NORMALIZE KENYAN PHONE NUMBER
|--------------------------------------------------------------------------
*/

function hotnetNormalizePhone($phone)
{
    $phone = preg_replace('/\D/', '', (string)$phone);

    if (strlen($phone) === 10 && $phone[0] === '0') {
        return '254' . substr($phone, 1);
    }

    if (strlen($phone) === 9) {
        return '254' . $phone;
    }

    if (strlen($phone) === 12 && substr($phone, 0, 3) === '254') {
        return $phone;
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| CALCULATE VOUCHER EXPIRY
|--------------------------------------------------------------------------
*/

function hotnetVoucherExpiry($package)
{
    $value = max(1, (int)($package['duration_value'] ?? 1));

    $type = strtolower(
        $package['package_type'] ?? 'daily'
    );

    $expiry = new DateTime('now');

    switch ($type) {

        case 'hourly':
            $expiry->modify("+{$value} hours");
            break;

        case 'daily':
            $expiry->modify("+{$value} days");
            break;

        case 'weekly':
            $expiry->modify("+{$value} weeks");
            break;

        case 'monthly':
            $expiry->modify("+{$value} months");
            break;

        default:

            $days = max(
                1,
                (int)($package['duration_days'] ?? 1)
            );

            $expiry->modify("+{$days} days");
            break;
    }

    return $expiry->format('Y-m-d H:i:s');
}

/*
|--------------------------------------------------------------------------
| GENERATE UNIQUE VOUCHER CODE
|--------------------------------------------------------------------------
*/

function hotnetGenerateVoucherCode(PDO $pdo)
{
    for ($i = 0; $i < 10; $i++) {

        $code = 'WIFI' . strtoupper(
            bin2hex(random_bytes(4))
        );

        $stmt = $pdo->prepare(
            "SELECT id
             FROM vouchers
             WHERE voucher_code = ?
             LIMIT 1"
        );

        $stmt->execute([$code]);

        if (!$stmt->fetch()) {
            return $code;
        }
    }

    throw new Exception(
        'Unable to generate a unique voucher code.'
    );
}

/*
|--------------------------------------------------------------------------
| GENERATE UNIQUE CUSTOMER USERNAME
|--------------------------------------------------------------------------
*/

function hotnetGenerateUsername(PDO $pdo, $phone)
{
    $base = 'wifi' . substr($phone, -8);

    for ($i = 0; $i < 20; $i++) {

        $username = $base . random_int(100, 9999);

        $stmt = $pdo->prepare(
            "SELECT id
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        $stmt->execute([$username]);

        if (!$stmt->fetch()) {
            return $username;
        }
    }

    throw new Exception(
        'Unable to generate a unique customer username.'
    );
}

/*
|--------------------------------------------------------------------------
| PROCESS SAVED CALLBACKS
|--------------------------------------------------------------------------
*/

function processPendingMpesaCallbacks(
    PDO $pdo,
    $checkoutRequestID = null
) {

    $sql = "
        SELECT id
        FROM mpesa_callback_inbox
        WHERE processing_status = 'Pending'
    ";

    $params = [];

    if (
        $checkoutRequestID !== null &&
        $checkoutRequestID !== ''
    ) {

        $sql .= " AND checkout_request_id = ?";

        $params[] = $checkoutRequestID;
    }

    $sql .= " ORDER BY id ASC LIMIT 20";

    $list = $pdo->prepare($sql);

    $list->execute($params);

    $callbackIds = $list->fetchAll(PDO::FETCH_COLUMN);

    foreach ($callbackIds as $callbackId) {

        try {

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | LOCK CALLBACK RECORD
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare(
                "SELECT *
                 FROM mpesa_callback_inbox
                 WHERE id = ?
                   AND processing_status = 'Pending'
                 FOR UPDATE"
            );

            $stmt->execute([(int)$callbackId]);

            $inbox = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inbox) {

                $pdo->commit();

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | INCREASE ATTEMPT COUNTER
            |--------------------------------------------------------------------------
            */

            $pdo->prepare(
                "UPDATE mpesa_callback_inbox
                 SET attempts = attempts + 1
                 WHERE id = ?"
            )->execute([(int)$callbackId]);

            /*
            |--------------------------------------------------------------------------
            | DECODE CALLBACK JSON
            |--------------------------------------------------------------------------
            */

            $data = json_decode(
                $inbox['callback_payload'],
                true
            );

            $callback = $data['Body']['stkCallback'] ?? null;

            if (!is_array($callback)) {

                throw new Exception(
                    'Saved callback JSON is invalid.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | EXTRACT CALLBACK IDENTIFIERS
            |--------------------------------------------------------------------------
            */

            $checkout = trim(
                (string)($callback['CheckoutRequestID'] ?? '')
            );

            $merchant = trim(
                (string)($callback['MerchantRequestID'] ?? '')
            );

            $resultCode = (string)(
                $callback['ResultCode'] ?? ''
            );

            $description = (string)(
                $callback['ResultDesc'] ?? 'Unknown result'
            );

            if ($checkout === '') {

                throw new Exception(
                    'CheckoutRequestID is missing.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | FIND PAYMENT
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare(
                "SELECT
                    payments.*,
                    packages.package_name,
                    packages.package_type,
                    packages.duration_value,
                    packages.duration_days
                 FROM payments
                 INNER JOIN packages
                    ON payments.package_id = packages.id
                 WHERE payments.checkout_request_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([$checkout]);

            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            /*
            |--------------------------------------------------------------------------
            | PAYMENT NOT READY
            |--------------------------------------------------------------------------
            */

            if (!$payment) {

                $pdo->prepare(
                    "UPDATE mpesa_callback_inbox
                     SET last_error = ?
                     WHERE id = ?"
                )->execute([
                    'Payment tracking ID is not available yet.',
                    (int)$callbackId
                ]);

                $pdo->commit();

                hotnetCallbackLog(
                    "Callback {$callbackId} waiting for payment tracking ID."
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | VERIFY MERCHANT REQUEST ID
            |--------------------------------------------------------------------------
            */

            if (
                !empty($payment['merchant_request_id']) &&
                $payment['merchant_request_id'] !== $merchant
            ) {

                throw new Exception(
                    'MerchantRequestID does not match.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | PREVENT DUPLICATE VOUCHER GENERATION
            |--------------------------------------------------------------------------
            */

            if ($payment['payment_status'] === 'Paid') {

                $pdo->prepare(
                    "UPDATE mpesa_callback_inbox
                     SET processing_status = 'Processed',
                         last_error = NULL,
                         processed_at = NOW()
                     WHERE id = ?"
                )->execute([(int)$callbackId]);

                $pdo->commit();

                hotnetCallbackLog(
                    "Duplicate callback ignored for payment {$payment['id']}."
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | CHECK PAYMENT STATUS
            |--------------------------------------------------------------------------
            */

            if ($payment['payment_status'] !== 'Pending') {

                throw new Exception(
                    'Payment is not Pending.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | HANDLE FAILED OR CANCELLED PAYMENT
            |--------------------------------------------------------------------------
            */

            if ($resultCode !== '0') {

                $stmt = $pdo->prepare(
                    "UPDATE payments
                     SET payment_status = 'Failed',
                         result_code = ?,
                         result_description = ?
                     WHERE id = ?
                       AND payment_status = 'Pending'"
                );

                $stmt->execute([
                    $resultCode,
                    $description,
                    $payment['id']
                ]);

                $pdo->prepare(
                    "UPDATE mpesa_callback_inbox
                     SET processing_status = 'Processed',
                         last_error = NULL,
                         processed_at = NOW()
                     WHERE id = ?"
                )->execute([(int)$callbackId]);

                $pdo->commit();

                hotnetCallbackLog(
                    "Payment {$payment['id']} failed: {$description}"
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | EXTRACT SUCCESSFUL PAYMENT DETAILS
            |--------------------------------------------------------------------------
            */

            $metadata = [];

            $items = $callback['CallbackMetadata']['Item'] ?? [];

            foreach ($items as $item) {

                if (isset($item['Name'])) {

                    $metadata[$item['Name']] =
                        $item['Value'] ?? null;
                }
            }

            $amount = (float)(
                $metadata['Amount'] ?? 0
            );

            $receipt = strtoupper(
                trim(
                    (string)(
                        $metadata['MpesaReceiptNumber'] ?? ''
                    )
                )
            );

            $callbackPhone = hotnetNormalizePhone(
                $metadata['PhoneNumber'] ?? ''
            );

            if (
                $amount <= 0 ||
                $receipt === '' ||
                $callbackPhone === ''
            ) {

                throw new Exception(
                    'Successful callback is missing required payment details.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | VERIFY PAYMENT AMOUNT
            |--------------------------------------------------------------------------
            */

            if (
                abs((float)$payment['amount'] - $amount) > 0.01
            ) {

                throw new Exception(
                    'Payment amount does not match.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | VERIFY CUSTOMER PHONE
            |--------------------------------------------------------------------------
            */

            $storedPhone = hotnetNormalizePhone(
                $payment['customer_phone'] ?? ''
            );

            if (
                $storedPhone === '' ||
                $storedPhone !== $callbackPhone
            ) {

                throw new Exception(
                    'M-Pesa phone number does not match.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | PREVENT DUPLICATE M-PESA RECEIPTS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare(
                "SELECT id
                 FROM payments
                 WHERE mpesa_receipt_number = ?
                   AND id != ?
                 LIMIT 1"
            );

            $stmt->execute([
                $receipt,
                $payment['id']
            ]);

            if ($stmt->fetch()) {

                throw new Exception(
                    'M-Pesa receipt has already been used.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | FIND EXISTING CUSTOMER
            |--------------------------------------------------------------------------
            */

            $localPhone = '0' . substr($storedPhone, 3);

            $stmt = $pdo->prepare(
                "SELECT id, fullname, username
                 FROM users
                 WHERE phone IN (?, ?)
                   AND status = 'Active'
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([
                $storedPhone,
                $localPhone
            ]);

            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            /*
            |--------------------------------------------------------------------------
            | CREATE CUSTOMER IF NOT FOUND
            |--------------------------------------------------------------------------
            */

            if (!$customer) {

                $customerName = trim(
                    (string)($payment['customer_name'] ?? '')
                );

                if ($customerName === '') {

                    throw new Exception(
                        'Customer name is missing.'
                    );
                }

                $username = hotnetGenerateUsername(
                    $pdo,
                    $storedPhone
                );

                $passwordHash = password_hash(
                    bin2hex(random_bytes(32)),
                    PASSWORD_DEFAULT
                );

                $stmt = $pdo->prepare(
                    "INSERT INTO users
                        (
                            fullname,
                            phone,
                            username,
                            password,
                            status
                        )
                     VALUES (?, ?, ?, ?, 'Active')"
                );

                $stmt->execute([
                    $customerName,
                    $storedPhone,
                    $username,
                    $passwordHash
                ]);

                $customerId = (int)$pdo->lastInsertId();

                hotnetCallbackLog(
                    "New customer account created: {$customerId}"
                );

            } else {

                $customerId = (int)$customer['id'];
            }

            /*
            |--------------------------------------------------------------------------
            | CALCULATE VOUCHER EXPIRY
            |--------------------------------------------------------------------------
            */

            $expiryDate = hotnetVoucherExpiry($payment);

            /*
            |--------------------------------------------------------------------------
            | FIND AVAILABLE VOUCHER
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare(
                "SELECT id
                 FROM vouchers
                 WHERE package_id = ?
                   AND status = 'Inactive'
                   AND user_id IS NULL
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([
                (int)$payment['package_id']
            ]);

            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            /*
            |--------------------------------------------------------------------------
            | CREATE OR ACTIVATE VOUCHER
            |--------------------------------------------------------------------------
            */

            if (!$voucher) {

                $voucherCode = hotnetGenerateVoucherCode($pdo);

                $stmt = $pdo->prepare(
                    "INSERT INTO vouchers
                        (
                            voucher_code,
                            package_id,
                            user_id,
                            status,
                            expiry_date
                        )
                     VALUES (?, ?, ?, 'Active', ?)"
                );

                $stmt->execute([
                    $voucherCode,
                    $payment['package_id'],
                    $customerId,
                    $expiryDate
                ]);

                $voucherId = (int)$pdo->lastInsertId();

            } else {

                $voucherId = (int)$voucher['id'];

                $stmt = $pdo->prepare(
                    "UPDATE vouchers
                     SET user_id = ?,
                         status = 'Active',
                         expiry_date = ?
                     WHERE id = ?
                       AND status = 'Inactive'
                       AND user_id IS NULL"
                );

                $stmt->execute([
                    $customerId,
                    $expiryDate,
                    $voucherId
                ]);

                if ($stmt->rowCount() !== 1) {

                    throw new Exception(
                        'Unable to assign available voucher.'
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | MARK PAYMENT AS PAID
            |--------------------------------------------------------------------------
            | IMPORTANT:
            | Preserve transaction_code.
            | Store Safaricom receipt in mpesa_receipt_number.
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare(
                "UPDATE payments
                 SET user_id = ?,
                     voucher_id = ?,
                     mpesa_receipt_number = ?,
                     result_code = ?,
                     result_description = ?,
                     payment_status = 'Paid',
                     payment_date = NOW()
                 WHERE id = ?
                   AND payment_status = 'Pending'"
            );

            $stmt->execute([
                $customerId,
                $voucherId,
                $receipt,
                $resultCode,
                $description,
                $payment['id']
            ]);

            if ($stmt->rowCount() !== 1) {

                throw new Exception(
                    'Unable to update payment record.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | MARK CALLBACK AS PROCESSED
            |--------------------------------------------------------------------------
            */

            $pdo->prepare(
                "UPDATE mpesa_callback_inbox
                 SET processing_status = 'Processed',
                     last_error = NULL,
                     processed_at = NOW()
                 WHERE id = ?"
            )->execute([(int)$callbackId]);

            /*
            |--------------------------------------------------------------------------
            | COMMIT TRANSACTION
            |--------------------------------------------------------------------------
            */

            $pdo->commit();

            hotnetCallbackLog(
                "PAYMENT SUCCESSFUL. " .
                "Payment: {$payment['id']}, " .
                "Customer: {$customerId}, " .
                "Voucher: {$voucherId}, " .
                "Receipt: {$receipt}"
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();
            }

            /*
            |--------------------------------------------------------------------------
            | SAVE ERROR FOR ADMIN REVIEW AND RETRY
            |--------------------------------------------------------------------------
            | Attempts was already increased once inside the transaction.
            | Only save the error here to avoid double counting.
            |--------------------------------------------------------------------------
            */

            try {

                $pdo->prepare(
                    "UPDATE mpesa_callback_inbox
                     SET last_error = ?
                     WHERE id = ?
                       AND processing_status = 'Pending'"
                )->execute([
                    $e->getMessage(),
                    (int)$callbackId
                ]);

            } catch (Throwable $logError) {

                hotnetCallbackLog(
                    'Unable to save processing error: ' .
                    $logError->getMessage()
                );
            }

            hotnetCallbackLog(
                "Callback {$callbackId} processing error: " .
                $e->getMessage()
            );
        }
    }
}