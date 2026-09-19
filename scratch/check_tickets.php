<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT * FROM ticket_types WHERE event_id IN (2, 4, 13)");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
