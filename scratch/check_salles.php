<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT * FROM salles");
$salles = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "SALLES COUNT: " . count($salles) . "\n";
echo json_encode($salles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
