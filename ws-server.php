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
        if (isset($data['subscribe']) && isset($data['orderId'])) {
            $orderId = $data['orderId'];
            $from->orderId = $orderId;
            if (!isset($this->orderClients[$orderId])) {
                $this->orderClients[$orderId] = [];
            }
            $this->orderClients[$orderId][$from->resourceId] = $from;
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
            foreach ($this->orderClients[$orderId] as $client) {
                $client->send(json_encode(['orderId' => $orderId, 'status' => $status]));
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
