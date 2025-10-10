<?php
// User Profile API (GET = fetch profile, POST = update profile)
// Supports image, address, city, state, pincode, label

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

$method = $_SERVER['REQUEST_METHOD'];

// Get JWT token
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
    echo json_encode(['status' => 'error', 'message' => 'Authorization token required']);
    exit;
}

$jwt = $matches[1];

try {
    $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
    $userId = $decoded->sub;
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
    exit;
}

// =======================================================
// ✅ GET PROFILE
// =======================================================
if ($method === 'GET') {
    // Fetch user profile
    $sql = "SELECT id, name, email, mobile, image, created_at FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $scriptDir = ($scriptDir === '/' || $scriptDir === '\\') ? '' : rtrim($scriptDir, '/');
        if (!empty($row['image'])) {
            $row['image_url'] = rtrim($baseUrl, '/') . $scriptDir . '/' . ltrim($row['image'], '/');
        } else {
            $row['image_url'] = null;
        }
        // Fetch all addresses for this user
        $sql2 = "SELECT id, address, city, state, pincode, label FROM user_addresses WHERE user_id = ? ORDER BY id DESC";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param('i', $userId);
        $stmt2->execute();
        $addresses = [];
        $result2 = $stmt2->get_result();
        while ($a = $result2->fetch_assoc()) {
            $addresses[] = [
                'id' => $a['id'],
                'addressLine' => $a['address'],
                'city' => $a['city'],
                'state' => $a['state'],
                'pincode' => $a['pincode'],
                'label' => $a['label']
            ];
        }
        $row['addresses'] = $addresses;
        $stmt2->close();
        echo json_encode(['status' => 'success', 'user' => $row]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
    }
    $stmt->close();
    $conn->close();
    exit;
}

// =======================================================
// ✅ POST UPDATE PROFILE
// =======================================================
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    // Profile image update (optional)
    $dbPath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/upload/profile/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $fileName = 'profile_' . $userId . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . $fileName;
        $dbPath = 'upload/profile/' . $fileName;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $filePath)) {
            echo json_encode(['status' => 'error', 'message' => 'Image upload failed']);
            exit;
        }
    }
    // If image, update users table
    if ($dbPath !== null) {
        $sql = "UPDATE users SET image=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $dbPath, $userId);
        $stmt->execute();
        $stmt->close();
    }
    // Address logic
    $addressId = isset($input['id']) ? intval($input['id']) : 0;
    $address = $input['addressLine'] ?? null;
    $city = $input['city'] ?? null;
    $state = $input['state'] ?? null;
    $pincode = $input['pincode'] ?? null;
    $label = $input['label'] ?? null;
    if ($address && $city && $state && $pincode) {
        if ($addressId > 0) {
            // Edit existing address
            $sql = "UPDATE user_addresses SET address=?, city=?, state=?, pincode=?, label=? WHERE id=? AND user_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssssiii', $address, $city, $state, $pincode, $label, $addressId, $userId);
            $stmt->execute();
            $stmt->close();
            $msg = 'Address updated';
        } else {
            // Add new address
            $sql = "INSERT INTO user_addresses (user_id, address, city, state, pincode, label) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isssss', $userId, $address, $city, $state, $pincode, $label);
            $stmt->execute();
            $stmt->close();
            $msg = 'Address added';
        }
        echo json_encode(['status' => 'success', 'message' => $msg]);
        $conn->close();
        exit;
    } else if ($dbPath !== null) {
        // Only image updated
        echo json_encode(['status' => 'success', 'message' => 'Profile image updated']);
        $conn->close();
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No address or image data provided']);
        $conn->close();
        exit;
    }
}

// Invalid request
echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
exit;
?>
