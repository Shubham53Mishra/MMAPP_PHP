<?php
// Mealbox Orders API: Get all orders for a user by token
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

include __DIR__ . '/../common/db.php';
require __DIR__ . '/../composer/autoload.php';
include __DIR__ . '/../common/jwt_secret.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$jwt = null;
if ($authHeader && preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
    $jwt = $matches[1];
} elseif (isset($_GET['user_token']) && $_GET['user_token']) {
    $jwt = $_GET['user_token'];
}
if (!$jwt) {
    echo json_encode(['status' => 'error', 'message' => 'Authorization token required']);
    exit;
}

try {
    $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    exit;
}

$userId = isset($decoded->sub) ? $decoded->sub : null;
if (!$userId) {
    echo json_encode(['status' => 'error', 'message' => 'User ID not found in token']);
    exit;
}

$rows = [];
$sql = 'SELECT * FROM meal_box_orders WHERE user_id = ? ORDER BY created_at DESC';
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $itemsArr = [];
    if (!empty($row['items'])) {
        $decodedItems = json_decode($row['items'], true);
        if (is_string($decodedItems)) {
            $decodedItems = json_decode($decodedItems, true);
        }
        if (is_array($decodedItems) && count($decodedItems) === 1 && is_string($decodedItems[0])) {
            $tryDecode = json_decode($decodedItems[0], true);
            if (is_array($tryDecode)) {
                $decodedItems = $tryDecode;
            }
        }
        if (is_array($decodedItems)) {
            $itemsArr = $decodedItems;
        }
    }
    $row['items'] = $itemsArr;
    $allMealbox = true;
    foreach ($row['items'] as &$it) {
        if (!isset($it['type']) || $it['type'] !== 'mealbox') {
            $allMealbox = false;
            break;
        }
        if (isset($it['mealbox'])) {
            $sqlMealbox = "SELECT * FROM vendor_meals WHERE id = ?";
            $stmtMealbox = $conn->prepare($sqlMealbox);
            $stmtMealbox->bind_param('i', $it['mealbox']);
            $stmtMealbox->execute();
            $resultMealbox = $stmtMealbox->get_result();
            $mealboxInfo = $resultMealbox->fetch_assoc();
            $stmtMealbox->close();
            $it['mealbox_info'] = $mealboxInfo;
        }
    }
    unset($it);
    if ($allMealbox && count($row['items']) > 0) {
        $rows[] = $row;
    }
}
echo json_encode(['status' => 'success', 'orders' => $rows, 'user_token' => $jwt]);
$stmt->close();
$conn->close();
