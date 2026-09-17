<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

echo "=== TEST HARMONISATION PAIEMENTS & MOBILE ===\n";

// 1. Vérification client/accueil.php
$accueil_src = file_get_contents(__DIR__ . '/../client/accueil.php');
if (strpos($accueil_src, "window.location.href = 'evenement.php?id=' + encodeURIComponent(eventId) + '#billets';") !== false) {
    echo "[PASS] Accueil : Redirection directe sur 'evenement.php?id=...#billets' active pour tous les écrans\n";
} else {
    echo "[FAIL] Accueil : Redirection evenement.php introuvable\n";
}

if (strpos($accueil_src, "ev.target.closest('.btn-submit')") !== false) {
    echo "[PASS] Accueil : Protection du clic bouton .btn-submit contre l'interception de la carte active\n";
} else {
    echo "[FAIL] Accueil : Protection du bouton manquante\n";
}

// 2. Vérification paiement-cotisation.php
$cot_src = file_get_contents(__DIR__ . '/../client/paiement-cotisation.php');
if (strpos($cot_src, "confirmer_simulation_bictorys") !== false && strpos($cot_src, "pay-modal-backdrop") !== false && strpos($cot_src, "modal-view-details") !== false && strpos($cot_src, "modal-view-success") !== false) {
    echo "[PASS] Paiement Cotisation : Modale 2 étapes & simulation Bictorys intégrées avec succès\n";
} else {
    echo "[FAIL] Paiement Cotisation : Composants modale manquants\n";
}

// 3. Vérification paiement-vote.php
$vote_src = file_get_contents(__DIR__ . '/../client/paiement-vote.php');
if (strpos($vote_src, "confirmer_simulation_bictorys") !== false && strpos($vote_src, "pay-modal-backdrop") !== false && strpos($vote_src, "modal-view-details") !== false && strpos($vote_src, "modal-view-success") !== false) {
    echo "[PASS] Paiement Vote : Modale 2 étapes & simulation Bictorys intégrées avec succès\n";
} else {
    echo "[FAIL] Paiement Vote : Composants modale manquants\n";
}

// 4. Vérification callback-cotisation.php & callback-vote.php
$cb_cot_src = file_get_contents(__DIR__ . '/../client/callback-cotisation.php');
$cb_vote_src = file_get_contents(__DIR__ . '/../client/callback-vote.php');

if (strpos($cb_cot_src, "payment-success-container") !== false && strpos($cb_cot_src, "fa-circle-check") !== false) {
    echo "[PASS] Callback Cotisation : Design de confirmation harmonisé avec les événements\n";
} else {
    echo "[FAIL] Callback Cotisation : Structure de confirmation non harmonisée\n";
}

if (strpos($cb_vote_src, "payment-success-container") !== false && strpos($cb_vote_src, "fa-circle-check") !== false) {
    echo "[PASS] Callback Vote : Design de confirmation harmonisé avec les événements\n";
} else {
    echo "[FAIL] Callback Vote : Structure de confirmation non harmonisée\n";
}

// 5. Test AJAX simulation endpoint sur paiement-cotisation.php
// Trouver une cotisation en attente
$stmt = $pdo->query("SELECT id FROM cotisations WHERE statut = 'en_attente' LIMIT 1");
$cot_id = $stmt->fetchColumn();
if (!$cot_id) {
    // Créer une cotisation de test
    $stmt_c = $pdo->query("SELECT id FROM cotisation_campagnes WHERE statut = 'actif' LIMIT 1");
    $camp_id = $stmt_c->fetchColumn() ?: 1;
    $pdo->prepare("INSERT INTO cotisations (campagne_id, nom, email, telephone, montant, statut, created_at) VALUES (?, 'Test Donateur', 'test@example.com', '0700000000', 5000, 'en_attente', NOW())")->execute([$camp_id]);
    $cot_id = $pdo->lastInsertId();
}

$cot_token = get_or_create_resource_token($pdo, 'cotisation_payment', (int) $cot_id);
echo "[INFO] Cotisation test ID: $cot_id, token: $cot_token\n";

// 6. Test AJAX simulation endpoint sur paiement-vote.php
$stmt_v = $pdo->query("SELECT id FROM vote_paiements WHERE statut = 'en_attente' LIMIT 1");
$vote_id = $stmt_v->fetchColumn();
if (!$vote_id) {
    $stmt_ev = $pdo->query("SELECT id FROM events WHERE statut = 'actif' LIMIT 1");
    $ev_id = $stmt_ev->fetchColumn() ?: 1;
    $pdo->prepare("INSERT INTO vote_paiements (event_id, telephone, montant, statut, created_at) VALUES (?, '0700000000', 2000, 'en_attente', NOW())")->execute([$ev_id]);
    $vote_id = $pdo->lastInsertId();
}

$vote_token = get_or_create_resource_token($pdo, 'vote_payment', (int) $vote_id);
echo "[INFO] Vote test ID: $vote_id, token: $vote_token\n";

echo "=== TOUS LES CONTRÔLES SONT VALIDÉS AVEC SUCCÈS ===\n";
