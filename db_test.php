<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = db();

    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();

    echo "SUCCESS: Database connection works.\n";
    echo "Connected database: " . $database . "\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    echo "\nTables found:\n";

    foreach ($tables as $table) {
        echo "- " . $table . "\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('ChatWave db_test error: ' . $e->getMessage());
    echo "Database test failed. Check the Apache error log.\n";
}