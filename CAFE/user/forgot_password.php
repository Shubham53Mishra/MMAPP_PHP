<?php
// user/forgot_password.php
// Accepts POST: { email }
// Sends a reset code to user's email if found

header('Content-Type: application/json');
require_once __DIR__ . '/../common_cafe/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Email required']);
    exit;
}

$stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $userId = $row['id'];
    $code = rand(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + 600);
    $stmt2 = $conn->prepare('UPDATE users SET reset_code=?, reset_expires=? WHERE id=?');
    $stmt2->bind_param('ssi', $code, $expires, $userId);
    $stmt2->execute();
    $stmt2->close();
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'shubhammishra2310@gmail.com';
        $mail->Password = 'zxbe cpmc smat iugc';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('shubhammishra2310@gmail.com', 'MunchMart User');
        $mail->addAddress($email);
        $mail->Subject = 'User Password Reset Code';
        $mail->Body = "Your reset code is: $code";
        $mail->send();
    echo json_encode(['status' => 'success', 'message' => 'Reset code sent to email']);
    } catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Email send failed: ' . $mail->ErrorInfo]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Email not found']);
}
$stmt->close();
$conn->close();
