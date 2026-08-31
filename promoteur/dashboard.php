<?php
// ==============================================================================
// TABLEAU DE BORD DYNAMIQUE DU PROMOTEUR (promoteur/dashboard.php)
// Statistiques en temps réel, graphiques Chart.js et suivi des ventes
// ==============================================================================

$page_title = "Tableau de Bord - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];

// 1. Calcul des statistiques financières et de billetterie du promoteur
$stats = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT e.id) AS total_events,
        COUNT(t.id) AS total_tickets_sold,
        COALESCE(SUM(t.prix), 0) AS total_ventes_brutes,
        COALESCE(SUM(t.prix * (e.commission_rate / 100)), 0) AS total_commission_prelevee
    FROM events e
    LEFT JOIN tickets t ON t.event_id = e.id AND t.statut IN ('vendu', 'utilise')
    WHERE e.user_id = ?
");
$stats->execute([$user_id]);
$prom_stats = $stats->fetch();

$total_events       = (int)($prom_stats['total_events'] ?? 0);
$total_tickets_sold = (int)($prom_stats['total_tickets_sold'] ?? 0);
$total_ventes       = (float)($prom_stats['total_ventes_brutes'] ?? 0);
$total_comm         = (float)($prom_stats['total_commission_prelevee'] ?? 0);

// 2. Vérification du statut de validation du promoteur
$stmt_check = $pdo->prepare("SELECT est_verifie FROM users WHERE id = ?");
$stmt_check->execute([$user_id]);
$is_verified = (int)$stmt_check->fetchColumn();

// 3. Événements du promoteur avec statistiques détaillées
$stmt_evs = $pdo->prepare("
    SELECT e.*, 
           (SELECT COUNT(*) FROM tickets t WHERE t.event_id = e.id AND t.statut IN ('vendu', 'utilise')) AS vendus,
           (SELECT COALESCE(SUM(t.prix), 0) FROM tickets t WHERE t.event_id = e.id AND t.statut IN ('vendu', 'utilise')) AS recette,
           (SELECT COALESCE(SUM(tt.quantite), 0) FROM ticket_types tt WHERE tt.event_id = e.id) AS capacite_totale
    FROM events e 
    WHERE e.user_id = ? 
    ORDER BY e.date_evenement ASC
");
$stmt_evs->execute([$user_id]);
$my_events = $stmt_evs->fetchAll();

// Préparation des données pour le graphique
$event_names = [];
$event_sales = [];
foreach ($my_events as $ev) {
    $event_names[] = mb_strimwidth($ev['nom'], 0, 18, '...');
    $event_sales[] = (float)$ev['recette'];
}
?>

<!-- Inclusion Chart.js pour graphiques -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker">Espace Promoteur</span>
        <h1><i class="fa-solid fa-chart-pie"></i> Tableau de Bord Organisateur</h1>
        <p>Bienvenue <strong><?php echo htmlspecialchars($_SESSION['user_nom']); ?></strong>. Suivez vos ventes et vos événements en direct.</p>
    </div>
</div>

<!-- Alerte si le compte est en attente d'approbation -->
<?php if ($is_verified === 0): ?>
    <div class="alert alert-error" style="background: #fef3c7; border-color: #fde68a; color: #92400e; margin-bottom: 1.5rem;">
        <i class="fa-solid fa-clock"></i>
        <strong>Dossier d'éligibilité en cours d'examen :</strong>
        Votre compte est actuellement en attente de validation par l'administrateur. Vos événements soumis seront publiés dès votre approbation.
    </div>
<?php endif; ?>

<!-- 1. Cartes de statistiques dynamiques -->
<div class="stats-grid">
    <div class="stat-card" style="border-left: 4px solid #16a34a;">
        <div class="stat-icon icon-green"><i class="fa-solid fa-wallet"></i></div>
        <div class="stat-info">
            <span>Solde Disponible</span>
            <strong style="color: #16a34a; font-size: 1.4rem;"><?php echo number_format($solde_actuel, 0, ',', ' '); ?> F</strong>
            <small style="color: var(--muted);">Prêt pour retrait</small>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid var(--primary);">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-money-bill-wave"></i></div>
        <div class="stat-info">
            <span>Ventes Totales</span>
            <strong style="font-size: 1.4rem;"><?php echo number_format($total_ventes, 0, ',', ' '); ?> F</strong>
            <small style="color: var(--muted);">Chiffre d'affaires brut</small>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #f97316;">
        <div class="stat-icon icon-orange"><i class="fa-solid fa-ticket"></i></div>
        <div class="stat-info">
            <span>Tickets Vendus</span>
            <strong style="font-size: 1.4rem;"><?php echo number_format($total_tickets_sold, 0, ',', ' '); ?></strong>
            <small style="color: #10b981;">Billets écoulés</small>
        </div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #0284c7;">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-calendar-days"></i></div>
        <div class="stat-info">
            <span>Événements Publiés</span>
            <strong style="font-size: 1.4rem;"><?php echo number_format($total_events, 0, ',', ' '); ?></strong>
            <small style="color: var(--muted);">À l'affiche</small>
        </div>
    </div>
</div>

<!-- 2. Actions rapides -->
<div class="content-section" style="margin-top: 1.5rem;">
    <div class="section-title"><i class="fa-solid fa-bolt"></i> Actions Rapides</div>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem;">
        <a href="demande-evenement.php" class="btn-submit" style="width: auto; margin: 0; padding: 0.75rem 1.5rem; text-decoration: none;">
            <i class="fa-solid fa-plus"></i> Proposer un nouvel événement
        </a>
        <a href="solde.php" class="btn-submit" style="width: auto; margin: 0; padding: 0.75rem 1.5rem; text-decoration: none; background: #16a34a;">
            <i class="fa-solid fa-money-bill-transfer"></i> Demander un retrait
        </a>
        <a href="mes-ventes.php" class="btn-submit" style="width: auto; margin: 0; padding: 0.75rem 1.5rem; text-decoration: none; background: #475569;">
            <i class="fa-solid fa-chart-pie"></i> Suivre mes ventes
        </a>
    </div>
</div>

<!-- 3. Graphique dynamique des recettes par événement & Liste -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
    <!-- Graphique Recettes par Événement -->
    <div class="content-section">
        <div class="section-title"><i class="fa-solid fa-chart-column"></i> Recettes par Événement (FCFA)</div>
        <div style="height: 270px; position: relative; margin-top: 1rem;">
            <?php if (count($event_sales) > 0 && max($event_sales) > 0): ?>
                <canvas id="promoterChart"></canvas>
            <?php else: ?>
                <div style="text-align: center; color: var(--muted); padding: 4rem 1rem;">
                    <i class="fa-solid fa-chart-simple" style="font-size: 2.5rem; color: var(--line); margin-bottom: 0.5rem; display: block;"></i>
                    Le graphique s'affichera dès vos premières ventes.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Taux de Remplissage des Événements -->
    <div class="content-section">
        <div class="section-title"><i class="fa-solid fa-users"></i> Taux de Remplissage</div>
        <?php if (count($my_events) > 0): ?>
            <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 1rem;">
                <?php foreach ($my_events as $ev): ?>
                    <?php 
                    $cap = (int)$ev['capacite_totale'];
                    $v   = (int)$ev['vendus'];
                    $pct = ($cap > 0) ? min(100, round(($v / $cap) * 100)) : 0;
                    ?>
                    <div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.88rem; margin-bottom: 4px;">
                            <strong><?php echo htmlspecialchars($ev['nom']); ?></strong>
                            <span><strong><?php echo $v; ?></strong> / <?php echo $cap; ?> billets (<?php echo $pct; ?>%)</span>
                        </div>
                        <div style="background: #e2e8f0; border-radius: 10px; height: 10px; overflow: hidden;">
                            <div style="background: var(--primary); height: 100%; width: <?php echo $pct; ?>%; border-radius: 10px; transition: width 0.5s ease;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color: var(--muted); margin-top: 1rem;">Aucun événement publié pour le moment.</p>
        <?php endif; ?>
    </div>
</div>

<!-- 4. Aperçu de tous mes événements -->
<div class="content-section" style="margin-top: 1.5rem;">
    <div class="section-title" style="display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fa-solid fa-calendar-check"></i> Mes Événements à l'Affiche</span>
        <a href="mes-evenements.php" style="font-size: 0.85rem; color: var(--primary); text-decoration: underline;">Voir tout</a>
    </div>

    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Événement</th>
                    <th>Date</th>
                    <th>Lieu</th>
                    <th>Billets Vendus</th>
                    <th>Recette Brute</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($my_events) > 0): ?>
                    <?php foreach ($my_events as $ev): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($ev['nom']); ?></strong></td>
                            <td><?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?></td>
                            <td><?php echo htmlspecialchars($ev['lieu']); ?></td>
                            <td><strong><?php echo (int)$ev['vendus']; ?></strong> billet(s)</td>
                            <td><strong style="color: var(--primary);"><?php echo number_format($ev['recette'], 0, ',', ' '); ?> F</strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--muted); padding: 2rem;">
                            Vous n'avez pas encore d'événement publié.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (count($event_sales) > 0 && max($event_sales) > 0): ?>
<script>
    const ctxProm = document.getElementById('promoterChart').getContext('2d');
    new Chart(ctxProm, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($event_names); ?>,
            datasets: [{
                label: 'Recette (FCFA)',
                data: <?php echo json_encode($event_sales); ?>,
                backgroundColor: 'rgba(15, 118, 110, 0.75)',
                borderColor: '#0f766e',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(v) { return v.toLocaleString('fr-FR') + ' F'; }
                    }
                }
            }
        }
    });
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>
