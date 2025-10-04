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
    // Create or update meal (with images and item reference)
    $id = $_POST['id'] ?? null;
    $title = $_POST['title'] ?? null;
    if ($title === null || $title === '') {
        echo json_encode(['status' => 'error', 'message' => 'title required']);
        exit;
    }
    $description = $_POST['description'] ?? null;
    $minQty = $_POST['minQty'] ?? null;
    $price = $_POST['price'] ?? null;
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
            'actualImage' => $actualImagePath
        ] as $key => $val) {
            if ($val !== null) {
                $fields[] = "$key = ?";
                $params[] = $val;
                if (in_array($key, ['minQty','minPrepareOrderDays','maxPrepareOrderDays','sampleAvailable'])) $types .= 'i';
                else if ($key === 'price') $types .= 'd';
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
        $sql = "INSERT INTO vendor_meals (vendor_id, title, description, minQty, price, sampleAvailable, items, packagingDetails, minPrepareOrderDays, maxPrepareOrderDays, boxImage, actualImage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('issidissiiss', $vendorId, $title, $description, $minQty, $price, $sampleAvailable, $items, $packagingDetails, $minPrepareOrderDays, $maxPrepareOrderDays, $boxImagePath, $actualImagePath);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Meal created', 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
        }
        $stmt->close();
    }
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
        // Decode items as array of IDs (handle array of strings, objects, or mixed)
        $itemObjs = [];
        if (!empty($row['items'])) {
            $decoded = json_decode($row['items'], true);
            $itemIds = [];
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $itemIds[] = $item['id'];
                    } else if (is_string($item)) {
                        // Try to extract id from stringified object or plain id
                        if (preg_match('/^\d+$/', $item)) {
                            $itemIds[] = (int)$item;
                        } else if (preg_match('/"id"\s*:\s*(\d+)/', $item, $m)) {
                            $itemIds[] = (int)$m[1];
                        }
                    } else if (is_numeric($item)) {
                        $itemIds[] = (int)$item;
                    }
                }
            }
            // Remove duplicates and empty
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
                    $itemObj = [
                        'id' => $itemRow['id'],
                        'name' => $itemRow['name'],
                        'description' => $itemRow['description'],
                        'cost' => $itemRow['cost'],
                        'imageUrl' => !empty($itemRow['image']) ? (rtrim($baseUrl, '/') . '/' . ltrim($itemRow['image'], '/')) : null,
                        'vendor_id' => $itemRow['vendor_id']
                    ];
                    $itemObjs[] = $itemObj;
                }
                $stmtItems->close();
            }
        }
        $row['items'] = $itemObjs;
        $rows[] = $row;
    }
    echo json_encode(['status' => 'success', 'meals' => $rows]);
    $stmt->close();
}

/* =====================================================
   UPDATE MEAL (POST with _method=PUT)
===================================================== */
else if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (!isset($_POST['id'])) { echo json_encode(['status' => 'error', 'message' => 'id required']); exit; }

    $id = intval($_POST['id']);
    $fields = ['title','description','minQty','price','sampleAvailable','items','packagingDetails','minPrepareOrderDays','maxPrepareOrderDays'];
    $set = [];
    $params = [];
    $types = '';

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

            if (in_array($f, ['minQty','minPrepareOrderDays','maxPrepareOrderDays'])) $types .= 'i';
            else if ($f === 'price') $types .= 'd';
            else if ($f === 'sampleAvailable') $types .= 'i';
            else $types .= 's';
        }
    }

    // ✅ Image Upload Handling (boxImage & actualImage)
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

    if (empty($set)) { echo json_encode(['status' => 'error', 'message' => 'No fields to update']); exit; }

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

/* =====================================================
   DELETE MEAL
===================================================== */
else if ($method === 'DELETE') {
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
}

/* =====================================================
   INVALID METHOD
===================================================== */
else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
}

$conn->close();
?>
