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
    $withdraw_id = (int) $_POST['withdraw_id'];
    $action_type = $_POST['action_retrait'];

    $stmt_w = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ?");
    $stmt_w->execute([$withdraw_id]);
    $w = $stmt_w->fetch();

    if ($w && $w['statut'] === 'en_attente') {
        $user_id = (int) $w['user_id'];
        $montant = (float) $w['montant'];

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
                if ($pdo->inTransaction())
                    $pdo->rollBack();
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
$tot_en_attente = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM withdrawals WHERE statut = 'en_attente'")->fetchColumn();
$nb_en_attente = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE statut = 'en_attente'")->fetchColumn();
$tot_paye = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM withdrawals WHERE statut = 'paye'")->fetchColumn();

function render_momo_icon($methode, $size = 24) {
    switch ($methode) {
        case 'wave':
            return '<span style="width: '.$size.'px; height: '.$size.'px; border-radius: 6px; background: #FF4A0D; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg viewBox="0 0 24 24" width="'.($size * 0.65).'" height="'.($size * 0.65).'" fill="none" stroke="#ffffff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 11c3-4 6-4 9 0s6 4 9 0"/>
                    <path d="M2 15.5c3-4 6-4 9 0s6 4 9 0" opacity="0.8"/>
                </svg>
            </span>';
        case 'orange_money':
            return '<span style="width: '.$size.'px; height: '.$size.'px; border-radius: 6px; background: #FF4A0D; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg viewBox="0 0 24 24" width="'.($size * 0.72).'" height="'.($size * 0.72).'" fill="none">
                    <rect x="2" y="2" width="20" height="20" rx="4" fill="#000000"/>
                    <rect x="5.5" y="5.5" width="13" height="13" rx="2" fill="#FF4A0D"/>
                    <text x="12" y="15" font-family="Arial, sans-serif" font-size="7.5" font-weight="900" fill="#ffffff" text-anchor="middle">OM</text>
                </svg>
            </span>';
        case 'mtn_money':
            return '<span style="width: '.$size.'px; height: '.$size.'px; border-radius: 6px; background: #FF4A0D; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg viewBox="0 0 24 24" width="'.($size * 0.85).'" height="'.($size * 0.85).'" fill="none">
                    <ellipse cx="12" cy="12" rx="9.5" ry="6.8" stroke="#000000" stroke-width="1.6" fill="#FF4A0D"/>
                    <text x="12" y="14.2" font-family="Arial, sans-serif" font-size="5.6" font-weight="900" fill="#000000" text-anchor="middle" letter-spacing="-0.2">MoMo</text>
                </svg>
            </span>';
        case 'moov_money':
            return '<span style="width: '.$size.'px; height: '.$size.'px; border-radius: 6px; background: #FF4A0D; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg viewBox="0 0 24 24" width="'.($size * 0.85).'" height="'.($size * 0.85).'" fill="none">
                    <circle cx="12" cy="12" r="8.5" fill="#FF4A0D"/>
                    <path d="M7.5 15V9.5l4.5 4 4.5-4V15" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>';
    }
    return '<span style="width: '.$size.'px; height: '.$size.'px; border-radius: 6px; background: #E5E5E5; color: #737373; display: inline-flex; align-items: center; justify-content: center;"><i class="fa-solid fa-wallet" style="font-size: 0.75rem;"></i></span>';
}
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-money-bill-transfer" style="color: #FF4A0D; font-size: 1.55rem;"></i>
                Gestion des Retraits & Virements Promoteurs
            </h1>
            <p>Validez les demandes de transfert de fonds et confirmez les virements Mobile Money aux organisateurs.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div
            style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i
                class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES D'ÉTAT ACTIF)
         ============================================================================== -->
    <div
        style="display: flex; gap: 0.4rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <a href="?tab=en_attente"
            style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'en_attente' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
            <i class="fa-solid fa-clock" style="color: #FF4A0D;"></i>
            <span>À Traiter</span>
            <?php if ($nb_en_attente > 0): ?>
                <span
                    style="background: #000000; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_en_attente; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=paye"
            style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'paye' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
            <i class="fa-solid fa-circle-check" style="color: #FF4A0D;"></i>
            <span>Virements Effectués</span>
        </a>

        <a href="?tab=refuse"
            style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'refuse' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
            <i class="fa-solid fa-ban" style="color: #000000;"></i>
            <span>Refusés</span>
        </a>

        <a href="?tab=tous"
            style="text-decoration: none; border-radius: 9px; padding: 0.45rem 1rem; font-size: 0.83rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $tab === 'tous' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
            <i class="fa-solid fa-list"></i>
            <span>Historique Complet</span>
        </a>

        <!-- Export Excel des retraits -->
        <a href="export.php?type=retraits&tab=<?php echo urlencode($tab); ?>"
            class="dash-btn-action"
            style="margin-left: auto; padding: 0.45rem 0.95rem; font-size: 0.83rem; text-decoration: none;" title="Exporter la liste des retraits sur Excel">
            <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i>
            <span>Exporter Excel</span>
        </a>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS DE TRÉSORERIE (AU-DESSOUS DES FILTRES)
         ============================================================================== -->
    <div
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">En Attente
                    de Virement</span>
                <span
                    style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-clock"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D;">
                <?php echo number_format($tot_en_attente, 0, ',', ' '); ?> F</div>
            <small style="color: #FF4A0D; font-size: 0.75rem;"><?php echo $nb_en_attente; ?> demande(s) en file
                d'attente</small>
        </div>

        <div class="dash-kpi-card"
            style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Total
                    Transféré aux Promoteurs</span>
                <span
                    style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i
                        class="fa-solid fa-circle-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D;">
                <?php echo number_format($tot_paye, 0, ',', ' '); ?> F</div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Fonds effectivement virés avec succès</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES DEMANDES DE RETRAIT
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: #FF4A0D;"></i> Demandes de Retrait
                (<?php echo count($withdrawals); ?>)
            </h3>
        </div>

        <?php if (empty($withdrawals)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-check-double"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
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
                            $methode = $w['methode'] ?? $w['moyen_paiement'] ?? 'wave';
                            $op_label = ucfirst(str_replace('_', ' ', $methode));
                            $tel = $w['numero_telephone'] ?? $w['numero_compte'] ?? '';
                            $statut_badge = [
                                'en_attente' => ['À payer', '#FFF2ED', '#FF4A0D'],
                                'paye' => ['Payé', '#FFF2ED', '#000000'],
                                'refuse' => ['Refusé', '#F5F5F5', '#000000']
                            ];
                            [$st_label, $st_bg, $st_fg] = $statut_badge[$w['statut']] ?? ['Inconnu', '#F5F5F5', '#737373'];
                            ?>
                            <tr>
                                <td>
                                    <span
                                        style="color: var(--dash-text); font-weight: 700; font-size: 0.84rem; display: block;">
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
                                    <strong style="color: #FF4A0D; font-size: 1.05rem; font-weight: 800;">
                                        <?php echo number_format((float) $w['montant'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <div style="display: inline-flex; align-items: center; gap: 8px;">
                                        <?php echo render_momo_icon($methode, 26); ?>
                                        <div>
                                            <strong style="font-weight: 700; font-size: 0.84rem; color: var(--dash-text); display: block;">
                                                <?php echo htmlspecialchars($op_label); ?>
                                            </strong>
                                            <small style="color: var(--dash-muted); font-family: monospace; font-size: 0.8rem;">
                                                <?php echo htmlspecialchars($tel); ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-size: 0.84rem; font-weight: 700; color: #737373;">
                                        <?php echo number_format((float) $w['solde_actuel'], 0, ',', ' '); ?> F
                                    </span>
                                </td>
                                <td>
                                    <span
                                        style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; padding: 3px 9px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">
                                        <?php echo $st_label; ?>
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <?php if ($is_p): ?>
                                        <div
                                            style="display: inline-flex; gap: 6px; align-items: center; justify-content: flex-end; white-space: nowrap;">
                                            <form method="POST"
                                                onsubmit="return confirm('Confirmez-vous que le virement Mobile Money a été envoyé avec succès ?');"
                                                style="margin: 0; display: inline;">
                                                <input type="hidden" name="withdraw_id" value="<?php echo $w['id']; ?>">
                                                <input type="hidden" name="action_retrait" value="pay">
                                                <button type="submit" class="dash-btn-action btn-success"
                                                    style="padding: 0.35rem 0.85rem; font-size: 0.76rem; font-weight: 800;">
                                                    <i class="fa-solid fa-check"></i> Marquer Payé
                                                </button>
                                            </form>

                                            <form method="POST"
                                                onsubmit="return confirm('Confirmez-vous le refus ? Le montant sera immédiatement re-crédité sur le solde du promoteur.');"
                                                style="margin: 0; display: inline;">
                                                <input type="hidden" name="withdraw_id" value="<?php echo $w['id']; ?>">
                                                <input type="hidden" name="action_retrait" value="reject">
                                                <button type="submit" class="dash-btn-action btn-danger"
                                                    style="padding: 0.35rem 0.65rem; font-size: 0.76rem; font-weight: 800;"
                                                    title="Rejeter et recréditer">
                                                    <i class="fa-solid fa-xmark"></i> Refuser
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <small style="color: var(--dash-muted); font-size: 0.78rem; white-space: nowrap;">
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