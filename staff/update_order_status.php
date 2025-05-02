<?php
// filepath: c:\xampp\htdocs\jx_tailoring\staff\update_order_status.php
session_start();
include '../db.php';

// Check if user is logged in and is staff
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Manager', 'Staff'])) {
    echo "Unauthorized access";
    exit;
}

// Add to the top of update_order_status.php for debugging
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "Error: Request method is not POST";
    exit;
}

if (empty($_POST['order_id'])) {
    echo "Error: order_id is missing";
    exit;
}

if (empty($_POST['status'])) {
    echo "Error: status is missing";
    exit;
}

// Debug logging
error_log("Order Status Update Request: " . json_encode($_POST));

$order_id = $_POST['order_id'];
$status = $_POST['status'];
$notes = $_POST['notes'] ?? null;
$user_id = $_SESSION['user_id'];

// Check if order exists
$check = $conn->prepare("SELECT order_id, customer_id, order_status FROM orders WHERE order_id = ?");
$check->bind_param("s", $order_id);
$check->execute();
$check_result = $check->get_result();

if ($check_result->num_rows === 0) {
    echo "Order not found";
    exit;
}

$order_info = $check_result->fetch_assoc();

// Validate status transition (ensure it follows the proper workflow)
$valid_transitions = [
    'pending_approval' => ['approved', 'cancelled'],
    'approved' => ['in_process', 'cancelled'],
    'in_process' => ['ready_for_pickup', 'cancelled'],
    'ready_for_pickup' => ['completed', 'cancelled'],
    'completed' => [],  // No transitions from completed
    'cancelled' => []   // No transitions from cancelled
];

if (!in_array($status, $valid_transitions[$order_info['order_status']] ?? [])) {
    echo "Invalid status transition from {$order_info['order_status']} to {$status}";
    exit;
}

// Begin transaction
$conn->begin_transaction();

// Replace the entire transaction block with this simplified version for testing
try {
    // Just update the status - no history or notifications
    $stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE order_id = ?");
    $stmt->bind_param("ss", $status, $order_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => "Order status updated to " . ucwords(str_replace('_', ' ', $status))
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => "Failed to update order status: " . $conn->error
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>