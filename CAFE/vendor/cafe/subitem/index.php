<?php
// subitem resource CRUD
header('Content-Type: application/json');
require_once __DIR__ . '/../../../common_cafe/db.php';
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $item_id = $_GET['item_id'] ?? '';
    $id = $_GET['id'] ?? '';
    if ($id) {
        $stmt = $conn->prepare("SELECT * FROM subitems WHERE id=? AND item_id=?");
        $stmt->bind_param('ii', $id, $item_id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_assoc());
        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT * FROM subitems WHERE item_id=?");
        $stmt->bind_param('i', $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subitems = [];
        while ($row = $result->fetch_assoc()) $subitems[] = $row;
        echo json_encode($subitems);
        $stmt->close();
    }
}
elseif ($method === 'POST') {
    $item_id = $_POST['item_id'] ?? '';
    $name = $_POST['name'] ?? '';
    if (!$item_id || !$name) {
        echo json_encode(['status'=>'error','message'=>'Item ID and name required']); exit;
    }
    $stmt = $conn->prepare("INSERT INTO subitems (item_id, name) VALUES (?, ?)");
    $stmt->bind_param('is', $item_id, $name);
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
    $stmt = $conn->prepare("UPDATE subitems SET name=? WHERE id=?");
    $stmt->bind_param('si', $name, $id);
    $stmt->execute();
    echo json_encode(['status'=>'success']);
    $stmt->close();
}
elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['status'=>'error','message'=>'ID required']); exit; }
    $stmt = $conn->prepare("DELETE FROM subitems WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['status'=>'success']);
    $stmt->close();
}
$conn->close();
?>