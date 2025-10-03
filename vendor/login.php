<?php

// Vendor login
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

include __DIR__ . '/../common/db.php';
require __DIR__ . '/../composer/autoload.php';
include __DIR__ . '/../common/jwt_secret.php';

// Accept login and password from form-data, x-www-form-urlencoded, or raw JSON
// Accept login and password from form-data, x-www-form-urlencoded, or raw JSON
$login = '';
$password = '';
if (isset($_POST['login'])) {
    $login = $_POST['login'];
}
if (isset($_POST['password'])) {
    $password = $_POST['password'];
}
// If not found in $_POST, try to parse raw input (for curl -d)
if (!$login || !$password) {
    parse_str(file_get_contents('php://input'), $parsed);
    if (isset($parsed['login'])) {
        $login = $parsed['login'];
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
            $login = $json['login'] ?? '';
            $password = $json['password'] ?? '';
        }
    }
}
if (!$login || !$password) {
    echo json_encode(['status' => 'error', 'message' => 'All fields required']);
    exit;
}

// Allow login with email or mobile
$sql = "SELECT * FROM vendors WHERE email = ? OR mobile = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $login, $login);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $valid = false;
    $verifyError = '';
    // Always try password_verify first
    $debugInfo = [
        'input_password' => $password,
        'db_hash' => $row['password']
    ];
    if (password_verify($password, $row['password'])) {
        $valid = true;
    } elseif ($password === $row['password']) {
        $valid = true;
    } else {
        $verifyError = 'Hash verify failed and plain compare failed';
    }
    if ($valid) {
        unset($row['password']);
        $issuedAt = time();
            $expirationTime = $issuedAt + 8 * 3600; // 8 hours
        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'sub' => $row['id'],
            'email' => $row['email'],
            'name' => $row['name'],
            'mobile' => $row['mobile'] ?? ''
        ];
        $jwt = \Firebase\JWT\JWT::encode($payload, JWT_SECRET, 'HS256');
        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful',
            'token' => $jwt,
            'vendor' => $row
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid password',
            'debug' => $verifyError,
            'info' => $debugInfo
        ]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Vendor not found']);
}
$stmt->close();
$conn->close();
?>
