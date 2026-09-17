<?php
require_once __DIR__ . '/../config/database.php';

echo "=== TABLES ===\n";
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo "- $t\n";
}

echo "\n=== FOREIGN KEYS ===\n";
$fkQuery = "
    SELECT
        tc.table_name, 
        kcu.column_name, 
        ccu.table_name AS foreign_table_name,
        ccu.column_name AS foreign_column_name 
    FROM information_schema.table_constraints AS tc 
    JOIN information_schema.key_column_usage AS kcu
      ON tc.constraint_name = kcu.constraint_name
      AND tc.table_schema = kcu.table_schema
    JOIN information_schema.constraint_column_usage AS ccu
      ON ccu.constraint_name = tc.constraint_name
      AND ccu.table_schema = tc.table_schema
    WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema='public'
    ORDER BY tc.table_name, kcu.column_name;
";
foreach ($pdo->query($fkQuery) as $fk) {
    echo "{$fk['table_name']}.{$fk['column_name']} -> {$fk['foreign_table_name']}.{$fk['foreign_column_name']}\n";
}

echo "\n=== USERS SUMMARY (ROLES & COUNTS) ===\n";
$stmtUsers = $pdo->query("SELECT role, statut, COUNT(*) as count FROM users GROUP BY role, statut ORDER BY role, statut");
foreach ($stmtUsers as $u) {
    echo "Role: {$u['role']} | Statut: {$u['statut']} | Count: {$u['count']}\n";
}
