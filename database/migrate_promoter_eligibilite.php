<?php
require_once __DIR__ . '/../config/database.php';

echo "=== MIGRATION ÉLIGIBILITÉ PROMOTEURS (Physique / Morale) ===\n";

function addColumnIfNotExists($pdo, $table, $column, $definition) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "[AJOUTÉ] $table.$column\n";
        } else {
            echo "[EXISTE] $table.$column\n";
        }
    } catch (PDOException $e) {
        echo "[ERREUR] $table.$column : " . $e->getMessage() . "\n";
    }
}

// 0. Ajout de 'en_attente' au statut de users
try {
    $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `statut` ENUM('actif', 'inactif', 'en_attente', 'suspendu_temp', 'suspendu_def') NOT NULL DEFAULT 'actif'");
    echo "[MODIFIÉ] users.statut (avec 'en_attente')\n";
} catch (PDOException $e) {
    echo "[INFO] users.statut : " . $e->getMessage() . "\n";
}

// 1. Enrichissement de promoter_requests
addColumnIfNotExists($pdo, 'promoter_requests', 'type_entite', "ENUM('physique', 'morale') NOT NULL DEFAULT 'physique' AFTER user_id");
addColumnIfNotExists($pdo, 'promoter_requests', 'raison_sociale', "VARCHAR(180) NULL AFTER type_entite");
addColumnIfNotExists($pdo, 'promoter_requests', 'numero_registre', "VARCHAR(100) NULL AFTER raison_sociale");
addColumnIfNotExists($pdo, 'promoter_requests', 'representant_legal', "VARCHAR(150) NULL AFTER numero_registre");
addColumnIfNotExists($pdo, 'promoter_requests', 'piece_entreprise', "VARCHAR(255) NULL AFTER piece_identite");
addColumnIfNotExists($pdo, 'promoter_requests', 'ville', "VARCHAR(100) NULL AFTER telephone");
addColumnIfNotExists($pdo, 'promoter_requests', 'volume_estime', "VARCHAR(100) NULL AFTER experience");

// 2. Enrichissement de promoters
addColumnIfNotExists($pdo, 'promoters', 'type_entite', "ENUM('physique', 'morale') NOT NULL DEFAULT 'physique' AFTER user_id");
addColumnIfNotExists($pdo, 'promoters', 'numero_registre', "VARCHAR(100) NULL AFTER nom_commercial");
addColumnIfNotExists($pdo, 'promoters', 'representant_legal', "VARCHAR(150) NULL AFTER numero_registre");
addColumnIfNotExists($pdo, 'promoters', 'ville', "VARCHAR(100) NULL AFTER adresse");

echo "\nMigration terminée avec succès !\n";
