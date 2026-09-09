<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT id, event_id, nom, prix, frais_place, quantite, quantite_vendue FROM ticket_types ORDER BY event_id DESC, prix DESC");
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
