<?php
// User login
include '../common/db.php';
require __DIR__ . '/../../composer/autoload.php';
include '../common/jwt_secret.php';

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(['status' => 'error', 'message' => 'All fields required']);
    exit;
}

$sql = "SELECT * FROM users WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();


if ($row = $result->fetch_assoc()) {
    if (password_verify($password, $row['password'])) {
        // Remove password from user data before sending
        unset($row['password']);
        // JWT token generate
        $issuedAt = time();
        $expirationTime = $issuedAt + 3600; // 1 hour
        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'sub' => $row['id'],
            'email' => $row['email'],
            'name' => $row['name']
        ];
        $jwt = \Firebase\JWT\JWT::encode($payload, JWT_SECRET, 'HS256');
        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful',
            'token' => $jwt,
            'user' => $row
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid password']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
}
$stmt->close();
$conn->close();
?>
