<?php
// Vendor category API: Add and Get categories for logged-in vendor only
require __DIR__ . '/../../backend/composer/autoload.php';
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

// Hardcoded fruit categories
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $categories = [
        ['id' => 1, 'category' => 'Apple'],
        ['id' => 2, 'category' => 'Banana'],
        ['id' => 3, 'category' => 'Orange'],
        ['id' => 4, 'category' => 'Mango'],
        ['id' => 5, 'category' => 'Grapes'],
        ['id' => 6, 'category' => 'Pineapple'],
        ['id' => 7, 'category' => 'Papaya'],
        ['id' => 8, 'category' => 'Watermelon'],
        ['id' => 9, 'category' => 'Strawberry'],
        ['id' => 10, 'category' => 'Guava'],
    ];
    echo json_encode(['status' => 'success', 'categories' => $categories]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Category creation not allowed. Only GET supported.']);
}
$conn->close();
?>
