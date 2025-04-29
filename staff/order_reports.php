<?php
/**
 * Authentication check for staff pages
 * This file verifies that the user is logged in and has appropriate staff permissions
 */

// Make sure session is started (although it should be started in the including file)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    // Not logged in, redirect to login page
    header("Location: login.php");
    exit();
}

// Check if user has staff permissions (all roles except Customer)
$allowed_roles = ['Admin', 'Manager', 'Tailor', 'Staff', 'Sublimator'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    // User doesn't have permission
    header("Location: login.php?error=insufficient_permissions");
    exit();
}

// User is authenticated and authorized
// The script will continue execution in the file that included this one
?>