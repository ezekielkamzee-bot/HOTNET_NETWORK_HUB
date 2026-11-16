<?php

if(!isset($_SESSION['admin'])){
    header("Location: ../auth/login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);

?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow">

    <div class="container-fluid">

        <a class="navbar-brand fw-bold" href="dashboard.php">
            📶 WiFi Billing
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarNav">

            <span class="navbar-toggler-icon"></span>

        </button>

        <div class="collapse navbar-collapse" id="navbarNav">

            <ul class="navbar-nav me-auto">

                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage=='dashboard.php') ? 'active' : ''; ?>"
                       href="dashboard.php">
                        Dashboard
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage=='users.php') ? 'active' : ''; ?>"
                       href="users.php">
                        Users
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage=='packages.php') ? 'active' : ''; ?>"
                       href="packages.php">
                        Packages
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage=='vouchers.php') ? 'active' : ''; ?>"
                       href="vouchers.php">
                        Vouchers
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage=='payments.php') ? 'active' : ''; ?>"
                       href="payments.php">
                        Payments
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage=='sessions.php') ? 'active' : ''; ?>"
                       href="sessions.php">
                        Sessions
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage=='reports.php') ? 'active' : ''; ?>"
                       href="reports.php">
                        Reports
                    </a>
                </li>

            </ul>

            <span class="navbar-text text-white me-3">

                Logged in as:
                <strong>
                    <?php echo htmlspecialchars($_SESSION['admin']); ?>
                </strong>

            </span>

            <a href="../auth/logout.php"
               class="btn btn-danger btn-sm">

                Logout

            </a>

        </div>

    </div>

</nav>