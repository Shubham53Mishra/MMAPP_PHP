<?php
// User registration
include '../common/db.php';

$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (!$name || !$email || !$password) {
    echo json_encode(['status' => 'error', 'message' => 'All fields required']);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$sql = "INSERT INTO users (name, email, password) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('sss', $name, $email, $passwordHash);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'User registered successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Registration failed: '.$conn->error]);
}
$stmt->close();
$conn->close();
?>
