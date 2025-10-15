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
    // Allow GET to show user's sample orders
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $userId = $decoded->sub ?? null;
        if (!$userId) {
            echo json_encode(['status' => 'error', 'message' => 'User ID missing in token.']);
            exit;
        }
        $sql = 'SELECT * FROM meal_box_orders WHERE user_id = ? ORDER BY created_at DESC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        $stmt->close();
        echo json_encode(['status' => 'success', 'orders' => $orders, 'total_orders' => count($orders)]);
        $conn->close();
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'POST method required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mealBoxId = isset($input['mealBoxId']) ? intval($input['mealBoxId']) : null;
$quantity = isset($input['quantity']) ? intval($input['quantity']) : 1;
if (!$mealBoxId || $quantity < 1) {
    echo json_encode([
        'status' => 'error',
        'message' => 'mealBoxId and quantity required',
        'debug' => [
            'mealBoxId' => $mealBoxId,
            'quantity' => $quantity,
            'input' => $input
        ]
    ]);
    exit;
}

// Check sampleAvailable for mealbox
// Check sampleAvailable for mealbox
$sqlSample = 'SELECT vendor_id, sampleAvailable FROM vendor_meals WHERE id = ?';
$stmtSample = $conn->prepare($sqlSample);
if (!$stmtSample) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Prepare failed',
        'sql_error' => $conn->error,
        'debug' => [
            'mealBoxId' => $mealBoxId
        ]
    ]);
    exit;
}
$stmtSample->bind_param('i', $mealBoxId);
$stmtSample->execute();
$resultSample = $stmtSample->get_result();
$rowSample = $resultSample->fetch_assoc();
$stmtSample->close();

if (!$rowSample) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Mealbox not found',
        'debug' => [
            'mealBoxId' => $mealBoxId
        ]
    ]);
    exit;
}
if (intval($rowSample['sampleAvailable']) !== 1) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Sample not available for this mealbox',
        'debug' => [
            'mealBoxId' => $mealBoxId,
            'sampleAvailable' => $rowSample['sampleAvailable']
        ]
    ]);
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

$orderNumber = 'MMF_SAMPLE' . rand(10, 99) . date('YmdHis');
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
// Fetch vendor email
$sqlVendorEmail = "SELECT email FROM vendors WHERE id = ?";
$stmtVendorEmail = $conn->prepare($sqlVendorEmail);
$stmtVendorEmail->bind_param('i', $vendorId);
$stmtVendorEmail->execute();
$resultVendorEmail = $stmtVendorEmail->get_result();
$vendorEmailRow = $resultVendorEmail->fetch_assoc();
$vendorEmail = $vendorEmailRow ? $vendorEmailRow['email'] : null;
$stmtVendorEmail->close();

// Insert order with vendor_email
$sql = 'INSERT INTO meal_box_orders (vendor_id, vendor_email, items, status, created_at, order_date, order_number, user_id, user_name, user_email, user_mobile) VALUES (?, ?, ?, "pending", NOW(), NOW(), ?, ?, ?, ?, ?)';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Prepare failed',
        'sql_error' => $conn->error,
        'debug' => [
            'vendorId' => $vendorId,
            'itemsJson' => $itemsJson,
            'orderNumber' => $orderNumber,
            'userId' => $userId
        ]
    ]);
    exit;
}
$stmt->bind_param('isssisss',
    $vendorId,      // i
    $vendorEmail,   // s
    $itemsJson,     // s
    $orderNumber,   // s
    $userId,        // i
    $userName,      // s
    $userEmail,     // s
    $userMobile     // s
);
if ($stmt->execute()) {
    // Fetch vendor info for response
    $sqlVendorInfo = "SELECT id, name, email, mobile FROM vendors WHERE id = ?";
    $stmtVendorInfo = $conn->prepare($sqlVendorInfo);
    $stmtVendorInfo->bind_param('i', $vendorId);
    $stmtVendorInfo->execute();
    $resultVendorInfo = $stmtVendorInfo->get_result();
    $vendorInfo = $resultVendorInfo->fetch_assoc();
    $stmtVendorInfo->close();
    echo json_encode([
        'status' => 'success',
        'order_id' => $conn->insert_id,
        'order_number' => $orderNumber,
        'vendor_id' => $vendorId,
        'user_name' => $userName,
        'user_email' => $userEmail,
        'user_mobile' => $userMobile,
        'vendor_info' => $vendorInfo ? [
            'id' => $vendorInfo['id'],
            'name' => $vendorInfo['name'] ?? '',
            'email' => $vendorInfo['email'] ?? '',
            'mobile' => $vendorInfo['mobile'] ?? ''
        ] : null
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Insert failed',
        'sql_error' => $conn->error,
        'debug' => [
            'vendorId' => $vendorId,
            'itemsJson' => $itemsJson,
            'orderNumber' => $orderNumber,
            'userId' => $userId
        ]
    ]);
}
$stmt->close();
$conn->close();
?>
