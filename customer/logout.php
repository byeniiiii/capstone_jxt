<?php
session_start();
include '../db.php';

// Check if the customer is logged in
if (!isset($_SESSION['customer_id'])) {
    header("Location: index.php");
    exit();
}

// Check if logout is confirmed
if (isset($_GET['confirm']) && $_GET['confirm'] == 1) {
    // Validate if the user is in the customers table
    $customer_id = $_SESSION['customer_id'];
    $query = "SELECT * FROM customers WHERE customer_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // If user is not in the customers table, force logout
        session_destroy();
        header("Location: index.php");
        exit();
    }

    // Destroy session and log out customer
    session_destroy();
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout Confirmation | JX Tailoring</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Poppins', sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logout-card {
            max-width: 400px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #ff7d00;
            color: white;
            text-align: center;
            padding: 20px;
        }
        .icon-container {
            font-size: 3rem;
            margin-bottom: 10px;
        }
        .btn-primary {
            background-color: #ff7d00;
            border-color: #ff7d00;
        }
        .btn-primary:hover {
            background-color: #e06c00;
            border-color: #e06c00;
        }
        .btn-outline-secondary:hover {
            background-color: #f8f9fa;
            color: #6c757d;
        }
        /* Logout Modal Styling */
        .logout-icon-container {
            display: flex;
            justify-content: center;
        }
        .logout-icon-circle {
            width: 80px;
            height: 80px;
            background-color: #fff0e6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logout-icon-circle i {
            font-size: 32px;
            color: #ff7d00;
        }
        #logoutModal .modal-content {
            border-radius: 16px;
            overflow: hidden;
        }
        #logoutModal .btn-link {
            text-decoration: none;
            font-weight: 500;
        }
        #logoutModal .btn-link:hover {
            background-color: rgba(0,0,0,0.03);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card logout-card">
            <div class="card-header">
                <div class="icon-container">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <h4>Logout Confirmation</h4>
            </div>
            <div class="card-body text-center p-4">
                <p class="mb-4">Are you sure you want to log out from your account?</p>
                <div class="d-grid gap-2">
                    <a href="logout.php?confirm=1" class="btn btn-primary">
                        <i class="fas fa-check me-2"></i> Yes, Log Me Out
                    </a>
                    <a href="home.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i> No, Take Me Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-body p-0">
                    <div class="text-center p-4 pb-0">
                        <div class="logout-icon-container mb-3">
                            <div class="logout-icon-circle">
                                <i class="fas fa-sign-out-alt"></i>
                            </div>
                        </div>
                        <h5 class="modal-title mb-3" id="logoutModalLabel">Ready to leave?</h5>
                        <p class="text-muted mb-4">Are you sure you want to log out from your account?</p>
                    </div>
                    <div class="d-flex border-top">
                        <button type="button" class="btn btn-link text-secondary flex-fill py-3 m-0 border-end rounded-0" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <a href="logout.php?confirm=1" class="btn btn-link text-danger flex-fill py-3 m-0 rounded-0">
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-show modal on page load
        document.addEventListener('DOMContentLoaded', function() {
            // No need for additional JavaScript as the page itself is the confirmation
        });
    </script>
</body>
</html>
