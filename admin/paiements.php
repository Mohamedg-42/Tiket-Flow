<?php
// ==============================================================================
// GESTION DES PAIEMENTS (admin/paiements.php)
// Suivi de tous les encaissements Mobile Money (Wave, Orange, MTN, Moov)
// ==============================================================================

$admin_page_title = "Historique des Paiements - Administration";
include 'header.php';

// Filtre par méthode
$methode_filter = trim($_GET['methode'] ?? '');

$sql = "
    SELECT p.*, o.numero_commande, u.nom AS client_nom, u.email AS client_email 
    FROM payments p 
    LEFT JOIN orders o ON o.id = p.order_id 
    LEFT JOIN users u ON u.id = p.user_id 
    WHERE 1=1
";
$params = [];

if (!empty($methode_filter) && in_array($methode_filter, ['wave', 'orange_money', 'mtn_money', 'moov_money'], true)) {
    $sql .= " AND p.methode = ?";
    $params[] = $methode_filter;
}

$sql .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Total cumulé
$total_encaisse = array_sum(array_column($payments, 'montant'));
?>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Trésorerie & Encaissements</span>
        <h1>Historique des Paiements</h1>
        <p>Consultez l'ensemble des transactions Mobile Money effectuées sur la plateforme.</p>
    </div>
</div>

<!-- Filtres par méthode -->
<div class="content-section" style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="paiements.php" class="btn-submit" style="width: auto; padding: 0.45rem 1rem; text-decoration: none; font-size: 0.85rem; <?php echo empty($methode_filter) ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
                Toutes les méthodes
            </a>
            <a href="?methode=wave" class="btn-submit" style="width: auto; padding: 0.45rem 1rem; text-decoration: none; font-size: 0.85rem; <?php echo ($methode_filter === 'wave') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
                🌊 Wave
            </a>
            <a href="?methode=orange_money" class="btn-submit" style="width: auto; padding: 0.45rem 1rem; text-decoration: none; font-size: 0.85rem; <?php echo ($methode_filter === 'orange_money') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
                🍊 Orange Money
            </a>
            <a href="?methode=mtn_money" class="btn-submit" style="width: auto; padding: 0.45rem 1rem; text-decoration: none; font-size: 0.85rem; <?php echo ($methode_filter === 'mtn_money') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
                🟡 MTN Money
            </a>
            <a href="?methode=moov_money" class="btn-submit" style="width: auto; padding: 0.45rem 1rem; text-decoration: none; font-size: 0.85rem; <?php echo ($methode_filter === 'moov_money') ? '' : 'background: transparent; color: var(--ink); border: 1px solid var(--line);'; ?>">
                🟢 Moov Money
            </a>
        </div>

        <div style="font-weight: bold; color: var(--primary); font-size: 1.1rem;">
            Total Encaissé : <?php echo number_format($total_encaisse, 0, ',', ' '); ?> FCFA
        </div>
    </div>
</div>

<div class="content-section">
    <div class="section-title">Transactions (<?php echo count($payments); ?>)</div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Réf. Paiement</th>
                    <th>N° Commande</th>
                    <th>Client</th>
                    <th>Moyen</th>
                    <th>Montant</th>
                    <th>Statut</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($payments) > 0): ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <strong style="font-family: monospace; font-size: 0.95rem; color: var(--navy);">
                                    <?php echo htmlspecialchars($p['reference']); ?>
                                </strong>
                            </td>
                            <td>
                                <strong>#<?php echo htmlspecialchars($p['numero_commande'] ?? '-'); ?></strong>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($p['client_nom'] ?? 'Client'); ?><br>
                                <small style="color: var(--muted);"><?php echo htmlspecialchars($p['client_email'] ?? ''); ?></small>
                            </td>
                            <td>
                                <span style="background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 0.78rem; text-transform: uppercase;">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $p['methode'])); ?>
                                </span>
                            </td>
                            <td>
                                <strong style="color: var(--primary); font-size: 1.05rem;">
                                    <?php echo number_format($p['montant'], 0, ',', ' '); ?> F
                                </strong>
                            </td>
                            <td>
                                <?php if ($p['statut'] === 'paye'): ?>
                                    <span style="color: #16a34a; font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Payé</span>
                                <?php else: ?>
                                    <span style="color: #ef4444; font-weight: bold;"><?php echo ucfirst($p['statut']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo date('d/m/Y H:i', strtotime($p['date_paiement'])); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--muted); padding: 2.5rem;">
                            Aucun paiement enregistré pour cette sélection.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
