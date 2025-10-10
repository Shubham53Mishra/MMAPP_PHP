<?php
// Vendor subcategory CRUD API (POST, GET, PUT, DELETE) with image upload

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

// Default: no vendor ID (public mode)
$vendorId = null;
$isAuthenticated = false;

// Try decoding JWT (optional for GET)
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if ($authHeader && preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
    $jwt = $matches[1];
    try {
        $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
        $vendorId = $decoded->sub;
        $isAuthenticated = true;
    } catch (Exception $e) {
        $isAuthenticated = false;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// Check for _method override in POST data
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper($_POST['_method']);
}

// ---------- POST ----------
if ($method === 'POST') {
    if (!$isAuthenticated) {
        echo json_encode(['status' => 'error', 'message' => 'Authorization token required']);
        exit;
    }

    // Add subcategory (with image upload)
    $data = $_POST;
    $fields = ['category_id', 'name', 'description', 'pricePerUnit', 'quantity', 'priceType', 'deliveryPriceEnabled', 'minDeliveryDays', 'maxDeliveryDays'];

    foreach ($fields as $f) {
        if (!isset($data[$f])) {
            echo json_encode(['status' => 'error', 'message' => "$f required"]);
            exit;
        }
    }

    // Convert available to integer
    $data['available'] = (isset($data['available']) && in_array($data['available'], [1, '1', true, 'true'])) ? 1 : 1;

    // Handle image upload (always vendor/uploads/subcategory_images/)
    $imageUrl = null;
    $uploadDir = __DIR__ . '/uploads/subcategory_images/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    if (isset($_FILES['imageUrl']) && $_FILES['imageUrl']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['imageUrl']['name'], PATHINFO_EXTENSION);
        $filename = 'subcategory_' . $vendorId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['imageUrl']['tmp_name'], $targetPath)) {
            $imageUrl = 'vendor/uploads/subcategory_images/' . $filename;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Image upload failed']);
            exit;
        }
    }

    // Price and discount
    $originalPricePerUnit = $data['pricePerUnit'];
    $discount = isset($data['discount']) ? floatval($data['discount']) : 0;
    $discountedPrice = $originalPricePerUnit - ($originalPricePerUnit * $discount / 100);

    $discountStart = $data['discountStart'] ?? null;
    $discountEnd = $data['discountEnd'] ?? null;

    $sql = "INSERT INTO vendor_subcategories 
        (vendor_id, category_id, name, description, pricePerUnit, originalPricePerUnit, imageUrl, quantity, priceType, deliveryPriceEnabled, minDeliveryDays, maxDeliveryDays, deliveryPrice, discount, discountStart, discountEnd, available)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'iissddsissiiidssi',
        $vendorId,
        $data['category_id'],
        $data['name'],
        $data['description'],
        $discountedPrice,
        $originalPricePerUnit,
        $imageUrl,
        $data['quantity'],
        $data['priceType'],
        $data['deliveryPriceEnabled'],
        $data['minDeliveryDays'],
        $data['maxDeliveryDays'],
        $data['deliveryPrice'],
        $discount,
        $discountStart,
        $discountEnd,
        $data['available']
    );

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Subcategory added', 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
    }
    $stmt->close();
}

// ---------- GET ----------
elseif ($method === 'GET') {
    if ($isAuthenticated && $vendorId) {
        // Show only vendor's categories + subcategories
        $sqlCat = 'SELECT DISTINCT c.id, c.name 
                   FROM categories c 
                   INNER JOIN vendor_subcategories vs ON c.id = vs.category_id 
                   WHERE vs.vendor_id = ?';
        $stmtCat = $conn->prepare($sqlCat);
        $stmtCat->bind_param('i', $vendorId);
        $stmtCat->execute();
        $resultCat = $stmtCat->get_result();
    } else {
        // Public mode — show all vendors
        $sqlCat = 'SELECT DISTINCT c.id, c.name 
                   FROM categories c 
                   INNER JOIN vendor_subcategories vs ON c.id = vs.category_id';
        $stmtCat = $conn->prepare($sqlCat);
        $stmtCat->execute();
        $resultCat = $stmtCat->get_result();
    }

    $categories = [];
    while ($cat = $resultCat->fetch_assoc()) {
        if ($isAuthenticated && $vendorId) {
            $sqlSub = 'SELECT * FROM vendor_subcategories WHERE vendor_id = ? AND category_id = ?';
            $stmtSub = $conn->prepare($sqlSub);
            $stmtSub->bind_param('ii', $vendorId, $cat['id']);
        } else {
            $sqlSub = 'SELECT * FROM vendor_subcategories WHERE category_id = ?';
            $stmtSub = $conn->prepare($sqlSub);
            $stmtSub->bind_param('i', $cat['id']);
        }

        $stmtSub->execute();
        $resultSub = $stmtSub->get_result();
        $subcategories = [];


        while ($row = $resultSub->fetch_assoc()) {
            // Add full image URL for subcategory_images (ensure correct public path)
            if (!empty($row['imageUrl'])) {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
                $imgName = basename($row['imageUrl']);
                $imgPath = 'vendor/uploads/subcategory_images/' . $imgName;
                $row['image_url'] = $baseUrl . '/' . $imgPath;
            } else {
                $row['image_url'] = null;
            }

            $row['discount'] = $row['discount'] ?? 0;
            $row['available'] = $row['available'] ?? 1;
            $row['originalPricePerUnit'] = $row['originalPricePerUnit'] ?? $row['pricePerUnit'];
            $row['stock_status'] = ($row['available'] == 1) ? 'in stock' : 'out of stock';

            // Fetch reviews for this subcategory
            $reviews = [];
            $sqlReviews = "SELECT r.*, u.name as user_name FROM reviews r INNER JOIN users u ON u.id = r.user_id WHERE r.entity_id = ? AND r.type = 'subcategory'";
            $stmtReviews = $conn->prepare($sqlReviews);
            $stmtReviews->bind_param('i', $row['id']);
            $stmtReviews->execute();
            $resultReviews = $stmtReviews->get_result();
            while ($review = $resultReviews->fetch_assoc()) {
                $reviews[] = $review;
            }
            $stmtReviews->close();
            $row['reviews'] = $reviews;

            $subcategories[] = $row;
        }

        $stmtSub->close();
        $cat['subcategories'] = $subcategories;
        $categories[] = $cat;
    }

    $stmtCat->close();
    echo json_encode(['status' => 'success', 'categories' => $categories]);
}

// ---------- PUT ----------
elseif ($method === 'PUT') {
    if (!$isAuthenticated) {
        echo json_encode(['status' => 'error', 'message' => 'Authorization token required']);
        exit;
    }

    // For PUT with multipart/form-data (using POST with _method=PUT)
    $data = $_POST;
    
    if (!isset($data['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'id required']);
        exit;
    }

    $fields = ['category_id', 'name', 'description', 'pricePerUnit', 'originalPricePerUnit', 'quantity', 'priceType', 'deliveryPriceEnabled', 'minDeliveryDays', 'maxDeliveryDays', 'deliveryPrice', 'discount', 'discountStart', 'discountEnd', 'available'];

    // Calculate discounted price if needed
    if (isset($data['originalPricePerUnit']) && isset($data['discount'])) {
        $discount = floatval($data['discount']);
        $original = floatval($data['originalPricePerUnit']);
        $data['pricePerUnit'] = $original - ($original * $discount / 100);
    }

    // Handle NEW image upload ONLY if a file is provided (always vendor/uploads/subcategory_images/)
    if (isset($_FILES['imageUrl']) && $_FILES['imageUrl']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/subcategory_images/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $ext = pathinfo($_FILES['imageUrl']['name'], PATHINFO_EXTENSION);
        $filename = 'subcategory_' . $vendorId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['imageUrl']['tmp_name'], $targetPath)) {
            $data['imageUrl'] = 'vendor/uploads/subcategory_images/' . $filename;
            // Add imageUrl to fields to update
            if (!in_array('imageUrl', $fields)) {
                $fields[] = 'imageUrl';
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Image upload failed']);
            exit;
        }
    }

    $set = [];
    $params = [];
    $types = '';
    foreach ($fields as $f) {
        if (isset($data[$f])) {
            if ($f === 'available') {
                $data[$f] = (in_array($data[$f], [1, '1', true, 'true'])) ? 1 : 0;
            }
            $set[] = "$f = ?";
            $params[] = $data[$f];
            $types .= is_numeric($data[$f]) ? 'd' : 's';
        }
    }

    if (empty($set)) {
        echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
        exit;
    }

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
}

// ---------- DELETE ----------
elseif ($method === 'DELETE') {
    if (!$isAuthenticated) {
        echo json_encode(['status' => 'error', 'message' => 'Authorization token required']);
        exit;
    }

    parse_str(file_get_contents('php://input'), $data);
    if (!isset($data['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'id required']);
        exit;
    }

    $sql = 'DELETE FROM vendor_subcategories WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $vendorId, $data['id']);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Subcategory deleted']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed: ' . $conn->error]);
    }
    $stmt->close();
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
}

$conn->close();
?>