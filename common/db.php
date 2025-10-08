<?php
// db.php - Database connection (direct credentials with port)

$host = '127.0.0.1';
$port = 3306;
$db   = 'u262838097_munchmart';
$user = 'u262838097_munchmart';
$pass = 'Ridobiko@123';

// Create connection
$conn = new mysqli($host, $user, $pass, $db, $port);

// Set charset
$conn->set_charset('utf8mb4');

// Check connection
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}
?>
