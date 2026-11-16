<?php
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/database.php');
include('menu.php');

$message = "";

/* =====================================
   DELETE VOUCHER
===================================== */

if (isset($_GET['delete'])) {

    $id = (int)$_GET['delete'];

    mysqli_query(
        $conn,
        "DELETE FROM vouchers WHERE id=$id"
    );

    header("Location: vouchers.php");
    exit();
}

/* =====================================
   GENERATE UNIQUE VOUCHER
===================================== */

if (isset($_POST['generate'])) {

    $package_id = (int)$_POST['package_id'];

    do {

        $voucher =
            "WIFI-" .
            strtoupper(
                substr(bin2hex(random_bytes(6)),0,12)
            );

        $check = mysqli_prepare(
            $conn,
            "SELECT id
             FROM vouchers
             WHERE voucher_code=?"
        );

        mysqli_stmt_bind_param(
            $check,
            "s",
            $voucher
        );

        mysqli_stmt_execute($check);

        $exists =
            mysqli_stmt_get_result($check);

    } while(mysqli_num_rows($exists)>0);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO vouchers
        (
            voucher_code,
            package_id
        )
        VALUES(?,?)"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "si",
        $voucher,
        $package_id
    );

    mysqli_stmt_execute($stmt);

    $message =
        "Voucher generated successfully : "
        .$voucher;
}

/* =====================================
   DASHBOARD STATISTICS
===================================== */

$totalVouchers =
mysqli_fetch_assoc(
mysqli_query(
$conn,
"SELECT COUNT(*) total
FROM vouchers"
))['total'];

$unusedVouchers =
mysqli_fetch_assoc(
mysqli_query(
$conn,
"SELECT COUNT(*) total
FROM vouchers
WHERE status='Unused'"
))['total'];

$usedVouchers =
mysqli_fetch_assoc(
mysqli_query(
$conn,
"SELECT COUNT(*) total
FROM vouchers
WHERE status='Used'"
))['total'];

$expiredVouchers =
mysqli_fetch_assoc(
mysqli_query(
$conn,
"SELECT COUNT(*) total
FROM vouchers
WHERE status='Expired'"
))['total'];

/* =====================================
   SEARCH
===================================== */

$search="";

$sql="

SELECT
vouchers.*,
packages.package_name,
packages.price

FROM vouchers

INNER JOIN packages

ON vouchers.package_id=packages.id

";

if(isset($_GET['search'])){

$search=mysqli_real_escape_string(
$conn,
$_GET['search']
);

$sql.="

WHERE voucher_code
LIKE '%$search%'

";

}

$sql.="

ORDER BY vouchers.id DESC

";

$result=mysqli_query(
$conn,
$sql
);

?>

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width,initial-scale=1">

<title>

Voucher Management

</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet">

</head>

<body class="bg-light">

<div class="container mt-4">

<h2 class="mb-4">

Voucher Management

</h2>

<?php if($message!=""){ ?>

<div class="alert alert-success">

<?php echo htmlspecialchars($message); ?>

</div>

<?php } ?>

<div class="row mb-4">

<div class="col-md-3">

<div class="card text-bg-primary shadow">

<div class="card-body text-center">

<h6>Total</h6>

<h2>

<?php echo $totalVouchers; ?>

</h2>

</div>

</div>

</div>

<div class="col-md-3">

<div class="card text-bg-success shadow">

<div class="card-body text-center">

<h6>Unused</h6>

<h2>

<?php echo $unusedVouchers; ?>

</h2>

</div>

</div>

</div>

<div class="col-md-3">

<div class="card text-bg-warning shadow">

<div class="card-body text-center">

<h6>Used</h6>

<h2>

<?php echo $usedVouchers; ?>

</h2>

</div>

</div>

</div>

<div class="col-md-3">

<div class="card text-bg-danger shadow">

<div class="card-body text-center">

<h6>Expired</h6>

<h2>

<?php echo $expiredVouchers; ?>

</h2>

</div>

</div>

</div>

</div>
<!-- GENERATE NEW VOUCHER -->

<div class="card shadow mb-4">

    <div class="card-header bg-primary text-white">

        <h4 class="mb-0">

            Generate New Voucher

        </h4>

    </div>

    <div class="card-body">

        <form method="POST">

            <div class="row">

                <div class="col-md-8">

                    <label class="form-label">

                        Select Package

                    </label>

                    <select
                        name="package_id"
                        class="form-select"
                        required>

                        <option value="">
                            -- Select Package --
                        </option>

                        <?php

                        $packages=mysqli_query(
                            $conn,
                            "SELECT *
                             FROM packages
                             WHERE status='Active'
                             ORDER BY package_name"
                        );

                        while($p=mysqli_fetch_assoc($packages)){

                        ?>

                        <option
                            value="<?php echo $p['id']; ?>">

                            <?php
                            echo htmlspecialchars($p['package_name']);
                            ?>

                            |

                            <?php
                            echo htmlspecialchars($p['speed']);
                            ?>

                            |

                            KES
                            <?php
                            echo number_format($p['price'],2);
                            ?>

                        </option>

                        <?php } ?>

                    </select>

                </div>

                <div class="col-md-4 d-flex align-items-end">

                    <button
                        type="submit"
                        name="generate"
                        class="btn btn-primary w-100">

                        Generate Voucher

                    </button>

                </div>

            </div>

        </form>

    </div>

</div>

<!-- GENERATED VOUCHERS -->

<div class="card shadow">

    <div class="card-header d-flex justify-content-between align-items-center">

        <h4 class="mb-0">

            Generated Vouchers

        </h4>

        <form method="GET" class="d-flex">

            <input
                type="text"
                name="search"
                class="form-control me-2"
                placeholder="Search voucher..."
                value="<?php echo htmlspecialchars($search); ?>">

            <button
                class="btn btn-primary">

                Search

            </button>

        </form>

    </div>

    <div class="card-body">

        <div class="table-responsive">

            <table class="table table-bordered table-hover align-middle">

                <thead class="table-dark">

                    <tr>

                        <th>ID</th>
                        <th>Voucher Code</th>
                        <th>Package</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Date Created</th>
                        <th width="220">Actions</th>

                    </tr>

                </thead>

                <tbody>

                <?php while($row=mysqli_fetch_assoc($result)){ ?>

                    <tr>

                        <td><?php echo $row['id']; ?></td>

                        <td>

                            <strong class="text-primary">

                                <?php
                                echo htmlspecialchars($row['voucher_code']);
                                ?>

                            </strong>

                        </td>

                        <td>

                            <?php
                            echo htmlspecialchars($row['package_name']);
                            ?>

                        </td>

                        <td>

                            KES
                            <?php
                            echo number_format($row['price'],2);
                            ?>

                        </td>

                        <td>

                            <?php

                            if($row['status']=="Unused"){

                                echo '<span class="badge bg-success">Unused</span>';

                            }elseif($row['status']=="Used"){

                                echo '<span class="badge bg-warning text-dark">Used</span>';

                            }else{

                                echo '<span class="badge bg-danger">Expired</span>';

                            }

                            ?>

                        </td>

                        <td>

                            <?php
                            echo $row['created_at'];
                            ?>

                        </td>

                        <td>

                        <button
class="btn btn-info btn-sm"

onclick="printVoucher(

'<?php echo $row['voucher_code']; ?>',

'<?php echo addslashes($row['package_name']); ?>',

'<?php echo number_format($row['price'],2); ?>',

'<?php echo $row['status']; ?>'

);">

Print

</button>

                            <a
                                href="?delete=<?php echo $row['id']; ?>"
                                class="btn btn-danger btn-sm"
                                onclick="return confirm('Delete this voucher?')">

                                Delete

                            </a>

                        </td>

                    </tr>

                <?php } ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<script>
function printVoucher(code,pkg,price,status){
 const w=window.open('','','width=600,height=600');
 w.document.write(`<html><body style="font-family:Arial;text-align:center"><h2>WiFi Voucher</h2><hr><h3>${code}</h3><p>Package: ${pkg}</p><p>Price: KES ${price}</p><p>Status: ${status}</p></body></html>`);
 w.document.close();w.print();
}
</script>
