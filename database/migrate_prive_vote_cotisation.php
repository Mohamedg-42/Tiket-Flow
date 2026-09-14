<?php
// ==============================================================================
// MIGRATION — Visibilité privée des cotisations & Whitelist dédiée
// Exécutable en CLI : php migrate_prive_vote_cotisation.php
// Idempotent (safe à ré-exécuter) — PostgreSQL
// ==============================================================================

require_once __DIR__ . '/../config/database.php';

echo "🚀 Migration — Cotisations Privées & Whitelist...\n";

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $definition): void {
    if (!columnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE \"$table\" ADD COLUMN \"$column\" $definition");
        echo "  + Colonne $table.$column ajoutée.\n";
    } else {
        echo "  = Colonne $table.$column déjà présente.\n";
    }
}

try {
    // 1. Colonnes sur cotisation_campagnes
    echo "1. Colonnes sur cotisation_campagnes...\n";
    addColumnIfNotExists($pdo, 'cotisation_campagnes', 'visibilite', "VARCHAR(20) NOT NULL DEFAULT 'public'");
    addColumnIfNotExists($pdo, 'cotisation_campagnes', 'access_token', "VARCHAR(64) NULL");
    addColumnIfNotExists($pdo, 'cotisation_campagnes', 'event_id', "INTEGER NULL REFERENCES events(id) ON DELETE SET NULL");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cotisation_visibilite ON cotisation_campagnes(visibilite)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cotisation_access_token ON cotisation_campagnes(access_token) WHERE access_token IS NOT NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cotisation_event_id ON cotisation_campagnes(event_id)");

    // 2. Table cotisation_whitelist pour les participants autorisés aux campagnes privées
    echo "2. Table cotisation_whitelist...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cotisation_whitelist (
            id SERIAL PRIMARY KEY,
            campagne_id INTEGER NOT NULL REFERENCES cotisation_campagnes(id) ON DELETE CASCADE,
            nom VARCHAR(100) NOT NULL,
            prenom VARCHAR(100) NULL,
            telephone VARCHAR(30) NOT NULL,
            email VARCHAR(150) NULL,
            montant_promis NUMERIC(12,2) NULL DEFAULT NULL,
            ajoute_par INTEGER NULL REFERENCES users(id),
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT uq_cotisation_wl UNIQUE (campagne_id, telephone)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cotisation_wl_campagne ON cotisation_whitelist(campagne_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cotisation_wl_tel ON cotisation_whitelist(telephone)");
    echo "  ✓ OK\n";

    echo "\n🎉 MIGRATION COTISATIONS PRIVÉES TERMINÉE AVEC SUCCÈS !\n";
} catch (PDOException $e) {
    echo "❌ Erreur : " . $e->getMessage() . "\n";
    exit(1);
}
