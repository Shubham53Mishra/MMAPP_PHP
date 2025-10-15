<?php
// subitem resource CRUD
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

// Set timezone to India (IST)
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../../../common_cafe/db.php';
require_once __DIR__ . '/../../../common_cafe/jwt_secret.php';

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

// Check for method override
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper($_POST['_method']);
}

// Token logic for GET
function getBearerToken() {
    $header = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ($header && preg_match('/Bearer\s(.*)/', $header, $matches)) {
        return $matches[1];
    }
    return null;
}

function validateToken($token) {
    require_once __DIR__ . '/../../../vendor/firebase/php-jwt/src/JWT.php';
    require_once __DIR__ . '/../../../vendor/firebase/php-jwt/src/Key.php';
    $secret = JWT_SECRET;
    try {
        $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
        if (isset($decoded->sub)) {
            return [ 'type' => 'vendor', 'vendor_id' => $decoded->sub ];
        } elseif (isset($decoded->user_id)) {
            return [ 'type' => 'user', 'user_id' => $decoded->user_id ];
        }
    } catch (Exception $e) {
        return false;
    }
    return false;
}

// Helper function to format datetime to local timezone
function formatLocalDateTime($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $datetime;
    }
}

// Helper function to format record dates
function formatRecordDates($record) {
    if ($record && isset($record['created_at'])) {
        $record['created_at'] = formatLocalDateTime($record['created_at']);
    }
    if ($record && isset($record['updated_at'])) {
        $record['updated_at'] = formatLocalDateTime($record['updated_at']);
    }
    return $record;
}

if ($method === 'GET') {
    $item_id = $_GET['item_id'] ?? '';
    $id = $_GET['id'] ?? '';
    $token = getBearerToken();
    $auth_info = $token ? validateToken($token) : false;

    if ($id) {
        if ($auth_info !== false && isset($auth_info['type']) && $auth_info['type'] === 'vendor') {
            // Vendor token: only show if item belongs to this vendor
            $stmt = $conn->prepare("SELECT s.* FROM subitems s JOIN items i ON s.item_id = i.id WHERE s.id=? AND s.item_id=? AND i.cafe_id=?");
            $stmt->bind_param('iii', $id, $item_id, $auth_info['vendor_id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && isset($row['images'])) {
                $row['images'] = json_decode($row['images'], true) ?: [];
            }
            $row = formatRecordDates($row);
            echo json_encode($row);
            $stmt->close();
        } else {
            // No token or user token: show all
            $stmt = $conn->prepare("SELECT * FROM subitems WHERE id=? AND item_id=?");
            $stmt->bind_param('ii', $id, $item_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && isset($row['images'])) {
                $row['images'] = json_decode($row['images'], true) ?: [];
            }
            $row = formatRecordDates($row);
            echo json_encode($row);
            $stmt->close();
        }
    } else if ($item_id) {
        if ($auth_info !== false && isset($auth_info['type']) && $auth_info['type'] === 'vendor') {
            // Vendor token: only show subitems for items belonging to this vendor
            $stmt = $conn->prepare("SELECT s.* FROM subitems s JOIN items i ON s.item_id = i.id WHERE s.item_id=? AND i.cafe_id=?");
            $stmt->bind_param('ii', $item_id, $auth_info['vendor_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $subitems = [];
            while ($row = $result->fetch_assoc()) {
                if (isset($row['images'])) {
                    $row['images'] = json_decode($row['images'], true) ?: [];
                }
                $row = formatRecordDates($row);
                $subitems[] = $row;
            }
            echo json_encode($subitems);
            $stmt->close();
        } else {
            // No token or user token: show all subitems for item_id
            $stmt = $conn->prepare("SELECT * FROM subitems WHERE item_id=?");
            $stmt->bind_param('i', $item_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $subitems = [];
            while ($row = $result->fetch_assoc()) {
                if (isset($row['images'])) {
                    $row['images'] = json_decode($row['images'], true) ?: [];
                }
                $row = formatRecordDates($row);
                $subitems[] = $row;
            }
            echo json_encode($subitems);
            $stmt->close();
        }
    } else {
        // No item_id: show all subitems (public or vendor filtered)
        if ($auth_info !== false && isset($auth_info['type']) && $auth_info['type'] === 'vendor') {
            $stmt = $conn->prepare("SELECT s.* FROM subitems s JOIN items i ON s.item_id = i.id WHERE i.cafe_id=?");
            $stmt->bind_param('i', $auth_info['vendor_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $subitems = [];
            while ($row = $result->fetch_assoc()) {
                if (isset($row['images'])) {
                    $row['images'] = json_decode($row['images'], true) ?: [];
                }
                $row = formatRecordDates($row);
                $subitems[] = $row;
            }
            echo json_encode($subitems);
            $stmt->close();
        } else {
            $result = $conn->query("SELECT * FROM subitems");
            $subitems = [];
            while ($row = $result->fetch_assoc()) {
                if (isset($row['images'])) {
                    $row['images'] = json_decode($row['images'], true) ?: [];
                }
                $row = formatRecordDates($row);
                $subitems[] = $row;
            }
            echo json_encode($subitems);
        }
    }
}
elseif ($method === 'POST') {
    $item_id = $_POST['item_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $price = $_POST['price'] ?? '';
    $description = $_POST['description'] ?? '';

    // Require vendor token
    $token = getBearerToken();
    $auth_info = $token ? validateToken($token) : false;
    if ($auth_info === false || !isset($auth_info['type']) || $auth_info['type'] !== 'vendor') {
        http_response_code(401);
        echo json_encode(['status'=>'error','message'=>'Unauthorized: Valid vendor token required']);
        exit;
    }
    $vendor_id = $auth_info['vendor_id'];

    if (!$item_id || !$name) {
        echo json_encode(['status'=>'error','message'=>'Item ID and name required']); exit;
    }

    // Check if item_id exists and belongs to this vendor
    $checkStmt = $conn->prepare("SELECT id FROM items WHERE id = ? AND cafe_id = ?");
    $checkStmt->bind_param('ii', $item_id, $vendor_id);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows === 0) {
        echo json_encode(['status'=>'error','message'=>'Invalid item_id: No such item for this vendor']);
        $checkStmt->close();
        exit;
    }
    $checkStmt->close();

    // Handle uploads
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/uploads/';

    // Single image
    $imageUrl = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $imgName = 'subitem_' . time() . '_main.' . $ext;
        $imgPath = $uploadDir . $imgName;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $imgPath)) {
            $imageUrl = $baseUrl . $imgName;
        }
    }

    // Multiple images - FIXED VERSION
    $imagesUrls = [];
    if (isset($_FILES['images'])) {
        $images = $_FILES['images'];
        
        // Check if it's already an array structure or single file
        if (is_array($images['tmp_name'])) {
            // Multiple files sent
            $fileCount = count($images['tmp_name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if (isset($images['tmp_name'][$i]) && 
                    !empty($images['tmp_name'][$i]) && 
                    $images['error'][$i] === UPLOAD_ERR_OK) {
                    
                    $ext = pathinfo($images['name'][$i], PATHINFO_EXTENSION);
                    $imgName = 'subitem_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                    $imgPath = $uploadDir . $imgName;
                    
                    if (move_uploaded_file($images['tmp_name'][$i], $imgPath)) {
                        $imagesUrls[] = $baseUrl . $imgName;
                    }
                }
            }
        } else {
            // Single file sent with 'images' name
            if (!empty($images['tmp_name']) && $images['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($images['name'], PATHINFO_EXTENSION);
                $imgName = 'subitem_' . time() . '_' . uniqid() . '_0.' . $ext;
                $imgPath = $uploadDir . $imgName;
                
                if (move_uploaded_file($images['tmp_name'], $imgPath)) {
                    $imagesUrls[] = $baseUrl . $imgName;
                }
            }
        }
    }
    
    // Convert to JSON for database storage
    $imagesJson = json_encode($imagesUrls);

    // Insert into DB
    $stmt = $conn->prepare("INSERT INTO subitems (item_id, name, price, description, image_url, images) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isdsss', $item_id, $name, $price, $description, $imageUrl, $imagesJson);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    
    // Fetch and return created record
    $fetchStmt = $conn->prepare("SELECT * FROM subitems WHERE id = ?");
    $fetchStmt->bind_param('i', $newId);
    $fetchStmt->execute();
    $createdRecord = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();
    
    if ($createdRecord && isset($createdRecord['images'])) {
        $createdRecord['images'] = json_decode($createdRecord['images'], true) ?: [];
    }
    $createdRecord = formatRecordDates($createdRecord);
    
    echo json_encode([
        'status'=>'success',
        'message'=>'Subitem created successfully',
        'data'=>$createdRecord
    ]);
}
elseif ($method === 'PUT') {
    // Parse multipart/form-data manually for PUT requests
    $data = [];
    $_PUT = [];
    $_PUT_FILES = [];
    
    // First check if using POST method override
    if (!empty($_POST)) {
        $data = $_POST;
        $_PUT = $_POST;
        // Remove _method from data
        unset($data['_method']);
        unset($_PUT['_method']);
        
        // For POST override, $_FILES will be populated normally
        $_PUT_FILES = $_FILES;
    } else {
        // Check query string
        $data = $_GET;
        
        // Parse multipart/form-data from raw input for true PUT requests
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false) {
            // Get boundary
            preg_match('/boundary=(.*)$/', $contentType, $matches);
            if (isset($matches[1])) {
                $boundary = $matches[1];
                $rawData = file_get_contents('php://input');
                
                // Split by boundary
                $parts = array_slice(explode("--" . $boundary, $rawData), 1);
                
                foreach ($parts as $part) {
                    if (empty(trim($part)) || $part == "--\r\n" || $part == "--") continue;
                    
                    // Parse headers and content
                    if (preg_match('/Content-Disposition: form-data; name="([^"]+)"(; filename="([^"]+)")?[\r\n]+(Content-Type: ([^\r\n]+))?[\r\n]*(.*)$/s', $part, $matches)) {
                        $name = $matches[1];
                        $filename = $matches[3] ?? '';
                        $contentType = $matches[5] ?? '';
                        $value = isset($matches[6]) ? substr($matches[6], 0, -2) : '';
                        
                        if (!empty($filename)) {
                            // It's a file upload
                            $tmpPath = tempnam(sys_get_temp_dir(), 'php_upload_');
                            file_put_contents($tmpPath, $value);
                            
                            // Handle array notation like images[]
                            if (strpos($name, '[]') !== false) {
                                $fieldName = str_replace('[]', '', $name);
                                if (!isset($_PUT_FILES[$fieldName])) {
                                    $_PUT_FILES[$fieldName] = [
                                        'name' => [],
                                        'type' => [],
                                        'tmp_name' => [],
                                        'error' => [],
                                        'size' => []
                                    ];
                                }
                                $_PUT_FILES[$fieldName]['name'][] = $filename;
                                $_PUT_FILES[$fieldName]['type'][] = $contentType;
                                $_PUT_FILES[$fieldName]['tmp_name'][] = $tmpPath;
                                $_PUT_FILES[$fieldName]['error'][] = UPLOAD_ERR_OK;
                                $_PUT_FILES[$fieldName]['size'][] = strlen($value);
                            } else {
                                $_PUT_FILES[$name] = [
                                    'name' => $filename,
                                    'type' => $contentType,
                                    'tmp_name' => $tmpPath,
                                    'error' => UPLOAD_ERR_OK,
                                    'size' => strlen($value)
                                ];
                            }
                        } else {
                            // Regular form field
                            $_PUT[$name] = $value;
                            $data[$name] = $value;
                        }
                    }
                }
            }
        } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str(file_get_contents('php://input'), $_PUT);
            $data = array_merge($data, $_PUT);
        } elseif (strpos($contentType, 'application/json') !== false) {
            $input = file_get_contents('php://input');
            $_PUT = json_decode($input, true) ?: [];
            $data = array_merge($data, $_PUT);
        }
    }
    
    // Require vendor token
    $token = getBearerToken();
    $auth_info = $token ? validateToken($token) : false;
    if ($auth_info === false || !isset($auth_info['type']) || $auth_info['type'] !== 'vendor') {
        http_response_code(401);
        echo json_encode(['status'=>'error','message'=>'Unauthorized: Valid vendor token required']);
        exit;
    }
    $vendor_id = $auth_info['vendor_id'];
    
    $id = $data['id'] ?? $_PUT['id'] ?? '';
    $name = $data['name'] ?? $_PUT['name'] ?? null;
    $price = $data['price'] ?? $_PUT['price'] ?? null;
    $description = $data['description'] ?? $_PUT['description'] ?? null;

    if (!$id) {
        echo json_encode(['status'=>'error','message'=>'ID required']); 
        exit;
    }
    
    // Verify subitem belongs to vendor's item
    $checkStmt = $conn->prepare("SELECT s.id FROM subitems s JOIN items i ON s.item_id = i.id WHERE s.id = ? AND i.cafe_id = ?");
    $checkStmt->bind_param('ii', $id, $vendor_id);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows === 0) {
        echo json_encode(['status'=>'error','message'=>'Unauthorized: Subitem not found or does not belong to vendor']);
        $checkStmt->close();
        exit;
    }
    $checkStmt->close();

    // Handle uploads (optional)
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/uploads/';

    // Single image
    $imageUrl = null;
    if (isset($_PUT_FILES['image']) && $_PUT_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_PUT_FILES['image']['name'], PATHINFO_EXTENSION);
        $imgName = 'subitem_' . time() . '_main.' . $ext;
        $imgPath = $uploadDir . $imgName;
        if (move_uploaded_file($_PUT_FILES['image']['tmp_name'], $imgPath)) {
            $imageUrl = $baseUrl . $imgName;
        }
    }

    // Multiple images - FIXED VERSION FOR PUT
    $imagesUrls = null;
    $imagesJson = null;
    if (isset($_PUT_FILES['images'])) {
        $images = $_PUT_FILES['images'];
        $urls = [];
        
        if (is_array($images['tmp_name'])) {
            // Multiple files
            foreach ($images['tmp_name'] as $idx => $tmpName) {
                if (!empty($tmpName) && $images['error'][$idx] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($images['name'][$idx], PATHINFO_EXTENSION);
                    $imgName = 'subitem_' . time() . '_' . uniqid() . '_' . $idx . '.' . $ext;
                    $imgPath = $uploadDir . $imgName;
                    if (move_uploaded_file($tmpName, $imgPath)) {
                        $urls[] = $baseUrl . $imgName;
                    }
                }
            }
        } else {
            // Single file
            if (!empty($images['tmp_name']) && $images['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($images['name'], PATHINFO_EXTENSION);
                $imgName = 'subitem_' . time() . '_' . uniqid() . '_0.' . $ext;
                $imgPath = $uploadDir . $imgName;
                if (move_uploaded_file($images['tmp_name'], $imgPath)) {
                    $urls[] = $baseUrl . $imgName;
                }
            }
        }
        
        if (!empty($urls)) {
            $imagesUrls = $urls;
            $imagesJson = json_encode($urls);
        }
    }

    // Build dynamic SQL
    $fields = [];
    $params = [];
    $types = '';
    
    // Always update updated_at with current timestamp
    $currentDateTime = date('Y-m-d H:i:s');
    $fields[] = 'updated_at = ?';
    $params[] = $currentDateTime;
    $types .= 's';
    
    if ($name !== null) { $fields[] = 'name = ?'; $params[] = $name; $types .= 's'; }
    if ($price !== null) { $fields[] = 'price = ?'; $params[] = $price; $types .= 'd'; }
    if ($description !== null) { $fields[] = 'description = ?'; $params[] = $description; $types .= 's'; }
    if ($imageUrl !== null) { $fields[] = 'image_url = ?'; $params[] = $imageUrl; $types .= 's'; }
    if ($imagesJson !== null) { $fields[] = 'images = ?'; $params[] = $imagesJson; $types .= 's'; }
    
    $params[] = $id;
    $types .= 'i';
    $sql = 'UPDATE subitems SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    
    // Fetch and return updated record
    $fetchStmt = $conn->prepare("SELECT * FROM subitems WHERE id = ?");
    $fetchStmt->bind_param('i', $id);
    $fetchStmt->execute();
    $updatedRecord = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();
    
    // Decode images JSON for response
    if ($updatedRecord && isset($updatedRecord['images'])) {
        $updatedRecord['images'] = json_decode($updatedRecord['images'], true) ?: [];
    }
    $updatedRecord = formatRecordDates($updatedRecord);
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Subitem updated successfully',
        'data' => $updatedRecord
    ]);
}
elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    // Require vendor token
    $token = getBearerToken();
    $auth_info = $token ? validateToken($token) : false;
    if ($auth_info === false || !isset($auth_info['type']) || $auth_info['type'] !== 'vendor') {
        http_response_code(401);
        echo json_encode(['status'=>'error','message'=>'Unauthorized: Valid vendor token required']);
        exit;
    }
    $vendor_id = $auth_info['vendor_id'];
    if (!$id) { 
        echo json_encode(['status'=>'error','message'=>'ID required']); 
        exit; 
    }
    // Delete subitem directly by id
    $stmt = $conn->prepare("DELETE FROM subitems WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        echo json_encode(['status'=>'success', 'message'=>'Subitem deleted successfully']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'Delete failed: Subitem not found', 'db_error' => $stmt->error]);
    }
    $stmt->close();
}
$conn->close();
?>