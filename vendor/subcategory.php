<?php
// Vendor subcategory CRUD API (POST, GET, PUT, DELETE) with image upload
require __DIR__ . '/../../backend/composer/autoload.php';
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
    // Add subcategory (with image upload)
    $data = $_POST;
    $fields = ['categoryId','name','description','pricePerUnit','quantity','priceType','deliveryPriceEnabled','minDeliveryDays','maxDeliveryDays','deliveryPrice'];
    foreach ($fields as $f) {
        if (!isset($data[$f]) && $f !== 'deliveryPrice') {
            echo json_encode(['status' => 'error', 'message' => "$f required"]); exit;
        }
    }
    $imageUrl = null;
    if (isset($_FILES['imageUrl']) && $_FILES['imageUrl']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
        $ext = pathinfo($_FILES['imageUrl']['name'], PATHINFO_EXTENSION);
        $filename = 'subcategory_' . $vendorId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['imageUrl']['tmp_name'], $targetPath)) {
            $imageUrl = 'uploads/' . $filename;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Image upload failed']); exit;
        }
    }
    $sql = "INSERT INTO vendor_subcategories (vendor_id, category_id, name, description, pricePerUnit, imageUrl, quantity, priceType, deliveryPriceEnabled, minDeliveryDays, maxDeliveryDays, deliveryPrice) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iissssssiiid',
        $vendorId,
        $data['categoryId'],
        $data['name'],
        $data['description'],
        $data['pricePerUnit'],
        $imageUrl,
        $data['quantity'],
        $data['priceType'],
        $data['deliveryPriceEnabled'],
        $data['minDeliveryDays'],
        $data['maxDeliveryDays'],
        $data['deliveryPrice']
    );
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Subcategory added', 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
    }
    $stmt->close();
} else if ($method === 'GET') {
    // Get all subcategories for this vendor
    $sql = 'SELECT * FROM vendor_subcategories WHERE vendor_id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        // Add full image URL if imageUrl exists
        if (!empty($row['imageUrl'])) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
            $row['image_url'] = rtrim($baseUrl, '/') . '/' . ltrim($row['imageUrl'], '/');
        } else {
            $row['image_url'] = null;
        }
        $rows[] = $row;
    }
    echo json_encode(['status' => 'success', 'subcategories' => $rows]);
    $stmt->close();
} else if ($method === 'PUT') {
    // Update subcategory (no image update via PUT)
    parse_str(file_get_contents('php://input'), $data);
    if (!isset($data['id'])) { echo json_encode(['status' => 'error', 'message' => 'id required']); exit; }
    $fields = ['categoryId','name','description','pricePerUnit','quantity','priceType','deliveryPriceEnabled','minDeliveryDays','maxDeliveryDays','deliveryPrice'];
    $set = [];
    $params = [];
    $types = '';
    foreach ($fields as $f) {
        if (isset($data[$f])) {
            $set[] = "$f = ?";
            $params[] = $data[$f];
            $types .= is_numeric($data[$f]) && $f !== 'priceType' && $f !== 'name' && $f !== 'description' ? 'd' : 's';
        }
    }
    if (empty($set)) { echo json_encode(['status' => 'error', 'message' => 'No fields to update']); exit; }
    $params[] = $vendorId;
    $params[] = $data['id'];
    $types .= 'ii';
    $sql = 'UPDATE vendor_subcategories SET ' . implode(',', $set) . ' WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Subcategory updated']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $conn->error]);
    }
    $stmt->close();
} else if ($method === 'DELETE') {
    // Delete subcategory
    parse_str(file_get_contents('php://input'), $data);
    if (!isset($data['id'])) { echo json_encode(['status' => 'error', 'message' => 'id required']); exit; }
    $sql = 'DELETE FROM vendor_subcategories WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $vendorId, $data['id']);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Subcategory deleted']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed: ' . $conn->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
}
$conn->close();
?>
