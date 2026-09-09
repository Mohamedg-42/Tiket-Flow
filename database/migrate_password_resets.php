<?php
// ==============================================================================
// MIGRATION : CRÉATION DE LA TABLE PASSWORD_RESETS POUR POSTGRESQL
// ==============================================================================

require_once __DIR__ . '/../config/database.php';

try {
    $sql = '
    CREATE TABLE IF NOT EXISTS "password_resets" (
        "id" SERIAL PRIMARY KEY,
        "email" VARCHAR(150) NOT NULL,
        "token" VARCHAR(255) NOT NULL UNIQUE,
        "created_at" TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
        "expires_at" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
        "used" SMALLINT NOT NULL DEFAULT 0
    );

    CREATE INDEX IF NOT EXISTS "idx_password_resets_token" ON "password_resets" ("token");
    CREATE INDEX IF NOT EXISTS "idx_password_resets_email" ON "password_resets" ("email");
    CREATE INDEX IF NOT EXISTS "idx_password_resets_validity" ON "password_resets" ("email", "used", "expires_at");
    ';

    $pdo->exec($sql);
    echo "✓ Table password_resets créée avec succès dans PostgreSQL !\n";
} catch (PDOException $e) {
    echo "❌ Erreur : " . $e->getMessage() . "\n";
    exit(1);
}
