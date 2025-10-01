<?php
// Vendor Meal CRUD API (POST, GET, PUT, DELETE) with image upload
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
    // Add meal (with images and item reference)
    $title = $_POST['title'] ?? null;
    $description = $_POST['description'] ?? null;
    $minQty = $_POST['minQty'] ?? null;
    $price = $_POST['price'] ?? null;
    $sampleAvailable = isset($_POST['sampleAvailable']) ? (($_POST['sampleAvailable'] === 'true' || $_POST['sampleAvailable'] === '1') ? 1 : 0) : 0;
    // Accept items as comma-separated string (e.g., 1,2,3) or JSON array
    $items = null;
    if (isset($_POST['items'])) {
        $rawItems = $_POST['items'];
        if (is_array($rawItems)) {
            $items = json_encode($rawItems);
        } else if (is_string($rawItems)) {
            // If comma-separated, convert to array
            if (strpos($rawItems, ',') !== false) {
                $arr = array_map('trim', explode(',', $rawItems));
                $items = json_encode($arr);
            } else if (preg_match('/^\[.*\]$/', $rawItems)) {
                // Looks like JSON array string
                $items = $rawItems;
            } else {
                $items = json_encode([$rawItems]);
            }
        }
    }
    $packagingDetails = $_POST['packagingDetails'] ?? null;
    $minPrepareOrderDays = $_POST['minPrepareOrderDays'] ?? null;
    $maxPrepareOrderDays = $_POST['maxPrepareOrderDays'] ?? null;
    // Images
    $boxImagePath = null;
    $actualImagePath = null;
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
    if (isset($_FILES['boxImage']) && $_FILES['boxImage']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['boxImage']['name'], PATHINFO_EXTENSION);
        $filename = 'meal_box_' . $vendorId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['boxImage']['tmp_name'], $targetPath)) {
            $boxImagePath = 'uploads/' . $filename;
        }
    }
    if (isset($_FILES['actualImage']) && $_FILES['actualImage']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['actualImage']['name'], PATHINFO_EXTENSION);
        $filename = 'meal_actual_' . $vendorId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['actualImage']['tmp_name'], $targetPath)) {
            $actualImagePath = 'uploads/' . $filename;
        }
    }
    $sql = "INSERT INTO vendor_meals (vendor_id, title, description, minQty, price, sampleAvailable, items, packagingDetails, minPrepareOrderDays, maxPrepareOrderDays, boxImage, actualImage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('issidissiiss', $vendorId, $title, $description, $minQty, $price, $sampleAvailable, $items, $packagingDetails, $minPrepareOrderDays, $maxPrepareOrderDays, $boxImagePath, $actualImagePath);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Meal created', 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
    }
    $stmt->close();
} else if ($method === 'GET') {
    // Get all meals for this vendor
    $sql = 'SELECT * FROM vendor_meals WHERE vendor_id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        // Add full image URLs
        foreach(['boxImage','actualImage'] as $imgField) {
            if (!empty($row[$imgField])) {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
                $row[$imgField.'_url'] = rtrim($baseUrl, '/') . '/' . ltrim($row[$imgField], '/');
            } else {
                $row[$imgField.'_url'] = null;
            }
        }
        // Decode items as array of strings/ints
        if (!empty($row['items'])) {
            $decoded = json_decode($row['items'], true);
            if (is_array($decoded)) {
                $row['items'] = $decoded;
            } else {
                $row['items'] = [];
            }
        } else {
            $row['items'] = [];
        }
        $rows[] = $row;
    }
    echo json_encode(['status' => 'success', 'meals' => $rows]);
    $stmt->close();
} else if ($method === 'PUT') {
    // Update meal (no image update via PUT)
    parse_str(file_get_contents('php://input'), $data);
    if (!isset($data['id'])) { echo json_encode(['status' => 'error', 'message' => 'id required']); exit; }
    $fields = ['title','description','minQty','price','sampleAvailable','items','packagingDetails','minPrepareOrderDays','maxPrepareOrderDays'];
    $set = [];
    $params = [];
    $types = '';
    foreach ($fields as $f) {
        if (isset($data[$f])) {
            $set[] = "$f = ?";
            if ($f === 'items') {
                $rawItems = $data[$f];
                if (is_array($rawItems)) {
                    $params[] = json_encode($rawItems);
                } else if (is_string($rawItems)) {
                    if (strpos($rawItems, ',') !== false) {
                        $arr = array_map('trim', explode(',', $rawItems));
                        $params[] = json_encode($arr);
                    } else if (preg_match('/^\[.*\]$/', $rawItems)) {
                        $params[] = $rawItems;
                    } else {
                        $params[] = json_encode([$rawItems]);
                    }
                } else {
                    $params[] = json_encode([]);
                }
            } else {
                $params[] = $data[$f];
            }
            if (in_array($f, ['minQty','minPrepareOrderDays','maxPrepareOrderDays'])) $types .= 'i';
            else if ($f === 'price') $types .= 'd';
            else if ($f === 'sampleAvailable') $types .= 'i';
            else $types .= 's';
        }
    }
    if (empty($set)) { echo json_encode(['status' => 'error', 'message' => 'No fields to update']); exit; }
    $params[] = $vendorId;
    $params[] = $data['id'];
    $types .= 'ii';
    $sql = 'UPDATE vendor_meals SET ' . implode(',', $set) . ' WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Meal updated']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $conn->error]);
    }
    $stmt->close();
} else if ($method === 'DELETE') {
    // Delete meal
    parse_str(file_get_contents('php://input'), $data);
    if (!isset($data['id'])) { echo json_encode(['status' => 'error', 'message' => 'id required']); exit; }
    $sql = 'DELETE FROM vendor_meals WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $vendorId, $data['id']);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Meal deleted']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed: ' . $conn->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
}
$conn->close();
?>
