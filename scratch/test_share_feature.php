<?php
// Test d'intégration des boutons de partage sur les affiches et dans les modales

function assert_check($condition, $label) {
    if ($condition) {
        echo "[PASS] $label\n";
    } else {
        echo "[FAIL] $label\n";
        exit(1);
    }
}

echo "=== Test 1: Vérification de js/accueil-client.js ===\n";
$js = file_get_contents(__DIR__ . '/../js/accueil-client.js');
assert_check(strpos($js, 'function openShareVote(') !== false, "function openShareVote déclarée");
assert_check(strpos($js, 'function openShareEvent(') !== false, "function openShareEvent déclarée");
assert_check(strpos($js, 'function openShareCotisation(') !== false, "function openShareCotisation déclarée");
assert_check(strpos($js, 'function openShareVoteFromModal(') !== false, "function openShareVoteFromModal déclarée");
assert_check(strpos($js, 'function openShareEventFromModal(') !== false, "function openShareEventFromModal déclarée");
assert_check(strpos($js, 'window.openShareVote = openShareVote') !== false, "window.openShareVote exposé");
assert_check(strpos($js, 'window.openShareEvent = openShareEvent') !== false, "window.openShareEvent exposé");

echo "\n=== Test 2: Boutons de partage flottants sur les affiches (client/accueil.php) ===\n";
$html_accueil = file_get_contents(__DIR__ . '/../client/accueil.php');
assert_check(strpos($html_accueil, '.poster-floating-share-btn') !== false, "CSS .poster-floating-share-btn présent");
assert_check(strpos($html_accueil, 'openShareVote') !== false, "openShareVote branché dans accueil.php");
assert_check(strpos($html_accueil, 'openShareEvent') !== false, "openShareEvent branché dans accueil.php");
assert_check(strpos($html_accueil, 'openShareCotisation') !== false, "openShareCotisation branché dans accueil.php");
assert_check(strpos($html_accueil, 'btnVoteModalShare') !== false, "Bouton de partage dans #voteDetailsModal");

echo "\n=== Test 3: Bouton de partage sur l'affiche de l'événement (client/evenement.php) ===\n";
$html_ev = file_get_contents(__DIR__ . '/../client/evenement.php');
assert_check(strpos($html_ev, '.poster-floating-share-btn') !== false, "CSS .poster-floating-share-btn dans evenement.php");
assert_check(strpos($html_ev, 'onclick="openShareEventModal()"') !== false, "Bouton partage présent sur l'affiche dans evenement.php");

echo "\n=== Test 4: Bouton de partage sur l'affiche du vote (client/vote.php) ===\n";
$html_vote = file_get_contents(__DIR__ . '/../client/vote.php');
assert_check(strpos($html_vote, '.poster-floating-share-btn') !== false, "CSS .poster-floating-share-btn dans vote.php");
assert_check(strpos($html_vote, 'onclick="openShareVotePage(') !== false, "Bouton partage présent sur l'affiche dans vote.php");

echo "\n============================================\n";
echo "TOUS LES TESTS DE PARTAGE SONT VALIDÉS !\n";
echo "============================================\n";
