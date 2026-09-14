<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE '%whitelist%'");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "Table: " . $r['table_name'] . "\n";
    $cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='{$r['table_name']}'");
    while ($c = $cols->fetch(PDO::FETCH_ASSOC)) {
        echo "  - {$c['column_name']} ({$c['data_type']})\n";
    }
}
