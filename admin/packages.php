<?php
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/database.php');
include('menu.php');

function clean($data)
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function verifyCsrf() {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Invalid CSRF token.');
    }
}

$message = "";
$messageType = "success";

$validTypes = ['Hourly', 'Daily', 'Weekly', 'Monthly'];

/*=========================================
=            EXPORT CSV                   =
=========================================*/

if (isset($_GET['export']) && $_GET['export'] == "csv") {

    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=packages_" . date("YmdHis") . ".csv");

    $output = fopen("php://output", "w");

    fputcsv($output, ["ID", "Package Name", "Type", "Duration", "Speed", "Price (KES)", "Status"]);

    $result = mysqli_query($conn, "SELECT * FROM packages ORDER BY id DESC");

    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, [
            $row['id'],
            $row['package_name'],
            $row['package_type'],
            $row['duration_value'],
            $row['speed'],
            number_format($row['price'], 2),
            $row['status'] ?? 'Active'
        ]);
    }

    fclose($output);
    exit();
}

/*=========================================
=            DELETE PACKAGE (POST only)   =
=========================================*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {

    verifyCsrf();

    $id = (int)$_POST['delete_id'];

    try {

        $stmt = mysqli_prepare($conn, "DELETE FROM packages WHERE id=?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        $message = "Package deleted successfully.";

    } catch (mysqli_sql_exception $e) {

        if ($e->getCode() == 1451) {
            $message = "Cannot delete this package because it is linked to existing users/vouchers/payments. Deactivate it instead.";
        } else {
            $message = "Failed to delete package.";
        }

        $messageType = "danger";
    }
}

/*=========================================
=      ACTIVATE / DEACTIVATE (POST only)  =
=========================================*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {

    verifyCsrf();

    $id = (int)$_POST['toggle_id'];

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE packages SET status = IF(status='Active','Inactive','Active') WHERE id=?"
    );
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);

    header("Location: packages.php");
    exit();
}

/*=========================================
=             EDIT PACKAGE FETCH          =
=========================================*/

$edit = false;
$id = 0;
$package_name = "";
$package_type = "Daily";
$duration_value = 1;
$speed = "";
$price = "";

if (isset($_GET['edit'])) {

    $id = (int)$_GET['edit'];

    $stmt = mysqli_prepare($conn, "SELECT * FROM packages WHERE id=?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        $edit = true;
        $package_name = $row['package_name'];
        $package_type = $row['package_type'];
        $duration_value = $row['duration_value'];
        $speed = $row['speed'];
        $price = $row['price'];
    } else {
        $id = 0;
    }
}

/*=========================================
=          SAVE / UPDATE PACKAGE          =
=========================================*/

if (isset($_POST['save'])) {

    verifyCsrf();

    $id = (int)($_POST['id'] ?? 0);

    $package_name = clean($_POST['package_name']);
    $package_type = $_POST['package_type'] ?? '';
    $duration_value = (int)($_POST['duration_value'] ?? 0);
    $speed = clean($_POST['speed']);
    $price = (float)($_POST['price'] ?? 0);

    if ($package_name === '' || $speed === '') {
        $message = "Package name and speed are required.";
        $messageType = "danger";
    } elseif (!in_array($package_type, $validTypes, true)) {
        $message = "Invalid package type selected.";
        $messageType = "danger";
    } elseif ($duration_value <= 0) {
        $message = "Duration must be greater than zero.";
        $messageType = "danger";
    } elseif ($price < 0) {
        $message = "Price cannot be negative.";
        $messageType = "danger";
    } else {

        if ($id > 0) {

            // uniqueness check excluding current row
            $check = mysqli_prepare($conn, "SELECT id FROM packages WHERE package_name=? AND id<>?");
            mysqli_stmt_bind_param($check, "si", $package_name, $id);
            mysqli_stmt_execute($check);
            $exists = mysqli_stmt_get_result($check);

            if (mysqli_num_rows($exists) > 0) {
                $message = "A package with this name already exists.";
                $messageType = "danger";
                $edit = true;
            } else {

                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE packages
                     SET package_name=?, package_type=?, duration_value=?, speed=?, price=?
                     WHERE id=?"
                );
                mysqli_stmt_bind_param(
                    $stmt,
                    "ssisdi",
                    $package_name,
                    $package_type,
                    $duration_value,
                    $speed,
                    $price,
                    $id
                );
                mysqli_stmt_execute($stmt);

                $message = "Package updated successfully.";

                // reset form
                $edit = false;
                $id = 0;
                $package_name = "";
                $package_type = "Daily";
                $duration_value = 1;
                $speed = "";
                $price = "";
            }

        } else {

            $check = mysqli_prepare($conn, "SELECT id FROM packages WHERE package_name=?");
            mysqli_stmt_bind_param($check, "s", $package_name);
            mysqli_stmt_execute($check);
            $result = mysqli_stmt_get_result($check);

            if (mysqli_num_rows($result) > 0) {
                $message = "Package already exists.";
                $messageType = "danger";
            } else {

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO packages
                    (package_name, package_type, duration_value, speed, price, status)
                    VALUES (?, ?, ?, ?, ?, 'Active')"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssisd",
                    $package_name,
                    $package_type,
                    $duration_value,
                    $speed,
                    $price
                );

                mysqli_stmt_execute($stmt);

                $message = "Package added successfully.";

                $package_name = "";
                $package_type = "Daily";
                $duration_value = 1;
                $speed = "";
                $price = "";
            }
        }
    }
}

/*=========================================
=            DASHBOARD STATS              =
=========================================*/

$totalPackages = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) total FROM packages")
)['total'];

$activePackages = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) total FROM packages WHERE status='Active'")
)['total'];

$inactivePackages = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) total FROM packages WHERE status='Inactive'")
)['total'];

/*=========================================
=              SEARCH + PAGINATION        =
=========================================*/

$search = trim($_GET['search'] ?? '');

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$where = "";
$params = [];
$types = "";

if ($search != "") {
    $where = " WHERE package_name LIKE ? OR speed LIKE ? OR package_type LIKE ? ";
    $like = "%{$search}%";
    $params = [$like, $like, $like];
    $types = "sss";
}

/*=========================================
=         TOTAL RECORDS                   =
=========================================*/

$countSql = "SELECT COUNT(*) total FROM packages {$where}";
$stmt = mysqli_prepare($conn, $countSql);

if ($types != "") {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$countResult = mysqli_stmt_get_result($stmt);
$totalRows = mysqli_fetch_assoc($countResult)['total'];
$totalPages = max(1, ceil($totalRows / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

/*=========================================
=          PACKAGE LIST                   =
=========================================*/

$sql = "SELECT * FROM packages {$where} ORDER BY id DESC LIMIT ?,?";
$stmt = mysqli_prepare($conn, $sql);

if ($types == "") {
    mysqli_stmt_bind_param($stmt, "ii", $offset, $limit);
} else {
    $bindTypes = $types . "ii";
    $bindValues = array_merge($params, [$offset, $limit]);
    mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindValues);
}

mysqli_stmt_execute($stmt);
$packages = mysqli_stmt_get_result($stmt);

/*=========================================
=          HELPER: DURATION LABEL         =
=========================================*/

function formatDuration($value, $type)
{
    $unitMap = [
        'Hourly'  => $value == 1 ? 'Hour' : 'Hours',
        'Daily'   => $value == 1 ? 'Day' : 'Days',
        'Weekly'  => $value == 1 ? 'Week' : 'Weeks',
        'Monthly' => $value == 1 ? 'Month' : 'Months',
    ];

    $unit = $unitMap[$type] ?? 'Days';

    return $value . ' ' . $unit;
}

function typeBadgeClass($type)
{
    $map = [
        'Hourly'  => 'bg-info text-dark',
        'Daily'   => 'bg-primary',
        'Weekly'  => 'bg-warning text-dark',
        'Monthly' => 'bg-dark',
    ];

    return $map[$type] ?? 'bg-secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Package Management</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">

<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4">
<div>
<h2 class="fw-bold"><i class="bi bi-box-seam"></i> Package Management</h2>
<p class="text-muted">Manage hourly, daily, weekly and monthly packages</p>
</div>
<div>
<a href="?export=csv" class="btn btn-success"><i class="bi bi-file-earmark-spreadsheet"></i> Export CSV</a>
<button onclick="window.print()" class="btn btn-dark"><i class="bi bi-printer"></i> Print</button>
</div>
</div>

<?php if ($message != "") { ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php } ?>

<div class="row g-3 mb-4">
<div class="col-md-4">
<div class="card text-bg-primary shadow border-0">
<div class="card-body text-center">
<h6>Total Packages</h6>
<h2><?php echo $totalPackages; ?></h2>
</div>
</div>
</div>
<div class="col-md-4">
<div class="card text-bg-success shadow border-0">
<div class="card-body text-center">
<h6>Active Packages</h6>
<h2><?php echo $activePackages; ?></h2>
</div>
</div>
</div>
<div class="col-md-4">
<div class="card text-bg-danger shadow border-0">
<div class="card-body text-center">
<h6>Inactive Packages</h6>
<h2><?php echo $inactivePackages; ?></h2>
</div>
</div>
</div>
</div>

<!-- ADD / EDIT PACKAGE -->
<div class="card shadow mb-4">
<div class="card-header bg-primary text-white">
<h4 class="mb-0"><?php echo $edit ? "Edit Package" : "Add New Package"; ?></h4>
</div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="id" value="<?php echo $id; ?>">

<div class="row">
<div class="col-md-6 mb-3">
<label class="form-label">Package Name</label>
<input type="text" name="package_name" class="form-control"
       placeholder="Example: Weekend Unlimited" required
       value="<?php echo htmlspecialchars($package_name); ?>">
</div>

<div class="col-md-6 mb-3">
<label class="form-label">Speed</label>
<input type="text" name="speed" class="form-control"
       placeholder="Example: 10 Mbps" required
       value="<?php echo htmlspecialchars($speed); ?>">
</div>
</div>

<div class="row">
<div class="col-md-4 mb-3">
<label class="form-label">Package Type</label>
<select name="package_type" class="form-select" required>
<?php foreach ($validTypes as $type) { ?>
<option value="<?php echo $type; ?>" <?php echo ($package_type == $type) ? 'selected' : ''; ?>>
<?php echo $type; ?>
</option>
<?php } ?>
</select>
</div>

<div class="col-md-4 mb-3">
<label class="form-label">Duration Value</label>
<input type="number" name="duration_value" class="form-control"
       min="1" required
       value="<?php echo htmlspecialchars($duration_value); ?>">
<small class="text-muted">
e.g. Type = Hourly, Value = 3 → "3 Hours"
</small>
</div>

<div class="col-md-4 mb-3">
<label class="form-label">Price (KES)</label>
<input type="number" name="price" class="form-control"
       step="0.01" min="0" required
       value="<?php echo htmlspecialchars($price); ?>">
</div>
</div>

<button type="submit" name="save" class="btn btn-primary">
<i class="bi bi-save"></i> <?php echo $edit ? "Update Package" : "Save Package"; ?>
</button>

<?php if ($edit) { ?>
<a href="packages.php" class="btn btn-secondary">Cancel</a>
<?php } ?>

</form>
</div>
</div>

<!-- PACKAGE LIST -->
<div class="card shadow">
<div class="card-header d-flex justify-content-between align-items-center">
<h4 class="mb-0">Available Packages</h4>
<form method="GET" class="d-flex">
<input type="text" name="search" class="form-control me-2"
       placeholder="Search package, speed or type..."
       value="<?php echo htmlspecialchars($search); ?>">
<button class="btn btn-primary">Search</button>
</form>
</div>

<div class="card-body">
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle">
<thead class="table-dark">
<tr>
<th>ID</th>
<th>Package</th>
<th>Type</th>
<th>Duration</th>
<th>Speed</th>
<th>Price</th>
<th>Status</th>
<th width="260">Actions</th>
</tr>
</thead>
<tbody>

<?php if (mysqli_num_rows($packages) > 0) { ?>
<?php while ($row = mysqli_fetch_assoc($packages)) { ?>
<tr>
<td><?php echo $row['id']; ?></td>
<td><?php echo htmlspecialchars($row['package_name']); ?></td>
<td>
<span class="badge <?php echo typeBadgeClass($row['package_type']); ?>">
<?php echo htmlspecialchars($row['package_type']); ?>
</span>
</td>
<td><?php echo formatDuration($row['duration_value'], $row['package_type']); ?></td>
<td><?php echo htmlspecialchars($row['speed']); ?></td>
<td>KES <?php echo number_format($row['price'], 2); ?></td>
<td>
<?php if (($row['status'] ?? 'Active') == "Active") { ?>
<span class="badge bg-success">Active</span>
<?php } else { ?>
<span class="badge bg-danger">Inactive</span>
<?php } ?>
</td>
<td>
<a href="?edit=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm">Edit</a>

<form method="POST" class="d-inline" onsubmit="return confirm('<?php echo ($row['status'] ?? 'Active') == 'Active' ? 'Deactivate' : 'Activate'; ?> this package?');">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="toggle_id" value="<?php echo $row['id']; ?>">
<button type="submit" class="btn btn-sm <?php echo ($row['status'] ?? 'Active') == 'Active' ? 'btn-secondary' : 'btn-success'; ?>">
<?php echo ($row['status'] ?? 'Active') == 'Active' ? 'Deactivate' : 'Activate'; ?>
</button>
</form>

<form method="POST" class="d-inline" onsubmit="return confirm('Delete this package? This cannot be undone.');">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="delete_id" value="<?php echo $row['id']; ?>">
<button type="submit" class="btn btn-danger btn-sm">Delete</button>
</form>
</td>
</tr>
<?php } ?>
<?php } else { ?>
<tr>
<td colspan="8" class="text-center">No packages found.</td>
</tr>
<?php } ?>

</tbody>
</table>
</div>

<nav class="mt-3">
<ul class="pagination justify-content-center">
<?php for ($i = 1; $i <= $totalPages; $i++) { ?>
<li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
<a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
</li>
<?php } ?>
</ul>
</nav>

</div>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>