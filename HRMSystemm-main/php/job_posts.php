<?php
require_once 'config.php';
ob_start();

header('Content-Type: application/json');

function jobPostRespond(bool $success, string $message = '', array $extra = []): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function ensureJobPostSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        position_key VARCHAR(30) NOT NULL,
        title VARCHAR(100) NOT NULL,
        salary VARCHAR(100) NOT NULL,
        qualification TEXT NOT NULL,
        description TEXT NOT NULL,
        applicants_needed INT NOT NULL DEFAULT 1,
        status ENUM('published','closed') NOT NULL DEFAULT 'published',
        created_by INT NULL,
        branch_id INT NULL,
        published_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_job_posts_status (status),
        KEY idx_job_posts_position (position_key),
        CONSTRAINT fk_job_posts_creator FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_posts' AND COLUMN_NAME = 'branch_id'");
    $check->execute();
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE job_posts ADD COLUMN branch_id INT NULL AFTER created_by");
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_posts' AND COLUMN_NAME = 'applicants_needed'");
    $check->execute();
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE job_posts ADD COLUMN applicants_needed INT NOT NULL DEFAULT 1 AFTER description");
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'job_post_id'");
    $check->execute();
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE applicants ADD COLUMN job_post_id INT NULL AFTER application_code, ADD KEY idx_applicants_job_post (job_post_id)");
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'branch_id'");
    $check->execute();
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE applicants ADD COLUMN branch_id INT NULL AFTER job_post_id, ADD KEY idx_applicants_branch (branch_id)");
    }
}

function requireJobPostManager(PDO $pdo): int {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        http_response_code(401);
        jobPostRespond(false, 'Your login session has expired. Please sign in again.');
    }
    $stmt = $pdo->prepare("SELECT position, status FROM employees WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $role = trim($user['position'] ?? '');
    $allowed = $user && $user['status'] === 'approved' &&
        ($role === 'Administrator' || stripos($role, 'HR') !== false || strcasecmp($role, 'Human Resources') === 0);
    if (!$allowed) {
        http_response_code(403);
        jobPostRespond(false, 'This action requires an active HR or Administrator account.');
    }
    return $userId;
}

function validateJobPostInput(PDO $pdo): array {
    $positionKey = strtolower(trim($_POST['position_key'] ?? ''));
    $title = trim($_POST['title'] ?? '');
    $salary = trim($_POST['salary'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $branchId = (int)($_POST['branch_id'] ?? 0);
    $applicantsNeeded = (int)($_POST['applicants_needed'] ?? 0);

    if (!in_array($positionKey, ['barista', 'cashier'], true)) {
        jobPostRespond(false, 'Please select Barista or Cashier as the job title.');
    }
    if ($branchId < 1) {
        jobPostRespond(false, 'Please select a branch for the job posting.');
    }
    if ($applicantsNeeded < 1 || $applicantsNeeded > 4) {
        jobPostRespond(false, 'Number of Applicants Needed must be between 1 and 4.');
    }
    $branchCheck = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND status = 'active' LIMIT 1");
    $branchCheck->execute([$branchId]);
    if (!$branchCheck->fetch()) {
        jobPostRespond(false, 'Please select a valid active branch for the job posting.');
    }
    if ($title === '' || $salary === '' || $qualification === '' || $description === '') {
        jobPostRespond(false, 'Job title, salary, qualifications, branch, and description are required.');
    }
    if (mb_strlen($title) > 100 || mb_strlen($salary) > 100) {
        jobPostRespond(false, 'Job title and salary must each be 100 characters or fewer.');
    }
    return [$positionKey, $title, $salary, $qualification, $description, $branchId, $applicantsNeeded];
}

ensureJobPostSchema($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'open') {
    $stmt = $pdo->query("SELECT jp.id, jp.position_key, jp.title, jp.salary, jp.qualification, jp.description, jp.applicants_needed, jp.published_at,
        jp.branch_id, b.name AS branch_name, b.location AS branch_location,
        COALESCE(
          CASE
            WHEN jp.position_key = 'barista' THEN b.required_baristas
            WHEN jp.position_key = 'cashier' THEN b.required_cashiers
            WHEN jp.position_key = 'manager' THEN b.required_managers
            ELSE 0
          END, 0) AS branch_required,
        COALESCE((SELECT COUNT(*) FROM applicants a WHERE a.job_post_id = jp.id), 0) AS applications_received,
        GREATEST(0, jp.applicants_needed - COALESCE((SELECT COUNT(*) FROM applicants a WHERE a.job_post_id = jp.id), 0)) AS remaining
        FROM job_posts jp
        LEFT JOIN branches b ON b.id = jp.branch_id
        WHERE jp.status = 'published' ORDER BY jp.published_at DESC, jp.id DESC");
    jobPostRespond(true, '', ['posts' => $stmt->fetchAll()]);
}

if ($action === 'list') {
    requireJobPostManager($pdo);
    $stmt = $pdo->query("SELECT jp.*, b.name AS branch_name, b.location AS branch_location,
        (SELECT COUNT(*) FROM applicants a WHERE a.job_post_id = jp.id) AS application_count
        FROM job_posts jp
        LEFT JOIN branches b ON b.id = jp.branch_id
        ORDER BY (jp.status = 'published') DESC, jp.updated_at DESC");
    jobPostRespond(true, '', ['posts' => $stmt->fetchAll()]);
}

if ($action === 'publish' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = requireJobPostManager($pdo);
    [$positionKey, $title, $salary, $qualification, $description, $branchId, $applicantsNeeded] = validateJobPostInput($pdo);
    $id = (int)($_POST['id'] ?? 0);

    $duplicate = $pdo->prepare("SELECT id FROM job_posts WHERE position_key = ? AND branch_id = ? AND status = 'published' AND id <> ? LIMIT 1");
    $duplicate->execute([$positionKey, $branchId, $id]);
    if ($duplicate->fetch()) {
        jobPostRespond(false, 'An open posting already exists for that position in the selected branch. Edit or close it first.');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE job_posts SET position_key = ?, title = ?, salary = ?, qualification = ?, description = ?, applicants_needed = ?, branch_id = ?, status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id = ?");
        $stmt->execute([$positionKey, $title, $salary, $qualification, $description, $applicantsNeeded, $branchId, $id]);
        if ($stmt->rowCount() < 1) {
            $exists = $pdo->prepare("SELECT id FROM job_posts WHERE id = ?");
            $exists->execute([$id]);
            if (!$exists->fetch()) jobPostRespond(false, 'The job posting no longer exists.');
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO job_posts (position_key, title, salary, qualification, description, applicants_needed, status, created_by, branch_id, published_at) VALUES (?, ?, ?, ?, ?, ?, 'published', ?, ?, NOW())");
        $stmt->execute([$positionKey, $title, $salary, $qualification, $description, $applicantsNeeded, $userId, $branchId]);
        $id = (int)$pdo->lastInsertId();
    }
    jobPostRespond(true, 'The job posting is now live and accepting applications.', ['id' => $id]);
}

if ($action === 'set_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireJobPostManager($pdo);
    $id = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    if ($id < 1 || !in_array($status, ['published', 'closed'], true)) jobPostRespond(false, 'Invalid job posting status.');
    if ($status === 'published') {
        $stmt = $pdo->prepare("SELECT position_key, branch_id FROM job_posts WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) jobPostRespond(false, 'The job posting no longer exists.');
        $duplicate = $pdo->prepare("SELECT id FROM job_posts WHERE position_key = ? AND branch_id = ? AND status = 'published' AND id <> ? LIMIT 1");
        $duplicate->execute([$row['position_key'], $row['branch_id'], $id]);
        if ($duplicate->fetch()) jobPostRespond(false, 'Another posting for this position in the same branch is already open.');
    }
    $stmt = $pdo->prepare("UPDATE job_posts SET status = ?, published_at = IF(? = 'published', NOW(), published_at) WHERE id = ?");
    $stmt->execute([$status, $status, $id]);
    if ($stmt->rowCount() < 1) jobPostRespond(false, 'The job posting status was not changed.');
    jobPostRespond(true, $status === 'published' ? 'The job posting is open again.' : 'The job posting has been closed.');
}

http_response_code(400);
jobPostRespond(false, 'Invalid job posting action.');
