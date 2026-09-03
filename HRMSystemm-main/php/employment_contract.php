<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/attendance_settings.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['user_role'] ?? '');
$isHr = $role === 'Administrator' || stripos($role, 'HR') !== false || strcasecmp($role, 'Human Resources') === 0;
if ($userId < 1 || !$isHr) {
    http_response_code(403);
    exit('HR or Administrator access is required.');
}

$employeeId = (int)($_GET['employee_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, full_name, email, position, created_at FROM employees WHERE id = ? AND status IN ('approved','inactive') LIMIT 1");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch();
if (!$employee) {
    http_response_code(404);
    exit('Onboarding employee not found.');
}

$position = strtolower((string)$employee['position']);
$monthlySalary = strpos($position, 'barista') !== false ? 16000 : (strpos($position, 'cashier') !== false ? 15000 : 14000);
$schedule = employeeAttendanceSettings((int)$employee['id']);
$formatTime = static fn(string $time): string => date('g:i A', strtotime('2000-01-01 ' . $time));
$employeeName = htmlspecialchars($employee['full_name'], ENT_QUOTES, 'UTF-8');
$employeePosition = htmlspecialchars(ucwords($employee['position']), ENT_QUOTES, 'UTF-8');
$employeeEmail = htmlspecialchars($employee['email'], ENT_QUOTES, 'UTF-8');
$salary = 'PHP ' . number_format($monthlySalary, 2);
$shift = $formatTime($schedule['shift_start']) . ' – ' . $formatTime($schedule['shift_end']);
$break = $formatTime($schedule['lunch_start']) . ' – ' . $formatTime($schedule['lunch_end']);
$dayOff = htmlspecialchars($schedule['day_off_label'], ENT_QUOTES, 'UTF-8');
$date = date('F j, Y');
$effectiveDate = date('F j, Y', strtotime($employee['created_at']));
$positionDuties = strpos($position, 'cashier') !== false
    ? 'Process customer orders and payments accurately, issue receipts, maintain the cash drawer, assist guests, and keep the assigned service area orderly.'
    : (strpos($position, 'barista') !== false
        ? 'Prepare and serve beverages according to approved recipes, maintain product quality and sanitation, assist guests, and keep the bar and equipment clean and orderly.'
        : 'Perform the normal duties of the assigned position, assist customers and team members, protect company property, and maintain workplace cleanliness and safety.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quadra Cafe Employment Contract - <?= $employeeName ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#d8d0c7;color:#29211c;font:14px/1.65 Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:18px;padding:13px 22px;background:#2b1f18;color:#f3e6d5;box-shadow:0 4px 18px #0003}.toolbar strong{display:block}.toolbar small{color:#c9a17a}.toolbar button{border:0;border-radius:9px;padding:11px 18px;background:#b98559;color:#fff;font-weight:700;cursor:pointer}.document{width:210mm;margin:24px auto;background:#fff;box-shadow:0 12px 40px #0002}.page{min-height:297mm;padding:22mm 20mm;position:relative;page-break-after:always}.page:last-child{page-break-after:auto}.head{display:flex;align-items:center;gap:14px;border-bottom:2px solid #8b5e3c;padding-bottom:14px;margin-bottom:28px}.head img{width:62px;height:62px;object-fit:contain}.brand h1{margin:0;color:#5e3e2b;font:700 27px Georgia,serif}.brand p{margin:2px 0;color:#77675d;font-size:11px;letter-spacing:2px}.meta{text-align:right;margin-left:auto;color:#6d5d52;font-size:11px}.title{text-align:center;color:#5e3e2b;font:700 25px Georgia,serif;margin:25px 0}.subject{font-weight:700}.editable{display:inline-block;min-width:120px;padding:0 4px;border-bottom:1px solid #8b5e3c;background:#fff9d8}.terms{width:100%;border-collapse:collapse;margin:18px 0}.terms th,.terms td{padding:9px 11px;border:1px solid #d8cbc0;text-align:left;vertical-align:top}.terms th{width:31%;background:#f4eee8;color:#5e3e2b}.clause{margin:16px 0}.clause h3{margin:0 0 4px;color:#5e3e2b;font-size:14px}.signature-grid{display:grid;grid-template-columns:1fr 1fr;gap:55px;margin-top:60px}.signature{border-top:1px solid #29211c;padding-top:7px}.signature strong,.signature span{display:block}.signature span{font-size:11px;color:#77675d}.notice{margin-top:30px;padding:12px;border:1px solid #d8cbc0;background:#f8f4f0;color:#76665c;font-size:10px}.footer{position:absolute;left:20mm;right:20mm;bottom:12mm;display:flex;justify-content:space-between;border-top:1px solid #ddd;padding-top:6px;color:#999;font-size:9px}@media(max-width:850px){.document{width:100%;margin:0}.page{padding:28px 20px}.toolbar{position:relative;align-items:flex-start}.signature-grid{grid-template-columns:1fr;gap:45px}}@media print{body{background:#fff}.toolbar{display:none}.document{width:auto;margin:0;box-shadow:none}.page{margin:0}.editable{background:transparent}.notice{display:none}}
.signature-note{margin-top:5px;font-size:9px;color:#8a7a70}
.document,.document *{color:#000!important}.document .terms th{background:#f2f2f2}.document .head{border-bottom-color:#000}.document .terms th,.document .terms td{border-color:#999}.document .signature{border-top-color:#000}.document .footer{border-top-color:#999}
@page{size:A4;margin:0}
@media print{
  html,body{width:210mm;height:auto;margin:0!important;padding:0!important;background:#fff}
  body{font-size:10.5px;line-height:1.38}
  .document{width:210mm!important;margin:0!important;padding:0!important}
  .page{width:210mm;height:297mm;min-height:297mm;max-height:297mm;overflow:hidden;padding:12mm 17mm 11mm;page-break-after:always;break-after:page}
  .page:last-child{page-break-after:auto;break-after:auto}
  .head{padding-bottom:8px;margin-bottom:13px}
  .head img{width:48px;height:48px}
  .brand h1{font-size:22px}.brand p,.meta{font-size:8px}
  .title{font-size:20px;margin:12px 0}
  .terms{margin:10px 0}.terms th,.terms td{padding:5px 7px}
  .clause{margin:7px 0}.clause h3{font-size:11px;margin-bottom:1px}.clause p{margin:2px 0}
  p{margin:7px 0}
  .signature-grid{margin-top:37px;gap:40px}
  .signature span{font-size:9px}
  .notice{display:none}
  .footer{left:17mm;right:17mm;bottom:6mm;font-size:7px}
}
</style>
</head>
<body>
<div class="toolbar"><div><strong>Quadra Cafe Employment Contract</strong><small>Fixed 2-page A4 layout. In the print dialog, keep “Headers and footers” off.</small></div><button onclick="window.print()">Print / Save as PDF</button></div>
<main class="document">
  <section class="page">
    <header class="head"><img src="../images/logo.png" alt="Quadra Cafe"><div class="brand"><h1>Quadra Cafe</h1><p>EMPLOYMENT OFFER</p></div><div class="meta">Document No. QC-EMP-<?= (int)$employee['id'] ?><br><?= $date ?></div></header>
    <p><?= $date ?></p>
    <p><strong><?= $employeeName ?></strong><br><?= $employeeEmail ?></p>
    <p class="subject">Subject: Employment Offer – <?= $employeePosition ?></p>
    <p>Dear <?= $employeeName ?>,</p>
    <p>Quadra Cafe is pleased to offer you full-time employment as <strong><?= $employeePosition ?></strong>, subject to the terms of the attached Employment Agreement and applicable company policies.</p>
    <table class="terms">
      <tr><th>Start Date</th><td><?= $effectiveDate ?></td></tr>
      <tr><th>Employment Status</th><td>Probationary Full-Time Employee</td></tr>
      <tr><th>Monthly Basic Salary</th><td><?= $salary ?>, paid semi-monthly, subject to lawful deductions</td></tr>
      <tr><th>Standard Shift</th><td><?= $shift ?></td></tr>
      <tr><th>Meal Break</th><td><?= $break ?></td></tr>
      <tr><th>Scheduled Day Off</th><td><?= $dayOff ?></td></tr>
      <tr><th>Work Location</th><td>Quadra Cafe Main Branch</td></tr>
    </table>
    <p>This offer is conditional upon submission and verification of employment requirements and your execution of the attached agreement. Statutory benefits and legally required contributions will be provided in accordance with applicable Philippine laws and regulations.</p>
    <p>Please indicate your acceptance by signing below and the attached Employment Agreement.</p>
    <p>We look forward to welcoming you to the Quadra Cafe team.</p>
    <p>Sincerely,</p>
    <div class="signature-grid"><div class="signature"><strong>Stephanie Nicole Briones</strong><span>HR Manager / Authorized Representative</span><span>Quadra Cafe</span><div class="signature-note">Date: __________________</div></div><div class="signature"><strong><?= $employeeName ?></strong><span>Employee – Conforme</span><div class="signature-note">Date: __________________</div></div></div>
    <footer class="footer"><span>Quadra Cafe • Confidential Employment Document</span><span>Offer Letter</span></footer>
  </section>

  <section class="page">
    <header class="head"><img src="../images/logo.png" alt="Quadra Cafe"><div class="brand"><h1>Quadra Cafe</h1><p>EMPLOYMENT AGREEMENT</p></div><div class="meta">Employee ID <?= (int)$employee['id'] ?></div></header>
    <h2 class="title">EMPLOYMENT AGREEMENT</h2>
    <p>This Employment Agreement is entered into effective <strong><?= $effectiveDate ?></strong> by and between <strong>Quadra Cafe</strong>, operating at Quadra Cafe Main Branch (“Employer”), and <strong><?= $employeeName ?></strong> (“Employee”).</p>
    <div class="clause"><h3>1. Position and Employment Status</h3><p>The Employee is engaged as <strong><?= $employeePosition ?></strong> on a probationary full-time basis. The Employee will be evaluated using reasonable standards communicated at engagement, including attendance, work quality, customer service, safety, sanitation, teamwork, reliability, and compliance with company policies.</p></div>
    <div class="clause"><h3>2. Duties and Responsibilities</h3><p><?= htmlspecialchars($positionDuties, ENT_QUOTES, 'UTF-8') ?> The Employee shall also follow lawful and reasonable instructions and comply with the Employee Handbook and workplace policies.</p></div>
    <div class="clause"><h3>3. Compensation and Payment</h3><p>The Employee shall receive a monthly basic salary of <strong><?= $salary ?></strong>, payable semi-monthly through the Employer’s established payroll method. Overtime, holiday pay, premium pay, and other wage-related benefits shall be handled according to applicable law and approved attendance records.</p></div>
    <div class="clause"><h3>4. Work Schedule and Rest Period</h3><p>The standard shift is <strong><?= $shift ?></strong>, with meal break from <strong><?= $break ?></strong> and scheduled day off every <strong><?= $dayOff ?></strong>. Operationally necessary changes will be communicated in advance where practicable and implemented consistently with applicable labor standards.</p></div>
    <div class="clause"><h3>5. Benefits and Deductions</h3><p>The Employee will receive statutory benefits and coverage required by law, including applicable SSS, PhilHealth, and Pag-IBIG benefits. Deductions shall be limited to those required by law or supported by the Employee’s written authorization where required.</p></div>
    <div class="clause"><h3>6. Attendance, Conduct, and Safety</h3><p>The Employee shall accurately record time in, breaks, and time out; promptly report absences; observe food safety and occupational safety requirements; and comply with lawful company rules. Attendance records remain subject to verification and correction.</p></div>
    <div class="clause"><h3>7. Confidentiality and Company Property</h3><p>During and after employment, the Employee shall not improperly disclose confidential business, customer, payroll, recipe, supplier, or personnel information. All company property must be returned upon request or separation.</p></div>
    <div class="clause"><h3>8. Grievance and Workplace Concerns</h3><p>Concerns should first be raised in good faith with the immediate supervisor or HR. If unresolved, the Employee may submit a written grievance to management without retaliation, subject to company procedure and applicable rights.</p></div>
    <div class="clause"><h3>9. Separation and Governing Standards</h3><p>Employment may be ended only in accordance with applicable law, due process requirements, and valid company policies. No provision of this Agreement waives minimum labor standards or statutory employee rights.</p></div>
    <div class="clause"><h3>10. Entire Agreement and Acknowledgment</h3><p>This Agreement, its approved annexes, and incorporated company policies contain the employment terms. Changes must be in writing and acknowledged by both parties. The Employee confirms that the terms were explained in a language understood by the Employee and that a signed copy will be provided.</p></div>
    <div class="signature-grid"><div class="signature"><strong>Stephanie Nicole Briones</strong><span>HR Manager / Authorized Representative</span><span>Quadra Cafe</span><div class="signature-note">Date: __________________</div></div><div class="signature"><strong><?= $employeeName ?></strong><span>Employee</span><div class="signature-note">Date: __________________</div></div></div>
    <footer class="footer"><span>Quadra Cafe • Confidential Employment Document</span><span>Employment Agreement</span></footer>
  </section>
</main>
</body>
</html>
