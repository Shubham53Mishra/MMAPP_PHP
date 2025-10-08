<?php
// Mealbox Orders API: create, confirm, cancel, deliver, get (only mealbox orders)
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

$vendorId = $decoded->sub;
$orderId = $_GET['id'] ?? null;
$trackingId = $_GET['tracking'] ?? null; // For tracking endpoint
$method = $_SERVER['REQUEST_METHOD'];

// Tracking endpoint
if ($method === 'GET' && $trackingId) {
    $sql = "SELECT status, changed_at FROM order_status_history WHERE order_id = ? ORDER BY changed_at ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('i', $trackingId);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = [
            'status' => $row['status'],
            'timestamp' => $row['changed_at']
        ];
    }
    echo json_encode(['order_id' => $trackingId, 'history' => $history]);
    $stmt->close();

} else if ($method === 'POST' && !$orderId) {
    // Create mealbox order
    $input = json_decode(file_get_contents('php://input'), true);
    $items = [];

    $bodyVendorId = isset($input['vendor_id']) ? intval($input['vendor_id']) : $vendorId;

    if (isset($input['items']) && is_array($input['items'])) {
        foreach ($input['items'] as $item) {
            if (isset($item['mealbox'])) {
                $items[] = [
                    'type' => 'mealbox',
                    'mealbox' => $item['mealbox'],
                    'quantity' => $item['quantity'] ?? 1,
                    'deliveryDays' => $item['deliveryDays'] ?? null
                ];
            }
        }
    }

    if (empty($items)) {
        echo json_encode(['status' => 'error', 'message' => 'At least one mealbox required in items']);
        exit;
    }

    $itemsJson = json_encode($items);

    // Fetch vendor email
    $sqlVendorEmail = "SELECT email FROM vendors WHERE id = ?";
    $stmtVendorEmail = $conn->prepare($sqlVendorEmail);
    $stmtVendorEmail->bind_param('i', $bodyVendorId);
    $stmtVendorEmail->execute();
    $resultVendorEmail = $stmtVendorEmail->get_result();
    $vendorEmailRow = $resultVendorEmail->fetch_assoc();
    $vendorEmail = $vendorEmailRow ? $vendorEmailRow['email'] : null;
    $stmtVendorEmail->close();

    // User info from token
    $userId = $decoded->sub ?? null;
    $userName = $decoded->name ?? null;
    $userEmail = $decoded->email ?? null;
    $userMobile = isset($decoded->mobile) ? $decoded->mobile : (isset($input['mobile']) ? $input['mobile'] : null);
    // If mobile not in token or input, fetch from users table using user email
    if (!$userMobile && $userEmail) {
        $sqlUserMobile = "SELECT mobile FROM users WHERE email = ?";
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

    $orderNumber = 'MMF' . rand(1000, 9999) . date('YmdHis');

    $sql = "INSERT INTO meal_box_orders 
    (vendor_id, vendor_email, items, status, created_at, order_date, order_number, user_id, user_name, user_email, user_mobile) 
    VALUES (?, ?, ?, 'pending', NOW(), NOW(), ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
        exit;
    }

    // 8 placeholders => issi order me variables
    $stmt->bind_param(
        'isssisss',
        $bodyVendorId,  // i
        $vendorEmail,   // s
        $itemsJson,     // s
        $orderNumber,   // s
        $userId,        // i
        $userName,      // s
        $userEmail,     // s
        $userMobile     // s
    );

    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'order_id' => $conn->insert_id,
            'order_number' => $orderNumber,
            'vendor_id' => $bodyVendorId,
            'vendor_email' => $vendorEmail,
            'user_name' => $userName,
            'user_email' => $userEmail,
            'user_mobile' => $userMobile
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
    }
    $stmt->close();

} else if ($method === 'GET' && !$orderId) {
    // Show only orders for the logged-in user (by user_email from token)
    $userEmail = isset($decoded->email) ? $decoded->email : null;
    if (!$userEmail) {
        echo json_encode(['status' => 'error', 'message' => 'User email not found in token']);
        exit;
    }
    $sql = 'SELECT * FROM meal_box_orders WHERE user_email = ? ORDER BY created_at DESC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $userEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['items'] = $row['items'] ? json_decode($row['items'], true) : [];
        $allMealbox = true;
        foreach ($row['items'] as &$it) {
            if (!isset($it['type']) || $it['type'] !== 'mealbox') {
                $allMealbox = false;
                break;
            }
            // Enrich mealbox info for each item
            if (isset($it['mealbox'])) {
                $sqlMealbox = "SELECT * FROM vendor_meals WHERE id = ?";
                $stmtMealbox = $conn->prepare($sqlMealbox);
                $stmtMealbox->bind_param('i', $it['mealbox']);
                $stmtMealbox->execute();
                $resultMealbox = $stmtMealbox->get_result();
                $it['mealbox_info'] = $resultMealbox->fetch_assoc();
                $stmtMealbox->close();
            }
        }
        unset($it);
        if ($allMealbox && count($row['items']) > 0) {
            $rows[] = $row;
        }
    }
    echo json_encode(['status' => 'success', 'orders' => $rows, 'user_token' => $jwt]);
    $stmt->close();

} else if ($method === 'GET' && $orderId) {
    $sql = 'SELECT * FROM meal_box_orders WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $vendorId, $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        $row['items'] = $row['items'] ? json_decode($row['items'], true) : [];
        $allMealbox = true;
        foreach ($row['items'] as &$it) {
            if (!isset($it['type']) || $it['type'] !== 'mealbox') {
                $allMealbox = false;
                break;
            }
            // Enrich mealbox info
            if (isset($it['mealbox'])) {
                $sqlMealbox = "SELECT * FROM vendor_meals WHERE id = ?";
                $stmtMealbox = $conn->prepare($sqlMealbox);
                $stmtMealbox->bind_param('i', $it['mealbox']);
                $stmtMealbox->execute();
                $resultMealbox = $stmtMealbox->get_result();
                $it['mealbox_info'] = $resultMealbox->fetch_assoc();
                $stmtMealbox->close();
            }
        }
        unset($it);
        if ($allMealbox && count($row['items']) > 0) {
            echo json_encode(['status' => 'success', 'order' => $row]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Not a mealbox order']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
    }
    $stmt->close();

} else if ($method === 'PUT' && $orderId) {
    $input = json_decode(file_get_contents('php://input'), true);

    // If orderId is not numeric, treat as order_number and fetch id
    $realOrderId = $orderId;
    if (!is_numeric($orderId)) {
        // Try vendor_id + order_number first
        $sqlFindId = "SELECT id FROM meal_box_orders WHERE vendor_id = ? AND order_number = ? LIMIT 1";
        $stmtFindId = $conn->prepare($sqlFindId);
        $stmtFindId->bind_param('is', $vendorId, $orderId);
        $stmtFindId->execute();
        $resultFindId = $stmtFindId->get_result();
        if ($rowFindId = $resultFindId->fetch_assoc()) {
            $realOrderId = $rowFindId['id'];
        } else {
            $stmtFindId->close();
            // Try global order_number (no vendor_id restriction)
            $sqlFindIdGlobal = "SELECT id FROM meal_box_orders WHERE order_number = ? LIMIT 1";
            $stmtFindIdGlobal = $conn->prepare($sqlFindIdGlobal);
            $stmtFindIdGlobal->bind_param('s', $orderId);
            $stmtFindIdGlobal->execute();
            $resultFindIdGlobal = $stmtFindIdGlobal->get_result();
            if ($rowFindIdGlobal = $resultFindIdGlobal->fetch_assoc()) {
                $realOrderId = $rowFindIdGlobal['id'];
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Order not found for order_number']);
                $stmtFindIdGlobal->close();
                exit;
            }
            $stmtFindIdGlobal->close();
        }
        $stmtFindId->close();
    }

    // Always check current status first
    $sqlCheck = "SELECT status FROM meal_box_orders WHERE id = ?";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bind_param('i', $realOrderId);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result();
    $rowCheck = $resultCheck->fetch_assoc();
    $stmtCheck->close();
    if ($rowCheck && $rowCheck['status'] === 'delivered') {
        echo json_encode(['status' => 'error', 'message' => 'Order already delivered, status cannot be changed']);
        exit;
    }
    // Confirm
    if (isset($input['deliveryTime']) && isset($input['deliveryDate'])) {
        $sql = "UPDATE meal_box_orders SET status='confirmed', delivery_time=?, delivery_date=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssii', $input['deliveryTime'], $input['deliveryDate'], $vendorId, $realOrderId);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Order confirmed']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $conn->error]);
        }
        $stmt->close();
    }
    // Cancel
    else if (isset($input['reason'])) {
        $sql = "UPDATE meal_box_orders SET status='cancelled', cancel_reason=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $input['reason'], $vendorId, $realOrderId);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Order cancelled']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Cancel failed: ' . $conn->error]);
        }
        $stmt->close();
    }
    // Deliver
    else if (isset($input['delivered']) && $input['delivered'] == 1 && isset($input['fromDate'])) {
        if ($rowCheck && $rowCheck['status'] === 'cancelled') {
            echo json_encode(['status' => 'error', 'message' => 'Cannot deliver a cancelled order']);
            exit;
        }
        $deliveryDate = $input['fromDate'];
        $deliveryTime = isset($input['deliveryTime']) ? $input['deliveryTime'] : null;
        if ($deliveryTime) {
            $sql = "UPDATE meal_box_orders SET status='delivered', delivery_date=?, delivery_time=? WHERE vendor_id=? AND id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssii', $deliveryDate, $deliveryTime, $vendorId, $realOrderId);
        } else {
            $sql = "UPDATE meal_box_orders SET status='delivered', delivery_date=? WHERE vendor_id=? AND id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sii', $deliveryDate, $vendorId, $realOrderId);
        }
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Order delivered']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Deliver failed: ' . $conn->error]);
        }
        $stmt->close();
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid update body']);
    }

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method or missing id']);
}

$conn->close();
