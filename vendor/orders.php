<?php
// Vendor Orders API: create, confirm, cancel, deliver, get
require __DIR__ . '/../../composer/autoload.php';
include '../common/db.php';
include '../common/jwt_secret.php';

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
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && !$orderId) {
    // Create order
    $input = json_decode(file_get_contents('php://input'), true);
    $items = [];
    if (isset($input['items']) && is_array($input['items'])) {
        foreach ($input['items'] as $item) {
            // Only allow item orders (no mealbox key)
            if (!isset($item['mealbox'])) {
                $items[] = [
                    'type' => 'item',
                    'category' => $item['category'] ?? null,
                    'subCategory' => $item['subCategory'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'deliveryDays' => $item['deliveryDays'] ?? null
                ];
            }
        }
    }
    if (empty($items)) {
        echo json_encode(['status' => 'error', 'message' => 'items required (no mealbox allowed)']); exit;
    }
    $itemsJson = json_encode($items);
    $sql = "INSERT INTO orders (vendor_id, items, status, created_at) VALUES (?, ?, 'pending', NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $vendorId, $itemsJson);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'order_id' => $conn->insert_id]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
    }
    $stmt->close();
} else if ($method === 'GET' && !$orderId) {
    // Get all orders for vendor
    $sql = 'SELECT * FROM orders WHERE vendor_id = ? ORDER BY created_at DESC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['items'] = $row['items'] ? json_decode($row['items'], true) : [];
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
        $sql = "UPDATE orders SET status='confirmed', deliveryTime=?, deliveryDate=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssii', $input['deliveryTime'], $input['deliveryDate'], $vendorId, $orderId);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Order confirmed']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $conn->error]);
        }
        $stmt->close();
    // Cancel order
    } else if (isset($input['reason'])) {
        $sql = "UPDATE orders SET status='cancelled', cancelReason=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $input['reason'], $vendorId, $orderId);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Order cancelled']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Cancel failed: ' . $conn->error]);
        }
        $stmt->close();
    // Deliver order
    } else if (isset($input['delivered']) && $input['delivered'] == 1 && isset($input['fromDate'])) {
        $sql = "UPDATE orders SET status='delivered', delivered_at=? WHERE vendor_id=? AND id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $input['fromDate'], $vendorId, $orderId);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Order delivered']);
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
