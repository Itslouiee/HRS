<?php
require __DIR__ . '/php/config.php';
$rows = $pdo->query('SELECT id, position, status FROM employees ORDER BY id ASC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
echo "rows=" . count($rows) . "\n";
foreach ($rows as $row) {
    echo json_encode($row) . "\n";
}
