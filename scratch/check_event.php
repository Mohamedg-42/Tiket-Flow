<?php
require_once __DIR__ . '/../config/database.php';
$s = $pdo->query('SELECT id, nom, lieu, salle_id FROM events WHERE id = 10')->fetch(PDO::FETCH_ASSOC);
echo "EVENT:\n";
print_r($s);

$tickets = $pdo->query('SELECT id, nom, prix, quantite, frais_place FROM ticket_types WHERE event_id = 10')->fetchAll(PDO::FETCH_ASSOC);
echo "\nTICKETS:\n";
print_r($tickets);

if (!empty($s['salle_id'])) {
    $salle = $pdo->query('SELECT * FROM salles WHERE id = ' . (int)$s['salle_id'])->fetch(PDO::FETCH_ASSOC);
    echo "\nSALLE:\n";
    print_r($salle);
    
    $zones = $pdo->query('SELECT * FROM zones WHERE salle_id = ' . (int)$s['salle_id'])->fetchAll(PDO::FETCH_ASSOC);
    echo "\nZONES: " . count($zones) . "\n";
    
    $seats = $pdo->query('SELECT count(*) as cnt FROM sieges WHERE salle_id = ' . (int)$s['salle_id'])->fetch(PDO::FETCH_ASSOC);
    echo "\nSIEGES: " . $seats['cnt'] . "\n";
} else {
    echo "\nNO SALLE_ID LINKED TO EVENT 10!\n";
}
