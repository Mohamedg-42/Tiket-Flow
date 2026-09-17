<?php
require_once __DIR__ . '/../config/database.php';

try {
    $sql = file_get_contents(__DIR__ . '/../config/migration-secure-tokens.sql');
    $pdo->exec($sql);
    echo "Migration table secure_resource_tokens exécutée avec succès.\n";
} catch (Exception $e) {
    echo "Erreur migration : " . $e->getMessage() . "\n";
    exit(1);
}
