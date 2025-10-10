<?php
// Vendor Orders API: create, confirm, cancel, deliver, get
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
$orderNumber = $_GET['order_number'] ?? null;
$trackingId = $_GET['tracking'] ?? null; // For tracking endpoint
$method = $_SERVER['REQUEST_METHOD'];

// Function to send order status update to WebSocket server
function sendOrderUpdateToWebSocket($orderId, $status, $vendorId) {
    $data = [
        'type' => 'order_update',
        'order_id' => $orderId,
        'status' => $status,
        'vendor_id' => $vendorId
    ];
    $fp = @fsockopen("127.0.0.1", 8080, $errno, $errstr, 2); // Change port if needed
    if ($fp) {
        fwrite($fp, json_encode($data) . "\n");
        fclose($fp);
    }
}

// Tracking endpoint: /api/orders/tracking/{order_id}
if ($method === 'GET' && $trackingId) {
    // Get order status history
    $sql = "SELECT status, changed_at FROM order_status_history WHERE order_id = ? ORDER BY changed_at ASC";
    $stmt = $conn->prepare($sql);
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
}
else if ($method === 'POST' && !$orderId && !$orderNumber) {
    // Create order
    $input = json_decode(file_get_contents('php://input'), true);
    $items = [];
    // Allow vendor_id in body, fallback to token
    // Always save vendor_id from body if provided, else from token
    $bodyVendorId = isset($input['vendor_id']) ? intval($input['vendor_id']) : $vendorId;
    if (isset($input['items']) && is_array($input['items'])) {
        foreach ($input['items'] as $item) {
            $items[] = [
                'type' => 'item',
                'id' => isset($item['id']) ? intval($item['id']) : null,
                'category' => $item['category'] ?? null,
                'subCategory' => $item['subCategory'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'deliveryDays' => $item['deliveryDays'] ?? null,
                'vendor_id' => $bodyVendorId // Save vendor_id in each item for reference
            ];
        }
    }
    if (empty($items)) {
        echo json_encode(['status' => 'error', 'message' => 'items required']); exit;
    }
    $itemsJson = json_encode($items);
    // Fetch vendor email using vendor_id
    $sqlVendorEmail = "SELECT email FROM vendors WHERE id = ?";
    $stmtVendorEmail = $conn->prepare($sqlVendorEmail);
    $stmtVendorEmail->bind_param('i', $bodyVendorId);
    $stmtVendorEmail->execute();
    $resultVendorEmail = $stmtVendorEmail->get_result();
    $vendorEmailRow = $resultVendorEmail->fetch_assoc();
    $vendorEmail = $vendorEmailRow ? $vendorEmailRow['email'] : null;
    $stmtVendorEmail->close();

    // Get user info from token
    $userName = isset($decoded->name) ? $decoded->name : null;
    $userEmail = isset($decoded->email) ? $decoded->email : null;
    $userMobile = isset($decoded->mobile) ? $decoded->mobile : null;
    // Debug log for userEmail
    error_log('Order Create Debug: userEmail = ' . var_export($userEmail, true));
    // If mobile not in token, fetch from users table using user email
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

    // Ensure user_mobile is not null
    if ($userMobile === null) {
        $userMobile = '';
    }
    // Generate order_number: MMF + rand + year + month + day + date
    $order_number = 'MMF' . rand(1000,9999) . date('YmdHis');
    // Add order_number to insert
    $sql = "INSERT INTO orders (vendor_id, items, vendor_email, status, created_at, order_date, user_name, user_email, user_mobile, order_number) VALUES (?, ?, ?, 'pending', NOW(), NOW(), ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $param_vendorId = (int)$bodyVendorId;
    $param_itemsJson = (string)$itemsJson;
    $param_vendorEmail = (string)$vendorEmail;
    $param_userName = (string)$userName;
    $param_userEmail = (string)$userEmail;
    $param_userMobile = (string)$userMobile;
    $param_orderNumber = (string)$order_number;
    $stmt->bind_param(
        'issssss',
        $param_vendorId,
        $param_itemsJson,
        $param_vendorEmail,
        $param_userName,
        $param_userEmail,
        $param_userMobile,
        $param_orderNumber
    );
    if ($stmt->execute()) {
        $orderId = $conn->insert_id;
        // Fetch vendor info using vendor_id from body (if provided)
        $sqlVendor = "SELECT id, name, email, mobile FROM vendors WHERE id = ?";
        $stmtVendor = $conn->prepare($sqlVendor);
        $stmtVendor->bind_param('i', $bodyVendorId);
        $stmtVendor->execute();
        $resultVendor = $stmtVendor->get_result();
        $vendorInfo = $resultVendor->fetch_assoc();
        $stmtVendor->close();

        // Fetch user info and order_number from orders table just inserted
        $sqlOrderUser = "SELECT user_name, user_email, user_mobile, order_number FROM orders WHERE id = ?";
        $stmtOrderUser = $conn->prepare($sqlOrderUser);
        $stmtOrderUser->bind_param('i', $orderId);
        $stmtOrderUser->execute();
        $resultOrderUser = $stmtOrderUser->get_result();
        $rowOrderUser = $resultOrderUser->fetch_assoc();
        $stmtOrderUser->close();
        $userInfo = [
            'user_name' => $rowOrderUser['user_name'],
            'user_email' => $rowOrderUser['user_email'],
            'user_mobile' => $rowOrderUser['user_mobile']
        ];

        echo json_encode([
            'status' => 'success',
            'order_id' => $orderId,
            'order_number' => $rowOrderUser['order_number'],
            'vendor_id' => $bodyVendorId,
            'vendor_info' => $vendorInfo,
            'user_info' => $userInfo,
            'token_vendor_id' => $vendorId
        ]);
        sendOrderUpdateToWebSocket($orderId, 'pending', $bodyVendorId);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
    }
    $stmt->close();
} else if ($method === 'POST' && ($orderId || $orderNumber)) {
    // If both id and order_number are provided, verify they match
    if ($orderId && $orderNumber) {
        $sqlCheckMatch = "SELECT id FROM orders WHERE id = ? AND order_number = ? AND vendor_id = ?";
        $stmtCheckMatch = $conn->prepare($sqlCheckMatch);
        $stmtCheckMatch->bind_param('isi', $orderId, $orderNumber, $vendorId);
        $stmtCheckMatch->execute();
        $resultCheckMatch = $stmtCheckMatch->get_result();
        $rowCheckMatch = $resultCheckMatch->fetch_assoc();
        $stmtCheckMatch->close();
        if (!$rowCheckMatch) {
            echo json_encode(['status' => 'error', 'message' => 'Order id and order_number do not match']);
            exit;
        }
    } else if ($orderNumber && !$orderId) {
        $sqlFindId = "SELECT id FROM orders WHERE order_number = ? AND vendor_id = ?";
        $stmtFindId = $conn->prepare($sqlFindId);
        $stmtFindId->bind_param('si', $orderNumber, $vendorId);
        $stmtFindId->execute();
        $resultFindId = $stmtFindId->get_result();
        $rowFindId = $resultFindId->fetch_assoc();
        $stmtFindId->close();
        if ($rowFindId) {
            $orderId = $rowFindId['id'];
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Order not found for order_number']);
            exit;
        }
    }
    $input = json_decode(file_get_contents('php://input'), true);
    // ...existing code for status update...
    // Status update (confirm/cancel/deliver) by id or order_number
    // If order_number is provided, lookup order id
    if ($orderNumber && !$orderId) {
        $sqlFindId = "SELECT id FROM orders WHERE order_number = ? AND vendor_id = ?";
        $stmtFindId = $conn->prepare($sqlFindId);
        $stmtFindId->bind_param('si', $orderNumber, $vendorId);
        $stmtFindId->execute();
        $resultFindId = $stmtFindId->get_result();
        $rowFindId = $resultFindId->fetch_assoc();
        $stmtFindId->close();
        if ($rowFindId) {
            $orderId = $rowFindId['id'];
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Order not found for order_number']);
            exit;
        }
    }
    $input = json_decode(file_get_contents('php://input'), true);
    // Confirm order
    if (isset($input['deliveryTime']) && isset($input['deliveryDate'])) {
        $sql = "UPDATE orders SET status='confirmed', delivery_time=?, delivery_date=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssii', $input['deliveryTime'], $input['deliveryDate'], $vendorId, $orderId);
        $success = $stmt->execute();
        $stmt->close();
        sendOrderUpdateToWebSocket($orderId, 'confirmed', $vendorId);
        echo json_encode([
            'status' => $success ? 'success' : 'error',
            'message' => $success ? 'Order confirmed' : 'Update failed: ' . $conn->error
        ]);
    // Cancel order
    } else if (isset($input['reason'])) {
        // Check if already cancelled
        $sqlCheck = "SELECT status FROM orders WHERE vendor_id=? AND id=?";
        $stmtCheck = $conn->prepare($sqlCheck);
        $stmtCheck->bind_param('ii', $vendorId, $orderId);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();
        $rowCheck = $resultCheck->fetch_assoc();
        $stmtCheck->close();
        if ($rowCheck && $rowCheck['status'] === 'cancelled') {
            echo json_encode(['status' => 'error', 'message' => 'Order already cancelled']);
            exit;
        }
        $sql = "UPDATE orders SET status='cancelled', cancel_reason=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $input['reason'], $vendorId, $orderId);
        $success = $stmt->execute();
        $stmt->close();
        sendOrderUpdateToWebSocket($orderId, 'cancelled', $vendorId);
        echo json_encode([
            'status' => $success ? 'success' : 'error',
            'message' => $success ? 'Order cancelled' : 'Cancel failed: ' . $conn->error
        ]);
    // Deliver order
    } else if (isset($input['delivered']) && $input['delivered'] == 1 && isset($input['fromDate'])) {
        // Check if order is cancelled
        $sqlCheck = "SELECT status FROM orders WHERE vendor_id=? AND id=?";
        $stmtCheck = $conn->prepare($sqlCheck);
        $stmtCheck->bind_param('ii', $vendorId, $orderId);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();
        $rowCheck = $resultCheck->fetch_assoc();
        $stmtCheck->close();
        if ($rowCheck && $rowCheck['status'] === 'cancelled') {
            echo json_encode(['status' => 'error', 'message' => 'Order already cancelled, cannot deliver']);
            exit;
        }
        if ($rowCheck && $rowCheck['status'] === 'delivered') {
            echo json_encode(['status' => 'error', 'message' => 'Order already delivered']);
            exit;
        }
        $sql = "UPDATE orders SET status='delivered', delivery_date=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $input['fromDate'], $vendorId, $orderId);
        $success = $stmt->execute();
        $stmt->close();
        sendOrderUpdateToWebSocket($orderId, 'delivered', $vendorId);
        echo json_encode([
            'status' => $success ? 'success' : 'error',
            'message' => $success ? 'Order delivered' : 'Deliver failed: ' . $conn->error
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid update body']);
    }
} else if ($method === 'GET' && !$orderId) {
    // Show orders for logged-in vendor or user
    $userEmail = isset($decoded->email) ? $decoded->email : null;
    // More robust vendor token detection: if sub exists and is numeric, treat as vendor
    $isVendor = isset($decoded->sub) && is_numeric($decoded->sub);
    $orders = [];
    if ($isVendor && isset($_GET['vendor_id'])) {
        // Vendor token and vendor_id param: show all orders for that vendor_id
        $paramVendorId = intval($_GET['vendor_id']);
        $sql = 'SELECT * FROM orders WHERE vendor_id = ? ORDER BY created_at DESC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $paramVendorId);
    } else if ($isVendor) {
        // Vendor token, no vendor_id param: show ALL orders
        $sql = 'SELECT * FROM orders ORDER BY created_at DESC';
        $stmt = $conn->prepare($sql);
    } else if (!$isVendor && isset($_GET['vendor_id'])) {
        // User token, vendor_id param: show user's orders for that vendor_id
        if (!$userEmail) {
            echo json_encode(['status' => 'error', 'message' => 'User email not found in token']);
            exit;
        }
        $paramVendorId = intval($_GET['vendor_id']);
        $sql = 'SELECT * FROM orders WHERE user_email = ? AND vendor_id = ? ORDER BY created_at DESC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $userEmail, $paramVendorId);
    } else {
        // User token: show only orders for this user
        if (!$userEmail) {
            echo json_encode(['status' => 'error', 'message' => 'User email not found in token']);
            exit;
        }
        $sql = 'SELECT * FROM orders WHERE user_email = ? ORDER BY created_at DESC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $userEmail);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Decode items JSON string to array
        if (isset($row['items'])) {
            $itemsArr = json_decode($row['items'], true);
            // Enrich each item with category_info and subCategory_info
            foreach ($itemsArr as &$item) {
                // Category info
                $catInfo = null;
                if (!empty($item['category'])) {
                    $sqlCat = "SELECT id, name FROM categories WHERE id = ?";
                    $stmtCat = $conn->prepare($sqlCat);
                    $stmtCat->bind_param('i', $item['category']);
                    $stmtCat->execute();
                    $resultCat = $stmtCat->get_result();
                    $catInfo = $resultCat->fetch_assoc();
                    $stmtCat->close();
                }
                $item['category_info'] = $catInfo;
                // SubCategory info
                $subCatInfo = null;
                if (!empty($item['subCategory'])) {
                    $sqlSubCat = "SELECT id, name FROM vendor_subcategories WHERE id = ?";
                    $stmtSubCat = $conn->prepare($sqlSubCat);
                    $stmtSubCat->bind_param('i', $item['subCategory']);
                    $stmtSubCat->execute();
                    $resultSubCat = $stmtSubCat->get_result();
                    $subCatInfo = $resultSubCat->fetch_assoc();
                    $stmtSubCat->close();
                }
                $item['subCategory_info'] = $subCatInfo;
            }
            unset($item);
            $row['items'] = $itemsArr;
        }
        $orders[] = $row;
    }
    $stmt->close();
    echo json_encode([
        'status' => 'success',
        'orders' => $orders,
        'user_token' => $jwt,
        'vendor_token' => $isVendor ? $jwt : null
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method or missing id']);
}
$conn->close();
?>