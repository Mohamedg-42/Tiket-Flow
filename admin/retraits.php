<?php
// ==============================================================================
// GESTION DES DEMANDES DE RETRAIT (admin/retraits.php)
// Design Dashboard Pro - Validation et confirmation des virements Mobile Money aux promoteurs
// ==============================================================================

$admin_page_title = "Gestion des Retraits - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Traitement des actions Administrateur (Payer ou Refuser)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_retrait'])) {
    $withdraw_id = (int)$_POST['withdraw_id'];
    $action_type = $_POST['action_retrait'];

    $stmt_w = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ?");
    $stmt_w->execute([$withdraw_id]);
    $w = $stmt_w->fetch();

    if ($w && $w['statut'] === 'en_attente') {
        $user_id = (int)$w['user_id'];
        $montant = (float)$w['montant'];

        if ($action_type === 'pay') {
            // Confirmation du virement effectué
            $stmt = $pdo->prepare("UPDATE withdrawals SET statut = 'paye', reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$withdraw_id]);

            $message = "Le retrait de " . number_format($montant, 0, ',', ' ') . " FCFA a été marqué comme PAYÉ avec succès.";
            $msg_type = "success";

        } elseif ($action_type === 'reject') {
            $commentaire = trim($_POST['commentaire_admin'] ?? 'Numéro incorrect ou compte non identifiable');

            try {
                $pdo->beginTransaction();

                // Refus de la demande
                $stmt = $pdo->prepare("UPDATE withdrawals SET statut = 'refuse', commentaire_admin = ?, reviewed_at = NOW() WHERE id = ?");
                $stmt->execute([$commentaire, $withdraw_id]);

                // Ré-crédit du solde du promoteur
                $stmt_refund = $pdo->prepare("UPDATE promoters SET solde = solde + ? WHERE user_id = ?");
                $stmt_refund->execute([$montant, $user_id]);

                $pdo->commit();

                $message = "La demande a été refusée et le montant de " . number_format($montant, 0, ',', ' ') . " FCFA a été ré-crédité sur le solde du promoteur.";
                $msg_type = "error";

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Erreur lors du refus : " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
}

// 2. Filtres
$tab = $_GET['tab'] ?? 'en_attente';
if (!in_array($tab, ['en_attente', 'paye', 'refuse', 'tous'], true)) {
    $tab = 'en_attente';
}

$sql = "
    SELECT w.*, u.nom AS promoteur_nom, u.email AS promoteur_email, p.nom_commercial, p.solde AS solde_actuel
    FROM withdrawals w
    JOIN users u ON w.user_id = u.id
    LEFT JOIN promoters p ON w.user_id = p.user_id
";

if ($tab !== 'tous') {
    $sql .= " WHERE w.statut = ?";
    $stmt = $pdo->prepare($sql . " ORDER BY w.created_at DESC");
    $stmt->execute([$tab]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY w.created_at DESC");
}
$withdrawals = $stmt->fetchAll();

// KPIs globaux
$tot_en_attente = (float)$pdo->query("SELECT COALESCE(SUM(montant), 0) FROM withdrawals WHERE statut = 'en_attente'")->fetchColumn();
$nb_en_attente  = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE statut = 'en_attente'")->fetchColumn();
$tot_paye       = (float)$pdo->query("SELECT COALESCE(SUM(montant), 0) FROM withdrawals WHERE statut = 'paye'")->fetchColumn();
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-money-bill-transfer" style="color: #10b981; font-size: 1.55rem;"></i>
                Gestion des Retraits & Virements Promoteurs
            </h1>
            <p>Validez les demandes de transfert de fonds et confirmez les virements Mobile Money aux organisateurs.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES D'ÉTAT ACTIF)
         ============================================================================== -->
    <div style="display: flex; gap: 0.4rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <a href="?tab=en_attente" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'en_attente' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-clock" style="color: #f59e0b;"></i>
            <span>À Traiter</span>
            <?php if ($nb_en_attente > 0): ?>
                <span style="background: #ef4444; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_en_attente; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=paye" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'paye' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
            <span>Virements Effectués</span>
        </a>

        <a href="?tab=refuse" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'refuse' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-ban" style="color: #ef4444;"></i>
            <span>Refusés</span>
        </a>

        <a href="?tab=tous" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'tous' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
            <i class="fa-solid fa-list"></i>
            <span>Historique Complet</span>
        </a>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS DE TRÉSORERIE (AU-DESSOUS DES FILTRES)
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #b45309; text-transform: uppercase;">En Attente de Virement</span>
                <span style="background: #fef3c7; color: #b45309; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-clock"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #b45309;"><?php echo number_format($tot_en_attente, 0, ',', ' '); ?> F</div>
            <small style="color: #b45309; font-size: 0.75rem;"><?php echo $nb_en_attente; ?> demande(s) en file d'attente</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Total Transféré aux Promoteurs</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo number_format($tot_paye, 0, ',', ' '); ?> F</div>
            <small style="color: #16a34a; font-size: 0.75rem;">Fonds effectivement virés avec succès</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES DEMANDES DE RETRAIT
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: #10b981;"></i> Demandes de Retrait (<?php echo count($withdrawals); ?>)
            </h3>
        </div>

        <?php if (empty($withdrawals)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-check-double" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucune demande de retrait dans cette catégorie.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Date Demande</th>
                            <th>Promoteur</th>
                            <th>Montant</th>
                            <th>Moyen de Paiement</th>
                            <th>Solde Actuel</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Action / Traitement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($withdrawals as $w): ?>
                            <?php
                            $is_p = ($w['statut'] === 'en_attente');
                            $op_label = ucfirst(str_replace('_', ' ', $w['moyen_paiement']));
                            $statut_badge = [
                                'en_attente' => ['À payer', '#fef3c7', '#b45309'],
                                'paye'       => ['Payé', '#dcfce7', '#166534'],
                                'refuse'     => ['Refusé', '#fee2e2', '#991b1b']
                            ];
                            [$st_label, $st_bg, $st_fg] = $statut_badge[$w['statut']] ?? ['Inconnu', '#f1f5f9', '#64748b'];
                            ?>
                            <tr>
                                <td>
                                    <span style="color: var(--dash-text); font-weight: 700; font-size: 0.84rem; display: block;">
                                        <?php echo date('d/m/Y', strtotime($w['created_at'])); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo date('H:i', strtotime($w['created_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.9rem; display: block;">
                                        <?php echo htmlspecialchars($w['nom_commercial'] ?: $w['promoteur_nom']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <?php echo htmlspecialchars($w['promoteur_email']); ?>
                                    </small>
                                </td>
                                <td>
                                    <strong style="color: #059669; font-size: 1.05rem; font-weight: 800;">
                                        <?php echo number_format((float)$w['montant'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <span style="font-weight: 700; font-size: 0.84rem; color: var(--dash-text); display: block;">
                                        <i class="fa-solid fa-mobile-screen-button" style="color: #0284c7;"></i> <?php echo htmlspecialchars($op_label); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-family: monospace; font-size: 0.8rem;">
                                        <?php echo htmlspecialchars($w['numero_compte']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; font-weight: 700; color: #475569;">
                                        <?php echo number_format((float)$w['solde_actuel'], 0, ',', ' '); ?> F
                                    </span>
                                </td>
                                <td>
                                    <span style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; padding: 3px 9px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                        <?php echo $st_label; ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($is_p): ?>
                                        <div style="display: inline-flex; gap: 6px;">
                                            <form method="POST" onsubmit="return confirm('Confirmez-vous que le virement Mobile Money a été envoyé avec succès ?');" style="margin: 0;">
                                                <input type="hidden" name="withdraw_id" value="<?php echo $w['id']; ?>">
                                                <input type="hidden" name="action_retrait" value="pay">
                                                <button type="submit" class="dash-btn-action" style="background: #16a34a; color: #ffffff; padding: 0.35rem 0.85rem; font-size: 0.76rem; font-weight: 800;">
                                                    <i class="fa-solid fa-check"></i> Marquer Payé
                                                </button>
                                            </form>

                                            <form method="POST" onsubmit="return confirm('Confirmez-vous le refus ? Le montant sera immédiatement re-crédité sur le solde du promoteur.');" style="margin: 0;">
                                                <input type="hidden" name="withdraw_id" value="<?php echo $w['id']; ?>">
                                                <input type="hidden" name="action_retrait" value="reject">
                                                <button type="submit" class="dash-btn-action" style="background: #fee2e2; color: #ef4444; padding: 0.35rem 0.65rem; font-size: 0.76rem; font-weight: 800;" title="Rejeter et recréditer">
                                                    <i class="fa-solid fa-xmark"></i> Refuser
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <small style="color: var(--dash-muted); font-size: 0.78rem;">
                                            <?php echo !empty($w['reviewed_at']) ? 'Traité le ' . date('d/m/Y', strtotime($w['reviewed_at'])) : 'Archivé'; ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
