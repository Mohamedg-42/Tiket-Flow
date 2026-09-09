<?php
require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'tickets'");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns of table tickets:\n";
    foreach ($cols as $c) {
        echo " - " . $c['column_name'] . " (" . $c['data_type'] . ")\n";
    }

    $stmt_t_seats = $pdo->prepare("SELECT place_numero FROM tickets WHERE event_id = ? AND statut = 'vendu' AND place_numero IS NOT NULL");
    $stmt_t_seats->execute([10]);
    echo "Query on place_numero SUCCEEDED!\n";
} catch (PDOException $e) {
    echo "PDO EXCEPTION: " . $e->getMessage() . "\n";
}
