
<?php
// Enable error reporting and logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

// Vendor registration
include __DIR__ . '/../common_cafe/db.php';

$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';
$mobile = $_POST['mobile'] ?? '';

if (!$name || !$email || !$password || !$mobile) {
    echo json_encode(['status' => 'error', 'message' => 'All fields required']);
    exit;
}

// Check if email already exists
$checkSql = "SELECT id FROM vendors WHERE email = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param('s', $email);
$checkStmt->execute();
$checkStmt->store_result();
if ($checkStmt->num_rows > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Email already registered']);
    $checkStmt->close();
    $conn->close();
    exit;
}
$checkStmt->close();

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$sql = "INSERT INTO vendors (name, email, password, mobile) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ssss', $name, $email, $passwordHash, $mobile);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Vendor registered successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Registration failed: '.$conn->error]);
}
$stmt->close();
$conn->close();
?>
