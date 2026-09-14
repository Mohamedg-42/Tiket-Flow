<?php
// ==============================================================================
// WEBHOOK BICTORYS (client/webhook-bictorys.php)
// Réception asynchrone des notifications serveur-à-serveur de Bictorys
// Traitement idempotent pour Commandes, Cotisations et Votes
// Documentation : https://docs.bictorys.com/docs/integration
// ==============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/bictorys.php';
require_once __DIR__ . '/../includes/whitelist.php';

// Toujours répondre immédiatement en JSON
header('Content-Type: application/json; charset=utf-8');

// 1. Récupération du payload brut
$raw_input = file_get_contents('php://input');
if (empty($raw_input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Corps de requête vide.']);
    exit();
}

$payload = json_decode($raw_input, true);
if (!$payload || !is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'JSON invalide.']);
    exit();
}

// 2. Récupération des en-têtes HTTP
$headers = [];
if (function_exists('getallheaders')) {
    $raw_headers = getallheaders();
    foreach ($raw_headers as $k => $v) {
        $headers[strtolower($k)] = $v;
    }
}
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $header_name = strtolower(str_replace('_', '-', substr($k, 5)));
        if (!isset($headers[$header_name])) {
            $headers[$header_name] = $v;
        }
    }
}

// 3. Vérification de la signature ou de la clé secrète Bictorys
if (!bictorys_verify_webhook($raw_input, $headers)) {
    error_log("Bictorys Webhook: Signature ou clé de sécurité invalide.");
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Signature ou clé webhook non autorisée.']);
    exit();
}

// Log technique du webhook
error_log("Bictorys Webhook reçu : " . substr($raw_input, 0, 500));

// 4. Extraction et normalisation des informations de l'événement
$event_type     = strtolower($payload['event'] ?? $payload['type'] ?? 'payment.success');
$transaction_id = $payload['transactionId'] ?? $payload['transaction_id'] ?? $payload['chargeId'] ?? $payload['id'] ?? '';
$ref_source     = $payload['paymentReference'] ?? $payload['data']['paymentReference'] ?? $payload['custom_id'] ?? $payload['reference'] ?? '';
$status         = strtolower($payload['status'] ?? $payload['data']['status'] ?? '');
$montant        = (float) ($payload['amount'] ?? $payload['data']['amount'] ?? 0);
$channel        = strtolower($payload['payment_type'] ?? $payload['channel'] ?? 'bictorys');

// Succès si statut validé ou type d'événement positif
$is_successful = in_array($status, ['success', 'successful', 'paid', 'approved', 'completed'], true)
              || in_array($event_type, ['charge.success', 'payment.success', 'transaction.success'], true);

if (!$is_successful) {
    echo json_encode(['status' => 'ignored', 'message' => "Statut non finalisé ($status / $event_type)."]);
    exit();
}

// 5. Routage selon la référence (ORDER_..., COT_..., VOTE_...)
try {
    if (strpos($ref_source, 'ORDER_') === 0) {
        $order_id = (int) str_replace('ORDER_', '', $ref_source);

        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if ($order && $order['statut'] === 'en_attente') {
            $pdo->beginTransaction();

            $ref_db = !empty($transaction_id) ? 'PAY-BICTORYS-' . $transaction_id : ('PAY-BICTORYS-' . strtoupper(substr(uniqid(), -8)));
            
            // Enregistrement du paiement
            $stmt_pay = $pdo->prepare("
                INSERT INTO payments (order_id, user_id, montant, methode, reference, transaction_id_api, statut, raw_response, date_paiement) 
                VALUES (?, ?, ?, 'bictorys', ?, ?, 'paye', ?, NOW())
            ");
            $stmt_pay->execute([
                $order_id,
                $order['user_id'],
                $order['montant_total'],
                $ref_db,
                $transaction_id,
                $raw_input
            ]);

            // Valider la commande
            $stmt_up = $pdo->prepare("UPDATE orders SET statut = 'paye', mode_paiement = 'bictorys', updated_at = NOW() WHERE id = ?");
            $stmt_up->execute([$order_id]);

            // Génération des billets si pas déjà générés
            $stmt_check_t = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE order_id = ?");
            $stmt_check_t->execute([$order_id]);
            if ((int) $stmt_check_t->fetchColumn() === 0) {
                $stmt_items = $pdo->prepare("
                    SELECT oi.*, tt.nom AS ticket_nom, tt.prix, tt.event_id, e.nom AS event_name, e.date_evenement, e.heure, e.lieu
                    FROM order_items oi 
                    JOIN ticket_types tt ON tt.id = oi.ticket_type_id 
                    JOIN events e ON tt.event_id = e.id
                    WHERE oi.order_id = ?
                ");
                $stmt_items->execute([$order_id]);
                $items = $stmt_items->fetchAll();

                $stmt_ticket = $pdo->prepare("
                    INSERT INTO tickets (order_id, ticket_type_id, event_id, user_id, client_nom, client_email, client_telephone, type_ticket, place_numero, prix, code_unique, qr_code, statut, date_achat) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'vendu', NOW())
                ");
                $stmt_stock = $pdo->prepare("UPDATE ticket_types SET quantite_vendue = quantite_vendue + ? WHERE id = ?");

                foreach ($items as $item) {
                    $qty = (int) $item['quantite'];
                    $ticket_type_id = (int) $item['ticket_type_id'];
                    $event_id = (int) $item['event_id'];

                    $stmt_stock->execute([$qty, $ticket_type_id]);

                    // Places
                    $places_list = !empty($item['places_numero']) ? array_map('trim', explode(',', $item['places_numero'])) : [];
                    if (count($places_list) < $qty) {
                        $stmt_auto = $pdo->prepare("SELECT numero FROM places WHERE ticket_type_id = ? AND statut = 'libre' ORDER BY LENGTH(numero) ASC, numero ASC LIMIT " . $qty);
                        $stmt_auto->execute([$ticket_type_id]);
                        foreach ($stmt_auto->fetchAll() as $ap) {
                            if (count($places_list) >= $qty) break;
                            if (!in_array($ap['numero'], $places_list, true)) {
                                $places_list[] = $ap['numero'];
                            }
                        }
                    }

                    for ($i = 0; $i < $qty; $i++) {
                        $code_unique = 'TK-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                        $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($code_unique);
                        $place_num = !empty($places_list[$i]) ? mb_substr(trim($places_list[$i]), 0, 20, 'UTF-8') : null;

                        $stmt_ticket->execute([
                            $order_id,
                            $ticket_type_id,
                            $event_id,
                            $order['user_id'],
                            $order['client_nom'],
                            $order['client_email'],
                            $order['client_telephone'],
                            $item['ticket_nom'],
                            $place_num,
                            $item['prix_unitaire'],
                            $code_unique,
                            $qr_code_url
                        ]);
                    }

                    if (!empty($places_list)) {
                        $in_nums = implode(',', array_fill(0, count($places_list), '?'));
                        $stmt_mark = $pdo->prepare("UPDATE places SET statut = 'vendu' WHERE ticket_type_id = ? AND numero IN ($in_nums) AND statut IN ('libre', 'reserve')");
                        $stmt_mark->execute(array_merge([$ticket_type_id], $places_list));
                    }

                    // Décrémenter whitelist invité si événement privé
                    if (!empty($order['client_telephone'])) {
                        $stmt_wl = $pdo->prepare("UPDATE event_guest_whitelist SET tickets_utilises = tickets_utilises + ? WHERE event_id = ? AND telephone = ?");
                        $stmt_wl->execute([$qty, $event_id, normalizePhone($order['client_telephone'])]);
                    }

                    // Commission promoteur
                    $stmt_ev = $pdo->prepare("SELECT user_id, commission_rate FROM events WHERE id = ?");
                    $stmt_ev->execute([$event_id]);
                    $ev_info = $stmt_ev->fetch();
                    if ($ev_info && !empty($ev_info['user_id'])) {
                        $comm_rate = (float) ($ev_info['commission_rate'] ?? 5.00);
                        $gain_net = ((float) $item['sous_total']) * (1 - ($comm_rate / 100));
                        $stmt_prom = $pdo->prepare("UPDATE promoters SET solde = solde + ? WHERE user_id = ?");
                        $stmt_prom->execute([$gain_net, (int) $ev_info['user_id']]);
                    }
                }
            }

            $pdo->commit();

            echo json_encode(['status' => 'success', 'module' => 'orders', 'id' => $order_id]);
            exit();
        }

    } elseif (strpos($ref_source, 'COT_') === 0) {
        $cotisation_id = (int) str_replace('COT_', '', $ref_source);

        $stmt = $pdo->prepare("SELECT * FROM cotisations WHERE id = ?");
        $stmt->execute([$cotisation_id]);
        $cot = $stmt->fetch();

        if ($cot && in_array($cot['statut'], ['en_attente', 'attente'], true)) {
            $pdo->beginTransaction();
            $ref_db = !empty($transaction_id) ? 'PAY-BICTORYS-' . $transaction_id : ('PAY-BICTORYS-' . strtoupper(substr(uniqid(), -8)));

            $stmt_up = $pdo->prepare("
                UPDATE cotisations 
                SET statut = 'payee', methode = 'bictorys', reference = ?, transaction_id_api = ?, date_paiement = NOW() 
                WHERE id = ?
            ");
            $stmt_up->execute([$ref_db, $transaction_id, $cotisation_id]);

            // Crédit de la campagne
            $stmt_c = $pdo->prepare("UPDATE cotisation_campagnes SET montant_collecte = montant_collecte + ? WHERE id = ?");
            $stmt_c->execute([$cot['montant'], $cot['campagne_id']]);

            $pdo->commit();

            echo json_encode(['status' => 'success', 'module' => 'cotisations', 'id' => $cotisation_id]);
            exit();
        }

    } elseif (strpos($ref_source, 'VOTE_') === 0) {
        $vote_id = (int) str_replace('VOTE_', '', $ref_source);

        $stmt = $pdo->prepare("SELECT * FROM vote_paiements WHERE id = ?");
        $stmt->execute([$vote_id]);
        $vp = $stmt->fetch();

        if ($vp && in_array($vp['statut'], ['en_attente', 'attente'], true)) {
            $pdo->beginTransaction();
            $ref_db = !empty($transaction_id) ? 'VOTE-BICTORYS-' . $transaction_id : ('VOTE-BICTORYS-' . strtoupper(substr(uniqid(), -8)));

            $stmt_up = $pdo->prepare("
                UPDATE vote_paiements 
                SET statut = 'paye', methode = 'bictorys', reference = ?, transaction_id_api = ? 
                WHERE id = ?
            ");
            $stmt_up->execute([$ref_db, $transaction_id, $vote_id]);

            // Enregistrement des votes payants
            $stmt_check_v = $pdo->prepare("SELECT COUNT(*) FROM event_votes WHERE paiement_id = ?");
            $stmt_check_v->execute([$vote_id]);
            if ((int) $stmt_check_v->fetchColumn() === 0) {
                $candidats = json_decode($vp['candidats_ids'] ?? '[]', true) ?: [];
                $stmt_vote = $pdo->prepare("
                    INSERT INTO event_votes (event_id, candidat_id, user_id, date_vote, type_vote, paiement_id, ip_address) 
                    VALUES (?, ?, ?, NOW(), 'payant', ?, 'Bictorys-Webhook')
                ");
                foreach ($candidats as $cand_id) {
                    $stmt_vote->execute([$vp['event_id'], (int) $cand_id, $vp['user_id'], $vote_id]);
                }
            }

            $pdo->commit();

            echo json_encode(['status' => 'success', 'module' => 'votes', 'id' => $vote_id]);
            exit();
        }
    }

    echo json_encode(['status' => 'ok', 'message' => 'Déjà traité ou référence non reconnue.']);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erreur Webhook Bictorys: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

