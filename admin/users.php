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

/*=========================================
=            EXPORT CSV                   =
=========================================*/

if (isset($_GET['export']) && $_GET['export'] == "csv") {

    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=users_" . date("YmdHis") . ".csv");

    $output = fopen("php://output", "w");

    fputcsv($output, ["ID", "Full Name", "Phone", "Username", "Status"]);

    $result = mysqli_query($conn, "SELECT * FROM users ORDER BY id DESC");

    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, [
            $row['id'],
            $row['fullname'],
            $row['phone'],
            $row['username'],
            $row['status']
        ]);
    }

    fclose($output);
    exit();
}

/*=========================================
=            DELETE USER (POST only)      =
=========================================*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {

    verifyCsrf();

    $id = (int)$_POST['delete_id'];

    try {

        $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id=?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        $message = "User deleted successfully.";

    } catch (mysqli_sql_exception $e) {

        // MySQL error 1451 = foreign key constraint violation
        if ($e->getCode() == 1451) {
            $message = "Cannot delete this user because they have related records (payments, sessions, etc). Deactivate the user instead.";
        } else {
            $message = "Failed to delete user.";
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
        "UPDATE users SET status = IF(status='Active','Inactive','Active') WHERE id=?"
    );
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);

    header("Location: users.php");
    exit();
}

/*=========================================
=             EDIT USER FETCH             =
=========================================*/

$edit = false;
$id = 0;
$fullname = "";
$phone = "";
$username = "";

if (isset($_GET['edit'])) {

    $id = (int)$_GET['edit'];

    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id=?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        $edit = true;
        $fullname = $row['fullname'];
        $phone = $row['phone'];
        $username = $row['username'];
    } else {
        // invalid id requested — reset
        $id = 0;
    }
}

/*=========================================
=          SAVE / UPDATE USER             =
=========================================*/

if (isset($_POST['save'])) {

    verifyCsrf();

    $id = (int)($_POST['id'] ?? 0);

    $fullname = clean($_POST['fullname']);
    $phone = clean($_POST['phone']);
    $username = clean($_POST['username']);
    $password = $_POST['password'] ?? '';

    // basic validation
    if ($fullname === '' || $phone === '' || $username === '') {
        $message = "All fields are required.";
        $messageType = "danger";
    } elseif ($id === 0 && $password === '') {
        $message = "Password is required for new users.";
        $messageType = "danger";
    } else {

        if ($id > 0) {

            // Check username uniqueness excluding current id
            $check = mysqli_prepare($conn, "SELECT id FROM users WHERE username=? AND id<>?");
            mysqli_stmt_bind_param($check, "si", $username, $id);
            mysqli_stmt_execute($check);
            $exists = mysqli_stmt_get_result($check);

            if (mysqli_num_rows($exists) > 0) {
                $message = "Username already exists.";
                $messageType = "danger";
                $edit = true;
            } else {

                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE users SET fullname=?, phone=?, username=?, password=? WHERE id=?"
                    );
                    mysqli_stmt_bind_param($stmt, "ssssi", $fullname, $phone, $username, $hash, $id);
                } else {
                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE users SET fullname=?, phone=?, username=? WHERE id=?"
                    );
                    mysqli_stmt_bind_param($stmt, "sssi", $fullname, $phone, $username, $id);
                }

                mysqli_stmt_execute($stmt);
                $message = "User updated successfully.";

                // reset form state after successful update
                $edit = false;
                $id = 0;
                $fullname = $phone = $username = "";
            }

        } else {

            $check = mysqli_prepare($conn, "SELECT id FROM users WHERE username=?");
            mysqli_stmt_bind_param($check, "s", $username);
            mysqli_stmt_execute($check);
            $exists = mysqli_stmt_get_result($check);

            if (mysqli_num_rows($exists) > 0) {
                $message = "Username already exists.";
                $messageType = "danger";
            } else {

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO users (fullname, phone, username, password, status)
                     VALUES (?,?,?,?, 'Active')"
                );
                mysqli_stmt_bind_param($stmt, "ssss", $fullname, $phone, $username, $hash);
                mysqli_stmt_execute($stmt);

                $message = "User added successfully.";
                $fullname = $phone = $username = "";
            }
        }
    }
}

/*=========================================
=            DASHBOARD STATS              =
=========================================*/

$totalUsers = 0;
$activeUsers = 0;
$inactiveUsers = 0;

$result = mysqli_query($conn, "SELECT COUNT(*) total FROM users");
if ($row = mysqli_fetch_assoc($result)) $totalUsers = $row['total'];

$result = mysqli_query($conn, "SELECT COUNT(*) total FROM users WHERE status='Active'");
if ($row = mysqli_fetch_assoc($result)) $activeUsers = $row['total'];

$result = mysqli_query($conn, "SELECT COUNT(*) total FROM users WHERE status='Inactive'");
if ($row = mysqli_fetch_assoc($result)) $inactiveUsers = $row['total'];

/*=========================================
=              SEARCH                     =
=========================================*/

$search = trim($_GET['search'] ?? '');

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$where = "";
$params = [];
$types = "";

if ($search != "") {
    $where = " WHERE fullname LIKE ? OR username LIKE ? OR phone LIKE ? ";
    $like = "%{$search}%";
    $params = [$like, $like, $like];
    $types = "sss";
}

/*=========================================
=         TOTAL RECORDS                   =
=========================================*/

$countSql = "SELECT COUNT(*) total FROM users {$where}";
$stmt = mysqli_prepare($conn, $countSql);

if ($types != "") {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$countResult = mysqli_stmt_get_result($stmt);
$totalRows = mysqli_fetch_assoc($countResult)['total'];
$totalPages = max(1, ceil($totalRows / $limit));

// clamp page to valid range now that we know totalPages
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

/*=========================================
=          USER LIST                      =
=========================================*/

$sql = "SELECT * FROM users {$where} ORDER BY id DESC LIMIT ?,?";
$stmt = mysqli_prepare($conn, $sql);

if ($types == "") {
    mysqli_stmt_bind_param($stmt, "ii", $offset, $limit);
} else {
    $bindTypes = $types . "ii";
    $bindValues = array_merge($params, [$offset, $limit]);
    mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindValues);
}

mysqli_stmt_execute($stmt);
$users = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>User Management</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">

<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4">
<div>
<h2 class="fw-bold"><i class="bi bi-people-fill"></i> User Management</h2>
<p class="text-muted">Manage all system users</p>
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
<div class="card shadow border-0">
<div class="card-body text-center">
<h6>Total Users</h6>
<h2 class="text-primary"><?php echo $totalUsers; ?></h2>
</div>
</div>
</div>
<div class="col-md-4">
<div class="card shadow border-0">
<div class="card-body text-center">
<h6>Active Users</h6>
<h2 class="text-success"><?php echo $activeUsers; ?></h2>
</div>
</div>
</div>
<div class="col-md-4">
<div class="card shadow border-0">
<div class="card-body text-center">
<h6>Inactive Users</h6>
<h2 class="text-danger"><?php echo $inactiveUsers; ?></h2>
</div>
</div>
</div>
</div>

<div class="card shadow mb-4">
<div class="card-header bg-primary text-white">
<h5 class="mb-0"><?php echo $edit ? "Edit User" : "Add User"; ?></h5>
</div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="id" value="<?php echo $id; ?>">

<div class="row">
<div class="col-md-6 mb-3">
<label>Full Name</label>
<input type="text" name="fullname" class="form-control" required value="<?php echo htmlspecialchars($fullname); ?>">
</div>
<div class="col-md-6 mb-3">
<label>Phone</label>
<input type="text" name="phone" class="form-control" required value="<?php echo htmlspecialchars($phone); ?>">
</div>
</div>

<div class="row">
<div class="col-md-6 mb-3">
<label>Username</label>
<input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($username); ?>">
</div>
<div class="col-md-6 mb-3">
<label>Password</label>
<input type="password" name="password" class="form-control" <?php if (!$edit) { ?>required<?php } ?>>
<?php if ($edit) { ?>
<small class="text-muted">Leave blank to keep the existing password.</small>
<?php } ?>
</div>
</div>

<button class="btn btn-primary" name="save"><i class="bi bi-save"></i> <?php echo $edit ? "Update User" : "Save User"; ?></button>

<?php if ($edit) { ?>
<a href="users.php" class="btn btn-secondary">Cancel</a>
<?php } ?>
</form>
</div>
</div>

<div class="card shadow">
<div class="card-header d-flex justify-content-between align-items-center">
<h5 class="mb-0">Registered Users</h5>
<form method="GET" class="d-flex">
<input type="text" name="search" class="form-control me-2" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
<button class="btn btn-primary">Search</button>
</form>
</div>

<div class="card-body">
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle">
<thead class="table-dark">
<tr>
<th>ID</th>
<th>Full Name</th>
<th>Phone</th>
<th>Username</th>
<th>Status</th>
<th width="260">Actions</th>
</tr>
</thead>
<tbody>

<?php if (mysqli_num_rows($users) > 0) { ?>
<?php while ($row = mysqli_fetch_assoc($users)) { ?>
<tr>
<td><?php echo $row['id']; ?></td>
<td><?php echo htmlspecialchars($row['fullname']); ?></td>
<td><?php echo htmlspecialchars($row['phone']); ?></td>
<td><?php echo htmlspecialchars($row['username']); ?></td>
<td>
<?php if ($row['status'] == "Active") { ?>
<span class="badge bg-success">Active</span>
<?php } else { ?>
<span class="badge bg-danger">Inactive</span>
<?php } ?>
</td>
<td>
<a href="?edit=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm">Edit</a>

<form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $row['status'] == 'Active' ? 'Deactivate' : 'Activate'; ?> this user?');">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="toggle_id" value="<?php echo $row['id']; ?>">
<button type="submit" class="btn btn-sm <?php echo $row['status'] == 'Active' ? 'btn-secondary' : 'btn-success'; ?>">
<?php echo $row['status'] == 'Active' ? 'Deactivate' : 'Activate'; ?>
</button>
</form>

<form method="POST" class="d-inline" onsubmit="return confirm('Delete this user? This cannot be undone.');">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<input type="hidden" name="delete_id" value="<?php echo $row['id']; ?>">
<button type="submit" class="btn btn-danger btn-sm">Delete</button>
</form>
</td>
</tr>
<?php } ?>
<?php } else { ?>
<tr>
<td colspan="6" class="text-center">No users found.</td>
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