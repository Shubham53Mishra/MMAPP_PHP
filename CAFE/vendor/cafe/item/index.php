<?php
// item resource CRUD with timestamps
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
require_once __DIR__ . '/../../../vendor/autoload.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../../common_cafe/db.php';
require_once __DIR__ . '/../../../common_cafe/jwt_secret.php';

// Set timezone to India (IST)
date_default_timezone_set('Asia/Kolkata');

// Helper: Get Bearer token from Authorization header
function getBearerToken() {
    $header = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ($header && preg_match('/Bearer\s(.*)/', $header, $matches)) {
        return $matches[1];
    }
    return null;
}

// Helper: Validate vendor/user token (JWT)
function validateToken($token) {
    require_once __DIR__ . '/../../../vendor/firebase/php-jwt/src/JWT.php';
    require_once __DIR__ . '/../../../vendor/firebase/php-jwt/src/Key.php';
    $secret = JWT_SECRET;
    try {
        $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
        if (isset($decoded->sub)) {
            return [
                'type' => 'vendor',
                'vendor_id' => $decoded->sub
            ];
        } elseif (isset($decoded->user_id)) {
            return [
                'type' => 'user',
                'user_id' => $decoded->user_id
            ];
        }
    } catch (Exception $e) {
        return false;
    }
    return false;
}

$method = $_SERVER['REQUEST_METHOD'];
// Token logic: only require for POST/PUT/DELETE
$token = getBearerToken();
if (!$token && isset($_POST['token'])) {
    $token = $_POST['token'];
}
$auth_info = $token ? validateToken($token) : false;

if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if ($auth_info !== false && isset($auth_info['type']) && $auth_info['type'] === 'vendor') {
        $vendor_id = $auth_info['vendor_id'];
        if ($id) {
            $stmt = $conn->prepare("SELECT * FROM items WHERE id=? AND vendor_id=?");
            $stmt->bind_param('ii', $id, $vendor_id);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_assoc());
            $stmt->close();
        } else {
            $stmt = $conn->prepare("SELECT * FROM items WHERE vendor_id=?");
            $stmt->bind_param('i', $vendor_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $items = [];
            while ($row = $result->fetch_assoc()) $items[] = $row;
            echo json_encode($items);
            $stmt->close();
        }
    } else {
        // No token or user token: show all items
        if ($id) {
            $stmt = $conn->prepare("SELECT * FROM items WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_assoc());
            $stmt->close();
        } else {
            $result = $conn->query("SELECT * FROM items");
            $items = [];
            while ($row = $result->fetch_assoc()) $items[] = $row;
            echo json_encode($items);
        }
    }
}
elseif ($method === 'POST') {
    if ($auth_info === false || $auth_info === null || !isset($auth_info['type']) || $auth_info['type'] !== 'vendor') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized: Invalid or missing token']);
        exit;
    }
    $name = $_POST['name'] ?? '';
    $vendor_id = $auth_info['vendor_id'];
    if (!$vendor_id || !$name) {
        echo json_encode(['status'=>'error','message'=>'Vendor token and name required']); exit;
    }
    $created_at = date('Y-m-d H:i:s');
    // Save vendor_id in items table
    $stmt = $conn->prepare("INSERT INTO items (vendor_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $vendor_id, $name, $created_at, $created_at);
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$stmt->insert_id]);
    $stmt->close();
}
elseif ($method === 'PUT') {
    if ($auth_info === false || $auth_info === null || !isset($auth_info['type']) || $auth_info['type'] !== 'vendor') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized: Invalid or missing token']);
        exit;
    }
    parse_str(file_get_contents('php://input'), $data);
    $id = $data['id'] ?? '';
    $name = $data['name'] ?? '';
    if (!$id || !$name) {
        echo json_encode(['status'=>'error','message'=>'ID and name required']); exit;
    }
    $updated_at = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE items SET name=?, updated_at=? WHERE id=?");
    $stmt->bind_param('ssi', $name, $updated_at, $id);
    $stmt->execute();
    echo json_encode(['status'=>'success']);
    $stmt->close();
}
elseif ($method === 'DELETE') {
    if ($auth_info === false || $auth_info === null || !isset($auth_info['type']) || $auth_info['type'] !== 'vendor') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized: Invalid or missing token']);
        exit;
    }
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['status'=>'error','message'=>'ID required']); exit; }
    $stmt = $conn->prepare("DELETE FROM items WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['status'=>'success']);
    $stmt->close();
}
$conn->close();
?>