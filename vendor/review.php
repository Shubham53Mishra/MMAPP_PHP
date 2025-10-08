<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include __DIR__ . '/../common/db.php';
require __DIR__ . '/../composer/autoload.php';
include __DIR__ . '/../common/jwt_secret.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');

// ---------------- JWT Auth ----------------
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
    echo json_encode(['status'=>'error','message'=>'Authorization token required']); 
    exit;
}
$jwt = $matches[1];
try {
    $decoded = JWT::decode($jwt, new Key(JWT_SECRET,'HS256'));
    $userId = $decoded->sub;
} catch(Exception $e){
    echo json_encode(['status'=>'error','message'=>'Invalid or expired token']); 
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ---------- POST: Add/Update Review ----------
if($method==='POST'){
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    if(!isset($input['type']) || !in_array($input['type'], ['meal','subcategory'])){
        echo json_encode(['status'=>'error','message'=>'type required: meal or subcategory']); 
        exit;
    }
    if(!isset($input['id'])){
        echo json_encode(['status'=>'error','message'=>'id required']); 
        exit;
    }
    if(!isset($input['rating'])){
        echo json_encode(['status'=>'error','message'=>'rating required']); 
        exit;
    }

    $type = $input['type'];
    $entityId = intval($input['id']);
    $rating = floatval($input['rating']);
    $comment = $input['comment'] ?? null;

    // Check if user already reviewed this entity
    $stmtCheck = $conn->prepare("SELECT id FROM reviews WHERE user_id=? AND entity_id=? AND type=?");
    $stmtCheck->bind_param('iis', $userId, $entityId, $type);
    $stmtCheck->execute();
    $res = $stmtCheck->get_result();

    if($res->num_rows > 0){
        // Update review
        $stmtUpd = $conn->prepare("UPDATE reviews SET rating=?, comment=? WHERE user_id=? AND entity_id=? AND type=?");
        $stmtUpd->bind_param('dsiis', $rating, $comment, $userId, $entityId, $type);
        $stmtUpd->execute();
        $stmtUpd->close();
        echo json_encode(['status'=>'success','message'=>'Review updated']);
    } else {
        // Insert new review
        $stmtIns = $conn->prepare("INSERT INTO reviews(user_id, entity_id, type, rating, comment) VALUES(?,?,?,?,?)");
        $stmtIns->bind_param('iisds', $userId, $entityId, $type, $rating, $comment);
        $stmtIns->execute();
        $stmtIns->close();
        echo json_encode(['status'=>'success','message'=>'Review added']);
    }

    $stmtCheck->close();
    $conn->close();
    exit;
}

// ---------- GET: Fetch Reviews ----------
if($method==='GET'){
    $entityId = $_GET['id'] ?? null;
    $type = $_GET['type'] ?? null;
    if(!$entityId || !$type){
        echo json_encode(['status'=>'error','message'=>'id and type required']); 
        exit;
    }

    $sql = "SELECT r.*, u.name as user_name FROM reviews r 
            INNER JOIN users u ON u.id = r.user_id 
            WHERE r.entity_id=? AND r.type=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $entityId, $type);
    $stmt->execute();
    $result = $stmt->get_result();

    $reviews = [];
    while($row = $result->fetch_assoc()){
        $reviews[] = $row;
    }

    $stmt->close();
    $conn->close();
    echo json_encode(['status'=>'success','reviews'=>$reviews]);
    exit;
}

echo json_encode(['status'=>'error','message'=>'Invalid request method']);
$conn->close();
?>
