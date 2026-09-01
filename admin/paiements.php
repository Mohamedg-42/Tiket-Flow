<?php
// ==============================================================================
// GESTION DES PAIEMENTS MOBILE MONEY (admin/paiements.php)
// Design Dashboard Pro - Traçabilité de tous les encaissements (Wave, Orange, MTN, Moov)
// ==============================================================================

$admin_page_title = "Paiements & Encaissements - Administration";
include 'header.php';

// Filtres
$methode_filter = trim($_GET['methode'] ?? '');
$search         = trim($_GET['q'] ?? '');

$sql = "
    SELECT p.*, o.numero_commande, u.nom AS client_nom, u.email AS client_email 
    FROM payments p 
    LEFT JOIN orders o ON o.id = p.order_id 
    LEFT JOIN users u ON u.id = p.user_id 
    WHERE 1=1
";
$params = [];

if (!empty($methode_filter) && in_array($methode_filter, ['wave', 'orange_money', 'mtn_money', 'moov_money'], true)) {
    $sql .= " AND LOWER(p.methode) = ?";
    $params[] = $methode_filter;
}

if (!empty($search)) {
    $sql .= " AND (p.reference LIKE ? OR o.numero_commande LIKE ? OR u.nom LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// KPIs globaux
$tot_encaisse = (float)$pdo->query("SELECT COALESCE(SUM(montant), 0) FROM payments WHERE statut = 'paye'")->fetchColumn();
$tot_transac  = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE statut = 'paye'")->fetchColumn();

// Par méthode
$stmt_m = $pdo->query("
    SELECT LOWER(methode) as m, COALESCE(SUM(montant), 0) as tot 
    FROM payments WHERE statut = 'paye' 
    GROUP BY LOWER(methode)
")->fetchAll(PDO::FETCH_KEY_PAIR);

$tot_wave   = (float)($stmt_m['wave'] ?? 0);
$tot_orange = (float)($stmt_m['orange_money'] ?? 0);
$tot_mtn    = (float)($stmt_m['mtn_money'] ?? 0);
$tot_moov   = (float)($stmt_m['moov_money'] ?? 0);
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-credit-card" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Flux Financiers & Paiements Mobile Money
            </h1>
            <p>Supervisez tous les encaissements instantanés par opérateur Mobile Money (Wave, Orange, MTN, Moov).</p>
        </div>

        <div>
            <a href="retraits.php" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-money-bill-transfer"></i> Gérer les Retraits Promoteurs
            </a>
        </div>
    </div>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES OPÉRATEURS ACTIVES)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES OPÉRATEURS -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?methode=&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $methode_filter === '' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-layer-group" style="<?php echo $methode_filter === '' ? 'color: #2dd4bf;' : ''; ?>"></i> Tous
            </a>

            <a href="?methode=wave&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $methode_filter === 'wave' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <span style="color: #0284c7;">🌊</span> Wave
            </a>

            <a href="?methode=orange_money&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $methode_filter === 'orange_money' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <span style="color: #ea580c;">🍊</span> Orange Money
            </a>

            <a href="?methode=mtn_money&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $methode_filter === 'mtn_money' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <span style="color: #ca8a04;">🟡</span> MTN Money
            </a>

            <a href="?methode=moov_money&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $methode_filter === 'moov_money' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <span style="color: #16a34a;">🟢</span> Moov Money
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form method="GET" action="paiements.php" style="display: inline-flex; gap: 6px; align-items: center; margin: 0;">
            <input type="hidden" name="methode" value="<?php echo htmlspecialchars($methode_filter); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Réf, n° commande, client..." style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 190px; background: #ffffff;">
            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($methode_filter !== '' || $search !== ''): ?>
                <a href="paiements.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS DE PAIEMENT
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Total Brut Encaissé</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-vault"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo number_format($tot_encaisse, 0, ',', ' '); ?> F</div>
            <small style="color: #16a34a; font-size: 0.75rem;"><?php echo number_format($tot_transac, 0, ',', ' '); ?> transactions réussies</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Wave Mobile Money</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;">🌊</span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo number_format($tot_wave, 0, ',', ' '); ?> F</div>
            <small style="color: #0284c7; font-size: 0.75rem;">Encaissés via QR & Wave</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ea580c; text-transform: uppercase;">Orange Money</span>
                <span style="background: #ffedd5; color: #ea580c; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;">🍊</span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ea580c;"><?php echo number_format($tot_orange, 0, ',', ' '); ?> F</div>
            <small style="color: #ea580c; font-size: 0.75rem;">Encaissés via Orange Money</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #ca8a04; text-transform: uppercase;">MTN & Moov</span>
                <span style="background: #fef9c3; color: #ca8a04; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;">📱</span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #ca8a04;"><?php echo number_format($tot_mtn + $tot_moov, 0, ',', ' '); ?> F</div>
            <small style="color: #ca8a04; font-size: 0.75rem;">MTN (<?php echo number_format($tot_mtn, 0, ',', ' '); ?>) · Moov (<?php echo number_format($tot_moov, 0, ',', ' '); ?>)</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES TRANSACTIONS
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Journal des Transactions Mobile Money (<?php echo count($payments); ?>)
            </h3>
        </div>

        <?php if (empty($payments)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-credit-card" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucun paiement ne correspond aux filtres sélectionnés.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Réf. Transaction</th>
                            <th>N° Commande</th>
                            <th>Acheteur</th>
                            <th>Moyen Mobile Money</th>
                            <th>Montant</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Date d'Encaissement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <?php
                            $m_label = ucfirst(str_replace('_', ' ', $p['methode']));
                            ?>
                            <tr>
                                <td>
                                    <strong style="font-family: monospace; font-size: 0.92rem; color: var(--dash-primary);">
                                        <?php echo htmlspecialchars($p['reference']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: var(--dash-text); font-size: 0.85rem;">
                                        #<?php echo htmlspecialchars($p['numero_commande'] ?? '—'); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($p['client_nom'] ?? 'Client'); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($p['client_email'] ?? ''); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-weight: 800; font-size: 0.82rem; color: var(--dash-text);">
                                        <i class="fa-solid fa-mobile-screen-button" style="color: #0284c7;"></i> <?php echo htmlspecialchars($m_label); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #059669; font-size: 0.98rem; font-weight: 800;">
                                        <?php echo number_format($p['montant'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($p['statut'] === 'paye'): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟢 Payé</span>
                                    <?php elseif ($p['statut'] === 'echoue'): ?>
                                        <span style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🔴 Échoué</span>
                                    <?php else: ?>
                                        <span style="background: #fef3c7; color: #b45309; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟡 En attente</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <span style="font-size: 0.82rem; color: var(--dash-muted);">
                                        <?php echo !empty($p['date_paiement']) ? date('d/m/Y H:i', strtotime($p['date_paiement'])) : date('d/m/Y H:i', strtotime($p['created_at'])); ?>
                                    </span>
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
