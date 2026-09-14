<?php
// ==============================================================================
// VENTE RAPIDE AU GUICHET (gare/vente.php)
// Émission immédiate de billets papier/QR pour un événement, payés en espèces
// sur place. Ventes marquées canal='guichet' pour être suivies séparément des
// ventes web (voir admin/gares-ventes.php).
// ==============================================================================

$page_title = "Vente Rapide - Espace Guichet";
include 'header.php';

$agent_user_id = (int) $_SESSION['user_id'];
$message = "";
$msg_type = "";

// Un admin peut choisir une gare ; un agent de guichet est rattaché à la sienne
$stations_admin_choice = [];
if ($_SESSION['user_role'] === 'admin') {
    $stations_admin_choice = $pdo->query("SELECT id, nom, statut FROM stations ORDER BY nom ASC")->fetchAll();
    $station_id = filter_input(INPUT_GET, 'station_id', FILTER_VALIDATE_INT) ?: (filter_input(INPUT_POST, 'station_id', FILTER_VALIDATE_INT) ?: null);
    $station = null;
    foreach ($stations_admin_choice as $s) {
        if ((int) $s['id'] === (int) $station_id) {
            $station = $s;
            break;
        }
    }
} else {
    $station = $my_station; // défini dans header.php
    $station_id = $station['id'] ?? null;
}

// Événements publics actifs disponibles à la vente au guichet
$events = $pdo->query("
    SELECT e.id, e.nom, e.date_evenement, e.heure, e.lieu
    FROM events e
    WHERE e.statut = 'actif' AND e.visibilite = 'public'
    ORDER BY e.date_evenement ASC
")->fetchAll();

$selected_event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
$ticket_types = [];
if ($selected_event_id) {
    $stmt_tt = $pdo->prepare("SELECT * FROM ticket_types WHERE event_id = ? ORDER BY prix ASC");
    $stmt_tt->execute([$selected_event_id]);
    $ticket_types = $stmt_tt->fetchAll();
}

$last_ticket = null;

// Traitement de la vente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_vendre'])) {
    $ticket_type_id = filter_input(INPUT_POST, 'ticket_type_id', FILTER_VALIDATE_INT);
    $quantite = max(1, (int) ($_POST['quantite'] ?? 1));
    $client_nom = trim($_POST['client_nom'] ?? 'Client Guichet');
    $client_telephone = trim($_POST['client_telephone'] ?? '');

    if (!$station || $station['statut'] !== 'active') {
        $message = "Cette gare n'est pas active. Impossible d'émettre un billet.";
        $msg_type = "error";
    } elseif (!$ticket_type_id) {
        $message = "Veuillez sélectionner un type de billet.";
        $msg_type = "error";
    } else {
        $stmt_ck = $pdo->prepare("SELECT tt.*, e.nom AS event_nom, e.id AS event_id, e.commission_rate, e.user_id AS promoter_user_id, e.date_evenement, e.heure, e.lieu FROM ticket_types tt JOIN events e ON tt.event_id = e.id WHERE tt.id = ?");
        $stmt_ck->execute([$ticket_type_id]);
        $tt = $stmt_ck->fetch();

        $stock_dispo = $tt ? ((int) $tt['quantite'] - (int) $tt['quantite_vendue']) : 0;

        if (!$tt) {
            $message = "Type de billet introuvable.";
            $msg_type = "error";
        } elseif ($quantite > $stock_dispo) {
            $message = "Stock insuffisant (reste $stock_dispo place(s)).";
            $msg_type = "error";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_upd = $pdo->prepare("UPDATE ticket_types SET quantite_vendue = quantite_vendue + ? WHERE id = ? AND quantite_vendue + ? <= quantite");
                $stmt_upd->execute([$quantite, $ticket_type_id, $quantite]);
                if ($stmt_upd->rowCount() !== 1) {
                    throw new Exception("Le stock a changé entre-temps, veuillez réessayer.");
                }

                $stmt_ins = $pdo->prepare("
                    INSERT INTO tickets (
                        event_id, ticket_type_id, client_nom, client_telephone, type_ticket, prix,
                        code_unique, qr_code, statut, date_achat, canal, station_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'vendu', NOW(), 'guichet', ?)
                ");

                $tickets_du_lot = [];
                for ($i = 0; $i < $quantite; $i++) {
                    $code_unique = 'TK-' . strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8));
                    $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($code_unique);
                    $stmt_ins->execute([
                        $tt['event_id'], $ticket_type_id, $client_nom, $client_telephone ?: null,
                        $tt['nom'], $tt['prix'], $code_unique, $qr_code_url, $station['id']
                    ]);
                    $tickets_du_lot[] = ['code_unique' => $code_unique, 'qr_code' => $qr_code_url];
                }

                // Crédit du solde du promoteur (comme pour une vente web)
                if (!empty($tt['promoter_user_id'])) {
                    $comm_rate = (float) ($tt['commission_rate'] ?? 5.0);
                    $gain_net = ((float) $tt['prix'] * $quantite) * (1 - ($comm_rate / 100));
                    $stmt_upd_prom = $pdo->prepare("UPDATE promoters SET solde = solde + ? WHERE user_id = ?");
                    $stmt_upd_prom->execute([$gain_net, $tt['promoter_user_id']]);
                }

                $pdo->commit();

                $last_ticket = [
                    'event_nom' => $tt['event_nom'],
                    'ticket_nom' => $tt['nom'],
                    'quantite' => $quantite,
                    'montant' => (float) $tt['prix'] * $quantite,
                    'tickets' => $tickets_du_lot,
                ];
                $message = "$quantite billet(s) émis avec succès pour « " . htmlspecialchars($tt['event_nom']) . " ».";
                $msg_type = "success";
                $selected_event_id = (int) $tt['event_id'];

                if (function_exists('logActivity')) {
                    logActivity('vente.guichet', 'ticket_type', $ticket_type_id, "$quantite billet(s) vendus au guichet « {$station['nom']} »", $agent_user_id);
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = "Erreur lors de la vente : " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
}
?>

<link rel="stylesheet" href="../css/dashboard-pro.css">

<div class="dash-container" style="max-width: 720px; margin: 0 auto; padding: 1.5rem 1rem;">
    <div style="margin-bottom: 1.25rem;">
        <h1 style="font-size: 1.4rem; font-weight: 800; margin: 0 0 4px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-ticket" style="color: #FF4A0D;"></i> Vente Rapide au Guichet
        </h1>
        <p style="color: #64748B; font-size: 0.88rem; margin: 0;">Émettez un billet immédiatement, payé en espèces sur place.</p>
    </div>

    <?php if ($_SESSION['user_role'] === 'admin'): ?>
        <form method="GET" action="vente.php" style="margin-bottom: 1rem;">
            <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Gare (mode admin)</label>
            <select name="station_id" onchange="this.form.submit()" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; font-weight: 700;">
                <option value="">— Choisir une gare —</option>
                <?php foreach ($stations_admin_choice as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo ((int) $s['id'] === (int) $station_id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['nom']); ?> (<?php echo $s['statut']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>

    <?php if (!empty($message)): ?>
        <div style="padding: 0.85rem 1.25rem; border-radius: 12px; margin-bottom: 1.25rem; font-size: 0.88rem; font-weight: 700; background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#FEE2E2'; ?>; color: #000000; border: 1px solid <?php echo $msg_type === 'success' ? '#FFD9C4' : '#FCA5A5'; ?>;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if (!$station): ?>
        <div style="background: #fff; border-radius: 16px; padding: 2.5rem 1.5rem; text-align: center; color: #64748B;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size: 2rem; color: #FCA5A5; margin-bottom: 0.5rem; display: block;"></i>
            Aucune gare rattachée à votre compte. Contactez un administrateur.
        </div>
    <?php elseif ($station['statut'] !== 'active'): ?>
        <div style="background: #fff; border-radius: 16px; padding: 2.5rem 1.5rem; text-align: center; color: #64748B;">
            <i class="fa-solid fa-ban" style="font-size: 2rem; color: #FCA5A5; margin-bottom: 0.5rem; display: block;"></i>
            La gare « <?php echo htmlspecialchars($station['nom']); ?> » est actuellement <strong><?php echo $station['statut']; ?></strong>.
            Les ventes sont temporairement bloquées par l'administration.
        </div>
    <?php else: ?>

        <?php if ($last_ticket): ?>
            <div style="background: #fff; border: 1px dashed #FF4A0D; border-radius: 16px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
                <h3 style="margin: 0 0 0.75rem; font-size: 1rem;"><i class="fa-solid fa-check-circle" style="color: #16A34A;"></i> Dernière vente</h3>
                <p style="margin: 0 0 0.5rem; font-size: 0.88rem;"><?php echo (int) $last_ticket['quantite']; ?> × <?php echo htmlspecialchars($last_ticket['ticket_nom']); ?> — <?php echo htmlspecialchars($last_ticket['event_nom']); ?></p>
                <p style="margin: 0 0 0.75rem; font-weight: 800;"><?php echo number_format($last_ticket['montant'], 0, ',', ' '); ?> FCFA encaissés</p>
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                    <?php foreach ($last_ticket['tickets'] as $t): ?>
                        <span style="font-family: monospace; font-size: 0.78rem; background: #F5F5F5; padding: 4px 8px; border-radius: 6px;"><?php echo htmlspecialchars($t['code_unique']); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="vente.php" style="background: #fff; border-radius: 16px; padding: 1.5rem;">
            <input type="hidden" name="action_vendre" value="1">
            <input type="hidden" name="station_id" value="<?php echo (int) $station['id']; ?>">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Événement *</label>
                <select name="event_id" required onchange="window.location.href='vente.php?event_id='+this.value+'&station_id=<?php echo (int) $station['id']; ?>'" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; font-weight: 700;">
                    <option value="">— Choisir un événement —</option>
                    <?php foreach ($events as $ev): ?>
                        <option value="<?php echo $ev['id']; ?>" <?php echo ((int) $ev['id'] === (int) $selected_event_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ev['nom']); ?> — <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($selected_event_id && !empty($ticket_types)): ?>
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Type de billet *</label>
                    <select name="ticket_type_id" required style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; font-weight: 700;">
                        <?php foreach ($ticket_types as $tt): ?>
                            <?php $rest = (int) $tt['quantite'] - (int) $tt['quantite_vendue']; ?>
                            <option value="<?php echo $tt['id']; ?>" <?php echo $rest <= 0 ? 'disabled' : ''; ?>>
                                <?php echo htmlspecialchars($tt['nom']); ?> — <?php echo number_format($tt['prix'], 0, ',', ' '); ?> F (<?php echo $rest; ?> restant(s))
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Quantité *</label>
                        <input type="number" name="quantite" value="1" min="1" required style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; box-sizing: border-box;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Téléphone acheteur</label>
                        <input type="tel" name="client_telephone" placeholder="Optionnel" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; box-sizing: border-box;">
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">Nom acheteur</label>
                    <input type="text" name="client_nom" placeholder="Client Guichet" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; box-sizing: border-box;">
                </div>

                <button type="submit" style="width: 100%; padding: 0.9rem; background: #FF4A0D; color: #fff; border: none; border-radius: 10px; font-weight: 800; font-size: 0.95rem; cursor: pointer;">
                    <i class="fa-solid fa-cash-register"></i> Encaisser & Émettre le Billet
                </button>
            <?php elseif ($selected_event_id): ?>
                <p style="color: #64748B; font-size: 0.85rem;">Aucun type de billet configuré pour cet événement.</p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
