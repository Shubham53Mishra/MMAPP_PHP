<?php
// push-status.php
// Usage: push-status.php?orderId=MMF...&type=order|mealbox&status=STATUS

// This script connects to the WebSocket server and pushes a status update.
// It uses the textalk/websocket client. Install with:
// composer require textalk/websocket

require_once __DIR__ . '/../vendor/autoload.php';

if (!class_exists('WebSocket\\Client')) {
    // Try alternative autoload path if running from a different directory
    $altAutoload = __DIR__ . '/../../vendor/autoload.php';
    if (file_exists($altAutoload)) {
        require_once $altAutoload;
    }
}

// Ensure the use statement is after autoloaders
use WebSocket\Client;


header('Content-Type: application/json');
$orderId = isset($_GET['orderId']) ? $_GET['orderId'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

if ($orderId === '' || $type === '' || $status === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing parameters: orderId, type, status required']);
    exit;
}

$wsHost = '127.0.0.1';
$wsPort = 8080;
$wsUrl = "ws://$wsHost:$wsPort";

$payload = json_encode([
    'broadcast' => true,
    'orderId' => $orderId,
    'type' => $type,
    'status' => $status
]);

try {
    // Ensure the client class exists
    if (!class_exists('WebSocket\\Client')) {
        throw new Exception('WebSocket client library not installed. Run: composer require textalk/websocket');
    }
    // Create a WebSocket client and send payload
    $client = new Client($wsUrl, ['timeout' => 3]);
    $client->send($payload);
    $client->close();
    echo json_encode(['success' => true, 'message' => 'Status pushed']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not push status', 'error' => $e->getMessage()]);
}

?>
