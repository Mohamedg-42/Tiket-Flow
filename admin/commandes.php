<?php
include 'header.php';

$stmt = $pdo->query("SELECT o.*, u.nom AS client_nom, u.email AS client_email FROM orders o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.id DESC");
$orders = $stmt->fetchAll();
?>
<div class="page-header">
    <div class="page-heading"><span class="page-kicker">Ventes</span><h1>Commandes</h1><p>Suivez les réservations et leur état de traitement.</p></div>
</div>
<div class="content-section events-section">
    <div class="section-title">Liste des commandes</div>
    <div class="table-wrapper">
        <table class="events-table">
            <thead><tr><th>Référence</th><th>Client</th><th>Montant</th><th>Statut</th></tr></thead>
            <tbody>
            <?php if ($orders): foreach ($orders as $order): ?>
                <tr>
                    <td class="event-name">#<?php echo htmlspecialchars($order['numero_commande']); ?></td>
                    <td><?php echo htmlspecialchars($order['client_nom'] ?? 'Client'); ?><br><small><?php echo htmlspecialchars($order['client_email'] ?? ''); ?></small></td>
                    <td class="ticket-price"><?php echo number_format($order['montant_total'], 0, ',', ' '); ?> F</td>
                    <td><span class="event-status <?php echo $order['statut'] === 'payee' ? 'status-active' : 'status-inactive'; ?>"><i class="fa-solid fa-circle" aria-hidden="true"></i><?php echo htmlspecialchars($order['statut']); ?></span></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" class="empty-events"><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>Aucune commande.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'footer.php'; ?>
