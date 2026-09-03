<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');

$requiredRole = trim($_GET['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$valid = false;

if ($userId > 0 && in_array($requiredRole, ['admin', 'hr', 'finance', 'manager', 'employee'], true)) {
    $stmt = $pdo->prepare('SELECT position, status, access_level FROM employees WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user && $user['status'] === 'approved') {
        $position = trim($user['position']);
        $isAdmin = $position === 'Administrator';
        $isHr = !$isAdmin && (stripos($position, 'HR') !== false || strcasecmp($position, 'Human Resources') === 0);
        $isFinance = !$isAdmin && !$isHr && stripos($position, 'finance') !== false;
        $isManager = !$isAdmin && !$isHr && !$isFinance && stripos($position, 'manager') !== false;
        $valid = $requiredRole === 'admin'
            ? $isAdmin
            : ($requiredRole === 'hr'
                ? $isHr
                : ($requiredRole === 'finance'
                    ? $isFinance
                    : ($requiredRole === 'manager'
                        ? $isManager
                        : (!$isAdmin && !$isHr && !$isFinance && !$isManager))));
    }
}

if (!$valid) http_response_code(401);
echo json_encode(['success' => $valid, 'access_level' => $valid ? ($user['access_level'] ?? 'full') : null]);
