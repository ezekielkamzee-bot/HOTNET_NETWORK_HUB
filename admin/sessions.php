<?php
session_start();

if(!isset($_SESSION['admin'])){
    header("Location: ../auth/login.php");
    exit();
}

include('../config/database.php');
include('menu.php');

$result = mysqli_query($conn,"
    SELECT sessions.*, vouchers.voucher_code
    FROM sessions
    JOIN vouchers
    ON sessions.voucher_id = vouchers.id
    ORDER BY sessions.id DESC
");

$totalSessions = mysqli_num_rows($result);

$activeSessionsResult = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS activeCount
     FROM sessions
     WHERE logout_time IS NULL"
);

$activeSessions = mysqli_fetch_assoc(
    $activeSessionsResult
)['activeCount'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Session Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container mt-4">

    <h1 class="mb-4">WiFi Session Reports</h1>

    <div class="row mb-4">

        <div class="col-md-4">

            <div class="card shadow">

                <div class="card-header">
                    <h5>Total Sessions</h5>
                </div>

                <div class="card-body text-center">

                    <h2><?php echo $totalSessions; ?></h2>

                </div>

            </div>

        </div>

        <div class="col-md-4">

            <div class="card shadow">

                <div class="card-header">
                    <h5>Active Sessions</h5>
                </div>

                <div class="card-body text-center">

                    <h2 class="text-success">
                        <?php echo $activeSessions; ?>
                    </h2>

                </div>

            </div>

        </div>

    </div>

    <div class="card shadow">

        <div class="card-header">
            <h4>Session History</h4>
        </div>

        <div class="card-body">

            <table class="table table-bordered table-hover">

                <thead class="table-dark">

                    <tr>
                        <th>ID</th>
                        <th>Voucher Code</th>
                        <th>Login Time</th>
                        <th>Logout Time</th>
                        <th>Status</th>
                    </tr>

                </thead>

                <tbody>

                <?php while($row = mysqli_fetch_assoc($result)){ ?>

                <tr>

                    <td><?php echo $row['id']; ?></td>

                    <td><?php echo $row['voucher_code']; ?></td>

                    <td><?php echo $row['login_time']; ?></td>

                    <td>
                        <?php
                        echo $row['logout_time']
                        ? $row['logout_time']
                        : '-';
                        ?>
                    </td>

                    <td>

                        <?php
                        if(empty($row['logout_time'])){
                            echo '<span class="badge bg-success">Active</span>';
                        }else{
                            echo '<span class="badge bg-secondary">Ended</span>';
                        }
                        ?>

                    </td>

                </tr>

                <?php } ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>