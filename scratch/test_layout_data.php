<?php
chdir(__DIR__ . '/../client');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

$order_id = 87;
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

$stmt_items = $pdo->prepare('
    SELECT oi.*, tt.nom AS ticket_nom, tt.prix AS ticket_prix,
           e.id AS event_id, e.nom AS event_nom, e.date_evenement, e.heure, e.lieu, e.image AS event_image, e.categorie AS event_cat
    FROM order_items oi
    JOIN ticket_types tt ON tt.id = oi.ticket_type_id
    JOIN events e ON tt.event_id = e.id
    WHERE oi.order_id = ?
');
$stmt_items->execute([$order_id]);
$order_items = $stmt_items->fetchAll();
$event_main = !empty($order_items) ? $order_items[0] : null;

print_r($order);
print_r($order_items);
