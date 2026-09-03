<?php
require_once __DIR__ . '/config.php';

function denyPortalAccess(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: Login.html?session=expired', true, 302);
    exit;
}

function requirePortalRole(string $requiredRole): void {
    global $pdo;

    header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) denyPortalAccess();

    $stmt = $pdo->prepare('SELECT full_name, position, status, access_level FROM employees WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'approved') denyPortalAccess();

    $position = trim($user['position']);
    $isAdmin = $position === 'Administrator';
    $isHr = !$isAdmin && (stripos($position, 'HR') !== false || strcasecmp($position, 'Human Resources') === 0);
    $isFinance = !$isAdmin && !$isHr && stripos($position, 'finance') !== false;
    $isManager = !$isAdmin && !$isHr && !$isFinance && stripos($position, 'manager') !== false;
    $allowed = $requiredRole === 'admin'
        ? $isAdmin
        : ($requiredRole === 'hr'
            ? $isHr
            : ($requiredRole === 'finance'
                ? $isFinance
                : ($requiredRole === 'manager'
                    ? ($isManager || $isAdmin)
                    : (!$isAdmin && !$isHr && !$isFinance && !$isManager))));
    if (!$allowed) denyPortalAccess();

    $_SESSION['user_role'] = $position;
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['access_level'] = $user['access_level'] ?? 'full';
}
