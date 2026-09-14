<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/whitelist.php';

echo "=== TEST MODIFICATION DES INVITÉS (WHITELIST) ===\n";

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

$pdo->beginTransaction();

// 1. Événement de test
$stmt_ev = $pdo->prepare("
    INSERT INTO events (user_id, categorie, nom, description, date_evenement, heure, lieu, statut, visibilite, access_token)
    VALUES (1, 'Gala', 'Gala Privé Test Edit', 'Description', CURRENT_DATE + INTERVAL '5 days', '20:00:00', 'Abidjan', 'actif', 'prive', 'tok_edit_test')
    RETURNING id
");
$stmt_ev->execute();
$event_id = $stmt_ev->fetchColumn();

// 2. Ajout d'un invité initial
$tel_init = "+225 07 11 22 33 44";
$tel_init_norm = normalizePhone($tel_init);

$stmt_g = $pdo->prepare("
    INSERT INTO event_guest_whitelist (event_id, nom, prenom, telephone, email, tickets_autorises, ajoute_par)
    VALUES (?, 'Kouame', 'Ancien', ?, 'ancien@test.com', 2, 1)
    RETURNING id
");
$stmt_g->execute([$event_id, $tel_init_norm]);
$guest_id = $stmt_g->fetchColumn();

assert_test($guest_id > 0, "Invité initial créé ID: $guest_id");

// 3. Modification de l'invité (Nouveau nom, nouveau téléphone, quota modifié)
$tel_mod = "+225 05 99 88 77 66";
$tel_mod_norm = normalizePhone($tel_mod);

$stmt_upd = $pdo->prepare("
    UPDATE event_guest_whitelist
    SET nom = ?, prenom = ?, telephone = ?, email = ?, tickets_autorises = ?
    WHERE id = ? AND event_id = ?
");
$stmt_upd->execute(['Kouame Modifie', 'Nouveau', $tel_mod_norm, 'modifie@test.com', 5, $guest_id, $event_id]);

// 4. Vérification de la mise à jour
$stmt_chk = $pdo->prepare("SELECT nom, prenom, telephone, email, tickets_autorises FROM event_guest_whitelist WHERE id = ?");
$stmt_chk->execute([$guest_id]);
$updated = $stmt_chk->fetch(PDO::FETCH_ASSOC);

assert_test($updated['nom'] === 'Kouame Modifie', "Nom de l'invité mis à jour avec succès");
assert_test($updated['prenom'] === 'Nouveau', "Prénom de l'invité mis à jour avec succès");
assert_test($updated['telephone'] === $tel_mod_norm, "Téléphone normalisé mis à jour avec succès");
assert_test($updated['email'] === 'modifie@test.com', "Email mis à jour avec succès");
assert_test((int)$updated['tickets_autorises'] === 5, "Quota de tickets autorisés mis à jour à 5");

// 5. Test modification dans cotisation_whitelist
$stmt_camp = $pdo->prepare("
    INSERT INTO cotisation_campagnes (user_id, titre, description, montant_objectif, statut, visibilite, access_token)
    VALUES (1, 'Campagne Test Edit', 'Desc', 500000, 'active', 'prive', 'tok_cot_edit')
    RETURNING id
");
$stmt_camp->execute();
$camp_id = $stmt_camp->fetchColumn();

// Ajout invité cotisation
$stmt_cw = $pdo->prepare("
    INSERT INTO cotisation_whitelist (campagne_id, nom, prenom, telephone, email, ajoute_par)
    VALUES (?, 'Yao', 'Initial', ?, 'yao@init.com', 1)
    RETURNING id
");
$stmt_cw->execute([$camp_id, $tel_init_norm]);
$part_id = $stmt_cw->fetchColumn();

// Modification invité cotisation
$stmt_upd_cw = $pdo->prepare("
    UPDATE cotisation_whitelist
    SET nom = ?, prenom = ?, telephone = ?, email = ?
    WHERE id = ? AND campagne_id = ?
");
$stmt_upd_cw->execute(['Yao MisAJour', 'Renomme', $tel_mod_norm, 'yao@nouveau.com', $part_id, $camp_id]);

$stmt_chk_cw = $pdo->prepare("SELECT nom, prenom, telephone, email FROM cotisation_whitelist WHERE id = ?");
$stmt_chk_cw->execute([$part_id]);
$updated_cw = $stmt_chk_cw->fetch(PDO::FETCH_ASSOC);

assert_test($updated_cw['nom'] === 'Yao MisAJour', "Nom du participant cotisation mis à jour");
assert_test($updated_cw['telephone'] === $tel_mod_norm, "Téléphone du participant cotisation mis à jour");

// Annulation transaction de test
$pdo->rollBack();

echo "=== RÉSULTATS : $pass SUCCÈS, $fail ÉCHECS ===\n";
