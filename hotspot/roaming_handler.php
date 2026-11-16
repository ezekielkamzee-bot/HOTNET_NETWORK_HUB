<?php

session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| HOTNET ROAMING HANDLER
|--------------------------------------------------------------------------
| Purpose:
| - Validate customer connection.
| - Validate destination access point.
| - Check roaming settings.
| - Update simulated connection.
| - Record roaming event.
|--------------------------------------------------------------------------
*/

function redirectWithMessage($message, $type = 'error')
{
    $_SESSION['roaming_message'] = $message;
    $_SESSION['roaming_message_type'] = $type;

    header('Location: roaming.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| REQUEST METHOD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: roaming.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| CUSTOMER SESSION
|--------------------------------------------------------------------------
*/

$voucherId = (int) ($_SESSION['hotspot_voucher_id'] ?? 0);
$connectionId = (int) ($_SESSION['hotspot_connection_id'] ?? 0);
$deviceId = (int) ($_SESSION['hotspot_device_id'] ?? 0);

if ($voucherId <= 0 || $connectionId <= 0) {
    redirectWithMessage('No active hotspot connection was found.');
}

/*
|--------------------------------------------------------------------------
| CSRF VALIDATION
|--------------------------------------------------------------------------
*/

$csrfToken = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['roaming_csrf_token']) ||
    !hash_equals($_SESSION['roaming_csrf_token'], $csrfToken)
) {
    redirectWithMessage('Invalid security token. Please try again.');
}

/*
|--------------------------------------------------------------------------
| DESTINATION ACCESS POINT
|--------------------------------------------------------------------------
*/

$accessPointId = filter_input(
    INPUT_POST,
    'access_point_id',
    FILTER_VALIDATE_INT
);

if (!$accessPointId || $accessPointId <= 0) {
    redirectWithMessage('Please select a valid access point.');
}

/*
|--------------------------------------------------------------------------
| DATABASE TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | CHECK ROAMING SETTINGS
    |--------------------------------------------------------------------------
    */

    $settings = [];

    $settingsResult = $conn->query("
        SELECT setting_key, setting_value
        FROM hotspot_settings
        WHERE setting_key IN ('allow_roaming', 'router_mode')
    ");

    if (!$settingsResult) {
        throw new Exception('Unable to read hotspot settings.');
    }

    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    if (($settings['allow_roaming'] ?? '0') !== '1') {
        throw new Exception('Roaming is currently disabled.');
    }

    if (($settings['router_mode'] ?? 'Simulation') !== 'Simulation') {
        throw new Exception(
            'This roaming handler currently supports simulation mode only.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LOCK AND VALIDATE CONNECTION
    |--------------------------------------------------------------------------
    */

    $connectionStmt = $conn->prepare("
        SELECT
            dc.id,
            dc.user_id,
            dc.voucher_id,
            dc.device_id,
            dc.connection_status,
            dc.connection_mode,
            dc.access_point_id,
            v.status AS voucher_status,
            v.expiry_date
        FROM device_connections dc
        INNER JOIN vouchers v
            ON dc.voucher_id = v.id
        INNER JOIN users u
            ON dc.user_id = u.id
        INNER JOIN packages p
            ON dc.package_id = p.id
        WHERE dc.id = ?
          AND dc.voucher_id = ?
          AND dc.connection_status = 'Active'
          AND v.status = 'Active'
          AND u.status = 'Active'
          AND p.status = 'Active'
          AND v.expiry_date > NOW()
        LIMIT 1
        FOR UPDATE
    ");

    if (!$connectionStmt) {
        throw new Exception('Unable to prepare connection validation.');
    }

    $connectionStmt->bind_param(
        'ii',
        $connectionId,
        $voucherId
    );

    $connectionStmt->execute();

    $connection = $connectionStmt->get_result()->fetch_assoc();

    if (!$connection) {
        throw new Exception('Your hotspot connection is no longer active.');
    }

    if ((int) $connection['user_id'] !== (int) ($_SESSION['customer_id'] ?? 0)) {
        throw new Exception('You are not authorized to move this connection.');
    }

    if ($deviceId > 0 && (int) $connection['device_id'] !== $deviceId) {
        throw new Exception('This connection does not belong to your registered device.');
    }

    if ($connection['connection_mode'] !== 'Simulation') {
        throw new Exception('This connection is not in simulation mode.');
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK DESTINATION ACCESS POINT
    |--------------------------------------------------------------------------
    */

    $accessPointStmt = $conn->prepare("
        SELECT
            id,
            access_point_name,
            router_node_id,
            status
        FROM access_points
        WHERE id = ?
          AND status = 'Simulation'
        LIMIT 1
    ");

    $accessPointStmt->bind_param('i', $accessPointId);
    $accessPointStmt->execute();

    $destination = $accessPointStmt->get_result()->fetch_assoc();

    if (!$destination) {
        throw new Exception(
            'The selected access point is unavailable or not registered for simulation.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK SOURCE ACCESS POINT
    |--------------------------------------------------------------------------
    */

    $oldAccessPointId = !empty($connection['access_point_id'])
        ? (int) $connection['access_point_id']
        : null;

    if ($oldAccessPointId === $accessPointId) {
        throw new Exception('You are already connected to this access point.');
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE CONNECTION ACCESS POINT
    |--------------------------------------------------------------------------
    */

    $routerNodeId = !empty($destination['router_node_id'])
        ? (int) $destination['router_node_id']
        : null;

    $updateStmt = $conn->prepare("
        UPDATE device_connections
        SET
            access_point_id = ?,
            router_node_id = ?,
            last_seen_at = NOW()
        WHERE id = ?
          AND connection_status = 'Active'
    ");

    $updateStmt->bind_param(
        'iii',
        $accessPointId,
        $routerNodeId,
        $connectionId
    );

    if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
        throw new Exception('Unable to update your connection.');
    }

    /*
    |--------------------------------------------------------------------------
    | RECORD ROAMING EVENT
    |--------------------------------------------------------------------------
    */

    $eventDescription = 'Simulated roaming to ' .
        $destination['access_point_name'];

    $eventStmt = $conn->prepare("
        INSERT INTO roaming_events (
            connection_id,
            device_id,
            from_access_point_id,
            to_access_point_id,
            event_type,
            event_description,
            occurred_at
        ) VALUES (?, ?, ?, ?, 'Roamed', ?, NOW())
    ");

    $eventDeviceId = $deviceId > 0 ? $deviceId : null;

    $eventStmt->bind_param(
        'iiiis',
        $connectionId,
        $eventDeviceId,
        $oldAccessPointId,
        $accessPointId,
        $eventDescription
    );

    if (!$eventStmt->execute()) {
        throw new Exception('Unable to save roaming event.');
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    redirectWithMessage(
        'Simulated roaming completed successfully to ' .
        $destination['access_point_name'] . '.',
        'success'
    );

} catch (Throwable $e) {

    $conn->rollback();

    error_log(
        'HOTNET roaming error: ' . $e->getMessage()
    );

    redirectWithMessage($e->getMessage());

}