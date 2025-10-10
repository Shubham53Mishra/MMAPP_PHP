<?php
// Vendor profile API inside user folder (for special use-case)
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

$vendorId = $decoded->sub;
$sql = "SELECT id, name, email, created_at, image FROM vendors WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $vendorId);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    if (!empty($row['image'])) {
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
        $row['image_url'] = rtrim($baseUrl, '/') . '/' . ltrim($row['image'], '/');
    } else {
        $row['image_url'] = null;
    }
    echo json_encode(['status' => 'success', 'vendor' => $row]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Vendor not found']);
}
$stmt->close();
$conn->close();
?>
