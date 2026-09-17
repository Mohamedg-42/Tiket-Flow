<?php
chdir(__DIR__ . '/../client');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure_token.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$event_id = 1;
$candidat_ids = [1];
$prix_vote = 1000;
$user_id = null;
$visitor_id = 'test_session_id';
$verified_tel_clean = null;

$nb_choix      = max(1, count($candidat_ids));
$montant_total = $prix_vote * $nb_choix;
$primary_cand  = !empty($candidat_ids) ? $candidat_ids[0] : null;
$cands_json    = !empty($candidat_ids) ? json_encode($candidat_ids) : null;

$reference = 'VOTE-' . strtoupper(substr(uniqid(), -6));

try {
    $stmt_ins = $pdo->prepare("
        INSERT INTO vote_paiements (event_id, candidat_id, candidats_ids, user_id, visitor_id, telephone, montant, methode, reference, statut)
        VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, 'en_attente')
    ");
    $stmt_ins->execute([
        $event_id,
        $primary_cand,
        $cands_json,
        $user_id,
        $visitor_id,
        $verified_tel_clean,
        $montant_total,
        $reference
    ]);
    
    $inserted_vote_id = (int) $pdo->lastInsertId();
    echo "Inserted Vote ID: $inserted_vote_id\n";
    
    $vote_token = get_or_create_resource_token($pdo, 'vote_payment', $inserted_vote_id);
    echo "Vote Token: $vote_token\n";
    
    $redir = 'paiement-vote.php?token=' . urlencode($vote_token);
    echo "Redirect: $redir\n";
    
} catch (\Throwable $e) {
    echo "Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
