<?php
session_start();

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

function redirectWithMessage($message, $type = 'info')
{
    $_SESSION['disconnect_message'] = $message;
    $_SESSION['disconnect_message_type'] = $type;

    header('Location: device_status.php');
    exit;
}

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$connectionId = (int)($_SESSION['hotspot_connection_id'] ?? 0);
$voucherId = (int)($_SESSION['hotspot_voucher_id'] ?? 0);

if ($customerId <= 0) {
    header('Location: ../customer/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage('Invalid request.', 'danger');
}

$csrfToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($csrfToken) ||
    empty($_SESSION['disconnect_csrf']) ||
    !hash_equals($_SESSION['disconnect_csrf'], $csrfToken)
) {
    redirectWithMessage('Security verification failed.', 'danger');
}

if ($connectionId <= 0 || $voucherId <= 0) {
    redirectWithMessage('No active connection was found.', 'danger');
}

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | VERIFY CONNECTION OWNER
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT id, connection_status
        FROM device_connections
        WHERE id = ?
          AND voucher_id = ?
          AND user_id = ?
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
        throw new Exception('Connection not found or access denied.');
    }

    if ($connection['connection_status'] !== 'Active') {
        throw new Exception('This connection is already disconnected.');
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE CONNECTION
    |--------------------------------------------------------------------------
    */

    $reason = 'Customer requested disconnection.';

    $update = $conn->prepare("
        UPDATE device_connections
        SET connection_status = 'Disconnected',
            ended_at = NOW(),
            last_seen_at = NOW(),
            disconnect_reason = ?
        WHERE id = ?
          AND voucher_id = ?
          AND user_id = ?
          AND connection_status = 'Active'
    ");

    $update->bind_param(
        'siii',
        $reason,
        $connectionId,
        $voucherId,
        $customerId
    );

    $update->execute();

    if ($update->affected_rows !== 1) {
        throw new Exception('Could not disconnect the connection.');
    }

    $update->close();

    /*
    |--------------------------------------------------------------------------
    | RECORD EVENT
    |--------------------------------------------------------------------------
    */

    $eventType = 'Disconnected';

    $description = 'Customer disconnected their HOTNET simulated connection.';

    $event = $conn->prepare("
        INSERT INTO roaming_events (
            connection_id,
            event_type,
            event_description,
            occurred_at
        ) VALUES (?, ?, ?, NOW())
    ");

    $event->bind_param(
        'iss',
        $connectionId,
        $eventType,
        $description
    );

    if (!$event->execute()) {
        throw new Exception('Could not record disconnection event.');
    }

    $event->close();

    $conn->commit();

    /*
    |--------------------------------------------------------------------------
    | PRESERVE VOUCHER AND DEVICE
    |--------------------------------------------------------------------------
    */

    unset($_SESSION['hotspot_connection_id']);

    $_SESSION['disconnect_csrf'] = bin2hex(random_bytes(32));

    redirectWithMessage(
        'Your HOTNET connection has been disconnected successfully.',
        'success'
    );

} catch (Throwable $error) {

    $conn->rollback();

    error_log('HOTNET disconnect: ' . $error->getMessage());

    redirectWithMessage($error->getMessage(), 'danger');
}