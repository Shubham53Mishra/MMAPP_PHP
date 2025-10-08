<?php
// favorites.php - Combined Meal & Subcategory Favorites (Fixed & Compatible)
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

// ---------------- JWT AUTH ----------------
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
    echo json_encode(['status' => 'error', 'message' => 'Authorization token required']);
    exit;
}

$jwt = $matches[1];

try {
    $decoded = JWT::decode($jwt, new Key(JWT_SECRET, 'HS256'));
    $userId = $decoded->sub; // Logged-in user ID
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ---------------- POST: Add/Remove Favorite ----------------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // Support old keys for backward compatibility
    if (isset($input['subcategoryId'])) {
        $input['type'] = 'subcategory';
        $input['id'] = $input['subcategoryId'];
    } elseif (isset($input['mealId'])) {
        $input['type'] = 'meal';
        $input['id'] = $input['mealId'];
    }

    if (!isset($input['type']) || !in_array($input['type'], ['meal', 'subcategory'])) {
        echo json_encode(['status' => 'error', 'message' => 'type required: meal or subcategory']);
        exit;
    }
    if (!isset($input['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'id required']);
        exit;
    }

    $type = $input['type'];
    $id = intval($input['id']);

    // Check if already favorite
    $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND type = ? AND entity_id = ?");
    $stmt->bind_param('isi', $userId, $type, $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        // Unfavorite
        $stmtDel = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND type = ? AND entity_id = ?");
        $stmtDel->bind_param('isi', $userId, $type, $id);
        $stmtDel->execute();
        $stmtDel->close();
        $statusMsg = 'Removed from favorites';
    } else {
        // Add favorite
        $stmtIns = $conn->prepare("INSERT INTO favorites (user_id, type, entity_id) VALUES (?, ?, ?)");
        $stmtIns->bind_param('isi', $userId, $type, $id);
        $stmtIns->execute();
        $stmtIns->close();
        $statusMsg = 'Added to favorites';
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'status' => 'success',
        'message' => $statusMsg
    ]);
    exit;
}

// ---------------- GET: List All Favorites ----------------
if ($method === 'GET') {
    $favorites = [
        'meals' => [],
        'subcategories' => []
    ];

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);

    // --- Meals ---
    $sqlMeals = "SELECT vm.*, f.id as fav_id
                 FROM vendor_meals vm
                 INNER JOIN favorites f ON f.entity_id = vm.id AND f.type='meal'
                 WHERE f.user_id=?";
    $stmt = $conn->prepare($sqlMeals);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['discount'] = $row['discount'] ?? 0;
        $row['available'] = $row['available'] ?? 1;
        $row['originalPricePerUnit'] = $row['originalPricePerUnit'] ?? null;
        $row['stock_status'] = ($row['available'] == 1) ? 'in stock' : 'out of stock';
        foreach (['boxImage', 'actualImage'] as $imgField) {
            $row[$imgField . '_url'] = !empty($row[$imgField]) ? rtrim($baseUrl, '/') . '/' . ltrim($row[$imgField], '/') : null;
        }
        $row['items'] = !empty($row['items']) ? json_decode($row['items'], true) : [];
        $favorites['meals'][] = $row;
    }
    $stmt->close();

    // --- Subcategories ---
    $sqlSubs = "SELECT vs.*, f.id as fav_id
                FROM vendor_subcategories vs
                INNER JOIN favorites f ON f.entity_id = vs.id AND f.type='subcategory'
                WHERE f.user_id=?";
    $stmt = $conn->prepare($sqlSubs);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['image_url'] = !empty($row['imageUrl']) ? rtrim($baseUrl, '/') . '/' . ltrim($row['imageUrl'], '/') : null;
        $row['available'] = $row['available'] ?? 1;
        $favorites['subcategories'][] = $row;
    }
    $stmt->close();
    $conn->close();

    echo json_encode([
        'status' => 'success',
        'favorites' => $favorites
    ]);
    exit;
}

// ---------------- INVALID METHOD ----------------
echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
$conn->close();
exit;
?>
