<?php
// users/verify_otp.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
header('Content-Type: application/json');
require_once __DIR__ . '/../common_cafe/db.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../common_cafe/db.php';

$email = $_POST['email'] ?? '';
$code  = $_POST['code'] ?? '';

if (!$email || !$code) {
    echo json_encode(['status' => 'error', 'message' => 'Email and OTP required']);
    exit;
}

// Fetch OTP and expiry
$stmt = $conn->prepare('SELECT id, reset_code, reset_expires FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    if ($row['reset_code'] === $code && strtotime($row['reset_expires']) > time()) {
        // Mark as verified
        $update = $conn->prepare('UPDATE users SET is_verified_for_reset=1 WHERE id=?');
        $update->bind_param('i', $row['id']);
        $update->execute();
        $update->close();

    echo json_encode(['status' => 'success', 'message' => 'OTP verified']);
    } else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Email not found']);
}

$stmt->close();
$conn->close();
?>
