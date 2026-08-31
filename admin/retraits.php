<?php
// ==============================================================================
// GESTION DES DEMANDES DE RETRAIT (admin/retraits.php)
// Validation, traitement et confirmation des virements Mobile Money aux promoteurs
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
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Trésorerie & Virements</span>
        <h1>Gestion des Demandes de Retrait</h1>
        <p>Validez et effectuez les virements Mobile Money aux promoteurs partenaires.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Onglets -->
<div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--line); padding-bottom: 0.5rem;">
    <a href="?tab=en_attente" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.9rem; <?php echo ($tab === 'en_attente') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-clock"></i> En Attente
    </a>
    <a href="?tab=paye" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.9rem; <?php echo ($tab === 'paye') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-check"></i> Payés / Virement fait
    </a>
    <a href="?tab=refuse" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.9rem; <?php echo ($tab === 'refuse') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        <i class="fa-solid fa-xmark"></i> Refusés
    </a>
    <a href="?tab=tous" class="btn-submit" style="width: auto; padding: 0.5rem 1rem; text-decoration: none; font-size: 0.9rem; <?php echo ($tab === 'tous') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
        Tous les retraits
    </a>
</div>

<!-- Liste des demandes de retrait -->
<div class="content-section">
    <div class="section-title">Demandes de Retrait (<?php echo count($withdrawals); ?>)</div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Promoteur</th>
                    <th>Montant Demandé</th>
                    <th>Moyen Mobile Money</th>
                    <th>Numéro de compte</th>
                    <th>Date demande</th>
                    <th>Statut</th>
                    <th style="text-align: right;">Action Admin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($withdrawals) > 0): ?>
                    <?php foreach ($withdrawals as $w): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($w['nom_commercial'] ?: $w['promoteur_nom']); ?></strong><br>
                                <small style="color: var(--muted);"><?php echo htmlspecialchars($w['promoteur_email']); ?></small>
                            </td>

                            <td>
                                <strong style="color: var(--primary); font-size: 1.1rem;">
                                    <?php echo number_format($w['montant'], 0, ',', ' '); ?> F
                                </strong>
                            </td>

                            <td>
                                <span style="background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 0.8rem; text-transform: uppercase;">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $w['methode'])); ?>
                                </span>
                            </td>

                            <td>
                                <strong style="font-size: 0.95rem;"><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($w['numero_telephone']); ?></strong>
                            </td>

                            <td>
                                <?php echo date('d/m/Y H:i', strtotime($w['created_at'])); ?>
                            </td>

                            <td>
                                <?php if ($w['statut'] === 'paye'): ?>
                                    <span style="color: #16a34a; font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Payé</span>
                                <?php elseif ($w['statut'] === 'refuse'): ?>
                                    <span style="color: #ef4444; font-weight: bold;"><i class="fa-solid fa-circle-xmark"></i> Refusé</span>
                                <?php else: ?>
                                    <span style="color: #f59e0b; font-weight: bold;"><i class="fa-solid fa-clock"></i> À payer</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($w['statut'] === 'en_attente'): ?>
                                    <form method="POST" style="display: inline-block;">
                                        <input type="hidden" name="withdraw_id" value="<?php echo $w['id']; ?>">
                                        <input type="hidden" name="action_retrait" value="pay">
                                        <button type="submit" class="btn-submit" style="display: inline-block; width: auto; padding: 0.4rem 0.85rem; background: #10b981; font-size: 0.82rem; margin: 0 4px 0 0;" onclick="return confirm('Confirmez-vous que le virement Mobile Money a été effectué au promoteur ?')">
                                            <i class="fa-solid fa-check"></i> Marquer Payé
                                        </button>
                                    </form>

                                    <form method="POST" style="display: inline-block;">
                                        <input type="hidden" name="withdraw_id" value="<?php echo $w['id']; ?>">
                                        <input type="hidden" name="action_retrait" value="reject">
                                        <button type="submit" class="btn-submit" style="display: inline-block; width: auto; padding: 0.4rem 0.85rem; background: #ef4444; font-size: 0.82rem; margin: 0;" onclick="return confirm('Refuser ce retrait et restituer le solde au promoteur ?')">
                                            <i class="fa-solid fa-xmark"></i> Refuser
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <small style="color: var(--muted);">Traité</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--muted); padding: 2.5rem;">
                            Aucune demande de retrait dans cette section.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
