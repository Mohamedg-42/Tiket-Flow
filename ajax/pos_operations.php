<?php
/**
 * AJAX Endpoint : Opérations de Caisse et Vente Guichet (POS)
 * ajax/pos_operations.php
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentification requise (agent, promoteur ou admin)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'UNAUTHENTICATED', 'message' => 'Session expirée.']);
    exit();
}

$user_id   = (int) $_SESSION['user_id'];
$action    = trim($_POST['action'] ?? '');

try {
    switch ($action) {
        // ----------------------------------------------------------------------
        // 1. Ouvrir une session de caisse
        // ----------------------------------------------------------------------
        case 'open_session':
            $pos_terminal_id = filter_input(INPUT_POST, 'pos_terminal_id', FILTER_VALIDATE_INT);
            $fond_caisse     = (float) ($_POST['fond_caisse_ouverture'] ?? 0);

            if (!$pos_terminal_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'MISSING_TERMINAL', 'message' => 'Guichet non identifié.']);
                exit();
            }

            // Vérifier si une session est déjà ouverte pour cet agent sur ce guichet
            $stmt_chk = $pdo->prepare("SELECT id FROM pos_sessions WHERE pos_terminal_id = ? AND agent_id = ? AND statut = 'ouverte'");
            $stmt_chk->execute([$pos_terminal_id, $user_id]);
            $existingSession = $stmt_chk->fetch(PDO::FETCH_ASSOC);

            if ($existingSession) {
                echo json_encode([
                    'success'    => true,
                    'session_id' => (int) $existingSession['id'],
                    'message'    => 'Session déjà ouverte.'
                ]);
                exit();
            }

            $stmt_ins = $pdo->prepare("
                INSERT INTO pos_sessions (pos_terminal_id, agent_id, fond_caisse_ouverture, statut, opened_at) 
                VALUES (?, ?, ?, 'ouverte', CURRENT_TIMESTAMP)
            ");
            $stmt_ins->execute([$pos_terminal_id, $user_id, $fond_caisse]);
            $session_id = (int) $pdo->lastInsertId();

            echo json_encode([
                'success'    => true,
                'session_id' => $session_id,
                'message'    => 'Session de caisse ouverte avec succès. Prêt pour les ventes.'
            ]);
            break;

        // ----------------------------------------------------------------------
        // 2. Clôturer une session de caisse
        // ----------------------------------------------------------------------
        case 'close_session':
            $session_id   = filter_input(INPUT_POST, 'session_id', FILTER_VALIDATE_INT);
            $montant_reel = (float) ($_POST['montant_reel_fermeture'] ?? 0);
            $notes        = trim($_POST['notes_cloture'] ?? '');

            if (!$session_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'MISSING_SESSION_ID', 'message' => 'Identifiant de session manquant.']);
                exit();
            }

            $stmt = $pdo->prepare("SELECT * FROM pos_sessions WHERE id = ?");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session || $session['statut'] !== 'ouverte') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ALREADY_CLOSED', 'message' => 'Cette session est déjà clôturée.']);
                exit();
            }

            // Calcul du solde théorique espèces en caisse : fond d'ouverture + ventes espèces
            $theorique = (float)$session['fond_caisse_ouverture'] + (float)$session['total_ventes_especes'];
            $ecart = $montant_reel - $theorique;

            $stmt_close = $pdo->prepare("
                UPDATE pos_sessions 
                SET montant_reel_fermeture = ?, 
                    ecart_caisse = ?, 
                    notes_cloture = ?, 
                    statut = 'cloturee', 
                    closed_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt_close->execute([$montant_reel, $ecart, $notes, $session_id]);

            echo json_encode([
                'success'      => true,
                'theorique'    => $theorique,
                'montant_reel' => $montant_reel,
                'ecart'        => $ecart,
                'message'      => 'Caisse clôturée avec succès.'
            ]);
            break;

        // ----------------------------------------------------------------------
        // 3. Émission rapide de billets au comptoir (Vente POS)
        // ----------------------------------------------------------------------
        case 'emit_tickets':
            $station_id      = filter_input(INPUT_POST, 'station_id', FILTER_VALIDATE_INT);
            $session_id      = filter_input(INPUT_POST, 'session_id', FILTER_VALIDATE_INT);
            $event_id        = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
            $ticket_type_id  = filter_input(INPUT_POST, 'ticket_type_id', FILTER_VALIDATE_INT);
            $quantite        = max(1, (int) ($_POST['quantite'] ?? 1));
            $client_nom      = trim($_POST['client_nom'] ?? 'Client Comptoir');
            $client_tel      = trim($_POST['client_telephone'] ?? '');
            $mode_paiement   = trim($_POST['mode_paiement'] ?? 'especes'); // 'especes' ou 'mobile_money'
            $montant_recu    = (float) ($_POST['montant_recu'] ?? 0);

            if (!$station_id || !$event_id || !$ticket_type_id || $quantite <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Paramètres de vente invalides.']);
                exit();
            }

            // 3.1 Contrôle strict du statut de la gare
            $stmt_st = $pdo->prepare("SELECT statut, nom FROM stations WHERE id = ?");
            $stmt_st->execute([$station_id]);
            $station = $stmt_st->fetch(PDO::FETCH_ASSOC);

            if (!$station || $station['statut'] !== 'actif') {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error'   => 'STATION_SUSPENDED',
                    'message' => 'Les opérations de vente de cette gare sont actuellement suspendues par l\'administration.'
                ]);
                exit();
            }

            // 3.2 Contrôle du type de billet et du stock disponible
            $stmt_tk = $pdo->prepare("SELECT * FROM ticket_types WHERE id = ? AND event_id = ?");
            $stmt_tk->execute([$ticket_type_id, $event_id]);
            $ticketType = $stmt_tk->fetch(PDO::FETCH_ASSOC);

            if (!$ticketType) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'TICKET_TYPE_NOT_FOUND', 'message' => 'Formule de billet introuvable.']);
                exit();
            }

            $stockDispo = (int)$ticketType['quantite'] - (int)$ticketType['quantite_vendue'];
            if ($quantite > $stockDispo) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => 'STOCK_INSUFFICIENT',
                    'message' => "Stock insuffisant : il ne reste que {$stockDispo} place(s)."
                ]);
                exit();
            }

            $prixUnitaire = (float) $ticketType['prix'];
            $totalAPayer  = $prixUnitaire * $quantite;
            $monnaieARendre = max(0, $montant_recu - $totalAPayer);

            // 3.3 Transaction atomique de création
            $pdo->beginTransaction();

            $numCommande = 'POS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            $sql_order = "
                INSERT INTO orders (
                    user_id, client_nom, client_telephone, numero_commande, 
                    montant_total, statut, canal_vente, station_id, pos_session_id, mode_paiement
                ) VALUES (
                    ?, ?, ?, ?, ?, 'validee', 'guichet', ?, ?, ?
                )
            ";
            $stmt_ord = $pdo->prepare($sql_order);
            $stmt_ord->execute([$user_id, $client_nom, $client_tel, $numCommande, $totalAPayer, $station_id, $session_id ?: null, $mode_paiement]);
            $order_id = (int) $pdo->lastInsertId();

            // Order item
            $stmt_item = $pdo->prepare("
                INSERT INTO order_items (order_id, ticket_type_id, quantite, prix_unitaire, sous_total) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt_item->execute([$order_id, $ticket_type_id, $quantite, $prixUnitaire, $totalAPayer]);

            // Génération des billets physiques individuels
            $issuedTickets = [];
            $stmt_ticket_ins = $pdo->prepare("
                INSERT INTO tickets (
                    order_id, ticket_type_id, event_id, client_nom, client_telephone, 
                    type_ticket, prix, code_unique, qr_code, statut, canal_vente, station_id, date_achat
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, 'vendu', 'guichet', ?, CURRENT_TIMESTAMP
                )
            ");

            for ($i = 1; $i <= $quantite; $i++) {
                $uniqueCode = 'TIK-' . strtoupper(substr(uniqid(), -6)) . '-' . mt_rand(10, 99);
                $qrContent  = $uniqueCode . '|' . $event_id . '|' . $order_id;

                $stmt_ticket_ins->execute([
                    $order_id,
                    $ticket_type_id,
                    $event_id,
                    $client_nom,
                    $client_tel,
                    $ticketType['nom'],
                    $prixUnitaire,
                    $uniqueCode,
                    $qrContent,
                    $station_id
                ]);

                $ticket_id = (int) $pdo->lastInsertId();
                $issuedTickets[] = [
                    'id'          => $ticket_id,
                    'code_unique' => $uniqueCode,
                    'nom'         => $ticketType['nom'],
                    'prix'        => $prixUnitaire
                ];
            }

            // Décrémenter le stock dans ticket_types
            $stmt_upd_tk = $pdo->prepare("UPDATE ticket_types SET quantite_vendue = quantite_vendue + ? WHERE id = ?");
            $stmt_upd_tk->execute([$quantite, $ticket_type_id]);

            // Mettre à jour les totaux de la session de caisse
            if ($session_id) {
                if ($mode_paiement === 'especes') {
                    $pdo->prepare("UPDATE pos_sessions SET total_ventes_especes = total_ventes_especes + ?, total_tickets_vendus = total_tickets_vendus + ? WHERE id = ?")
                        ->execute([$totalAPayer, $quantite, $session_id]);
                } else {
                    $pdo->prepare("UPDATE pos_sessions SET total_ventes_mobile = total_ventes_mobile + ?, total_tickets_vendus = total_tickets_vendus + ? WHERE id = ?")
                        ->execute([$totalAPayer, $quantite, $session_id]);
                }
            }

            $pdo->commit();

            echo json_encode([
                'success'          => true,
                'order_id'         => $order_id,
                'numero_commande'  => $numCommande,
                'quantite'         => $quantite,
                'total'            => $totalAPayer,
                'montant_recu'     => $montant_recu,
                'monnaie_a_rendre' => $monnaieARendre,
                'mode_paiement'    => $mode_paiement,
                'client_nom'       => $client_nom,
                'tickets'          => $issuedTickets,
                'message'          => 'Billet(s) émis avec succès !'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'UNKNOWN_ACTION', 'message' => 'Action inconnue.']);
            break;
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DATABASE_ERROR', 'message' => $e->getMessage()]);
}
