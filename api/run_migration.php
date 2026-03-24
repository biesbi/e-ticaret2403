<?php
require_once 'config.php';
require_once 'helpers.php';

echo "Running image URL migration...\n";

// Read SQL file
$sql = file_get_contents('sql/migrations/001_fix_image_urls.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));

$db = db();
foreach ($statements as $statement) {
    if (empty($statement) || str_starts_with($statement, '--')) {
        continue;
    }
    
    echo "Executing: " . substr($statement, 0, 80) . "...\n";
    $result = $db->exec($statement);
    echo "  → Affected rows: $result\n";
}

echo "\n✓ Migration completed!\n";
