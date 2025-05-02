<?php
// filepath: c:\xampp\htdocs\jx_tailoring\manager\sidebar.php

// Determine which page is currently active
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!-- Sidebar -->
<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="dashboard.php">
        <div class="sidebar-brand-icon">
            <i class="fas fa-scissors"></i>
        </div>
        <div class="sidebar-brand-text mx-3">JX Tailoring</div>
    </a>

    <!-- Divider -->
    <hr class="sidebar-divider my-0">

    <!-- Nav Item - Dashboard -->
    <li class="nav-item <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>">
        <a class="nav-link" href="dashboard.php">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        Order Management
    </div>

    <!-- Nav Item - Orders -->
    <li class="nav-item <?php echo (in_array($currentPage, ['orders.php', 'view_order.php'])) ? 'active' : ''; ?>">
        <a class="nav-link" href="orders.php">
            <i class="fas fa-fw fa-clipboard-list"></i>
            <span>New Orders</span>
        </a>
    </li>

    <!-- Nav Item - Manage Orders -->
    <li class="nav-item <?php echo ($currentPage == 'manage_orders.php') ? 'active' : ''; ?>">
        <a class="nav-link" href="manage_orders.php">
            <i class="fas fa-fw fa-tasks"></i>
            <span>Manage Orders</span>
        </a>
    </li>

    <!-- Nav Item - Payments -->
    <li class="nav-item <?php echo ($currentPage == 'manage_payments.php') ? 'active' : ''; ?>">
        <a class="nav-link" href="manage_payments.php">
            <i class="fas fa-fw fa-cash-register"></i>
            <span>Manage Payments</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        Management
    </div>

    <!-- Nav Item - Staff -->
    <li class="nav-item <?php echo ($currentPage == 'staff.php') ? 'active' : ''; ?>">
        <a class="nav-link" href="staff.php">
            <i class="fas fa-fw fa-user-tie"></i>
            <span>Staff Management</span>
        </a>
    </li>

    <!-- Nav Item - Reports Collapse Menu -->
    <li class="nav-item <?php echo (in_array($currentPage, ['sales_report.php', 'order_report.php'])) ? 'active' : ''; ?>">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseReports" aria-expanded="true" aria-controls="collapseReports">
            <i class="fas fa-fw fa-chart-bar"></i>
            <span>Reports</span>
        </a>
        <div id="collapseReports" class="collapse" aria-labelledby="headingReports" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <h6 class="collapse-header">Report Types:</h6>
                <a class="collapse-item" href="sales_report.php">Sales Report</a>
                <a class="collapse-item" href="order_report.php">Order Report</a>
            </div>
        </div>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        Settings
    </div>

    <!-- Divider -->
    <hr class="sidebar-divider d-none d-md-block">

    <!-- Sidebar Toggler (Sidebar) -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>
</ul>
<!-- End of Sidebar -->
