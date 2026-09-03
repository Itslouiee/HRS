<?php
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');

function financeRespond(bool $success, string $message = '', array $extra = []): void { echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra)); exit; }
function financePeriodLabel(string $period): string {
    if (!preg_match('/^(\d{4}-\d{2})-(15|30)$/', $period, $m)) return $period;
    $date = new DateTime($m[1] . '-01');
    return $date->format('F Y') . ($m[2] === '15' ? ' (1-15)' : ' (16-' . $date->format('t') . ')');
}
function financeCurrentPeriod(): string {
    $today = new DateTime('today'); $day = (int)$today->format('j'); $lastDay = (int)$today->format('t');
    return $day >= $lastDay ? $today->format('Y-m') . '-30' : ($day >= 15 ? $today->format('Y-m') . '-15' : $today->modify('first day of last month')->format('Y-m') . '-30');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare('SELECT position, status FROM employees WHERE id = ? LIMIT 1'); $stmt->execute([$userId]); $user = $stmt->fetch();
if (!$user || $user['status'] !== 'approved' || stripos($user['position'], 'finance') === false) { http_response_code(403); financeRespond(false, 'Finance access is required.'); }

$column = $pdo->query("SHOW COLUMNS FROM payroll_records LIKE 'payment_status'")->fetch();
if (!$column) $pdo->exec("ALTER TABLE payroll_records ADD COLUMN payment_status ENUM('pending','paid') NOT NULL DEFAULT 'paid' AFTER status");
// An issued payslip is automatically a paid payroll record. Finance only reports this status.
$pdo->exec("UPDATE payroll_records SET payment_status = 'paid' WHERE status = 'issued' AND payment_status <> 'paid'");
$action = $_GET['action'] ?? $_POST['action'] ?? 'summary';

if ($action === 'summary') {
    $employees = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'approved' AND position <> 'Administrator' AND position NOT LIKE '%HR%' AND position NOT LIKE '%Finance%'")->fetchColumn();
    $latestPeriod = financeCurrentPeriod();
    $totals = $pdo->query("SELECT COUNT(*) AS paid, COALESCE(SUM(gross_pay),0) AS gross, COALESCE(SUM(total_deductions),0) AS deductions, COALESCE(SUM(net_pay),0) AS net FROM payroll_records WHERE status = 'issued'")->fetch();
    $pending = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE e.status = 'approved' AND e.position <> 'Administrator' AND e.position NOT LIKE '%HR%' AND e.position NOT LIKE '%Finance%' AND NOT EXISTS (SELECT 1 FROM payroll_records pr WHERE pr.employee_id = e.id AND pr.period = ? AND pr.status = 'issued')");
    $pending->execute([$latestPeriod]);
    financeRespond(true, '', ['summary' => ['total_employees' => $employees, 'total_gross_payroll' => (float)$totals['gross'], 'total_deductions' => (float)$totals['deductions'], 'total_net_payroll' => (float)$totals['net'], 'pending_payments' => (int)$pending->fetchColumn(), 'paid_payroll' => (int)$totals['paid'], 'latest_period' => $latestPeriod]]);
}

if ($action === 'records') {
    $period = trim($_GET['period'] ?? ''); $payment = trim($_GET['payment_status'] ?? '');
    $sql = "SELECT pr.id, pr.employee_id, pr.period, pr.gross_pay, pr.total_deductions, pr.net_pay, pr.payment_status, pr.issued_at, e.full_name, e.position FROM payroll_records pr JOIN employees e ON e.id = pr.employee_id WHERE pr.status = 'issued'";
    $params = [];
    if (preg_match('/^\d{4}-\d{2}-(15|30)$/', $period)) { $sql .= ' AND pr.period = ?'; $params[] = $period; }
    if (in_array($payment, ['pending', 'paid'], true)) { $sql .= ' AND pr.payment_status = ?'; $params[] = $payment; }
    $sql .= ' ORDER BY pr.period DESC, e.full_name'; $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll();
    foreach ($records as &$record) $record['period_label'] = financePeriodLabel($record['period']); unset($record);
    financeRespond(true, '', ['records' => $records]);
}

if ($action === 'history') {
    $rows = $pdo->query("SELECT period, COUNT(*) AS employees, COALESCE(SUM(gross_pay),0) AS total_gross, COALESCE(SUM(total_deductions),0) AS total_deductions, COALESCE(SUM(net_pay),0) AS total_net, SUM(payment_status = 'pending') AS pending, SUM(payment_status = 'paid') AS paid, MAX(issued_at) AS issued_at FROM payroll_records WHERE status = 'issued' GROUP BY period ORDER BY period DESC")->fetchAll();
    foreach ($rows as &$row) $row['period_label'] = financePeriodLabel($row['period']); unset($row); financeRespond(true, '', ['records' => $rows]);
}

if ($action === 'payslip') {
    $id = (int)($_GET['id'] ?? 0); $stmt = $pdo->prepare("SELECT pr.*, e.full_name, e.email, e.position FROM payroll_records pr JOIN employees e ON e.id = pr.employee_id WHERE pr.id = ? AND pr.status = 'issued'"); $stmt->execute([$id]); $record = $stmt->fetch();
    if (!$record) { http_response_code(404); financeRespond(false, 'Payroll record not found.'); }
    $record['period_label'] = financePeriodLabel($record['period']); financeRespond(true, '', ['record' => $record]);
}

http_response_code(400); financeRespond(false, 'Invalid finance action.');
