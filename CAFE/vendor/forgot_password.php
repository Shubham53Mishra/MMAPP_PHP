<?php
// vendor/forgot_password.php
// Accepts POST: { email }
// Sends a reset code to vendor's email if found




header('Content-Type: application/json');

require_once __DIR__ . '../common_cafe/db.php';
require_once __DIR__ . '../vendor/autoload.php';

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
if (!$email) {
    echo json_encode(['success' => false, 'error' => 'Email required']);
    exit;
}

// Check if vendor exists
$stmt = $conn->prepare('SELECT id FROM vendors WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $vendorId = $row['id'];
    // Generate a 6-digit code
    $code = rand(100000, 999999);
    // Save code and expiry (10 min) in DB
    $expires = date('Y-m-d H:i:s', time() + 600);
    $stmt2 = $conn->prepare('UPDATE vendors SET reset_code=?, reset_expires=? WHERE id=?');
    $stmt2->bind_param('ssi', $code, $expires, $vendorId);
    $stmt2->execute();
    $stmt2->close();
    // Send email using PHPMailer
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'shubhammishra2310@gmail.com'; // Your Gmail
        $mail->Password = 'zxbe cpmc smat iugc'; // Your Gmail App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('shubhammishra2310@gmail.com', 'MunchMart Vendor');
        $mail->addAddress($email);
        $mail->Subject = 'Vendor Password Reset Code';
        $mail->Body = "Your reset code is: $code";
        $mail->send();
        echo json_encode(['success' => true, 'message' => 'Reset code sent to email']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Email send failed: ' . $mail->ErrorInfo]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Email not found']);
}
$stmt->close();
$conn->close();
