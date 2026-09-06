<?php
$pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=ticket_platform;", "postgres", "123", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
echo "Version : " . $pdo->query("SELECT version();")->fetchColumn() . "\n";
echo "Port    : " . $pdo->query("SHOW port;")->fetchColumn() . "\n";
echo "User    : " . $pdo->query("SELECT current_user;")->fetchColumn() . "\n";
echo "DBs     : " . implode(', ', $pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false;")->fetchAll(PDO::FETCH_COLUMN)) . "\n";
