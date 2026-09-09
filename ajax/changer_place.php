<?php
// ==============================================================================
// ENDPOINT AJAX : CHANGEMENT DE PLACE / SIÈGE POST-PAIEMENT
// (ajax/changer_place.php)
// ==============================================================================

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_email = trim($_SESSION['user_email'] ?? '');

if ($user_id <= 0 && empty($user_email)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Veuillez vous connecter pour modifier votre place.']);
    exit();
}

$ticket_id = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
$nouveau_siege = trim($_POST['nouveau_siege'] ?? '');

if (!$ticket_id || empty($nouveau_siege)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides (ticket ou siège manquant).']);
    exit();
}

try {
    // 1. Récupération des données complètes du billet
    $stmt = $pdo->prepare("
        SELECT t.id, t.order_id, t.ticket_type_id, t.event_id, t.user_id, t.client_email, t.client_nom,
               t.type_ticket, t.place_numero, t.prix, t.code_unique, t.qr_code, t.statut,
               e.nom AS event_name, e.date_evenement, e.heure, e.lieu, e.statut AS event_statut
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Billet introuvable.']);
        exit();
    }

    // 2. Vérification de la propriété du billet
    $is_owner = false;
    if ($user_id > 0 && (int)$ticket['user_id'] === $user_id) {
        $is_owner = true;
    } elseif (!empty($user_email) && !empty($ticket['client_email']) && strtolower($user_email) === strtolower($ticket['client_email'])) {
        $is_owner = true;
    }

    if (!$is_owner) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Action non autorisée sur ce billet.']);
        exit();
    }

    // 3. Contrôle du statut du billet
    if ($ticket['statut'] !== 'vendu') {
        echo json_encode([
            'success' => false,
            'message' => 'Ce billet ne peut plus être modifié car il a déjà été ' . ($ticket['statut'] === 'utilise' ? 'validé à l\'entrée.' : 'annulé.')
        ]);
        exit();
    }

    // 4. Contrôle de la date et statut de l'événement
    $event_ts = strtotime($ticket['date_evenement'] . ' ' . $ticket['heure']);
    if ($event_ts < time() || $ticket['event_statut'] === 'termine' || $ticket['event_statut'] === 'annule') {
        echo json_encode([
            'success' => false,
            'message' => 'Cet événement est déjà terminé ou en cours. La modification de place est clôturée.'
        ]);
        exit();
    }

    $ancienne_place = trim((string)($ticket['place_numero'] ?? ''));
    if ($ancienne_place === $nouveau_siege) {
        echo json_encode([
            'success' => true,
            'unchanged' => true,
            'message' => "Vous occupez déjà la place « $nouveau_siege ». Aucun changement nécessaire.",
            'nouveau_siege' => $nouveau_siege
        ]);
        exit();
    }

    $ticket_type_id = (int)($ticket['ticket_type_id'] ?? 0);
    if ($ticket_type_id === 0 && !empty($ticket['event_id'])) {
        $stmt_tt = $pdo->prepare("SELECT id FROM ticket_types WHERE event_id = ? AND (nom ILIKE ? OR prix = ?) LIMIT 1");
        $stmt_tt->execute([(int)$ticket['event_id'], $ticket['type_ticket'], $ticket['prix']]);
        $tt_found = $stmt_tt->fetch(PDO::FETCH_ASSOC);
        if ($tt_found) {
            $ticket_type_id = (int)$tt_found['id'];
            $pdo->prepare("UPDATE tickets SET ticket_type_id = ? WHERE id = ?")->execute([$ticket_type_id, $ticket['id']]);
        }
    }

    // 5. TRANSACTION ATOMIQUE
    $pdo->beginTransaction();

    // A. Vérifier si le nouveau siège est déjà attribué à un autre billet vendu
    $stmt_check_ticket = $pdo->prepare("
        SELECT id FROM tickets 
        WHERE ticket_type_id = ? AND statut = 'vendu' AND place_numero = ? AND id != ?
        LIMIT 1
    ");
    $stmt_check_ticket->execute([$ticket_type_id, $nouveau_siege, $ticket['id']]);
    if ($stmt_check_ticket->fetch()) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => "Le siège « $nouveau_siege » vient tout juste d'être réservé par un autre spectateur. Veuillez choisir un autre siège."
        ]);
        exit();
    }

    // B. Vérifier le statut dans la table `places`
    $stmt_check_place = $pdo->prepare("
        SELECT id, statut FROM places 
        WHERE ticket_type_id = ? AND numero = ? 
        LIMIT 1
    ");
    $stmt_check_place->execute([$ticket_type_id, $nouveau_siege]);
    $place_row = $stmt_check_place->fetch(PDO::FETCH_ASSOC);

    if ($place_row && $place_row['statut'] === 'vendu') {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => "La place « $nouveau_siege » n'est plus disponible."
        ]);
        exit();
    }

    // C. Libérer l'ancienne place dans `places`
    if (!empty($ancienne_place)) {
        $stmt_free = $pdo->prepare("UPDATE places SET statut = 'libre' WHERE ticket_type_id = ? AND numero = ?");
        $stmt_free->execute([$ticket_type_id, $ancienne_place]);
    }

    // D. Marquer la nouvelle place comme vendue dans `places`
    if ($place_row) {
        $stmt_occupy = $pdo->prepare("UPDATE places SET statut = 'vendu' WHERE id = ?");
        $stmt_occupy->execute([$place_row['id']]);
    } else {
        $stmt_ins = $pdo->prepare("INSERT INTO places (ticket_type_id, numero, statut) VALUES (?, ?, 'vendu')");
        $stmt_ins->execute([$ticket_type_id, $nouveau_siege]);
    }

    // E. Mettre à jour le billet
    $stmt_upd_ticket = $pdo->prepare("UPDATE tickets SET place_numero = ? WHERE id = ?");
    $stmt_upd_ticket->execute([$nouveau_siege, $ticket['id']]);

    // F. Mettre à jour la commande associée dans `order_items`
    if (!empty($ticket['order_id'])) {
        $stmt_oi = $pdo->prepare("
            SELECT id, places_numero FROM order_items 
            WHERE order_id = ? AND ticket_type_id = ? 
            LIMIT 1
        ");
        $stmt_oi->execute([$ticket['order_id'], $ticket_type_id]);
        $oi = $stmt_oi->fetch(PDO::FETCH_ASSOC);

        if ($oi && !empty($oi['places_numero'])) {
            $items_places = array_map('trim', explode(',', $oi['places_numero']));
            $idx = array_search($ancienne_place, $items_places, true);
            if ($idx !== false) {
                $items_places[$idx] = $nouveau_siege;
            } else {
                $items_places[] = $nouveau_siege;
            }
            $clean_places = implode(', ', array_unique($items_places));
            $upd_oi = $pdo->prepare("UPDATE order_items SET places_numero = ? WHERE id = ?");
            $upd_oi->execute([$clean_places, $oi['id']]);
        }
    }

    $pdo->commit();

    // 6. Journalisation d'activité (audit)
    $log_msg = "Changement de place billet #{$ticket['code_unique']} : '$ancienne_place' -> '$nouveau_siege' ({$ticket['event_name']})";
    logActivity('changement_place', 'ticket', (int)$ticket['id'], $log_msg, $user_id ?: null);

    // 7. Envoi de l'e-mail de confirmation avec le nouveau billet PDF attaché
    $dest_email = !empty($ticket['client_email']) ? $ticket['client_email'] : $user_email;
    $dest_name = !empty($ticket['client_nom']) ? $ticket['client_nom'] : ($_SESSION['user_nom'] ?? 'Client');
    if (!empty($dest_email) && filter_var($dest_email, FILTER_VALIDATE_EMAIL)) {
        try {
            sendSeatChangedConfirmationEmail(
                $dest_email,
                $dest_name,
                $ticket,
                $ancienne_place,
                $nouveau_siege
            );
        } catch (Throwable $e) {
            error_log("[Tikéli Mailer] Erreur lors de l'envoi de notification changement place : " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Félicitations ! Votre place a été mise à jour avec succès : $nouveau_siege.",
        'ticket_id' => (int)$ticket['id'],
        'ancienne_place' => $ancienne_place,
        'nouveau_siege' => $nouveau_siege
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur technique lors du changement de place : ' . $e->getMessage()
    ]);
}
