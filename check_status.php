<?php
require __DIR__ . '/api/config.php';
require __DIR__ . '/api/helpers.php';
$ct = tableColumnType('orders', 'status');
echo 'Status column type: ' . ($ct ?? 'NULL') . PHP_EOL;

$allowed = [];
if (is_string($ct) && str_starts_with($ct, 'enum(')) {
    preg_match_all("/'([^']+)'/", $ct, $matches);
    $allowed = $matches[1] ?? [];
}
echo 'Allowed values: ' . implode(', ', $allowed) . PHP_EOL;
