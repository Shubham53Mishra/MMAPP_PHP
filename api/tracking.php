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


// Normalize type param: accept many variants for 'order' and 'mealbox'
$type = isset($_GET['type']) ? $_GET['type'] : '';
$normType = strtolower(trim($type));
// remove underscores, hyphens and spaces
$normType = str_replace(['_', '-', ' '], ['', '', ''], $normType);
// remove plural 's' at end (mealboxes -> mealbox)
if (substr($normType, -1) === 's') {
    $normType = substr($normType, 0, -1);
}

// Accept common short forms
if ($normType === 'meal' || $normType === 'box') {
    $normType = 'mealbox';
}

// Read raw id as provided (don't cast yet)
$idRaw = isset($_GET['id']) ? $_GET['id'] : '';

if ($normType === 'order') {
    if ($idRaw === '') {
        echo json_encode(['success' => false, 'error' => 'Missing type or id']);
        exit;
    }
} elseif ($normType === 'mealbox') {
    if ($idRaw === '') {
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
    // order id is string
    $id = $idRaw;
    $sql = "SELECT * FROM $table WHERE $id_field = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $id);
} elseif ($normType === 'mealbox') {
    // Try several candidate columns in meal_box_orders to find the id
    $table = 'meal_box_orders';
    $id = $idRaw;
    $candidates = ['order_number', 'id'];
    foreach ($candidates as $col) {
        $sql = "SELECT * FROM $table WHERE $col = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        if ($col === 'id' && ctype_digit($id)) {
            $tmp = intval($id);
            $stmt->bind_param('i', $tmp);
        } else {
            $stmt->bind_param('s', $id);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            continue;
        }
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            echo json_encode(['success' => true, 'data' => $row]);
            $stmt->close();
            $conn->close();
            exit;
        }
        $stmt->close();
    }

    // Not found in meal_box_orders — fallback to orders table
    $table = 'orders';
    $id_field = 'order_number';
    $id = $idRaw;
    $sql = "SELECT * FROM $table WHERE $id_field = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (ctype_digit($id)) {
        $tmp = intval($id);
        $stmt->bind_param('i', $tmp);
    } else {
        $stmt->bind_param('s', $id);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid type', 'received' => $type]);
    exit;
}
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    echo json_encode(['success' => true, 'data' => $row]);
} else {
    echo json_encode(['success' => false, 'error' => 'Order not found or access denied']);
}
$stmt->close();
$conn->close();
