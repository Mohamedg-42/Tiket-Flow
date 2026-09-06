<?php
// Connect to MySQL
$pdo_mysql = new PDO("mysql:host=localhost;dbname=ticket_platform;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Connect to PostgreSQL
$pdo_pg = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=ticket_platform;", "postgres", "123", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// List all tables in MySQL
$tables = $pdo_mysql->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo sprintf("%-30s | %-12s | %-12s\n", "Table", "MySQL Count", "PG Count");
echo str_repeat("-", 60) . "\n";

foreach ($tables as $table) {
    $count_mysql = $pdo_mysql->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    
    try {
        $count_pg = $pdo_pg->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn();
    } catch (Exception $e) {
        $count_pg = "N/A (Error)";
    }
    
    echo sprintf("%-30s | %-12s | %-12s\n", $table, $count_mysql, $count_pg);
}
