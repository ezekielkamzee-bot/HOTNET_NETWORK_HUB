<?php
session_start();

if(!isset($_SESSION['admin'])){
    header("Location: ../auth/login.php");
    exit();
}

include('../config/database.php');
include('menu.php');

$message="";

if(!isset($_GET['id'])){
    header("Location: packages.php");
    exit();
}

$id=(int)$_GET['id'];

$result=mysqli_query($conn,"SELECT * FROM packages WHERE id=$id");

if(mysqli_num_rows($result)==0){
    die("Package not found.");
}

$package=mysqli_fetch_assoc($result);

if(isset($_POST['update'])){

    $name=trim($_POST['package_name']);
    $speed=trim($_POST['speed']);
    $duration=(int)$_POST['duration_days'];
    $price=(float)$_POST['price'];

    $stmt=mysqli_prepare(
        $conn,
        "UPDATE packages
         SET package_name=?,
             speed=?,
             duration_days=?,
             price=?
         WHERE id=?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "ssidi",
        $name,
        $speed,
        $duration,
        $price,
        $id
    );

    mysqli_stmt_execute($stmt);

    $message="Package updated successfully.";

    $result=mysqli_query($conn,"SELECT * FROM packages WHERE id=$id");
    $package=mysqli_fetch_assoc($result);
}
?>

<!DOCTYPE html>
<html>

<head>

<title>Edit Package</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body class="bg-light">

<div class="container mt-4">

<h2>Edit Package</h2>

<?php if($message!=""){ ?>

<div class="alert alert-success">

<?php echo htmlspecialchars($message); ?>

</div>

<?php } ?>

<div class="card shadow">

<div class="card-body">

<form method="POST">

<div class="mb-3">

<label>Package Name</label>

<input
type="text"
name="package_name"
class="form-control"
value="<?php echo htmlspecialchars($package['package_name']); ?>"
required>

</div>

<div class="mb-3">

<label>Speed</label>

<input
type="text"
name="speed"
class="form-control"
value="<?php echo htmlspecialchars($package['speed']); ?>"
required>

</div>

<div class="mb-3">

<label>Duration (Days)</label>

<input
type="number"
name="duration_days"
class="form-control"
value="<?php echo $package['duration_days']; ?>"
required>

</div>

<div class="mb-3">

<label>Price (KES)</label>

<input
type="number"
step="0.01"
name="price"
class="form-control"
value="<?php echo $package['price']; ?>"
required>

</div>

<button
type="submit"
name="update"
class="btn btn-success">

Update Package

</button>

<a
href="packages.php"
class="btn btn-secondary">

Back

</a>

</form>

</div>

</div>

</div>

</body>

</html>