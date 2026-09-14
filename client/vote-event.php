<?php
// ==============================================================================
// VOTE / RETRAIT DE VOTE POUR UN ÉVÉNEMENT (client/vote-event.php)
// Endpoint AJAX : bascule le vote du visiteur courant et renvoie le nouveau compteur
// ==============================================================================

require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $redir_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: (int)($_GET['event_id'] ?? 0);
    if ($redir_id > 0) {
        header('Location: vote.php?id=' . $redir_id);
        exit();
    }
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit();
}

$event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT) ?: (int)($_POST['event_id'] ?? 0);
if (!$event_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Événement invalide.']);
    exit();
}

// Vérifier que l'événement existe et est actif (+ visibilité et prix du vote)
$stmt = $pdo->prepare("SELECT id, nom, prix_vote, visibilite, access_token, type_vote, vote_question FROM events WHERE id = ? AND statut = 'actif'");
$stmt->execute([$event_id]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    echo json_encode(['error' => 'Événement introuvable.']);
    exit();
}
$prix_vote = (float)($event['prix_vote'] ?? 0);

// Identifiant du visiteur (connecté ou non) basé sur la session
$visitor_id = session_id();
$user_id    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Les promoteurs et administrateurs ne peuvent pas voter (réservé aux clients)
if ($user_id && in_array($_SESSION['user_role'] ?? '', ['promoteur', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Action réservée aux clients : votre compte ' . ($_SESSION['user_role'] ?? '') . ' ne peut pas voter.']);
    exit();
}

// CONTRÔLE D'ACCÈS ET WHITELIST POUR LES SCRUTINS PRIVÉS
$is_private_event = ($event['visibilite'] ?? 'public') === 'prive';
$verified_tel_clean = null;
if ($is_private_event) {
    $token_req = trim($_POST['token'] ?? ($_GET['token'] ?? ''));
    $expected_token = (string)($event['access_token'] ?? '');
    if (empty($token_req) || !hash_equals($expected_token, $token_req)) {
        http_response_code(403);
        echo json_encode(['error' => "Action non autorisée : Jeton d'accès privé manquant ou invalide."]);
        exit();
    }

    require_once __DIR__ . '/../includes/whitelist.php';
    $tel_input = trim($_POST['telephone'] ?? '');
    if (empty($tel_input) && $user_id) {
        $stmt_tel = $pdo->prepare("SELECT telephone FROM users WHERE id = ?");
        $stmt_tel->execute([$user_id]);
        $tel_input = (string) $stmt_tel->fetchColumn();
    }

    $verified_tel_clean = normalizePhone($tel_input);
    if (empty($verified_tel_clean)) {
        http_response_code(403);
        echo json_encode(['error' => "Ce scrutin est privé. Votre numéro de téléphone enregistré auprès de l'organisateur est requis pour voter.", 'requires_phone' => true]);
        exit();
    }

    // Vérifier l'enregistrement préalable dans la liste des invités de l'événement
    $stmt_wl = $pdo->prepare("SELECT id, nom, prenom FROM event_guest_whitelist WHERE event_id = ? AND telephone = ?");
    $stmt_wl->execute([$event_id, $verified_tel_clean]);
    $guest_wl = $stmt_wl->fetch(PDO::FETCH_ASSOC);

    if (!$guest_wl) {
        http_response_code(403);
        echo json_encode(['error' => "Action refusée : Le numéro " . htmlspecialchars($tel_input) . " ne figure pas sur la liste des participants autorisés pour ce scrutin privé."]);
        exit();
    }
}

// Vérifie si le visiteur/client a déjà voté pour cet événement
function vote_existant($pdo, $event_id, $user_id, $visitor_id) {
    if ($user_id) {
        $stmt = $pdo->prepare("SELECT id FROM event_votes WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$event_id, $user_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM event_votes WHERE event_id = ? AND visitor_id = ?");
        $stmt->execute([$event_id, $visitor_id]);
    }
    return $stmt->fetch();
}

try {
    // Récupérer les candidats éventuels de l'événement
    $stmt_cands = $pdo->prepare("SELECT id, nom, description, photo FROM event_candidats WHERE event_id = ? ORDER BY id ASC");
    $stmt_cands->execute([$event_id]);
    $candidats = $stmt_cands->fetchAll(PDO::FETCH_ASSOC);

    // Ajuster le chemin des photos pour le front
    foreach ($candidats as &$c) {
        if (!empty($c['photo'])) {
            if (strpos($c['photo'], 'http') !== 0) {
                $c['photo'] = '../uploads/candidats/' . $c['photo'];
            }
        } else {
            $c['photo'] = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
        }
    }
    unset($c);

    // ===== VOTE PAYANT : le promoteur a fixé un prix pour voter =====
    if ($prix_vote > 0) {
        // Phase 1 (sans confirmation) : annonce du prix & envoi des candidats éventuels (la modale s'ouvre côté client)
        if (($_POST['phase'] ?? '') !== '2') {
            echo json_encode([
                'needs_payment' => true,
                'prix'          => $prix_vote,
                'event_nom'     => $event['nom'],
                'type_vote'     => $event['type_vote'] ?? 'concours',
                'vote_question' => $event['vote_question'] ?? '',
                'candidats'     => $candidats
            ]);
            exit();
        }

        // Phase 2 : création du paiement de vote en attente
        // (l'opérateur Mobile Money et le numéro seront choisis sur la page de paiement sécurisée,
        //  exactement comme pour l'achat de billets — aucune sélection ici)

        // Traitement des choix multiples (candidats)
        $raw_candidats = $_POST['candidat_ids'] ?? [];
        $candidat_ids  = [];
        if (is_array($raw_candidats)) {
            $candidat_ids = array_values(array_filter(array_map('intval', $raw_candidats)));
        } elseif (is_string($raw_candidats) && trim($raw_candidats) !== '') {
            $candidat_ids = array_values(array_filter(array_map('intval', explode(',', $raw_candidats))));
        }

        if (!empty($candidats) && empty($candidat_ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'Veuillez cocher au moins une option / candidat pour valider votre vote.']);
            exit();
        }

        $nb_choix      = max(1, count($candidat_ids));
        $montant_total = $prix_vote * $nb_choix;
        $primary_cand  = !empty($candidat_ids) ? $candidat_ids[0] : null;
        $cands_json    = !empty($candidat_ids) ? json_encode($candidat_ids) : null;

        $reference = 'VOTE-' . strtoupper(substr(uniqid(), -6));
        $stmt_ins = $pdo->prepare("
            INSERT INTO vote_paiements (event_id, candidat_id, candidats_ids, user_id, visitor_id, telephone, montant, methode, reference, statut)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, 'en_attente')
        ");
        $stmt_ins->execute([$event_id, $primary_cand, $cands_json, $user_id, $visitor_id, $verified_tel_clean, $montant_total, $reference]);

        $redir = 'paiement-vote.php?id=' . $pdo->lastInsertId() . ($is_private_event ? ('&token=' . urlencode($token_req)) : '');
        echo json_encode(['redirect' => $redir]);
        exit();
    }

    // ===== VOTE GRATUIT : bascule voter / retirer le vote =====
    $candidat_id = filter_input(INPUT_POST, 'candidat_id', FILTER_VALIDATE_INT) ?: null;

    if ($candidat_id) {
        if ($user_id) {
            $stmt = $pdo->prepare("SELECT id FROM event_votes WHERE event_id = ? AND user_id = ? AND candidat_id = ?");
            $stmt->execute([$event_id, $user_id, $candidat_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM event_votes WHERE event_id = ? AND visitor_id = ? AND candidat_id = ?");
            $stmt->execute([$event_id, $visitor_id, $candidat_id]);
        }
        $existing_cand = $stmt->fetch();

        if ($existing_cand) {
            $stmt_del = $pdo->prepare("DELETE FROM event_votes WHERE id = ?");
            $stmt_del->execute([$existing_cand['id']]);
            $voted = false;
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO event_votes (event_id, user_id, visitor_id, candidat_id) VALUES (?, ?, ?, ?)");
            $stmt_ins->execute([$event_id, $user_id, $visitor_id, $candidat_id]);
            $voted = true;
        }

        // Compteur de ce candidat spécifique
        $stmt_c = $pdo->prepare("SELECT COUNT(*) FROM event_votes WHERE candidat_id = ?");
        $stmt_c->execute([$candidat_id]);
        $cand_votes = (int)$stmt_c->fetchColumn();

        // Nouveau compteur total de l'événement
        $stmt_t = $pdo->prepare("SELECT COUNT(*) FROM event_votes WHERE event_id = ?");
        $stmt_t->execute([$event_id]);
        $total_votes = (int)$stmt_t->fetchColumn();

        echo json_encode([
            'voted'       => $voted,
            'votes'       => $total_votes,
            'candidat_id' => $candidat_id,
            'cand_votes'  => $cand_votes
        ]);
        exit();
    }

    $existing = vote_existant($pdo, $event_id, $user_id, $visitor_id);

    if ($existing) {
        // Retirer le vote
        $stmt = $pdo->prepare("DELETE FROM event_votes WHERE id = ?");
        $stmt->execute([$existing['id']]);
        $voted = false;
    } else {
        // Ajouter le vote
        $stmt = $pdo->prepare("INSERT INTO event_votes (event_id, user_id, visitor_id) VALUES (?, ?, ?)");
        $stmt->execute([$event_id, $user_id, $visitor_id]);
        $voted = true;
    }

    // Nouveau compteur de votes pour cet événement
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_votes WHERE event_id = ?");
    $stmt->execute([$event_id]);
    $count = (int)$stmt->fetchColumn();

    echo json_encode(['voted' => $voted, 'votes' => $count]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de données : exécutez config/migration-votes-cotisations.sql pour créer la table event_votes.']);
}