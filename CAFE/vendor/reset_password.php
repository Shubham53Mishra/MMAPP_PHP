<?php
// vendor/reset_password.php
// POST: { email, new_password }

header('Content-Type: application/json');
require_once __DIR__ . '/../common_cafe/db.php';

$email = $_POST['email'] ?? '';
$new_password = $_POST['new_password'] ?? '';

if (!$email || !$new_password) {
    echo json_encode(['success' => false, 'error' => 'Email and new password required']);
    exit;
}

$stmt = $conn->prepare('SELECT id, is_verified_for_reset FROM vendors WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    if ($row['is_verified_for_reset'] == 1) {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare('UPDATE vendors SET password=?, is_verified_for_reset=0, reset_code=NULL, reset_expires=NULL WHERE id=?');
        $update->bind_param('si', $hash, $row['id']);
        $update->execute();
        $update->close();

        echo json_encode(['success' => true, 'message' => 'Password reset successful']);
    } else {
        echo json_encode(['success' => false, 'error' => 'OTP not verified yet']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Email not found']);
}

$stmt->close();
$conn->close();
?>
