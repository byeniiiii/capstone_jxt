<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit();
}

$customer_id = $_SESSION['customer_id'];
$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    echo "Invalid request.";
    exit();
}

// Fetch order info
$stmt = $conn->prepare("SELECT * FROM orders WHERE order_id = ? AND customer_id = ?");
$stmt->bind_param("si", $order_id, $customer_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo "Order not found.";
    exit();
}

if ($order['order_status'] != 'approved' || $order['payment_status'] != 'pending') {
    echo "You cannot make a downpayment for this order.";
    exit();
}

$downpayment = $order['downpayment_amount'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'];
    $reference = $_POST['transaction_reference'];

    // Manager will process the actual payment but we record it now
    $stmt = $conn->prepare("INSERT INTO payments (order_id, amount, payment_type, payment_method, transaction_reference, received_by) VALUES (?, ?, 'downpayment', ?, ?, 0)");
    $stmt->bind_param("sdss", $order_id, $downpayment, $payment_method, $reference);

    if ($stmt->execute()) {
        echo "<script>alert('Downpayment submitted! Waiting for manager approval.'); window.location.href='track_order.php';</script>";
    } else {
        echo "Error processing payment.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Submit Downpayment</title>
    <style>
        body { font-family: Poppins; padding: 20px; }
        form { max-width: 400px; margin: auto; }
        label, input, select { display: block; width: 100%; margin-bottom: 10px; }
        button { background-color: orange; color: white; padding: 10px; border: none; }
    </style>
</head>
<body>
    <?php include "sidebar.php"; ?>
    
    <h2>Submit Downpayment for Order: <?= htmlspecialchars($order_id) ?></h2>
    <p>Amount: <strong>₱<?= number_format($downpayment, 2) ?></strong></p>
    <form method="POST">
        <label for="payment_method">Payment Method:</label>
        <select name="payment_method" required>
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
        </select>

        <label for="transaction_reference">Transaction Reference (if GCash):</label>
        <input type="text" name="transaction_reference" placeholder="Leave blank if Cash">

        <button type="submit">Submit Downpayment</button>
    </form>
</body>
</html>
