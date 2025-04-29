<?php
// filepath: c:\xampp\htdocs\jx_tailoring\staff\decline_order.php
include '../db.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Process decline request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && isset($_POST['reason'])) {
    $order_id = mysqli_real_escape_string($conn, $_POST['order_id']);
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);
    $staff_id = $_SESSION['user_id'];
    
    // Update order status to "declined"
    $updateQuery = "UPDATE orders SET 
                    order_status = 'declined', 
                    notes = CONCAT(IFNULL(notes, ''), '\nDeclined Reason: $reason'), 
                    staff_id = '$staff_id',
                    updated_at = NOW() 
                    WHERE order_id = '$order_id'";
    
    if (mysqli_query($conn, $updateQuery)) {
        // Log this action
        $log_query = "INSERT INTO activity_logs (user_id, action_type, description, created_at) 
                      VALUES ('$staff_id', 'order_decline', 'Order #$order_id declined: $reason', NOW())";
        mysqli_query($conn, $log_query);
        
        // Send notification to customer
        $customer_query = "SELECT customer_id FROM orders WHERE order_id = '$order_id'";
        $customer_result = mysqli_query($conn, $customer_query);
        if ($customer_row = mysqli_fetch_assoc($customer_result)) {
            $customer_id = $customer_row['customer_id'];
            
            // Fixed notification query to match your table structure
            $notif_query = "INSERT INTO notifications (customer_id, order_id, title, message) 
                           VALUES ('$customer_id', '$order_id', 'Order Declined', 'Your order #$order_id was declined: $reason')";
            mysqli_query($conn, $notif_query);
        }
        
        echo "Order has been declined.";
    } else {
        echo "Error: " . mysqli_error($conn);
    }
} else {
    echo "Invalid request.";
}
?>