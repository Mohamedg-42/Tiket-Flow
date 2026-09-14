<?php
// Test de vérification complète des flux privés (votes & cotisations)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/whitelist.php';

echo "=== TEST VALIDATION FLUX PRIVES ===\n";

$pass_count = 0;
$fail_count = 0;

function assert_true($cond, $label) {
    global $pass_count, $fail_count;
    if ($cond) {
        echo " [OK] $label\n";
        $pass_count++;
    } else {
        echo " [FAIL] $label\n";
        $fail_count++;
    }
}

// 1. Création d'une campagne de cotisation privée de test
$test_token = bin2hex(random_bytes(16));
$stmt = $pdo->prepare("
    INSERT INTO cotisation_campagnes (user_id, titre, description, montant_objectif, statut, visibilite, access_token)
    VALUES (1, 'Campagne Privée Test', 'Collecte secrète', 500000, 'active', 'prive', ?)
    RETURNING id
");
$stmt->execute([$test_token]);
$test_camp_id = $stmt->fetchColumn();
echo "Campagne de test créée ID: $test_camp_id, Token: $test_token\n";

// Test 1: Filtrage sur accueil.php (les campagnes privées ne doivent pas apparaître)
$stmt_acc = $pdo->query("
    SELECT c.id FROM cotisation_campagnes c 
    WHERE c.statut = 'active' AND (c.visibilite IS NULL OR c.visibilite = 'public')
");
$camp_ids_accueil = $stmt_acc->fetchAll(PDO::FETCH_COLUMN);
assert_true(!in_array($test_camp_id, $camp_ids_accueil), "Campagne privée invisible sur l'accueil public");

// Test 2: Whitelist cotisation
// Numéro invité autorisé
$tel_invite = "+225 07 01 02 03 04";
$tel_norm = normalizePhone($tel_invite);
$tel_intrus = "+225 05 99 88 77 66";
$tel_intrus_norm = normalizePhone($tel_intrus);

$stmt_w = $pdo->prepare("
    INSERT INTO cotisation_whitelist (campagne_id, nom, prenom, telephone, email)
    VALUES (?, 'Kouassi', 'Test', ?, 'kouassi@test.com')
");
$stmt_w->execute([$test_camp_id, $tel_norm]);

// Vérification de la whitelist via requête
$chk_w = $pdo->prepare("SELECT COUNT(*) FROM cotisation_whitelist WHERE campagne_id = ? AND telephone = ?");
$chk_w->execute([$test_camp_id, $tel_norm]);
assert_true($chk_w->fetchColumn() > 0, "Invité autorisé présent dans cotisation_whitelist");

$chk_w->execute([$test_camp_id, $tel_intrus_norm]);
assert_true($chk_w->fetchColumn() == 0, "Intrus absent de cotisation_whitelist");

// Test 3: Création d'un événement privé avec vote
$event_token = bin2hex(random_bytes(16));
$stmt_ev = $pdo->prepare("
    INSERT INTO events (user_id, categorie, nom, description, date_evenement, heure, lieu, statut, visibilite, access_token)
    VALUES (1, 'Concert', 'Concours Privé Test', 'Scrutin fermé', CURRENT_DATE + INTERVAL '10 days', '20:00:00', 'Abidjan', 'actif', 'prive', ?)
    RETURNING id
");
$stmt_ev->execute([$event_token]);
$test_event_id = $stmt_ev->fetchColumn();
echo "Événement de test créé ID: $test_event_id, Token: $event_token\n";

// Candidat de test
$stmt_cand = $pdo->prepare("
    INSERT INTO event_candidats (event_id, nom, description)
    VALUES (?, 'Candidat A', 'Test Candidate')
    RETURNING id
");
$stmt_cand->execute([$test_event_id]);
$cand_id = $stmt_cand->fetchColumn();

// Test 4: Invisibilité sur l'accueil public pour les votes et événements
$stmt_ev_acc = $pdo->query("
    SELECT e.id FROM events e 
    WHERE e.statut = 'actif' AND e.visibilite = 'public'
");
$events_accueil = $stmt_ev_acc->fetchAll(PDO::FETCH_COLUMN);
assert_true(!in_array($test_event_id, $events_accueil), "Événement privé invisible sur le catalogue public");

// Test 5: Whitelist pour l'événement privé
$stmt_ev_w = $pdo->prepare("
    INSERT INTO event_guest_whitelist (event_id, nom, telephone, email)
    VALUES (?, 'Invité Concours', ?, 'invite@concours.com')
");
$stmt_ev_w->execute([$test_event_id, $tel_norm]);

$chk_ev_w = $pdo->prepare("SELECT COUNT(*) FROM event_guest_whitelist WHERE event_id = ? AND telephone = ?");
$chk_ev_w->execute([$test_event_id, $tel_norm]);
assert_true($chk_ev_w->fetchColumn() > 0, "Invité autorisé présent dans event_guest_whitelist");

$chk_ev_w->execute([$test_event_id, $tel_intrus_norm]);
assert_true($chk_ev_w->fetchColumn() == 0, "Intrus absent de event_guest_whitelist");

// Test 6: Simulation du contrôle logique de vote-event.php sur un scrutin privé
function simulate_vote_check($pdo, $event_id, $provided_token, $telephone) {
    $stmt_e = $pdo->prepare("SELECT id, statut, visibilite, access_token FROM events WHERE id = ?");
    $stmt_e->execute([$event_id]);
    $ev = $stmt_e->fetch(PDO::FETCH_ASSOC);
    if (!$ev) return ['status' => 404, 'msg' => 'Introuvable'];
    
    if (($ev['visibilite'] ?? 'public') === 'prive') {
        $exp_token = (string)($ev['access_token'] ?? '');
        if ($exp_token === '' || !hash_equals($exp_token, (string)$provided_token)) {
            return ['status' => 403, 'msg' => 'Accès restreint : jeton invalide'];
        }
        $tel_clean = normalizePhone($telephone);
        if (empty($tel_clean)) {
            return ['status' => 400, 'msg' => 'Téléphone requis'];
        }
        $chk_w = $pdo->prepare("SELECT id FROM event_guest_whitelist WHERE event_id = ? AND telephone = ?");
        $chk_w->execute([$event_id, $tel_clean]);
        if (!$chk_w->fetch()) {
            return ['status' => 403, 'msg' => 'Numéro non autorisé'];
        }
    }
    return ['status' => 200, 'msg' => 'Autorisé'];
}

$res_vote_no_token = simulate_vote_check($pdo, $test_event_id, '', $tel_norm);
assert_true($res_vote_no_token['status'] === 403, "Vote privé rejeté sans token");

$res_vote_bad_token = simulate_vote_check($pdo, $test_event_id, 'wrong_token', $tel_norm);
assert_true($res_vote_bad_token['status'] === 403, "Vote privé rejeté avec mauvais token");

$res_vote_not_whitelisted = simulate_vote_check($pdo, $test_event_id, $event_token, $tel_intrus);
assert_true($res_vote_not_whitelisted['status'] === 403, "Vote privé rejeté pour numéro non whitelisté");

$res_vote_whitelisted = simulate_vote_check($pdo, $test_event_id, $event_token, $tel_invite);
assert_true($res_vote_whitelisted['status'] === 200, "Vote privé accepté pour invité whitelisté avec bon token");

// Test 7: Simulation du contrôle logique de cotisation.php sur une campagne privée
function simulate_cotisation_check($pdo, $camp_id, $provided_token, $telephone) {
    $stmt_c = $pdo->prepare("SELECT id, visibilite, access_token, event_id FROM cotisation_campagnes WHERE id = ?");
    $stmt_c->execute([$camp_id]);
    $camp = $stmt_c->fetch(PDO::FETCH_ASSOC);
    if (!$camp) return ['status' => 404, 'msg' => 'Introuvable'];

    if (($camp['visibilite'] ?? 'public') === 'prive') {
        $exp_token = (string)($camp['access_token'] ?? '');
        if ($exp_token === '' || !hash_equals($exp_token, (string)$provided_token)) {
            return ['status' => 403, 'msg' => 'Accès restreint : jeton invalide'];
        }
        $tel_clean = normalizePhone($telephone);
        if (empty($tel_clean)) {
            return ['status' => 400, 'msg' => 'Téléphone requis'];
        }
        $stmt_w = $pdo->prepare("SELECT id FROM cotisation_whitelist WHERE campagne_id = ? AND telephone = ?");
        $stmt_w->execute([$camp_id, $tel_clean]);
        $ok = (bool)$stmt_w->fetch();
        if (!$ok && !empty($camp['event_id'])) {
            $stmt_we = $pdo->prepare("SELECT id FROM event_guest_whitelist WHERE event_id = ? AND telephone = ?");
            $stmt_we->execute([$camp['event_id'], $tel_clean]);
            $ok = (bool)$stmt_we->fetch();
        }
        if (!$ok) {
            return ['status' => 403, 'msg' => 'Numéro non autorisé'];
        }
    }
    return ['status' => 200, 'msg' => 'Autorisé'];
}

$res_cotis_no_token = simulate_cotisation_check($pdo, $test_camp_id, '', $tel_norm);
assert_true($res_cotis_no_token['status'] === 403, "Cotisation privée rejetée sans token");

$res_cotis_not_whitelisted = simulate_cotisation_check($pdo, $test_camp_id, $test_token, $tel_intrus);
assert_true($res_cotis_not_whitelisted['status'] === 403, "Cotisation privée rejetée pour numéro non whitelisté");

$res_cotis_whitelisted = simulate_cotisation_check($pdo, $test_camp_id, $test_token, $tel_invite);
assert_true($res_cotis_whitelisted['status'] === 200, "Cotisation privée acceptée pour invité whitelisté avec bon token");

// Nettoyage des données de test
$pdo->prepare("DELETE FROM event_guest_whitelist WHERE event_id = ?")->execute([$test_event_id]);
$pdo->prepare("DELETE FROM event_candidats WHERE event_id = ?")->execute([$test_event_id]);
$pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$test_event_id]);
$pdo->prepare("DELETE FROM cotisation_whitelist WHERE campagne_id = ?")->execute([$test_camp_id]);
$pdo->prepare("DELETE FROM cotisation_campagnes WHERE id = ?")->execute([$test_camp_id]);

echo "=== RÉSULTATS : $pass_count SUCCÈS, $fail_count ÉCHECS ===\n";
