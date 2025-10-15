<?php
// Vendor Orders API: create, confirm, cancel, deliver, get
// Set timezone to Asia/Kolkata (or change as needed)
date_default_timezone_set('Asia/Kolkata');
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

// Get JWT token
$userToken = $_GET['user_token'] ?? null;
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$jwt = null;
if ($userToken) {
    $jwt = $userToken;
} elseif ($authHeader && preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
    $jwt = $matches[1];
}
if (!$jwt) {
    echo json_encode(['status' => 'error', 'message' => 'Authorization token required']);
    exit;
}

// Decode JWT
try {
    $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    exit;
}

// Get vendor ID from token
$vendorId = null;
if (isset($decoded->id) && is_numeric($decoded->id)) {
    $vendorId = $decoded->id;
} elseif (isset($decoded->sub) && is_numeric($decoded->sub)) {
    $vendorId = $decoded->sub;
}

// Get request parameters
$orderId = $_GET['id'] ?? null;
$orderNumber = $_GET['order_number'] ?? null;
$trackingId = $_GET['tracking'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// Function to send order status update to WebSocket server
function sendOrderUpdateToWebSocket($orderId, $status, $vendorId) {
    $data = [
        'type' => 'order_update',
        'order_id' => $orderId,
        'status' => $status,
        'vendor_id' => $vendorId
    ];
    $fp = @fsockopen("127.0.0.1", 8080, $errno, $errstr, 2);
    if ($fp) {
        fwrite($fp, json_encode($data) . "\n");
        fclose($fp);
    }
}

// Function to get order items with category info
function getOrderItemsWithCategoryInfo($items, $conn) {
    if (!$items) return [];
    
    $itemsArr = json_decode($items, true);
    foreach ($itemsArr as &$item) {
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

        $subCatInfo = null;
        if (!empty($item['subCategory'])) {
            $sqlSubCat = "SELECT * FROM vendor_subcategories WHERE id = ?";
            $stmtSubCat = $conn->prepare($sqlSubCat);
            $stmtSubCat->bind_param('i', $item['subCategory']);
            $stmtSubCat->execute();
            $resultSubCat = $stmtSubCat->get_result();
            $subCatInfo = $resultSubCat->fetch_assoc();
            $stmtSubCat->close();
        }

        $item['subCategory_info'] = $subCatInfo;

        // Add vendor info for this item
        $vendorInfo = null;
        if (!empty($item['vendor_id'])) {
            $sqlVendor = "SELECT name, email, mobile FROM vendors WHERE id = ?";
            $stmtVendor = $conn->prepare($sqlVendor);
            $stmtVendor->bind_param('i', $item['vendor_id']);
            $stmtVendor->execute();
            $resultVendor = $stmtVendor->get_result();
            $vendorInfo = $resultVendor->fetch_assoc();
            $stmtVendor->close();
        }
        $item['vendor_info'] = $vendorInfo;

        // Add pricePerUnit from vendor_subcategories if subCategory is present, else from items table
        $pricePerUnit = null;
        if (!empty($item['subCategory'])) {
            $sqlPrice = "SELECT pricePerUnit FROM vendor_subcategories WHERE id = ?";
            $stmtPrice = $conn->prepare($sqlPrice);
            $stmtPrice->bind_param('i', $item['subCategory']);
            $stmtPrice->execute();
            $resultPrice = $stmtPrice->get_result();
            $rowPrice = $resultPrice->fetch_assoc();
            if ($rowPrice && isset($rowPrice['pricePerUnit'])) {
                $pricePerUnit = $rowPrice['pricePerUnit'];
            }
            $stmtPrice->close();
        }
        if ($pricePerUnit === null && !empty($item['id'])) {
            $sqlPrice = "SELECT price_per_unit FROM items WHERE id = ?";
            $stmtPrice = $conn->prepare($sqlPrice);
            $stmtPrice->bind_param('i', $item['id']);
            $stmtPrice->execute();
            $resultPrice = $stmtPrice->get_result();
            $rowPrice = $resultPrice->fetch_assoc();
            if ($rowPrice && isset($rowPrice['price_per_unit'])) {
                $pricePerUnit = $rowPrice['price_per_unit'];
            }
            $stmtPrice->close();
        }
        $item['pricePerUnit'] = $pricePerUnit !== null ? floatval($pricePerUnit) : 0.0;
    }
    unset($item);
    return $itemsArr;
}

// ============================================
// TRACKING ENDPOINT: GET /api/orders?tracking={order_id}
// ============================================
if ($method === 'GET' && $trackingId) {
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
    $conn->close();
    exit;
}

// ============================================
// CREATE ORDER: POST /api/orders (no id or order_number)
// ============================================
elseif ($method === 'POST' && !$orderId && !$orderNumber) {
    $input = json_decode(file_get_contents('php://input'), true);
    $items = [];
    
    // Allow vendor_id in body, fallback to token
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
                'vendor_id' => $bodyVendorId
            ];
        }
    }
    
    if (empty($items)) {
        echo json_encode(['status' => 'error', 'message' => 'items required']);
        exit;
    }
    
    $itemsJson = json_encode($items);
    
    // Get vendor email
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
    $userId = isset($decoded->id) ? $decoded->id : null;
    
    error_log('Order Create Debug: userEmail = ' . var_export($userEmail, true));
    
    // Try to get missing user info from database
    if (!$userMobile || !$userId) {
        $sqlUser = "SELECT id, mobile FROM users WHERE email = ?";
        $stmtUser = $conn->prepare($sqlUser);
        $stmtUser->bind_param('s', $userEmail);
        $stmtUser->execute();
        $resultUser = $stmtUser->get_result();
        if ($rowUser = $resultUser->fetch_assoc()) {
            if (!$userMobile) $userMobile = $rowUser['mobile'];
            if (!$userId) $userId = $rowUser['id'];
        }
        $stmtUser->close();
    }
    
    if ($userMobile === null) {
        $userMobile = '';
    }
    
    $order_number = 'MMF' . rand(1000,9999) . date('YmdHis');
    $now = date('Y-m-d H:i:s');
    
    $sql = "INSERT INTO orders (vendor_id, items, vendor_email, status, created_at, order_date, user_name, user_email, user_mobile, order_number, user_id) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    $param_vendorId = (int)$bodyVendorId;
    $param_itemsJson = (string)$itemsJson;
    $param_vendorEmail = (string)$vendorEmail;
    $param_now = (string)$now;
    $param_userName = (string)$userName;
    $param_userEmail = (string)$userEmail;
    $param_userMobile = (string)$userMobile;
    $param_orderNumber = (string)$order_number;
    $param_userId = (int)$userId;
    
    $stmt->bind_param(
        'issssssssi',
        $param_vendorId,
        $param_itemsJson,
        $param_vendorEmail,
        $param_now,
        $param_now,
        $param_userName,
        $param_userEmail,
        $param_userMobile,
        $param_orderNumber,
        $param_userId
    );
    
    if ($stmt->execute()) {
        $orderId = $conn->insert_id;
        
        // Get vendor info
        $sqlVendor = "SELECT id, name, email, mobile FROM vendors WHERE id = ?";
        $stmtVendor = $conn->prepare($sqlVendor);
        $stmtVendor->bind_param('i', $bodyVendorId);
        $stmtVendor->execute();
        $resultVendor = $stmtVendor->get_result();
        $vendorInfo = $resultVendor->fetch_assoc();
        $stmtVendor->close();

        // Get order user info
        $sqlOrderUser = "SELECT user_name, user_email, user_mobile, order_number FROM orders WHERE id = ?";
        $stmtOrderUser = $conn->prepare($sqlOrderUser);
        $stmtOrderUser->bind_param('i', $orderId);
        $stmtOrderUser->execute();
        $resultOrderUser = $stmtOrderUser->get_result();
        $rowOrderUser = $resultOrderUser->fetch_assoc();
        $stmtOrderUser->close();
        
        $userInfo = [
            'user_id' => $userId,
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
    $conn->close();
    exit;
}

// ============================================
// UPDATE ORDER: POST /api/orders?id={id} or ?order_number={number}
// (confirm, cancel, deliver)
// ============================================
elseif ($method === 'POST' && ($orderId || $orderNumber)) {
    // Verify id and order_number match if both provided
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
    } elseif ($orderNumber && !$orderId) {
        // Find order ID by order_number
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
    
    // CONFIRM ORDER
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
    }
    // CANCEL ORDER
    elseif (isset($input['reason'])) {
        // Check if already cancelled or delivered
        $sqlCheck = "SELECT status FROM orders WHERE vendor_id=? AND id=?";
        $stmtCheck = $conn->prepare($sqlCheck);
        $stmtCheck->bind_param('ii', $vendorId, $orderId);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();
        $rowCheck = $resultCheck->fetch_assoc();
        $stmtCheck->close();
        
        if ($rowCheck) {
            if ($rowCheck['status'] === 'cancelled') {
                echo json_encode(['status' => 'error', 'message' => 'Order already cancelled']);
                exit;
            }
            if ($rowCheck['status'] === 'delivered') {
                echo json_encode(['status' => 'error', 'message' => 'Order already delivered, cannot cancel']);
                exit;
            }
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
    }
    // DELIVER ORDER
    elseif (isset($input['delivered']) && $input['delivered'] == 1 && isset($input['fromDate'])) {
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
    
    $conn->close();
    exit;
}

// ============================================
// GET ORDERS: GET /api/orders (no id or order_number)
// ============================================
elseif ($method === 'GET' && !$orderId && !$orderNumber) {
    $userEmail = isset($decoded->email) ? $decoded->email : null;
    $orders = [];
    // If user_token is present in query, always treat as user request
    if (isset($_GET['user_token'])) {
        $userId = null;
        if (isset($decoded->id) && is_numeric($decoded->id)) {
            $userId = $decoded->id;
        } elseif (isset($decoded->sub) && is_numeric($decoded->sub)) {
            $userId = $decoded->sub;
        }
        if (!$userId) {
            if ($userEmail && trim($userEmail) !== '') {
                $sqlUser = "SELECT id FROM users WHERE email = ?";
                $stmtUser = $conn->prepare($sqlUser);
                $stmtUser->bind_param('s', $userEmail);
                $stmtUser->execute();
                $resultUser = $stmtUser->get_result();
                if ($rowUser = $resultUser->fetch_assoc()) {
                    $userId = $rowUser['id'];
                }
                $stmtUser->close();
            }
        }
        if (!$userId) {
            echo json_encode(['status' => 'error', 'message' => 'User id not found']);
            exit;
        }
        $sql = 'SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rowCount = 0;
        while ($row = $result->fetch_assoc()) {
            $rowCount++;
            $row['items'] = getOrderItemsWithCategoryInfo($row['items'], $conn);
            $orders[] = $row;
        }
        $stmt->close();
        if ($rowCount === 0) {
            echo json_encode([
                'status' => 'success',
                'message' => 'No orders found for this user',
                'orders' => [],
                'total_orders' => 0
            ]);
        } else {
            echo json_encode([
                'status' => 'success',
                'orders' => $orders,
                'total_orders' => $rowCount,
                'user_token' => $jwt
            ]);
        }
    } else {
        // Default: vendor logic
        $isVendor = (isset($decoded->sub) && is_numeric($decoded->sub)) || (isset($vendorId) && $vendorId !== null);
        if ($isVendor && $vendorId) {
            $sql = 'SELECT * FROM orders WHERE vendor_id = ? ORDER BY created_at DESC';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $vendorId);
            $stmt->execute();
            $result = $stmt->get_result();
            $rowCount = 0;
            while ($row = $result->fetch_assoc()) {
                $rowCount++;
                $row['items'] = getOrderItemsWithCategoryInfo($row['items'], $conn);
                // Fetch vendor info for this order
                $sqlVendor = "SELECT id, name, email, mobile FROM vendors WHERE id = ?";
                $stmtVendor = $conn->prepare($sqlVendor);
                $stmtVendor->bind_param('i', $row['vendor_id']);
                $stmtVendor->execute();
                $resultVendor = $stmtVendor->get_result();
                $vendorInfo = $resultVendor->fetch_assoc();
                $stmtVendor->close();
                $row['vendor_info'] = $vendorInfo;
                $orders[] = $row;
            }
            $stmt->close();
            if ($rowCount === 0) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'No orders found for this vendor',
                    'orders' => [],
                    'total_orders' => 0
                ]);
            } else {
                echo json_encode([
                    'status' => 'success',
                    'orders' => $orders,
                    'total_orders' => $rowCount,
                    'vendor_token' => $jwt
                ]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Vendor id not found']);
        }
    }
    $conn->close();
    exit;
}

// ============================================
// INVALID REQUEST
// ============================================
else {
    if ($method === 'POST') {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Missing id or order_number for update. Please provide ?id= or ?order_number= in URL.'
        ]);
    } elseif ($method === 'GET') {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Invalid GET request parameters.'
        ]);
    } else {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Invalid HTTP method: ' . $method
        ]);
    }
    $conn->close();
    exit;
}