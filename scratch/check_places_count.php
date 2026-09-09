<?php
require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM places");
    echo "Places count: " . $stmt->fetchColumn() . "\n";
} catch (PDOException $e) {
    echo "Places error: " . $e->getMessage() . "\n";
}
