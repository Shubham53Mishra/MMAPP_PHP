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

if ($method === 'POST') {
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
    // Insert order with local date/time
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare('INSERT INTO orders (user_id, total, created_at, updated_at) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('idss', $user_id, $order_total, $now, $now);
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
    $stmt = $conn->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = [];
    while ($order = $result->fetch_assoc()) {
        // Get order subitems
        $stmt_items = $conn->prepare('SELECT subitem_id, quantity, price FROM order_subitems WHERE order_id = ?');
        $stmt_items->bind_param('i', $order['id']);
        $stmt_items->execute();
        $items_result = $stmt_items->get_result();
        $subitems = [];
        while ($item = $items_result->fetch_assoc()) {
            $subitems[] = $item;
        }
        $stmt_items->close();
        $order['subitems'] = $subitems;
        $orders[] = $order;
    }
    $stmt->close();
    echo json_encode(['status' => 'success', 'orders' => $orders]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
