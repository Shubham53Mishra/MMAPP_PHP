<?php
// WebSocket server for real-time order/mealbox status updates
// Fix: Use correct Composer autoload path for this project
require __DIR__ . '/vendor/autoload.php';
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class OrderStatusWsServer implements MessageComponentInterface {
    protected $clients;
    protected $orderClients; // orderId => [connections]

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->orderClients = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "[WS] Client connected: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!is_array($data)) return;

        // Client subscription: { "subscribe": true, "orderId": "MMF..." }
        if (isset($data['subscribe']) && isset($data['orderId'])) {
            $orderId = (string)$data['orderId'];
            $from->orderId = $orderId;
            if (!isset($this->orderClients[$orderId])) {
                $this->orderClients[$orderId] = [];
            }
            $this->orderClients[$orderId][$from->resourceId] = $from;
            return;
        }

        // Broadcast message from internal push script: { "broadcast": true, "orderId": "MMF..", "type": "order|mealbox", "status": "processing", ... }
        if (!empty($data['broadcast']) && isset($data['orderId'])) {
            $orderId = (string)$data['orderId'];
            $status = isset($data['status']) ? $data['status'] : null;
            $type = isset($data['type']) ? $data['type'] : 'order';

            // Build event name
            $event = ($type === 'mealbox' || strtolower($type) === 'meal') ? 'mealboxOrderTrackingUpdated' : 'orderTrackingUpdated';

            // prepare payload to send to clients
            $payload = [
                'event' => $event,
                'data' => [
                    'order_number' => $orderId,
                    'status' => $status,
                ],
            ];

            $this->broadcastStatus($orderId, $payload);
            return;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        if (isset($conn->orderId) && isset($this->orderClients[$conn->orderId][$conn->resourceId])) {
            unset($this->orderClients[$conn->orderId][$conn->resourceId]);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }

    // Call this from your PHP API to broadcast status
    public function broadcastStatus($orderId, $status) {
        if (isset($this->orderClients[$orderId])) {
            $payload = is_array($status) ? $status : ['event' => 'orderTrackingUpdated', 'data' => ['order_number' => $orderId, 'status' => $status]];
            $json = json_encode($payload);
            foreach ($this->orderClients[$orderId] as $client) {
                $client->send($json);
            }
        }
    }
}

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new OrderStatusWsServer()
        )
    ),
    8080 // Port
);

echo "[WS] WebSocket server started on ws://localhost:8080\n";
$server->run();
