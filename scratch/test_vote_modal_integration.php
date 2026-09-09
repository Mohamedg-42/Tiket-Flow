<?php
// Test d'intégration complet pour le carrousel horizontal des votes et modales de détails

$base = "http://localhost/ticket-platform/client/";

echo "=== Test 1: accueil.php?onglet=voter ===\n";
$html_accueil_voter = file_get_contents($base . "accueil.php?onglet=voter");
assert_str($html_accueil_voter, 'vote-cands-horizontal-track', "CSS / DOM track horizontal présent dans accueil.php");
assert_str($html_accueil_voter, 'openVoteDetailsModal', "Fonction openVoteDetailsModal déclenchée sur les cartes de vote");
assert_str($html_accueil_voter, 'id="voteDetailsModal"', "Modale #voteDetailsModal présente");
assert_str($html_accueil_voter, 'id="candidatDetailsModal"', "Modale détails candidat présente");
assert_str($html_accueil_voter, 'scrollVoteCands', "Boutons de défilement horizontal présents");
assert_str($html_accueil_voter, 'accueil-client.css?v=', "Cache-busting présent sur CSS");

echo "\n=== Test 2: accueil.php (Événements avec candidats) ===\n";
$html_accueil_events = file_get_contents($base . "accueil.php?onglet=evenements");
assert_str($html_accueil_events, 'openVoteDetailsModal', "Clic direct sur Progression des candidats ouvre la modale sans rechargement");

echo "\n=== Test 3: evenement.php?id=1 ===\n";
$html_ev = file_get_contents($base . "evenement.php?id=1");
assert_str($html_ev, 'vote-cands-horizontal-track', "Track horizontal présent dans evenement.php");
assert_str($html_ev, 'scrollEvCands', "Boutons défilement horizontal dans evenement.php");
assert_str($html_ev, 'openEvCandModal', "Bouton Détails candidat avec modale dans evenement.php");
assert_str($html_ev, 'id="evCandidateDetailModal"', "Modale détails candidat présente dans evenement.php");
assert_str($html_ev, 'accueil-client.css?v=', "Cache-busting sur CSS dans evenement.php");

echo "\n=== Test 4: vote.php?id=1 ===\n";
$html_vote = file_get_contents($base . "vote.php?id=1");
assert_str($html_vote, 'vote-cands-horizontal-track', "Track horizontal présent dans vote.php");
assert_str($html_vote, 'scrollPageCands', "Défilement horizontal dans vote.php");
assert_str($html_vote, 'openCandidateDetailModal', "Fonction openCandidateDetailModal dans vote.php");
assert_str($html_vote, 'id="candPageDetailModal"', "Modale détails candidat présente dans vote.php");
assert_str($html_vote, 'btn-cand-vote', "Bouton Voter pour elle présent dans vote.php");
assert_str($html_vote, 'accueil-client.css?v=', "Cache-busting sur CSS dans vote.php");

echo "\n============================================\n";
echo "TOUS LES TESTS D'INTÉGRATION SONT VALIDÉS !\n";
echo "============================================\n";

function assert_str($content, $needle, $label) {
    if (strpos($content, $needle) !== false) {
        echo "[PASS] $label\n";
    } else {
        echo "[FAIL] $label (chaîne non trouvée : '$needle')\n";
        exit(1);
    }
}
