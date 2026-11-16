<?php
session_start();

if(isset($_SESSION['admin'])){
    header("Location: ../admin/dashboard.php");
    exit();
}

include('../config/database.php');

$error = "";

if(isset($_POST['login'])){

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, username, password
         FROM admins
         WHERE username = ?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "s",
        $username
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if(mysqli_num_rows($result) > 0){

        $row = mysqli_fetch_assoc($result);

        if(password_verify(
            $password,
            $row['password']
        )){

            session_regenerate_id(true);

            $_SESSION['admin'] = $row['username'];
            $_SESSION['admin_id'] = $row['id'];

            header("Location: ../admin/dashboard.php");
            exit();

        }else{

            $error = "Invalid password.";

        }

    }else{

        $error = "Admin account not found.";

    }

    mysqli_stmt_close($stmt);
}
?>

<!DOCTYPE html>
<html>
<head>

    <title>WiFi Billing System</title>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

</head>

<body class="bg-light">

<div class="container">

    <div class="row justify-content-center align-items-center"
         style="min-height:100vh;">

        <div class="col-md-5">

            <div class="card shadow-lg border-0">

                <div class="card-header bg-dark text-white text-center">

                    <h2>
                        <i class="bi bi-router"></i>
                        WiFi Billing System
                    </h2>

                    <p class="mb-0">
                        Administrator Login
                    </p>

                </div>

                <div class="card-body p-4">

                    <?php if(!empty($error)){ ?>

                    <div class="alert alert-danger">

                        <?php echo htmlspecialchars($error); ?>

                    </div>

                    <?php } ?>

                    <form method="POST"
                          autocomplete="off">

                        <div class="mb-3">

                            <label class="form-label">
                                Username
                            </label>

                            <input
                                type="text"
                                name="username"
                                class="form-control"
                                required>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                name="password"
                                id="password"
                                class="form-control"
                                required>

                        </div>

                        <div class="form-check mb-3">

                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="showPassword"
                                onclick="togglePassword()">

                            <label
                                class="form-check-label"
                                for="showPassword">

                                Show Password

                            </label>

                        </div>

                        <button
                            type="submit"
                            name="login"
                            class="btn btn-primary w-100">

                            <i class="bi bi-box-arrow-in-right"></i>
                            Login

                        </button>

                    </form>

                </div>

                <div class="card-footer text-center">

                    <small>
                        WiFi Billing Management System
                    </small>

                </div>

            </div>

        </div>

    </div>

</div>

<script>

function togglePassword(){

    let passwordField =
        document.getElementById("password");

    if(passwordField.type === "password"){

        passwordField.type = "text";

    }else{

        passwordField.type = "password";

    }
}

</script>

</body>
</html>