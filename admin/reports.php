<?php
session_start();

if(!isset($_SESSION['admin'])){
    header("Location: ../auth/login.php");
    exit();
}

include('../config/database.php');
include('menu.php');

$revenueResult = mysqli_query(
    $conn,
    "SELECT SUM(amount) AS totalRevenue FROM payments"
);

$revenueRow = mysqli_fetch_assoc($revenueResult);

$totalRevenue = $revenueRow['totalRevenue'] ?? 0;

$totalPayments = mysqli_num_rows(
    mysqli_query($conn,"SELECT * FROM payments")
);

$totalUsers = mysqli_num_rows(
    mysqli_query($conn,"SELECT * FROM users")
);

$totalPackages = mysqli_num_rows(
    mysqli_query($conn,"SELECT * FROM packages")
);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reports</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container mt-4">

    <h1 class="mb-4">System Reports</h1>

    <div class="row mb-4">

        <div class="col-md-3">

            <div class="card shadow">

                <div class="card-header">
                    <h6>Total Revenue</h6>
                </div>

                <div class="card-body text-center">

                    <h3 class="text-success">
                        KES <?php echo number_format($totalRevenue,2); ?>
                    </h3>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="card shadow">

                <div class="card-header">
                    <h6>Total Payments</h6>
                </div>

                <div class="card-body text-center">

                    <h3><?php echo $totalPayments; ?></h3>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="card shadow">

                <div class="card-header">
                    <h6>Total Users</h6>
                </div>

                <div class="card-body text-center">

                    <h3><?php echo $totalUsers; ?></h3>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="card shadow">

                <div class="card-header">
                    <h6>Total Packages</h6>
                </div>

                <div class="card-body text-center">

                    <h3><?php echo $totalPackages; ?></h3>

                </div>

            </div>

        </div>

    </div>

    <div class="card shadow">

        <div class="card-header">
            <h4>Payment Records</h4>
        </div>

        <div class="card-body">

            <table class="table table-bordered table-hover">

                <thead class="table-dark">

                    <tr>
                        <th>ID</th>
                        <th>User ID</th>
                        <th>Amount</th>
                        <th>Transaction Code</th>
                        <th>Method</th>
                        <th>Date</th>
                    </tr>

                </thead>

                <tbody>

                <?php

                $payments = mysqli_query(
                    $conn,
                    "SELECT * FROM payments
                     ORDER BY payment_date DESC"
                );

                while($payment = mysqli_fetch_assoc($payments)){
                ?>

                <tr>

                    <td><?php echo $payment['id']; ?></td>

                    <td><?php echo $payment['user_id']; ?></td>

                    <td>
                        KES <?php echo number_format($payment['amount'],2); ?>
                    </td>

                    <td><?php echo $payment['transaction_code']; ?></td>

                    <td><?php echo $payment['payment_method']; ?></td>

                    <td><?php echo $payment['payment_date']; ?></td>

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