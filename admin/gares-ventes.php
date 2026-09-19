<?php
// ==============================================================================
// RAPPORTS DE VENTES GUICHET vs WEB & CLÔTURES DE CAISSE (admin/gares-ventes.php)
// ==============================================================================

$admin_page_title = "Ventes Guichets & Clôtures - Administration";
include 'header.php';

// 1. Comparatif global des ventes par canal (web vs guichet)
$par_canal = $pdo->query("
    SELECT canal, COUNT(*) AS nb_tickets, COALESCE(SUM(prix), 0) AS montant
    FROM tickets
    GROUP BY canal
")->fetchAll();
$canal_stats = ['web' => ['nb_tickets' => 0, 'montant' => 0], 'guichet' => ['nb_tickets' => 0, 'montant' => 0]];
foreach ($par_canal as $row) {
    $canal_stats[$row['canal']] = ['nb_tickets' => (int) $row['nb_tickets'], 'montant' => (float) $row['montant']];
}

// 2. Ventes guichet par gare
$par_gare = $pdo->query("
    SELECT s.id, s.nom, s.statut, COUNT(t.id) AS nb_tickets, COALESCE(SUM(t.prix), 0) AS montant
    FROM stations s
    LEFT JOIN tickets t ON t.station_id = s.id AND t.canal = 'guichet'
    GROUP BY s.id, s.nom, s.statut
    ORDER BY montant DESC
")->fetchAll();

// 3. Historique des clôtures de caisse
$closures = $pdo->query("
    SELECT c.*, s.nom AS station_nom, u.nom AS agent_nom, u.prenom AS agent_prenom
    FROM station_cash_closures c
    JOIN stations s ON c.station_id = s.id
    LEFT JOIN users u ON c.agent_user_id = u.id
    ORDER BY c.created_at DESC
    LIMIT 100
")->fetchAll();

$total_web = $canal_stats['web']['montant'];
$total_guichet = $canal_stats['guichet']['montant'];
$total_global = $total_web + $total_guichet;
$pct_web = $total_global > 0 ? round(($total_web / $total_global) * 100) : 0;
$pct_guichet = 100 - $pct_web;
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<div class="dash-container">
    <div class="dash-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-chart-column" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Ventes Guichets & Clôtures de Caisse
            </h1>
            <p>Comparez les ventes en ligne et au guichet physique, et consultez l'historique des clôtures de caisse
                des gares routières.</p>
        </div>
        <div class="dash-filter-bar">
            <a href="gares" class="dash-btn-action" style="text-decoration: none;">
                <i class="fa-solid fa-bus"></i>
                <span>Gérer les Gares</span>
            </a>
        </div>
    </div>

    <!-- Comparatif Web vs Guichet -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        <div class="dash-card" style="padding: 1.25rem;">
            <small style="color: var(--dash-muted); font-weight: 700; text-transform: uppercase; font-size: 0.72rem;">Ventes Web</small>
            <div style="font-size: 1.5rem; font-weight: 800; color: var(--dash-text); margin: 4px 0;"><?php echo number_format($total_web, 0, ',', ' '); ?> F</div>
            <small style="color: var(--dash-muted);"><?php echo $canal_stats['web']['nb_tickets']; ?> billet(s) — <?php echo $pct_web; ?>%</small>
        </div>
        <div class="dash-card" style="padding: 1.25rem;">
            <small style="color: var(--dash-muted); font-weight: 700; text-transform: uppercase; font-size: 0.72rem;">Ventes Guichet</small>
            <div style="font-size: 1.5rem; font-weight: 800; color: #FF4A0D; margin: 4px 0;"><?php echo number_format($total_guichet, 0, ',', ' '); ?> F</div>
            <small style="color: var(--dash-muted);"><?php echo $canal_stats['guichet']['nb_tickets']; ?> billet(s) — <?php echo $pct_guichet; ?>%</small>
        </div>
        <div class="dash-card" style="padding: 1.25rem;">
            <small style="color: var(--dash-muted); font-weight: 700; text-transform: uppercase; font-size: 0.72rem;">Total Combiné</small>
            <div style="font-size: 1.5rem; font-weight: 800; color: var(--dash-text); margin: 4px 0;"><?php echo number_format($total_global, 0, ',', ' '); ?> F</div>
            <small style="color: var(--dash-muted);"><?php echo $canal_stats['web']['nb_tickets'] + $canal_stats['guichet']['nb_tickets']; ?> billet(s) au total</small>
        </div>
    </div>

    <!-- Ventes par gare -->
    <div class="dash-card" style="margin-bottom: 1.5rem;">
        <div class="dash-card-head">
            <div>
                <h3 class="dash-card-title"><i class="fa-solid fa-bus" style="color: var(--dash-primary);"></i> Ventes par Gare</h3>
                <div class="dash-card-subtitle">Performance de chaque point de vente physique</div>
            </div>
        </div>
        <div class="dash-table-wrapper">
            <table class="dash-pro-table">
                <thead>
                    <tr>
                        <th>Gare</th>
                        <th>Statut</th>
                        <th>Billets vendus</th>
                        <th>Montant</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($par_gare) > 0): ?>
                        <?php foreach ($par_gare as $g): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($g['nom']); ?></strong></td>
                                <td style="text-transform: capitalize; font-size: 0.85rem;"><?php echo htmlspecialchars($g['statut']); ?></td>
                                <td><?php echo (int) $g['nb_tickets']; ?></td>
                                <td><?php echo number_format((float) $g['montant'], 0, ',', ' '); ?> F</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align: center; color: var(--dash-muted); padding: 2rem;">Aucune gare enregistrée.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Historique des clôtures -->
    <div class="dash-card">
        <div class="dash-card-head">
            <div>
                <h3 class="dash-card-title"><i class="fa-solid fa-cash-register" style="color: var(--dash-primary);"></i> Historique des Clôtures de Caisse</h3>
                <div class="dash-card-subtitle">Clôtures réalisées par les agents de guichet</div>
            </div>
        </div>
        <div class="dash-table-wrapper">
            <table class="dash-pro-table">
                <thead>
                    <tr>
                        <th>Gare</th>
                        <th>Agent</th>
                        <th>Période</th>
                        <th>Billets</th>
                        <th>Montant</th>
                        <th>Clôturée le</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($closures) > 0): ?>
                        <?php foreach ($closures as $c): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($c['station_nom']); ?></strong></td>
                                <td style="font-size: 0.85rem;"><?php echo htmlspecialchars(trim(($c['agent_nom'] ?? '') . ' ' . ($c['agent_prenom'] ?? '')) ?: '—'); ?></td>
                                <td style="font-size: 0.78rem; color: var(--dash-muted);">
                                    <?php echo date('d/m H:i', strtotime($c['periode_debut'])); ?> → <?php echo date('d/m H:i', strtotime($c['periode_fin'])); ?>
                                </td>
                                <td><?php echo (int) $c['nombre_tickets']; ?></td>
                                <td><strong><?php echo number_format((float) $c['montant_total'], 0, ',', ' '); ?> F</strong></td>
                                <td style="font-size: 0.78rem; color: var(--dash-muted);"><?php echo date('d/m/Y H:i', strtotime($c['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--dash-muted); padding: 2rem;">Aucune clôture de caisse enregistrée.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
