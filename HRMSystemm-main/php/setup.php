<?php
// One-time setup: creates the database, employees table, and seeds
// an Admin account and an approved HR account so the flow is testable.
// Run once in the browser: http://localhost/HRMSYSTEM/php/setup.php

$host = 'localhost';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;charset=$charset";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS quadra_hrms");
$pdo->exec("USE quadra_hrms");

$pdo->exec("CREATE TABLE IF NOT EXISTS employees (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(100) NOT NULL UNIQUE,
    contact_no VARCHAR(11) NULL,
    username   VARCHAR(50)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    position   VARCHAR(50)  NOT NULL,
    branch_id  INT NULL,
    status     ENUM('pending','approved','inactive','rejected') NOT NULL DEFAULT 'pending',
    access_level ENUM('limited','full') NOT NULL DEFAULT 'full',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pinColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'pin'");
$pinColumn->execute();
if (!$pinColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE employees ADD COLUMN pin VARCHAR(255) NULL AFTER password");
}


$accessLevelColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'access_level'");
$accessLevelColumn->execute();
if (!$accessLevelColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE employees ADD COLUMN access_level ENUM('limited','full') NOT NULL DEFAULT 'full' AFTER status");
}
$branchColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'branch_id'");
$branchColumn->execute();
if (!$branchColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE employees ADD COLUMN branch_id INT NULL AFTER position");
}
$contactColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'contact_no'");
$contactColumn->execute();
if (!$contactColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE employees ADD COLUMN contact_no VARCHAR(11) NULL AFTER email");
}

$statusColumn = $pdo->query("SHOW COLUMNS FROM employees LIKE 'status'")->fetch();
if ($statusColumn && strpos($statusColumn['Type'], "'inactive'") === false) {
    $pdo->exec("ALTER TABLE employees MODIFY status ENUM('pending','approved','inactive','rejected') DEFAULT 'pending'");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS applicants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_code VARCHAR(30) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NOT NULL,
    birthdate DATE NOT NULL,
    gender VARCHAR(30) NOT NULL,
    civil_status VARCHAR(30) NOT NULL,
    email VARCHAR(100) NOT NULL,
    contact_no VARCHAR(30) NOT NULL,
    address TEXT NOT NULL,
    position VARCHAR(80) NOT NULL,
    employment_type VARCHAR(40) NOT NULL,
    years_experience VARCHAR(30) NOT NULL,
    preferred_shift VARCHAR(40) NOT NULL,
    education VARCHAR(100) NOT NULL,
    school VARCHAR(150) NOT NULL,
    course VARCHAR(150) NOT NULL,
    year_graduated VARCHAR(10) NOT NULL,
    high_school VARCHAR(150) NOT NULL,
    high_school_year VARCHAR(10) NOT NULL,
    honors VARCHAR(255) NULL,
    resume_name VARCHAR(255) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    hr_remarks TEXT NULL,
    interview_date DATE NULL,
    interview_time VARCHAR(50) NULL,
    interview_location VARCHAR(255) NULL,
    email_sent_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_applicant_status (status)
)");

$posRequiredColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'pos_account_required'");
$posRequiredColumn->execute();
if (!$posRequiredColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE applicants ADD COLUMN pos_account_required TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN pos_account_created_at DATETIME NULL");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS job_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    position_key VARCHAR(30) NOT NULL,
    title VARCHAR(100) NOT NULL,
    salary VARCHAR(100) NOT NULL,
    qualification TEXT NOT NULL,
    description TEXT NOT NULL,
    status ENUM('published','closed') NOT NULL DEFAULT 'published',
    created_by INT NULL,
    branch_id INT NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_job_posts_status (status),
    KEY idx_job_posts_position (position_key)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    work_date DATE NOT NULL,
    clock_in DATETIME NOT NULL,
    break_start DATETIME NULL,
    break_end DATETIME NULL,
    clock_out DATETIME NULL,
    status ENUM('on_time','late') NOT NULL DEFAULT 'on_time',
    day_status ENUM('in_progress','full_day','half_day','undertime') NOT NULL DEFAULT 'in_progress',
    approval_status ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    late_minutes INT NOT NULL DEFAULT 0,
    undertime_minutes INT NOT NULL DEFAULT 0,
    overtime_minutes INT NOT NULL DEFAULT 0,
    worked_seconds INT NOT NULL DEFAULT 0,
    attendance_remark VARCHAR(100) NOT NULL DEFAULT 'In Progress',
    admin_note TEXT NULL,
    auto_clock_out TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_employee_date (employee_id, work_date),
    KEY idx_attendance_date (work_date),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
)");

foreach (['break_start' => 'DATETIME NULL AFTER clock_in', 'break_end' => 'DATETIME NULL AFTER break_start'] as $column => $definition) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = ?");
    $check->execute([$column]);
    if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE attendance_records ADD COLUMN {$column} {$definition}");
}

foreach ([
    'day_status' => "ENUM('in_progress','full_day','half_day','undertime') NOT NULL DEFAULT 'in_progress' AFTER status",
    'approval_status' => "ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required' AFTER day_status",
    'reviewed_by' => 'INT NULL AFTER approval_status',
    'reviewed_at' => 'DATETIME NULL AFTER reviewed_by',
    'late_minutes' => 'INT NOT NULL DEFAULT 0 AFTER reviewed_at',
    'undertime_minutes' => 'INT NOT NULL DEFAULT 0 AFTER late_minutes',
    'overtime_minutes' => 'INT NOT NULL DEFAULT 0 AFTER undertime_minutes',
    'worked_seconds' => 'INT NOT NULL DEFAULT 0 AFTER overtime_minutes',
    'attendance_remark' => "VARCHAR(100) NOT NULL DEFAULT 'In Progress' AFTER worked_seconds",
    'admin_note' => 'TEXT NULL AFTER attendance_remark',
    'auto_clock_out' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_note'
] as $column => $definition) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = ?");
    $check->execute([$column]);
    if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE attendance_records ADD COLUMN {$column} {$definition}");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    reference_code VARCHAR(40) NOT NULL,
    leave_type ENUM('vacation','sick','personal','emergency','maternity') NOT NULL,
    pay_type ENUM('with-pay','without-pay') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    days DECIMAL(5,1) NOT NULL,
    contact_no VARCHAR(11) NOT NULL,
    reason TEXT NOT NULL,
    leave_during ENUM('whole-day','half-day-am','half-day-pm') NOT NULL,
    comments TEXT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_leave_reference (reference_code),
    KEY idx_leave_employee (employee_id),
    KEY idx_leave_status (status),
    KEY idx_leave_dates (start_date, end_date),
    CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_leave_reviewer FOREIGN KEY (reviewed_by) REFERENCES employees(id) ON DELETE SET NULL
)");

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

$branchReqBaristas = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'required_baristas'");
$branchReqBaristas->execute();
if (!$branchReqBaristas->fetchColumn()) {
    $pdo->exec("ALTER TABLE branches ADD COLUMN required_baristas INT NOT NULL DEFAULT 0 AFTER contact_details");
}
$branchReqCashiers = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'required_cashiers'");
$branchReqCashiers->execute();
if (!$branchReqCashiers->fetchColumn()) {
    $pdo->exec("ALTER TABLE branches ADD COLUMN required_cashiers INT NOT NULL DEFAULT 0 AFTER required_baristas");
}
$branchReqManagers = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'required_managers'");
$branchReqManagers->execute();
if (!$branchReqManagers->fetchColumn()) {
    $pdo->exec("ALTER TABLE branches ADD COLUMN required_managers INT NOT NULL DEFAULT 0 AFTER required_cashiers");
}

$jobPostColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'job_post_id'");
$jobPostColumn->execute();
if (!$jobPostColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE applicants ADD COLUMN job_post_id INT NULL AFTER application_code, ADD KEY idx_applicants_job_post (job_post_id)");
}

$jobBranchColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'branch_id'");
$jobBranchColumn->execute();
if (!$jobBranchColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE applicants ADD COLUMN branch_id INT NULL AFTER job_post_id, ADD KEY idx_applicants_branch (branch_id)");
}

// Add resume storage path for existing applicants tables.
$resumePathColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'resume_path'");
$resumePathColumn->execute();
if (!$resumePathColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE applicants ADD COLUMN resume_path VARCHAR(255) NULL AFTER resume_name");
}

foreach ([
    'employee_id' => "VARCHAR(30) NULL AFTER application_code",
    'interview_stage' => "VARCHAR(20) NULL AFTER status",
    'interview_result' => "VARCHAR(20) NULL AFTER interview_stage",
    'initial_interviewer_id' => "INT NULL AFTER interview_result",
    'initial_interviewed_at' => "DATETIME NULL AFTER initial_interviewer_id",
    'initial_interview_result' => "VARCHAR(20) NULL AFTER initial_interviewed_at",
    'final_interviewer_id' => "INT NULL AFTER initial_interview_result",
    'final_interviewed_at' => "DATETIME NULL AFTER final_interviewer_id",
    'final_interview_result' => "VARCHAR(20) NULL AFTER final_interviewed_at"
] as $column => $definition) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'applicants' AND COLUMN_NAME = ?");
    $check->execute([$column]);
    if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE applicants ADD COLUMN {$column} {$definition}");
}

$resultEmailColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'quadra_hrms' AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'result_email_sent_at'");
$resultEmailColumn->execute();
if (!$resultEmailColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE applicants ADD COLUMN result_email_sent_at DATETIME NULL AFTER email_sent_at");
}

// Keep applications created with the older workflow visible in the new interview flow.
$pdo->exec("UPDATE applicants SET status = 'initial_interview_pending' WHERE status = 'hr_qualified'");
$pdo->exec("UPDATE applicants SET status = 'rejected' WHERE status IN ('hr_rejected', 'admin_rejected')");
$pdo->exec("UPDATE applicants SET status = 'hired' WHERE status = 'admin_approved'");

seed($pdo, 'System Administrator', 'admin@quadra.com', 'admin', 'admin123', 'Administrator', '123456');
seed($pdo, 'Stephanie Nicole Briones', 'hr@quadra.com', 'hr', 'hr12345', 'HR Manager', '654321');
seed($pdo, 'Finance Officer', 'finance@quadra.com', 'finance', 'finance123', 'Finance Officer', '456789');
seed($pdo, 'Hiring Manager', 'manager@quadra.com', 'manager', 'manager123', 'Hiring Manager', '789012');

echo "Setup complete.<br>";
echo "Admin PIN: 123456<br>";
echo "HR PIN: 654321<br>";
echo "Finance PIN: 456789<br>";
echo "Hiring Manager PIN: 789012";

function seed($pdo, $name, $email, $username, $pw, $pos, $pin) {
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE username = ? OR email = ? LIMIT 1");
    $stmt->execute([$username, $email]);
    $existingId = $stmt->fetchColumn();
    $passwordHash = password_hash($pw, PASSWORD_DEFAULT);
    $pinHash = password_hash($pin, PASSWORD_DEFAULT);

    if ($existingId) {
        // System accounts are fixed: restore their identity, role, approval, and PIN whenever setup runs.
        $stmt = $pdo->prepare("UPDATE employees SET full_name = ?, email = ?, username = ?, password = ?, pin = ?, position = ?, status = 'approved' WHERE id = ?");
        $stmt->execute([$name, $email, $username, $passwordHash, $pinHash, $pos, $existingId]);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO employees (full_name, email, username, password, pin, position, status)
                           VALUES (?, ?, ?, ?, ?, ?, 'approved')");
    $stmt->execute([$name, $email, $username, $passwordHash, $pinHash, $pos]);
}
