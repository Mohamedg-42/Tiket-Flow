<?php
require_once __DIR__ . '/../config/database.php';

$tables = ['events', 'promoters', 'users', 'tickets', 'orders', 'cotisation_campagnes', 'cotisations', 'vote_paiements'];
foreach ($tables as $t) {
    echo "=== TABLE: $t ===\n";
    try {
        $stmt = $pdo->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position");
        $stmt->execute([$t]);
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  - {$c['column_name']} ({$c['data_type']})\n";
        }
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
}
