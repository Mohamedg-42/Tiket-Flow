<?php
require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query("SELECT id FROM orders ORDER BY id DESC LIMIT 5");
$orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($orderIds as $oid) {
    $st = $pdo->prepare('SELECT oi.*, tt.nom as ticket_nom, e.nom as event_nom, e.date_evenement, e.heure, e.lieu, e.image as event_image FROM order_items oi JOIN ticket_types tt ON tt.id = oi.ticket_type_id JOIN events e ON tt.event_id = e.id WHERE oi.order_id = ?');
    $st->execute([$oid]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "Order #$oid items count: " . count($items) . "\n";
    if (!empty($items)) {
        echo "  Event: {$items[0]['event_nom']} | Ticket: {$items[0]['ticket_nom']} | Qty: {$items[0]['quantite']}\n";
    }
}
