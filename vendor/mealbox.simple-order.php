<?php
// Simple Mealbox Sample Order API
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'POST method required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mealBoxId = isset($input['mealBoxId']) ? intval($input['mealBoxId']) : null;
$quantity = isset($input['quantity']) ? intval($input['quantity']) : 1;
if (!$mealBoxId || $quantity < 1) {
    echo json_encode(['status' => 'error', 'message' => 'mealBoxId and quantity required']);
    exit;
}

// Check sampleAvailable for mealbox
// Check sampleAvailable for mealbox
$sqlSample = 'SELECT vendor_id, sampleAvailable FROM vendor_meals WHERE id = ?';
$stmtSample = $conn->prepare($sqlSample);
if (!$stmtSample) {
    echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}
$stmtSample->bind_param('i', $mealBoxId);
$stmtSample->execute();
$resultSample = $stmtSample->get_result();
$rowSample = $resultSample->fetch_assoc();
$stmtSample->close();

if (!$rowSample) {
    echo json_encode(['status' => 'error', 'message' => 'Mealbox not found']);
    exit;
}
if (intval($rowSample['sampleAvailable']) !== 1) {
    echo json_encode(['status' => 'error', 'message' => 'Sample not available for this mealbox']);
    exit;
}

$vendorId = intval($rowSample['vendor_id']);
$userId = $decoded->sub ?? null;
$userName = $decoded->name ?? null;
$userEmail = $decoded->email ?? null;
$userMobile = isset($decoded->mobile) ? $decoded->mobile : null;
if (!$userMobile && $userEmail) {
    $sqlUserMobile = 'SELECT mobile FROM users WHERE email = ?';
    $stmtUserMobile = $conn->prepare($sqlUserMobile);
    $stmtUserMobile->bind_param('s', $userEmail);
    $stmtUserMobile->execute();
    $resultUserMobile = $stmtUserMobile->get_result();
    if ($rowUserMobile = $resultUserMobile->fetch_assoc()) {
        $userMobile = $rowUserMobile['mobile'];
    }
    $stmtUserMobile->close();
}
if ($userMobile === null) {
    $userMobile = '';
}

$orderNumber = 'MMF_SAMPLE' . rand(1000, 9999) . date('YmdHis');
$items = [
    [
        'type' => 'mealbox',
        'mealbox' => $mealBoxId,
        'quantity' => $quantity,
        'sampleOrder' => true
    ]
];
$itemsJson = json_encode($items);

// 9 columns, so 9 types: i (vendor_id), s (items), s (status), s (order_number), i (user_id), s (user_name), s (user_email), s (user_mobile)
// Only bind variables for the 8 placeholders: vendor_id, items, order_number, user_id, user_name, user_email, user_mobile
$sql = 'INSERT INTO meal_box_orders (vendor_id, items, status, created_at, order_date, order_number, user_id, user_name, user_email, user_mobile) VALUES (?, ?, "pending", NOW(), NOW(), ?, ?, ?, ?, ?)';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}
$stmt->bind_param('issssss',
    $vendorId,      // i
    $itemsJson,     // s
    $orderNumber,   // s
    $userId,        // s (should be string for user_id)
    $userName,      // s
    $userEmail,     // s
    $userMobile     // s
);
if ($stmt->execute()) {
    echo json_encode([
        'status' => 'success',
        'order_id' => $conn->insert_id,
        'order_number' => $orderNumber,
        'vendor_id' => $vendorId,
        'user_name' => $userName,
        'user_email' => $userEmail,
        'user_mobile' => $userMobile
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
}
$stmt->close();
$conn->close();
?>
