<?php
// Vendor Item CRUD API (POST, GET, PUT, DELETE) with image upload
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


$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    // Add vendor item (with image upload)
    $name = $_POST['name'] ?? null;
    $description = $_POST['description'] ?? null;
    $cost = $_POST['cost'] ?? null;
    if (!$name || !$cost) {
        echo json_encode(['status' => 'error', 'message' => 'name and cost required']); exit;
    }
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = 'vendor_' . $vendorId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            $imagePath = 'uploads/' . $filename;
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Image upload failed',
                'error' => $_FILES['image']['error'],
                'targetPath' => $targetPath,
                'is_dir' => is_dir($uploadDir),
                'writable' => is_writable($uploadDir),
                '_FILES' => $_FILES,
                '_POST' => $_POST
            ]);
            exit;
        }
    } else if (!isset($_FILES['image'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No image field in request',
            '_FILES' => $_FILES,
            '_POST' => $_POST
        ]);
        exit;
    } else if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Image upload error',
            'error' => $_FILES['image']['error'],
            '_FILES' => $_FILES,
            '_POST' => $_POST
        ]);
        exit;
    }
    $sql = "INSERT INTO items (vendor_id, name, description, cost, image) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('issds', $vendorId, $name, $description, $cost, $imagePath);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Item created', 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
    }
    $stmt->close();
} else if ($method === 'GET') {
    // Get all vendor items
    $sql = 'SELECT * FROM items WHERE vendor_id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        // Add full image URL if image exists
        if (!empty($row['image'])) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
            $row['image_url'] = rtrim($baseUrl, '/') . '/' . ltrim($row['image'], '/');
        } else {
            $row['image_url'] = null;
        }
        $rows[] = $row;
    }
    echo json_encode(['status' => 'success', 'items' => $rows]);
    $stmt->close();
} else if ($method === 'PUT') {
    // Update vendor item (no image update via PUT)
    parse_str(file_get_contents('php://input'), $data);
    if (!isset($data['id'])) { echo json_encode(['status' => 'error', 'message' => 'id required']); exit; }
    $fields = ['name','description','cost'];
    $set = [];
    $params = [];
    $types = '';
    foreach ($fields as $f) {
        if (isset($data[$f])) {
            $set[] = "$f = ?";
            $params[] = $data[$f];
            $types .= ($f === 'cost') ? 'd' : 's';
        }
    }
    if (empty($set)) { echo json_encode(['status' => 'error', 'message' => 'No fields to update']); exit; }
    $params[] = $vendorId;
    $params[] = $data['id'];
    $types .= 'ii';
    $sql = 'UPDATE items SET ' . implode(',', $set) . ' WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Item updated']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $conn->error]);
    }
    $stmt->close();
} else if ($method === 'DELETE') {
    // Delete vendor item
    parse_str(file_get_contents('php://input'), $data);
    if (!isset($data['id'])) { echo json_encode(['status' => 'error', 'message' => 'id required']); exit; }
    $sql = 'DELETE FROM items WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $vendorId, $data['id']);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Item deleted']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed: ' . $conn->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
}
$conn->close();
?>
