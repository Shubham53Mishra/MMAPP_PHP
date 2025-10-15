<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../common_cafe/db.php';

$email = $_POST['email'] ?? ''; 
$code = $_POST['code'] ?? '';

if (!$email || !$code) {
    echo json_encode(['success' => false, 'error' => 'Email and OTP required']);
    exit;
}

$stmt = $conn->prepare('SELECT id, reset_code, reset_expires FROM vendors WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    if ($row['reset_code'] === $code && strtotime($row['reset_expires']) > time()) {
        // ✅ Mark as verified
        $update = $conn->prepare('UPDATE vendors SET is_verified_for_reset=1 WHERE id=?');
        $update->bind_param('i', $row['id']);
        $update->execute();
        $update->close();

        echo json_encode(['success' => true, 'message' => 'OTP verified']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired OTP']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Email not found']);
}
?>
