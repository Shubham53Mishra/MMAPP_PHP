<?php
// Vendor Item CRUD API (POST, GET, PUT, DELETE) with image upload
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

// GET method - no authentication required, but filters by vendor if token provided
if ($method === 'GET') {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    $vendorId = null;

    // Check if vendor token is provided
    if ($authHeader && preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
        $jwt = $matches[1];
        try {
            $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
            $vendorId = $decoded->sub;
        } catch (Exception $e) {
            // Invalid token - ignore and show all items
            $vendorId = null;
        }
    }

    // Build SQL query based on vendor authentication
    if ($vendorId) {
        // Vendor is authenticated - show only their items
        $sql = 'SELECT * FROM items WHERE vendor_id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $vendorId);
    } else {
        // No vendor token - show all items
        $sql = 'SELECT * FROM items';
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    // Base URL for images
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
               "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);

    while ($row = $result->fetch_assoc()) {
        // Add full image URL if exists
        if (!empty($row['image'])) {
            $row['image_url'] = rtrim($baseUrl, '/') . '/' . ltrim($row['image'], '/');
        } else {
            $row['image_url'] = null;
        }
        $rows[] = $row;
    }

    echo json_encode(['status' => 'success', 'items' => $rows]);
    $stmt->close();
    $conn->close();
    exit;
}

// For all other methods (POST, PUT, DELETE) - Vendor authentication required
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
    echo json_encode(['status' => 'error', 'message' => 'Vendor token required']);
    exit;
}
$jwt = $matches[1];
try {
    $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid vendor token']);
    exit;
}
$vendorId = $decoded->sub;


if ($method === 'POST') {
    // Create or update vendor item (with image upload)
    $id = $_POST['id'] ?? null;
    $name = $_POST['name'] ?? null;
    $description = $_POST['description'] ?? null;
    $cost = $_POST['cost'] ?? null;
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/vendor_' . $vendorId . '/item/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = 'item_' . $vendorId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            $imagePath = 'uploads/vendor_' . $vendorId . '/item/' . $filename;
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
    }
    if ($id) {
        // Update item (with image upload)
        // Only allow update if item belongs to this vendor
        $sqlCheck = "SELECT * FROM items WHERE id = ? AND vendor_id = ?";
        $stmtCheck = $conn->prepare($sqlCheck);
        $stmtCheck->bind_param('ii', $id, $vendorId);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();
        $existing = $resultCheck->fetch_assoc();
        $stmtCheck->close();
        if (!$existing) {
            echo json_encode(['status' => 'error', 'message' => 'Item not found for this vendor']); exit;
        }
        if ($description === null || $description === '') {
            echo json_encode(['status' => 'error', 'message' => 'description required']); exit;
        }
        $fields = ['name','description','cost'];
        $set = [];
        $params = [];
        $types = '';
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $set[] = "$f = ?";
                $params[] = $_POST[$f];
                $types .= ($f === 'cost') ? 'd' : 's';
            }
        }
        if ($imagePath) {
            $set[] = "image = ?";
            $params[] = $imagePath;
            $types .= 's';
        }
        if (empty($set)) { echo json_encode(['status' => 'error', 'message' => 'No fields to update']); exit; }
        $params[] = $vendorId;
        $params[] = $id;
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
    } else {
        // Create item (with image upload)
        if (!$name || !$cost || $description === null || $description === '') {
            echo json_encode(['status' => 'error', 'message' => 'name, cost and description required']); exit;
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
    }
} else if (
    ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT')
    || $method === 'PUT'
) {
    // Update vendor item (image upload supported via POST + form-data)

    $id = null;

    if ($method === 'PUT') {
        // Parse urlencoded data for PUT (no file support here)
        parse_str(file_get_contents('php://input'), $data);
        if (isset($_GET['id']) && !is_array($_GET['id'])) {
            $id = $_GET['id'];
        } elseif (isset($data['id'])) {
            $id = $data['id'];
        }
    } else {
        // POST + _method=PUT (with files support)
        $id = $_POST['id'] ?? null;
        $data = $_POST;
    }

    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'id required']);
        exit;
    }

    $fields = ['name', 'description', 'cost'];
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

    // ✅ Only works if POST + _method=PUT (because $_FILES available)
    if ($method === 'POST' && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/vendor_' . $vendorId . '/item/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = 'item_' . $vendorId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            $imagePath = 'uploads/vendor_' . $vendorId . '/item/' . $filename;
            $set[] = "image = ?";
            $params[] = $imagePath;
            $types .= 's';
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Image upload failed']);
            exit;
        }
    }

    if (empty($set)) {
        echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
        exit;
    }

    $params[] = $vendorId;
    $params[] = $id;
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
}
else if ($method === 'DELETE') {
    // Delete vendor item
    $id = null;
    // Accept id from URL or input
    if (isset($_GET['id']) && !is_array($_GET['id'])) {
        $id = $_GET['id'];
    }
    parse_str(file_get_contents('php://input'), $data);
    if (!$id && isset($data['id']) && !is_array($data['id'])) {
        $id = $data['id'];
    }
    if (!$id) { echo json_encode(['status' => 'error', 'message' => 'id required']); exit; }
    $sql = 'DELETE FROM items WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $vendorId, $id);
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