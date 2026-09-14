<?php
// ==============================================================================
// MIGRATION ÉLIGIBILITÉ PROMOTEURS (Physique / Morale) — Version PostgreSQL
// (portée depuis l'ancienne version MySQL, incompatible avec la base de
// production). Idempotent — safe à ré-exécuter à tout moment.
// ==============================================================================

require_once __DIR__ . '/../config/database.php';

echo "=== MIGRATION ÉLIGIBILITÉ PROMOTEURS (Physique / Morale) — PostgreSQL ===\n";

function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
        $stmt->execute([$table, $column]);
        if (!$stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE \"$table\" ADD COLUMN \"$column\" $definition");
            echo "[AJOUTÉ] $table.$column\n";
        } else {
            echo "[EXISTE] $table.$column\n";
        }
    } catch (PDOException $e) {
        echo "[ERREUR] $table.$column : " . $e->getMessage() . "\n";
    }
}

// 0. Statut 'en_attente' sur users : `statut` est un simple VARCHAR côté PostgreSQL
// (pas un ENUM SQL comme en MySQL), donc aucune contrainte à modifier — n'importe
// quelle valeur texte, dont 'en_attente', y est déjà acceptée nativement.
echo "[INFO] users.statut est VARCHAR (PostgreSQL) : 'en_attente' est déjà une valeur valide, aucune modification requise.\n";

// 1. Enrichissement de promoter_requests
addColumnIfNotExists($pdo, 'promoter_requests', 'type_entite', "VARCHAR(50) NOT NULL DEFAULT 'physique'");
addColumnIfNotExists($pdo, 'promoter_requests', 'raison_sociale', "VARCHAR(180) NULL");
addColumnIfNotExists($pdo, 'promoter_requests', 'numero_registre', "VARCHAR(100) NULL");
addColumnIfNotExists($pdo, 'promoter_requests', 'representant_legal', "VARCHAR(150) NULL");
addColumnIfNotExists($pdo, 'promoter_requests', 'piece_entreprise', "VARCHAR(255) NULL");
addColumnIfNotExists($pdo, 'promoter_requests', 'ville', "VARCHAR(100) NULL");
addColumnIfNotExists($pdo, 'promoter_requests', 'volume_estime', "VARCHAR(100) NULL");

// 2. Enrichissement de promoters
addColumnIfNotExists($pdo, 'promoters', 'type_entite', "VARCHAR(50) NOT NULL DEFAULT 'physique'");
addColumnIfNotExists($pdo, 'promoters', 'numero_registre', "VARCHAR(100) NULL");
addColumnIfNotExists($pdo, 'promoters', 'representant_legal', "VARCHAR(150) NULL");
addColumnIfNotExists($pdo, 'promoters', 'ville', "VARCHAR(100) NULL");

echo "\nMigration terminée avec succès !\n";
