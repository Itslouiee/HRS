<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');

function loginRespond(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function accountPortalRole(string $position): string {
    if ($position === 'Administrator') return 'admin';
    if (stripos($position, 'finance') !== false && stripos($position, 'manager') === false) return 'finance';
    if (stripos($position, 'HR') !== false || strcasecmp($position, 'Human Resources') === 0) return 'hr';
    if (stripos($position, 'manager') !== false) return 'manager';
    return 'employee';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') loginRespond(false, 'Invalid request.');

$identifier = trim($_POST['identifier'] ?? '');
$pin = trim($_POST['pin'] ?? '');
if (!preg_match('/^\d{6}$/', $pin)) loginRespond(false, 'Enter a valid 6-digit access PIN.');

$user = null;
if ($identifier !== '') {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $candidate = $stmt->fetch();
    if ($candidate && !empty($candidate['pin']) && password_verify($pin, $candidate['pin'])) $user = $candidate;
} else {
    // Privileged PINs are reserved even if an employee happens to have the same PIN.
    $stmt = $pdo->query("SELECT * FROM employees WHERE pin IS NOT NULL AND pin <> ''");
    $candidates = $stmt->fetchAll();
    foreach ($candidates as $candidate) {
        if (accountPortalRole((string)$candidate['position']) === 'employee') continue;
        if (password_verify($pin, $candidate['pin'])) {
            loginRespond(false, 'Username or email is required for Admin, HR, Manager, and Finance accounts.');
        }
    }
    // PIN-only authentication is intentionally limited to ordinary employees.
    foreach ($candidates as $candidate) {
        if (accountPortalRole((string)$candidate['position']) !== 'employee') continue;
        if (password_verify($pin, $candidate['pin'])) { $user = $candidate; break; }
    }
}

if (!$user) {
    loginRespond(false, $identifier === ''
        ? 'Invalid employee PIN. Admin, HR, Manager, and Finance accounts must also enter their username or email.'
        : 'Invalid username/email or PIN.');
}

$portalRole = accountPortalRole((string)$user['position']);
if ($portalRole !== 'employee' && $identifier === '') loginRespond(false, 'Username or email is required for this account role.');

if ($user['status'] === 'pending') loginRespond(false, 'Account not yet approved. Please wait for the admin to accept your account.');
if ($user['status'] === 'rejected') loginRespond(false, 'This employee account was not approved. Please contact HR.');
if ($user['status'] === 'inactive') loginRespond(false, 'This employee account is not active. Please contact HR.');
if ($user['status'] !== 'approved') loginRespond(false, 'This account is not allowed to sign in.');

$redirects = [
    'admin' => 'Admin.html',
    'hr' => 'Hr.html',
    'manager' => 'Manager.html',
    'finance' => 'Finance.html',
    'employee' => 'Employee.html',
];
if (!isset($redirects[$portalRole])) loginRespond(false, 'This account role does not have an assigned portal.');

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['user_name'] = $user['full_name'];
$_SESSION['user_role'] = $user['position'];
$_SESSION['portal_role'] = $portalRole;
$_SESSION['access_level'] = $user['access_level'] ?? 'full';
$_SESSION['login_started_at'] = time();
$_SESSION['last_activity'] = time();

loginRespond(true, 'Login successful.', [
    'redirect' => $redirects[$portalRole],
    'name' => $user['full_name'],
    'role' => $portalRole,
    'access_level' => $user['access_level'] ?? 'full',
]);
