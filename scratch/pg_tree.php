<?php
$pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=ticket_platform;", "postgres", "123", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

echo "=== VÉRIFICATION POSTGRESQL ===\n";
echo "1. Bases de données disponibles :\n";
$dbs = $pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname;")->fetchAll(PDO::FETCH_COLUMN);
foreach ($dbs as $db) {
    echo "  - " . $db . "\n";
}

echo "\n2. Tables dans la base 'ticket_platform' -> Schéma 'public' :\n";
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name;")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
    echo sprintf("  - %-25s (%d lignes)\n", $t, $count);
}
