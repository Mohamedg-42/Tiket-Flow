<?php
// Test de validation des requêtes de recherche pour les cotisations et les votes
require_once __DIR__ . '/../config/database.php';

echo "=== TEST DES ZONES DE RECHERCHE COTISATIONS ET VOTES ===\n";

$pass = 0;
$fail = 0;

function assert_test($cond, $label) {
    global $pass, $fail;
    if ($cond) {
        echo " [OK] $label\n";
        $pass++;
    } else {
        echo " [FAIL] $label\n";
        $fail++;
    }
}

// 1. Insertion de données de test
$pdo->beginTransaction();

// Cotisation publique A
$stmt = $pdo->prepare("
    INSERT INTO cotisation_campagnes (user_id, titre, description, montant_objectif, statut, visibilite)
    VALUES (1, 'Festival Solidarité Alpha', 'Aide aux artistes', 1000000, 'active', 'public')
    RETURNING id
");
$stmt->execute();
$camp_a = $stmt->fetchColumn();

// Cotisation publique B (terminée)
$stmt = $pdo->prepare("
    INSERT INTO cotisation_campagnes (user_id, titre, description, montant_objectif, statut, visibilite)
    VALUES (1, 'Rénovation Salle Bêta', 'Collecte achevée', 500000, 'terminee', 'public')
    RETURNING id
");
$stmt->execute();
$camp_b = $stmt->fetchColumn();

// Cotisation privée C (ne doit jamais sortir)
$stmt = $pdo->prepare("
    INSERT INTO cotisation_campagnes (user_id, titre, description, montant_objectif, statut, visibilite, access_token)
    VALUES (1, 'Collecte Privée Alpha Secret', 'Confidentiel', 200000, 'active', 'prive', 'secret_token_123')
    RETURNING id
");
$stmt->execute();
$camp_c = $stmt->fetchColumn();

// Test Recherche Cotisation par mot-clé "Alpha"
$q = 'alpha';
$sql_test_camp = "
    SELECT id FROM cotisation_campagnes c
    WHERE c.statut IN ('active', 'terminee')
      AND (c.visibilite IS NULL OR c.visibilite = 'public')
      AND (c.titre ILIKE ? OR c.description ILIKE ?)
";
$stmt_srch = $pdo->prepare($sql_test_camp);
$stmt_srch->execute(["%$q%", "%$q%"]);
$res_camps = $stmt_srch->fetchAll(PDO::FETCH_COLUMN);

assert_test(in_array($camp_a, $res_camps), "Recherche cotisation : trouve la campagne correspondante publique");
assert_test(!in_array($camp_b, $res_camps), "Recherche cotisation : exclut les campagnes non correspondantes");
assert_test(!in_array($camp_c, $res_camps), "Recherche cotisation : n'inclut JAMAIS une campagne privée");

// Test Filtre Cotisation par statut 'terminee'
$sql_statut = "
    SELECT id FROM cotisation_campagnes c
    WHERE c.statut = 'terminee' AND (c.visibilite IS NULL OR c.visibilite = 'public')
";
$res_term = $pdo->query($sql_statut)->fetchAll(PDO::FETCH_COLUMN);
assert_test(in_array($camp_b, $res_term), "Filtre statut 'terminee' inclut la campagne terminée");
assert_test(!in_array($camp_a, $res_term), "Filtre statut 'terminee' exclut la campagne active");

// 2. Événement avec Vote
$stmt_v = $pdo->prepare("
    INSERT INTO events (user_id, categorie, nom, description, date_evenement, heure, lieu, prix_vote, type_vote, statut, visibilite)
    VALUES (1, 'Concert', 'Super Concours Live 2026', 'Événement musical', CURRENT_DATE + INTERVAL '5 days', '19:00:00', 'Palais de la Culture', 500.00, 'concours', 'actif', 'public')
    RETURNING id
");
$stmt_v->execute();
$ev_vote_id = $stmt_v->fetchColumn();

// Candidat pour l'événement
$stmt_cand = $pdo->prepare("
    INSERT INTO event_candidats (event_id, nom, description)
    VALUES (?, 'Awa la Chanteuse', 'Candidate officielle')
    RETURNING id
");
$stmt_cand->execute([$ev_vote_id]);

// Test Recherche Vote par nom d'événement "Super Concours"
$q_v = 'Super Concours';
$sql_test_v = "
    SELECT e.id FROM events e
    WHERE e.statut = 'actif' AND e.visibilite = 'public'
      AND (e.type_vote IN ('realisation', 'concours') OR e.prix_vote > 0 OR EXISTS (SELECT 1 FROM event_candidats ec WHERE ec.event_id = e.id))
      AND (e.nom ILIKE ? OR e.description ILIKE ? OR e.lieu ILIKE ? OR EXISTS (SELECT 1 FROM event_candidats ec WHERE ec.event_id = e.id AND ec.nom ILIKE ?))
";
$stmt_v_srch = $pdo->prepare($sql_test_v);
$stmt_v_srch->execute(["%$q_v%", "%$q_v%", "%$q_v%", "%$q_v%"]);
$res_votes = $stmt_v_srch->fetchAll(PDO::FETCH_COLUMN);
assert_test(in_array($ev_vote_id, $res_votes), "Recherche vote : trouve l'événement par son titre");

// Test Recherche Vote par nom de CANDIDAT "Awa"
$q_cand = 'Awa';
$stmt_v_srch->execute(["%$q_cand%", "%$q_cand%", "%$q_cand%", "%$q_cand%"]);
$res_votes_cand = $stmt_v_srch->fetchAll(PDO::FETCH_COLUMN);
assert_test(in_array($ev_vote_id, $res_votes_cand), "Recherche vote : trouve l'événement via le nom d'un candidat");

// Annulation des insertions de test
$pdo->rollBack();

echo "=== RÉSULTATS : $pass SUCCÈS, $fail ÉCHECS ===\n";
