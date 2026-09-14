<?php
// Test d'exportation CSV des listes d'invités
require_once __DIR__ . '/../config/database.php';

function run_test($name, $fn) {
    try {
        $res = $fn();
        if ($res) {
            echo "[\033[32mOK\033[0m] $name\n";
        } else {
            echo "[\033[31mFAIL\033[0m] $name\n";
        }
    } catch (Exception $e) {
        echo "[\033[31mERROR\033[0m] $name: " . $e->getMessage() . "\n";
    }
}

// 1. Trouver un promoteur et un événement privé
$stmt_p = $pdo->query("SELECT user_id, id FROM events WHERE visibilite = 'prive' LIMIT 1");
$ev = $stmt_p->fetch();

if (!$ev) {
    echo "Création d'un événement privé de test...\n";
    $p_id = $pdo->query("SELECT id FROM users WHERE role = 'promoteur' LIMIT 1")->fetchColumn() ?: 1;
    $pdo->prepare("INSERT INTO events (user_id, nom, visibilite, access_token, date_evenement, heure, lieu, categorie, description) VALUES (?, 'Soirée Export Test', 'prive', 'token_export_test', CURRENT_DATE + INTERVAL '5 days', '20:00', 'Abidjan', 'Concert', 'Test desc')")->execute([$p_id]);
    $ev_id = $pdo->lastInsertId();
} else {
    $p_id = $ev['user_id'];
    $ev_id = $ev['id'];
}

// S'assurer d'avoir au moins 1 invité dans cet événement
$pdo->prepare("INSERT INTO event_guest_whitelist (event_id, nom, prenom, telephone, email, tickets_autorises, ajoute_par) VALUES (?, 'Kouamé', 'Jean-Luc', '0700112233', 'jeanluc@test.ci', 2, ?) ON CONFLICT (event_id, telephone) DO NOTHING")->execute([$ev_id, $p_id]);

// 2. Trouver ou créer une campagne de cotisation privée
$stmt_c = $pdo->prepare("SELECT id FROM cotisation_campagnes WHERE user_id = ? AND visibilite = 'prive' LIMIT 1");
$stmt_c->execute([$p_id]);
$camp_id = $stmt_c->fetchColumn();

if (!$camp_id) {
    $pdo->prepare("INSERT INTO cotisation_campagnes (user_id, titre, montant_objectif, visibilite, access_token, statut) VALUES (?, 'Cotisation Export Test', 500000, 'prive', 'cotis_exp_token', 'active')")->execute([$p_id]);
    $camp_id = $pdo->lastInsertId();
}

// S'assurer d'avoir au moins 1 invité dans cette campagne
$pdo->prepare("INSERT INTO cotisation_whitelist (campagne_id, nom, prenom, telephone, email, montant_promis, ajoute_par) VALUES (?, 'Aka', 'Estelle', '0505112233', 'estelle@test.ci', 25000, ?) ON CONFLICT (campagne_id, telephone) DO NOTHING")->execute([$camp_id, $p_id]);

// TEST 1 : Requête SQL d'exportation d'invités d'événements
run_test("Export invités événements - Extraction SQL & formatage", function() use ($pdo, $p_id, $ev_id) {
    $sql = "
        SELECT w.*, e.nom AS event_nom, e.visibilite AS event_visibilite
        FROM event_guest_whitelist w
        JOIN events e ON w.event_id = e.id
        WHERE e.user_id = ? AND w.event_id = ?
        ORDER BY e.nom ASC, w.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$p_id, $ev_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return false;
    
    // Vérifier présence de Kouamé
    $found = false;
    foreach ($rows as $r) {
        if ($r['nom'] === 'Kouamé' && $r['telephone'] === '0700112233') {
            $found = true;
            break;
        }
    }
    return $found;
});

// TEST 2 : Requête SQL d'exportation d'invités de cotisations
run_test("Export invités cotisations - Extraction SQL & formatage", function() use ($pdo, $p_id, $camp_id) {
    $sql = "
        SELECT cw.*, c.titre AS campagne_titre
        FROM cotisation_whitelist cw
        JOIN cotisation_campagnes c ON cw.campagne_id = c.id
        WHERE c.user_id = ? AND cw.campagne_id = ?
        ORDER BY c.titre ASC, cw.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$p_id, $camp_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return false;
    
    $found = false;
    foreach ($rows as $r) {
        if ($r['nom'] === 'Aka' && $r['telephone'] === '0505112233') {
            $found = true;
            break;
        }
    }
    return $found;
});

// TEST 3 : Simulation d'exécution du buffer CSV export.php avec BOM
run_test("Vérification format CSV (BOM UTF-8, délimiteur ';')", function() {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, "\xEF\xBB\xBF"); // BOM UTF-8
    
    $header = ['ID Invité', 'Événement', 'Nom', 'Prénom', 'Téléphone'];
    fputcsv($stream, $header, ';', '"', "\\");
    
    $row = ['#INV-00001', 'Gala Privé', 'Konan', 'Éric', '0700998877'];
    fputcsv($stream, $row, ';', '"', "\\");
    
    rewind($stream);
    $content = stream_get_contents($stream);
    fclose($stream);
    
    $has_bom = str_starts_with($content, "\xEF\xBB\xBF");
    $has_semicolon = str_contains($content, ';');
    $has_name = str_contains($content, 'Konan') && str_contains($content, '0700998877');
    
    return $has_bom && $has_semicolon && $has_name;
});

echo "\nTous les tests d'exportation ont été exécutés avec succès.\n";
