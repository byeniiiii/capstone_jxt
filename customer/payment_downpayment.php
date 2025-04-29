<?php
session_start();
include '../db.php';

// Check if user is logged in
if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit();
}

$customer_id = $_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'] ?? '';
$order_id = $_GET['order_id'] ?? null;
$error_message = '';
$success_message = '';
$payment_id = 0; // Will store the ID of the created payment

// Fetch all staff members for the dropdown
$staff_query = "SELECT user_id, CONCAT(first_name, ' ', last_name) AS staff_name 
                FROM users 
                WHERE role IN ('Admin', 'Manager', 'Staff')
                ORDER BY first_name, last_name";
$staff_result = $conn->query($staff_query);
$staff_members = [];
while ($staff = $staff_result->fetch_assoc()) {
    $staff_members[] = $staff;
}

// Validate order ID
if (!$order_id) {
    $error_message = "Invalid order ID. Please return to the orders page and try again.";
} else {
    // Fetch order info
    $stmt = $conn->prepare("SELECT o.*, 
                                  CONCAT(c.first_name, ' ', c.last_name) AS customer_name, 
                                  c.email, c.phone_number 
                           FROM orders o
                           JOIN customers c ON o.customer_id = c.customer_id
                           WHERE o.order_id = ? AND o.customer_id = ?");
    $stmt->bind_param("si", $order_id, $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $error_message = "Order not found or you don't have permission to access it.";
    } else {
        $order = $result->fetch_assoc();
        
        // Calculate downpayment as 50% of total amount
        $order['downpayment_amount'] = $order['total_amount'] / 2;
        
        // Check if order is eligible for downpayment
        if ($order['order_status'] != 'approved' || $order['payment_status'] != 'pending') {
            $error_message = "This order is not eligible for downpayment at this time.";
        }
    }
}

// Handle form submission - FIXED to prevent status updates when errors occur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error_message)) {
    $payment_method = $_POST['payment_method'];
    $reference = $_POST['transaction_reference'] ?? '';
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $required_downpayment = $order['downpayment_amount'];
    $received_by = $_POST['received_by'] ?? NULL;
    $screenshot_path = '';
    $transaction_completed = false; // Track if transaction completes successfully
    
    // Validate form data - FIXED VALIDATION LOGIC
    if ($amount_paid < $required_downpayment) {
        $error_message = "Amount paid must be at least the required downpayment amount (₱" . number_format($required_downpayment, 2) . ").";
    } elseif ($payment_method == 'gcash' && empty($reference)) {
        $error_message = "Transaction reference is required for GCash payments.";
    } elseif ($payment_method == 'gcash' && (!isset($_FILES['gcash_screenshot']) || $_FILES['gcash_screenshot']['error'] != 0)) {
        // Only validate screenshot upload for GCash payments - fixed parentheses issue
        $error_message = "Please upload a screenshot of your GCash transaction.";
    } elseif (empty($received_by)) {
        $error_message = "Please select who received the payment.";
    } else {
        // Process the payment
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Handle file upload for GCash
            if ($payment_method == 'gcash' && isset($_FILES['gcash_screenshot']) && $_FILES['gcash_screenshot']['error'] == 0) {
                $upload_dir = '../uploads/payment_screenshots/';
                
                // Create directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = pathinfo($_FILES['gcash_screenshot']['name'], PATHINFO_EXTENSION);
                $file_name = 'payment_' . $order_id . '_' . time() . '.' . $file_extension;
                $target_file = $upload_dir . $file_name;
                
                // Move uploaded file
                if (move_uploaded_file($_FILES['gcash_screenshot']['tmp_name'], $target_file)) {
                    $screenshot_path = $target_file;
                } else {
                    throw new Exception("Failed to upload the screenshot.");
                }
            }
            
            // Insert payment record - FIXED VERSION
            $stmt = $conn->prepare("INSERT INTO payments (order_id, amount, payment_type, payment_method, transaction_reference, received_by) VALUES (?, ?, 'downpayment', ?, ?, ?)");
            $stmt->bind_param("sdssi", $order_id, $amount_paid, $payment_method, $reference, $received_by);
            $stmt->execute();
            
            // Get the payment ID for the receipt
            $payment_id = $conn->insert_id;
            
            // Update order payment status - Only if the entire payment process was successful
            $status = ($amount_paid >= $order['total_amount']) ? 'fully_paid' : 'downpayment_paid';
            $stmt = $conn->prepare("UPDATE orders SET payment_status = ? WHERE order_id = ?");
            $stmt->bind_param("ss", $status, $order_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Mark transaction as complete
            $transaction_completed = true;
            
            // Set success message
            $success_message = "Your payment has been successfully submitted! A notification will be sent once it's verified.";
            
            // Create notification for staff
            $notification_msg = "New payment submitted for Order #$order_id";
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
            $link = "order_details.php?id=" . $order_id;
            $stmt->bind_param("iss", $received_by, $notification_msg, $link);
            $stmt->execute();
            
        } catch (Exception $e) {
            // Rollback in case of error
            $conn->rollback();
            $error_message = "Error processing payment: " . $e->getMessage();
            
            // If there was an error, ensure payment_id is reset so no receipt is shown
            $payment_id = 0;
        }
    }
    
    // If there was an error, make sure we redirect back to the form without marking payment as complete
    if (!empty($error_message) && $payment_id > 0) {
        // If a payment record was created but an error occurred later,
        // we need to delete that payment record to keep data consistent
        $stmt = $conn->prepare("DELETE FROM payments WHERE payment_id = ?");
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $payment_id = 0;
    }
}

// If we have a payment ID, fetch the payment details for the receipt
$payment_details = null;
if ($payment_id > 0) {
    $stmt = $conn->prepare("SELECT p.*, 
                           CONCAT(u.first_name, ' ', u.last_name) AS staff_name,
                           DATE_FORMAT(p.payment_date, '%M %d, %Y %h:%i %p') AS formatted_date
                        FROM payments p
                        LEFT JOIN users u ON p.received_by = u.user_id
                        WHERE p.payment_id = ?");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $payment_result = $stmt->get_result();
    if ($payment_result->num_rows > 0) {
        $payment_details = $payment_result->fetch_assoc();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Downpayment - JX Tailoring</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
            padding-top: 60px;
        }
        
        .navbar {
            background-color: #ffffff;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .navbar-brand {
            font-weight: 600;
            color: #ff6f43;
        }
        
        .payment-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .payment-card-header {
            background-color: #ff6f43;
            color: white;
            padding: 1.25rem;
            font-weight: 600;
        }
        
        .payment-card-body {
            padding: 1.5rem;
        }
        
        .order-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        .order-info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        
        .order-info-label {
            font-weight: 500;
            color: #6c757d;
        }
        
        .order-info-value {
            font-weight: 600;
            text-align: right;
        }
        
        .amount-highlight {
            font-size: 1.5rem;
            font-weight: 700;
            color: #ff6f43;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .btn-primary {
            background-color: #ff6f43;
            border-color: #ff6f43;
            font-weight: 500;
            padding: 0.625rem 1.25rem;
        }
        
        .btn-primary:hover {
            background-color: #e85a30;
            border-color: #e85a30;
        }
        
        .btn-outline-secondary {
            color: #6c757d;
            border-color: #6c757d;
            font-weight: 500;
            padding: 0.625rem 1.25rem;
        }
        
        .payment-method-options {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .payment-method-option {
            flex: 1;
            padding: 1rem;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .payment-method-option.selected {
            border-color: #ff6f43;
            background-color: rgba(255, 111, 67, 0.1);
        }
        
        .payment-method-option i {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #6c757d;
        }
        
        .payment-method-option.selected i {
            color: #ff6f43;
        }
        
        .payment-instructions {
            background-color: #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            font-size: 0.9rem;
        }
        
        .alert {
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .file-upload-wrapper {
            position: relative;
            width: 100%;
            height: 160px;
            border: 2px dashed #ced4da;
            border-radius: 8px;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .file-upload-wrapper:hover {
            border-color: #ff6f43;
            background-color: rgba(255, 111, 67, 0.05);
        }
        
        .file-upload-message {
            text-align: center;
            color: #6c757d;
        }
        
        .file-upload-message i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #adb5bd;
        }
        
        .file-preview {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: none;
            object-fit: contain;
            padding: 10px;
        }
        
        .file-upload-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        
        .file-upload-remove {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: none;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            z-index: 2;
            color: #dc3545;
            border: none;
        }
        
        /* Receipt styles */
        .receipt {
            background-color: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1);
            max-width: 800px;
            margin: 0 auto;
        }
        
        .receipt-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .receipt-logo {
            max-width: 150px;
            margin-bottom: 1rem;
        }
        
        .receipt-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .receipt-id {
            font-size: 1rem;
            color: #6c757d;
        }
        
        .receipt-details {
            margin-bottom: 2rem;
        }
        
        .receipt-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed #e9ecef;
        }
        
        .receipt-label {
            font-weight: 500;
            color: #6c757d;
        }
        
        .receipt-value {
            text-align: right;
            font-weight: 600;
        }
        
        .receipt-total {
            font-size: 1.25rem;
            font-weight: 700;
            color: #ff6f43;
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
        }
        
        .receipt-action {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .receipt-screenshot {
            max-width: 100%;
            max-height: 300px;
            margin: 1rem auto;
            display: block;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        
        @media print {
            body * {
                visibility: hidden;
            }
            .receipt, .receipt * {
                visibility: visible;
            }
            .receipt {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                padding: 15px;
            }
            .receipt-action {
                display: none;
            }
            .navbar, .container > .row > .col-md-8 > .alert, .container > .row > .col-md-8 > .text-center {
                display: none;
            }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg fixed-top navbar-light">
    <div class="container">
        <a class="navbar-brand" href="index.php">JX Tailoring</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php"><i class="fas fa-home me-1"></i> Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="track_order.php"><i class="fas fa-box me-1"></i> My Orders</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($customer_name); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_message; ?>
                </div>
                <div class="text-center mb-4">
                    <a href="track_order.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i> Back to Orders
                    </a>
                </div>
            <?php elseif (!empty($success_message)): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <?php echo $success_message; ?>
                </div>
                
                <!-- Receipt Section -->
                <div class="receipt" id="printableReceipt">
                    <div class="receipt-header">
                        <img src="../image/logo.png" alt="JX Tailoring Logo" class="receipt-logo">
                        <h1 class="receipt-title">Payment Receipt</h1>
                        <div class="receipt-id">Receipt #<?php echo sprintf('%06d', $payment_id); ?></div>
                        <div class="receipt-date"><?php echo $payment_details['formatted_date']; ?></div>
                    </div>
                    
                    <div class="receipt-details">
                        <div class="receipt-row">
                            <div class="receipt-label">Customer:</div>
                            <div class="receipt-value"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Phone Number:</div>
                            <div class="receipt-value"><?php echo htmlspecialchars($order['phone_number']); ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Order ID:</div>
                            <div class="receipt-value"><?php echo htmlspecialchars($order_id); ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Order Type:</div>
                            <div class="receipt-value"><?php echo ucfirst(htmlspecialchars($order['order_type'])); ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Payment Type:</div>
                            <div class="receipt-value">
                                <?php echo ($payment_details['amount'] >= $order['total_amount']) ? 'Full Payment' : 'Downpayment'; ?>
                            </div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Payment Method:</div>
                            <div class="receipt-value"><?php echo ucfirst(htmlspecialchars($payment_details['payment_method'])); ?></div>
                        </div>
                        <?php if (!empty($payment_details['transaction_reference'])): ?>
                        <div class="receipt-row">
                            <div class="receipt-label">Reference Number:</div>
                            <div class="receipt-value"><?php echo htmlspecialchars($payment_details['transaction_reference']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="receipt-row">
                            <div class="receipt-label">Received By:</div>
                            <div class="receipt-value"><?php echo htmlspecialchars($payment_details['staff_name']); ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Amount Paid:</div>
                            <div class="receipt-value receipt-total">₱<?php echo number_format($payment_details['amount'], 2); ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Total Amount:</div>
                            <div class="receipt-value">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Balance:</div>
                            <div class="receipt-value">₱<?php echo number_format($order['total_amount'] - $payment_details['amount'], 2); ?></div>
                        </div>
                    </div>
                    
                    <?php if (!empty($payment_details['screenshot_path']) && isset($payment_details['screenshot_path']) && file_exists($payment_details['screenshot_path'])): ?>
                    <div class="receipt-screenshot-container">
                        <p class="text-center fw-bold">Transaction Screenshot</p>
                        <img src="<?php echo $payment_details['screenshot_path']; ?>" alt="Payment Screenshot" class="receipt-screenshot">
                    </div>
                    <?php endif; ?>
                    
                    <div class="receipt-footer">
                        <p>Thank you for your payment!</p>
                        <p>JX Tailoring<br>123 Main Street, Cebu City<br>Phone: (032) 123-4567</p>
                    </div>
                </div>
                
                <div class="receipt-action">
                    <button class="btn btn-primary" onclick="printReceipt()">
                        <i class="fas fa-print me-2"></i> Print Receipt
                    </button>
                    <a href="track_order.php" class="btn btn-outline-secondary ms-2">
                        <i class="fas fa-box me-2"></i> View Orders
                    </a>
                </div>
                
            <?php else: ?>
                <div class="payment-card">
                    <div class="payment-card-header">
                        <h4 class="mb-0">Submit Payment</h4>
                    </div>
                    <div class="payment-card-body">
                        <div class="order-info mb-4">
                            <h5 class="mb-3">Order Information</h5>
                            <div class="order-info-row">
                                <div class="order-info-label">Order ID:</div>
                                <div class="order-info-value"><?php echo htmlspecialchars($order_id); ?></div>
                            </div>
                            <div class="order-info-row">
                                <div class="order-info-label">Order Type:</div>
                                <div class="order-info-value"><?php echo ucfirst(htmlspecialchars($order['order_type'])); ?></div>
                            </div>
                            <div class="order-info-row">
                                <div class="order-info-label">Date Ordered:</div>
                                <div class="order-info-value"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                            </div>
                            <div class="order-info-row">
                                <div class="order-info-label">Total Amount:</div>
                                <div class="order-info-value">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                            </div>
                            <div class="order-info-row">
                                <div class="order-info-label">Required Downpayment:</div>
                                <div class="order-info-value amount-highlight">₱<?php echo number_format($order['downpayment_amount'], 2); ?></div>
                                <input type="hidden" id="minimum_payment" value="<?php echo $order['downpayment_amount']; ?>">
                                <input type="hidden" id="total_amount" value="<?php echo $order['total_amount']; ?>">
                            </div>
                        </div>
                        
                        <form method="POST" id="paymentForm" enctype="multipart/form-data">
                            <h5 class="mb-3">Payment Method</h5>
                            <div class="payment-method-options">
                                <div class="payment-method-option selected" data-method="cash">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <div>Cash</div>
                                </div>
                                <div class="payment-method-option" data-method="gcash">
                                    <i class="fas fa-mobile-alt"></i>
                                    <div>GCash</div>
                                </div>
                            </div>
                            <input type="hidden" name="payment_method" id="payment_method" value="cash">
                            
                            <!-- Amount Paid -->
                            <div class="mb-3">
                                <label for="amount_paid" class="form-label">Amount Paid <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" min="<?php echo $order['downpayment_amount']; ?>" max="<?php echo $order['total_amount']; ?>" class="form-control" id="amount_paid" name="amount_paid" value="<?php echo $order['downpayment_amount']; ?>" required>
                                </div>
                                <div class="form-text">
                                    Minimum payment required: ₱<?php echo number_format($order['downpayment_amount'], 2); ?> (50% of total)
                                </div>
                                <div class="invalid-feedback" id="amount_error">
                                    Amount must be at least the required downpayment.
                                </div>
                            </div>
                            
                            <div id="gcashFields" style="display: none;">
                                <div class="mb-3">
                                    <label for="transaction_reference" class="form-label">GCash Reference Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="transaction_reference" name="transaction_reference" placeholder="Enter your GCash reference number">
                                    <div class="invalid-feedback">Please enter the reference number from your GCash transaction.</div>
                                </div>
                                
                                <!-- GCash Screenshot Upload -->
                                <div class="mb-3">
                                    <label class="form-label">Upload Screenshot <span class="text-danger">*</span></label>
                                    <div class="file-upload-wrapper">
                                        <div class="file-upload-message">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                            <p>Drag and drop a screenshot or click to browse</p>
                                        </div>
                                        <img src="" class="file-preview" id="screenshotPreview">
                                        <input type="file" class="file-upload-input" id="gcash_screenshot" name="gcash_screenshot" accept="image/*">
                                        <button type="button" class="file-upload-remove" id="removeScreenshot">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        Upload a screenshot of your GCash payment confirmation.
                                    </div>
                                </div>
                                
                                <div class="payment-instructions">
                                    <p class="mb-2"><strong>GCash Payment Instructions:</strong></p>
                                    <ol class="mb-0">
                                        <li>Open your GCash app and log in</li>
                                        <li>Send payment to: <strong>0917 123 4567</strong> (JX Tailoring)</li>
                                        <li>Enter the exact amount you want to pay</li>
                                        <li>Complete the payment and take a screenshot</li>
                                        <li>Upload the screenshot and enter the reference number above</li>
                                    </ol>
                                </div>
                            </div>
                            
                            <div id="cashFields">
                                <div class="payment-instructions">
                                    <p class="mb-2"><strong>Cash Payment Instructions:</strong></p>
                                    <ol class="mb-0">
                                        <li>Your payment will be marked as "pending verification"</li>
                                        <li>Visit our store to pay the amount in cash</li>
                                        <li>Once payment is verified, your order will move to the next stage</li>
                                    </ol>
                                </div>
                            </div>
                            
                            <!-- Staff Selection -->
                            <div class="mb-3 mt-4">
                                <label for="received_by" class="form-label">Payment Received By <span class="text-danger">*</span></label>
                                <select class="form-select staff-select" id="received_by" name="received_by" required>
                                    <option value="">Select Staff Member</option>
                                    <?php foreach ($staff_members as $staff): ?>
                                    <option value="<?php echo $staff['user_id']; ?>"><?php echo htmlspecialchars($staff['staff_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select the staff member who received this payment.</div>
                            </div>
                            
                            <div class="d-flex justify-content-between mt-4">
                                <a href="track_order.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check-circle me-2"></i> Submit Payment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bootstrap JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize select2
    $('.staff-select').select2({
        theme: 'bootstrap-5',
        placeholder: "Select a staff member",
        allowClear: true
    });
    
    const minimumPayment = parseFloat($('#minimum_payment').val());
    const totalAmount = parseFloat($('#total_amount').val());
    
    // Handle payment method selection
    $('.payment-method-option').click(function() {
        $('.payment-method-option').removeClass('selected');
        $(this).addClass('selected');
        
        const method = $(this).data('method');
        $('#payment_method').val(method);
        
        if (method === 'gcash') {
            $('#gcashFields').show();
            $('#cashFields').hide();
            $('#transaction_reference').attr('required', true);
            $('#gcash_screenshot').attr('required', true);
        } else {
            $('#gcashFields').hide();
            $('#cashFields').show();
            $('#transaction_reference').attr('required', false);
            $('#gcash_screenshot').attr('required', false);
        }
    });
    
    // Handle amount paid validation
    $('#amount_paid').on('input', function() {
        const amountPaid = parseFloat($(this).val());
        
        if (amountPaid < minimumPayment) {
            $(this).addClass('is-invalid');
            $('#amount_error').text(`Amount must be at least ₱${minimumPayment.toFixed(2)} (required downpayment).`);
        } else if (amountPaid > totalAmount) {
            $(this).addClass('is-invalid');
            $('#amount_error').text(`Amount cannot exceed the total order amount (₱${totalAmount.toFixed(2)}).`);
        } else {
            $(this).removeClass('is-invalid');
        }
        
        // Update payment type text based on amount
        if (amountPaid >= totalAmount) {
            $('.payment-type-text').text('Full Payment');
        } else {
            $('.payment-type-text').text('Downpayment');
        }
    });
    
    // File upload preview handling
    $('#gcash_screenshot').change(function(e) {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            const fileReader = new FileReader();
            
            fileReader.onload = function(e) {
                $('#screenshotPreview').attr('src', e.target.result);
                $('#screenshotPreview').css('display', 'block');
                $('.file-upload-message').css('display', 'none');
                $('#removeScreenshot').css('display', 'flex');
            }
            
            fileReader.readAsDataURL(file);
        }
    });
    
    // Remove screenshot
    $('#removeScreenshot').click(function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        $('#gcash_screenshot').val('');
        $('#screenshotPreview').attr('src', '');
        $('#screenshotPreview').css('display', 'none');
        $('.file-upload-message').css('display', 'block');
        $(this).css('display', 'none');
    });
    
    // Form validation
    $('#paymentForm').submit(function(e) {
        let isValid = true;
        
        // Validate amount
        const amountPaid = parseFloat($('#amount_paid').val());
        if (isNaN(amountPaid) || amountPaid < minimumPayment) {
            $('#amount_paid').addClass('is-invalid');
            isValid = false;
        } else {
            $('#amount_paid').removeClass('is-invalid');
        }
        
        // Validate GCash fields if selected
        const method = $('#payment_method').val();
        if (method === 'gcash') {
            const reference = $('#transaction_reference').val().trim();
            if (reference === '') {
                $('#transaction_reference').addClass('is-invalid');
                isValid = false;
            } else {
                $('#transaction_reference').removeClass('is-invalid');
            }
            
            const hasFile = $('#gcash_screenshot')[0].files.length > 0;
            if (!hasFile) {
                $('.file-upload-wrapper').css('border-color', '#dc3545');
                isValid = false;
            } else {
                $('.file-upload-wrapper').css('border-color', '#ced4da');
            }
        }
        
        // Validate staff selection
        const staffId = $('#received_by').val();
        if (!staffId) {
            $('#received_by').addClass('is-invalid');
            isValid = false;
        } else {
            $('#received_by').removeClass('is-invalid');
        }
        
        if (!isValid) {
            e.preventDefault();
        }
    });
});

// Print receipt function
function printReceipt() {
    window.print();
}
</script>

</body>
</html>
