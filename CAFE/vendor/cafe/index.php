<?php
header('Content-Type: application/json');
require_once __DIR__ . '/common_cafe/db.php';
require_once __DIR__ . '/common_cafe/vendor/autoload.php'; // include composer autoload

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$method = $_SERVER['REQUEST_METHOD'];
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

// JWT secret key (keep secure in env file in production)
$SECRET_KEY = 'YOUR_SUPER_SECRET_KEY_123';

// Extract Bearer token
$token = null;
if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $token = $matches[1];
}

// Function to verify JWT
function verifyJWT($token, $SECRET_KEY) {
    try {
        $decoded = JWT::decode($token, new Key($SECRET_KEY, 'HS256'));
        return (array)$decoded;
    } catch (Exception $e) {
        return null;
    }
}

$userData = null;
if ($token) {
    $userData = verifyJWT($token, $SECRET_KEY);
}

// --------------------- CRUD Operations ---------------------

if ($method === 'GET') {
    if ($userData) {
        // Vendor authenticated → show only their cafes
        $owner_id = $userData['vendor_id'];
        $stmt = $conn->prepare("SELECT * FROM cafes WHERE owner_id = ?");
        $stmt->bind_param('i', $owner_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $cafes = [];
        while ($row = $result->fetch_assoc()) $cafes[] = $row;
        echo json_encode([
            'status' => 'success',
            'filter' => 'vendor',
            'data' => $cafes
        ]);
        $stmt->close();
    } else {
        // Public request → show all cafes
        $result = $conn->query("SELECT * FROM cafes");
        $cafes = [];
        while ($row = $result->fetch_assoc()) $cafes[] = $row;
        echo json_encode([
            'status' => 'success',
            'filter' => 'public',
            'data' => $cafes
        ]);
    }
}
elseif ($method === 'POST') {
    if (!$userData) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit;
    }

    $name = $_POST['name'] ?? '';
    if (!$name) {
        echo json_encode(['status' => 'error', 'message' => 'Name required']); exit;
    }

    $owner_id = $userData['vendor_id'];
    $stmt = $conn->prepare("INSERT INTO cafes (name, owner_id) VALUES (?, ?)");
    $stmt->bind_param('si', $name, $owner_id);
    $stmt->execute();
    $cafe_id = $stmt->insert_id;
    $stmt->close();

    // External API registration
    $externalApiUrl = 'https://external-api.example.com/register-cafe'; // Change to actual API URL
    $payload = json_encode([
        'cafe_id' => $cafe_id,
        'name' => $name,
        'owner_id' => $owner_id
    ]);
    $ch = curl_init($externalApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $apiResponse = curl_exec($ch);
    $apiError = curl_error($ch);
    curl_close($ch);

    echo json_encode([
        'status' => 'success',
        'id' => $cafe_id,
        'external_api_response' => $apiResponse,
        'external_api_error' => $apiError
    ]);
}
elseif ($method === 'PUT') {
    if (!$userData) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit;
    }

    parse_str(file_get_contents('php://input'), $data);
    $id = $data['id'] ?? '';
    $name = $data['name'] ?? '';

    if (!$id || !$name) {
        echo json_encode(['status' => 'error', 'message' => 'ID and name required']); exit;
    }

    $owner_id = $userData['vendor_id'];
    $stmt = $conn->prepare("UPDATE cafes SET name=? WHERE id=? AND owner_id=?");
    $stmt->bind_param('sii', $name, $id, $owner_id);
    $stmt->execute();
    echo json_encode(['status' => 'success']);
    $stmt->close();
}
elseif ($method === 'DELETE') {
    if (!$userData) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit;
    }

    $id = $_GET['id'] ?? '';
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'ID required']); exit;
    }

    $owner_id = $userData['vendor_id'];
    $stmt = $conn->prepare("DELETE FROM cafes WHERE id=? AND owner_id=?");
    $stmt->bind_param('ii', $id, $owner_id);
    $stmt->execute();
    echo json_encode(['status' => 'success']);
    $stmt->close();
}

$conn->close();
?>
