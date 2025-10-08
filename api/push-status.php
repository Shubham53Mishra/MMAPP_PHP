<?php
// push-status.php
// Usage: push-status.php?orderId=123&type=order|mealbox&status=STATUS

// This script connects to the WebSocket server and pushes a status update

$orderId = isset($_GET['orderId']) ? intval($_GET['orderId']) : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

if (!$orderId || !$type || !$status) {
    http_response_code(400);
    echo 'Missing parameters';
    exit;
}

$wsHost = '127.0.0.1';
$wsPort = 8080;
$wsUrl = "ws://$wsHost:$wsPort";

// Use Ratchet's websocket client (or fallback to a simple PHP WebSocket client)
// We'll use text sockets for simplicity
$payload = json_encode([
    'broadcast' => true,
    'orderId' => $orderId,
    'type' => $type,
    'status' => $status
]);

$fp = fsockopen($wsHost, $wsPort, $errno, $errstr, 2);
if (!$fp) {
    echo "Could not connect to WebSocket server: $errstr ($errno)\n";
    exit;
}

// Simple handshake (not a full WebSocket handshake, for demo only)
fwrite($fp, $payload . "\n");
fclose($fp);
echo 'Status pushed';
