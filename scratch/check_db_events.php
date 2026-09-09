<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query('SELECT id, nom, lieu, salle_id FROM events ORDER BY id DESC LIMIT 15');
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

// Also check salles
$stmtSalles = $pdo->query('SELECT id, nom, capacite_totale FROM salles LIMIT 10');
echo "SALLES:" . PHP_EOL;
echo json_encode($stmtSalles->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
