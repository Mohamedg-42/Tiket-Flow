<?php
require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query("SELECT id, nom, capacite, statut FROM salles");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
