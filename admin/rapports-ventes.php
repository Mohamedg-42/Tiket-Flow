<?php
// ==============================================================================
// RAPPORTS DE VENTES DISTINCTS & CLÔTURES DE CAISSE (admin/rapports-ventes.php)
// Ségrégation stricte : Ventes Web vs Guichets Physiques (Gares)
// Standard Typographique Suisse Müller-Brockmann & Dashboard Pro
// ==============================================================================

$admin_page_title = "Rapports des Ventes & Clôtures de Caisse - Administration";
include 'header.php';

// Filtres
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin   = $_GET['date_fin'] ?? date('Y-m-d');
$canal      = $_GET['canal'] ?? 'tous';
$station_id = filter_input(INPUT_GET, 'station_id', FILTER_VALIDATE_INT) ?: 0;
$onglet     = $_GET['onglet'] ?? 'ventes';

// 1. Métriques comparatives Web vs Guichets sur la période
$sql_web = "SELECT COALESCE(SUM(montant_total), 0) AS total, COUNT(*) AS nb 
            FROM orders 
            WHERE statut = 'validee' AND canal_vente = 'web' 
              AND DATE(created_at) BETWEEN ? AND ?";
$stmt_web = $pdo->prepare($sql_web);
$stmt_web->execute([$date_debut, $date_fin]);
$stat_web = $stmt_web->fetch(PDO::FETCH_ASSOC);

$sql_guichet = "SELECT COALESCE(SUM(montant_total), 0) AS total, COUNT(*) AS nb 
                FROM orders 
                WHERE statut = 'validee' AND canal_vente = 'guichet' 
                  AND DATE(created_at) BETWEEN ? AND ?";
$params_g = [$date_debut, $date_fin];
if ($station_id > 0) {
    $sql_guichet .= " AND station_id = ?";
    $params_g[] = $station_id;
}
$stmt_g = $pdo->prepare($sql_guichet);
$stmt_g->execute($params_g);
$stat_guichet = $stmt_g->fetch(PDO::FETCH_ASSOC);

$total_recettes = (float)$stat_web['total'] + (float)$stat_guichet['total'];
$pct_web = ($total_recettes > 0) ? round(($stat_web['total'] / $total_recettes) * 100, 1) : 0;
$pct_guichet = ($total_recettes > 0) ? round(($stat_guichet['total'] / $total_recettes) * 100, 1) : 0;

// 2. Liste de toutes les gares pour le filtre
$all_stations = $pdo->query("SELECT id, nom, code FROM stations ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);

// 3. Commandes détaillées
$sql_orders = "
    SELECT o.*, s.nom AS station_nom, s.code AS station_code,
           (SELECT COUNT(*) FROM tickets t WHERE t.order_id = o.id) AS nb_tickets
    FROM orders o
    LEFT JOIN stations s ON o.station_id = s.id
    WHERE o.statut = 'validee'
      AND DATE(o.created_at) BETWEEN ? AND ?
";
$orders_params = [$date_debut, $date_fin];

if ($canal !== 'tous') {
    $sql_orders .= " AND o.canal_vente = ?";
    $orders_params[] = $canal;
}
if ($station_id > 0) {
    $sql_orders .= " AND o.station_id = ?";
    $orders_params[] = $station_id;
}
$sql_orders .= " ORDER BY o.created_at DESC LIMIT 100";
$stmt_orders = $pdo->prepare($sql_orders);
$stmt_orders->execute($orders_params);
$orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);

// 4. Clôtures de caisse (Sessions POS)
$sql_sessions = "
    SELECT ps.*, pt.nom_guichet, pt.code_guichet, s.nom AS station_nom, u.nom AS agent_nom, u.prenom AS agent_prenom
    FROM pos_sessions ps
    JOIN pos_terminals pt ON ps.pos_terminal_id = pt.id
    JOIN stations s ON pt.station_id = s.id
    JOIN users u ON ps.agent_id = u.id
    WHERE DATE(ps.opened_at) BETWEEN ? AND ?
";
$session_params = [$date_debut, $date_fin];
if ($station_id > 0) {
    $sql_sessions .= " AND s.id = ?";
    $session_params[] = $station_id;
}
$sql_sessions .= " ORDER BY ps.opened_at DESC LIMIT 50";
$stmt_sess = $pdo->prepare($sql_sessions);
$stmt_sess->execute($session_params);
$sessions = $stmt_sess->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="dash-container">
    <!-- Titre de page -->
    <div class="dash-header-section" style="margin-bottom: 1.5rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-chart-pie" style="color: var(--tikeli-orange, #FF4A0D); font-size: 1.6rem;"></i>
                Rapports de Vente & Clôtures de Caisse
            </h1>
            <p>Ségrégation étanche entre les recettes Web (Mobile Money) et les guichets physiques des gares routières.</p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="gares.php" class="dash-btn-action" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-bus"></i> Gérer les Gares
            </a>
            <button type="button" onclick="window.print()" class="dash-btn-action"
                    style="background: #0F172A; color: #FFFFFF; border: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-print"></i> Imprimer Rapport
            </button>
        </div>
    </div>

    <!-- Filtres Avancés -->
    <div class="dash-card" style="margin-bottom: 1.5rem; padding: 1.25rem;">
        <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: flex-end;">
            <input type="hidden" name="onglet" value="<?php echo htmlspecialchars($onglet); ?>">

            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #64748B; margin-bottom: 4px;">Date Début</label>
                <input type="date" name="date_debut" value="<?php echo htmlspecialchars($date_debut); ?>"
                       style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 6px; border: 1px solid #CBD5E1; font-size: 0.88rem; box-sizing: border-box;">
            </div>

            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #64748B; margin-bottom: 4px;">Date Fin</label>
                <input type="date" name="date_fin" value="<?php echo htmlspecialchars($date_fin); ?>"
                       style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 6px; border: 1px solid #CBD5E1; font-size: 0.88rem; box-sizing: border-box;">
            </div>

            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #64748B; margin-bottom: 4px;">Canal de Vente</label>
                <select name="canal" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 6px; border: 1px solid #CBD5E1; font-size: 0.88rem; box-sizing: border-box;">
                    <option value="tous" <?php echo $canal === 'tous' ? 'selected' : ''; ?>>Tous les canaux</option>
                    <option value="web" <?php echo $canal === 'web' ? 'selected' : ''; ?>>🌐 Ventes Web Uniquement</option>
                    <option value="guichet" <?php echo $canal === 'guichet' ? 'selected' : ''; ?>>🏢 Guichets Physiques Uniquement</option>
                </select>
            </div>

            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #64748B; margin-bottom: 4px;">Gare Spécifique</label>
                <select name="station_id" style="width: 100%; padding: 0.55rem 0.8rem; border-radius: 6px; border: 1px solid #CBD5E1; font-size: 0.88rem; box-sizing: border-box;">
                    <option value="0">Toutes les gares</option>
                    <?php foreach ($all_stations as $st): ?>
                        <option value="<?php echo (int) $st['id']; ?>" <?php echo $station_id === (int)$st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['nom']); ?> (<?php echo htmlspecialchars($st['code']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit" class="dash-btn-action"
                        style="width: 100%; background: var(--tikeli-orange, #FF4A0D); color: #fff; border: none; font-weight: 700; padding: 0.65rem; border-radius: 6px; cursor: pointer;">
                    <i class="fa-solid fa-filter"></i> Actualiser
                </button>
            </div>
        </form>
    </div>

    <!-- Comparaison Visuelle des Flux (Swiss Style) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
        <div class="dash-card" style="padding: 1.25rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748B;">Recettes Totales Période</span>
                <i class="fa-solid fa-coins" style="color: #0F172A;"></i>
            </div>
            <strong style="font-family: 'Space Mono', monospace; font-size: 1.8rem; font-weight: 800; color: #0F172A;">
                <?php echo number_format($total_recettes, 0, ',', ' '); ?> <small style="font-size: 0.75rem;">FCFA</small>
            </strong>
        </div>

        <div class="dash-card" style="padding: 1.25rem; border-left: 4px solid #3B82F6;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #3B82F6;">🌐 Ventes Web (Mobile Money)</span>
                <span style="font-family: 'Space Mono', monospace; font-size: 0.75rem; font-weight: 700; background: #DBEAFE; color: #1E40AF; padding: 2px 6px; border-radius: 4px;">
                    <?php echo $pct_web; ?>%
                </span>
            </div>
            <strong style="font-family: 'Space Mono', monospace; font-size: 1.7rem; font-weight: 800; color: #1E40AF;">
                <?php echo number_format((float)$stat_web['total'], 0, ',', ' '); ?> <small style="font-size: 0.75rem;">FCFA</small>
            </strong>
            <small style="display: block; color: #64748B; font-size: 0.75rem; margin-top: 4px;">
                <?php echo (int)$stat_web['nb']; ?> commande(s) en ligne
            </small>
        </div>

        <div class="dash-card" style="padding: 1.25rem; border-left: 4px solid var(--tikeli-orange, #FF4A0D);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--tikeli-orange, #FF4A0D);">🏢 Guichets Physiques (Gares)</span>
                <span style="font-family: 'Space Mono', monospace; font-size: 0.75rem; font-weight: 700; background: #FFF2ED; color: #FF4A0D; padding: 2px 6px; border-radius: 4px;">
                    <?php echo $pct_guichet; ?>%
                </span>
            </div>
            <strong style="font-family: 'Space Mono', monospace; font-size: 1.7rem; font-weight: 800; color: var(--tikeli-orange, #FF4A0D);">
                <?php echo number_format((float)$stat_guichet['total'], 0, ',', ' '); ?> <small style="font-size: 0.75rem;">FCFA</small>
            </strong>
            <small style="display: block; color: #64748B; font-size: 0.75rem; margin-top: 4px;">
                <?php echo (int)$stat_guichet['nb']; ?> commande(s) au comptoir
            </small>
        </div>
    </div>

    <!-- Onglets : Ventes Détail vs Clôtures de Caisse -->
    <div style="display: flex; gap: 8px; margin-bottom: 1.25rem; border-bottom: 1.5px solid #E2E8F0; padding-bottom: 0.5rem;">
        <a href="?<?php echo http_build_query(array_merge($_GET, ['onglet' => 'ventes'])); ?>"
           style="padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 700; font-size: 0.9rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; <?php echo $onglet === 'ventes' ? 'background: #0F172A; color: #FFFFFF;' : 'background: #F1F5F9; color: #64748B;'; ?>">
            <i class="fa-solid fa-list-check"></i> Détail des Commandes (<?php echo count($orders); ?>)
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['onglet' => 'clotures'])); ?>"
           style="padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 700; font-size: 0.9rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; <?php echo $onglet === 'clotures' ? 'background: #0F172A; color: #FFFFFF;' : 'background: #F1F5F9; color: #64748B;'; ?>">
            <i class="fa-solid fa-cash-register"></i> Clôtures de Caisse POS (<?php echo count($sessions); ?>)
        </a>
    </div>

    <?php if ($onglet === 'ventes'): ?>
        <!-- Tableau des Commandes -->
        <div class="dash-card">
            <?php if (empty($orders)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: #64748B;">
                    <i class="fa-solid fa-receipt" style="font-size: 2.5rem; color: #CBD5E1; margin-bottom: 0.75rem; display: block;"></i>
                    <h4 style="margin: 0 0 0.35rem; color: #0F172A; font-weight: 800;">Aucune transaction trouvée</h4>
                    <p style="margin: 0; font-size: 0.85rem;">Aucune vente validée ne correspond aux filtres sélectionnés sur cette période.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="dash-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 1.5px solid #E2E8F0; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748B;">
                                <th style="padding: 0.75rem 1rem;">N° Commande</th>
                                <th style="padding: 0.75rem 1rem;">Canal / Point de Vente</th>
                                <th style="padding: 0.75rem 1rem;">Client / Porteur</th>
                                <th style="padding: 0.75rem 1rem; text-align: center;">Mode Paiement</th>
                                <th style="padding: 0.75rem 1rem; text-align: center;">Billets</th>
                                <th style="padding: 0.75rem 1rem; text-align: right;">Montant</th>
                                <th style="padding: 0.75rem 1rem; text-align: right;">Date & Heure</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): 
                                $is_guichet = ($o['canal_vente'] === 'guichet');
                                ?>
                                <tr style="border-bottom: 1px solid #F1F5F9; font-size: 0.86rem;">
                                    <td style="padding: 0.8rem 1rem; font-family: 'Space Mono', monospace; font-weight: 700; color: #0F172A;">
                                        <?php echo htmlspecialchars($o['numero_commande']); ?>
                                    </td>
                                    <td style="padding: 0.8rem 1rem;">
                                        <?php if ($is_guichet): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; color: var(--tikeli-orange, #FF4A0D);">
                                                <i class="fa-solid fa-bus"></i> <?php echo htmlspecialchars($o['station_nom'] ?? 'Guichet'); ?>
                                            </span>
                                            <small style="display: block; font-family: 'Space Mono', monospace; color: #64748B; font-size: 0.74rem;">
                                                <?php echo htmlspecialchars($o['station_code'] ?? 'POS'); ?>
                                            </small>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; color: #3B82F6;">
                                                <i class="fa-solid fa-globe"></i> Billetterie Web
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.8rem 1rem;">
                                        <div style="font-weight: 700; color: #0F172A;">
                                            <?php echo htmlspecialchars($o['client_nom'] ?: 'Client Comptoir'); ?>
                                        </div>
                                        <small style="font-family: 'Space Mono', monospace; color: #64748B;">
                                            <?php echo htmlspecialchars($o['client_telephone'] ?: '—'); ?>
                                        </small>
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: center;">
                                        <span style="font-family: 'Space Mono', monospace; font-size: 0.72rem; font-weight: 700; background: #F1F5F9; padding: 2px 7px; border-radius: 4px; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($o['mode_paiement'] ?? 'MOBILE'); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: center; font-family: 'Space Mono', monospace; font-weight: 700;">
                                        <?php echo (int) $o['nb_tickets']; ?>
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: right; font-family: 'Space Mono', monospace; font-weight: 800; color: #0F172A;">
                                        <?php echo number_format((float) $o['montant_total'], 0, ',', ' '); ?> F
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: right; font-family: 'Space Mono', monospace; font-size: 0.78rem; color: #64748B;">
                                        <?php echo date('d/m/Y H:i', strtotime($o['created_at'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- Tableau des Clôtures de Caisse (Sessions POS) -->
        <div class="dash-card">
            <?php if (empty($sessions)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: #64748B;">
                    <i class="fa-solid fa-cash-register" style="font-size: 2.5rem; color: #CBD5E1; margin-bottom: 0.75rem; display: block;"></i>
                    <h4 style="margin: 0 0 0.35rem; color: #0F172A; font-weight: 800;">Aucune session de caisse trouvée</h4>
                    <p style="margin: 0; font-size: 0.85rem;">Aucune ouverture ou clôture de caisse enregistrée sur cette période.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="dash-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 1.5px solid #E2E8F0; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748B;">
                                <th style="padding: 0.75rem 1rem;">Gare & Guichet</th>
                                <th style="padding: 0.75rem 1rem;">Guichetier / Caissier</th>
                                <th style="padding: 0.75rem 1rem; text-align: center;">Ouverture</th>
                                <th style="padding: 0.75rem 1rem; text-align: center;">Fermeture</th>
                                <th style="padding: 0.75rem 1rem; text-align: right;">Fond Ouverture</th>
                                <th style="padding: 0.75rem 1rem; text-align: right;">Ventes Espèces</th>
                                <th style="padding: 0.75rem 1rem; text-align: right;">Compté Clôture</th>
                                <th style="padding: 0.75rem 1rem; text-align: center;">Écart Caisse</th>
                                <th style="padding: 0.75rem 1rem; text-align: center;">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $ps): 
                                $is_open = ($ps['statut'] === 'ouverte');
                                $ecart = (float) ($ps['ecart_caisse'] ?? 0);
                                ?>
                                <tr style="border-bottom: 1px solid #F1F5F9; font-size: 0.86rem;">
                                    <td style="padding: 0.8rem 1rem;">
                                        <div style="font-weight: 800; color: #0F172A;">
                                            <?php echo htmlspecialchars($ps['station_nom']); ?>
                                        </div>
                                        <small style="color: var(--tikeli-orange, #FF4A0D); font-weight: 700;">
                                            <?php echo htmlspecialchars($ps['nom_guichet']); ?> (<?php echo htmlspecialchars($ps['code_guichet']); ?>)
                                        </small>
                                    </td>
                                    <td style="padding: 0.8rem 1rem; font-weight: 600; color: #0F172A;">
                                        <?php echo htmlspecialchars($ps['agent_nom'] . ' ' . ($ps['agent_prenom'] ?? '')); ?>
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: center; font-family: 'Space Mono', monospace; font-size: 0.78rem;">
                                        <?php echo date('d/m/Y H:i', strtotime($ps['opened_at'])); ?>
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: center; font-family: 'Space Mono', monospace; font-size: 0.78rem;">
                                        <?php echo !empty($ps['closed_at']) ? date('d/m/Y H:i', strtotime($ps['closed_at'])) : '<em style="color:#D97706;">En cours</em>'; ?>
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: right; font-family: 'Space Mono', monospace;">
                                        <?php echo number_format((float) $ps['fond_caisse_ouverture'], 0, ',', ' '); ?> F
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: right; font-family: 'Space Mono', monospace; font-weight: 700; color: #059669;">
                                        <?php echo number_format((float) $ps['total_ventes_especes'], 0, ',', ' '); ?> F
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: right; font-family: 'Space Mono', monospace; font-weight: 700;">
                                        <?php echo !empty($ps['montant_reel_fermeture']) ? number_format((float) $ps['montant_reel_fermeture'], 0, ',', ' ') . ' F' : '—'; ?>
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: center;">
                                        <?php if ($is_open): ?>
                                            <span style="color: #64748B;">—</span>
                                        <?php elseif ($ecart == 0): ?>
                                            <span style="font-family: 'Space Mono', monospace; font-size: 0.75rem; font-weight: 800; color: #059669; background: #ECFDF5; padding: 2px 7px; border-radius: 4px;">
                                                0 F (PARFAIT)
                                            </span>
                                        <?php elseif ($ecart > 0): ?>
                                            <span style="font-family: 'Space Mono', monospace; font-size: 0.75rem; font-weight: 800; color: #2563EB; background: #EFF6FF; padding: 2px 7px; border-radius: 4px;">
                                                +<?php echo number_format($ecart, 0, ',', ' '); ?> F (SURPLUS)
                                            </span>
                                        <?php else: ?>
                                            <span style="font-family: 'Space Mono', monospace; font-size: 0.75rem; font-weight: 800; color: #DC2626; background: #FEF2F2; padding: 2px 7px; border-radius: 4px;">
                                                <?php echo number_format($ecart, 0, ',', ' '); ?> F (MANQUE)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.8rem 1rem; text-align: center;">
                                        <?php if ($is_open): ?>
                                            <span class="dash-badge badge-warning" style="font-family: 'Space Mono', monospace; font-size: 0.7rem; font-weight: 700; background: #FEF3C7; color: #D97706;">
                                                OUVERTE
                                            </span>
                                        <?php else: ?>
                                            <span class="dash-badge badge-success" style="font-family: 'Space Mono', monospace; font-size: 0.7rem; font-weight: 700; background: #F1F5F9; color: #475569;">
                                                CLÔTURÉE
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
