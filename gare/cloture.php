<?php
// ==============================================================================
// CLÔTURE DE CAISSE GUICHET (gare/cloture.php)
// Regroupe tous les billets vendus au guichet depuis la dernière clôture et
// génère un relevé de caisse consultable dans admin/gares-ventes.php.
// ==============================================================================

$page_title = "Clôture de Caisse - Espace Guichet";
include 'header.php';

$agent_user_id = (int) $_SESSION['user_id'];
$message = "";
$msg_type = "";

$station_id = null;
$station = null;
if ($_SESSION['user_role'] === 'admin') {
    $stations_admin_choice = $pdo->query("SELECT id, nom FROM stations ORDER BY nom ASC")->fetchAll();
    $station_id = filter_input(INPUT_GET, 'station_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'station_id', FILTER_VALIDATE_INT);
    foreach ($stations_admin_choice as $s) {
        if ((int) $s['id'] === (int) $station_id) {
            $station = $s;
        }
    }
} else {
    $station = $my_station;
    $station_id = $station['id'] ?? null;
}

// Traitement de la clôture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cloturer']) && $station_id) {
    try {
        $pdo->beginTransaction();

        $stmt_pending = $pdo->prepare("
            SELECT id, prix, date_achat FROM tickets
            WHERE station_id = ? AND canal = 'guichet' AND cloture_id IS NULL
            FOR UPDATE
        ");
        $stmt_pending->execute([$station_id]);
        $pending = $stmt_pending->fetchAll();

        if (empty($pending)) {
            $pdo->rollBack();
            $message = "Aucune vente en attente de clôture pour cette gare.";
            $msg_type = "error";
        } else {
            $montant_total = array_sum(array_column($pending, 'prix'));
            $dates = array_column($pending, 'date_achat');
            $periode_debut = min($dates);
            $periode_fin = max($dates);

            $stmt_close = $pdo->prepare("
                INSERT INTO station_cash_closures (station_id, agent_user_id, periode_debut, periode_fin, nombre_tickets, montant_total, statut)
                VALUES (?, ?, ?, ?, ?, ?, 'cloturee')
                RETURNING id
            ");
            $stmt_close->execute([$station_id, $agent_user_id, $periode_debut, $periode_fin, count($pending), $montant_total]);
            $closure_id = $stmt_close->fetchColumn();

            $ids = array_column($pending, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE tickets SET cloture_id = ? WHERE id IN ($in)")->execute(array_merge([$closure_id], $ids));

            $pdo->commit();
            $message = count($pending) . " vente(s) clôturée(s) pour un total de " . number_format($montant_total, 0, ',', ' ') . " FCFA.";
            $msg_type = "success";

            if (function_exists('logActivity')) {
                logActivity('cloture.caisse', 'station', $station_id, "Clôture de " . count($pending) . " vente(s) — " . number_format($montant_total, 0, ',', ' ') . " FCFA", $agent_user_id);
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = friendly_db_error($e, 'cloture_caisse', "Impossible de clôturer la caisse pour le moment. Veuillez vérifier vos opérations et réessayer.");
        $msg_type = "error";
    }
}

// Ventes en attente de clôture
$pending_sales = [];
$pending_total = 0;
if ($station_id) {
    $stmt_p = $pdo->prepare("
        SELECT t.*, e.nom AS event_nom FROM tickets t
        JOIN events e ON t.event_id = e.id
        WHERE t.station_id = ? AND t.canal = 'guichet' AND t.cloture_id IS NULL
        ORDER BY t.date_achat DESC
    ");
    $stmt_p->execute([$station_id]);
    $pending_sales = $stmt_p->fetchAll();
    $pending_total = array_sum(array_column($pending_sales, 'prix'));
}

// Historique des clôtures de cette gare
$my_closures = [];
if ($station_id) {
    $stmt_h = $pdo->prepare("SELECT * FROM station_cash_closures WHERE station_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt_h->execute([$station_id]);
    $my_closures = $stmt_h->fetchAll();
}
?>

<link rel="stylesheet" href="../css/dashboard-pro.css">

<div class="dash-container" style="max-width: 720px; margin: 0 auto; padding: 1.5rem 1rem;">
    <div style="margin-bottom: 1.25rem;">
        <h1 style="font-size: 1.4rem; font-weight: 800; margin: 0 0 4px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-cash-register" style="color: #FF4A0D;"></i> Clôture de Caisse
        </h1>
        <p style="color: #64748B; font-size: 0.88rem; margin: 0;">Regroupez vos ventes du jour en un relevé de caisse unique.</p>
    </div>

    <?php if ($_SESSION['user_role'] === 'admin'): ?>
        <form method="GET" action="cloture.php" style="margin-bottom: 1rem;">
            <select name="station_id" onchange="this.form.submit()" style="width: 100%; padding: 0.65rem; border: 1px solid #E5E5E5; border-radius: 8px; font-weight: 700;">
                <option value="">— Choisir une gare —</option>
                <?php foreach ($stations_admin_choice as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo ((int) $s['id'] === (int) $station_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['nom']); ?></option>
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
            Aucune gare sélectionnée.
        </div>
    <?php else: ?>
        <div style="background: #fff; border-radius: 16px; padding: 1.5rem; margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <div>
                    <h3 style="margin: 0; font-size: 1rem;">Ventes en attente — <?php echo htmlspecialchars($station['nom']); ?></h3>
                    <small style="color: #64748B;"><?php echo count($pending_sales); ?> billet(s) non clôturé(s)</small>
                </div>
                <div style="text-align: right;">
                    <small style="display: block; color: #64748B; text-transform: uppercase; font-size: 0.7rem; font-weight: 700;">Total</small>
                    <strong style="font-size: 1.3rem;"><?php echo number_format($pending_total, 0, ',', ' '); ?> F</strong>
                </div>
            </div>

            <?php if (!empty($pending_sales)): ?>
                <div style="max-height: 260px; overflow-y: auto; border-top: 1px solid #F5F5F5; margin-bottom: 1rem;">
                    <?php foreach ($pending_sales as $t): ?>
                        <div style="display: flex; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid #F5F5F5; font-size: 0.85rem;">
                            <span><?php echo htmlspecialchars($t['event_nom']); ?> — <?php echo htmlspecialchars($t['type_ticket']); ?></span>
                            <strong><?php echo number_format($t['prix'], 0, ',', ' '); ?> F</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form method="POST" action="cloture.php">
                    <input type="hidden" name="action_cloturer" value="1">
                    <input type="hidden" name="station_id" value="<?php echo (int) $station_id; ?>">
                    <button type="submit" style="width: 100%; padding: 0.9rem; background: #FF4A0D; color: #fff; border: none; border-radius: 10px; font-weight: 800; font-size: 0.95rem; cursor: pointer;"
                        onclick="return confirm('Clôturer la caisse pour ces <?php echo count($pending_sales); ?> vente(s) ?')">
                        <i class="fa-solid fa-lock"></i> Clôturer la Caisse
                    </button>
                </form>
            <?php else: ?>
                <p style="color: #64748B; font-size: 0.85rem; text-align: center; padding: 1rem 0;">Aucune vente en attente de clôture.</p>
            <?php endif; ?>
        </div>

        <div style="background: #fff; border-radius: 16px; padding: 1.5rem;">
            <h3 style="margin: 0 0 1rem; font-size: 1rem;">Historique des clôtures</h3>
            <?php if (!empty($my_closures)): ?>
                <?php foreach ($my_closures as $c): ?>
                    <div style="display: flex; justify-content: space-between; padding: 0.65rem 0; border-bottom: 1px solid #F5F5F5; font-size: 0.85rem;">
                        <span><?php echo date('d/m/Y H:i', strtotime($c['created_at'])); ?> — <?php echo (int) $c['nombre_tickets']; ?> billet(s)</span>
                        <strong><?php echo number_format($c['montant_total'], 0, ',', ' '); ?> F</strong>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #64748B; font-size: 0.85rem;">Aucune clôture réalisée pour le moment.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
