<?php
error_log('Test error log entry from profile.php');
// Get logged-in vendor profile (JWT protected)
// Vendor login
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

if ($method === 'PUT' || $method === 'POST') {
    // Support both JSON and multipart/form-data for profile update
    $fields = [];
    $params = [];
    $imagePath = null;
    $isMultipart = isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
    $hasImage = false;
    if ($isMultipart) {
        // Handle form-data (Postman form-data)
        if (isset($_POST['name'])) {
            $fields[] = 'name = ?';
            $params[] = $_POST['name'];
        }
        if (isset($_POST['city'])) {
            $fields[] = 'city = ?';
            $params[] = $_POST['city'];
        }
        if (isset($_POST['address'])) {
            $fields[] = 'address = ?';
            $params[] = $_POST['address'];
        }
        if (isset($_POST['state'])) {
            $fields[] = 'state = ?';
            $params[] = $_POST['state'];
        }
        if (isset($_FILES['profile_image'])) {
            if ($_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                $filename = 'vendor_' . $vendorId . '_' . time() . '.' . $ext;
                $targetPath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetPath)) {
                    // Save relative path for DB, but always use forward slashes
                    $imagePath = 'uploads/' . $filename;
                    $fields[] = 'image = ?';
                    $params[] = $imagePath;
                    $hasImage = true;
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Image upload failed']);
                    exit;
                }
            } else if ($_FILES['profile_image']['error'] === UPLOAD_ERR_NO_FILE) {
                // No new image uploaded, do not update image field
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Image upload error']);
                exit;
            }
        }
    } else {
        // Handle JSON
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
            exit;
        }
        if (isset($input['name'])) {
            $fields[] = 'name = ?';
            $params[] = $input['name'];
        }
        if (isset($input['city'])) {
            $fields[] = 'city = ?';
            $params[] = $input['city'];
        }
        if (isset($input['address'])) {
            $fields[] = 'address = ?';
            $params[] = $input['address'];
        }
        if (isset($input['state'])) {
            $fields[] = 'state = ?';
            $params[] = $input['state'];
        }
    }
    if (empty($fields) && !$hasImage) {
    error_log('No fields to update for vendor id: ' . $vendorId);
    echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
    exit;
    }
    $sql = 'UPDATE vendors SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $params[] = $vendorId;
    // Dynamically build type string: 's' for each field, 'i' for id
    $types = '';
    foreach ($fields as $f) {
        $types .= 's';
    }
    $types .= 'i';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        // Always fetch latest profile after update
        $sql2 = "SELECT id, name, email, mobile, city, state, address, created_at, image FROM vendors WHERE id = ?";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param('i', $vendorId);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        if ($row2 = $result2->fetch_assoc()) {
            // Always check and return image URL if image exists in DB
            if (!empty($row2['image'])) {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
                $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                $row2['profile_image'] = rtrim($baseUrl, '/') . rtrim($scriptDir, '/') . '/' . ltrim($row2['image'], '/');
            } else {
                $row2['profile_image'] = null;
            }
            unset($row2['image']);
            $row2['city'] = $row2['city'] ?? null;
            $row2['state'] = $row2['state'] ?? null;
            $row2['address'] = $row2['address'] ?? null;
            echo json_encode(['status' => 'success', 'message' => 'Profile updated', 'vendor' => $row2]);
        } else {
            echo json_encode(['status' => 'success', 'message' => 'Profile updated']);
        }
        $stmt2->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Update failed']);
    }
    $stmt->close();
    $conn->close();
    exit;
}

// GET: fetch vendor profile
$sql = "SELECT id, name, email, mobile, city, state, address, created_at, image FROM vendors WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $vendorId);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $row['profile_image'] = !empty($row['image']) ? rtrim($baseUrl, '/') . rtrim($scriptDir, '/') . '/' . ltrim($row['image'], '/') : null;
    unset($row['image']);
    // Ensure city, state, address are present in response (null if not set)
    $row['city'] = $row['city'] ?? null;
    $row['state'] = $row['state'] ?? null;
    $row['address'] = $row['address'] ?? null;
    echo json_encode(['status' => 'success', 'vendor' => $row]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Vendor not found']);
}
$stmt->close();
$conn->close();
?>
