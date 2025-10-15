<?php
// Vendor Category GET API with JWT authentication, returns vendor info and categories
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

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if ($method === 'POST') {
    // Add new category with image upload, NO token or Authorization required
    $data = $_POST;
    if (!isset($data['name'])) {
        echo json_encode(['status' => 'error', 'message' => 'name required']); exit;
    }
    $vendorId = isset($data['vendor_id']) ? intval($data['vendor_id']) : null;
    if (!$vendorId) {
        echo json_encode(['status' => 'error', 'message' => 'vendor_id required']); exit;
    }
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/vendor_' . $vendorId . '/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = 'category_' . $vendorId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            $imagePath = 'uploads/vendor_' . $vendorId . '/' . $filename;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Image upload failed']); exit;
        }
    }
    $sql = "INSERT INTO categories (name, image, vendor_id) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $data['name'], $imagePath, $vendorId);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Category added', 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
    }
    $stmt->close();
    $conn->close();
    exit;
}

if ($method === 'GET') {
    // If ?all=1, return all categories for user (no token required)
    if (isset($_GET['all']) && $_GET['all'] == '1') {
        $sql = "SELECT * FROM categories";
        $result = $conn->query($sql);
        $categories = [];
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/';
        while ($row = $result->fetch_assoc()) {
            $row['image_url'] = !empty($row['image']) ? ($baseUrl . $row['image']) : null;
            $categories[] = $row;
        }
        $conn->close();
        echo json_encode(['status' => 'success', 'categories' => $categories]);
        exit;
    }

    // Default: vendor GET (token required)
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
    // Get vendor info
    $sql = "SELECT id, name, email, mobile FROM vendors WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $vendor = $result->fetch_assoc();
    $stmt->close();
    // Get categories for this vendor
    $sql = "SELECT * FROM categories WHERE vendor_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $categories = [];
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/';
    while ($row = $result->fetch_assoc()) {
        $row['image_url'] = !empty($row['image']) ? ($baseUrl . $row['image']) : null;
        $categories[] = $row;
    }
    $stmt->close();
    // If no categories, add 10 hardcoded food names and save to DB
    if (empty($categories)) {
        $foods = [
            'Pizza', 'Burger', 'Pasta', 'Sandwich', 'Biryani', 'Dosa', 'Samosa', 'Chowmein', 'Paneer Tikka', 'Chole Bhature'
        ];
        $inserted = [];
        $sqlInsert = "INSERT INTO categories (name, vendor_id) VALUES (?, ?)";
        $stmtInsert = $conn->prepare($sqlInsert);
        foreach ($foods as $food) {
            $stmtInsert->bind_param('si', $food, $vendorId);
            if ($stmtInsert->execute()) {
                $inserted[] = [
                    'id' => $stmtInsert->insert_id,
                    'name' => $food,
                    'vendor_id' => $vendorId
                ];
            }
        }
        $stmtInsert->close();
        $categories = $inserted;
    }
    // Save the response in api_responses table
    $responseData = json_encode(['status' => 'success', 'vendor' => $vendor, 'categories' => $categories]);
    // Create table if not exists (id, vendor_id, response, created_at)
    $sqlCreate = "CREATE TABLE IF NOT EXISTS api_responses (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT, response TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)";
    $conn->query($sqlCreate);
    // Insert response
    $sqlResp = "INSERT INTO api_responses (vendor_id, response) VALUES (?, ?)";
    $stmtResp = $conn->prepare($sqlResp);
    $stmtResp->bind_param('is', $vendorId, $responseData);
    $stmtResp->execute();
    $stmtResp->close();
    $conn->close();
    echo $responseData;
    exit;
}
?>
