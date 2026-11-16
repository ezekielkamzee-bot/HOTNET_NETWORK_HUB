
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/**
 * Send JSON response and stop execution.
 */
function respond($success, $message, $code = 200, $data = [])
{
    http_response_code($code);

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));

    exit;
}

/**
 * Accept POST requests only.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', 405);
}

/**
 * Get active customer session details.
 */
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$connectionId = (int) ($_SESSION['hotspot_connection_id'] ?? 0);
$voucherId = (int) ($_SESSION['hotspot_voucher_id'] ?? 0);
$deviceId = (int) ($_SESSION['hotspot_device_id'] ?? 0);

$csrfToken = $_POST['csrf_token'] ?? '';

/**
 * Verify session.
 */
if ($customerId <= 0 || $connectionId <= 0 || $voucherId <= 0) {
    respond(false, 'Your connection session has expired.', 401);
}

/**
 * Verify CSRF token.
 */
if (
    !is_string($csrfToken) ||
    empty($_SESSION['heartbeat_csrf_token']) ||
    !hash_equals($_SESSION['heartbeat_csrf_token'], $csrfToken)
) {
    respond(false, 'Security verification failed.', 403);
}

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | VERIFY CONNECTION, VOUCHER, CUSTOMER AND PACKAGE
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            dc.id,
            dc.connection_status,
            dc.device_id,
            dc.bytes_in,
            dc.bytes_out,
            v.status AS voucher_status,
            v.expiry_date,
            u.status AS user_status,
            p.status AS package_status
        FROM device_connections dc
        INNER JOIN vouchers v ON v.id = dc.voucher_id
        INNER JOIN users u ON u.id = dc.user_id
        INNER JOIN packages p ON p.id = dc.package_id
        WHERE dc.id = ?
          AND dc.voucher_id = ?
          AND dc.user_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->bind_param(
        'iii',
        $connectionId,
        $voucherId,
        $customerId
    );

    $stmt->execute();

    $connection = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$connection) {
        throw new Exception('Connection not found.');
    }

    if ($connection['connection_status'] !== 'Active') {
        throw new Exception('Your connection is no longer active.');
    }

    if ($connection['voucher_status'] !== 'Active') {
        throw new Exception('Your voucher is inactive.');
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK VOUCHER EXPIRY
    |--------------------------------------------------------------------------
    */

    // Empty expiry date means the voucher has no expiry.
    if (!empty($connection['expiry_date'])) {

        $expiry = strtotime($connection['expiry_date']);

        if ($expiry === false || $expiry <= time()) {
            throw new Exception('Your voucher has expired.');
        }
    }

    if ($connection['user_status'] !== 'Active') {
        throw new Exception('Your account is inactive.');
    }

    if ($connection['package_status'] !== 'Active') {
        throw new Exception('Your package is unavailable.');
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY REGISTERED DEVICE
    |--------------------------------------------------------------------------
    */

    if ($deviceId > 0) {

        if ((int) $connection['device_id'] !== $deviceId) {
            throw new Exception('Device verification failed.');
        }

        $deviceStmt = $conn->prepare("
            SELECT id
            FROM customer_devices
            WHERE id = ?
              AND user_id = ?
              AND status = 'Active'
            LIMIT 1
        ");

        $deviceStmt->bind_param(
            'ii',
            $deviceId,
            $customerId
        );

        $deviceStmt->execute();

        $device = $deviceStmt->get_result()->fetch_assoc();

        $deviceStmt->close();

        if (!$device) {
            throw new Exception('Your registered device is inactive.');
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE DEVICE ACTIVITY
        |--------------------------------------------------------------------------
        */

        $deviceUpdate = $conn->prepare("
            UPDATE customer_devices
            SET last_seen_at = NOW()
            WHERE id = ?
              AND user_id = ?
              AND status = 'Active'
        ");

        $deviceUpdate->bind_param(
            'ii',
            $deviceId,
            $customerId
        );

        if (!$deviceUpdate->execute()) {
            throw new Exception('Could not update device activity.');
        }

        $deviceUpdate->close();
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE CONNECTION HEARTBEAT
    |--------------------------------------------------------------------------
    */

    $update = $conn->prepare("
        UPDATE device_connections
        SET last_seen_at = NOW()
        WHERE id = ?
          AND voucher_id = ?
          AND user_id = ?
          AND connection_status = 'Active'
    ");

    $update->bind_param(
        'iii',
        $connectionId,
        $voucherId,
        $customerId
    );

    if (!$update->execute()) {
        throw new Exception('Could not update connection activity.');
    }

    $update->close();

    /*
    |--------------------------------------------------------------------------
    | COMMIT TRANSACTION
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    /*
    |--------------------------------------------------------------------------
    | RETURN SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    respond(true, 'HOTNET session is active.', 200, [
        'data' => [
            'connection_id' => $connectionId,
            'voucher_id' => $voucherId,
            'device_id' => $deviceId,
            'status' => 'Active',
            'bytes_in' => (float) $connection['bytes_in'],
            'bytes_out' => (float) $connection['bytes_out']
        ]
    ]);

} catch (Throwable $error) {

    $conn->rollback();

    error_log('HOTNET heartbeat: ' . $error->getMessage());

    respond(false, $error->getMessage(), 200);
}
