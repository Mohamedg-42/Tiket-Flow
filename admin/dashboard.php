<?php
// ==============================================================================
// TABLEAU DE BORD DYNAMIQUE ADMINISTRATEUR (admin/dashboard.php)
// Statistiques en temps réel, graphiques interactifs Chart.js et indicateurs clés
// ==============================================================================

$admin_page_title = "Tableau de Bord Dynamique - Administration";
include 'header.php';

// 1. Statistiques globales
$total_users        = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_promoters    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'promoteur'")->fetchColumn();
$total_events       = (int)$pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
$total_tickets_sold = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE statut IN ('vendu', 'utilise')")->fetchColumn();
$total_tickets_used = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE statut = 'utilise'")->fetchColumn();
$total_orders       = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE statut = 'payee'")->fetchColumn();

// 2. Statistiques financières
$total_revenue = (float)$pdo->query("SELECT COALESCE(SUM(montant), 0) FROM payments WHERE statut = 'paye'")->fetchColumn();
$total_commission = (float)$pdo->query("
    SELECT COALESCE(SUM(t.prix * (e.commission_rate / 100)), 0) 
    FROM tickets t 
    JOIN events e ON t.event_id = e.id 
    WHERE t.statut IN ('vendu', 'utilise')
")->fetchColumn();

// 3. Demandes en attente (Badges alertes)
$pending_promoters   = (int)$pdo->query("SELECT COUNT(*) FROM promoter_requests WHERE statut = 'en_attente'")->fetchColumn();
$pending_events      = (int)$pdo->query("SELECT COUNT(*) FROM event_requests WHERE statut = 'en_attente'")->fetchColumn();
$pending_withdrawals = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE statut = 'en_attente'")->fetchColumn();
$pending_claims      = (int)$pdo->query("SELECT COUNT(*) FROM claims WHERE statut = 'en_attente'")->fetchColumn();

// 4. Données pour le graphique des 7 derniers jours
$days_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days_data[$d] = 0;
}
$stmt_days = $pdo->query("
    SELECT DATE(date_paiement) as jour, SUM(montant) as total_jour 
    FROM payments 
    WHERE statut = 'paye' AND date_paiement >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(date_paiement)
");
while ($row = $stmt_days->fetch()) {
    if (isset($days_data[$row['jour']])) {
        $days_data[$row['jour']] = (float)$row['total_jour'];
    }
}
$chart_labels = array_map(function($d) { return date('d/m', strtotime($d)); }, array_keys($days_data));
$chart_values = array_values($days_data);

// 5. Répartition par méthode de paiement
$methods_db = $pdo->query("
    SELECT methode, COALESCE(SUM(montant), 0) as total 
    FROM payments 
    WHERE statut = 'paye' 
    GROUP BY methode
")->fetchAll(PDO::FETCH_KEY_PAIR);

$wave_tot   = (float)($methods_db['wave'] ?? 0);
$orange_tot = (float)($methods_db['orange_money'] ?? 0);
$mtn_tot    = (float)($methods_db['mtn_money'] ?? 0);
$moov_tot   = (float)($methods_db['moov_money'] ?? 0);

// 6. Top événements les plus rentables
$top_events = $pdo->query("
    SELECT e.nom, COUNT(t.id) as tickets_vendus, COALESCE(SUM(t.prix), 0) as recette_totale
    FROM events e
    JOIN tickets t ON t.event_id = e.id
    WHERE t.statut IN ('vendu', 'utilise')
    GROUP BY e.id
    ORDER BY recette_totale DESC
    LIMIT 4
")->fetchAll();

// 7. Dernières commandes
$recent_orders = $pdo->query("
    SELECT o.*, u.nom AS client_nom, u.email AS client_email 
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    ORDER BY o.created_at DESC 
    LIMIT 5
")->fetchAll();
?>

<!-- Inclusion Chart.js pour graphiques animés -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Panneau de Contrôle Temps Réel</span>
        <h1><i class="fa-solid fa-chart-line"></i> Tableau de Bord Dynamique</h1>
        <p>Statistiques financières en direct, graphiques de ventes et gestion des alertes.</p>
    </div>
    <div class="user-info">
        <span class="user-info-icon"><i class="fa-solid fa-user-shield"></i></span>
        <span>
            <small>Administrateur</small>
            <strong><?php echo htmlspecialchars($_SESSION['user_nom'] ?? 'Admin'); ?></strong>
            <em><i class="fa-solid fa-circle" style="color: #10b981;"></i> En direct</em>
        </span>
    </div>
</div>

<!-- 1. Cartes statistiques principales (Design dynamique) -->
<div class="stats-grid">
    <div class="stat-card" style="border-left: 4px solid var(--primary);">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-money-bill-trend-up"></i></div>
        <div class="stat-info">
            <span>Chiffre d'Affaires Global</span>
            <strong style="color: var(--primary); font-size: 1.45rem;"><?php echo number_format($total_revenue, 0, ',', ' '); ?> F</strong>
            <small style="color: #10b981;"><i class="fa-solid fa-arrow-trend-up"></i> Total encaissé</small>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #f59e0b;">
        <div class="stat-icon icon-orange"><i class="fa-solid fa-percent"></i></div>
        <div class="stat-info">
            <span>Commissions Plateforme</span>
            <strong style="color: #d97706; font-size: 1.45rem;"><?php echo number_format($total_commission, 0, ',', ' '); ?> F</strong>
            <small style="color: var(--muted);">Bénéfice net du site</small>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #10b981;">
        <div class="stat-icon icon-green"><i class="fa-solid fa-ticket"></i></div>
        <div class="stat-info">
            <span>Tickets Vendus</span>
            <strong style="font-size: 1.45rem;"><?php echo number_format($total_tickets_sold, 0, ',', ' '); ?></strong>
            <small style="color: #0284c7;"><?php echo $total_tickets_used; ?> déjà compostés (<?php echo ($total_tickets_sold > 0) ? round(($total_tickets_used / $total_tickets_sold) * 100) : 0; ?>%)</small>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #0284c7;">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-calendar-days"></i></div>
        <div class="stat-info">
            <span>Événements Publiés</span>
            <strong style="font-size: 1.45rem;"><?php echo number_format($total_events, 0, ',', ' '); ?></strong>
            <small style="color: var(--muted);"><?php echo $total_promoters; ?> promoteurs actifs</small>
        </div>
    </div>
</div>

<!-- 2. Centre d'Alertes & Actions Urgentes -->
<div class="content-section" style="margin-top: 1.5rem; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);">
    <div class="section-title"><i class="fa-solid fa-bell"></i> Actions Requises & Demandes en Attente</div>
    
    <div class="stats-grid-compact" style="margin-top: 1rem;">
        <a href="demandes-promoteurs.php" class="action-card">
            <div>
                <strong>Candidatures Promoteurs</strong>
                <p style="margin: 0; color: var(--muted); font-size: 0.85rem;">Vérification des pièces d'identité</p>
            </div>
            <span class="badge-pending" style="<?php echo ($pending_promoters > 0) ? 'background:#ef4444;' : 'background:#10b981;'; ?>">
                <?php echo $pending_promoters; ?> en attente
            </span>
        </a>

        <a href="demandes-evenements.php" class="action-card">
            <div>
                <strong>Propositions d'Événements</strong>
                <p style="margin: 0; color: var(--muted); font-size: 0.85rem;">Validation et fixation de commission</p>
            </div>
            <span class="badge-pending" style="<?php echo ($pending_events > 0) ? 'background:#ef4444;' : 'background:#10b981;'; ?>">
                <?php echo $pending_events; ?> en attente
            </span>
        </a>

        <a href="retraits.php" class="action-card">
            <div>
                <strong>Demandes de Retraits</strong>
                <p style="margin: 0; color: var(--muted); font-size: 0.85rem;">Virements Mobile Money à effectuer</p>
            </div>
            <span class="badge-pending" style="<?php echo ($pending_withdrawals > 0) ? 'background:#ef4444;' : 'background:#10b981;'; ?>">
                <?php echo $pending_withdrawals; ?> en attente
            </span>
        </a>

        <a href="reclamations.php" class="action-card">
            <div>
                <strong>Réclamations Support</strong>
                <p style="margin: 0; color: var(--muted); font-size: 0.85rem;">Demandes d'assistance ouvertes</p>
            </div>
            <span class="badge-pending" style="<?php echo ($pending_claims > 0) ? 'background:#ef4444;' : 'background:#10b981;'; ?>">
                <?php echo $pending_claims; ?> en attente
            </span>
        </a>
    </div>
</div>

<!-- 3. Graphiques Dynamiques Chart.js -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
    <!-- Graphique 1 : Évolution des Ventes sur 7 jours -->
    <div class="content-section">
        <div class="section-title"><i class="fa-solid fa-chart-area"></i> Évolution des Recettes (7 Derniers Jours)</div>
        <div style="height: 280px; position: relative; margin-top: 1rem;">
            <canvas id="salesChart"></canvas>
        </div>
    </div>

    <!-- Graphique 2 : Répartition par Méthode Mobile Money -->
    <div class="content-section">
        <div class="section-title"><i class="fa-solid fa-chart-pie"></i> Moyens de Paiement</div>
        <div style="height: 280px; position: relative; margin-top: 1rem; display: flex; align-items: center; justify-content: center;">
            <canvas id="paymentMethodsChart"></canvas>
        </div>
    </div>
</div>

<!-- 4. Top Événements & Dernières Commandes -->
<div style="display: grid; grid-template-columns: 1fr 1.3fr; gap: 1.5rem; margin-top: 1.5rem;">
    <!-- Top Événements -->
    <div class="content-section">
        <div class="section-title"><i class="fa-solid fa-trophy"></i> Top Événements Rentables</div>
        <?php if (count($top_events) > 0): ?>
            <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1rem;">
                <?php foreach ($top_events as $index => $ev): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; background: #f8faf9; padding: 0.85rem 1rem; border-radius: 8px; border: 1px solid var(--line);">
                        <div>
                            <strong style="color: var(--navy); font-size: 0.95rem;">
                                #<?php echo $index + 1; ?> <?php echo htmlspecialchars($ev['nom']); ?>
                            </strong><br>
                            <small style="color: var(--muted);"><?php echo (int)$ev['tickets_vendus']; ?> billets vendus</small>
                        </div>
                        <strong style="color: var(--primary); font-size: 1.05rem;">
                            <?php echo number_format($ev['recette_totale'], 0, ',', ' '); ?> F
                        </strong>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color: var(--muted); margin-top: 1rem;">Aucune vente enregistrée pour le moment.</p>
        <?php endif; ?>
    </div>

    <!-- Dernières Commandes -->
    <div class="content-section">
        <div class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Dernières Commandes</div>
        <div class="table-wrapper" style="margin-top: 0.75rem;">
            <table class="events-table" style="font-size: 0.88rem;">
                <thead>
                    <tr>
                        <th>N° Commande</th>
                        <th>Client</th>
                        <th>Montant</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recent_orders) > 0): ?>
                        <?php foreach ($recent_orders as $ord): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($ord['numero_commande']); ?></strong></td>
                                <td><?php echo htmlspecialchars($ord['client_nom']); ?></td>
                                <td><strong style="color: var(--primary);"><?php echo number_format($ord['montant_total'], 0, ',', ' '); ?> F</strong></td>
                                <td>
                                    <?php if ($ord['statut'] === 'payee'): ?>
                                        <span style="color: #16a34a; font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Payée</span>
                                    <?php else: ?>
                                        <span style="color: #f59e0b; font-weight: bold;"><i class="fa-solid fa-clock"></i> <?php echo ucfirst($ord['statut']); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align: center; color: var(--muted); padding: 1.5rem;">Aucune commande.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Initialisation des Graphiques Dynamiques -->
<script>
    // 1. Graphique d'évolution des recettes
    const ctxSales = document.getElementById('salesChart').getContext('2d');
    new Chart(ctxSales, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Recette (FCFA)',
                data: <?php echo json_encode($chart_values); ?>,
                borderColor: '#0f766e',
                backgroundColor: 'rgba(15, 118, 110, 0.12)',
                borderWidth: 3,
                fill: true,
                tension: 0.35,
                pointBackgroundColor: '#0f766e',
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) { return value.toLocaleString('fr-FR') + ' F'; }
                    }
                }
            }
        }
    });

    // 2. Graphique Donut Moyens de Paiement
    const ctxPayment = document.getElementById('paymentMethodsChart').getContext('2d');
    new Chart(ctxPayment, {
        type: 'doughnut',
        data: {
            labels: ['Wave', 'Orange Money', 'MTN Money', 'Moov Money'],
            datasets: [{
                data: [<?php echo $wave_tot; ?>, <?php echo $orange_tot; ?>, <?php echo $mtn_tot; ?>, <?php echo $moov_tot; ?>],
                backgroundColor: ['#00b4d8', '#f97316', '#eab308', '#22c55e'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 } }
                }
            }
        }
    });
</script>

<?php include 'footer.php'; ?>