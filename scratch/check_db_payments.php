<?php
require_once __DIR__ . '/../config/database.php';
foreach (['payments', 'vote_paiements', 'cotisations', 'orders'] as $t) {
    echo "=== TABLE $t ===\n";
    $stmt = $pdo->prepare("SELECT column_name, data_type, udt_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position");
    $stmt->execute([$t]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        echo "  {$c['column_name']} ({$c['data_type']} / {$c['udt_name']})\n";
    }
}
