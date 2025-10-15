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


// Debug: log incoming request
file_put_contents(__DIR__ . '/verify_otp_debug.log', date('Y-m-d H:i:s') . " | POST: " . json_encode($_POST) . "\n", FILE_APPEND);

$email = $_POST['email'] ?? '';
$code  = $_POST['code'] ?? '';


if (!$email || !$code) {
    file_put_contents(__DIR__ . '/verify_otp_debug.log', date('Y-m-d H:i:s') . " | Missing email or code\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Email and OTP required']);
    exit;
}

// Fetch OTP and expiry

$stmt = $conn->prepare('SELECT id, reset_code, reset_expires FROM users WHERE email = ? LIMIT 1');
if (!$stmt) {
    file_put_contents(__DIR__ . '/verify_otp_debug.log', date('Y-m-d H:i:s') . " | Prepare failed: " . $conn->error . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    exit;
}
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();


if ($row = $result->fetch_assoc()) {
    file_put_contents(__DIR__ . '/verify_otp_debug.log', date('Y-m-d H:i:s') . " | Found user: " . json_encode($row) . "\n", FILE_APPEND);
    if ($row['reset_code'] === $code && strtotime($row['reset_expires']) > time()) {
        // Mark as verified
        $update = $conn->prepare('UPDATE users SET is_verified_for_reset=1 WHERE id=?');
        if (!$update) {
            file_put_contents(__DIR__ . '/verify_otp_debug.log', date('Y-m-d H:i:s') . " | Update prepare failed: " . $conn->error . "\n", FILE_APPEND);
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
            exit;
        }
        $update->bind_param('i', $row['id']);
        $update->execute();
        $update->close();

        file_put_contents(__DIR__ . '/verify_otp_debug.log', date('Y-m-d H:i:s') . " | OTP verified for user id: " . $row['id'] . "\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'OTP verified']);
    } else {
        file_put_contents(__DIR__ . '/verify_otp_debug.log', date('Y-m-d H:i:s') . " | Invalid or expired OTP. code: $code, db_code: " . $row['reset_code'] . ", expires: " . $row['reset_expires'] . "\n", FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
    }
} else {
    file_put_contents(__DIR__ . '/verify_otp_debug.log', date('Y-m-d H:i:s') . " | Email not found: $email\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Email not found']);
}

$stmt->close();
$conn->close();
?>
