<?php
// Enable error reporting and logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

include __DIR__ . '/../common/db.php';
require __DIR__ . '/../composer/autoload.php';
include __DIR__ . '/../common/jwt_secret.php'; 

// Accept email/mobile and password from form-data, x-www-form-urlencoded, or raw JSON/input
$login = '';
$password = '';
if (isset($_POST['email'])) {
    $login = $_POST['email'];
}
if (isset($_POST['mobile'])) {
    $login = $_POST['mobile'];
}
if (isset($_POST['password'])) {
    $password = $_POST['password'];
}
// If not found in $_POST, try to parse raw input (for curl -d)
if (!$login || !$password) {
    parse_str(file_get_contents('php://input'), $parsed);
    if (isset($parsed['email'])) {
        $login = $parsed['email'];
    }
    if (isset($parsed['mobile'])) {
        $login = $parsed['mobile'];
    }
    if (isset($parsed['password'])) {
        $password = $parsed['password'];
    }
}
// If still not found, try JSON
if (!$login || !$password) {
    $input = file_get_contents('php://input');
    if ($input) {
        $json = json_decode($input, true);
        if (is_array($json)) {
            $login = $json['email'] ?? ($json['mobile'] ?? '');
            $password = $json['password'] ?? '';
        }
    }
}
if (!$login || !$password) {
    echo json_encode(['status' => 'error', 'message' => 'All fields required']);
    exit;
}

$sql = "SELECT * FROM users WHERE email = ? OR mobile = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $login, $login);
$stmt->execute();
$result = $stmt->get_result();


if ($row = $result->fetch_assoc()) {
    if ($password === $row['password']) {
        // Remove password from user data before sending
        unset($row['password']);
        // JWT token generate
        $issuedAt = time();
        $expirationTime = $issuedAt + 3600; // 1 hour
        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'sub' => $row['id'],
            'email' => $row['email'],
            'name' => $row['name']
        ];
        $jwt = \Firebase\JWT\JWT::encode($payload, JWT_SECRET, 'HS256');
        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful',
            'token' => $jwt,
            'user' => $row
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid password']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
}
$stmt->close();
$conn->close();
?>
