<?php
// User image upload (POST, multipart/form-data)
require __DIR__ . '/../../composer/autoload.php';
include '../common/db.php';
include '../common/jwt_secret.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
    echo json_encode(['status' => 'error', 'message' => 'Authorization token required']);
    exit;
}
$jwt = $matches[1];

try {
    $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    exit;
}

$userId = $decoded->sub;

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'No image uploaded or upload error']);
    exit;
}

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
$ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
$filename = 'user_' . $userId . '_' . time() . '.' . $ext;
$targetPath = $uploadDir . $filename;

if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
    $imageUrl = 'uploads/' . $filename;
    $sql = 'UPDATE users SET image = ? WHERE id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $imageUrl, $userId);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Image uploaded', 'image' => $imageUrl]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'DB update failed: ' . $conn->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Image upload failed']);
}
$conn->close();
?>
