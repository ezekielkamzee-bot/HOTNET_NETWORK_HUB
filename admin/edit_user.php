<?php
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/database.php');
include('menu.php');

$message = "";

if (!isset($_GET['id'])) {
    header("Location: users.php");
    exit();
}

$id = (int) $_GET['id'];

$result = mysqli_query($conn, "SELECT * FROM users WHERE id=$id");

if (mysqli_num_rows($result) == 0) {
    die("User not found.");
}

$user = mysqli_fetch_assoc($result);

if (isset($_POST['update'])) {

    $fullname = trim($_POST['fullname']);
    $phone = trim($_POST['phone']);
    $username = trim($_POST['username']);
    $status = $_POST['status'];

    mysqli_query(
        $conn,
        "UPDATE users
         SET fullname='$fullname',
             phone='$phone',
             username='$username',
             status='$status'
         WHERE id=$id"
    );

    $message = "User updated successfully.";

    $result = mysqli_query($conn, "SELECT * FROM users WHERE id=$id");
    $user = mysqli_fetch_assoc($result);
}
?>

<!DOCTYPE html>
<html>

<head>

    <title>Edit User</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body class="bg-light">

<div class="container mt-4">

    <h2>Edit User</h2>

    <?php if ($message != "") { ?>
        <div class="alert alert-success">
            <?php echo $message; ?>
        </div>
    <?php } ?>

    <div class="card shadow">

        <div class="card-body">

            <form method="POST">

                <div class="mb-3">
                    <label>Full Name</label>
                    <input
                        type="text"
                        name="fullname"
                        class="form-control"
                        value="<?php echo htmlspecialchars($user['fullname']); ?>"
                        required>
                </div>

                <div class="mb-3">
                    <label>Phone Number</label>
                    <input
                        type="text"
                        name="phone"
                        class="form-control"
                        value="<?php echo htmlspecialchars($user['phone']); ?>"
                        required>
                </div>

                <div class="mb-3">
                    <label>Username</label>
                    <input
                        type="text"
                        name="username"
                        class="form-control"
                        value="<?php echo htmlspecialchars($user['username']); ?>"
                        required>
                </div>

                <div class="mb-3">
                    <label>Status</label>

                    <select name="status" class="form-select">

                        <option value="Active"
                            <?php if ($user['status'] == 'Active') echo 'selected'; ?>>
                            Active
                        </option>

                        <option value="Inactive"
                            <?php if ($user['status'] == 'Inactive') echo 'selected'; ?>>
                            Inactive
                        </option>

                    </select>

                </div>

                <button type="submit" name="update" class="btn btn-success">
                    Update User
                </button>

                <a href="users.php" class="btn btn-secondary">
                    Back
                </a>

            </form>

        </div>

    </div>

</div>

</body>
</html>