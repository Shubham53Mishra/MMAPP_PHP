<?php
// item resource CRUD
header('Content-Type: application/json');
require_once __DIR__ . '/../../../common_cafe/db.php';
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $cafe_id = $_GET['cafe_id'] ?? '';
    $id = $_GET['id'] ?? '';
    if ($id) {
        $stmt = $conn->prepare("SELECT * FROM items WHERE id=? AND cafe_id=?");
        $stmt->bind_param('ii', $id, $cafe_id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_assoc());
        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT * FROM items WHERE cafe_id=?");
        $stmt->bind_param('i', $cafe_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) $items[] = $row;
        echo json_encode($items);
        $stmt->close();
    }
}
elseif ($method === 'POST') {
    $cafe_id = $_POST['cafe_id'] ?? '';
    $name = $_POST['name'] ?? '';
    if (!$cafe_id || !$name) {
        echo json_encode(['status'=>'error','message'=>'Cafe ID and name required']); exit;
    }
    $stmt = $conn->prepare("INSERT INTO items (cafe_id, name) VALUES (?, ?)");
    $stmt->bind_param('is', $cafe_id, $name);
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$stmt->insert_id]);
    $stmt->close();
}
elseif ($method === 'PUT') {
    parse_str(file_get_contents('php://input'), $data);
    $id = $data['id'] ?? '';
    $name = $data['name'] ?? '';
    if (!$id || !$name) {
        echo json_encode(['status'=>'error','message'=>'ID and name required']); exit;
    }
    $stmt = $conn->prepare("UPDATE items SET name=? WHERE id=?");
    $stmt->bind_param('si', $name, $id);
    $stmt->execute();
    echo json_encode(['status'=>'success']);
    $stmt->close();
}
elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['status'=>'error','message'=>'ID required']); exit; }
    $stmt = $conn->prepare("DELETE FROM items WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['status'=>'success']);
    $stmt->close();
}
$conn->close();
?>