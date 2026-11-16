<?php
session_start();

if(!isset($_SESSION['admin'])){
    header("Location: ../auth/login.php");
    exit();
}

include('../config/database.php');


if(!isset($_GET['id'])){

    die("Invalid receipt request.");

}


$id = (int)$_GET['id'];


/* GET PAYMENT DETAILS */

$stmt = mysqli_prepare(
    $conn,
    "SELECT 
        payments.*,
        users.fullname,
        users.username,
        users.phone,
        users.email
     FROM payments
     LEFT JOIN users
     ON payments.user_id = users.id
     WHERE payments.id=?"
);


mysqli_stmt_bind_param(
    $stmt,
    "i",
    $id
);


mysqli_stmt_execute($stmt);


$result = mysqli_stmt_get_result($stmt);


$payment = mysqli_fetch_assoc($result);



if(!$payment){

    die("Payment receipt not found.");

}

?>


<!DOCTYPE html>

<html>

<head>

<title>
Payment Receipt
</title>


<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">


<style>


body{

    background:#f5f5f5;

}



.receipt{

    width:800px;
    max-width:100%;
    margin:30px auto;
    background:white;
    padding:40px;
    border-radius:10px;
    box-shadow:0 0 15px #ccc;

}



.logo{

    text-align:center;

}



.receipt-title{

    text-align:center;
    margin-bottom:30px;

}



.table th{

    width:35%;

}



@media print{


.no-print{

    display:none;

}


.receipt{

    box-shadow:none;

}


body{

    background:white;

}


}



</style>


</head>



<body>


<div class="receipt">


<div class="logo">


<h2>
WIFI PAYMENT RECEIPT
</h2>


<p>
Thank you for your payment
</p>


</div>



<hr>



<div class="receipt-title">

<h4>
Receipt No: #<?php echo $payment['id']; ?>
</h4>


<p>

Date:
<?php echo $payment['payment_date']; ?>

</p>


</div>




<table class="table table-bordered">


<tr>

<th>
Customer Name
</th>


<td>

<?php echo htmlspecialchars($payment['fullname']); ?>

</td>


</tr>



<tr>

<th>
Username
</th>


<td>

<?php echo htmlspecialchars($payment['username']); ?>

</td>


</tr>



<tr>

<th>
Phone
</th>


<td>

<?php echo htmlspecialchars($payment['phone'] ?? '-'); ?>

</td>


</tr>



<tr>

<th>
Email
</th>


<td>

<?php echo htmlspecialchars($payment['email'] ?? '-'); ?>

</td>


</tr>



<tr>

<th>
Amount Paid
</th>


<td>

<strong>

KES 
<?php

echo number_format(
    $payment['amount'],
    2
);

?>

</strong>


</td>


</tr>




<tr>

<th>
Payment Method
</th>


<td>

<?php echo htmlspecialchars($payment['payment_method']); ?>

</td>


</tr>




<tr>

<th>
Transaction Code
</th>


<td>

<?php echo htmlspecialchars($payment['transaction_code']); ?>

</td>


</tr>



</table>



<div class="text-center mt-4">


<h5>
Payment Successful
</h5>


<p>
Keep this receipt for your records.
</p>


</div>




<div class="text-center mt-4 no-print">


<button 
onclick="window.print()"
class="btn btn-primary">

🖨 Print Receipt

</button>



<a href="payments.php"
class="btn btn-secondary">

Back

</a>



</div>



</div>


</body>


</html>