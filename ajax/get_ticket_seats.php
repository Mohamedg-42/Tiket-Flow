<?php
// ==============================================================================
// ENDPOINT AJAX : CHARGEMENT DES PLACES POUR CHANGEMENT DE SIÈGE
// (ajax/get_ticket_seats.php)
// ==============================================================================

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/places.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_email = trim($_SESSION['user_email'] ?? '');

if ($user_id <= 0 && empty($user_email)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Veuillez vous connecter pour accéder à vos billets.']);
    exit();
}

$ticket_id = filter_input(INPUT_GET, 'ticket_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);

if (!$ticket_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Identifiant de billet manquant ou invalide.']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.order_id, t.ticket_type_id, t.event_id, t.user_id, t.client_email, t.client_nom,
               t.type_ticket, t.place_numero, t.prix, t.code_unique, t.qr_code, t.statut,
               e.nom AS event_name, e.date_evenement, e.heure, e.lieu, e.salle_id, e.statut AS event_statut,
               tt.nom AS ticket_type_nom, tt.quantite AS type_quantite, tt.frais_place
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN ticket_types tt ON t.ticket_type_id = tt.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Billet introuvable.']);
        exit();
    }

    // Vérification de la propriété du billet
    $is_owner = false;
    if ($user_id > 0 && (int)$ticket['user_id'] === $user_id) {
        $is_owner = true;
    } elseif (!empty($user_email) && !empty($ticket['client_email']) && strtolower($user_email) === strtolower($ticket['client_email'])) {
        $is_owner = true;
    }

    if (!$is_owner) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Vous n\'êtes pas autorisé à modifier ce billet.']);
        exit();
    }

    // Vérification du statut du billet
    if ($ticket['statut'] !== 'vendu') {
        echo json_encode([
            'success' => false,
            'message' => 'Ce billet ne peut pas être déplacé car il est ' . ($ticket['statut'] === 'utilise' ? 'déjà utilisé / scanné.' : 'annulé.')
        ]);
        exit();
    }

    // Vérification de la date de l'événement
    $event_ts = strtotime($ticket['date_evenement'] . ' ' . $ticket['heure']);
    if ($event_ts < time() || $ticket['event_statut'] === 'termine' || $ticket['event_statut'] === 'annule') {
        echo json_encode([
            'success' => false,
            'message' => 'Cet événement est déjà terminé ou commencé. Le changement de place n\'est plus possible.'
        ]);
        exit();
    }

    $ticket_type_id = (int)$ticket['ticket_type_id'];

    // S'assurer que les places physiques de ce type sont bien créées en base
    if ($ticket_type_id > 0 && !empty($ticket['type_quantite'])) {
        generer_places_type($pdo, $ticket_type_id, (int)$ticket['type_quantite']);
    }

    // Récupérer les places actuellement occupées par d'autres billets vendus pour cet événement/type
    $stmt_sold_tickets = $pdo->prepare("
        SELECT place_numero 
        FROM tickets 
        WHERE ticket_type_id = ? AND statut = 'vendu' AND place_numero IS NOT NULL AND id != ?
    ");
    $stmt_sold_tickets->execute([$ticket_type_id, $ticket['id']]);
    $taken_by_other_tickets = [];
    while ($r = $stmt_sold_tickets->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['place_numero'])) {
            $taken_by_other_tickets[trim($r['place_numero'])] = true;
        }
    }

    // Récupération de la liste des places depuis la table `places`
    $stmt_places = $pdo->prepare("
        SELECT id, numero, statut, pos_x, pos_y 
        FROM places 
        WHERE ticket_type_id = ? 
        ORDER BY LENGTH(numero) ASC, numero ASC
    ");
    $stmt_places->execute([$ticket_type_id]);
    $db_places = $stmt_places->fetchAll(PDO::FETCH_ASSOC);

    $current_seat = trim((string)($ticket['place_numero'] ?? ''));
    $places_list = [];

    foreach ($db_places as $p) {
        $num = trim($p['numero']);
        $is_current = ($current_seat !== '' && $num === $current_seat);
        $is_occupied = isset($taken_by_other_tickets[$num]) || ($p['statut'] === 'vendu' && !$is_current);

        // Détection de rangée pour organisation visuelle
        $row_label = 'Standard';
        if (preg_match('/^([A-Za-z]+)\s*(\d+)$/', $num, $matches)) {
            $row_label = 'Rangée ' . strtoupper($matches[1]);
        } elseif (preg_match('/^Place\s*(\d+)$/i', $num, $matches)) {
            $val = (int)$matches[1];
            $row_num = ceil($val / 10);
            $row_label = 'Rangée ' . chr(64 + ($row_num <= 26 ? $row_num : 26));
        }

        $places_list[] = [
            'id' => (int)$p['id'],
            'numero' => $num,
            'row' => $row_label,
            'is_current' => $is_current,
            'is_available' => !$is_occupied && !$is_current,
            'statut_label' => $is_current ? 'Votre place actuelle' : ($is_occupied ? 'Occupée' : 'Disponible')
        ];
    }

    echo json_encode([
        'success' => true,
        'ticket' => [
            'id' => (int)$ticket['id'],
            'order_id' => (int)$ticket['order_id'],
            'event_id' => (int)$ticket['event_id'],
            'event_name' => $ticket['event_name'],
            'date_evenement' => date('d/m/Y', strtotime($ticket['date_evenement'])),
            'heure' => substr($ticket['heure'], 0, 5),
            'lieu' => $ticket['lieu'],
            'type_ticket' => $ticket['type_ticket'],
            'place_numero' => $current_seat,
            'prix' => (float)$ticket['prix'],
            'code_unique' => $ticket['code_unique'],
            'has_salle' => !empty($ticket['salle_id'])
        ],
        'total_places' => count($places_list),
        'available_count' => count(array_filter($places_list, fn($p) => $p['is_available'])),
        'places' => $places_list
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors de la récupération des places : ' . $e->getMessage()
    ]);
}
