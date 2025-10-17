

<?php
// vender/order.php
// API to place an order (POST) and get all orders (GET)

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
header('Content-Type: application/json');
require_once __DIR__ . '/../common_cafe/db.php'; // Adjust path as needed
require_once __DIR__ . '/../common_cafe/jwt_secret.php';
require_once __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set('Asia/Kolkata');
$method = $_SERVER['REQUEST_METHOD'];


// Confirm order endpoint (user confirms order, start 10 min timer, generate OTP)
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'confirm') {
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : null);
    // Debug log for received token
    file_put_contents(__DIR__ . '/../error.log', "[DEBUG] Received Authorization header: " . print_r($authHeader, true) . "\n", FILE_APPEND);
    if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
        file_put_contents(__DIR__ . '/../error.log', "[ERROR] Authorization token missing or format incorrect\n", FILE_APPEND);
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authorization token missing']);
        exit;
    }
    $jwt = $matches[1];
    try {
        // Debug log for JWT before decoding
        file_put_contents(__DIR__ . '/../error.log', "[DEBUG] JWT to decode: " . print_r($jwt, true) . "\n", FILE_APPEND);
        $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key(JWT_SECRET, 'HS256'));
        if (isset($decoded->user_id)) {
            $user_id = $decoded->user_id;
        } elseif (isset($decoded->sub)) {
            $user_id = $decoded->sub;
        } elseif (isset($decoded->data) && isset($decoded->data->user_id)) {
            $user_id = $decoded->data->user_id;
        } else {
            file_put_contents(__DIR__ . '/../error.log', "[ERROR] Token missing user_id field\n", FILE_APPEND);
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Token missing user_id']);
            exit;
        }
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/../error.log', "[ERROR] JWT decode failed: " . $e->getMessage() . "\n", FILE_APPEND);
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid token', 'error' => $e->getMessage()]);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || !isset($input['order_id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Order ID required']);
        exit;
    }
    $order_id = $input['order_id'];
    // Check if order exists and is pending
    $stmt = $conn->prepare('SELECT status FROM orders WHERE id = ?');
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if ($row['status'] !== 'pending') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Order already confirmed or not pending']);
            $stmt->close();
            exit;
        }
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Order not found', 'order_id' => $order_id, 'jwt_user_id' => $user_id]);
        $stmt->close();
        exit;
    }
    $stmt->close();
    // Check if vendor owns any subitem in this order
    $stmt = $conn->prepare('SELECT COUNT(*) as cnt FROM order_subitems os JOIN subitems s ON os.subitem_id = s.id WHERE os.order_id = ? AND s.vendor_id = ?');
    $stmt->bind_param('ii', $order_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if ($row['cnt'] == 0) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not authorized for this order (vendor does not own any subitem)', 'order_id' => $order_id, 'vendor_id' => $user_id]);
        exit;
    }
    // Set status to confirmed, set confirm_time to now, generate OTP, start 10 min timer
    $confirm_time = date('Y-m-d H:i:s');
    $otp = rand(100000, 999999);
    $conn->begin_transaction();
    $stmt = $conn->prepare('UPDATE orders SET status = ?, confirm_time = ?, otp = ? WHERE id = ? AND status = ?');
    $status = 'confirmed';
    $pending = 'pending';
    $stmt->bind_param('sssis', $status, $confirm_time, $otp, $order_id, $pending);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected > 0) {
        $conn->commit();
        echo json_encode(['status' => 'success', 'confirm_time' => $confirm_time, 'otp' => $otp]);
    } else {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to confirm order (no update)', 'order_id' => $order_id, 'vendor_id' => $user_id]);
    }
    exit;
}

// Show OTP endpoint (user or vendor can view OTP for order)
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'otp') {
    $order_id = isset($_GET['order_id']) ? $_GET['order_id'] : null;
    if (!$order_id) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Order ID required']);
        exit;
    }
    $stmt = $conn->prepare('SELECT otp, status, confirm_time FROM orders WHERE id = ?');
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['status' => 'success', 'otp' => $row['otp'], 'order_status' => $row['status'], 'confirm_time' => $row['confirm_time']]);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
    }
    $stmt->close();
    exit;
}

// Mark ready endpoint (vendor marks order as ready)
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'ready') {
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : null);
    if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authorization token missing']);
        exit;
    }
    $jwt = $matches[1];
    try {
        $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key(JWT_SECRET, 'HS256'));
        if (isset($decoded->user_id)) {
            $vendor_id = $decoded->user_id;
        } elseif (isset($decoded->sub)) {
            $vendor_id = $decoded->sub;
        } elseif (isset($decoded->data) && isset($decoded->data->user_id)) {
            $vendor_id = $decoded->data->user_id;
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Token missing user_id']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $order_id = isset($input['order_id']) ? $input['order_id'] : null;
    if (!$order_id) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Order ID required']);
        exit;
    }
    // Only allow if vendor owns at least one subitem in this order
    $stmt = $conn->prepare('SELECT COUNT(*) as cnt FROM order_subitems os JOIN subitems s ON os.subitem_id = s.id WHERE os.order_id = ? AND s.vendor_id = ?');
    $stmt->bind_param('ii', $order_id, $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row['cnt'] == 0) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not authorized for this order']);
        exit;
    }
    $stmt->close();
    // Mark order as ready
    $status = 'ready';
    $ready_time = date('Y-m-d H:i:s');
    $stmt = $conn->prepare('UPDATE orders SET status = ?, ready_time = ? WHERE id = ?');
    $stmt->bind_param('ssi', $status, $ready_time, $order_id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'ready_time' => $ready_time]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to mark ready']);
    }
    $stmt->close();
    exit;
}

if ($method === 'POST' && !isset($_GET['action'])) {
    // Place a new order
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : null);
    if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authorization token missing']);
        exit;
    }
    $jwt = $matches[1];
    try {
        $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key(JWT_SECRET, 'HS256'));
        // Debug log JWT payload
        file_put_contents(__DIR__ . '/../jwt_debug.log', print_r($decoded, true), FILE_APPEND);
        if (isset($decoded->user_id)) {
            $user_id = $decoded->user_id;
        } elseif (isset($decoded->sub)) {
            $user_id = $decoded->sub;
        } elseif (isset($decoded->data) && isset($decoded->data->user_id)) {
            $user_id = $decoded->data->user_id;
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Token missing user_id']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['items']) || !is_array($input['items'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }
    $items = $input['items']; // array of subitem_id and quantity
    $order_total = 0;
    $order_items = [];
    foreach ($items as $item) {
        if (!isset($item['subitem_id']) || !isset($item['quantity'])) continue;
        $subitem_id = $item['subitem_id'];
        $quantity = $item['quantity'];
        // Get subitem price
        $stmt = $conn->prepare('SELECT price FROM subitems WHERE id = ?');
        $stmt->bind_param('i', $subitem_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $price = $row['price'];
            $order_total += $price * $quantity;
            $order_items[] = ['subitem_id' => $subitem_id, 'quantity' => $quantity, 'price' => $price];
        }
        $stmt->close();
    }
    if (empty($order_items)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No valid items']);
        exit;
    }
    // Insert order with local date/time, status (no otp yet)
    $now = date('Y-m-d H:i:s');
    $status = 'pending';
    $stmt = $conn->prepare('INSERT INTO orders (user_id, total, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('idsss', $user_id, $order_total, $status, $now, $now);
    if ($stmt->execute()) {
        $order_id = $stmt->insert_id;
        $stmt->close();
        // Insert order subitems with local date/time
        $stmt = $conn->prepare('INSERT INTO order_subitems (order_id, subitem_id, quantity, price, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($order_items as $oi) {
            $stmt->bind_param('iiidss', $order_id, $oi['subitem_id'], $oi['quantity'], $oi['price'], $now, $now);
            $stmt->execute();
        }
        $stmt->close();
        echo json_encode(['status' => 'success', 'order_id' => $order_id]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Order failed']);
    }
    exit;
}

if ($method === 'GET') {
    // Get orders for the authenticated vendor only
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : null);
    if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authorization token missing']);
        exit;
    }
    $jwt = $matches[1];
    try {
        $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key(JWT_SECRET, 'HS256'));
        if (isset($decoded->user_id)) {
            $user_id = $decoded->user_id;
        } elseif (isset($decoded->sub)) {
            $user_id = $decoded->sub;
        } elseif (isset($decoded->data) && isset($decoded->data->user_id)) {
            $user_id = $decoded->data->user_id;
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Token missing user_id']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
        exit;
    }
    // Get all orders (not just by user_id)
    $stmt = $conn->prepare('SELECT * FROM orders ORDER BY created_at DESC');
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = [];
    while ($order = $result->fetch_assoc()) {
        // Get only subitems for this vendor
        $stmt_items = $conn->prepare('SELECT os.subitem_id, os.quantity, os.price, s.item_id, s.name FROM order_subitems os JOIN subitems s ON os.subitem_id = s.id WHERE os.order_id = ? AND s.vendor_id = ?');
        $stmt_items->bind_param('ii', $order['id'], $user_id);
        $stmt_items->execute();
        $items_result = $stmt_items->get_result();
        $subitems = [];
        while ($item = $items_result->fetch_assoc()) {
            $subitems[] = $item;
        }
        $stmt_items->close();
        if (count($subitems) > 0) {
            $order['subitems'] = $subitems;
            $orders[] = $order;
        }
    }
    $stmt->close();
    echo json_encode(['status' => 'success', 'orders' => $orders]);
    exit;
}
// Deliver endpoint (vendor marks order as delivered after OTP verification)
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'deliver') {
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : null);
    if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authorization token missing']);
        exit;
    }
    $jwt = $matches[1];
    try {
        $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key(JWT_SECRET, 'HS256'));
        if (isset($decoded->user_id)) {
            $vendor_id = $decoded->user_id;
        } elseif (isset($decoded->sub)) {
            $vendor_id = $decoded->sub;
        } elseif (isset($decoded->data) && isset($decoded->data->user_id)) {
            $vendor_id = $decoded->data->user_id;
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Token missing user_id']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $order_id = isset($input['order_id']) ? $input['order_id'] : null;
    $otp = isset($input['otp']) ? $input['otp'] : null;
    if (!$order_id || !$otp) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Order ID and OTP required']);
        exit;
    }
    // Only allow if vendor owns at least one subitem in this order
    $stmt = $conn->prepare('SELECT COUNT(*) as cnt FROM order_subitems os JOIN subitems s ON os.subitem_id = s.id WHERE os.order_id = ? AND s.vendor_id = ?');
    $stmt->bind_param('ii', $order_id, $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if ($row['cnt'] == 0) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not authorized for this order']);
        exit;
    }
    // Verify OTP
    $stmt = $conn->prepare('SELECT otp, status FROM orders WHERE id = ?');
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($order = $result->fetch_assoc()) {
        if ($order['status'] !== 'ready') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Order not ready for delivery']);
            $stmt->close();
            exit;
        }
        if ($order['otp'] != $otp) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid OTP']);
            $stmt->close();
            exit;
        }
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        $stmt->close();
        exit;
    }
    $stmt->close();
    // Mark order as delivered
    $delivered_time = date('Y-m-d H:i:s');
    $status = 'delivered';
    // Set status, delivered_time, and remove OTP
    $stmt = $conn->prepare('UPDATE orders SET status = ?, delivered_time = ?, otp = NULL WHERE id = ?');
    $stmt->bind_param('ssi', $status, $delivered_time, $order_id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'delivered_time' => $delivered_time]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to mark delivered']);
    }
    $stmt->close();
    exit;
} 

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
