<?php
session_start();

// If the user is not logged in, redirect to index.php
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// Include database connection
include '../db.php';

// Status filter (default to 'all')
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Build the query with filters
$where_clauses = [];
$params = [];
$types = "";

if ($status_filter != 'all') {
    $where_clauses[] = "o.order_status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_clauses[] = "(o.order_id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone_number LIKE ?)";
    $search_pattern = "%$search_term%";
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $types .= "ssss";
}

$where_clause = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM orders o 
               LEFT JOIN customers c ON o.customer_id = c.customer_id 
               $where_clause";

$stmt = mysqli_prepare($conn, $count_query);

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$count_result = mysqli_stmt_get_result($stmt);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch orders with pagination
$query = "SELECT o.*, c.first_name, c.last_name, c.email, c.phone_number, 
         CASE 
            WHEN o.order_type = 'sublimation' THEN s.completion_date
            WHEN o.order_type = 'tailoring' THEN t.completion_date
            ELSE NULL
         END AS completion_date
         FROM orders o
         LEFT JOIN customers c ON o.customer_id = c.customer_id
         LEFT JOIN sublimation_orders s ON o.order_id = s.order_id AND o.order_type = 'sublimation'
         LEFT JOIN tailoring_orders t ON o.order_id = t.order_id AND o.order_type = 'tailoring'
         $where_clause
         ORDER BY 
            CASE 
                WHEN o.order_status = 'pending_approval' THEN 1
                WHEN o.order_status = 'approved' THEN 2
                WHEN o.order_status = 'in_process' THEN 3
                WHEN o.order_status = 'ready_for_pickup' THEN 4
                WHEN o.order_status = 'completed' THEN 5
                ELSE 6
            END,
            o.created_at DESC
         LIMIT ?, ?";

$stmt = mysqli_prepare($conn, $query);

if (!empty($params)) {
    $types .= "ii";
    $params[] = $offset;
    $params[] = $records_per_page;
    mysqli_stmt_bind_param($stmt, $types, ...$params);
} else {
    mysqli_stmt_bind_param($stmt, "ii", $offset, $records_per_page);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Get order status counts for the tabs
$status_counts = [
    'all' => $total_records,
    'pending_approval' => 0,
    'approved' => 0,
    'in_process' => 0,
    'ready_for_pickup' => 0,
    'completed' => 0,
    'declined' => 0
];

$count_query = "SELECT order_status, COUNT(*) as count FROM orders GROUP BY order_status";
$count_result = mysqli_query($conn, $count_query);

while ($row = mysqli_fetch_assoc($count_result)) {
    $status_counts[$row['order_status']] = $row['count'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="icon" type="image" href="../image/logo.png">
    <title>JXT - Manage Orders</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- FontAwesome & Custom Fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fc;
            overflow-x: hidden; /* Prevent horizontal scroll */
        }
        
        .order-card {
            transition: all 0.3s ease;
            border-left: 4px solid;
            margin-bottom: 1rem;
        }
        
        .order-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }
        
        .order-pending {
            border-left-color: #f6c23e;
        }
        
        .order-approved {
            border-left-color: #4e73df;
        }
        
        .order-in-process {
            border-left-color: #36b9cc;
        }
        
        .order-ready {
            border-left-color: #1cc88a;
        }
        
        .order-completed {
            border-left-color: #1cc88a;
        }
        
        .order-declined {
            border-left-color: #e74a3b;
        }
        
        .status-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-approved {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-in-process {
            background-color: #e1f5fe;
            color: #0277bd;
        }
        
        .status-ready {
            background-color: #e0f7fa;
            color: #006064;
        }
        
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-declined {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .payment-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .payment-pending {
            background-color: #ffe0b2;
            color: #e65100;
        }
        
        .payment-partial {
            background-color: #e1bee7;
            color: #6a1b9a;
        }
        
        .payment-paid {
            background-color: #c8e6c9;
            color: #2e7d32;
        }
        
        .nav-pills .nav-link {
            color: #5a5c69;
            font-weight: 600;
            padding: 0.75rem 1rem;
            border-radius: 0.35rem;
        }
        
        .nav-pills .nav-link.active {
            background-color: #4e73df;
            color: #fff;
        }
        
        .nav-pills .nav-link .badge {
            margin-left: 0.5rem;
        }
        
        .table th {
            font-weight: 600;
            background-color: #f8f9fc;
        }
        
        .dropdown-item.active, .dropdown-item:active {
            background-color: #4e73df;
        }
        
        .action-icon {
            cursor: pointer;
            padding: 0.4rem;
            border-radius: 0.25rem;
            color: #5a5c69;
            transition: all 0.2s;
        }
        
        .action-icon:hover {
            background-color: #eaecf4;
        }
        
        .search-box {
            max-width: 300px;
        }
        
        /* Add these responsive styles to your existing styles */
        /* Responsive table improvements */
        .table-responsive {
            overflow-x: visible; /* Change from auto to visible */
        }
        
        /* Responsive columns that adjust based on screen size */
        .table th, .table td {
            white-space: normal;
            vertical-align: middle;
        }
        
        /* Column specific widths */
        .col-id { width: 8%; min-width: 80px; }
        .col-type { width: 10%; min-width: 90px; }
        .col-customer { width: 15%; min-width: 130px; }
        .col-total { width: 8%; min-width: 80px; }
        .col-payment { width: 10%; min-width: 90px; }
        .col-status { width: 12%; min-width: 110px; }
        .col-created { width: 10%; min-width: 90px; }
        .col-completion { width: 12%; min-width: 110px; }
        .col-actions { width: 10%; min-width: 90px; }
        
        /* Text truncation for long content */
        .text-truncate-custom {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            display: block;
        }
        
        /* Responsive filters section */
        @media (max-width: 768px) {
            .filters-row > div {
                margin-bottom: 1rem;
            }
            
            .table-responsive {
                font-size: 0.9rem;
            }
        }
        
        /* Pill tabs scrolling container */
        .nav-pills-wrapper {
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 5px; /* Space for scrollbar */
            margin-bottom: 1rem;
            -ms-overflow-style: none; /* Hide scrollbar in IE and Edge */
            scrollbar-width: none; /* Hide scrollbar in Firefox */
        }
        
        .nav-pills-wrapper::-webkit-scrollbar {
            display: none; /* Hide scrollbar in Chrome/Safari */
        }
        
        .nav-pills-wrapper .nav-pills {
            display: inline-flex;
            padding-bottom: 5px;
        }
        
        /* Additional responsive card styles */
        .card {
            overflow: hidden;
        }
        
        /* Responsive buttons */
        .btn-responsive {
            padding: 0.375rem 0.5rem;
            font-size: 0.9rem;
        }
        
        @media (max-width: 576px) {
            .dropdown-menu {
                position: fixed !important;
                top: auto !important;
                right: 0 !important;
                left: 0 !important;
                width: 100%;
                transform: none !important;
                bottom: 0;
                margin: 0;
                border-radius: 1rem 1rem 0 0;
                max-height: 70vh;
                overflow-y: auto;
                padding-bottom: 1rem;
                box-shadow: 0 -4px 10px rgba(0,0,0,0.1);
            }
            
            .dropdown-item {
                padding: 0.75rem 1.5rem;
            }
        }
    </style>
</head>

<body id="page-top">
    <div id="wrapper">
        <?php include 'sidebar.php'; ?>

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light topbar mb-4 static-top shadow">
                    <ul class="navbar-nav ml-auto">
                        <?php include 'notification.php'; ?>

                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small">
                                    <?php echo htmlspecialchars($_SESSION['first_name']); ?>
                                    <i class='fas fa-user-circle' style="font-size:20px; margin-left: 10px;"></i>
                                </span>
                            </a>
                        </li>
                    </ul>
                </nav>

                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">
                            Manage Orders
                        </h1>
                    </div>

                    <!-- Search and Filter -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Filters</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3 align-items-center filters-row">
                                <div class="col-lg-4 col-md-6">
                                    <div class="input-group search-box w-100">
                                        <input type="text" class="form-control" name="search" placeholder="Search orders..." value="<?php echo htmlspecialchars($search_term); ?>">
                                        <button class="btn btn-outline-secondary" type="submit">
                                            <i class="fas fa-search fa-sm"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="col-lg-4 col-md-6">
                                    <div class="input-group w-100">
                                        <label class="input-group-text" for="status">Status</label>
                                        <select class="form-select" name="status" id="status" onchange="this.form.submit()">
                                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                            <option value="pending_approval" <?php echo $status_filter == 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="in_process" <?php echo $status_filter == 'in_process' ? 'selected' : ''; ?>>In Process</option>
                                            <option value="ready_for_pickup" <?php echo $status_filter == 'ready_for_pickup' ? 'selected' : ''; ?>>Ready</option>
                                            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="declined" <?php echo $status_filter == 'declined' ? 'selected' : ''; ?>>Declined</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <?php if (!empty($search_term) || $status_filter != 'all'): ?>
                                <div class="col-auto">
                                    <a href="manage_orders.php" class="btn btn-secondary w-100">Clear Filters</a>
                                </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <!-- Order status tabs -->
                    <div class="nav-pills-wrapper">
                        <ul class="nav nav-pills mb-4">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $status_filter == 'all' ? 'active' : ''; ?>" href="?status=all">
                                    All <span class="badge bg-secondary"><?php echo $status_counts['all']; ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $status_filter == 'pending_approval' ? 'active' : ''; ?>" href="?status=pending_approval">
                                    Pending <span class="badge bg-warning text-dark"><?php echo $status_counts['pending_approval']; ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $status_filter == 'approved' ? 'active' : ''; ?>" href="?status=approved">
                                    Approved <span class="badge bg-primary"><?php echo $status_counts['approved']; ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $status_filter == 'in_process' ? 'active' : ''; ?>" href="?status=in_process">
                                    In Process <span class="badge bg-info"><?php echo $status_counts['in_process']; ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $status_filter == 'ready_for_pickup' ? 'active' : ''; ?>" href="?status=ready_for_pickup">
                                    Ready <span class="badge bg-success"><?php echo $status_counts['ready_for_pickup']; ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $status_filter == 'completed' ? 'active' : ''; ?>" href="?status=completed">
                                    Completed <span class="badge bg-secondary"><?php echo $status_counts['completed']; ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $status_filter == 'declined' ? 'active' : ''; ?>" href="?status=declined">
                                    Declined <span class="badge bg-danger"><?php echo $status_counts['declined']; ?></span>
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Orders Table -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <?php echo $status_filter == 'all' ? 'All Orders' : ucwords(str_replace('_', ' ', $status_filter)) . ' Orders'; ?>
                            </h6>
                        </div>
                        <div class="card-body p-0"> <!-- Removed padding to maximize table space -->
                            <?php if (mysqli_num_rows($result) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th class="col-id">ID</th>
                                                <th class="col-type d-none d-md-table-cell">Type</th>
                                                <th class="col-customer">Customer</th>
                                                <th class="col-total d-none d-lg-table-cell">Total</th>
                                                <th class="col-payment">Payment</th>
                                                <th class="col-status">Status</th>
                                                <th class="col-created d-none d-md-table-cell">Created</th>
                                                <th class="col-completion d-none d-xl-table-cell">Est. Completion</th>
                                                <th class="col-actions">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($order = mysqli_fetch_assoc($result)): ?>
                                                <tr class="order-row">
                                                    <td>
                                                        <a href="view_order.php?id=<?php echo htmlspecialchars($order['order_id']); ?>" class="font-weight-bold">
                                                            #<?php echo htmlspecialchars($order['order_id']); ?>
                                                        </a>
                                                    </td>
                                                    <td class="d-none d-md-table-cell">
                                                        <?php echo ucfirst(htmlspecialchars($order['order_type'])); ?>
                                                    </td>
                                                    <td>
                                                        <div class="text-truncate-custom"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></div>
                                                        <small class="text-muted d-block text-truncate-custom"><?php echo htmlspecialchars($order['phone_number']); ?></small>
                                                    </td>
                                                    <td class="d-none d-lg-table-cell">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                                    <td>
                                                        <?php
                                                        $payment_class = '';
                                                        switch ($order['payment_status']) {
                                                            case 'pending':
                                                                $payment_class = 'payment-pending';
                                                                break;
                                                            case 'partial':
                                                            case 'downpayment_paid':
                                                                $payment_class = 'payment-partial';
                                                                break;
                                                            case 'paid':
                                                                $payment_class = 'payment-paid';
                                                                break;
                                                        }
                                                        $payment_status = str_replace('_', ' ', ucwords($order['payment_status']));
                                                        // Shorten payment status text on small screens
                                                        if (in_array($order['payment_status'], ['downpayment_paid'])) {
                                                            $payment_status = 'Partial';
                                                        }
                                                        ?>
                                                        <span class="payment-badge <?php echo $payment_class; ?>">
                                                            <?php echo $payment_status; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status_class = '';
                                                        switch ($order['order_status']) {
                                                            case 'pending_approval':
                                                                $status_class = 'status-pending';
                                                                $status_text = 'Pending';
                                                                break;
                                                            case 'approved':
                                                                $status_class = 'status-approved';
                                                                $status_text = 'Approved';
                                                                break;
                                                            case 'in_process':
                                                                $status_class = 'status-in-process';
                                                                $status_text = 'In Process';
                                                                break;
                                                            case 'ready_for_pickup':
                                                                $status_class = 'status-ready';
                                                                $status_text = 'Ready';
                                                                break;
                                                            case 'completed':
                                                                $status_class = 'status-completed';
                                                                $status_text = 'Completed';
                                                                break;
                                                            case 'declined':
                                                                $status_class = 'status-declined';
                                                                $status_text = 'Declined';
                                                                break;
                                                            default:
                                                                $status_text = str_replace('_', ' ', ucwords($order['order_status']));
                                                        }
                                                        ?>
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo $status_text; ?>
                                                        </span>
                                                    </td>
                                                    <td class="d-none d-md-table-cell">
                                                        <?php echo date('M d', strtotime($order['created_at'])); ?>
                                                    </td>
                                                    <td class="d-none d-xl-table-cell">
                                                        <?php 
                                                        if ($order['completion_date']) {
                                                            echo date('M d', strtotime($order['completion_date']));
                                                        } else {
                                                            echo 'N/A';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <div class="dropdown">
                                                            <button class="btn btn-sm btn-light dropdown-toggle btn-responsive" type="button" id="actionDropdown<?php echo $order['order_id']; ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                                                Actions
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="actionDropdown<?php echo $order['order_id']; ?>">
                                                                <li>
                                                                    <a class="dropdown-item" href="view_order.php?id=<?php echo $order['order_id']; ?>">
                                                                        <i class="fas fa-eye fa-sm fa-fw mr-2 text-gray-400"></i> View Details
                                                                    </a>
                                                                </li>
                                                                <?php if ($order['order_status'] == 'pending_approval'): ?>
                                                                <li>
                                                                    <a class="dropdown-item text-success approve-btn" href="#" data-id="<?php echo $order['order_id']; ?>">
                                                                        <i class="fas fa-check fa-sm fa-fw mr-2 text-success"></i> Approve
                                                                    </a>
                                                                </li>
                                                                <li>
                                                                    <a class="dropdown-item text-danger decline-btn" href="#" data-id="<?php echo $order['order_id']; ?>">
                                                                        <i class="fas fa-times fa-sm fa-fw mr-2 text-danger"></i> Decline
                                                                    </a>
                                                                </li>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($order['order_status'] == 'approved'): ?>
                                                                <li>
                                                                    <a class="dropdown-item text-primary process-btn" href="#" data-id="<?php echo $order['order_id']; ?>">
                                                                        <i class="fas fa-cogs fa-sm fa-fw mr-2 text-primary"></i> Mark In Process
                                                                    </a>
                                                                </li>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($order['order_status'] == 'in_process'): ?>
                                                                <li>
                                                                    <a class="dropdown-item text-info ready-btn" href="#" data-id="<?php echo $order['order_id']; ?>">
                                                                        <i class="fas fa-box fa-sm fa-fw mr-2 text-info"></i> Mark Ready for Pickup
                                                                    </a>
                                                                </li>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($order['order_status'] == 'ready_for_pickup'): ?>
                                                                <li>
                                                                    <a class="dropdown-item text-success complete-btn" href="#" data-id="<?php echo $order['order_id']; ?>">
                                                                        <i class="fas fa-check-double fa-sm fa-fw mr-2 text-success"></i> Mark Completed
                                                                    </a>
                                                                </li>
                                                                <?php endif; ?>
                                                                
                                                                <li>
                                                                    <a class="dropdown-item edit-status-btn" href="#" data-id="<?php echo $order['order_id']; ?>" data-status="<?php echo $order['order_status']; ?>">
                                                                        <i class="fas fa-edit fa-sm fa-fw mr-2 text-gray-400"></i> Edit Status
                                                                    </a>
                                                                </li>
                                                                
                                                                <?php if ($order['payment_status'] != 'paid'): ?>
                                                                <li>
                                                                    <a class="dropdown-item text-success mark-paid-btn" href="#" data-id="<?php echo $order['order_id']; ?>">
                                                                        <i class="fas fa-dollar-sign fa-sm fa-fw mr-2 text-success"></i> Mark as Paid
                                                                    </a>
                                                                </li>
                                                                <?php endif; ?>
                                                                
                                                                <li><hr class="dropdown-divider"></li>
                                                                <li>
                                                                    <a class="dropdown-item text-primary add-note-btn" href="#" data-id="<?php echo $order['order_id']; ?>">
                                                                        <i class="fas fa-sticky-note fa-sm fa-fw mr-2 text-primary"></i> Add Note
                                                                    </a>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="d-flex justify-content-center mt-4">
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination">
                                            <?php if ($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo ($page - 1); ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>" aria-label="Previous">
                                                        <span aria-hidden="true">&laquo; Previous</span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php
                                            // Calculate range of pages to show
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            
                                            // Show first page if not in range
                                            if ($start_page > 1) {
                                                echo '<li class="page-item"><a class="page-link" href="?page=1&status=' . $status_filter . '&search=' . urlencode($search_term) . '">1</a></li>';
                                                if ($start_page > 2) {
                                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                                }
                                            }
                                            
                                            // Show page numbers
                                            for ($i = $start_page; $i <= $end_page; $i++) {
                                                echo '<li class="page-item ' . ($i == $page ? 'active' : '') . '">';
                                                echo '<a class="page-link" href="?page=' . $i . '&status=' . $status_filter . '&search=' . urlencode($search_term) . '">' . $i . '</a>';
                                                echo '</li>';
                                            }
                                            
                                            // Show last page if not in range
                                            if ($end_page < $total_pages) {
                                                if ($end_page < $total_pages - 1) {
                                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                                }
                                                echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . '&status=' . $status_filter . '&search=' . urlencode($search_term) . '">' . $total_pages . '</a></li>';
                                            }
                                            ?>
                                            
                                            <?php if ($page < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo ($page + 1); ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>" aria-label="Next">
                                                        <span aria-hidden="true">Next &raquo;</span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                                <?php endif; ?>
                                
                            <?php else: ?>
                                <div class="text-center p-4">
                                    <img src="../image/no-data.svg" alt="No Orders" style="max-width: 200px;" class="mb-3">
                                    <h5>No Orders Found</h5>
                                    <p class="text-muted">There are no orders matching your criteria.</p>
                                    <?php if (!empty($search_term) || $status_filter != 'all'): ?>
                                        <a href="manage_orders.php" class="btn btn-primary mt-2">Clear Filters</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Order Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="statusUpdateForm">
                        <input type="hidden" name="order_id" id="status_order_id">
                        <div class="mb-3">
                            <label for="order_status" class="form-label">Status</label>
                            <select class="form-select" id="order_status" name="order_status" required>
                                <option value="pending_approval">Pending Approval</option>
                                <option value="approved">Approved</option>
                                <option value="in_process">In Process</option>
                                <option value="ready_for_pickup">Ready for Pickup</option>
                                <option value="completed">Completed</option>
                                <option value="declined">Declined</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="status_notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="status_notes" name="notes" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="updateStatusBtn">Update Status</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Decline Order Modal -->
    <div class="modal fade" id="declineModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Decline Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="declineForm">
                        <input type="hidden" name="order_id" id="decline_order_id">
                        <div class="mb-3">
                            <label for="decline_reason" class="form-label">Reason for Declining</label>
                            <textarea class="form-control" id="decline_reason" name="reason" rows="3" required></textarea>
                            <div class="form-text">This reason will be visible to the customer.</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeclineBtn">Decline Order</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Note Modal -->
    <div class="modal fade" id="addNoteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Note to Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addNoteForm">
                        <input type="hidden" name="order_id" id="note_order_id">
                        <div class="mb-3">
                            <label for="note_content" class="form-label">Note</label>
                            <textarea class="form-control" id="note_content" name="note" rows="4" required></textarea>
                            <div class="form-text">This note will only be visible to staff members.</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="addNoteBtn">Add Note</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap & jQuery Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        $(document).ready(function() {
            // Edit status button
            $('.edit-status-btn').click(function(e) {
                e.preventDefault();
                const orderId = $(this).data('id');
                const currentStatus = $(this).data('status');
                
                $('#status_order_id').val(orderId);
                $('#order_status').val(currentStatus);
                
                const statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
                statusModal.show();
            });
            
            // Update status action
            $('#updateStatusBtn').click(function() {
                const orderId = $('#status_order_id').val();
                const status = $('#order_status').val();
                const notes = $('#status_notes').val();
                
                // Make AJAX call to update status
                $.ajax({
                    url: 'update_order_status.php',
                    type: 'POST',
                    data: {
                        order_id: orderId,
                        status: status,
                        notes: notes
                    },
                    success: function(response) {
                        if (response === 'success') {
                            // Reload page to show updated status
                            location.reload();
                        } else {
                            alert('Error updating order status: ' + response);
                        }
                    },
                    error: function() {
                        alert('An error occurred while updating the order status.');
                    }
                });
            });
            
            // Approve button
            $('.approve-btn').click(function(e) {
                e.preventDefault();
                const orderId = $(this).data('id');
                
                if (confirm('Are you sure you want to approve this order?')) {
                    $.ajax({
                        url: 'update_order_status.php',
                        type: 'POST',
                        data: {
                            order_id: orderId,
                            status: 'approved',
                            notes: 'Order approved by staff.'
                        },
                        success: function(response) {
                            if (response === 'success') {
                                location.reload();
                            } else {
                                alert('Error approving order: ' + response);
                            }
                        }
                    });
                }
            });
            
            // Decline button
            $('.decline-btn').click(function(e) {
                e.preventDefault();
                const orderId = $(this).data('id');
                
                $('#decline_order_id').val(orderId);
                
                const declineModal = new bootstrap.Modal(document.getElementById('declineModal'));
                declineModal.show();
            });
            
            // Confirm decline action
            $('#confirmDeclineBtn').click(function() {
                const orderId = $('#decline_order_id').val();
                const reason = $('#decline_reason').val();
                
                if (!reason.trim()) {
                    alert('Please provide a reason for declining the order.');
                    return;
                }
                
                $.ajax({
                    url: 'decline_order.php',
                    type: 'POST',
                    data: {
                        order_id: orderId,
                        reason: reason
                    },
                    success: function(response) {
                        if (response.includes('declined')) {
                            location.reload();
                        } else {
                            alert('Error declining order: ' + response);
                        }
                    }
                });
            });
            
            // Process button
            $('.process-btn').click(function(e) {
                e.preventDefault();
                const orderId = $(this).data('id');
                
                if (confirm('Mark this order as In Process?')) {
                    $.ajax({
                        url: 'update_order_status.php',
                        type: 'POST',
                        data: {
                            order_id: orderId,
                            status: 'in_process',
                            notes: 'Order moved to production.'
                        },
                        success: function(response) {
                            if (response === 'success') {
                                location.reload();
                            } else {
                                alert('Error updating order: ' + response);
                            }
                        }
                    });
                }
            });
            
            // Ready button
            $('.ready-btn').click(function(e) {
                e.preventDefault();
                const orderId = $(this).data('id');
                
                if (confirm('Mark this order as Ready for Pickup?')) {
                    $.ajax({
                        url: 'update_order_status.php',
                        type: 'POST',
                        data: {
                            order_id: orderId,
                            status: 'ready_for_pickup',
                            notes: 'Order is ready for pickup.'
                        },
                        success: function(response) {
                            if (response === 'success') {
                                location.reload();
                            } else {
                                alert('Error updating order: ' + response);
                            }
                        }
                    });
                }
            });
            
            // Complete button
            $('.complete-btn').click(function(e) {
                e.preventDefault();
                const orderId = $(this).data('id');
                
                if (confirm('Mark this order as Completed?')) {
                    $.ajax({
                        url: 'update_order_status.php',
                        type: 'POST',
                        data: {
                            order_id: orderId,
                            status: 'completed',
                            notes: 'Order has been picked up/delivered and is now complete.'
                        },
                        success: function(response) {
                            if (response === 'success') {
                                location.reload();
                            } else {
                                alert('Error updating order: ' + response);
                            }
                        }
                    });
                }
            });
            
            // Mark as paid button
            $('.mark-paid-btn').click(function(e) {
                e.preventDefault();
                const orderId = $(this).data('id');
                
                if (confirm('Mark this order as fully paid?')) {
                    $.ajax({
                        url: 'update_payment_status.php',
                        type: 'POST',
                        data: {
                            order_id: orderId,
                            status: 'paid',
                            notes: 'Payment has been completed.'
                        },
                        success: function(response) {
                            if (response === 'success') {
                                location.reload();
                            } else {
                                alert('Error updating payment: ' + response);
                            }
                        }
                    });
                }
            });
            
            // Add note button
            $('.add-note-btn').click(function(e) {
                e.preventDefault();
                const orderId = $(this).data('id');
                
                $('#note_order_id').val(orderId);
                
                const noteModal = new bootstrap.Modal(document.getElementById('addNoteModal'));
                noteModal.show();
            });
            
            // Add note action
            $('#addNoteBtn').click(function() {
                const orderId = $('#note_order_id').val();
                const note = $('#note_content').val();
                
                if (!note.trim()) {
                    alert('Please enter a note.');
                    return;
                }
                
                $.ajax({
                    url: 'add_order_note.php',
                    type: 'POST',
                    data: {
                        order_id: orderId,
                        note: note
                    },
                    success: function(response) {
                        if (response === 'success') {
                            alert('Note added successfully.');
                            const noteModal = bootstrap.Modal.getInstance(document.getElementById('addNoteModal'));
                            noteModal.hide();
                        } else {
                            alert('Error adding note: ' + response);
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>