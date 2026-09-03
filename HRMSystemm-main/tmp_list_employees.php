<?php
require __DIR__ . '/php/config.php';
$stmt = $pdo->query('SELECT id, position, status, branch_id, username, email FROM employees LIMIT 50');
foreach ($stmt as $row) {
    echo implode('|', [
        $row['id'],
        $row['position'] ?? '',
        $row['status'] ?? '',
        $row['branch_id'] ?? '',
        $row['username'] ?? '',
        $row['email'] ?? '',
    ]) . "\n";
}
