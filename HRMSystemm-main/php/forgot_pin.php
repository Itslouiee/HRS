<?php
require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter the registered email address saved in your employee account.']);
    exit;
}

$columns = [
    'pending_pin_hash' => "ALTER TABLE employees ADD COLUMN pending_pin_hash VARCHAR(255) NULL AFTER pin",
    'pending_pin_token' => "ALTER TABLE employees ADD COLUMN pending_pin_token VARCHAR(255) NULL AFTER pending_pin_hash",
    'pending_pin_code' => "ALTER TABLE employees ADD COLUMN pending_pin_code VARCHAR(255) NULL AFTER pending_pin_token",
    'pending_pin_expires_at' => "ALTER TABLE employees ADD COLUMN pending_pin_expires_at DATETIME NULL AFTER pending_pin_code",
];
foreach ($columns as $column => $sql) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = ?");
    $check->execute([$column]);
    if (!$check->fetchColumn()) $pdo->exec($sql);
}

$stmt = $pdo->prepare("SELECT id, full_name, email FROM employees WHERE LOWER(email) = ? AND status = 'approved' AND position <> 'Administrator' AND position NOT LIKE '%HR%' AND position NOT LIKE '%Manager%' AND position NOT LIKE '%Finance%' LIMIT 1");
$stmt->execute([$email]);
$employee = $stmt->fetch();
if (!$employee) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'That email does not match any active employee account. Please use the email saved in the database.']);
    exit;
}

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expiresAt = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/php/forgot_pin.php')), '/');
$confirmUrl = $scheme . '://' . $host . $basePath . '/profile.php?action=confirm_pin&token=' . urlencode($token);

$save = $pdo->prepare("UPDATE employees SET pending_pin_hash = NULL, pending_pin_token = ?, pending_pin_code = NULL, pending_pin_expires_at = ? WHERE id = ?");
$save->execute([$tokenHash, $expiresAt, $employee['id']]);

$subject = 'Reset Your Quadra Cafe Employee PIN';
$message = "Dear {$employee['full_name']},\n\nWe received a Forgot PIN request for your Quadra Cafe employee account.\n\nClick this secure link to confirm the reset:\n{$confirmUrl}\n\nAfter confirmation, your new 6-digit PIN will be emailed to this same registered address. Your current PIN remains active until you confirm. This link expires in 30 minutes.\n\nIf you did not request this reset, ignore this email.\n\nRegards,\nQuadra Cafe HR Team";

try {
    require_once 'send_email.php';
    sendQuadraEmail($employee['email'], $employee['full_name'], $subject, $message);
} catch (Throwable $error) {
    $pdo->prepare("UPDATE employees SET pending_pin_token = NULL, pending_pin_expires_at = NULL WHERE id = ?")->execute([$employee['id']]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The reset email could not be sent. Please try again later.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'A secure PIN reset link was sent to your registered employee email.']);
