<?php
// ==============================================================================
// MIGRATION — Visibilité événements, liste d'invités (whitelist) + OTP,
// et module Gares Routières / Guichets physiques
// Exécutable en CLI : php migrate_visibilite_whitelist_gares.php
// Idempotent (safe à ré-exécuter) — compatible PostgreSQL uniquement
// ==============================================================================

require_once __DIR__ . '/../config/database.php';

echo "🚀 Migration — Visibilité, Whitelist, Gares Routières...\n";

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
    // ==========================================================================
    // 1. Visibilité des événements (public / privé)
    // ==========================================================================
    echo "1. Visibilité des événements...\n";
    addColumnIfNotExists($pdo, 'events', 'visibilite', "VARCHAR(20) NOT NULL DEFAULT 'public'");
    addColumnIfNotExists($pdo, 'events', 'access_token', "VARCHAR(64) NULL");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_events_access_token ON events(access_token) WHERE access_token IS NOT NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_visibilite ON events(visibilite)");

    // ==========================================================================
    // 2. Liste d'invités autorisés (whitelist) par événement privé
    // ==========================================================================
    echo "2. Table event_guest_whitelist...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS event_guest_whitelist (
            id SERIAL PRIMARY KEY,
            event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
            nom VARCHAR(100) NOT NULL,
            prenom VARCHAR(100) NULL,
            telephone VARCHAR(30) NOT NULL,
            email VARCHAR(150) NULL,
            tickets_autorises INTEGER NOT NULL DEFAULT 1,
            tickets_utilises INTEGER NOT NULL DEFAULT 0,
            ajoute_par INTEGER NULL REFERENCES users(id),
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT uq_whitelist_event_tel UNIQUE (event_id, telephone)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_whitelist_event ON event_guest_whitelist(event_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_whitelist_tel ON event_guest_whitelist(telephone)");
    echo "  ✓ OK\n";

    // ==========================================================================
    // 3. Vérifications OTP (SMS)
    // ==========================================================================
    echo "3. Table otp_verifications...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS otp_verifications (
            id SERIAL PRIMARY KEY,
            telephone VARCHAR(30) NOT NULL,
            event_id INTEGER NULL REFERENCES events(id) ON DELETE CASCADE,
            code VARCHAR(10) NOT NULL,
            purpose VARCHAR(40) NOT NULL DEFAULT 'whitelist_achat',
            attempts INTEGER NOT NULL DEFAULT 0,
            used SMALLINT NOT NULL DEFAULT 0,
            expires_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_otp_telephone ON otp_verifications(telephone)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_otp_event ON otp_verifications(event_id)");
    echo "  ✓ OK\n";

    // ==========================================================================
    // 4. Gares routières (stations / points de vente physiques)
    // ==========================================================================
    echo "4. Table stations...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stations (
            id SERIAL PRIMARY KEY,
            nom VARCHAR(150) NOT NULL,
            ville VARCHAR(100) NULL,
            adresse VARCHAR(255) NULL,
            responsable_nom VARCHAR(150) NULL,
            responsable_telephone VARCHAR(30) NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stations_statut ON stations(statut)");
    echo "  ✓ OK (statut: active | suspendue | stoppee)\n";

    // ==========================================================================
    // 5. Rattachement des agents de guichet à une gare
    // ==========================================================================
    echo "5. users.station_id...\n";
    addColumnIfNotExists($pdo, 'users', 'station_id', "INTEGER NULL REFERENCES stations(id)");

    // ==========================================================================
    // 6. Distinction des ventes web / guichet sur les tickets émis
    // ==========================================================================
    echo "6. tickets.canal / tickets.station_id / tickets.cloture_id...\n";
    addColumnIfNotExists($pdo, 'tickets', 'canal', "VARCHAR(20) NOT NULL DEFAULT 'web'");
    addColumnIfNotExists($pdo, 'tickets', 'station_id', "INTEGER NULL REFERENCES stations(id)");
    addColumnIfNotExists($pdo, 'tickets', 'cloture_id', "INTEGER NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_canal ON tickets(canal)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_station ON tickets(station_id)");

    // ==========================================================================
    // 7. Clôtures de caisse des guichets
    // ==========================================================================
    echo "7. Table station_cash_closures...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS station_cash_closures (
            id SERIAL PRIMARY KEY,
            station_id INTEGER NOT NULL REFERENCES stations(id),
            agent_user_id INTEGER NULL REFERENCES users(id),
            periode_debut TIMESTAMP WITHOUT TIME ZONE NOT NULL,
            periode_fin TIMESTAMP WITHOUT TIME ZONE NOT NULL,
            nombre_tickets INTEGER NOT NULL DEFAULT 0,
            montant_total NUMERIC(12,2) NOT NULL DEFAULT 0,
            statut VARCHAR(20) NOT NULL DEFAULT 'cloturee',
            notes TEXT NULL,
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_closures_station ON station_cash_closures(station_id)");
    echo "  ✓ OK\n";

    // ==========================================================================
    // 8. Autoriser la méthode de paiement "espèces guichet" si payments.methode
    //    a une contrainte CHECK (vérification défensive — no-op si absente)
    // ==========================================================================
    echo "8. Vérification des contraintes sur payments.methode...\n";
    $chk = $pdo->query("
        SELECT conname, pg_get_constraintdef(oid) AS def
        FROM pg_constraint
        WHERE conrelid = 'payments'::regclass AND contype = 'c'
    ")->fetchAll();
    foreach ($chk as $c) {
        if (stripos($c['def'], 'methode') !== false) {
            echo "  ⚠ Contrainte CHECK détectée sur payments.methode : {$c['conname']} — " . $c['def'] . "\n";
            echo "    Si 'especes_guichet' est rejeté, adaptez cette contrainte manuellement.\n";
        }
    }
    echo "  ✓ OK (aucune contrainte bloquante connue par défaut)\n";

    echo "\n🎉 MIGRATION TERMINÉE AVEC SUCCÈS !\n";
} catch (PDOException $e) {
    echo "❌ Erreur : " . $e->getMessage() . "\n";
    exit(1);
}
