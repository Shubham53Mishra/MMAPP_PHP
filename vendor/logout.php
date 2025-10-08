<?php
// Vendor logout API: Requires valid JWT token to logout
include __DIR__ . '/../common/db.php';
require __DIR__ . '/../composer/autoload.php';
include __DIR__ . '/../common/jwt_secret.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Authorization token required'
        ]);
        exit;
    }
    $jwt = $matches[1];
    try {
        $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid token'
        ]);
        exit;
    }
    echo json_encode([
        'status' => 'success',
        'message' => 'Logged out successfully. Please remove the token from client storage.'
    ]);
    exit;
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method'
    ]);
    exit;
}
?>
