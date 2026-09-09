<?php
// ==============================================================================
// MIGRATION : AJOUT DU TAUX DE COMMISSION PERSONNALISÉ POUR LES PROMOTEURS
// (database/migrate_promoter_commission_rate.php)
// ==============================================================================

require_once __DIR__ . '/../config/database.php';

echo "=== MIGRATION : AJOUT DE COMMISSION_RATE DANS PROMOTERS ===\n";

try {
    // Vérifier si la colonne existe déjà
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'promoters' AND column_name = 'commission_rate'
    ");
    
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE promoters ADD COLUMN commission_rate NUMERIC(5,2) NOT NULL DEFAULT 5.00");
        echo "✅ [AJOUTÉ] Colonne 'commission_rate' (NUMERIC(5,2) DEFAULT 5.00) ajoutée avec succès à la table 'promoters'.\n";
    } else {
        echo "ℹ️ [INFO] La colonne 'commission_rate' existe déjà dans 'promoters'.\n";
    }

    // Vérifier également dans promoter_requests
    $stmt_req = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'promoter_requests' AND column_name = 'commission_rate'
    ");
    if ($stmt_req->rowCount() === 0) {
        $pdo->exec("ALTER TABLE promoter_requests ADD COLUMN commission_rate NUMERIC(5,2) NULL DEFAULT 5.00");
        echo "✅ [AJOUTÉ] Colonne 'commission_rate' ajoutée à 'promoter_requests'.\n";
    }

    echo "\n🎉 MIGRATION TERMINÉE AVEC SUCCÈS !\n";
} catch (Exception $e) {
    echo "❌ [ERREUR] " . $e->getMessage() . "\n";
}
