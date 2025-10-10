<?php
// users/reset_password.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
header('Content-Type: application/json');
require_once __DIR__ . '/../common_cafe/db.php';

$email        = $_POST['email'] ?? '';
$new_password = $_POST['new_password'] ?? '';

if (!$email || !$new_password) {
    echo json_encode(['status' => 'error', 'message' => 'Email and new password required']);
    exit;
}

// Check if user is verified
$stmt = $conn->prepare('SELECT id, is_verified_for_reset FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    if ($row['is_verified_for_reset'] == 1) {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare('UPDATE users SET password=?, is_verified_for_reset=0, reset_code=NULL, reset_expires=NULL WHERE id=?');
        $update->bind_param('si', $hash, $row['id']);
        $update->execute();
        $update->close();

    echo json_encode(['status' => 'success', 'message' => 'Password reset successful']);
    } else {
    echo json_encode(['status' => 'error', 'message' => 'OTP not verified yet']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Email not found']);
}

$stmt->close();
$conn->close();
?>
