<?php
require_once 'config.php';
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');

function respond(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function requireRole(string $role): void {
    global $pdo;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        http_response_code(401);
        respond(false, 'Your login session has expired. Please sign in again.');
    }
    $userStmt = $pdo->prepare("SELECT position, status FROM employees WHERE id = ? LIMIT 1");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user || $user['status'] !== 'approved') {
        http_response_code(403);
        respond(false, 'Your account is not active or could not be verified. Please sign in again.');
    }
    $currentRole = trim($user['position']);
    $_SESSION['user_role'] = $currentRole;
    $isAdmin = $currentRole === 'Administrator';
    $isHr = !$isAdmin && (stripos($currentRole, 'HR') !== false || strcasecmp($currentRole, 'Human Resources') === 0);
    $isManager = !$isAdmin && !$isHr && stripos($currentRole, 'manager') !== false;
    $allowed = $role === 'admin'
        ? $isAdmin
        : ($role === 'hr'
            ? ($isAdmin || $isHr)
            : ($role === 'manager'
                ? ($isAdmin || $isManager)
                : false));
    if (!$allowed) {
        http_response_code(403);
        if ($role === 'admin') {
            respond(false, 'This action requires an Administrator account.');
        } elseif ($role === 'manager') {
            respond(false, 'This action requires an active Hiring Manager or Administrator account.');
        }
        respond(false, 'This action requires an active HR or Administrator account.');
    }
}

function normalizedHiringPosition(string $position): string {
    $value = strtolower(trim($position));
    if (strpos($value, 'barista') !== false) return 'barista';
    if (strpos($value, 'cashier') !== false) return 'cashier';
    return $value;
}

function isPositionAvailable(PDO $pdo, string $position): bool {
    $normalized = normalizedHiringPosition($position);
    if (!in_array($normalized, ['barista', 'cashier'], true)) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE status = 'approved' AND LOWER(position) LIKE ?");
    $stmt->execute(['%' . $normalized . '%']);
    return (int)$stmt->fetchColumn() < 4;
}

function findOpenJobPost(PDO $pdo, string $position): ?array {
    $normalized = normalizedHiringPosition($position);
    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_posts'")->fetchColumn();
    if (!$table) return null;
    $stmt = $pdo->prepare("SELECT id, title, branch_id FROM job_posts WHERE position_key = ? AND status = 'published' ORDER BY published_at DESC, id DESC LIMIT 1");
    $stmt->execute([$normalized]);
    $post = $stmt->fetch();
    return $post ?: null;
}

function uniqueEmployeeUsername(PDO $pdo, string $email, string $firstName, string $lastName): string {
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', strstr($email, '@', true) ?: ($firstName . $lastName)));
    if ($base === '') $base = 'employee';
    $base = substr($base, 0, 42);
    $username = $base;
    $counter = 1;
    $check = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE username = ?');
    while (true) {
        $check->execute([$username]);
        if (!(int)$check->fetchColumn()) return $username;
        $username = $base . $counter++;
    }
}

function createOnboardingEmployee(PDO $pdo, array $applicant): int {
    if (!empty($applicant['employee_id'])) return (int)$applicant['employee_id'];
    $existing = $pdo->prepare('SELECT id, status FROM employees WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $existing->execute([$applicant['email']]);
    if ($row = $existing->fetch()) {
        if ($row['status'] === 'approved') throw new RuntimeException('An active employee account already uses this email.');
        return (int)$row['id'];
    }
    $name = trim(implode(' ', array_filter([$applicant['first_name'], $applicant['middle_name'] ?? '', $applicant['last_name']])));
    $username = uniqueEmployeeUsername($pdo, $applicant['email'], $applicant['first_name'], $applicant['last_name']);
    $password = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO employees (full_name, email, contact_no, username, password, pin, position, branch_id, status, access_level) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, 'inactive', 'limited')");
    $stmt->execute([$name, $applicant['email'], $applicant['contact_no'] ?? null, $username, $password, $applicant['position'], $applicant['branch_id'] ?? null]);
    return (int)$pdo->lastInsertId();
}

function generateUniqueEmployeePin(PDO $pdo): array {
    $hashes = $pdo->query("SELECT pin FROM employees WHERE pin IS NOT NULL AND pin <> ''")->fetchAll(PDO::FETCH_COLUMN);
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $pin = (string)random_int(100000, 999999);
        $used = false;
        foreach ($hashes as $hash) if (password_verify($pin, $hash)) { $used = true; break; }
        if (!$used) return [$pin, password_hash($pin, PASSWORD_DEFAULT)];
    }
    throw new RuntimeException('Unable to generate a unique employee PIN.');
}
function ensureApplicantSchema(PDO $pdo): void {
    $accessLevel = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'access_level'");
    $accessLevel->execute();
    if (!$accessLevel->fetchColumn()) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN access_level ENUM('limited','full') NOT NULL DEFAULT 'full' AFTER status");
    }
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'availability'");
    $check->execute();
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE applicants ADD COLUMN availability ENUM('available','not-available') NOT NULL DEFAULT 'available' AFTER employment_type");
    } else {
        $pdo->exec("ALTER TABLE applicants MODIFY availability ENUM('available','not-available') NOT NULL DEFAULT 'available'");
    }
    $resultEmail = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'result_email_sent_at'");
    $resultEmail->execute();
    if (!$resultEmail->fetchColumn()) {
        $pdo->exec("ALTER TABLE applicants ADD COLUMN result_email_sent_at DATETIME NULL AFTER email_sent_at");
    }
    $posRequired = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'pos_account_required'");
    $posRequired->execute();
    if (!$posRequired->fetchColumn()) {
        $pdo->exec("ALTER TABLE applicants ADD COLUMN pos_account_required TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN pos_account_created_at DATETIME NULL");
    }
    foreach ([
        'initial_interviewer_id' => 'INT NULL AFTER interview_result',
        'initial_interviewed_at' => 'DATETIME NULL AFTER initial_interviewer_id',
        'initial_interview_result' => 'VARCHAR(20) NULL AFTER initial_interviewed_at',
        'final_interviewer_id' => 'INT NULL AFTER initial_interview_result',
        'final_interviewed_at' => 'DATETIME NULL AFTER final_interviewer_id',
        'final_interview_result' => 'VARCHAR(20) NULL AFTER final_interviewed_at'
    ] as $column => $definition) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = ?");
        $check->execute([$column]);
        if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE applicants ADD COLUMN {$column} {$definition}");
    }
    // Migrate the former pre-scheduling state into the explicit priority shortlist.
    $pdo->exec("UPDATE applicants SET status = 'shortlisted' WHERE status = 'initial_interview_pending'");
}

ensureApplicantSchema($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'track' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = strtoupper(trim($_POST['application_code'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($code === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(false, 'Please enter your Application ID and the email address used in your application.');
    $stmt = $pdo->prepare("SELECT application_code, first_name, last_name, position, status, interview_date, interview_time, interview_location, created_at, updated_at FROM applicants WHERE application_code = ? AND LOWER(email) = ? LIMIT 1");
    $stmt->execute([$code, $email]);
    $applicant = $stmt->fetch();
    if (!$applicant) { http_response_code(404); respond(false, 'No application matched those details. Check your Application ID and email, then try again.'); }
    $statuses = [
        'pending' => ['Pending review', 'Your application has been received and is waiting for HR review.'],
        'shortlisted' => ['Accepted', 'HR accepted your application and you are waiting for an interview schedule.'],
        'initial_interview_pending' => ['Qualified for initial interview', 'HR reviewed your application. Your initial interview schedule is being prepared.'],
        'initial_interview_scheduled' => ['Initial interview scheduled', 'Review the schedule below and check your email for the invitation.'],
        'initial_interview_arrived' => ['Arrived for initial interview', 'Your arrival was confirmed. Please wait for the interview result.'],
        'final_interview_pending' => ['Initial interview passed', 'You passed the initial interview. HR is preparing your final interview schedule.'],
        'final_interview_scheduled' => ['Final interview scheduled', 'Review the schedule below and check your email for the invitation.'],
        'final_interview_arrived' => ['Arrived for final interview', 'Your arrival was confirmed. Please wait for the final interview result.'],
        'interview_no_show' => ['Interview attendance not confirmed', 'Please contact HR if you need to request a new interview schedule.'],
        'final_interview_passed' => ['Selected for hiring', 'The Manager selected you for contract onboarding.'],
        'contract_pending' => ['Contract signing and verification', 'HR is preparing or verifying your signed employment contract.'],
        'final_confirmation_pending' => ['Awaiting final confirmation', 'Your contract is verified and awaiting the Manager’s final employment confirmation.'],
        'hired' => ['Hired', 'Congratulations and welcome to Quadra Cafe. Please follow the onboarding instructions from HR.'],
        'hr_rejected' => ['Application not selected', 'Thank you for applying. Your application was not selected to continue at this time.'],
        'admin_rejected' => ['Application not selected', 'Thank you for applying. Your application was not selected to continue at this time.'],
        'rejected' => ['Application not selected', 'Thank you for applying. Your application was not selected to continue at this time.'],
        'interview_failed' => ['Application not selected', 'Thank you for your time. The application will not continue to the next stage.'],
    ];
    [$label, $message] = $statuses[$applicant['status']] ?? ['Application in progress', 'Your application is still being processed.'];
    $updated = new DateTime($applicant['updated_at'] ?: $applicant['created_at']);
    $waitingDays = max(0, (int)$updated->diff(new DateTime())->format('%a'));
    if (in_array($applicant['status'], ['pending', 'shortlisted', 'initial_interview_pending', 'final_interview_pending'], true) && $waitingDays >= 7) $message .= ' We apologize for the wait. Your application remains active; HR has been flagged to provide an update.';
    respond(true, '', ['application' => [
        'application_code' => $applicant['application_code'], 'applicant_name' => trim($applicant['first_name'] . ' ' . $applicant['last_name']),
        'position' => $applicant['position'], 'status' => $applicant['status'], 'status_label' => $label, 'message' => $message,
        'submitted_at' => $applicant['created_at'], 'updated_at' => $applicant['updated_at'], 'waiting_days' => $waitingDays,
        'interview_date' => $applicant['interview_date'], 'interview_time' => $applicant['interview_time'], 'interview_location' => $applicant['interview_location'],
    ]]);
}

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['first_name', 'last_name', 'birthdate', 'gender', 'civil_status', 'email', 'contact_no', 'address', 'position', 'years_experience'];
    $data = [];
    foreach ($fields as $field) {
        $data[$field] = trim($_POST[$field] ?? '');
        if ($data[$field] === '') respond(false, 'Please complete all required application fields.');
    }
    $data['employment_type'] = 'full-time';
    $data['preferred_shift'] = '8:00 AM - 5:00 PM';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) respond(false, 'Please enter a valid email address.');
    if (!preg_match('/^\d{11}$/', $data['contact_no'])) respond(false, 'Contact number must be exactly 11 digits.');

    $jobPostId = (int)($_POST['job_post_id'] ?? 0);
    if ($jobPostId < 1) {
        respond(false, 'Please select the job opening you want to apply for.');
    }
    $stmt = $pdo->prepare("SELECT id, title, branch_id FROM job_posts WHERE id = ? AND status = 'published' LIMIT 1");
    $stmt->execute([$jobPostId]);
    $jobPost = $stmt->fetch();
    if (!$jobPost) respond(false, 'This job posting is no longer open. Please refresh the application page and choose an active opening.');

    if (!isPositionAvailable($pdo, $data['position'])) respond(false, 'This position is not available right now. Please choose an available Barista or Cashier opening.');
    if (strtolower(trim($data['position'])) !== strtolower($jobPost['title']) && strtolower(trim($data['position'])) !== strtolower($data['position'])) {
        // keep existing logic but not required; position is validated by selected job posting
    }

    if (empty($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) respond(false, 'Please attach a resume file.');
    $resume = $_FILES['resume'];
    if ($resume['size'] > 5 * 1024 * 1024) respond(false, 'Resume file must not exceed 5 MB.');
    $extension = strtolower(pathinfo($resume['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'doc', 'docx', 'jpg', 'jpeg'], true)) respond(false, 'Resume must be a PDF, DOC, DOCX, JPG, or JPEG file.');
    $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'resumes';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) respond(false, 'Unable to prepare resume storage.');
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($resume['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $storedName)) respond(false, 'Unable to save the resume file.');

    $code = 'QC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $resumePath = 'uploads/resumes/' . $storedName;
    $stmt = $pdo->prepare('INSERT INTO applicants (application_code, job_post_id, branch_id, first_name, middle_name, last_name, birthdate, gender, civil_status, email, contact_no, address, position, employment_type, availability, years_experience, preferred_shift, education, school, course, year_graduated, high_school, high_school_year, honors, resume_name, resume_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$code, $jobPost['id'], $jobPost['branch_id'] ?? null, $data['first_name'], trim($_POST['middle_name'] ?? ''), $data['last_name'], $data['birthdate'], $data['gender'], $data['civil_status'], $data['email'], $data['contact_no'], $data['address'], $data['position'], $data['employment_type'], 'available', $data['years_experience'], $data['preferred_shift'], 'Provided in resume', 'Provided in resume', 'Provided in resume', 'N/A', 'Provided in resume', 'N/A', '', basename($resume['name']), $resumePath]);
    respond(true, 'Application submitted successfully.', ['application_code' => $code]);
}

if ($action === 'list') {
    $view = $_GET['view'] ?? '';
    if ($view === 'hr') {
        requireRole('hr');
        $stmt = $pdo->query("SELECT applicants.*, employees.status AS employee_status,
                                    initial_hr.full_name AS initial_interviewer_name,
                                    initial_hr.position AS initial_interviewer_role,
                                    final_hr.full_name AS final_interviewer_name,
                                    final_hr.position AS final_interviewer_role
                             FROM applicants
                             LEFT JOIN employees ON employees.id = applicants.employee_id
                             LEFT JOIN employees AS initial_hr ON initial_hr.id = applicants.initial_interviewer_id
                             LEFT JOIN employees AS final_hr ON final_hr.id = applicants.final_interviewer_id
                             ORDER BY applicants.created_at ASC, applicants.id ASC");
    } elseif ($view === 'manager') {
        requireRole('manager');
        $stmt = $pdo->query("SELECT applicants.*, employees.status AS employee_status,
                                    initial_hr.full_name AS initial_interviewer_name,
                                    initial_hr.position AS initial_interviewer_role,
                                    final_hr.full_name AS final_interviewer_name,
                                    final_hr.position AS final_interviewer_role
                             FROM applicants
                             LEFT JOIN employees ON employees.id = applicants.employee_id
                             LEFT JOIN employees AS initial_hr ON initial_hr.id = applicants.initial_interviewer_id
                             LEFT JOIN employees AS final_hr ON final_hr.id = applicants.final_interviewer_id
                             WHERE applicants.status IN ('final_interview_pending', 'final_interview_scheduled', 'final_interview_arrived', 'final_interview_passed', 'contract_pending', 'final_confirmation_pending')
                             ORDER BY applicants.created_at ASC, applicants.id ASC");
    } elseif ($view === 'admin') {
        requireRole('admin');
        $stmt = $pdo->query("SELECT applicants.*, employees.status AS employee_status, employees.access_level FROM applicants LEFT JOIN employees ON employees.id = applicants.employee_id WHERE applicants.status = 'hired' AND applicants.pos_account_required = 1 AND applicants.pos_account_created_at IS NULL ORDER BY applicants.updated_at ASC");
    } else {
        respond(false, 'Invalid applicant view.');
    }
    respond(true, '', ['applicants' => $stmt->fetchAll()]);
}

if ($action === 'update_availability' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('hr');
    $id = (int)($_POST['id'] ?? 0);
    $availability = trim($_POST['availability'] ?? '');
    if ($id < 1 || !in_array($availability, ['available', 'not-available'], true)) {
        respond(false, 'Please select Available or Not Available.');
    }
    $stmt = $pdo->prepare("UPDATE applicants SET availability = ? WHERE id = ?");
    $stmt->execute([$availability, $id]);
    if ($stmt->rowCount() < 1) respond(false, 'Applicant availability was not changed.');
    respond(true, 'Applicant availability updated.');
}

if ($action === 'legacy_hr_hire_disabled' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('hr');
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) respond(false, 'Invalid applicant.');
    $stmt = $pdo->prepare("UPDATE applicants SET status = 'hired', availability = 'not-available' WHERE id = ? AND status = 'final_interview_passed'");
    $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) respond(false, 'Only applicants who passed the final interview can be hired.');
    respond(true, 'Applicant hired successfully.');
}

if ($action === 'hr_decision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('hr');
    $id = (int)($_POST['id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    if ($id < 1 || !in_array($decision, ['qualified', 'shortlisted', 'rejected'], true)) respond(false, 'Invalid applicant decision.');
    $isShortlisted = in_array($decision, ['qualified', 'shortlisted'], true);
    $status = $isShortlisted ? 'shortlisted' : 'hr_rejected';
    $availability = $isShortlisted ? 'available' : 'not-available';
    $stmt = $pdo->prepare("UPDATE applicants SET status = ?, availability = ?, hr_remarks = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([$status, $availability, trim($_POST['remarks'] ?? ''), $id]);
    if ($stmt->rowCount() !== 1) respond(false, 'Only pending applications can be processed by HR.');
    respond(true, $isShortlisted ? 'Applicant accepted and added to the interview scheduling queue.' : 'Applicant has been rejected.');
}

if ($action === 'schedule_interview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $stage = $_POST['stage'] ?? '';
    if ($id < 1 || !in_array($stage, ['initial', 'final'], true)) respond(false, 'Invalid interview stage.');
    requireRole($stage === 'initial' ? 'hr' : 'manager');
    $date = trim($_POST['interview_date'] ?? '');
    $time = trim($_POST['interview_time'] ?? '');
    $location = trim($_POST['interview_location'] ?? '');
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date || !$time || !$location) respond(false, 'Please provide the initial interview date, time, and location.');
    $interviewDateTime = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if (!$interviewDateTime) respond(false, 'Please enter a valid interview date and time.');
    $now = new DateTime();
    if ($interviewDateTime <= $now) respond(false, 'The interview schedule must be in the future.');
    $maxInterviewDate = (clone $now)->modify('+7 days')->setTime(23, 59, 59);
    if ($interviewDateTime > $maxInterviewDate) respond(false, 'Interview schedule must be within 1 week from today only.');

    $requiredStatus = $stage === 'initial' ? 'shortlisted' : 'final_interview_pending';
    $scheduledStatus = $stage === 'initial' ? 'initial_interview_scheduled' : 'final_interview_scheduled';
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, position, availability FROM applicants WHERE id = ? AND status = ?");
    $stmt->execute([$id, $requiredStatus]);
    $applicant = $stmt->fetch();
    if (!$applicant) respond(false, 'The applicant is not ready for this interview stage.');
    if (($applicant['availability'] ?? '') === 'not-available') respond(false, 'This applicant is marked Not Available and cannot be scheduled for interview yet.');

    $stageLabel = ucfirst($stage);
    $cleanLocation = trim(str_replace(["\r", "\n"], ' ', $location));
    $stmt = $pdo->prepare("UPDATE applicants SET status = ?, interview_date = ?, interview_time = ?, interview_location = ?, email_sent_at = NULL WHERE id = ? AND status = ?");
    $stmt->execute([$scheduledStatus, $date, $interviewDateTime->format('H:i'), $cleanLocation, $id, $requiredStatus]);
    if ($stmt->rowCount() !== 1) respond(false, 'The interview schedule was not saved. Please refresh and try again.');
    respond(true, $stageLabel . ' interview saved. The invitation email is being sent in the background.', [
        'email_pending' => true,
        'applicant_id' => $id,
        'stage' => $stage,
    ]);
}

if ($action === 'interview_arrival' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $stage = $_POST['stage'] ?? '';
    $arrived = $_POST['arrived'] ?? '';
    requireRole($stage === 'final' ? 'manager' : 'hr');
    if ($id < 1 || !in_array($stage, ['initial', 'final'], true) || !in_array($arrived, ['yes', 'no'], true)) {
        respond(false, 'Invalid interview arrival update.');
    }
    $requiredStatus = $stage === 'initial' ? 'initial_interview_scheduled' : 'final_interview_scheduled';
    $nextStatus = $arrived === 'yes'
        ? ($stage === 'initial' ? 'initial_interview_arrived' : 'final_interview_arrived')
        : 'interview_no_show';
    $availability = $arrived === 'yes' ? 'available' : 'not-available';
    $stmt = $pdo->prepare('UPDATE applicants SET status = ?, availability = ?, interview_stage = ? WHERE id = ? AND status = ?');
    $stmt->execute([$nextStatus, $availability, $stage, $id, $requiredStatus]);
    if ($stmt->rowCount() !== 1) respond(false, 'Only scheduled applicants can have their arrival recorded.');
    respond(true, $arrived === 'yes' ? 'Applicant moved to Arrived / For Interview.' : 'Applicant moved to the No Show list.');
}

if ($action === 'send_interview_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $stage = $_POST['stage'] ?? '';
    if ($id < 1 || !in_array($stage, ['initial', 'final'], true)) respond(false, 'Invalid interview email request.');
    requireRole($stage === 'initial' ? 'hr' : 'manager');
    $scheduledStatus = $stage === 'initial' ? 'initial_interview_scheduled' : 'final_interview_scheduled';

    // Atomically claim this email so rapid double-clicks cannot send duplicates.
    $claim = $pdo->prepare("UPDATE applicants SET email_sent_at = '1970-01-01 00:00:01' WHERE id = ? AND status = ? AND email_sent_at IS NULL");
    $claim->execute([$id, $scheduledStatus]);
    if ($claim->rowCount() !== 1) respond(true, 'Invitation email is already being processed or was already sent.');

    $stmt = $pdo->prepare("SELECT first_name, last_name, email, position, interview_date, interview_time, interview_location FROM applicants WHERE id = ? AND status = ?");
    $stmt->execute([$id, $scheduledStatus]);
    $applicant = $stmt->fetch();
    if (!$applicant) respond(false, 'The scheduled applicant could not be found.');

    $clean = static fn(string $value): string => trim(str_replace(["\r", "\n"], ' ', $value));
    $name = $clean($applicant['first_name'] . ' ' . $applicant['last_name']);
    $dateObject = DateTime::createFromFormat('Y-m-d', $applicant['interview_date']);
    $interviewDateTime = DateTime::createFromFormat('Y-m-d H:i', $applicant['interview_date'] . ' ' . substr($applicant['interview_time'], 0, 5));
    if (!$dateObject || !$interviewDateTime) {
        $pdo->prepare('UPDATE applicants SET email_sent_at = NULL WHERE id = ?')->execute([$id]);
        respond(false, 'The saved interview schedule is invalid.');
    }

    $stageLabel = ucfirst($stage);
    $subject = $stageLabel . ' Interview Invitation - Quadra Cafe';
    $message = "Dear {$name},\n\nThank you for applying for the {$applicant['position']} position at Quadra Cafe. You are invited to your {$stage} interview.\n\n{$stageLabel} INTERVIEW DETAILS\nDate: " . $dateObject->format('l, F j, Y') . "\nTime: " . $interviewDateTime->format('g:i A') . "\nVenue / meeting link: " . $clean($applicant['interview_location']) . "\n\nPlease arrive at least 10 minutes early for an in-person interview. If you need to reschedule, please reply to this email.\n\nRegards,\nQuadra Cafe HR Team";
    // Release the PHP session lock before SMTP so other HR buttons stay responsive.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    try {
        require_once 'send_email.php';
        sendQuadraEmail($applicant['email'], $name, $subject, $message);
        $pdo->prepare('UPDATE applicants SET email_sent_at = NOW() WHERE id = ?')->execute([$id]);
    } catch (Throwable $error) {
        $pdo->prepare('UPDATE applicants SET email_sent_at = NULL WHERE id = ?')->execute([$id]);
        http_response_code(500);
        respond(false, 'The schedule was saved, but the invitation email could not be sent: ' . $error->getMessage());
    }
    respond(true, 'Invitation email sent to ' . $applicant['email'] . '.');
}

if ($action === 'interview_result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $result = $_POST['result'] ?? '';
    if ($id < 1 || !in_array($result, ['passed', 'failed'], true)) respond(false, 'Invalid interview result. Please select Yes or No.');
    $stage = $_POST['stage'] ?? '';
    if (!in_array($stage, ['initial', 'final'], true)) respond(false, 'Invalid interview stage.');
    if ($stage === 'initial') {
        requireRole('hr');
        $allowedStatuses = ['initial_interview_arrived'];
    } else {
        requireRole('manager');
        $allowedStatuses = ['final_interview_arrived'];
    }
    $status = $result === 'failed' ? 'interview_failed' : ($stage === 'initial' ? 'final_interview_pending' : 'final_interview_passed');
    $scheduleStmt = $pdo->prepare('SELECT status, interview_date, interview_time, first_name, last_name, email, position FROM applicants WHERE id = ?');
    $scheduleStmt->execute([$id]);
    $schedule = $scheduleStmt->fetch();
    if ($schedule && $stage === 'final' && $result === 'passed' && in_array($schedule['status'], ['contract_pending', 'final_confirmation_pending', 'hired'], true)) {
        respond(true, 'This applicant has already been hired and the onboarding workflow is already in progress.', [
            'already_processed' => true,
            'applicant_id' => $id,
        ]);
    }
    if (!$schedule || !in_array($schedule['status'], $allowedStatuses, true)) respond(false, 'This applicant is not ready for this interview result.');
    $scheduledAt = DateTime::createFromFormat('Y-m-d H:i', $schedule['interview_date'] . ' ' . substr($schedule['interview_time'], 0, 5));
    if (!$scheduledAt) respond(false, 'The saved interview schedule is invalid. Please schedule the interview again.');
    $availability = $result === 'failed' ? 'not-available' : 'available';
    $interviewerId = (int)($_SESSION['user_id'] ?? 0);
    if ($stage === 'initial') {
        $stmt = $pdo->prepare("UPDATE applicants SET status = ?, availability = ?, interview_stage = ?, interview_result = ?, initial_interviewer_id = ?, initial_interviewed_at = NOW(), initial_interview_result = ? WHERE id = ? AND status = ?");
        $stmt->execute([$status, $availability, $stage, $result, $interviewerId, $result, $id, $schedule['status']]);
    } else {
        $pdo->beginTransaction();
        try {
            if ($result === 'passed') {
                $fullStmt = $pdo->prepare('SELECT * FROM applicants WHERE id = ? FOR UPDATE');
                $fullStmt->execute([$id]);
                $fullApplicant = $fullStmt->fetch();
                if (!$fullApplicant || $fullApplicant['status'] !== 'final_interview_arrived') {
                    throw new RuntimeException('This hire decision was already processed. Please refresh the applicant list.');
                }
                $employeeId = createOnboardingEmployee($pdo, $fullApplicant);
                [$pin, $pinHash] = generateUniqueEmployeePin($pdo);
                $employeeName = trim(implode(' ', array_filter([$fullApplicant['first_name'], $fullApplicant['middle_name'] ?? '', $fullApplicant['last_name']])));
                $subject = 'Your Quadra Cafe Limited HRMS Access PIN';
                $message = "Dear {$employeeName},\n\nYou have been hired by the Hiring Manager. A limited HRMS account has been created for your contract onboarding.\n\nAccess PIN: {$pin}\n\nUse this PIN on the Employee Login screen. You must complete the contract signing process in My Contract before the rest of the HRMS features can be unlocked. Keep this PIN private.\n\nRegards,\nQuadra Cafe HR Team";
                require_once 'send_email.php';
                sendQuadraEmail($fullApplicant['email'], $employeeName, $subject, $message);
                $activate = $pdo->prepare("UPDATE employees SET pin = ?, status = 'approved', access_level = 'limited' WHERE id = ? AND status = 'inactive'");
                $activate->execute([$pinHash, $employeeId]);
                if ($activate->rowCount() !== 1) throw new RuntimeException('The limited onboarding account could not be activated.');
                $status = 'contract_pending';
                $posRequired = normalizedHiringPosition((string)$fullApplicant['position']) === 'cashier' ? 1 : 0;
                $stmt = $pdo->prepare("UPDATE applicants SET status = ?, employee_id = ?, availability = 'available', interview_stage = 'final', interview_result = 'passed', final_interviewer_id = ?, final_interviewed_at = NOW(), final_interview_result = 'passed', result_email_sent_at = NULL, pos_account_required = ? WHERE id = ? AND status = ?");
                $stmt->execute([$status, (string)$employeeId, $interviewerId, $posRequired, $id, $schedule['status']]);
            } else {
                $stmt = $pdo->prepare("UPDATE applicants SET status = 'interview_failed', availability = 'not-available', interview_stage = 'final', interview_result = 'failed', final_interviewer_id = ?, final_interviewed_at = NOW(), final_interview_result = 'failed', result_email_sent_at = NULL WHERE id = ? AND status = ?");
                $stmt->execute([$interviewerId, $id, $schedule['status']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            respond(false, 'Unable to start contract onboarding: ' . $error->getMessage());
        }
    }
    if ($stmt->rowCount() !== 1) respond(false, 'Only applicants who completed this scheduled interview can have a result.');
    if ($stage === 'final') {
        respond(true, $result === 'passed'
            ? 'Hire decision saved. A limited HRMS account was created and the unique PIN was emailed to the employee. HR can now upload the contract.'
            : 'Final interview result saved. The result email is being sent in the background.', [
                'email_pending' => $result === 'failed',
                'applicant_id' => $id,
            ]);
    }
    respond(true, $result === 'passed' ? 'Initial interview passed. You may now schedule the final interview.' : 'Interview result saved: the applicant did not pass.');
}


if ($action === 'start_contract' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('manager');
    $id = (int)($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM applicants WHERE id = ? AND status = 'final_interview_passed' FOR UPDATE");
        $stmt->execute([$id]);
        $applicant = $stmt->fetch();
        if (!$applicant) throw new RuntimeException('This applicant is not waiting for contract onboarding.');
        $employeeId = createOnboardingEmployee($pdo, $applicant);
        $update = $pdo->prepare("UPDATE applicants SET status = 'contract_pending', employee_id = ? WHERE id = ? AND status = 'final_interview_passed'");
        $update->execute([(string)$employeeId, $id]);
        if ($update->rowCount() !== 1) throw new RuntimeException('The applicant status changed. Please refresh.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, $error->getMessage());
    }
    respond(true, 'Applicant returned to HR for contract signing and verification.');
}
if ($action === 'final_confirmation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('manager');
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT a.*, e.id AS onboarding_employee_id, e.status AS employee_status FROM applicants a JOIN employees e ON e.id = CAST(a.employee_id AS UNSIGNED) JOIN employee_documents d ON d.employee_id = e.id AND d.document_type = 'employment_contract' AND d.verification_status = 'verified' AND d.contract_status = 'verified' WHERE a.id = ? AND a.status = 'final_confirmation_pending' AND e.status = 'approved' AND e.access_level = 'limited' LIMIT 1");
    $stmt->execute([$id]);
    $applicant = $stmt->fetch();
    if (!$applicant) respond(false, 'A verified contract and limited onboarding account are required for final confirmation.');
    try {
        $pdo->beginTransaction();
        $activate = $pdo->prepare("UPDATE employees SET access_level = 'full' WHERE id = ? AND status = 'approved' AND access_level = 'limited'");
        $activate->execute([$applicant['onboarding_employee_id']]);
        if ($activate->rowCount() !== 1) throw new RuntimeException('The onboarding account is no longer pending.');
        $complete = $pdo->prepare("UPDATE applicants SET status = 'hired', availability = 'not-available' WHERE id = ? AND status = 'final_confirmation_pending'");
        $complete->execute([$id]);
        if ($complete->rowCount() !== 1) throw new RuntimeException('The applicant is no longer awaiting confirmation.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, 'Employment access could not be activated: ' . $error->getMessage());
    }
    respond(true, 'Employment confirmed. Full HRMS access is now unlocked.');
}

if ($action === 'complete_pos_account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin');
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE applicants SET pos_account_created_at = NOW() WHERE id = ? AND status = 'hired' AND pos_account_required = 1 AND pos_account_created_at IS NULL");
    $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) respond(false, 'This POS account task is no longer pending.');
    respond(true, 'POS account creation has been recorded.');
}
if ($action === 'send_result_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('manager');
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) respond(false, 'Invalid result email request.');

    $claim = $pdo->prepare("UPDATE applicants SET result_email_sent_at = '1970-01-01 00:00:01' WHERE id = ? AND interview_stage = 'final' AND status IN ('final_interview_passed','interview_failed') AND result_email_sent_at IS NULL");
    $claim->execute([$id]);
    if ($claim->rowCount() !== 1) respond(true, 'Result email is already being processed or was already sent.');

    $stmt = $pdo->prepare("SELECT first_name, last_name, email, position, status FROM applicants WHERE id = ? AND interview_stage = 'final'");
    $stmt->execute([$id]);
    $applicant = $stmt->fetch();
    if (!$applicant) respond(false, 'The applicant result could not be found.');
    $applicantName = trim($applicant['first_name'] . ' ' . $applicant['last_name']);
    $passed = $applicant['status'] === 'final_interview_passed';
    $subject = $passed ? 'Final Interview Passed - Quadra Cafe' : 'Application Update - Quadra Cafe';
    $message = $passed
        ? "Dear {$applicantName},\n\nCongratulations! We are pleased to inform you that you passed your final interview and have been selected for the {$applicant['position']} position at Quadra Cafe.\n\nFor the next meeting, please be ready for your onboarding discussion. The Administrator will prepare your employee account, and the HR team will assist you with the next steps.\n\nWelcome to the Quadra Cafe team!\n\nRegards,\nQuadra Cafe HR Team"
        : "Dear {$applicantName},\n\nThank you for the time and effort you invested throughout our interview process for the {$applicant['position']} position.\n\nAfter careful consideration, we are sorry to inform you that we will not be moving forward with your application at this time. We appreciate your interest in Quadra Cafe and wish you success in your future opportunities.\n\nRegards,\nQuadra Cafe HR Team";
    // Release the PHP session lock before SMTP so other HR buttons stay responsive.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    try {
        require_once 'send_email.php';
        sendQuadraEmail($applicant['email'], $applicantName, $subject, $message);
        $pdo->prepare('UPDATE applicants SET result_email_sent_at = NOW() WHERE id = ?')->execute([$id]);
    } catch (Throwable $error) {
        $pdo->prepare('UPDATE applicants SET result_email_sent_at = NULL WHERE id = ?')->execute([$id]);
        http_response_code(500);
        respond(false, 'The result was saved, but the email could not be sent: ' . $error->getMessage());
    }
    respond(true, 'Result email sent to ' . $applicant['email'] . '.');
}

respond(false, 'Invalid action.');
