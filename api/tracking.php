<?php
// tracking.php
// Usage: tracking.php?type=order|mealbox&id=ORDER_ID

header('Content-Type: application/json');
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../composer/autoload.php';
require_once __DIR__ . '/../common/jwt_secret.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
    echo json_encode(['success' => false, 'error' => 'Authorization token required']);
    exit;
}
$jwt = $matches[1];
try {
    $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
    $userId = $decoded->sub;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}


// Normalize type param: accept order, mealbox, meal_box, meal-box, mealBox
$type = isset($_GET['type']) ? $_GET['type'] : '';
$normType = strtolower(str_replace(['_', '-'], ['', ''], $type));
if ($normType === 'order') {
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    if (!$type || $id === '') {
        echo json_encode(['success' => false, 'error' => 'Missing type or id']);
        exit;
    }
} elseif ($normType === 'mealbox') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$type || !$id) {
        echo json_encode(['success' => false, 'error' => 'Missing type or id']);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid type', 'received' => $type]);
    exit;
}

$table = '';
$id_field = '';
$user_field = '';
$bindType = '';
if ($normType === 'order') {
    $table = 'orders';
    $id_field = 'order_number';
    $user_field = 'user_id';
    $bindType = 'si'; // order_number (string), user_id (int)
    $id = isset($_GET['id']) ? $_GET['id'] : '';
} elseif ($normType === 'mealbox') {
    $table = 'meal_box_orders';
    $id_field = 'meal_box_order_id';
    $user_field = 'user_id';
    $bindType = 'ii'; // meal_box_order_id (int), user_id (int)
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid type', 'received' => $type]);
    exit;
}

if ($normType === 'order') {
    $sql = "SELECT status FROM $table WHERE $id_field = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $id);
} elseif ($normType === 'mealbox') {
    $sql = "SELECT status FROM $table WHERE $id_field = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
}
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    echo json_encode(['success' => true, 'status' => $row['status']]);
} else {
    echo json_encode(['success' => false, 'error' => 'Order not found or access denied']);
}
$stmt->close();
$conn->close();
