<?php
// ==============================================================================
// WEBHOOK KADEV PAY (client/webhook-kadevpay.php)
// Réception asynchrone des notifications serveur-à-serveur de Kadev Pay
// Traitement idempotent pour Commandes, Cotisations et Votes
// ==============================================================================

require_once '../config/database.php';
require_once '../config/kadevpay.php';

// Répondre immédiatement en JSON
header('Content-Type: application/json; charset=utf-8');

// Récupération du payload brut
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

// Log technique pour le débogage (peut être consulté si besoin)
error_log("KadevPay Webhook reçu : " . substr($raw_input, 0, 500));

// Extraction des données principales
$event_type     = $payload['event'] ?? $payload['type'] ?? 'payment.success';
$transaction_id = $payload['transaction_id'] ?? $payload['id'] ?? $payload['reference'] ?? '';
$custom_id      = $payload['custom_id'] ?? $payload['metadata']['custom_id'] ?? '';
$status         = strtolower($payload['status'] ?? $payload['data']['status'] ?? '');
$montant        = (float) ($payload['amount'] ?? $payload['data']['amount'] ?? 0);

// Vérification du statut de succès
$is_successful = in_array($status, ['success', 'successful', 'paid', 'approved'], true) 
              || in_array($event_type, ['payment.success', 'charge.success', 'transaction.success'], true);

if (!$is_successful) {
    echo json_encode(['status' => 'ignored', 'message' => 'Événement non finalisé ou ignoré.']);
    exit();
}

// Analyse du custom_id pour router vers le bon module :
// ORDER_123  -> Commande de billetterie
// COT_456    -> Campagne de cotisation
// VOTE_789   -> Vote payant

try {
    if (strpos($custom_id, 'ORDER_') === 0) {
        $order_id = (int) str_replace('ORDER_', '', $custom_id);
        
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if ($order && $order['statut'] === 'en_attente') {
            $pdo->beginTransaction();

            $ref = !empty($transaction_id) ? 'PAY-KADEV-' . $transaction_id : ('PAY-KADEVPAY-' . strtoupper(substr(uniqid(), -6)));
            $stmt_pay = $pdo->prepare("
                INSERT INTO payments (order_id, user_id, montant, methode, reference, transaction_id_api, statut, raw_response, date_paiement) 
                VALUES (?, ?, ?, 'kadevpay', ?, ?, 'paye', ?, NOW())
            ");
            $stmt_pay->execute([
                $order_id,
                $order['user_id'],
                $order['montant_total'],
                $ref,
                $transaction_id,
                $raw_input
            ]);

            // Valider la commande
            $stmt_up = $pdo->prepare("UPDATE orders SET statut = 'paye' WHERE id = ?");
            $stmt_up->execute([$order_id]);

            // Mettre à jour les stocks et tickets si non déjà générés
            $stmt_check_t = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE order_id = ?");
            $stmt_check_t->execute([$order_id]);
            if ((int)$stmt_check_t->fetchColumn() === 0) {
                // Articles
                $stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                $stmt_items->execute([$order_id]);
                $items = $stmt_items->fetchAll();

                $stmt_ticket = $pdo->prepare("
                    INSERT INTO tickets (order_id, ticket_type_id, event_id, user_id, client_nom, client_email, client_telephone, type_ticket, statut, code_unique, qr_code, prix, date_achat) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'vendu', ?, ?, ?, NOW())
                ");
                $stmt_stock = $pdo->prepare("UPDATE ticket_types SET quantite_vendue = quantite_vendue + ? WHERE id = ?");

                foreach ($items as $it) {
                    $qty = (int)$it['quantite'];
                    $stmt_stock->execute([$qty, $it['ticket_type_id']]);
                    for ($i = 0; $i < $qty; $i++) {
                        $code_unique = 'TK-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                        $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($code_unique);
                        $stmt_ticket->execute([
                            $order_id,
                            $it['ticket_type_id'],
                            $it['event_id'],
                            $order['user_id'],
                            $order['client_nom'],
                            $order['client_email'],
                            $order['client_telephone'],
                            'Standard',
                            $code_unique,
                            $qr_code_url,
                            $it['prix_unitaire']
                        ]);
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['status' => 'success', 'module' => 'orders', 'id' => $order_id]);
            exit();
        }

    } elseif (strpos($custom_id, 'COT_') === 0) {
        $cotisation_id = (int) str_replace('COT_', '', $custom_id);

        $stmt = $pdo->prepare("SELECT * FROM cotisations WHERE id = ?");
        $stmt->execute([$cotisation_id]);
        $cot = $stmt->fetch();

        if ($cot && $cot['statut'] === 'en_attente') {
            $pdo->beginTransaction();
            $ref = !empty($transaction_id) ? 'PAY-KADEV-' . $transaction_id : ('PAY-KADEVPAY-' . strtoupper(substr(uniqid(), -6)));

            $stmt_up = $pdo->prepare("
                UPDATE cotisations 
                SET statut = 'valide', methode_paiement = 'kadevpay', reference_paiement = ?, date_paiement = NOW() 
                WHERE id = ?
            ");
            $stmt_up->execute([$ref, $cotisation_id]);

            // Mettre à jour le montant collecté de la campagne
            $stmt_c = $pdo->prepare("UPDATE cotisation_campagnes SET montant_collecte = montant_collecte + ? WHERE id = ?");
            $stmt_c->execute([$cot['montant'], $cot['campagne_id']]);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'module' => 'cotisations', 'id' => $cotisation_id]);
            exit();
        }

    } elseif (strpos($custom_id, 'VOTE_') === 0) {
        $vote_id = (int) str_replace('VOTE_', '', $custom_id);

        $stmt = $pdo->prepare("SELECT * FROM vote_paiements WHERE id = ?");
        $stmt->execute([$vote_id]);
        $vp = $stmt->fetch();

        if ($vp && $vp['statut'] === 'en_attente') {
            $pdo->beginTransaction();
            $ref = !empty($transaction_id) ? 'VOTE-KADEV-' . $transaction_id : ('VOTE-KADEVPAY-' . strtoupper(substr(uniqid(), -6)));

            $stmt_up = $pdo->prepare("
                UPDATE vote_paiements 
                SET statut = 'valide', methode_paiement = 'kadevpay', reference_paiement = ?, date_paiement = NOW() 
                WHERE id = ?
            ");
            $stmt_up->execute([$ref, $vote_id]);

            // Inscrire les votes dans event_votes si non déjà présents
            $stmt_check_v = $pdo->prepare("SELECT COUNT(*) FROM event_votes WHERE paiement_id = ?");
            $stmt_check_v->execute([$vote_id]);
            if ((int)$stmt_check_v->fetchColumn() === 0) {
                $candidats = json_decode($vp['candidats_ids'] ?? '[]', true) ?: [];
                $stmt_vote = $pdo->prepare("
                    INSERT INTO event_votes (event_id, candidat_id, user_id, date_vote, type_vote, paiement_id, ip_address) 
                    VALUES (?, ?, ?, NOW(), 'payant', ?, 'KadevPay-Webhook')
                ");
                foreach ($candidats as $cand_id) {
                    $stmt_vote->execute([$vp['event_id'], (int)$cand_id, $vp['user_id'], $vote_id]);
                }
            }

            $pdo->commit();
            echo json_encode(['status' => 'success', 'module' => 'votes', 'id' => $vote_id]);
            exit();
        }
    }

    echo json_encode(['status' => 'ok', 'message' => 'Déjà traité ou non ciblé.']);
} catch (\Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erreur Webhook KadevPay: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
