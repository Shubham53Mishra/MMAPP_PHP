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
else if ($method === 'POST' && !$orderId) {
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

    $sql = "INSERT INTO orders (vendor_id, items, vendor_email, status, created_at, order_date) VALUES (?, ?, ?, 'pending', NOW(), NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $bodyVendorId, $itemsJson, $vendorEmail);
    if ($stmt->execute()) {
        $orderId = $conn->insert_id;
        // Fetch vendor info using vendor_id from body (if provided)
        $sqlVendor = "SELECT id, name, email, phone FROM vendors WHERE id = ?";
        $stmtVendor = $conn->prepare($sqlVendor);
        $stmtVendor->bind_param('i', $bodyVendorId);
        $stmtVendor->execute();
        $resultVendor = $stmtVendor->get_result();
        $vendorInfo = $resultVendor->fetch_assoc();
        $stmtVendor->close();

        // Fetch user info using user_id from token only
        $userId = isset($decoded->user_id) ? $decoded->user_id : null;
        $userInfo = null;
        if ($userId) {
            $sqlUser = "SELECT id, name, email, phone FROM users WHERE id = ?";
            $stmtUser = $conn->prepare($sqlUser);
            $stmtUser->bind_param('i', $userId);
            $stmtUser->execute();
            $resultUser = $stmtUser->get_result();
            $userInfo = $resultUser->fetch_assoc();
            $stmtUser->close();
        }

        echo json_encode([
            'status' => 'success',
            'order_id' => $orderId,
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
} else if ($method === 'GET' && !$orderId) {
    // Get all orders for vendor
    // Optional category/subCategory filter from query params
    $category = $_GET['category'] ?? null;
    $subCategory = $_GET['subCategory'] ?? null;
    $sql = 'SELECT * FROM orders WHERE vendor_id = ? ORDER BY created_at DESC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['items'] = $row['items'] ? json_decode($row['items'], true) : [];
        // Enrich each item with category/subCategory info from items table if item id exists
        foreach ($row['items'] as &$item) {
            if (isset($item['id'])) {
                    // Only enrich if columns exist in items table
                    $sqlItem = "SHOW COLUMNS FROM items LIKE 'category'";
                    $resultCol = $conn->query($sqlItem);
                    if ($resultCol && $resultCol->num_rows > 0) {
                        $sqlItem = "SELECT category, subCategory FROM items WHERE id = ?";
                        $stmtItem = $conn->prepare($sqlItem);
                        $stmtItem->bind_param('i', $item['id']);
                        $stmtItem->execute();
                        $resultItem = $stmtItem->get_result();
                        if ($itemRow = $resultItem->fetch_assoc()) {
                            $item['category_id'] = $itemRow['category'];
                            $item['subCategory_id'] = $itemRow['subCategory'];
                            // Remove old fields if present
                            if (isset($item['category'])) unset($item['category']);
                            if (isset($item['subCategory'])) unset($item['subCategory']);
                            // Fetch category info
                            $item['category_info'] = null;
                            $item['subCategory_info'] = null;
                            if (!empty($itemRow['category'])) {
                                $sqlCat = "SELECT * FROM category WHERE id = ?";
                                $stmtCat = $conn->prepare($sqlCat);
                                $stmtCat->bind_param('i', $itemRow['category']);
                                $stmtCat->execute();
                                $resultCat = $stmtCat->get_result();
                                if ($catRow = $resultCat->fetch_assoc()) {
                                    $item['category_info'] = $catRow;
                                }
                                $stmtCat->close();
                            }
                            if (!empty($itemRow['subCategory'])) {
                                $sqlSubCat = "SELECT * FROM subcategory WHERE id = ?";
                                $stmtSubCat = $conn->prepare($sqlSubCat);
                                $stmtSubCat->bind_param('i', $itemRow['subCategory']);
                                $stmtSubCat->execute();
                                $resultSubCat = $stmtSubCat->get_result();
                                if ($subCatRow = $resultSubCat->fetch_assoc()) {
                                    $item['subCategory_info'] = $subCatRow;
                                }
                                $stmtSubCat->close();
                            }
                        }
                        $stmtItem->close();
                    }
            }
        }
        unset($item); // break reference
        // Only show orders where at least one item matches vendor token and category/subCategory
        $found = false;
        foreach ($row['items'] as $item) {
            if (
                (isset($item['vendor_id']) && $item['vendor_id'] == $vendorId) &&
                ($category === null || (isset($item['category']) && $item['category'] == $category)) &&
                ($subCategory === null || (isset($item['subCategory']) && $item['subCategory'] == $subCategory))
            ) {
                $found = true;
                break;
            }
        }
        if (!$found) continue;
            // Always include vendor_id and vendor_email in each order
            $row['vendor_id'] = $row['vendor_id'];
            $row['vendor_email'] = $row['vendor_email'];
        $rows[] = $row;
    }
    echo json_encode(['status' => 'success', 'orders' => $rows]);
    $stmt->close();
} else if ($method === 'GET' && $orderId) {
    // Get single order
    $sql = 'SELECT * FROM orders WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $vendorId, $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        $row['items'] = $row['items'] ? json_decode($row['items'], true) : [];
        echo json_encode(['status' => 'success', 'order' => $row]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
    }
    $stmt->close();
} else if ($method === 'PUT' && $orderId) {
    $input = json_decode(file_get_contents('php://input'), true);
    // Confirm order
        if (isset($input['deliveryTime']) && isset($input['deliveryDate'])) {
            $sql = "UPDATE orders SET status='confirmed', delivery_time=?, delivery_date=? WHERE vendor_id=? AND id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssii', $input['deliveryTime'], $input['deliveryDate'], $vendorId, $orderId);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Order confirmed']);
                sendOrderUpdateToWebSocket($orderId, 'confirmed', $vendorId);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $conn->error]);
            }
            $stmt->close();
    // Cancel order
    } else if (isset($input['reason'])) {
        $sql = "UPDATE orders SET status='cancelled', cancel_reason=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $input['reason'], $vendorId, $orderId);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Order cancelled']);
            sendOrderUpdateToWebSocket($orderId, 'cancelled', $vendorId);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Cancel failed: ' . $conn->error]);
        }
        $stmt->close();
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
        $sql = "UPDATE orders SET status='delivered', delivery_date=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $input['fromDate'], $vendorId, $orderId);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Order delivered']);
            sendOrderUpdateToWebSocket($orderId, 'delivered', $vendorId);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Deliver failed: ' . $conn->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid update body']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method or missing id']);
}
$conn->close();
?>
