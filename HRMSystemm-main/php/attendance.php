<?php
require_once 'config.php';
require_once __DIR__ . '/attendance_settings.php';
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');

function attendanceRespond(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if (isLimitedEmployeeSession($pdo)) {
    http_response_code(403);
    attendanceRespond(false, 'Attendance unlocks after final Manager confirmation.');
}

function ensureAttendanceSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        work_date DATE NOT NULL,
        clock_in DATETIME NOT NULL,
        clock_out DATETIME NULL,
        status ENUM('on_time','late') NOT NULL DEFAULT 'on_time',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_attendance_employee_date (employee_id, work_date),
        KEY idx_attendance_date (work_date),
        CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['break_start' => 'DATETIME NULL AFTER clock_in', 'break_end' => 'DATETIME NULL AFTER break_start'] as $column => $definition) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = ?");
        $check->execute([$column]);
        if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE attendance_records ADD COLUMN {$column} {$definition}");
    }
    $columns = [
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
        'auto_clock_out' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_note',
    ];
    foreach ($columns as $column => $definition) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = ?");
        $check->execute([$column]);
        if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE attendance_records ADD COLUMN {$column} {$definition}");
    }
    // Classify completed legacy rows so Admin can review records created before approval was introduced.
    $pdo->exec("UPDATE attendance_records
        SET day_status = CASE
            WHEN GREATEST(0, TIMESTAMPDIFF(SECOND, clock_in, clock_out) - IF(break_start IS NULL, 0, TIMESTAMPDIFF(SECOND, break_start, COALESCE(break_end, clock_out)))) <= 18000 THEN 'half_day'
            WHEN TIME(clock_out) < '17:00:00' OR GREATEST(0, TIMESTAMPDIFF(SECOND, clock_in, clock_out) - IF(break_start IS NULL, 0, TIMESTAMPDIFF(SECOND, break_start, COALESCE(break_end, clock_out)))) < 27000 THEN 'undertime'
            ELSE 'full_day'
        END,
        approval_status = 'pending'
        WHERE clock_out IS NOT NULL AND day_status = 'in_progress'");
    // A very short attendance (for example 16 minutes) is undertime, never half day.
    $pdo->exec("UPDATE attendance_records
        SET day_status = 'undertime'
        WHERE clock_out IS NOT NULL AND day_status = 'half_day'
          AND GREATEST(0, TIMESTAMPDIFF(SECOND, clock_in, clock_out) - IF(break_start IS NULL, 0, TIMESTAMPDIFF(SECOND, break_start, COALESCE(break_end, clock_out)))) < 14400");
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_proofs (
        proof_id INT AUTO_INCREMENT PRIMARY KEY,
        attendance_id INT NOT NULL,
        employee_id INT NOT NULL,
        event_type ENUM('clock_in','break_end','clock_out') NOT NULL,
        photo_path VARCHAR(255) NOT NULL,
        latitude DECIMAL(10,7) NOT NULL,
        longitude DECIMAL(10,7) NOT NULL,
        accuracy_meters DECIMAL(10,2) NOT NULL,
        face_match_distance DECIMAL(8,6) NULL,
        blink_verified TINYINT(1) NOT NULL DEFAULT 1,
        captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_attendance_proof_event (attendance_id, event_type),
        CONSTRAINT fk_proof_attendance FOREIGN KEY (attendance_id) REFERENCES attendance_records(id) ON DELETE CASCADE,
        CONSTRAINT fk_proof_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("ALTER TABLE attendance_proofs MODIFY event_type ENUM('clock_in','break_end','clock_out') NOT NULL");
    foreach (['face_match_distance' => 'DECIMAL(8,6) NULL AFTER accuracy_meters', 'blink_verified' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER face_match_distance'] as $column => $definition) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_proofs' AND COLUMN_NAME = ?");
        $check->execute([$column]);
        if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE attendance_proofs ADD COLUMN {$column} {$definition}");
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

function saveAttendanceProof(PDO $pdo, int $attendanceId, int $employeeId, string $eventType, ?float $faceDistance = null): void {
    $photo = trim($_POST['proof_photo'] ?? '');
    $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $accuracy = filter_var($_POST['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $photo, $match)) attendanceRespond(false, 'A live selfie is required for this attendance action.');
    if ($latitude === false || $longitude === false || $accuracy === false || abs($latitude) > 90 || abs($longitude) > 180) attendanceRespond(false, 'A valid GPS location is required.');
    $binary = base64_decode($match[1], true);
    if ($binary === false || strlen($binary) < 1000 || strlen($binary) > 3 * 1024 * 1024) attendanceRespond(false, 'The attendance selfie is invalid or too large.');
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'attendance_proofs';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) attendanceRespond(false, 'Unable to prepare attendance proof storage.');
    $storedName = bin2hex(random_bytes(24)) . '.jpg';
    if (file_put_contents($directory . DIRECTORY_SEPARATOR . $storedName, $binary, LOCK_EX) === false) attendanceRespond(false, 'Unable to save the attendance selfie.');
    $relativePath = 'uploads/attendance_proofs/' . $storedName;
    $stmt = $pdo->prepare("INSERT INTO attendance_proofs (attendance_id, employee_id, event_type, photo_path, latitude, longitude, accuracy_meters, face_match_distance, blink_verified, captured_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE photo_path=VALUES(photo_path), latitude=VALUES(latitude), longitude=VALUES(longitude), accuracy_meters=VALUES(accuracy_meters), face_match_distance=VALUES(face_match_distance), blink_verified=1, captured_at=NOW()");
    $stmt->execute([$attendanceId, $employeeId, $eventType, $relativePath, $latitude, $longitude, max(0, $accuracy), $faceDistance]);
}

function verifyAttendanceFace(PDO $pdo, int $employeeId): float {
    if (($_POST['liveness_verified'] ?? '') !== '1') attendanceRespond(false, 'Head-turn verification is required. Please complete the live face check.');
    $submitted = json_decode(trim($_POST['face_descriptor'] ?? ''), true);
    if (!is_array($submitted) || count($submitted) !== 128) attendanceRespond(false, 'A valid live face scan is required.');
    $stmt = $pdo->prepare('SELECT face_descriptor FROM employee_face_profiles WHERE employee_id = ? AND blink_verified = 1 LIMIT 1');
    $stmt->execute([$employeeId]);
    $stored = json_decode((string)$stmt->fetchColumn(), true);
    if (!is_array($stored) || count($stored) !== 128) attendanceRespond(false, 'No verified face is registered for this account. Ask the Administrator to enroll your face.');
    $sum = 0.0;
    foreach ($stored as $index => $knownValue) {
        if (!isset($submitted[$index]) || !is_numeric($submitted[$index]) || !is_numeric($knownValue)) attendanceRespond(false, 'The live face scan is invalid.');
        $difference = (float)$knownValue - (float)$submitted[$index];
        $sum += $difference * $difference;
    }
    $distance = sqrt($sum);
    if ($distance > 0.58) attendanceRespond(false, 'Face not recognized. Use the registered employee account owner’s face and try again.');
    return $distance;
}

function attendanceUser(PDO $pdo): array {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        http_response_code(401);
        attendanceRespond(false, 'Your session has expired. Please sign in again.');
    }
    $stmt = $pdo->prepare('SELECT id, full_name, email, position, status FROM employees WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'approved') {
        http_response_code(403);
        attendanceRespond(false, 'Your employee account is not active.');
    }
    return $user;
}

function isPrivilegedPosition(string $position): bool {
    return $position === 'Administrator' || stripos($position, 'HR') !== false || strcasecmp($position, 'Human Resources') === 0;
}

function attendanceDepartment(string $position): string {
    $value = strtolower($position);
    if (strpos($value, 'cook') !== false || strpos($value, 'chef') !== false || strpos($value, 'kitchen') !== false) return 'Kitchen';
    if (strpos($value, 'manager') !== false || strpos($value, 'supervisor') !== false) return 'Management';
    if (strpos($value, 'finance') !== false || strpos($value, 'admin') !== false) return 'Admin & Finance';
    if (strpos($value, 'maintenance') !== false) return 'Maintenance';
    return 'Operations';
}

function attendanceLeaveMap(PDO $pdo, string $date): array {
    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests'")->fetchColumn();
    if (!$table) return [];
    $stmt = $pdo->prepare("SELECT employee_id, leave_type, pay_type, leave_during FROM leave_requests WHERE status = 'approved' AND start_date <= ? AND end_date >= ?");
    $stmt->execute([$date, $date]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['employee_id']] = $row;
    }
    return $map;
}

function durationSeconds(?string $start, ?string $end = null, ?string $breakStart = null, ?string $breakEnd = null): int {
    if (!$start) return 0;
    $startTime = strtotime($start);
    $endTime = $end ? strtotime($end) : time();
    $total = max(0, $endTime - $startTime);
    if ($breakStart) {
        $breakStartTime = strtotime($breakStart);
        $breakEndTime = $breakEnd ? strtotime($breakEnd) : $endTime;
        $total -= max(0, min($endTime, $breakEndTime) - $breakStartTime);
    }
    return max(0, $total);
}

function attendanceCalculation(array $row, string $clockOut, bool $automatic = false): array {
    $settings = employeeAttendanceSettings((int)($row['employee_id'] ?? 0));
    $date = $row['work_date'];
    $shiftStart = strtotime($date . ' ' . $settings['shift_start']);
    $shiftEnd = strtotime($date . ' ' . $settings['shift_end']);
    $clockIn = strtotime($row['clock_in']);
    $clockOutTime = strtotime($clockOut);
    $late = max(0, (int)ceil(($clockIn - $shiftStart) / 60));
    $undertime = max(0, (int)ceil(($shiftEnd - $clockOutTime) / 60));
    $overtime = max(0, (int)floor(($clockOutTime - $shiftEnd) / 60));
    $missingLunch = empty($row['break_start']) || empty($row['break_end']);
    $worked = durationSeconds($row['clock_in'], $clockOut, $row['break_start'] ?? null, $row['break_end'] ?? null);
    $needsReview = $automatic || $missingLunch || $undertime > 0 || $overtime > 0;
    if ($automatic) $remark = 'Auto Clock Out';
    elseif ($missingLunch) $remark = 'Missing Lunch';
    elseif ($undertime > 0) $remark = 'Undertime (' . $undertime . ' mins)';
    elseif ($overtime > 0) $remark = 'Overtime (' . $overtime . ' mins)';
    elseif ($late > 0) $remark = 'Late (' . $late . ' mins)';
    else $remark = 'On Time';
    return [
        'late_minutes' => $late, 'undertime_minutes' => $undertime, 'overtime_minutes' => $overtime,
        'worked_seconds' => $worked, 'attendance_remark' => $remark,
        'approval_status' => $needsReview ? 'pending' : 'approved',
        'day_status' => $undertime > 0 ? 'undertime' : 'full_day', 'auto_clock_out' => $automatic ? 1 : 0,
    ];
}

function finalizeAttendance(PDO $pdo, array $row, string $clockOut, bool $automatic = false): array {
    $result = attendanceCalculation($row, $clockOut, $automatic);
    $stmt = $pdo->prepare('UPDATE attendance_records SET clock_out = ?, day_status = ?, approval_status = ?, late_minutes = ?, undertime_minutes = ?, overtime_minutes = ?, worked_seconds = ?, attendance_remark = ?, auto_clock_out = ? WHERE id = ? AND clock_out IS NULL');
    $stmt->execute([$clockOut, $result['day_status'], $result['approval_status'], $result['late_minutes'], $result['undertime_minutes'], $result['overtime_minutes'], $result['worked_seconds'], $result['attendance_remark'], $result['auto_clock_out'], $row['id']]);
    return $result;
}

function autoCloseForgottenAttendance(PDO $pdo): void {
    $settings = attendanceSettings();
    if (!empty($settings['demo_anytime_clock_in'])) return;
    $stmt = $pdo->query('SELECT * FROM attendance_records WHERE clock_out IS NULL');
    foreach ($stmt->fetchAll() as $row) {
        $autoAt = strtotime($row['work_date'] . ' ' . $settings['shift_end']) + ($settings['auto_clock_out_grace_minutes'] * 60);
        if (time() < $autoAt) continue;
        finalizeAttendance($pdo, $row, date('Y-m-d H:i:s', $autoAt), true);
    }
}

function backfillAttendanceCalculations(PDO $pdo): void {
    $stmt = $pdo->query("SELECT * FROM attendance_records WHERE clock_out IS NOT NULL AND (attendance_remark = 'In Progress' OR worked_seconds = 0)");
    foreach ($stmt->fetchAll() as $row) {
        $result = attendanceCalculation($row, $row['clock_out'], !empty($row['auto_clock_out']));
        $approval = in_array($row['approval_status'], ['approved', 'rejected'], true) ? $row['approval_status'] : $result['approval_status'];
        $update = $pdo->prepare('UPDATE attendance_records SET day_status = ?, approval_status = ?, late_minutes = ?, undertime_minutes = ?, overtime_minutes = ?, worked_seconds = ?, attendance_remark = ? WHERE id = ?');
        $update->execute([$result['day_status'], $approval, $result['late_minutes'], $result['undertime_minutes'], $result['overtime_minutes'], $result['worked_seconds'], $result['attendance_remark'], $row['id']]);
    }
}

function attendanceRow(array $row): array {
    return [
        'id' => (int)$row['id'],
        'work_date' => $row['work_date'],
        'clock_in' => $row['clock_in'],
        'break_start' => $row['break_start'] ?? null,
        'break_end' => $row['break_end'] ?? null,
        'clock_out' => $row['clock_out'],
        'status' => $row['status'],
        'day_status' => $row['day_status'] ?? 'in_progress',
        'approval_status' => $row['approval_status'] ?? 'not_required',
        'late_minutes' => (int)($row['late_minutes'] ?? 0),
        'undertime_minutes' => (int)($row['undertime_minutes'] ?? 0),
        'overtime_minutes' => (int)($row['overtime_minutes'] ?? 0),
        'attendance_remark' => $row['attendance_remark'] ?? 'In Progress',
        'admin_note' => $row['admin_note'] ?? null,
        'auto_clock_out' => (int)($row['auto_clock_out'] ?? 0),
        'duration_seconds' => $row['clock_out'] ? (int)($row['worked_seconds'] ?: durationSeconds($row['clock_in'], $row['clock_out'], $row['break_start'] ?? null, $row['break_end'] ?? null)) : durationSeconds($row['clock_in'], null, $row['break_start'] ?? null, $row['break_end'] ?? null),
        'break_seconds' => ($row['break_start'] ?? null) ? durationSeconds($row['break_start'], $row['break_end'] ?? $row['clock_out']) : 0,
    ];
}

ensureAttendanceSchema($pdo);
autoCloseForgottenAttendance($pdo);
backfillAttendanceCalculations($pdo);
$user = attendanceUser($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'mine';

if ($action === 'mine') {
    if (isPrivilegedPosition($user['position'])) {
        http_response_code(403);
        attendanceRespond(false, 'Attendance time clock is for employee accounts only.');
    }
    $today = date('Y-m-d');
    $todayStmt = $pdo->prepare('SELECT * FROM attendance_records WHERE employee_id = ? AND work_date = ? LIMIT 1');
    $todayStmt->execute([$user['id'], $today]);
    $todayRow = $todayStmt->fetch();

    $logsStmt = $pdo->prepare('SELECT * FROM attendance_records WHERE employee_id = ? ORDER BY work_date DESC, id DESC LIMIT 31');
    $logsStmt->execute([$user['id']]);
    $logs = array_map('attendanceRow', $logsStmt->fetchAll());

    $monthStart = date('Y-m-01');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $statsStmt = $pdo->prepare("SELECT
        SUM(work_date >= ? AND clock_in IS NOT NULL) AS days_present,
        SUM(work_date >= ? AND status = 'late') AS lates,
        SUM(CASE WHEN work_date >= ? THEN GREATEST(0, TIMESTAMPDIFF(SECOND, clock_in, COALESCE(clock_out, NOW())) - IF(break_start IS NULL, 0, TIMESTAMPDIFF(SECOND, break_start, COALESCE(break_end, clock_out, NOW())))) ELSE 0 END) AS week_seconds,
        SUM(CASE WHEN work_date >= ? THEN GREATEST(0, TIMESTAMPDIFF(SECOND, clock_in, COALESCE(clock_out, NOW())) - IF(break_start IS NULL, 0, TIMESTAMPDIFF(SECOND, break_start, COALESCE(break_end, clock_out, NOW())))) ELSE 0 END) AS month_seconds
        FROM attendance_records WHERE employee_id = ?");
    $statsStmt->execute([$monthStart, $monthStart, $weekStart, $monthStart, $user['id']]);
    $stats = $statsStmt->fetch();

    $activities = [];
    foreach (array_slice($logs, 0, 5) as $log) {
        if ($log['clock_out']) $activities[] = ['type' => 'clock_out', 'occurred_at' => $log['clock_out']];
        if ($log['break_end']) $activities[] = ['type' => 'break_end', 'occurred_at' => $log['break_end']];
        if ($log['break_start']) $activities[] = ['type' => 'break_start', 'occurred_at' => $log['break_start']];
        $activities[] = ['type' => $log['status'] === 'late' ? 'late_clock_in' : 'clock_in', 'occurred_at' => $log['clock_in']];
    }
    usort($activities, static fn($a, $b) => strcmp($b['occurred_at'], $a['occurred_at']));

    $settings = employeeAttendanceSettings((int)$user['id']);
    attendanceRespond(true, '', [
        'server_now' => date('Y-m-d H:i:s'),
        'schedule' => [
            'shift_start' => $settings['shift_start'],
            'shift_end' => $settings['shift_end'],
            'break_start' => $settings['lunch_start'],
            'break_end' => $settings['lunch_end'],
            'auto_clock_out_grace_minutes' => $settings['auto_clock_out_grace_minutes'],
            'day_off' => $settings['day_off_label'],
            'is_day_off' => (int)date('N') === $settings['day_off_iso'],
            'can_clock_in' => !empty($settings['demo_anytime_clock_in'])
                || ((int)date('N') !== $settings['day_off_iso'] && time() < strtotime($today . ' ' . $settings['shift_end'])),
        ],
        'today' => $todayRow ? attendanceRow($todayRow) : null,
        'logs' => $logs,
        'activities' => array_slice($activities, 0, 8),
        'stats' => [
            'days_present' => (int)($stats['days_present'] ?? 0),
            'lates' => (int)($stats['lates'] ?? 0),
            'week_seconds' => (int)($stats['week_seconds'] ?? 0),
            'month_seconds' => (int)($stats['month_seconds'] ?? 0),
        ],
    ]);
}

if (in_array($action, ['clock', 'break_start', 'break_end', 'clock_out'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isPrivilegedPosition($user['position'])) {
        http_response_code(403);
        attendanceRespond(false, 'Attendance time clock is for employee accounts only.');
    }
    $faceDistance = null;
    if (in_array($action, ['clock', 'break_end', 'clock_out'], true)) $faceDistance = verifyAttendanceFace($pdo, (int)$user['id']);
    $pdo->beginTransaction();
    try {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare('SELECT * FROM attendance_records WHERE employee_id = ? AND work_date = ? FOR UPDATE');
        $stmt->execute([$user['id'], $today]);
        $record = $stmt->fetch();
        if ($record && $record['clock_out'] && $action === 'clock' && !empty(attendanceSettings()['demo_anytime_clock_in'])) {
            $settings = employeeAttendanceSettings((int)$user['id']);
            $now = date('Y-m-d H:i:s');
            $lateMinutes = max(0, (int)ceil((time() - strtotime($today . ' ' . $settings['shift_start'])) / 60));
            $status = $lateMinutes > 0 ? 'late' : 'on_time';
            $remark = $lateMinutes > 0 ? 'Late (' . $lateMinutes . ' mins)' : 'On Time';
            $restart = $pdo->prepare("UPDATE attendance_records
                SET clock_in = ?, break_start = NULL, break_end = NULL, clock_out = NULL,
                    status = ?, day_status = 'in_progress', approval_status = 'not_required',
                    late_minutes = ?, undertime_minutes = 0, overtime_minutes = 0,
                    worked_seconds = 0, attendance_remark = ?, admin_note = NULL,
                    auto_clock_out = 0
                WHERE id = ?");
            $restart->execute([$now, $status, $lateMinutes, $remark, $record['id']]);
            $pdo->prepare('DELETE FROM attendance_proofs WHERE attendance_id = ?')->execute([$record['id']]);
            saveAttendanceProof($pdo, (int)$record['id'], (int)$user['id'], 'clock_in', $faceDistance);
            $pdo->commit();
            attendanceRespond(true, 'New demo time in recorded at ' . date('g:i:s A') . '. You may test break and time out again.', ['event' => 'clock_in', 'record_id' => (int)$record['id'], 'late_minutes' => $lateMinutes]);
        }
        if (!$record) {
            if ($action !== 'clock') {
                $pdo->rollBack();
                attendanceRespond(false, 'Clock in before recording a break or ending your shift.');
            }
            $settings = employeeAttendanceSettings((int)$user['id']);
            $demoAnytimeClockIn = !empty($settings['demo_anytime_clock_in']);
            if (!$demoAnytimeClockIn && (int)date('N') === $settings['day_off_iso']) {
                $pdo->rollBack();
                attendanceRespond(false, 'Tuesday is your scheduled day off. Time in is not available today.');
            }
            if (!$demoAnytimeClockIn && time() >= strtotime($today . ' ' . $settings['shift_end'])) {
                $pdo->rollBack();
                attendanceRespond(false, 'Operations end at 5:00 PM. Time in is no longer available today.');
            }
            $now = date('Y-m-d H:i:s');
            $lateMinutes = max(0, (int)ceil((time() - strtotime($today . ' ' . $settings['shift_start'])) / 60));
            $status = $lateMinutes > 0 ? 'late' : 'on_time';
            $remark = $lateMinutes > 0 ? 'Late (' . $lateMinutes . ' mins)' : 'On Time';
            $insert = $pdo->prepare('INSERT INTO attendance_records (employee_id, work_date, clock_in, status, late_minutes, attendance_remark) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([$user['id'], $today, $now, $status, $lateMinutes, $remark]);
            $id = (int)$pdo->lastInsertId();
            saveAttendanceProof($pdo, $id, (int)$user['id'], 'clock_in', $faceDistance);
            $pdo->commit();
            attendanceRespond(true, 'Time in recorded at ' . date('g:i:s A') . ($lateMinutes ? '. Late by ' . $lateMinutes . ' minute(s).' : '. On time.'), ['event' => 'clock_in', 'record_id' => $id, 'late_minutes' => $lateMinutes]);
        }
        if ($record['clock_out']) {
            $pdo->rollBack();
            attendanceRespond(false, 'Today\'s time in and time out are already complete.');
        }
        $now = date('Y-m-d H:i:s');
        if ($action === 'break_start') {
            if (!empty($record['break_start'])) {
                $pdo->rollBack();
                attendanceRespond(false, empty($record['break_end']) ? 'Your break is already active.' : 'Today\'s break has already been recorded.');
            }
            $update = $pdo->prepare('UPDATE attendance_records SET break_start = ? WHERE id = ? AND break_start IS NULL');
            $update->execute([$now, $record['id']]);
            $pdo->commit();
            attendanceRespond(true, 'Lunch Out recorded at ' . date('g:i:s A') . '. Your work timer is paused.', ['event' => 'break_start', 'record_id' => (int)$record['id']]);
        }
        if ($action === 'break_end') {
            if (empty($record['break_start']) || !empty($record['break_end'])) {
                $pdo->rollBack();
                attendanceRespond(false, empty($record['break_start']) ? 'Start your break before resuming work.' : 'Your break has already ended.');
            }
            $update = $pdo->prepare('UPDATE attendance_records SET break_end = ? WHERE id = ? AND break_start IS NOT NULL AND break_end IS NULL');
            $update->execute([$now, $record['id']]);
            saveAttendanceProof($pdo, (int)$record['id'], (int)$user['id'], 'break_end', $faceDistance);
            $pdo->commit();
            attendanceRespond(true, 'Face matched and Lunch In recorded at ' . date('g:i:s A') . '. Your work timer has resumed.', ['event' => 'break_end', 'record_id' => (int)$record['id'], 'face_match_distance' => $faceDistance]);
        }
        if ($action === 'clock') $action = 'clock_out';
        if ($action !== 'clock_out') {
            $pdo->rollBack();
            attendanceRespond(false, 'Invalid attendance action.');
        }
        $result = finalizeAttendance($pdo, $record, $now, false);
        saveAttendanceProof($pdo, (int)$record['id'], (int)$user['id'], 'clock_out', $faceDistance);
        $pdo->commit();
        $message = 'Time out recorded at ' . date('g:i:s A') . '.';
        $message .= ' ' . $result['attendance_remark'] . '.' . ($result['approval_status'] === 'pending' ? ' Pending Admin review.' : ' Automatically approved.');
        attendanceRespond(true, $message, ['event' => 'clock_out', 'record_id' => (int)$record['id']] + $result);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        attendanceRespond(false, 'Attendance could not be recorded. Please try again.');
    }
}

if ($action === 'attendance_decision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user['position'] !== 'Administrator') {
        http_response_code(403);
        attendanceRespond(false, 'Administrator access is required.');
    }
    $id = (int)($_POST['id'] ?? 0);
    $decision = trim($_POST['decision'] ?? '');
    $adminNote = trim($_POST['admin_note'] ?? '');
    if ($id < 1 || !in_array($decision, ['approved', 'rejected'], true)) attendanceRespond(false, 'Invalid attendance review decision.');
    if (mb_strlen($adminNote) > 1000) attendanceRespond(false, 'Admin note must not exceed 1000 characters.');
    if ($decision === 'rejected' && $adminNote === '') attendanceRespond(false, 'A rejection reason is required.');
    $stmt = $pdo->prepare("UPDATE attendance_records SET approval_status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND approval_status = 'pending'");
    $stmt->execute([$decision, $adminNote ?: null, $user['id'], $id]);
    if ($stmt->rowCount() !== 1) attendanceRespond(false, 'This attendance record is no longer pending review.');
    attendanceRespond(true, 'Attendance record ' . $decision . ' successfully.');
}

if ($action === 'proof') {
    if ($user['position'] !== 'Administrator') {
        http_response_code(403);
        attendanceRespond(false, 'Administrator access is required.');
    }
    $proofId = (int)($_GET['proof_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT p.*, e.full_name, a.work_date
        FROM attendance_proofs p
        JOIN employees e ON e.id = p.employee_id
        JOIN attendance_records a ON a.id = p.attendance_id
        WHERE p.proof_id = ? LIMIT 1");
    $stmt->execute([$proofId]);
    $proof = $stmt->fetch();
    if (!$proof) {
        http_response_code(404);
        attendanceRespond(false, 'Attendance proof was not found.');
    }
    $absolutePhoto = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $proof['photo_path']);
    if (!is_file($absolutePhoto)) {
        http_response_code(404);
        attendanceRespond(false, 'The attendance selfie file is missing.');
    }
    $photoData = base64_encode((string)file_get_contents($absolutePhoto));
    $employeeName = htmlspecialchars($proof['full_name'], ENT_QUOTES, 'UTF-8');
    $eventLabel = $proof['event_type'] === 'clock_in' ? 'Time In' : ($proof['event_type'] === 'break_end' ? 'Lunch In' : 'Time Out');
    $capturedAt = date('F j, Y · g:i:s A', strtotime($proof['captured_at']));
    $latitude = (float)$proof['latitude'];
    $longitude = (float)$proof['longitude'];
    $accuracy = number_format((float)$proof['accuracy_meters'], 0);
    $faceMatch = $proof['face_match_distance'] !== null ? 'Passed · distance ' . number_format((float)$proof['face_match_distance'], 3) : 'Legacy proof';
    $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode($latitude . ',' . $longitude);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Attendance Proof</title><style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;padding:32px 18px;background:#2b1f18;color:#e6d6c2;font-family:Arial,sans-serif}
    .card{max-width:780px;margin:auto;background:#432d21;border:1px solid #8b5e3c;border-radius:24px;overflow:hidden;box-shadow:0 24px 60px #160f0b}
    .head{padding:24px 28px;background:#35241b;border-bottom:1px solid #6c4933}.eyebrow{color:#c9a17a;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase}
    h1{margin:8px 0 4px;font-family:Georgia,serif;font-size:30px}.sub{color:#bca995}.photo{display:block;width:100%;max-height:560px;object-fit:contain;background:#1b1310}
    .details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:24px 28px}.item{padding:15px;border:1px solid #755039;border-radius:14px;background:#39271e}
    .label{display:block;margin-bottom:5px;color:#a98d78;font-size:11px;text-transform:uppercase;letter-spacing:1px}.value{font-weight:700}.map{color:#f0c99f}.note{grid-column:1/-1;color:#bca995;font-size:13px}
    @media(max-width:600px){.details{grid-template-columns:1fr;padding:18px}.note{grid-column:auto}body{padding:12px}}</style></head><body><main class="card">
    <div class="head"><div class="eyebrow">Quadra Cafe · Verified Attendance Evidence</div><h1>' . $employeeName . '</h1><div class="sub">' . $eventLabel . ' proof</div></div>
    <img class="photo" src="data:image/jpeg;base64,' . $photoData . '" alt="Live attendance selfie">
    <section class="details"><div class="item"><span class="label">Event</span><span class="value">' . $eventLabel . '</span></div>
    <div class="item"><span class="label">Captured</span><span class="value">' . htmlspecialchars($capturedAt, ENT_QUOTES, 'UTF-8') . '</span></div>
    <div class="item"><span class="label">GPS Coordinates</span><span class="value">' . htmlspecialchars(number_format($latitude, 7) . ', ' . number_format($longitude, 7), ENT_QUOTES, 'UTF-8') . '</span></div>
    <div class="item"><span class="label">Location Accuracy</span><span class="value">±' . $accuracy . ' meters</span></div>
    <div class="item"><span class="label">Face Verification</span><span class="value">' . htmlspecialchars($faceMatch, ENT_QUOTES, 'UTF-8') . ' · Head movement verified</span></div>
    <div class="item note">GPS provides device location evidence. <a class="map" href="' . htmlspecialchars($mapUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Open location in Google Maps</a>.</div></section>
    </main></body></html>';
    exit;
}

if ($action === 'admin') {
    if ($user['position'] !== 'Administrator') {
        http_response_code(403);
        attendanceRespond(false, 'Administrator access is required.');
    }
    $date = trim($_GET['date'] ?? date('Y-m-d'));
    $validDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!$validDate || $validDate->format('Y-m-d') !== $date) attendanceRespond(false, 'Invalid attendance date.');

    $stmt = $pdo->prepare("SELECT e.id AS employee_id, e.full_name, e.email, e.position, a.id, a.work_date, a.clock_in, a.break_start, a.break_end, a.clock_out, a.status, a.day_status, a.approval_status, a.late_minutes, a.undertime_minutes, a.overtime_minutes, a.worked_seconds, a.attendance_remark, a.admin_note, a.auto_clock_out, a.reviewed_at, reviewer.full_name AS reviewed_by_name,
        (SELECT proof_id FROM attendance_proofs p WHERE p.attendance_id = a.id AND p.event_type = 'clock_in' LIMIT 1) AS clock_in_proof_id,
        (SELECT proof_id FROM attendance_proofs p WHERE p.attendance_id = a.id AND p.event_type = 'break_end' LIMIT 1) AS break_end_proof_id,
        (SELECT proof_id FROM attendance_proofs p WHERE p.attendance_id = a.id AND p.event_type = 'clock_out' LIMIT 1) AS clock_out_proof_id
        FROM employees e LEFT JOIN attendance_records a ON a.employee_id = e.id AND a.work_date = ?
        LEFT JOIN employees reviewer ON reviewer.id = a.reviewed_by
        WHERE e.status = 'approved' AND e.position <> 'Administrator' AND e.position NOT LIKE '%HR%'
        ORDER BY e.full_name");
    $stmt->execute([$date]);
    $leaveMap = attendanceLeaveMap($pdo, $date);
    $records = [];
    foreach ($stmt->fetchAll() as $row) {
        $employeeSchedule = employeeAttendanceSettings((int)$row['employee_id']);
        $isEmployeeDayOff = (int)date('N', strtotime($date)) === (int)$employeeSchedule['day_off_iso'];
        $absenceCutoff = strtotime($date . ' ' . $employeeSchedule['shift_start'])
            + ((int)$employeeSchedule['absent_after_minutes'] * 60);
        $absenceCutoffPassed = $date < date('Y-m-d')
            || ($date === date('Y-m-d') && time() >= $absenceCutoff);
        $leave = $leaveMap[(int)$row['employee_id']] ?? null;
        $status = $row['status'] ?: ($leave ? 'on_leave' : ($isEmployeeDayOff ? 'day_off' : ($absenceCutoffPassed ? 'absent' : 'not_clocked_in')));
        $records[] = [
            'employee_id' => (int)$row['employee_id'], 'full_name' => $row['full_name'], 'email' => $row['email'], 'position' => $row['position'],
            'department' => attendanceDepartment($row['position']), 'clock_in' => $row['clock_in'], 'break_start' => $row['break_start'], 'break_end' => $row['break_end'], 'clock_out' => $row['clock_out'], 'status' => $status,
            'scheduled_day_off' => $employeeSchedule['day_off_label'],
            'record_id' => $row['id'] ? (int)$row['id'] : null, 'day_status' => $row['day_status'] ?: 'in_progress', 'approval_status' => $row['approval_status'] ?: 'not_required',
            'late_minutes' => (int)($row['late_minutes'] ?? 0), 'undertime_minutes' => (int)($row['undertime_minutes'] ?? 0), 'overtime_minutes' => (int)($row['overtime_minutes'] ?? 0),
            'attendance_remark' => $row['attendance_remark'] ?? null, 'admin_note' => $row['admin_note'] ?? null, 'auto_clock_out' => (int)($row['auto_clock_out'] ?? 0), 'reviewed_at' => $row['reviewed_at'] ?? null, 'reviewed_by_name' => $row['reviewed_by_name'] ?? null,
            'clock_in_proof_id' => $row['clock_in_proof_id'] ? (int)$row['clock_in_proof_id'] : null, 'break_end_proof_id' => $row['break_end_proof_id'] ? (int)$row['break_end_proof_id'] : null, 'clock_out_proof_id' => $row['clock_out_proof_id'] ? (int)$row['clock_out_proof_id'] : null,
            'leave_type' => $leave['leave_type'] ?? null, 'leave_pay_type' => $leave['pay_type'] ?? null, 'leave_during' => $leave['leave_during'] ?? null,
            'duration_seconds' => $row['clock_in'] ? (int)($row['worked_seconds'] ?: durationSeconds($row['clock_in'], $row['clock_out'], $row['break_start'], $row['break_end'])) : 0,
        ];
    }
    $present = count(array_filter($records, static fn($r) => $r['clock_in']));
    $late = count(array_filter($records, static fn($r) => $r['status'] === 'late'));
    $onLeave = count(array_filter($records, static fn($r) => $r['status'] === 'on_leave'));
    $absent = count(array_filter($records, static fn($r) => $r['status'] === 'absent'));

    $activityStmt = $pdo->query("SELECT a.clock_in, a.break_start, a.break_end, a.clock_out, a.status, e.full_name FROM attendance_records a JOIN employees e ON e.id = a.employee_id ORDER BY GREATEST(a.clock_in, COALESCE(a.clock_out, a.break_end, a.break_start, a.clock_in)) DESC LIMIT 8");
    $activities = [];
    foreach ($activityStmt->fetchAll() as $row) {
        if ($row['clock_out']) $activities[] = ['employee' => $row['full_name'], 'type' => 'clock_out', 'occurred_at' => $row['clock_out']];
        if ($row['break_end']) $activities[] = ['employee' => $row['full_name'], 'type' => 'break_end', 'occurred_at' => $row['break_end']];
        if ($row['break_start']) $activities[] = ['employee' => $row['full_name'], 'type' => 'break_start', 'occurred_at' => $row['break_start']];
        $activities[] = ['employee' => $row['full_name'], 'type' => $row['status'] === 'late' ? 'late_clock_in' : 'clock_in', 'occurred_at' => $row['clock_in']];
    }
    usort($activities, static fn($a, $b) => strcmp($b['occurred_at'], $a['occurred_at']));
    attendanceRespond(true, '', ['date' => $date, 'is_day_off' => false, 'records' => $records, 'stats' => ['present' => $present, 'late' => $late, 'absent' => $absent, 'on_leave' => $onLeave], 'activities' => array_slice($activities, 0, 8)]);
}

http_response_code(400);
attendanceRespond(false, 'Invalid attendance action.');
