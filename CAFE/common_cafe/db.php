
<?php
// Simple database connection for CAFE
$host = 'localhost'; // Use 'localhost' for hosting
$user = 'u262838097_CAFE';
$pass = 'Ridobiko@123';
$dbname = 'u262838097_CAFE';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}
?>