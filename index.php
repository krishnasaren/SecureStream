<?php
// Start session at the very beginning
session_start();

// Define base path
define('BASE_PATH', __DIR__);

// Simple redirect based on session
if (isset($_SESSION['user_id']) && isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    // User is logged in, go to dashboard
    header('Location: pages/dashboard.php');
    exit;
} else {
    // User not logged in, show landing page
    header('Location: pages/login.php');
    exit;
}
?>