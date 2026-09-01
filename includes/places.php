<?php
// ==============================================================================
// GÉNÉRATEUR AUTOMATIQUE DE PLACES (includes/places.php)
// Crée les places physiques d'un type de billet dès sa création : la table
// 'places' est toujours synchronisée avec 'ticket_types' (sans doublons).
// Appelé automatiquement lors de la validation d'un événement.
// ==============================================================================

/**
 * Génère les places manquantes d'un type de billet.
 *
 * @param PDO   $pdo     Connexion active (peut être dans une transaction)
 * @param int   $type_id ID du type de billet (ticket_types.id)
 * @param int   $qty     Quantité totale de places attendue
 * @param float $frais   Frais supplémentaires par place choisie (informatif)
 *
 * @return int Nombre de places créées
 */
function generer_places_type(PDO $pdo, int $type_id, int $qty): int
{
    if ($qty <= 0) {
        return 0;
    }

    // Numéros déjà utilisés pour ce type (ex: "Place 3" -> 3) pour éviter les doublons
    $stmt_existing = $pdo->prepare("SELECT numero FROM places WHERE ticket_type_id = ?");
    $stmt_existing->execute([$type_id]);
    $used = [];
    foreach ($stmt_existing->fetchAll() as $row) {
        if (preg_match('/(\d+)\s*$/', $row['numero'], $m)) {
            $used[(int)$m[1]] = true;
        }
    }

    // Insertion uniquement des places manquantes
    $stmt_insert = $pdo->prepare("INSERT INTO places (ticket_type_id, numero, statut) VALUES (?, ?, 'libre')");
    $created = 0;
    for ($i = 1; $i <= $qty; $i++) {
        if (isset($used[$i])) {
            continue;
        }
        $stmt_insert->execute([$type_id, "Place " . $i]);
        $created++;
    }

    return $created;
}
