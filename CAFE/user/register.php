<?php


error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

// User registration
include '../common_cafe/db.php';
require_once '../vendor/autoload.php'; // JWT library
include '../common_cafe/jwt_secret.php'; // JWT secret
$name = $_POST['name'] ?? '';
// Print connected database name for debugging
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
} else {
    // Only print for debug, remove/comment after testing
    // echo json_encode(['connected_database' => $conn->query("SELECT DATABASE() AS dbname")->fetch_assoc()['dbname']]);
}


$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
// Hash password before saving
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

if (!$name || !$email || !$password || !$mobile) {
    echo json_encode(['status' => 'error', 'message' => 'All fields required']);
    exit;
}

// Check if email or mobile already exists
$sqlCheck = "SELECT id FROM users WHERE email = ? OR mobile = ?";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param('ss', $email, $mobile);
$stmtCheck->execute();
$stmtCheck->store_result();
if ($stmtCheck->num_rows > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Email or mobile already registered']);
    $stmtCheck->close();
    $conn->close();
    exit;
}
$stmtCheck->close();



$sql = "INSERT INTO users (name, email, password, mobile) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ssss', $name, $email, $hashedPassword, $mobile);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'User registered successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Registration failed: '.$conn->error]);
}
$stmt->close();
$conn->close();
?>
