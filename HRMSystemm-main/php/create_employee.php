<?php
require_once 'config.php';

header('Content-Type: application/json');

function ensureEmployeeContactColumn(PDO $pdo): void {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'contact_no'");
    $check->execute();
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN contact_no VARCHAR(11) NULL AFTER email");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_face_profiles (
        face_profile_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL UNIQUE,
        face_descriptor JSON NOT NULL,
        reference_photo_path VARCHAR(255) NOT NULL,
        blink_verified TINYINT(1) NOT NULL DEFAULT 1,
        enrolled_by INT NOT NULL,
        enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_face_profile_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        CONSTRAINT fk_face_profile_admin FOREIGN KEY (enrolled_by) REFERENCES employees(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

ensureEmployeeContactColumn($pdo);

function normalizedHiringPosition(string $position): string {
    $value = strtolower(trim($position));
    if (strpos($value, 'barista') !== false) return 'barista';
    if (strpos($value, 'cashier') !== false) return 'cashier';
    return $value;
}

function ensureHiringSlotAvailable(PDO $pdo, string $position): ?string {
    $normalized = normalizedHiringPosition($position);
    if (!in_array($normalized, ['barista', 'cashier'], true)) {
        return 'Only Barista and Cashier positions can be hired through this form.';
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE status = 'approved' AND LOWER(position) LIKE ?");
    $stmt->execute(['%' . $normalized . '%']);
    if ((int)$stmt->fetchColumn() >= 4) {
        return ucfirst($normalized) . ' is already full. Set an existing employee to Not Active/Resigned before hiring another one.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// Employee accounts may only be created by the Administrator.
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Administrator') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the Administrator can create employee accounts.']);
    exit;
}

$full_name = trim($_POST['full_name'] ?? '');
$email     = trim($_POST['email'] ?? '');
$contact_no = trim($_POST['contact_no'] ?? '');
$username  = trim($_POST['username'] ?? '');
$position  = trim($_POST['position'] ?? 'Employee');
$applicant_id = (int)($_POST['applicant_id'] ?? 0);
$faceDescriptorJson = trim($_POST['face_descriptor'] ?? '');
$facePhoto = trim($_POST['face_photo'] ?? '');
$livenessVerified = ($_POST['liveness_verified'] ?? '') === '1';

if (!$full_name || !$email || !$contact_no || !$username) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if (!preg_match('/^\d{11}$/', $contact_no)) {
    echo json_encode(['success' => false, 'message' => 'Contact number must be exactly 11 digits.']);
    exit;
}
$faceDescriptor = json_decode($faceDescriptorJson, true);
if (!$livenessVerified || !is_array($faceDescriptor) || count($faceDescriptor) !== 128) {
    echo json_encode(['success' => false, 'message' => 'Complete the employee face and head-turn verification before creating the account.']);
    exit;
}
foreach ($faceDescriptor as $value) {
    if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > 5) {
        echo json_encode(['success' => false, 'message' => 'The captured face profile is invalid. Please enroll the employee again.']);
        exit;
    }
}
if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $facePhoto, $photoMatch)) {
    echo json_encode(['success' => false, 'message' => 'A valid live employee face photo is required.']);
    exit;
}
$faceBinary = base64_decode($photoMatch[1], true);
if ($faceBinary === false || strlen($faceBinary) < 1000 || strlen($faceBinary) > 3 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'The employee face photo is invalid or too large.']);
    exit;
}

// HR creates employee accounts only; privileged accounts cannot be created here.
if (stripos($position, 'administrator') !== false || stripos($position, 'HR') !== false || stripos($position, 'manager') !== false || stripos($position, 'finance') !== false) {
    echo json_encode(['success' => false, 'message' => 'This form can create employee accounts only.']);
    exit;
}
$slotError = ensureHiringSlotAvailable($pdo, $position);
if ($slotError) {
    echo json_encode(['success' => false, 'message' => $slotError]);
    exit;
}

// Prevent duplicate email / username
$stmt = $pdo->prepare("SELECT id FROM employees WHERE email = ? OR username = ? LIMIT 1");
$stmt->execute([$email, $username]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Email or username already exists.']);
    exit;
}

$pin = (string)random_int(100000, 999999);
$pinHash = password_hash($pin, PASSWORD_DEFAULT);
$passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$subject = 'Your Quadra Cafe Employee Account PIN';
$message = "Dear {$full_name},\n\nYour Quadra Cafe employee account has been created.\n\nAccess PIN: {$pin}\n\nPlease remember this PIN and keep it private. You will use it to sign in to your employee account.\n\nRegards,\nQuadra Cafe HR Team";

try {
    require_once 'send_email.php';
    sendQuadraEmail($email, $full_name, $subject, $message);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The employee account was not created because the PIN email could not be sent: ' . $error->getMessage()]);
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO employees (full_name, email, contact_no, username, password, pin, position, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'approved')");
    $stmt->execute([$full_name, $email, $contact_no, $username, $passwordHash, $pinHash, $position]);
    $employeeId = (int)$pdo->lastInsertId();

    $faceDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'face_profiles';
    if (!is_dir($faceDirectory) && !mkdir($faceDirectory, 0755, true)) throw new RuntimeException('Unable to prepare secure face profile storage.');
    $faceFileName = bin2hex(random_bytes(24)) . '.jpg';
    $absoluteFacePath = $faceDirectory . DIRECTORY_SEPARATOR . $faceFileName;
    if (file_put_contents($absoluteFacePath, $faceBinary, LOCK_EX) === false) throw new RuntimeException('Unable to save the employee face profile.');
    $facePath = 'uploads/face_profiles/' . $faceFileName;
    $faceStmt = $pdo->prepare("INSERT INTO employee_face_profiles (employee_id, face_descriptor, reference_photo_path, blink_verified, enrolled_by) VALUES (?, ?, ?, 1, ?)");
    $faceStmt->execute([$employeeId, json_encode(array_map('floatval', $faceDescriptor)), $facePath, (int)$_SESSION['user_id']]);

    if ($applicant_id > 0) {
        $stmt = $pdo->prepare("UPDATE applicants SET status = 'hired', employee_id = ? WHERE id = ? AND status IN ('final_interview_passed','hired') AND employee_id IS NULL");
        $stmt->execute([(string)$employeeId, $applicant_id]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!empty($absoluteFacePath) && is_file($absoluteFacePath)) unlink($absoluteFacePath);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The employee account or face profile could not be saved. Please try again.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Employee account and verified face profile created successfully. The access PIN was emailed to ' . $email . '.'
]);
