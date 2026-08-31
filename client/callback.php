<?php
// ==============================================================================
// CONFIRMATION DU PAIEMENT MOBILE MONEY (client/callback.php)
// Génération des tickets uniques avec QR Code et affichage immédiat
// ==============================================================================

require_once '../config/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: accueil.php');
    exit();
}

$order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$methode  = $_POST['methode'] ?? '';
$methodes_autorisees = ['wave', 'orange_money', 'mtn_money', 'moov_money'];

if (!$order_id || !in_array($methode, $methodes_autorisees, true)) {
    $_SESSION['order_message'] = "Méthode de paiement non reconnue.";
    header('Location: accueil.php');
    exit();
}

// 1. Récupération de la commande en attente
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order || $order['statut'] !== 'en_attente') {
    $_SESSION['order_message'] = 'Cette commande est introuvable ou a déjà été payée.';
    header('Location: accueil.php');
    exit();
}

// Référence unique de paiement et ID de transaction
$reference = 'PAY-' . strtoupper($methode) . '-' . strtoupper(substr(uniqid(), -6));
$transaction_api_id = 'TXN-' . date('YmdHis') . '-' . random_int(1000, 9999);
$user_id = $order['user_id'];
$client_nom = $order['client_nom'] ?: 'Client';
$client_email = $order['client_email'] ?: '';
$client_telephone = $order['client_telephone'] ?: '';

$generated_tickets_list = [];

try {
    $pdo->beginTransaction();

    // 2. Récupération des articles de la commande
    $stmt_items = $pdo->prepare('
        SELECT oi.*, tt.nom AS ticket_nom, tt.prix, tt.event_id, e.nom AS event_name, e.date_evenement, e.heure, e.lieu
        FROM order_items oi 
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id 
        JOIN events e ON tt.event_id = e.id
        WHERE oi.order_id = ?
    ');
    $stmt_items->execute([$order_id]);
    $items = $stmt_items->fetchAll();

    if (!$items || count($items) === 0) {
        throw new Exception('La commande ne contient aucun article.');
    }

    // 3. Enregistrement de la transaction dans 'payments'
    $stmt_pay = $pdo->prepare("
        INSERT INTO payments (order_id, user_id, montant, methode, reference, transaction_id_api, statut, date_paiement) 
        VALUES (?, ?, ?, ?, ?, ?, 'paye', NOW())
    ");
    $stmt_pay->execute([
        $order_id,
        $user_id,
        $order['montant_total'],
        $methode,
        $reference,
        $transaction_api_id
    ]);

    // 4. Préparation des requêtes pour les tickets et la décrémentation de stock
    $stmt_ticket = $pdo->prepare("
        INSERT INTO tickets (order_id, ticket_type_id, event_id, user_id, client_nom, client_email, client_telephone, type_ticket, place_numero, prix, code_unique, qr_code, statut, date_achat) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'vendu', NOW())
    ");

    $stmt_quantity = $pdo->prepare("
        UPDATE ticket_types 
        SET quantite_vendue = quantite_vendue + ? 
        WHERE id = ? AND quantite_vendue + ? <= quantite
    ");

    foreach ($items as $item) {
        $quantity = (int)$item['quantite'];
        $ticket_type_id = (int)$item['ticket_type_id'];
        $event_id = (int)$item['event_id'];

        // Mise à jour du stock
        $stmt_quantity->execute([$quantity, $ticket_type_id, $quantity]);
        if ($stmt_quantity->rowCount() !== 1) {
            throw new Exception("Le stock pour le tarif « " . $item['ticket_nom'] . " » n'est plus suffisant.");
        }

        // Places choisies réparties une par une sur les billets (si option place au choix)
        $places_list = !empty($item['places_numero']) ? array_map('trim', explode(',', $item['places_numero'])) : [];

        // Si aucune place n'a été choisie : attribution automatique de places libres
        // afin que TOUS les billets affichent une place
        if (count($places_list) < $quantity) {
            $stmt_auto = $pdo->prepare("SELECT id, numero FROM places WHERE ticket_type_id = ? AND statut = 'libre' ORDER BY LENGTH(numero) ASC, numero ASC LIMIT " . $quantity);
            $stmt_auto->execute([$ticket_type_id]);
            foreach ($stmt_auto->fetchAll() as $ap) {
                if (count($places_list) >= $quantity) {
                    break;
                }
                if (!in_array($ap['numero'], $places_list, true)) {
                    $places_list[] = $ap['numero'];
                }
            }
        }

        // Génération de chaque billet individuel avec son code unique
        for ($i = 0; $i < $quantity; $i++) {
            $code_unique = 'TK-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($code_unique);
            $place_numero = $places_list[$i] ?? null;

            $stmt_ticket->execute([
                $order_id,
                $ticket_type_id,
                $event_id,
                $user_id,
                $client_nom,
                $client_email,
                $client_telephone,
                $item['ticket_nom'],
                $place_numero,
                $item['prix'],
                $code_unique,
                $qr_code_url
            ]);

            $generated_tickets_list[] = [
                'code_unique' => $code_unique,
                'qr_code'     => $qr_code_url,
                'event_name'  => $item['event_name'],
                'type_ticket' => $item['ticket_nom'],
                'place'       => $place_numero,
                'prix'        => $item['prix'],
                'date_ev'     => $item['date_evenement'],
                'heure'       => $item['heure'],
                'lieu'        => $item['lieu']
            ];
        }

        // Marque toutes les places attribuées comme vendues (choisies ou auto-attribuées)
        // afin qu'elles ne puissent plus être attribuées à une autre personne
        if (!empty($places_list)) {
            $in_nums = implode(',', array_fill(0, count($places_list), '?'));
            $stmt_mark = $pdo->prepare("UPDATE places SET statut = 'vendu' WHERE ticket_type_id = ? AND numero IN ($in_nums) AND statut IN ('libre', 'reserve')");
            $stmt_mark->execute(array_merge([$ticket_type_id], $places_list));
        }

        // 5. Calcul de la commission et crédit du solde du promoteur
        $stmt_ev = $pdo->prepare("SELECT user_id, commission_rate FROM events WHERE id = ?");
        $stmt_ev->execute([$event_id]);
        $ev_info = $stmt_ev->fetch();

        if ($ev_info && !empty($ev_info['user_id'])) {
            $promoter_user_id = (int)$ev_info['user_id'];
            $comm_rate = (float)($ev_info['commission_rate'] ?? 5.00);
            
            $sous_total_item = (float)$item['sous_total'];
            $gain_net_promoteur = $sous_total_item * (1 - ($comm_rate / 100));

            $stmt_upd_prom = $pdo->prepare("UPDATE promoters SET solde = solde + ? WHERE user_id = ?");
            $stmt_upd_prom->execute([$gain_net_promoteur, $promoter_user_id]);
        }
    }

    // 6. Mise à jour de la commande à 'payee'
    $stmt_order_upd = $pdo->prepare("UPDATE orders SET statut = 'payee' WHERE id = ?");
    $stmt_order_upd->execute([$order_id]);

    $pdo->commit();

    // 7. Envoi automatique de la copie des billets par email
    require_once '../includes/mailer.php';
    if (!empty($client_email)) {
        sendTicketEmail($client_email, $client_nom, $order['numero_commande'], $generated_tickets_list, $order_id);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['order_message'] = 'Erreur lors du traitement du paiement : ' . $e->getMessage();
    header('Location: accueil.php');
    exit();
}

$page_title = "Billets & Paiement Confirmé - Ticket Flow";
$body_class = "client-page payment-result-page";
include 'header.php';
?>

<main style="max-width: 960px; margin: 2rem auto; padding: 0 1rem;">
    <!-- En-tête de confirmation -->
    <div style="background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 2.5rem; text-align: center; box-shadow: var(--shadow-lg); margin-bottom: 2rem;">
        <div style="width: 70px; height: 70px; background: #dcfce7; color: #16a34a; border-radius: 50%; display: grid; place-items: center; font-size: 2rem; margin: 0 auto 1.25rem;">
            <i class="fa-solid fa-check"></i>
        </div>

        <span class="page-kicker" style="color: #16a34a;"><i class="fa-solid fa-circle-check"></i> Paiement Réussi avec Succès</span>
        <h1 style="color: var(--navy); margin: 0.2rem 0 0.5rem; font-size: 1.85rem;">Vos Billets sont Prêts !</h1>
        <p style="color: var(--muted); font-size: 0.95rem; max-width: 600px; margin: 0 auto 1.5rem;">
            Merci <strong><?php echo htmlspecialchars($client_nom); ?></strong>. Une copie de confirmation a été adressée à <strong><?php echo htmlspecialchars($client_email); ?></strong>.
        </p>

        <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
            <a href="telecharger-ticket.php?order_id=<?php echo $order_id; ?>" target="_blank" class="btn-submit" style="width: auto; padding: 0.65rem 1.4rem; background: var(--primary); text-decoration: none;">
                <i class="fa-solid fa-file-pdf"></i> Télécharger tous mes Billets (PDF)
            </a>
            <a href="accueil.php" class="btn-submit" style="width: auto; padding: 0.65rem 1.25rem; background: transparent; color: var(--navy); border: 1px solid var(--line); text-decoration: none;">
                Retour à l'accueil
            </a>
        </div>
    </div>

    <!-- Affichage direct de chaque billet avec QR Code et téléchargement individuel -->
    <div class="section-title"><i class="fa-solid fa-qrcode"></i> Vos Billets d'Entrée (<?php echo count($generated_tickets_list); ?>)</div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 3rem;">
        <?php foreach ($generated_tickets_list as $index => $tk): ?>
            <div style="background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-md); display: flex; flex-direction: column;">
                <div style="background: var(--navy); color: #ffffff; padding: 0.85rem 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 700; font-size: 0.9rem;">
                        <i class="fa-solid fa-ticket"></i> Billet #<?php echo $index + 1; ?> - <?php echo htmlspecialchars($tk['type_ticket']); ?>
                    </span>
                    <span style="background: #10b981; color: #ffffff; font-size: 0.72rem; font-weight: 800; padding: 3px 8px; border-radius: 10px;">
                        VALIDE
                    </span>
                </div>

                <div style="padding: 1.5rem; display: flex; flex-direction: column; align-items: center; text-align: center; flex: 1;">
                    <!-- QR Code -->
                    <div style="background: #ffffff; border: 2px dashed #cbd5e1; border-radius: 10px; padding: 0.75rem; margin-bottom: 1rem;">
                        <img src="<?php echo htmlspecialchars($tk['qr_code']); ?>" alt="QR Code" style="width: 160px; height: 160px; display: block;">
                    </div>

                    <!-- Code unique -->
                    <div style="background: #f1f5f9; padding: 0.4rem 1rem; border-radius: 6px; font-family: monospace; font-size: 1.15rem; font-weight: 800; color: var(--navy); margin-bottom: 0.75rem; letter-spacing: 1px;">
                        <?php echo htmlspecialchars($tk['code_unique']); ?>
                    </div>

                    <h3 style="margin: 0 0 0.35rem; color: var(--navy); font-size: 1.15rem;">
                        <?php echo htmlspecialchars($tk['event_name']); ?>
                    </h3>

                    <div style="color: var(--muted); font-size: 0.85rem; margin-bottom: 1rem;">
                        <div><i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($tk['date_ev'])); ?> à <?php echo substr($tk['heure'], 0, 5); ?></div>
                        <div><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($tk['lieu']); ?></div>
                        <?php if (!empty($tk['place'])): ?>
                            <div><i class="fa-solid fa-chair" style="color: var(--primary);"></i> Place : <strong style="color: var(--navy);"><?php echo htmlspecialchars($tk['place']); ?></strong></div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: auto; width: 100%; border-top: 1px solid var(--line-light); padding-top: 1rem; display: flex; flex-direction: column; gap: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--muted);">
                            <span>Titulaire : <strong><?php echo htmlspecialchars($client_nom); ?></strong></span>
                            <strong style="color: var(--primary);"><?php echo number_format($tk['prix'], 0, ',', ' '); ?> F</strong>
                        </div>

                        <?php
                        // Lien de partage WhatsApp du billet
                        $ticket_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') . '/telecharger-ticket.php?code=' . urlencode($tk['code_unique']);
                        $wa_text = "🎟️ Mon billet Ticket Flow\nÉvénement : " . $tk['event_name']
                            . "\nType : " . $tk['type_ticket']
                            . (!empty($tk['place']) ? "\nPlace : " . $tk['place'] : '')
                            . "\nCode : " . $tk['code_unique']
                            . "\nTélécharger le billet : " . $ticket_url;
                        ?>
                        <div style="display: flex; gap: 0.5rem;">
                            <a href="telecharger-ticket.php?code=<?php echo urlencode($tk['code_unique']); ?>" target="_blank" class="btn-submit" style="flex: 1; padding: 0.55rem; font-size: 0.8rem; text-decoration: none;">
                                <i class="fa-solid fa-download"></i> PDF
                            </a>
                            <a href="https://wa.me/?text=<?php echo urlencode($wa_text); ?>" target="_blank" class="btn-submit" style="flex: 1; padding: 0.55rem; font-size: 0.8rem; background: #25D366; text-decoration: none;">
                                <i class="fa-brands fa-whatsapp"></i> WhatsApp
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php include 'footer.php'; ?>
