<?php
// Vendor Meal CRUD API (POST, GET, PUT, DELETE) with image upload
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

// =====================================================
// GET - Fetch meals (with or without vendor token)
// =====================================================
if ($method === 'GET') {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    $vendorId = null;

    // Try to extract and validate token
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $jwt = $matches[1];
        try {
            $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
            $vendorId = $decoded->sub ?? null;
        } catch (Exception $e) {
            // Invalid token - proceed without vendor restriction
            $vendorId = null;
        }
    }

    // Build query based on whether vendor token exists
    if ($vendorId) {
        // Vendor authenticated - show only their meals
        $sql = 'SELECT * FROM vendor_meals WHERE vendor_id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $vendorId);
    } else {
        // No valid token - show all meals
        $sql = 'SELECT * FROM vendor_meals';
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        // Default fallback values
        $row['discount'] = $row['discount'] ?? 0;
        $row['available'] = $row['available'] ?? 1;
        $row['originalPricePerUnit'] = $row['originalPricePerUnit'] ?? null;
        $row['discountStart'] = $row['discountStart'] ?? null;
        $row['discountEnd'] = $row['discountEnd'] ?? null;

        // Stock status
        $row['stock_status'] = ($row['available'] == 1) ? 'in stock' : 'out of stock';

        // Base URL for images
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
                   "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);

        // Add image URLs
        foreach (['boxImage', 'actualImage'] as $imgField) {
            $row[$imgField . '_url'] = !empty($row[$imgField])
                ? rtrim($baseUrl, '/') . '/' . ltrim($row[$imgField], '/')
                : null;
        }

        // Decode and fetch items
        $itemObjs = [];
        if (!empty($row['items'])) {
            $decoded = json_decode($row['items'], true);
            $itemIds = [];

            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $itemIds[] = $item['id'];
                    } elseif (is_numeric($item)) {
                        $itemIds[] = (int)$item;
                    } elseif (is_string($item)) {
                        if (preg_match('/^\d+$/', $item)) {
                            $itemIds[] = (int)$item;
                        } elseif (preg_match('/"id"\s*:\s*(\d+)/', $item, $m)) {
                            $itemIds[] = (int)$m[1];
                        }
                    }
                }
            }

            $itemIds = array_filter(array_unique($itemIds));

            if (!empty($itemIds)) {
                $in = implode(',', array_fill(0, count($itemIds), '?'));
                $sqlItems = 'SELECT * FROM items WHERE id IN (' . $in . ')';
                $stmtItems = $conn->prepare($sqlItems);
                $types = str_repeat('i', count($itemIds));
                $stmtItems->bind_param($types, ...$itemIds);
                $stmtItems->execute();
                $resultItems = $stmtItems->get_result();

                while ($itemRow = $resultItems->fetch_assoc()) {
                    $itemObjs[] = [
                        'id' => $itemRow['id'],
                        'name' => $itemRow['name'],
                        'description' => $itemRow['description'],
                        'cost' => $itemRow['cost'],
                        'imageUrl' => !empty($itemRow['image'])
                            ? rtrim($baseUrl, '/') . '/' . ltrim($itemRow['image'], '/')
                            : null,
                        'vendor_id' => $itemRow['vendor_id']
                    ];
                }
                $stmtItems->close();
            }
        }

        $row['items'] = $itemObjs;
        $rows[] = $row;
    }

    echo json_encode(['status' => 'success', 'meals' => $rows]);
    $stmt->close();
    exit;
}

// =====================================================
// For POST, PUT, DELETE - Token is REQUIRED
// =====================================================
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

// =====================================================
// POST - Create or Update meal
// =====================================================
if ($method === 'POST') {
    $id = $_POST['id'] ?? null;
    $title = $_POST['title'] ?? null;
    $discount = isset($_POST['discount']) ? floatval($_POST['discount']) : 0;
    $available = isset($_POST['available']) ? (($_POST['available'] === 'true' || $_POST['available'] === '1' || $_POST['available'] === 1) ? 1 : 0) : 1;
    $originalPricePerUnit = isset($_POST['originalPricePerUnit']) ? floatval($_POST['originalPricePerUnit']) : null;
    $discountStart = $_POST['discountStart'] ?? null;
    $discountEnd = $_POST['discountEnd'] ?? null;
    
    if ($title === null || $title === '') {
        echo json_encode(['status' => 'error', 'message' => 'title required']);
        exit;
    }
    
    $description = $_POST['description'] ?? null;
    $minQty = $_POST['minQty'] ?? null;
    
    // Calculate price after discount if both originalPricePerUnit and discount are provided
    if ($originalPricePerUnit !== null && $discount > 0) {
        $price = $originalPricePerUnit - ($originalPricePerUnit * $discount / 100);
    } else {
        $price = $_POST['price'] ?? null;
    }
    
    $sampleAvailable = isset($_POST['sampleAvailable']) ? (($_POST['sampleAvailable'] === 'true' || $_POST['sampleAvailable'] === '1') ? 1 : 0) : 0;
    
    $items = null;
    if (isset($_POST['items'])) {
        $rawItems = $_POST['items'];
        if (is_array($rawItems)) {
            $items = json_encode($rawItems);
        } else if (is_string($rawItems)) {
            if (strpos($rawItems, ',') !== false) {
                $arr = array_map('trim', explode(',', $rawItems));
                $items = json_encode($arr);
            } else if (preg_match('/^\[.*\]$/', $rawItems)) {
                $items = $rawItems;
            } else {
                $items = json_encode([$rawItems]);
            }
        }
    }
    
    $packagingDetails = $_POST['packagingDetails'] ?? null;
    $minPrepareOrderDays = $_POST['minPrepareOrderDays'] ?? null;
    $maxPrepareOrderDays = $_POST['maxPrepareOrderDays'] ?? null;
    
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
    
    if ($id) {
        // Update meal
        $fields = [];
        $params = [];
        $types = '';
        
        foreach ([
            'title' => $title,
            'description' => $description,
            'minQty' => $minQty,
            'price' => $price,
            'sampleAvailable' => $sampleAvailable,
            'items' => $items,
            'packagingDetails' => $packagingDetails,
            'minPrepareOrderDays' => $minPrepareOrderDays,
            'maxPrepareOrderDays' => $maxPrepareOrderDays,
            'boxImage' => $boxImagePath,
            'actualImage' => $actualImagePath,
            'discount' => $discount,
            'available' => $available,
            'originalPricePerUnit' => $originalPricePerUnit,
            'discountStart' => $discountStart,
            'discountEnd' => $discountEnd
        ] as $key => $val) {
            if ($val !== null) {
                $fields[] = "$key = ?";
                $params[] = $val;
                if (in_array($key, ['minQty','minPrepareOrderDays','maxPrepareOrderDays','sampleAvailable','available'])) $types .= 'i';
                else if ($key === 'price' || $key === 'discount' || $key === 'originalPricePerUnit') $types .= 'd';
                else $types .= 's';
            }
        }
        
        if (empty($fields)) {
            echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
            exit;
        }
        
        $params[] = $vendorId;
        $params[] = $id;
        $types .= 'ii';
        
        $sql = 'UPDATE vendor_meals SET ' . implode(',', $fields) . ' WHERE vendor_id = ? AND id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Meal updated']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $conn->error]);
        }
        $stmt->close();
    } else {
        // Create meal
        $sql = "INSERT INTO vendor_meals (vendor_id, title, description, minQty, price, sampleAvailable, items, packagingDetails, minPrepareOrderDays, maxPrepareOrderDays, boxImage, actualImage, discount, available, originalPricePerUnit, discountStart, discountEnd) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('issidissiissdiiss', $vendorId, $title, $description, $minQty, $price, $sampleAvailable, $items, $packagingDetails, $minPrepareOrderDays, $maxPrepareOrderDays, $boxImagePath, $actualImagePath, $discount, $available, $originalPricePerUnit, $discountStart, $discountEnd);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Meal created', 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
        }
        $stmt->close();
    }
}

// =====================================================
// POST with _method=PUT - Update meal
// =====================================================
else if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (!isset($_POST['id'])) { 
        echo json_encode(['status' => 'error', 'message' => 'id required']); 
        exit; 
    }

    $id = intval($_POST['id']);
    $fields = ['title','description','minQty','price','sampleAvailable','items','packagingDetails','minPrepareOrderDays','maxPrepareOrderDays','discount','available','originalPricePerUnit','discountStart','discountEnd'];
    $set = [];
    $params = [];
    $types = '';

    // Calculate price after discount if both originalPricePerUnit and discount are provided
    $originalPricePerUnit = isset($_POST['originalPricePerUnit']) ? floatval($_POST['originalPricePerUnit']) : null;
    $discount = isset($_POST['discount']) ? floatval($_POST['discount']) : 0;
    if ($originalPricePerUnit !== null && $discount > 0) {
        $_POST['price'] = $originalPricePerUnit - ($originalPricePerUnit * $discount / 100);
    }

    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $set[] = "$f = ?";
            if ($f === 'items') {
                $rawItems = $_POST[$f];
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
                $params[] = $_POST[$f];
            }

            if (in_array($f, ['minQty','minPrepareOrderDays','maxPrepareOrderDays','sampleAvailable','available'])) $types .= 'i';
            else if (in_array($f, ['price','discount','originalPricePerUnit'])) $types .= 'd';
            else $types .= 's';
        }
    }

    // Image Upload Handling
    if (!empty($_FILES['boxImage']['name'])) {
        $imgName = 'meal_box_' . time() . '_' . basename($_FILES['boxImage']['name']);
        $target = __DIR__ . "/uploads/" . $imgName;
        if (move_uploaded_file($_FILES['boxImage']['tmp_name'], $target)) {
            $set[] = "boxImage = ?";
            $params[] = 'uploads/' . $imgName;
            $types .= 's';
        }
    }
    
    if (!empty($_FILES['actualImage']['name'])) {
        $imgName = 'meal_actual_' . time() . '_' . basename($_FILES['actualImage']['name']);
        $target = __DIR__ . "/uploads/" . $imgName;
        if (move_uploaded_file($_FILES['actualImage']['tmp_name'], $target)) {
            $set[] = "actualImage = ?";
            $params[] = 'uploads/' . $imgName;
            $types .= 's';
        }
    }

    if (empty($set)) { 
        echo json_encode(['status' => 'error', 'message' => 'No fields to update']); 
        exit; 
    }

    $params[] = $vendorId;
    $params[] = $id;
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
}

// =====================================================
// DELETE - Delete meal
// =====================================================
else if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $data);
    if (!isset($data['id'])) { 
        echo json_encode(['status' => 'error', 'message' => 'id required']); 
        exit; 
    }
    
    $sql = 'DELETE FROM vendor_meals WHERE vendor_id = ? AND id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $vendorId, $data['id']);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Meal deleted']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed: ' . $conn->error]);
    }
    $stmt->close();
}

// =====================================================
// Invalid method
// =====================================================
else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
}

$conn->close();
?>