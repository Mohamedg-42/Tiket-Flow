<?php
// Redirection transparente vers le Tableau de Bord complet
header('Location: dashboard.php');
exit();

// ── 1. KPIs globaux ──────────────────────────────────────────────────────────
$stmt_kpi = $pdo->prepare("
    SELECT
        COUNT(DISTINCT e.id)                                          AS nb_events,
        COUNT(t.id)                                                   AS nb_tickets_vendus,
        COALESCE(SUM(t.prix), 0)                                      AS ventes_brutes,
        COALESCE(SUM(t.prix * e.commission_rate / 100), 0)            AS total_commission,
        COALESCE(SUM(t.prix * (1 - e.commission_rate / 100)), 0)      AS gains_nets,
        COUNT(CASE WHEN t.statut = 'utilise' THEN 1 END)              AS tickets_utilises
    FROM events e
    LEFT JOIN tickets t ON t.event_id = e.id AND t.statut IN ('vendu','utilise')
    WHERE e.user_id = ?
");
$stmt_kpi->execute([$user_id]);
$kpi = $stmt_kpi->fetch();

// ── 2. Données par événement ──────────────────────────────────────────────────
$stmt_ev = $pdo->prepare("
    SELECT
        e.id, e.nom, e.date_evenement, e.heure, e.lieu, e.categorie,
        e.statut, e.commission_rate,
        COALESCE(SUM(CASE WHEN t.statut IN ('vendu','utilise') THEN 1 ELSE 0 END), 0)      AS tickets_vendus,
        COALESCE(SUM(CASE WHEN t.statut = 'utilise' THEN 1 ELSE 0 END), 0)                 AS tickets_utilises,
        COALESCE(SUM(CASE WHEN t.statut IN ('vendu','utilise') THEN t.prix ELSE 0 END), 0) AS recette_brute,
        COALESCE(SUM(tt2.quantite), 0)                                                     AS capacite_totale
    FROM events e
    LEFT JOIN tickets t  ON t.event_id = e.id
    LEFT JOIN ticket_types tt2 ON tt2.event_id = e.id
    WHERE e.user_id = ?
    GROUP BY e.id
    ORDER BY e.date_evenement DESC
");
$stmt_ev->execute([$user_id]);
$events_data = $stmt_ev->fetchAll();

// ── 3. Détail par type de ticket (tous événements) ────────────────────────────
$stmt_tt = $pdo->prepare("
    SELECT
        tt.id, tt.nom AS type_nom, tt.prix, tt.quantite, tt.quantite_vendue,
        e.nom AS event_nom, e.id AS event_id,
        COALESCE(SUM(CASE WHEN t.statut IN ('vendu','utilise') THEN t.prix ELSE 0 END), 0) AS recette_type,
        COUNT(CASE WHEN t.statut = 'utilise' THEN 1 END)                                   AS utilises
    FROM ticket_types tt
    JOIN events e ON tt.event_id = e.id
    LEFT JOIN tickets t ON t.ticket_type_id = tt.id AND t.statut IN ('vendu','utilise')
    WHERE e.user_id = ?
    GROUP BY tt.id
    ORDER BY e.date_evenement DESC, tt.prix DESC
");
$stmt_tt->execute([$user_id]);
$types_data = $stmt_tt->fetchAll();

// ── 4. Ventes des 14 derniers jours (courbe) ──────────────────────────────────
$stmt_trend = $pdo->prepare("
    SELECT DATE(t.date_achat) AS jour, COUNT(*) AS nb, COALESCE(SUM(t.prix), 0) AS montant
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu','utilise')
      AND t.date_achat >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY DATE(t.date_achat)
    ORDER BY jour ASC
");
$stmt_trend->execute([$user_id]);
$trend_raw = $stmt_trend->fetchAll(PDO::FETCH_KEY_PAIR);  // jour => nb  (only 2 cols)

// Reconstruire avec montant aussi
$stmt_trend2 = $pdo->prepare("
    SELECT DATE(t.date_achat) AS jour, COUNT(*) AS nb, COALESCE(SUM(t.prix), 0) AS montant
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu','utilise')
      AND t.date_achat >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY DATE(t.date_achat)
    ORDER BY jour ASC
");
$stmt_trend2->execute([$user_id]);
$trend_rows = $stmt_trend2->fetchAll();

// Remplir les 14 jours (même sans ventes)
$trend_labels  = [];
$trend_tickets = [];
$trend_montant = [];
$trend_index   = [];
foreach ($trend_rows as $r) { $trend_index[$r['jour']] = $r; }
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $trend_labels[]  = date('d/m', strtotime($day));
    $trend_tickets[] = isset($trend_index[$day]) ? (int)$trend_index[$day]['nb'] : 0;
    $trend_montant[] = isset($trend_index[$day]) ? (float)$trend_index[$day]['montant'] : 0;
}

// ── 5. Répartition Mobile Money ───────────────────────────────────────────────
$stmt_mm = $pdo->prepare("
    SELECT p.methode, COUNT(*) AS nb, COALESCE(SUM(p.montant), 0) AS total
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    JOIN order_items oi ON oi.order_id = o.id
    JOIN ticket_types tt ON oi.ticket_type_id = tt.id
    JOIN events e ON tt.event_id = e.id
    WHERE e.user_id = ? AND p.statut = 'paye'
    GROUP BY p.methode
");
$stmt_mm->execute([$user_id]);
$mm_data = $stmt_mm->fetchAll();

// Données pour Chart.js
$ev_labels   = array_map(fn($e) => mb_strimwidth($e['nom'], 0, 20, '…'), $events_data);
$ev_recettes = array_column($events_data, 'recette_brute');
$ev_vendus   = array_column($events_data, 'tickets_vendus');

$mm_labels = array_map(fn($r) => strtoupper(str_replace('_', ' ', $r['methode'])), $mm_data);
$mm_totals = array_column($mm_data, 'total');
$mm_colors = ['#0284c7','#ea580c','#ca8a04','#16a34a'];
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:1rem; margin-bottom:2rem; }
    .kpi-card {
        background:#fff; border:1px solid var(--line); border-radius:14px;
        padding:1.25rem 1.1rem; display:flex; flex-direction:column; gap:.25rem;
        box-shadow:0 1px 4px rgba(0,0,0,.04); transition:.2s;
    }
    .kpi-card:hover { transform:translateY(-3px); box-shadow:0 6px 20px rgba(0,0,0,.07); }
    .kpi-icon { font-size:1.4rem; margin-bottom:.2rem; }
    .kpi-label { font-size:.75rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
    .kpi-value { font-size:1.5rem; font-weight:900; color:var(--navy); line-height:1; }
    .kpi-sub   { font-size:.74rem; color:var(--muted); }

    .charts-row { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem; }
    .chart-box  { background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.25rem; }
    .chart-title{ font-size:.9rem; font-weight:800; color:var(--navy); margin-bottom:1rem; display:flex; align-items:center; gap:.45rem; }

    .ev-row { border-bottom:1px solid var(--line-light); }
    .ev-row:last-child { border-bottom:none; }
    .ev-header { display:grid; grid-template-columns:3fr 1fr 1fr 1fr 1fr 80px; gap:.5rem;
                 align-items:center; padding:.9rem 1rem; cursor:pointer; transition:.15s; }
    .ev-header:hover { background:#f8fafc; border-radius:8px; }
    .ev-detail  { display:none; background:#f8fafc; border-radius:0 0 8px 8px; overflow:hidden; }
    .ev-detail.open { display:block; }
    .ev-detail table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .ev-detail th { background:#f1f5f9; font-size:.75rem; color:var(--muted); font-weight:700;
                    padding:.5rem .9rem; text-align:left; text-transform:uppercase; }
    .ev-detail td { padding:.6rem .9rem; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
    .ev-detail tr:last-child td { border-bottom:none; }

    .statut-badge { font-size:.7rem; font-weight:800; padding:2px 8px; border-radius:6px; }
    .statut-actif   { background:#dcfce7; color:#15803d; }
    .statut-termine { background:#f1f5f9; color:#475569; }
    .statut-annule  { background:#fee2e2; color:#b91c1c; }

    .progress-sm { height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden; }
    .progress-sm div { height:100%; border-radius:3px; }

    .mm-row { display:flex; align-items:center; gap:.75rem; padding:.55rem 0; border-bottom:1px solid var(--line-light); }
    .mm-row:last-child { border-bottom:none; }
</style>

<!-- En-tête -->
<div class="page-header">
    <div class="page-heading">
        <span class="page-kicker"><i class="fa-solid fa-chart-pie"></i> Analytiques</span>
        <h1>Tableau de Bord des Ventes</h1>
        <p>Vue complète de vos ventes par événement, par type de ticket et par moyen de paiement.</p>
    </div>
</div>

<!-- ── KPIs ─────────────────────────────────────────────────────────────────── -->
<div class="kpi-grid">
    <div class="kpi-card" style="border-top:3px solid #0f766e;">
        <div class="kpi-icon">💰</div>
        <div class="kpi-label">Ventes Brutes</div>
        <div class="kpi-value"><?php echo number_format($kpi['ventes_brutes'], 0, ',', ' '); ?> <small style="font-size:.9rem;">F</small></div>
        <div class="kpi-sub">Chiffre d'affaires total</div>
    </div>
    <div class="kpi-card" style="border-top:3px solid #16a34a;">
        <div class="kpi-icon">✅</div>
        <div class="kpi-label">Gains Nets</div>
        <div class="kpi-value" style="color:#16a34a;"><?php echo number_format($kpi['gains_nets'], 0, ',', ' '); ?> <small style="font-size:.9rem;">F</small></div>
        <div class="kpi-sub">Après commission plateforme</div>
    </div>
    <div class="kpi-card" style="border-top:3px solid #f59e0b;">
        <div class="kpi-icon">📊</div>
        <div class="kpi-label">Commission Plateforme</div>
        <div class="kpi-value" style="color:#d97706;"><?php echo number_format($kpi['total_commission'], 0, ',', ' '); ?> <small style="font-size:.9rem;">F</small></div>
        <div class="kpi-sub">Frais de service prélevés</div>
    </div>
    <div class="kpi-card" style="border-top:3px solid #0284c7;">
        <div class="kpi-icon">🎟️</div>
        <div class="kpi-label">Tickets Vendus</div>
        <div class="kpi-value"><?php echo number_format($kpi['nb_tickets_vendus'], 0, ',', ' '); ?></div>
        <div class="kpi-sub"><?php echo $kpi['tickets_utilises']; ?> déjà utilisés</div>
    </div>
    <div class="kpi-card" style="border-top:3px solid #8b5cf6;">
        <div class="kpi-icon">📅</div>
        <div class="kpi-label">Événements</div>
        <div class="kpi-value"><?php echo (int)$kpi['nb_events']; ?></div>
        <div class="kpi-sub">Publiés sur la plateforme</div>
    </div>
    <div class="kpi-card" style="border-top:3px solid #10b981;">
        <div class="kpi-icon">💼</div>
        <div class="kpi-label">Solde Disponible</div>
        <div class="kpi-value" style="color:#10b981;"><?php echo number_format($solde_actuel, 0, ',', ' '); ?> <small style="font-size:.9rem;">F</small></div>
        <div class="kpi-sub"><a href="solde.php" style="color:var(--primary); font-weight:700;">Retirer →</a></div>
    </div>
</div>

<!-- ── Graphiques ────────────────────────────────────────────────────────────── -->
<div class="charts-row">
    <!-- Courbe sur 14 jours -->
    <div class="chart-box">
        <div class="chart-title"><i class="fa-solid fa-chart-line" style="color:var(--primary);"></i> Ventes — 14 derniers jours</div>
        <div style="height:200px; position:relative;">
            <canvas id="chartTrend"></canvas>
        </div>
    </div>
    <!-- Barres recettes par événement -->
    <div class="chart-box">
        <div class="chart-title"><i class="fa-solid fa-chart-column" style="color:#0284c7;"></i> Recettes par Événement (FCFA)</div>
        <div style="height:200px; position:relative;">
            <?php if (count($ev_recettes) > 0 && max($ev_recettes) > 0): ?>
                <canvas id="chartEvents"></canvas>
            <?php else: ?>
                <div style="text-align:center; color:var(--muted); padding:4rem 1rem;">Aucune vente enregistrée.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="charts-row">
    <!-- Tickets vendus par événement -->
    <div class="chart-box">
        <div class="chart-title"><i class="fa-solid fa-ticket" style="color:#f59e0b;"></i> Tickets Vendus par Événement</div>
        <div style="height:200px; position:relative;">
            <?php if (count($ev_vendus) > 0 && max($ev_vendus) > 0): ?>
                <canvas id="chartTickets"></canvas>
            <?php else: ?>
                <div style="text-align:center; color:var(--muted); padding:4rem 1rem;">Aucune vente enregistrée.</div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Donut Mobile Money -->
    <div class="chart-box">
        <div class="chart-title"><i class="fa-solid fa-mobile-screen-button" style="color:#16a34a;"></i> Répartition par Opérateur Mobile Money</div>
        <?php if (count($mm_data) > 0): ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; align-items:center;">
            <div style="height:180px; position:relative;">
                <canvas id="chartMM"></canvas>
            </div>
            <div>
                <?php foreach ($mm_data as $i => $mm): ?>
                <div class="mm-row">
                    <span style="width:12px;height:12px;border-radius:3px;background:<?php echo $mm_colors[$i % 4]; ?>;flex-shrink:0;display:inline-block;"></span>
                    <div style="flex:1;">
                        <div style="font-size:.8rem; font-weight:700; color:var(--navy);"><?php echo strtoupper(str_replace('_',' ',$mm['methode'])); ?></div>
                        <div style="font-size:.75rem; color:var(--muted);"><?php echo $mm['nb']; ?> paiement<?php echo $mm['nb']>1?'s':''; ?> · <?php echo number_format($mm['total'],0,',',' '); ?> F</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
            <div style="text-align:center; color:var(--muted); padding:3rem 1rem;">Aucun paiement enregistré.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Détail par Événement (accordéon) ──────────────────────────────────────── -->
<div class="content-section" style="margin-top:.5rem;">
    <div class="section-title">
        <i class="fa-solid fa-calendar-days"></i> Détail des Ventes par Événement
        <small style="font-weight:500; color:var(--muted); margin-left:.4rem;">Cliquez sur un événement pour voir le détail par type de ticket</small>
    </div>

    <?php if (count($events_data) === 0): ?>
        <div style="text-align:center; color:var(--muted); padding:3rem;">Aucun événement publié.</div>
    <?php else: ?>

    <!-- En-tête du tableau -->
    <div style="display:grid; grid-template-columns:3fr 1fr 1fr 1fr 1fr 80px; gap:.5rem;
                padding:.6rem 1rem; background:#f1f5f9; border-radius:8px 8px 0 0;
                font-size:.75rem; font-weight:800; color:var(--muted); text-transform:uppercase;">
        <div>Événement</div>
        <div style="text-align:center;">Vendus</div>
        <div style="text-align:center;">Recette Brute</div>
        <div style="text-align:center;">Gains Nets</div>
        <div style="text-align:center;">Remplissage</div>
        <div></div>
    </div>

    <div style="border:1px solid var(--line); border-top:none; border-radius:0 0 12px 12px; overflow:hidden;">
    <?php foreach ($events_data as $i => $ev):
        $cap      = (int)$ev['capacite_totale'];
        $vendus   = (int)$ev['tickets_vendus'];
        $utilises = (int)$ev['tickets_utilises'];
        $pct      = ($cap > 0) ? min(100, round(($vendus / $cap) * 100)) : 0;
        $gain_net = (float)$ev['recette_brute'] * (1 - (float)$ev['commission_rate'] / 100);
        $pct_color = $pct >= 80 ? '#ef4444' : ($pct >= 50 ? '#f59e0b' : 'var(--primary)');

        // Types de tickets de cet événement
        $ev_types = array_filter($types_data, fn($t) => (int)$t['event_id'] === (int)$ev['id']);
    ?>
    <div class="ev-row">
        <div class="ev-header" onclick="toggleDetail(<?php echo $i; ?>)">
            <div>
                <strong style="color:var(--navy); font-size:.92rem;"><?php echo htmlspecialchars($ev['nom']); ?></strong><br>
                <small style="color:var(--muted);">
                    <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                    &nbsp;·&nbsp; <i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars(mb_strimwidth($ev['lieu'], 0, 30, '…')); ?>
                </small>
                &nbsp;
                <span class="statut-badge statut-<?php echo $ev['statut']; ?>">
                    <?php echo strtoupper($ev['statut']); ?>
                </span>
            </div>
            <div style="text-align:center;">
                <strong style="color:#0284c7; font-size:1.1rem;"><?php echo $vendus; ?></strong><br>
                <small style="color:var(--muted);"><?php echo $utilises; ?> utilisés</small>
            </div>
            <div style="text-align:center;">
                <strong style="color:var(--primary);"><?php echo number_format($ev['recette_brute'], 0, ',', ' '); ?> F</strong>
            </div>
            <div style="text-align:center;">
                <strong style="color:#16a34a;"><?php echo number_format($gain_net, 0, ',', ' '); ?> F</strong>
                <small style="color:var(--muted); display:block;"><?php echo (float)$ev['commission_rate']; ?>% comm.</small>
            </div>
            <div>
                <div style="display:flex; justify-content:center; align-items:center; gap:.4rem;">
                    <div class="progress-sm" style="flex:1;">
                        <div style="width:<?php echo $pct; ?>%; background:<?php echo $pct_color; ?>;"></div>
                    </div>
                    <span style="font-size:.78rem; font-weight:800; color:<?php echo $pct_color; ?>; white-space:nowrap;"><?php echo $pct; ?>%</span>
                </div>
                <small style="color:var(--muted); font-size:.72rem; display:block; text-align:center; margin-top:2px;"><?php echo $vendus; ?> / <?php echo $cap; ?> places</small>
            </div>
            <div style="text-align:right;">
                <i class="fa-solid fa-chevron-down" id="chevron-<?php echo $i; ?>" style="color:var(--muted); transition:.25s;"></i>
            </div>
        </div>

        <!-- Détail par type de ticket -->
        <div class="ev-detail" id="detail-<?php echo $i; ?>">
            <?php if (count($ev_types) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <th>Prix Unitaire</th>
                        <th>Vendus / Capacité</th>
                        <th>Restants</th>
                        <th>Recette Catégorie</th>
                        <th>Gain Net Catégorie</th>
                        <th>Scannés / Entrés</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ev_types as $tt):
                        $tt_vendus  = (int)$tt['quantite_vendue'];
                        $tt_total   = (int)$tt['quantite'];
                        $tt_restant = max(0, $tt_total - $tt_vendus);
                        $tt_pct     = ($tt_total > 0) ? min(100, round(($tt_vendus / $tt_total) * 100)) : 0;
                        $tt_gain    = (float)$tt['recette_type'] * (1 - (float)$ev['commission_rate'] / 100);
                    ?>
                    <tr>
                        <td>
                            <span style="background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:5px; font-weight:800; font-size:.8rem;">
                                <?php echo htmlspecialchars($tt['type_nom']); ?>
                            </span>
                        </td>
                        <td><strong><?php echo number_format($tt['prix'], 0, ',', ' '); ?> F</strong></td>
                        <td>
                            <div style="display:flex; align-items:center; gap:.4rem; min-width:120px;">
                                <div class="progress-sm" style="flex:1;">
                                    <div style="width:<?php echo $tt_pct; ?>%; background:var(--primary);"></div>
                                </div>
                                <span style="font-size:.78rem; font-weight:700; color:var(--navy); white-space:nowrap;"><?php echo $tt_vendus; ?> / <?php echo $tt_total; ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($tt_restant === 0): ?>
                                <span style="background:#fee2e2; color:#b91c1c; font-size:.75rem; font-weight:800; padding:2px 7px; border-radius:5px;">Épuisé</span>
                            <?php else: ?>
                                <span style="background:#dcfce7; color:#15803d; font-size:.75rem; font-weight:800; padding:2px 7px; border-radius:5px;"><?php echo $tt_restant; ?> dispo.</span>
                            <?php endif; ?>
                        </td>
                        <td><strong style="color:var(--primary);"><?php echo number_format($tt['recette_type'], 0, ',', ' '); ?> F</strong></td>
                        <td><strong style="color:#16a34a;"><?php echo number_format($tt_gain, 0, ',', ' '); ?> F</strong></td>
                        <td>
                            <?php if ($tt_vendus > 0): ?>
                                <span style="font-weight:700; color:#0284c7;"><?php echo (int)$tt['utilises']; ?></span>
                                <small style="color:var(--muted);"> / <?php echo $tt_vendus; ?> vendus (<?php echo round(($tt['utilises']/$tt_vendus)*100); ?>%)</small>
                            <?php else: ?>
                                <span style="color:var(--muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div style="padding:1.25rem; text-align:center; color:var(--muted); font-size:.88rem;">
                    Aucun type de ticket configuré. <a href="ticket-types.php?event_id=<?php echo $ev['id']; ?>" style="color:var(--primary); font-weight:700;">Configurer →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Résumé par Type de Ticket (tous événements confondus) ─────────────────── -->
<?php if (count($types_data) > 0): ?>
<div class="content-section" style="margin-top:1.5rem;">
    <div class="section-title"><i class="fa-solid fa-tags"></i> Synthèse par Type de Ticket (tous événements)</div>
    <div class="table-wrapper">
        <table class="events-table">
            <thead>
                <tr>
                    <th>Événement</th>
                    <th>Catégorie</th>
                    <th>Prix</th>
                    <th>Vendus / Cap.</th>
                    <th>Recette</th>
                    <th>Gain Net</th>
                    <th>Taux</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($types_data as $tt):
                    $pct_tt = ((int)$tt['quantite'] > 0) ? min(100, round(((int)$tt['quantite_vendue'] / (int)$tt['quantite']) * 100)) : 0;
                    $gain_tt_rate = 0;
                    foreach ($events_data as $ev_row) {
                        if ((int)$ev_row['id'] === (int)$tt['event_id']) {
                            $gain_tt_rate = 1 - (float)$ev_row['commission_rate'] / 100;
                            break;
                        }
                    }
                ?>
                <tr>
                    <td><small style="color:var(--muted);"><?php echo htmlspecialchars(mb_strimwidth($tt['event_nom'], 0, 25, '…')); ?></small></td>
                    <td>
                        <span style="background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:5px; font-weight:800; font-size:.8rem;">
                            <?php echo htmlspecialchars($tt['type_nom']); ?>
                        </span>
                    </td>
                    <td><strong><?php echo number_format($tt['prix'], 0, ',', ' '); ?> F</strong></td>
                    <td>
                        <strong style="color:#0284c7;"><?php echo (int)$tt['quantite_vendue']; ?></strong>
                        <span style="color:var(--muted);"> / <?php echo (int)$tt['quantite']; ?></span>
                    </td>
                    <td><strong style="color:var(--primary);"><?php echo number_format($tt['recette_type'], 0, ',', ' '); ?> F</strong></td>
                    <td><strong style="color:#16a34a;"><?php echo number_format((float)$tt['recette_type'] * $gain_tt_rate, 0, ',', ' '); ?> F</strong></td>
                    <td>
                        <div style="display:flex; align-items:center; gap:.4rem;">
                            <div class="progress-sm" style="width:55px;">
                                <div style="width:<?php echo $pct_tt; ?>%; background:<?php echo $pct_tt >= 80 ? '#ef4444' : 'var(--primary)'; ?>;"></div>
                            </div>
                            <strong style="font-size:.8rem;"><?php echo $pct_tt; ?>%</strong>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Scripts Chart.js ───────────────────────────────────────────────────────── -->
<script>
const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } }
};

// Courbe 14 jours
new Chart(document.getElementById('chartTrend'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($trend_labels); ?>,
        datasets: [{
            label: 'Tickets vendus',
            data: <?php echo json_encode($trend_tickets); ?>,
            borderColor: '#0f766e',
            backgroundColor: 'rgba(15,118,110,.1)',
            fill: true,
            tension: .4,
            pointRadius: 4,
            pointBackgroundColor: '#0f766e'
        }]
    },
    options: { ...chartDefaults,
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } },
            x: { ticks: { font: { size: 10 } } }
        },
        plugins: { ...chartDefaults.plugins, tooltip: {
            callbacks: { label: ctx => ctx.parsed.y + ' ticket(s)' }
        }}
    }
});

<?php if (count($ev_recettes) > 0 && max($ev_recettes) > 0): ?>
// Barres recettes
new Chart(document.getElementById('chartEvents'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($ev_labels); ?>,
        datasets: [{
            label: 'Recette (FCFA)',
            data: <?php echo json_encode(array_map('floatval', $ev_recettes)); ?>,
            backgroundColor: 'rgba(2,132,199,.75)',
            borderColor: '#0284c7',
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: { ...chartDefaults,
        scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr-FR') + ' F' } } }
    }
});
<?php endif; ?>

<?php if (count($ev_vendus) > 0 && max($ev_vendus) > 0): ?>
// Barres tickets vendus
new Chart(document.getElementById('chartTickets'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($ev_labels); ?>,
        datasets: [{
            label: 'Tickets vendus',
            data: <?php echo json_encode(array_map('intval', $ev_vendus)); ?>,
            backgroundColor: 'rgba(245,158,11,.75)',
            borderColor: '#f59e0b',
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: { ...chartDefaults,
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
<?php endif; ?>

<?php if (count($mm_data) > 0): ?>
// Donut Mobile Money
new Chart(document.getElementById('chartMM'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($mm_labels); ?>,
        datasets: [{
            data: <?php echo json_encode(array_map('floatval', $mm_totals)); ?>,
            backgroundColor: <?php echo json_encode(array_slice($mm_colors, 0, count($mm_data))); ?>,
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } }
    }
});
<?php endif; ?>

// Accordéon événements
function toggleDetail(i) {
    const d = document.getElementById('detail-' + i);
    const c = document.getElementById('chevron-' + i);
    const open = d.classList.contains('open');
    document.querySelectorAll('.ev-detail').forEach(el => el.classList.remove('open'));
    document.querySelectorAll('[id^="chevron-"]').forEach(el => el.style.transform = 'rotate(0deg)');
    if (!open) {
        d.classList.add('open');
        c.style.transform = 'rotate(180deg)';
    }
}
</script>

<?php include 'footer.php'; ?>
