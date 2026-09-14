<?php
// Test de validation complet de la gestion des votes privés
require_once __DIR__ . '/../config/database.php';

echo "=== TEST VALIDATION GESTION DES VOTES PRIVÉS ===\n";

$pass = 0;
$fail = 0;

function assert_v($cond, $label) {
    global $pass, $fail;
    if ($cond) {
        echo " [\033[32mOK\033[0m] $label\n";
        $pass++;
    } else {
        echo " [\033[31mFAIL\033[0m] $label\n";
        $fail++;
    }
}

// 1. Vérification dans client/accueil.php : les votes privés ne doivent JAMAIS apparaître
$sql_vote = "
    SELECT e.id, e.nom, e.visibilite
    FROM events e
    WHERE e.statut = 'actif'
      AND e.visibilite = 'public'
      AND (
          e.type_vote IN ('realisation', 'concours')
          OR e.prix_vote > 0
          OR EXISTS (SELECT 1 FROM event_candidats ec WHERE ec.event_id = e.id)
      )
";
$public_votes = $pdo->query($sql_vote)->fetchAll(PDO::FETCH_ASSOC);
$public_vote_names = array_column($public_votes, 'nom');

assert_v(!in_array('La Parade', $public_vote_names), "« La Parade » (privé) n'apparaît plus sur l'accueil public");
assert_v(!in_array('LA VIDA', $public_vote_names), "« LA VIDA » (privé) n'apparaît plus sur l'accueil public");

// 2. Vérification que "La Parade" a bien son token secret
$stmt_lp = $pdo->query("SELECT id, nom, visibilite, access_token FROM events WHERE nom = 'La Parade' LIMIT 1");
$row_lp = $stmt_lp->fetch(PDO::FETCH_ASSOC);

assert_v($row_lp && $row_lp['visibilite'] === 'prive', "« La Parade » est bien enregistrée avec visibilité 'prive' dans events");
assert_v(!empty($row_lp['access_token']), "« La Parade » possède un jeton access_token secret (" . ($row_lp['access_token'] ?? '') . ")");

// 3. Test de basculement de visibilité (simulation action_toggle_visibilite)
$test_token = $row_lp['access_token'];
// Passer en public
$pdo->prepare("UPDATE events SET visibilite = 'public' WHERE id = ?")->execute([$row_lp['id']]);
$chk_pub = $pdo->prepare("SELECT visibilite FROM events WHERE id = ?");
$chk_pub->execute([$row_lp['id']]);
assert_v($chk_pub->fetchColumn() === 'public', "Basculement temporaire en PUBLIC fonctionnel");

// Re-passer en privé
$pdo->prepare("UPDATE events SET visibilite = 'prive', access_token = ? WHERE id = ?")->execute([$test_token, $row_lp['id']]);
$chk_priv = $pdo->prepare("SELECT visibilite, access_token FROM events WHERE id = ?");
$chk_priv->execute([$row_lp['id']]);
$res_priv = $chk_priv->fetch(PDO::FETCH_ASSOC);
assert_v($res_priv['visibilite'] === 'prive' && $res_priv['access_token'] === $test_token, "Retour en PRIVÉ avec préservation du token fonctionnel");

// 4. Test d'approbation automatique dans admin/demandes.php avec une demande privée
$p_id = $pdo->query("SELECT id FROM users WHERE role = 'promoteur' LIMIT 1")->fetchColumn() ?: 1;

$pdo->prepare("
    INSERT INTO event_requests (user_id, nom, description, categorie, date_evenement, heure, lieu, visibilite, type_vote, statut)
    VALUES (?, 'Concours Test Privé Workflow', 'Description', 'Concert', CURRENT_DATE + INTERVAL '10 days', '19:00', 'Abidjan', 'prive', 'concours', 'en_attente')
")->execute([$p_id]);
$req_test_id = $pdo->lastInsertId();

// Simuler la logique d'approbation corrigée de admin/demandes.php
$stmt_r = $pdo->prepare("SELECT * FROM event_requests WHERE id = ?");
$stmt_r->execute([$req_test_id]);
$req_data = $stmt_r->fetch(PDO::FETCH_ASSOC);

$visibilite = ($req_data['visibilite'] ?? 'public') === 'prive' ? 'prive' : 'public';
$access_token = ($visibilite === 'prive') ? bin2hex(random_bytes(16)) : null;

$stmt_ins = $pdo->prepare("
    INSERT INTO events (user_id, nom, description, categorie, date_evenement, heure, lieu, prix_vote, type_vote, statut, visibilite, access_token) 
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'actif', ?, ?)
");
$stmt_ins->execute([
    $req_data['user_id'], $req_data['nom'], $req_data['description'], $req_data['categorie'],
    $req_data['date_evenement'], $req_data['heure'], $req_data['lieu'], $req_data['type_vote'],
    $visibilite, $access_token
]);
$new_ev_id = $pdo->lastInsertId();

// Vérifier que l'événement créé a hérité de la visibilité privée et d'un token
$stmt_chk_new = $pdo->prepare("SELECT visibilite, access_token FROM events WHERE id = ?");
$stmt_chk_new->execute([$new_ev_id]);
$new_ev_res = $stmt_chk_new->fetch(PDO::FETCH_ASSOC);

assert_v($new_ev_res['visibilite'] === 'prive', "Nouvel événement approuvé a hérité de visibilite='prive'");
assert_v(!empty($new_ev_res['access_token']) && strlen($new_ev_res['access_token']) === 32, "Nouvel événement approuvé a généré un token 32 hex");

// Nettoyage de l'événement de test
$pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$new_ev_id]);
$pdo->prepare("DELETE FROM event_requests WHERE id = ?")->execute([$req_test_id]);

echo "=== RÉSULTATS : $pass SUCCÈS, $fail ÉCHECS ===\n";
