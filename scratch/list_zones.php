<?php
require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->prepare("SELECT * FROM salle_zones WHERE salle_id = 1");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
