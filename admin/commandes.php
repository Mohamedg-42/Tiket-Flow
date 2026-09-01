<?php
// ==============================================================================
// GESTION DES COMMANDES (admin/commandes.php)
// Design Dashboard Pro - Traçabilité des paniers, statuts et réservations
// ==============================================================================

$admin_page_title = "Gestion des Commandes - Administration";
include 'header.php';

// Filtres
$statut_f = $_GET['statut'] ?? 'tous';
$search   = trim($_GET['q'] ?? '');

$sql = "
    SELECT o.*, u.nom AS client_nom, u.email AS client_email, u.telephone as client_tel,
           (SELECT COUNT(*) FROM tickets t WHERE t.order_id = o.id) as nb_billets
    FROM orders o 
    LEFT JOIN users u ON u.id = o.user_id 
    WHERE 1=1
";
$params = [];

if ($statut_f === 'payee') {
    $sql .= " AND o.statut = 'payee'";
} elseif ($statut_f === 'en_attente') {
    $sql .= " AND o.statut = 'en_attente'";
} elseif ($statut_f === 'annulee') {
    $sql .= " AND o.statut = 'annulee'";
}

if (!empty($search)) {
    $sql .= " AND (o.numero_commande LIKE ? OR u.nom LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY o.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// KPIs globaux
$tot_orders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$tot_payees = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE statut = 'payee'")->fetchColumn();
$tot_ca     = (float)$pdo->query("SELECT COALESCE(SUM(montant_total), 0) FROM orders WHERE statut = 'payee'")->fetchColumn();
$panier_moyen = ($tot_payees > 0) ? round($tot_ca / $tot_payees) : 0;
?>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-cart-shopping" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Gestion des Commandes Clients
            </h1>
            <p>Supervisez les transactions d'achat de billets et l'état des paniers clients.</p>
        </div>

        <div>
            <a href="paiements.php" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-credit-card"></i> Voir les Flux Mobile Money
            </a>
        </div>
    </div>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES ACTIVES)
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES STATUT -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?statut=tous&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'tous' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $statut_f === 'tous' ? 'color: #2dd4bf;' : ''; ?>"></i> Toutes (<?php echo $tot_orders; ?>)
            </a>

            <a href="?statut=payee&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'payee' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Payées (<?php echo $tot_payees; ?>)
            </a>

            <a href="?statut=en_attente&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'en_attente' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-clock" style="color: #f59e0b;"></i> En attente
            </a>

            <a href="?statut=annulee&q=<?php echo urlencode($search); ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.95rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; <?php echo $statut_f === 'annulee' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-ban" style="color: #ef4444;"></i> Annulées
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form method="GET" action="commandes.php" style="display: inline-flex; gap: 6px; align-items: center; margin: 0;">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($statut_f); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="N° commande, client..." style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 180px; background: #ffffff;">
            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($statut_f !== 'tous' || $search !== ''): ?>
                <a href="commandes.php" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">Commandes Payées</span>
                <span style="background: #dcfce7; color: #16a34a; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #16a34a;"><?php echo number_format($tot_payees, 0, ',', ' '); ?></div>
            <small style="color: #16a34a; font-size: 0.75rem;">Transactions finalisées avec succès</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Volume Financier</span>
                <span style="background: #e0f2fe; color: #0284c7; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-sack-dollar"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7;"><?php echo number_format($tot_ca, 0, ',', ' '); ?> F</div>
            <small style="color: #0284c7; font-size: 0.75rem;">Total encaissé sur les commandes</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #8b5cf6; text-transform: uppercase;">Panier Moyen</span>
                <span style="background: #f5f3ff; color: #8b5cf6; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-receipt"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #8b5cf6;"><?php echo number_format($panier_moyen, 0, ',', ' '); ?> F</div>
            <small style="color: #8b5cf6; font-size: 0.75rem;">Dépense moyenne par commande</small>
        </div>

        <div class="dash-kpi-card" style="padding: 1.15rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total Commandes</span>
                <span style="background: #f1f5f9; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-cart-shopping"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo $tot_orders; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Tous statuts confondus</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES COMMANDES
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Commandes (<?php echo count($orders); ?>)
            </h3>
        </div>

        <?php if (empty($orders)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-cart-shopping" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                Aucune commande ne correspond aux critères de recherche.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>N° Commande</th>
                            <th>Client / Acheteur</th>
                            <th>Billets</th>
                            <th>Montant Total</th>
                            <th>Statut</th>
                            <th>Date de Commande</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td>
                                    <strong style="font-family: monospace; font-size: 0.92rem; color: var(--dash-primary);">
                                        #<?php echo htmlspecialchars($o['numero_commande']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($o['client_nom'] ?? 'Client Anonyme'); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($o['client_email'] ?? '—'); ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-weight: 700; font-size: 0.85rem; color: var(--dash-text);">
                                        <?php echo (int)$o['nb_billets']; ?> billet(s)
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #059669; font-size: 0.98rem; font-weight: 800;">
                                        <?php echo number_format($o['montant_total'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($o['statut'] === 'payee'): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟢 Payée</span>
                                    <?php elseif ($o['statut'] === 'annulee'): ?>
                                        <span style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🔴 Annulée</span>
                                    <?php else: ?>
                                        <span style="background: #fef3c7; color: #b45309; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem;">🟡 En attente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--dash-muted);">
                                        <?php echo !empty($o['created_at']) ? date('d/m/Y H:i', strtotime($o['created_at'])) : '—'; ?>
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
