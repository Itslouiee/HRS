<?php
require_once 'config.php';
ob_start();

header('Content-Type: application/json');

function branchRespond(bool $success, string $message = '', array $extra = []): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function isAdmin(): bool {
    return !empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'Administrator';
}

function isAdminOrHr(): bool {
    global $pdo;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT position, status FROM employees WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'approved') {
        return false;
    }
    $role = trim($user['position']);
    return $role === 'Administrator' || stripos($role, 'HR') !== false || strcasecmp($role, 'Human Resources') === 0;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    location VARCHAR(255) NOT NULL,
    contact_details VARCHAR(255) NOT NULL,
    required_baristas INT NOT NULL DEFAULT 0,
    required_cashiers INT NOT NULL DEFAULT 0,
    required_managers INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$checkBaristaReq = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'required_baristas'");
$checkBaristaReq->execute();
if (!$checkBaristaReq->fetchColumn()) {
    $pdo->exec("ALTER TABLE branches ADD COLUMN required_baristas INT NOT NULL DEFAULT 0 AFTER contact_details");
}
$checkCashierReq = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'required_cashiers'");
$checkCashierReq->execute();
if (!$checkCashierReq->fetchColumn()) {
    $pdo->exec("ALTER TABLE branches ADD COLUMN required_cashiers INT NOT NULL DEFAULT 0 AFTER required_baristas");
}
$checkManagerReq = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'required_managers'");
$checkManagerReq->execute();
if (!$checkManagerReq->fetchColumn()) {
    $pdo->exec("ALTER TABLE branches ADD COLUMN required_managers INT NOT NULL DEFAULT 0 AFTER required_cashiers");
}

$employeesTableExists = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'")->fetchColumn();
$hasEmployeeBranchId = false;
if ($employeesTableExists) {
    $employeeBranchColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'branch_id'");
    $employeeBranchColumn->execute();
    $hasEmployeeBranchId = (bool)$employeeBranchColumn->fetchColumn();
    if (!$hasEmployeeBranchId) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN branch_id INT NULL AFTER position");
        $hasEmployeeBranchId = true;
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list') {
    if (!isAdminOrHr()) {
        http_response_code(403);
        branchRespond(false, 'Administrator or HR access is required.');
    }
    $baseQuery = "SELECT b.id, b.name, b.location, b.contact_details, b.status, b.created_at,
        b.required_baristas, b.required_cashiers, b.required_managers,
        COALESCE((SELECT COUNT(*) FROM employees e WHERE e.branch_id = b.id AND e.status = 'approved' AND LOWER(e.position) LIKE '%barista%'), 0) AS current_baristas,
        COALESCE((SELECT COUNT(*) FROM employees e WHERE e.branch_id = b.id AND e.status = 'approved' AND LOWER(e.position) LIKE '%cashier%'), 0) AS current_cashiers,
        COALESCE((SELECT COUNT(*) FROM employees e WHERE e.branch_id = b.id AND e.status = 'approved' AND (LOWER(e.position) LIKE '%manager%' OR LOWER(e.position) LIKE '%supervisor%')), 0) AS current_managers,
        COALESCE((SELECT COUNT(*) FROM applicants a WHERE a.branch_id = b.id), 0) AS applicant_count
        FROM branches b";
    if (!isAdmin()) {
        $baseQuery .= " WHERE b.status = 'active'";
    }
    $baseQuery .= " ORDER BY b.created_at DESC";
    $stmt = $pdo->query($baseQuery);
    $branches = $stmt->fetchAll();
    branchRespond(true, '', ['branches' => $branches]);
}

if (!isAdmin()) {
    http_response_code(403);
    branchRespond(false, 'Administrator access is required.');
}

if ($action === 'create' || $action === 'update') {
    $id = (int)($_POST['branch_id'] ?? 0);
    $name = trim($_POST['branch_name'] ?? '');
    $location = trim($_POST['branch_location'] ?? '');
    $contact = trim($_POST['branch_contact'] ?? '');
    $status = trim($_POST['branch_status'] ?? 'active');
    $requiredBaristas = (int)($_POST['required_baristas'] ?? 0);
    $requiredCashiers = (int)($_POST['required_cashiers'] ?? 0);
    $requiredManagers = (int)($_POST['required_managers'] ?? 0);

    if ($requiredBaristas < 0 || $requiredBaristas > 4 || $requiredCashiers < 0 || $requiredCashiers > 4 || $requiredManagers < 0 || $requiredManagers > 4) {
        http_response_code(400);
        branchRespond(false, 'Branch staffing requirements must be between 0 and 4 for each role.');
    }

    if (!$name || !$location || !$contact || !in_array($status, ['active', 'inactive'], true)) {
        http_response_code(400);
        branchRespond(false, 'Please provide valid branch name, location, contact details, and status.');
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare('INSERT INTO branches (name, location, contact_details, required_baristas, required_cashiers, required_managers, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $location, $contact, $requiredBaristas, $requiredCashiers, $requiredManagers, $status]);
        branchRespond(true, 'Branch created successfully.', ['id' => (int)$pdo->lastInsertId()]);
    }

    if ($id < 1) {
        http_response_code(400);
        branchRespond(false, 'Invalid branch ID.');
    }

    $stmt = $pdo->prepare('UPDATE branches SET name = ?, location = ?, contact_details = ?, required_baristas = ?, required_cashiers = ?, required_managers = ?, status = ? WHERE id = ?');
    $stmt->execute([$name, $location, $contact, $requiredBaristas, $requiredCashiers, $requiredManagers, $status, $id]);
    branchRespond(true, 'Branch updated successfully.');
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) {
        http_response_code(400);
        branchRespond(false, 'Invalid branch ID.');
    }
    $stmt = $pdo->prepare('DELETE FROM branches WHERE id = ?');
    $stmt->execute([$id]);
    branchRespond(true, 'Branch deleted successfully.');
}

http_response_code(400);
branchRespond(false, 'Invalid branch action.');
