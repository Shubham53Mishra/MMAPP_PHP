<?php
// Get logged-in user or vendor profile (JWT protected, auto-detect)
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
$email = $decoded->email ?? null;

// Try user first
$sql = "SELECT id, name, email, created_at FROM users WHERE id = ? AND email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('is', $userId, $email);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    echo json_encode(['status' => 'success', 'type' => 'user', 'profile' => $row]);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

// Try vendor
$sql = "SELECT id, name, email, created_at, image FROM vendors WHERE id = ? AND email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('is', $userId, $email);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    echo json_encode(['status' => 'success', 'type' => 'vendor', 'profile' => $row]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Profile not found']);
}
$stmt->close();
$conn->close();
?>
