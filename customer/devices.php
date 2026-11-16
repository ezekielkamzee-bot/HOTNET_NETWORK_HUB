
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

mysqli_set_charset($conn, "utf8mb4");

/* =====================================================
   CUSTOMER AUTHENTICATION
===================================================== */

if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit;
}

$customer_id = (int) $_SESSION['customer_id'];

/* =====================================================
   HELPER FUNCTIONS
===================================================== */

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function deviceMessage($message, $type = 'success') {
    $_SESSION['device_message'] = $message;
    $_SESSION['device_message_type'] = $type;

    header("Location: devices.php");
    exit;
}

/* =====================================================
   CSRF PROTECTION
===================================================== */

if (empty($_SESSION['device_csrf'])) {
    $_SESSION['device_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['device_csrf'];

/* =====================================================
   VERIFY CUSTOMER
===================================================== */

$stmt = $conn->prepare(
    "SELECT fullname, username, status
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer || $customer['status'] !== 'Active') {
    session_unset();
    session_destroy();

    header("Location: login.php");
    exit;
}

/* =====================================================
   GET CUSTOMER DEVICE LIMIT
===================================================== */

$stmt = $conn->prepare(
    "SELECT COALESCE(MAX(l.max_devices), 1) AS max_devices
     FROM vouchers v
     LEFT JOIN package_device_limits l
        ON l.package_id = v.package_id
     WHERE v.user_id = ?
       AND v.status = 'Active'
       AND (v.expiry_date IS NULL OR v.expiry_date >= NOW())"
);

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$limit_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$max_devices = max(1, (int)($limit_data['max_devices'] ?? 1));

/* =====================================================
   HANDLE DEVICE REGISTRATION
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'register') {

    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        deviceMessage("Security verification failed.", "danger");
    }

    $device_name = trim($_POST['device_name'] ?? '');
    $device_type = $_POST['device_type'] ?? '';

    $allowed_types = [
        'Phone',
        'Tablet',
        'Laptop',
        'Smart TV',
        'Other'
    ];

    if ($device_name === '' || strlen($device_name) > 100) {
        deviceMessage("Enter a valid device name.", "danger");
    }

    if (!in_array($device_type, $allowed_types, true)) {
        deviceMessage("Select a valid device type.", "danger");
    }

    /* Check active devices */
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM customer_devices
         WHERE user_id = ?
         AND status = 'Active'"
    );

    $stmt->bind_param("i", $customer_id);
    $stmt->execute();

    $active_count = (int)(
        $stmt->get_result()->fetch_assoc()['total'] ?? 0
    );

    $stmt->close();

    if ($active_count >= $max_devices) {
        deviceMessage(
            "You have reached your maximum device limit of "
            . $max_devices . ". Deactivate an existing device first.",
            "warning"
        );
    }

    /* Generate secure device token */
    $device_token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $device_token);

    $stmt = $conn->prepare(
        "INSERT INTO customer_devices
        (
            user_id,
            device_name,
            device_type,
            device_token_hash,
            status,
            first_seen_at
        )
        VALUES (?, ?, ?, ?, 'Active', NOW())"
    );

    $stmt->bind_param(
        "isss",
        $customer_id,
        $device_name,
        $device_type,
        $token_hash
    );

    if (!$stmt->execute()) {
        $stmt->close();
        deviceMessage("Device registration failed.", "danger");
    }

    $stmt->close();
// Save the device token securely in the browser
setcookie('hotnet_device_token', $device_token, [
    'expires'  => time() + (365 * 24 * 60 * 60),
    'path'     => '/network/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);
    $_SESSION['new_device_token'] = $device_token;
    $_SESSION['new_device_name'] = $device_name;

    deviceMessage("Your device was registered successfully.");
}

/* =====================================================
   HANDLE DEVICE DEACTIVATION
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'deactivate') {

    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        deviceMessage("Security verification failed.", "danger");
    }

    $device_id = filter_input(
        INPUT_POST,
        'device_id',
        FILTER_VALIDATE_INT
    );

    if (!$device_id || $device_id < 1) {
        deviceMessage("Invalid device selected.", "danger");
    }

    $stmt = $conn->prepare(
        "UPDATE customer_devices
         SET status = 'Inactive'
         WHERE id = ?
         AND user_id = ?
         AND status = 'Active'"
    );

    $stmt->bind_param("ii", $device_id, $customer_id);
    $stmt->execute();

    $changed = $stmt->affected_rows;
    $stmt->close();

    if ($changed > 0) {
        deviceMessage("Device deactivated successfully.");
    }

    deviceMessage(
        "Device not found or already deactivated.",
        "warning"
    );
}

/* =====================================================
   LOAD MESSAGES
===================================================== */

$message = $_SESSION['device_message'] ?? '';
$message_type = $_SESSION['device_message_type'] ?? 'success';

unset(
    $_SESSION['device_message'],
    $_SESSION['device_message_type']
);

/* =====================================================
   LOAD NEW DEVICE TOKEN
===================================================== */

$new_token = $_SESSION['new_device_token'] ?? '';
$new_device_name = $_SESSION['new_device_name'] ?? '';

unset(
    $_SESSION['new_device_token'],
    $_SESSION['new_device_name']
);

/* =====================================================
   LOAD CUSTOMER DEVICES
===================================================== */

$stmt = $conn->prepare(
    "SELECT id, device_name, device_type, status,
            first_seen_at, last_seen_at
     FROM customer_devices
     WHERE user_id = ?
     ORDER BY created_at DESC"
);

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$result = $stmt->get_result();

$devices = [];
$active_count = 0;

while ($row = $result->fetch_assoc()) {
    $devices[] = $row;

    if ($row['status'] === 'Active') {
        $active_count++;
    }
}

$stmt->close();

$available_slots = max(0, $max_devices - $active_count);

$device_icons = [
    'Phone' => 'smartphone',
    'Tablet' => 'tablet',
    'Laptop' => 'laptop',
    'Smart TV' => 'tv',
    'Other' => 'monitor'
];

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Devices | HOTNET WiFi</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>

:root {
    --hotnet-orange: #f97316;
    --hotnet-dark: #c2410c;
    --hotnet-light: #fff7ed;
    --hotnet-text: #292524;
}

* {
    box-sizing: border-box;
}

body {
    background: #f8fafc;
    color: var(--hotnet-text);
    font-family: Arial, sans-serif;
}

.navbar {
    background: linear-gradient(110deg, #ea580c, #f97316);
    padding: 15px 0;
    box-shadow: 0 3px 12px rgba(0,0,0,.12);
}

.navbar-brand {
    color: white !important;
    font-weight: 800;
    font-size: 23px;
    letter-spacing: 1px;
}

.nav-link-custom {
    color: white;
    text-decoration: none;
    font-weight: 600;
    margin-left: 18px;
}

.nav-link-custom:hover {
    color: #ffedd5;
}

.page-header {
    background: linear-gradient(120deg, #c2410c, #f97316);
    border-radius: 18px;
    padding: 35px;
    color: white;
    box-shadow: 0 8px 25px rgba(234,88,12,.18);
}

.page-header h2 {
    font-weight: 800;
}

.stat-card {
    background: white;
    border-radius: 16px;
    padding: 22px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 5px 18px rgba(0,0,0,.04);
    height: 100%;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 13px;
    background: var(--hotnet-light);
    color: var(--hotnet-orange);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 23px;
    margin-bottom: 14px;
}

.stat-number {
    color: var(--hotnet-dark);
    font-size: 30px;
    font-weight: 800;
}

.content-card {
    background: white;
    padding: 25px;
    border-radius: 17px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 5px 20px rgba(0,0,0,.04);
    height: 100%;
}

.section-title {
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 20px;
}

.form-control,
.form-select {
    padding: 12px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--hotnet-orange);
    box-shadow: 0 0 0 3px rgba(249,115,22,.12);
}

.btn-hotnet {
    background: var(--hotnet-orange);
    color: white;
    border: none;
    border-radius: 10px;
    padding: 11px 18px;
    font-weight: 700;
}

.btn-hotnet:hover {
    background: var(--hotnet-dark);
    color: white;
}

.device-item {
    border: 1px solid #e2e8f0;
    border-radius: 13px;
    padding: 17px;
    margin-bottom: 13px;
    transition: .2s;
}

.device-item:hover {
    border-color: #fdba74;
    box-shadow: 0 4px 12px rgba(0,0,0,.04);
}

.device-symbol {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--hotnet-light);
    color: var(--hotnet-orange);
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 22px;
}

.device-name {
    font-weight: 800;
}

.token-box {
    background: #fff7ed;
    border: 1px dashed var(--hotnet-orange);
    padding: 15px;
    border-radius: 10px;
    overflow-wrap: anywhere;
}

.notice {
    background: #fff7ed;
    border-left: 4px solid var(--hotnet-orange);
    padding: 17px;
    border-radius: 8px;
}

footer {
    background: #292524;
    color: #e7e5e4;
    margin-top: 50px;
    padding: 25px;
}

@media(max-width: 768px) {

    .page-header {
        padding: 25px 20px;
    }

    .page-header h2 {
        font-size: 23px;
    }

    .content-card {
        padding: 18px;
    }

    .nav-link-custom {
        margin-left: 8px;
        font-size: 13px;
    }

}

</style>

</head>

<body>

<!-- NAVIGATION -->

<nav class="navbar navbar-expand-lg">

<div class="container">

<a class="navbar-brand" href="dashboard.php">
<i class="bi bi-wifi"></i> HOTNET
</a>

<div class="ms-auto">

<a href="dashboard.php" class="nav-link-custom">
<i class="bi bi-speedometer2"></i> Dashboard
</a>

<a href="buy_package.php" class="nav-link-custom">
<i class="bi bi-bag"></i> Packages
</a>

<a href="logout.php" class="nav-link-custom">
<i class="bi bi-box-arrow-right"></i> Logout
</a>

</div>

</div>

</nav>


<div class="container py-4">

<!-- PAGE HEADER -->

<div class="page-header mb-4">

<h2>
<i class="bi bi-laptop"></i>
My Devices
</h2>

<p class="mb-0">
Welcome, <?php echo h($customer['fullname']); ?>.
Manage the devices registered to your HOTNET account.
</p>

</div>


<!-- NOTIFICATIONS -->

<?php if ($message): ?>

<div class="alert alert-<?php echo h($message_type); ?> alert-dismissible fade show">

<?php echo h($message); ?>

<button type="button" class="btn-close" data-bs-dismiss="alert"></button>

</div>

<?php endif; ?>


<!-- NEW DEVICE TOKEN -->

<?php if ($new_token): ?>

<div class="content-card mb-4">

<h5 class="text-success fw-bold">
<i class="bi bi-check-circle-fill"></i>
Device Registered Successfully
</h5>

<p>
Your device
<strong><?php echo h($new_device_name); ?></strong>
has been registered.
</p>

<p class="small text-muted">
This private registration token is displayed only once.
</p>

<div class="token-box mb-3">
<code id="deviceToken"><?php echo h($new_token); ?></code>
</div>

<button class="btn btn-hotnet" onclick="copyToken()">
<i class="bi bi-clipboard"></i> Copy Token
</button>

</div>

<?php endif; ?>


<!-- STATISTICS -->

<div class="row g-3 mb-4">

<div class="col-md-4">

<div class="stat-card">

<div class="stat-icon">
<i class="bi bi-phone"></i>
</div>

<div class="text-muted">Active Devices</div>

<div class="stat-number">
<?php echo $active_count; ?>
</div>

</div>

</div>


<div class="col-md-4">

<div class="stat-card">

<div class="stat-icon">
<i class="bi bi-hdd-stack"></i>
</div>

<div class="text-muted">Maximum Devices</div>

<div class="stat-number">
<?php echo $max_devices; ?>
</div>

</div>

</div>


<div class="col-md-4">

<div class="stat-card">

<div class="stat-icon">
<i class="bi bi-plus-circle"></i>
</div>

<div class="text-muted">Available Slots</div>

<div class="stat-number">
<?php echo $available_slots; ?>
</div>

</div>

</div>

</div>


<!-- MAIN CONTENT -->

<div class="row g-4">


<!-- REGISTER DEVICE -->

<div class="col-lg-5">

<div class="content-card">

<h4 class="section-title">
<i class="bi bi-plus-circle-fill text-warning"></i>
Register New Device
</h4>

<?php if ($available_slots <= 0): ?>

<div class="alert alert-warning">

<i class="bi bi-exclamation-triangle"></i>

You have reached your device limit.

Deactivate an existing device before registering another.

</div>

<?php else: ?>

<form method="POST">

<input type="hidden" name="csrf_token"
value="<?php echo h($csrf); ?>">

<input type="hidden" name="action" value="register">


<div class="mb-3">

<label class="form-label fw-bold">
Device Name
</label>

<input type="text"
name="device_name"
class="form-control"
placeholder="Example: My Samsung TV"
maxlength="100"
required>

</div>


<div class="mb-4">

<label class="form-label fw-bold">
Device Type
</label>

<select name="device_type" class="form-select" required>

<option value="">Choose device type</option>

<option value="Phone">Mobile Phone</option>

<option value="Tablet">Tablet</option>

<option value="Laptop">Laptop / Computer</option>

<option value="Smart TV">Smart TV</option>

<option value="Other">Other Device</option>

</select>

</div>


<button type="submit" class="btn btn-hotnet w-100">

<i class="bi bi-plus-circle"></i>
Register Device

</button>

</form>

<?php endif; ?>

</div>

</div>


<!-- DEVICE LIST -->

<div class="col-lg-7">

<div class="content-card">

<h4 class="section-title">
<i class="bi bi-devices text-warning"></i>
Registered Devices
</h4>


<?php if (empty($devices)): ?>

<div class="text-center py-5">

<i class="bi bi-laptop display-3 text-secondary"></i>

<h5 class="mt-3">No Devices Registered</h5>

<p class="text-muted">
Register your first device using the form.
</p>

</div>

<?php else: ?>


<?php foreach ($devices as $device): ?>

<div class="device-item">

<div class="d-flex align-items-center gap-3">

<div class="device-symbol">

<i class="bi bi-<?php
echo h($device_icons[$device['device_type']] ?? 'device-ssd');
?>"></i>

</div>

<div class="flex-grow-1">

<div class="device-name">
<?php echo h($device['device_name']); ?>
</div>

<div class="small text-muted">
<?php echo h($device['device_type']); ?>
</div>

<div class="small text-muted">
Registered:
<?php echo h(date(
    'd M Y',
    strtotime($device['first_seen_at'])
)); ?>
</div>

</div>


<div class="text-end">

<?php if ($device['status'] === 'Active'): ?>

<span class="badge bg-success mb-2">Active</span>

<form method="POST"
onsubmit="return confirm('Are you sure you want to deactivate this device?');">

<input type="hidden" name="csrf_token"
value="<?php echo h($csrf); ?>">

<input type="hidden" name="action"
value="deactivate">

<input type="hidden" name="device_id"
value="<?php echo (int)$device['id']; ?>">

<button class="btn btn-sm btn-outline-danger" type="submit">
Deactivate
</button>

</form>

<?php elseif ($device['status'] === 'Blocked'): ?>

<span class="badge bg-danger">Blocked</span>

<?php else: ?>

<span class="badge bg-secondary">Inactive</span>

<?php endif; ?>

</div>

</div>

</div>

<?php endforeach; ?>

<?php endif; ?>


</div>

</div>

</div>


<!-- CONNECTION NOTICE -->

<div class="notice mt-4">

<h6 class="fw-bold">
<i class="bi bi-info-circle-fill text-warning"></i>
Important Connection Information
</h6>

<p class="mb-0 small">

Device registration currently manages your customer device records.
Actual WiFi authentication, automatic reconnection, roaming and
router control will be implemented in the next stages.

The current system is in simulation mode because no MikroTik
router has been configured.

</p>

</div>


</div>


<!-- FOOTER -->

<footer class="text-center">

<h5 class="fw-bold">
<i class="bi bi-wifi"></i> HOTNET WiFi
</h5>

<p class="mb-1">
Reliable WiFi. Simple Payments. Easy Connectivity.
</p>

<small>
Contact: 0718347038
</small>

</footer>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

function copyToken() {

    const token = document.getElementById('deviceToken').innerText;

    if (navigator.clipboard && window.isSecureContext) {

        navigator.clipboard.writeText(token)
        .then(() => alert('Device token copied successfully.'));

    } else {

        alert('Please select and copy the device token manually.');

    }

}

</script>

</body>
</html>
