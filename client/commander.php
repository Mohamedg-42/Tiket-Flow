<?php
// ==============================================================================
// CRÉATION DE COMMANDE MULTI-TICKETS (client/commander.php)
// Supporte l'achat simultané de plusieurs types de billets et quantités
// ==============================================================================

require_once '../config/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: accueil.php');
    exit();
}

// Les promoteurs et administrateurs ne peuvent pas acheter de billets (réservé aux clients)
if (isset($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['promoteur', 'admin'], true)) {
    $_SESSION['order_message'] = "L'achat de billets est réservé aux clients. Votre compte " . ($_SESSION['user_role'] ?? '') . " ne peut pas réserver.";
    header('Location: accueil.php?onglet=evenements');
    exit();
}

$event_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$event_id) {
    $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
}

// Coordonnées de l'acheteur (connecté ou invité)
$client_nom       = trim($_POST['client_nom'] ?? ($_SESSION['user_nom'] ?? ''));
$client_email     = trim($_POST['client_email'] ?? ($_SESSION['user_email'] ?? ''));
$client_telephone = trim($_POST['client_telephone'] ?? '');
$user_id          = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Récupération du panier multi-tickets : array tickets[ticket_type_id] = quantite
$tickets_input = $_POST['tickets'] ?? [];

// Places choisies sur le plan : array places[ticket_type_id][] = id_place
$places_input = $_POST['places'] ?? [];

// Rétrocompatibilité si un seul ticket_type était envoyé
if (empty($tickets_input) && isset($_POST['ticket_type'])) {
    $single_id = (int)$_POST['ticket_type'];
    $single_qty = (int)($_POST['quantite'] ?? 1);
    if ($single_id > 0 && $single_qty > 0) {
        $tickets_input[$single_id] = $single_qty;
    }
}

if (!$event_id || empty($tickets_input)) {
    $_SESSION['order_message'] = "Données de réservation invalides.";
    header('Location: accueil.php');
    exit();
}

if (empty($client_nom) || empty($client_email)) {
    $_SESSION['order_message'] = "Veuillez renseigner votre nom et votre adresse email pour recevoir vos billets.";
    header('Location: accueil.php');
    exit();
}

// 1. Vérification que l'événement est actif
$stmt = $pdo->prepare("SELECT id, nom FROM events WHERE id = ? AND statut = 'actif'");
$stmt->execute([$event_id]);
$event = $stmt->fetch();

if (!$event) {
    $_SESSION['order_message'] = "Cet événement n'est plus disponible à la réservation.";
    header('Location: accueil.php');
    exit();
}

// 2. Validation de chaque type de billet et vérification des stocks
$order_items_to_create = [];
$montant_total_commande = 0;
$total_places_choisies = 0;

// Fusionne les types choisis par quantité ET par places sélectionnées sur le plan
$all_type_ids = array_unique(array_merge(array_keys($tickets_input), array_keys($places_input)));

foreach ($all_type_ids as $ticket_type_id) {
    $ticket_type_id = (int)$ticket_type_id;
    $seat_ids = array_map('intval', $places_input[$ticket_type_id] ?? []);
    $qty = !empty($seat_ids) ? count($seat_ids) : (int)($tickets_input[$ticket_type_id] ?? 0);

    if ($qty <= 0) {
        continue;
    }

    $stmt_ticket = $pdo->prepare("SELECT * FROM ticket_types WHERE id = ? AND event_id = ?");
    $stmt_ticket->execute([$ticket_type_id, $event_id]);
    $ticket = $stmt_ticket->fetch();

    if (!$ticket) {
        $_SESSION['order_message'] = "Un des types de billet sélectionnés est invalide.";
        header('Location: accueil.php');
        exit();
    }

    $stock_disponible = (int)$ticket['quantite'] - (int)$ticket['quantite_vendue'];
    if ($qty > $stock_disponible) {
        $_SESSION['order_message'] = "Stock insuffisant pour « " . htmlspecialchars($ticket['nom']) . " » (reste " . $stock_disponible . " place(s)).";
        header('Location: accueil.php');
        exit();
    }

    $prix_unitaire = (float)$ticket['prix'];

    // ===== Validation des places choisies (supplément selon le type de billet) =====
    $frais_place_unitaire = (float)($ticket['frais_place'] ?? 0);
    $places_numero        = null;
    $frais_place_total    = 0;
    $places_a_reserver    = [];

    if (!empty($seat_ids)) {
        if ($frais_place_unitaire <= 0) {
            $_SESSION['order_message'] = "Le choix de place n'est pas disponible pour ce type de billet.";
            header('Location: accueil.php');
            exit();
        }

        $in = implode(',', $seat_ids);
        $stmt_places = $pdo->prepare("SELECT id, numero FROM places WHERE ticket_type_id = ? AND id IN ($in) AND statut = 'libre'");
        $stmt_places->execute([$ticket_type_id]);
        $valid_places = $stmt_places->fetchAll();

        if (count($valid_places) !== count(array_unique($seat_ids))) {
            $_SESSION['order_message'] = "Une des places choisies n'est plus disponible. Veuillez en sélectionner d'autres.";
            header('Location: accueil.php');
            exit();
        }

        $places_a_reserver = array_column($valid_places, 'id');
        $places_numero     = implode(', ', array_column($valid_places, 'numero'));
        $frais_place_total = $frais_place_unitaire * count($valid_places);
        $qty               = count($valid_places);
    }

    $sous_total = ($prix_unitaire * $qty) + $frais_place_total;

    $order_items_to_create[] = [
        'ticket_type_id' => $ticket_type_id,
        'nom'            => $ticket['nom'],
        'quantite'       => $qty,
        'prix_unitaire'  => $prix_unitaire,
        'sous_total'     => $sous_total,
        'frais_place'    => $frais_place_total,
        'places_numero'  => $places_numero,
        'places_ids'     => $places_a_reserver
    ];

    $montant_total_commande += $sous_total;
    $total_places_choisies += $qty;
}

if ($total_places_choisies <= 0 || empty($order_items_to_create)) {
    $_SESSION['order_message'] = "Veuillez sélectionner au moins un billet pour continuer.";
    header('Location: accueil.php');
    exit();
}

$numero_commande = 'CMD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

try {
    $pdo->beginTransaction();

    // 3. Création de la commande principale
    $sql_order = "INSERT INTO orders (user_id, client_nom, client_email, client_telephone, numero_commande, montant_total, statut) 
                  VALUES (?, ?, ?, ?, ?, ?, 'en_attente')";
    $stmt_order = $pdo->prepare($sql_order);
    $stmt_order->execute([
        $user_id,
        $client_nom,
        $client_email,
        $client_telephone,
        $numero_commande,
        $montant_total_commande
    ]);

    $order_id = (int)$pdo->lastInsertId();

    // 4. Enregistrement de chaque type de billet dans 'order_items' (avec places choisies)
    $sql_item = "INSERT INTO order_items (order_id, ticket_type_id, quantite, prix_unitaire, sous_total, frais_place, places_numero) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt_item = $pdo->prepare($sql_item);

    foreach ($order_items_to_create as $item) {
        $stmt_item->execute([
            $order_id,
            $item['ticket_type_id'],
            $item['quantite'],
            $item['prix_unitaire'],
            $item['sous_total'],
            $item['frais_place'],
            $item['places_numero']
        ]);
    }

    // 5. Réservation des places choisies sur le plan (statut 'reserve')
    $stmt_place = $pdo->prepare("UPDATE places SET statut = 'reserve' WHERE id = ? AND statut = 'libre'");
    foreach ($order_items_to_create as $item) {
        foreach ($item['places_ids'] as $place_id) {
            $stmt_place->execute([$place_id]);
        }
    }

    $pdo->commit();

    $_SESSION['guest_order_id'] = $order_id;

    // 5. Redirection vers le paiement Mobile Money
    header('Location: paiement.php?order_id=' . $order_id);
    exit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['order_message'] = "Erreur lors de la création de la commande : " . $e->getMessage();
    header('Location: accueil.php');
    exit();
}
