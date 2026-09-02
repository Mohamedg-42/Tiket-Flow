<?php
// ==============================================================================
// SUIVI DES VENTES & BILLETTERIE (promoteur/mes-ventes.php)
// Filtres intégrés sur la même ligne dans l'en-tête (Style Dashboard Pro)
// ==============================================================================

$page_title = "Mes Ventes & Billetterie - Espace Organisateur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];

// ── 1. PARAMÈTRES DE RECHERCHE ET FILTRES DYNAMIQUES ─────────────────────────
$q             = trim($_GET['q'] ?? '');
$filter_event  = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$filter_status = trim($_GET['statut'] ?? '');
$filter_type   = trim($_GET['type'] ?? '');
$filter_period = trim($_GET['period'] ?? '');

// Liste de tous les événements du promoteur pour le sélecteur
$my_events_stmt = $pdo->prepare("SELECT id, nom, date_evenement FROM events WHERE user_id = ? ORDER BY nom ASC");
$my_events_stmt->execute([$user_id]);
$my_events_list = $my_events_stmt->fetchAll();

// Liste des types de billets disponibles (VIP, Standard, etc.)
$sql_types = "SELECT DISTINCT tt.nom FROM ticket_types tt JOIN events e ON tt.event_id = e.id WHERE e.user_id = ?";
$params_types = [$user_id];
if ($filter_event) {
    $sql_types .= " AND e.id = ?";
    $params_types[] = $filter_event;
}
$sql_types .= " ORDER BY tt.nom ASC";
$stmt_types = $pdo->prepare($sql_types);
$stmt_types->execute($params_types);
$available_types = $stmt_types->fetchAll(PDO::FETCH_COLUMN);

// ── 2. ÉTAT DES STOCKS & DISPONIBILITÉ PAR CATÉGORIE ──────────────────────────
$sql_stock = "
    SELECT tt.id, tt.nom AS category_name, tt.description, tt.prix, tt.quantite AS total_places, tt.quantite_vendue AS vendus,
           (tt.quantite - tt.quantite_vendue) AS restants, e.nom AS event_nom, e.id AS event_id
    FROM ticket_types tt
    JOIN events e ON tt.event_id = e.id
    WHERE e.user_id = ?
";
$params_stock = [$user_id];
if ($filter_event) {
    $sql_stock .= " AND e.id = ?";
    $params_stock[] = $filter_event;
}
if (!empty($filter_type)) {
    $sql_stock .= " AND tt.nom = ?";
    $params_stock[] = $filter_type;
}
$sql_stock .= " ORDER BY e.nom ASC, tt.prix DESC";
$stmt_stock = $pdo->prepare($sql_stock);
$stmt_stock->execute($params_stock);
$stocks_summary = $stmt_stock->fetchAll();

// ── 3. RECHERCHE & FILTRAGE DES BILLETS VENDUS (ACHETEURS) ────────────────────
$sql_sales = "
    SELECT t.*, e.nom AS event_name, e.date_evenement,
           COALESCE(t.client_nom, u.nom, 'Client Web') AS buyer_name,
           COALESCE(t.client_email, u.email, 'Non renseigné') AS buyer_email,
           COALESCE(t.client_telephone, u.telephone, '') AS buyer_phone,
           ag.nom AS agent_nom
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN users ag ON t.validated_by = ag.id
    WHERE e.user_id = ?
";
$params_sales = [$user_id];

// A. Recherche textuelle tous champs (nom, email, téléphone, code ticket)
if (!empty($q)) {
    $sql_sales .= " AND (
        t.code_unique LIKE ? OR
        t.client_nom LIKE ? OR
        u.nom LIKE ? OR
        t.client_email LIKE ? OR
        u.email LIKE ? OR
        t.client_telephone LIKE ? OR
        u.telephone LIKE ? OR
        e.nom LIKE ?
    )";
    $term = "%$q%";
    $params_sales = array_merge($params_sales, [$term, $term, $term, $term, $term, $term, $term, $term]);
}

// B. Filtre par événement
if ($filter_event) {
    $sql_sales .= " AND e.id = ?";
    $params_sales[] = $filter_event;
}

// C. Filtre par catégorie de billet
if (!empty($filter_type)) {
    $sql_sales .= " AND t.type_ticket = ?";
    $params_sales[] = $filter_type;
}

// D. Filtre par statut d'accès
if (!empty($filter_status)) {
    $sql_sales .= " AND t.statut = ?";
    $params_sales[] = $filter_status;
}

// E. Filtre par période
if (!empty($filter_period)) {
    switch ($filter_period) {
        case 'today':
            $sql_sales .= " AND DATE(t.date_achat) = CURDATE()";
            break;
        case '7d':
            $sql_sales .= " AND t.date_achat >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case '30d':
            $sql_sales .= " AND t.date_achat >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'this_month':
            $sql_sales .= " AND MONTH(t.date_achat) = MONTH(CURDATE()) AND YEAR(t.date_achat) = YEAR(CURDATE())";
            break;
    }
}

$sql_sales .= " ORDER BY t.date_achat DESC";

$stmt_sales = $pdo->prepare($sql_sales);
$stmt_sales->execute($params_sales);
$sales = $stmt_sales->fetchAll();

$total_filtre = array_sum(array_column($sales, 'prix'));

// ── 4. KPIS FINANCIERS & OPÉRATIONNELS ────────────────────────────────────────
$sql_kpi = "
    SELECT
        COALESCE(SUM(t.prix), 0)                                      AS ventes_brutes,
        COALESCE(SUM(t.prix * e.commission_rate / 100), 0)            AS total_commission,
        COALESCE(SUM(t.prix * (1 - e.commission_rate / 100)), 0)      AS gains_nets,
        COUNT(t.id)                                                   AS nb_vendus,
        COUNT(CASE WHEN t.statut = 'utilise' THEN 1 END)              AS nb_utilises
    FROM events e
    LEFT JOIN tickets t ON t.event_id = e.id AND t.statut IN ('vendu','utilise')
    WHERE e.user_id = ?
";
$params_kpi = [$user_id];
if ($filter_event) {
    $sql_kpi .= " AND e.id = ?";
    $params_kpi[] = $filter_event;
}
if (!empty($filter_type)) {
    $sql_kpi .= " AND t.type_ticket = ?";
    $params_kpi[] = $filter_type;
}
if (!empty($filter_period)) {
    switch ($filter_period) {
        case 'today':
            $sql_kpi .= " AND DATE(t.date_achat) = CURDATE()";
            break;
        case '7d':
            $sql_kpi .= " AND t.date_achat >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case '30d':
            $sql_kpi .= " AND t.date_achat >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'this_month':
            $sql_kpi .= " AND MONTH(t.date_achat) = MONTH(CURDATE()) AND YEAR(t.date_achat) = YEAR(CURDATE())";
            break;
    }
}
$stmt_kpi = $pdo->prepare($sql_kpi);
$stmt_kpi->execute($params_kpi);
$kpi = $stmt_kpi->fetch();

$capacite_totale_stocks = array_sum(array_column($stocks_summary, 'total_places'));
$taux_presence = ($kpi['nb_vendus'] > 0) ? round(($kpi['nb_utilises'] / $kpi['nb_vendus']) * 100) : 0;
$taux_global_remplissage = ($capacite_totale_stocks > 0) ? round(($kpi['nb_vendus'] / $capacite_totale_stocks) * 100) : 0;
$prix_moyen = ($kpi['nb_vendus'] > 0) ? round($kpi['ventes_brutes'] / $kpi['nb_vendus']) : 0;

// ── 5. COURBE DES VENTES SUR 14 JOURS ─────────────────────────────────────────
$sql_trend = "
    SELECT DATE(t.date_achat) AS jour, COUNT(*) AS nb, COALESCE(SUM(t.prix), 0) AS montant
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu','utilise')
      AND t.date_achat >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
";
$params_trend = [$user_id];
if ($filter_event) {
    $sql_trend .= " AND e.id = ?";
    $params_trend[] = $filter_event;
}
if (!empty($filter_type)) {
    $sql_trend .= " AND t.type_ticket = ?";
    $params_trend[] = $filter_type;
}
$sql_trend .= " GROUP BY DATE(t.date_achat) ORDER BY jour ASC";
$stmt_trend = $pdo->prepare($sql_trend);
$stmt_trend->execute($params_trend);
$trend_rows = $stmt_trend->fetchAll();

$trend_index = [];
foreach ($trend_rows as $r) { $trend_index[$r['jour']] = $r; }
$trend_labels = []; $trend_tickets = []; $trend_montant = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $trend_labels[]  = date('d/m', strtotime($day));
    $trend_tickets[] = isset($trend_index[$day]) ? (int)$trend_index[$day]['nb'] : 0;
    $trend_montant[] = isset($trend_index[$day]) ? (float)$trend_index[$day]['montant'] : 0;
}

// ── 6. REMPLISSAGE PAR ÉVÉNEMENT (DONUT) ──────────────────────────────────────
$donut_data = [];
foreach ($stocks_summary as $stk) {
    $en = $stk['event_nom'];
    if (!isset($donut_data[$en])) $donut_data[$en] = ['vendus' => 0, 'total' => 0];
    $donut_data[$en]['vendus'] += (int)$stk['vendus'];
    $donut_data[$en]['total']  += (int)$stk['total_places'];
}
$donut_labels = array_keys($donut_data);
$donut_pcts   = array_map(fn($d) => $d['total'] > 0 ? min(100, round(($d['vendus'] / $d['total']) * 100)) : 0, array_values($donut_data));
$donut_colors = ['#5b50e6', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#14b8a6', '#64748b'];
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
.dash-kpi-card {
    transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.22s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.22s !important;
    position: relative;
    overflow: hidden;
}
.dash-kpi-card:hover {
    transform: translateY(-4px) !important;
    box-shadow: 0 14px 28px -6px rgba(91, 80, 230, 0.12), 0 4px 10px -2px rgba(0, 0, 0, 0.04) !important;
    border-color: #cbd5e1 !important;
}
.dash-pro-table tbody tr {
    transition: background-color 0.15s ease;
}
.dash-pro-table tbody tr:hover {
    background-color: #f8fafc;
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. BARRE D'EN-TÊTE DU DASHBOARD : TITRE À GAUCHE & FILTRES DIRECTS À DROITE
         ============================================================================== -->
    <div class="dash-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-ticket" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Mes Ventes & Billets
            </h1>
            <p>Traçabilité en temps réel des ventes, disponibilité des places et historique des acheteurs.</p>
        </div>

        <div class="dash-filter-bar">
            <!-- Formulaire de filtrage dynamique sur la même ligne tout en haut -->
            <form method="GET" action="mes-ventes.php" id="ventesFilterForm" style="display: flex; align-items: center; gap: 0.5rem; margin: 0; flex-wrap: wrap;">
                
                <!-- Filtre Catégorie / Type de Billet (VIP, etc.) -->
                <div class="dash-control-select" style="padding: 0.4rem 0.75rem;">
                    <i class="fa-solid fa-tag" style="color: #0ea5e9; font-size: 0.8rem;"></i>
                    <select name="type" onchange="document.getElementById('ventesFilterForm').submit();" style="border: none; background: transparent; font-weight: 700; color: var(--dash-text); outline: none; cursor: pointer; font-size: 0.82rem; max-width: 165px;">
                        <option value="">Tous les types (VIP, Standard...)</option>
                        <?php foreach ($available_types as $t_item): ?>
                            <option value="<?php echo htmlspecialchars($t_item); ?>" <?php echo $filter_type === $t_item ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t_item); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filtre Événement -->
                <div class="dash-control-select" style="padding: 0.4rem 0.75rem;">
                    <i class="fa-solid fa-calendar-days" style="color: var(--dash-primary); font-size: 0.8rem;"></i>
                    <select name="event_id" onchange="document.getElementById('ventesFilterForm').submit();" style="border: none; background: transparent; font-weight: 700; color: var(--dash-text); outline: none; cursor: pointer; max-width: 170px; font-size: 0.82rem;">
                        <option value="">Tous mes événements</option>
                        <?php foreach ($my_events_list as $ev_item): ?>
                            <option value="<?php echo $ev_item['id']; ?>" <?php echo $filter_event === (int)$ev_item['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(mb_strimwidth($ev_item['nom'], 0, 24, '...')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filtre Statut d'accès -->
                <div class="dash-control-select" style="padding: 0.4rem 0.75rem;">
                    <i class="fa-solid fa-qrcode" style="color: #10b981; font-size: 0.8rem;"></i>
                    <select name="statut" onchange="document.getElementById('ventesFilterForm').submit();" style="border: none; background: transparent; font-weight: 700; color: var(--dash-text); outline: none; cursor: pointer; font-size: 0.82rem;">
                        <option value="">Tous statuts</option>
                        <option value="vendu" <?php echo $filter_status === 'vendu' ? 'selected' : ''; ?>>Valide (Non scanné)</option>
                        <option value="utilise" <?php echo $filter_status === 'utilise' ? 'selected' : ''; ?>>Entré (Scanné)</option>
                    </select>
                </div>

                <!-- Filtre Période -->
                <div class="dash-control-select" style="padding: 0.4rem 0.75rem;">
                    <i class="fa-regular fa-calendar" style="color: var(--dash-secondary); font-size: 0.8rem;"></i>
                    <select name="period" onchange="document.getElementById('ventesFilterForm').submit();" style="border: none; background: transparent; font-weight: 700; color: var(--dash-text); outline: none; cursor: pointer; font-size: 0.82rem;">
                        <option value="" <?php echo empty($filter_period) ? 'selected' : ''; ?>>Toutes dates</option>
                        <option value="today" <?php echo $filter_period === 'today' ? 'selected' : ''; ?>>Aujourd'hui</option>
                        <option value="7d" <?php echo $filter_period === '7d' ? 'selected' : ''; ?>>7 derniers jours</option>
                        <option value="30d" <?php echo $filter_period === '30d' ? 'selected' : ''; ?>>30 derniers jours</option>
                        <option value="this_month" <?php echo $filter_period === 'this_month' ? 'selected' : ''; ?>>Ce mois-ci</option>
                    </select>
                </div>

                <?php if (!empty($filter_type) || $filter_event || !empty($filter_status) || !empty($filter_period)): ?>
                    <a href="mes-ventes.php" class="dash-btn-action" style="padding: 0.4rem 0.65rem; color: #ef4444;" title="Effacer les filtres">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </form>

            <button type="button" class="dash-btn-action" onclick="window.print()" title="Imprimer le bilan">
                <i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i>
                <span>Imprimer Bilan</span>
            </button>
        </div>
    </div>

    <!-- ==============================================================================
         2. BANDEAU DE 6 KPIS FINANCIERS & OPÉRATIONNELS CLIQUABLES
         ============================================================================== -->
    <div class="dash-kpi-grid-6">
        <!-- 1. Recettes Brutes -->
        <a href="mes-ventes.php" class="dash-kpi-card" style="text-decoration: none; color: inherit; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" title="Cliquez pour réinitialiser et voir toutes les recettes">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap purple">
                    <i class="fa-solid fa-coins"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-arrow-trend-up"></i> Brut
                </span>
            </div>
            <div class="dash-kpi-title">Recettes Brutes</div>
            <div class="dash-kpi-amount"><?php echo number_format($kpi['ventes_brutes'], 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub">Total ventes encaissées <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
        </a>

        <!-- 2. Revenu Net Promoteur -->
        <a href="solde.php" class="dash-kpi-card" style="text-decoration: none; color: inherit; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" title="Cliquez pour accéder à votre Solde et demander un retrait">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap green">
                    <i class="fa-solid fa-piggy-bank"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-check"></i> Net
                </span>
            </div>
            <div class="dash-kpi-title">Revenu Net (Gains)</div>
            <div class="dash-kpi-amount" style="color: #10b981;"><?php echo number_format($kpi['gains_nets'], 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub">Voir mon solde disponible <i class="fa-solid fa-wallet" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
        </a>

        <!-- 3. Frais & Commissions -->
        <a href="solde.php" class="dash-kpi-card" style="text-decoration: none; color: inherit; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" title="Cliquez pour voir le barème et l'historique des commissions">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap amber">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-percent"></i> 5%
                </span>
            </div>
            <div class="dash-kpi-title">Commissions Frais</div>
            <div class="dash-kpi-amount" style="color: #f59e0b;"><?php echo number_format($kpi['total_commission'], 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub">Détail des commissions <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
        </a>

        <!-- 4. Billets Écoulés -->
        <a href="#table-acheteurs" class="dash-kpi-card" style="text-decoration: none; color: inherit; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" title="Cliquez pour voir la liste des billets achetés">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap blue">
                    <i class="fa-solid fa-ticket"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-circle-check"></i> Actif
                </span>
            </div>
            <div class="dash-kpi-title">Billets Écoulés</div>
            <div class="dash-kpi-amount"><?php echo number_format($kpi['nb_vendus'], 0, ',', ' '); ?></div>
            <div class="dash-kpi-sub">Voir les acheteurs <i class="fa-solid fa-users" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
        </a>

        <!-- 5. Taux d'Entrée & Scans -->
        <a href="mes-ventes.php?statut=utilise#table-acheteurs" class="dash-kpi-card" style="text-decoration: none; color: inherit; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" title="Cliquez pour filtrer uniquement les billets scannés aux portes">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap orange">
                    <i class="fa-solid fa-user-check"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-qrcode"></i> <?php echo $taux_presence; ?>%
                </span>
            </div>
            <div class="dash-kpi-title">Check-in Effectués</div>
            <div class="dash-kpi-amount"><?php echo number_format($kpi['nb_utilises'], 0, ',', ' '); ?></div>
            <div class="dash-kpi-sub">Filtrer les billets scannés <i class="fa-solid fa-filter" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
        </a>

        <!-- 6. Panier Moyen / Prix Moyen Billet -->
        <a href="#section-stocks" class="dash-kpi-card" style="text-decoration: none; color: inherit; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" title="Cliquez pour voir les tarifs par formule">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap pink">
                    <i class="fa-solid fa-tags"></i>
                </div>
                <span class="dash-kpi-badge-pill neutral">
                    Moyen
                </span>
            </div>
            <div class="dash-kpi-title">Prix Moyen Billet</div>
            <div class="dash-kpi-amount" style="color: #ec4899;"><?php echo number_format($prix_moyen, 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub">Voir la grille des tarifs <i class="fa-solid fa-arrow-down" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
        </a>
    </div>

    <!-- ==============================================================================
         3. SECTION GRAPHIQUES ANALYTIQUES (2 COLONNES ÉQUILIBRÉES)
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); gap: clamp(0.75rem, 2vw, 1.25rem); margin-bottom: 1.75rem; width: 100%;">
        <!-- 1. Tendance 14 jours (Courbe principale) -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-chart-area" style="color: var(--dash-primary);"></i>
                        Tendance des Ventes (14 derniers jours)
                    </h3>
                    <div class="dash-card-subtitle">Évolution quotidienne des flux de billetterie</div>
                </div>
                <div class="dash-chart-tabs">
                    <button type="button" class="dash-chart-tab active" onclick="switchTrendMode('revenue', this)">Recettes (F)</button>
                    <button type="button" class="dash-chart-tab" onclick="switchTrendMode('tickets', this)">Billets</button>
                </div>
            </div>
            <div style="height: 250px; max-height: 250px; position: relative; width: 100%; overflow: hidden;">
                <canvas id="mvChartTrend"></canvas>
            </div>
        </div>

        <!-- 2. Donut Remplissage par Événement -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-pie-chart" style="color: #10b981;"></i>
                        Remplissage Événements
                    </h3>
                    <div class="dash-card-subtitle">Taux d'occupation des jauges (cliquable)</div>
                </div>
            </div>
            <div class="dash-donut-box" style="height: 160px;">
                <canvas id="mvChartDonut"></canvas>
                <div class="dash-donut-info-center">
                    <strong style="font-size: 1.25rem;"><?php echo $taux_global_remplissage; ?>%</strong>
                    <span style="font-size: 0.68rem;">Global</span>
                </div>
            </div>
            <div class="dash-donut-legend-list" style="margin-top: 0.75rem; gap: 0.4rem; max-height: 110px; overflow-y: auto;">
                <?php foreach ($donut_data as $en => $d): ?>
                    <?php 
                    $idx = array_search($en, $donut_labels);
                    $color = $donut_colors[$idx % count($donut_colors)];
                    $p = ($d['total'] > 0) ? min(100, round(($d['vendus'] / $d['total']) * 100)) : 0;
                    $matched_ev_id = '';
                    foreach ($my_events_list as $ev_check) {
                        if ($ev_check['nom'] === $en) { $matched_ev_id = $ev_check['id']; break; }
                    }
                    ?>
                    <a href="mes-ventes.php?event_id=<?php echo $matched_ev_id; ?>" class="dash-legend-entry" style="text-decoration: none; color: inherit; cursor: pointer; border-radius: 6px; padding: 3px 6px; transition: background 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'" title="Filtrer cet événement">
                        <div class="dash-legend-name" style="min-width: 0;">
                            <span class="dash-legend-bullet" style="background: <?php echo $color; ?>;"></span>
                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; font-size: 0.8rem; font-weight: 600;"><?php echo htmlspecialchars($en); ?></span>
                        </div>
                        <div class="dash-legend-details">
                            <span class="dash-legend-num"><?php echo $d['vendus']; ?>/<?php echo $d['total']; ?></span>
                            <span class="dash-legend-percent"><?php echo $p; ?>%</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ==============================================================================
         4. ÉTAT DES STOCKS & DISPONIBILITÉ PAR CATÉGORIE (SECTIONS CLIQUABLES)
         ============================================================================== -->
    <div class="dash-card" id="section-stocks" style="margin-bottom: 1.75rem;">
        <div class="dash-card-head">
            <div>
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-boxes-stacked" style="color: var(--dash-primary);"></i>
                    Disponibilité & Quotas des Billets
                </h3>
                <div class="dash-card-subtitle">Cliquez sur une catégorie pour filtrer</div>
            </div>
            <span style="background: var(--dash-primary-light); color: var(--dash-primary); padding: 4px 10px; border-radius: 8px; font-size: 0.78rem; font-weight: 800;">
                <?php echo count($stocks_summary); ?> Catégorie(s)
            </span>
        </div>

        <div class="dash-table-wrapper">
            <table class="dash-pro-table mv-stack">
                <thead>
                    <tr>
                        <th>Événement</th>
                        <th>Catégorie de Billet</th>
                        <th>Prix Unitaire</th>
                        <th>Billets Vendus</th>
                        <th>Billets Restants</th>
                        <th>Jauge & Quota Global</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($stocks_summary) > 0): ?>
                        <?php foreach ($stocks_summary as $stk): ?>
                            <?php 
                            $v = (int)$stk['vendus'];
                            $tot = (int)$stk['total_places'];
                            $r = max(0, $tot - $v);
                            $pct = ($tot > 0) ? min(100, round(($v / $tot) * 100)) : 0;
                            $bar_color = ($pct >= 85) ? '#ef4444' : (($pct >= 50) ? '#f59e0b' : '#10b981');
                            ?>
                            <tr>
                                <td data-label="Événement">
                                    <a href="mes-ventes.php?event_id=<?php echo $stk['event_id']; ?>" style="color: var(--dash-text); text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;" title="Filtrer les ventes de cet événement">
                                        <span><?php echo htmlspecialchars($stk['event_nom']); ?></span>
                                        <i class="fa-solid fa-filter" style="font-size: 0.68rem; color: var(--dash-muted);"></i>
                                    </a>
                                </td>
                                <td data-label="Catégorie">
                                    <a href="mes-ventes.php?type=<?php echo urlencode($stk['category_name']); ?>" style="text-decoration: none;" title="Filtrer toutes les ventes de catégorie <?php echo htmlspecialchars($stk['category_name']); ?>">
                                        <span style="background: #eeedfd; color: #5b50e6; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.8rem; display: inline-block; transition: background 0.15s;" onmouseover="this.style.background='#ddd6fe'" onmouseout="this.style.background='#eeedfd'">
                                            <i class="fa-solid fa-tag" style="font-size: 0.7rem; margin-right: 4px;"></i><?php echo htmlspecialchars($stk['category_name']); ?>
                                        </span>
                                    </a>
                                </td>
                                <td data-label="Prix Unitaire">
                                    <strong style="color: var(--dash-text);"><?php echo number_format($stk['prix'], 0, ',', ' '); ?> F</strong>
                                </td>
                                <td data-label="Billets Vendus">
                                    <a href="mes-ventes.php?event_id=<?php echo $stk['event_id']; ?>&type=<?php echo urlencode($stk['category_name']); ?>#table-acheteurs" style="text-decoration: none; color: #0ea5e9;" title="Voir les acheteurs de cette catégorie">
                                        <strong style="font-size: 0.92rem; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-circle-check"></i> <?php echo number_format($v, 0, ',', ' '); ?>
                                            <i class="fa-solid fa-arrow-down" style="font-size: 0.65rem;"></i>
                                        </strong>
                                    </a>
                                </td>
                                <td data-label="Billets Restants">
                                    <?php if ($r === 0 && $tot > 0): ?>
                                        <span style="background: #fef2f2; color: #ef4444; border: 1px solid #fee2e2; border-radius: 6px; padding: 3px 8px; font-weight: 800; font-size: 0.78rem;">
                                            <i class="fa-solid fa-ban"></i> Épuisé
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #ecfdf5; color: #10b981; border: 1px solid #d1fae5; border-radius: 6px; padding: 3px 8px; font-weight: 800; font-size: 0.78rem;">
                                            <i class="fa-solid fa-ticket"></i> <?php echo number_format($r, 0, ',', ' '); ?> restant(s)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Jauge & Quota Global">
                                    <a href="mes-ventes.php?event_id=<?php echo $stk['event_id']; ?>&type=<?php echo urlencode($stk['category_name']); ?>#table-acheteurs" style="text-decoration: none; color: inherit; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; flex-wrap: nowrap;" title="Cliquez pour filtrer cette formule">
                                        <span class="dash-gauge-track" style="width: clamp(60px, 8vw, 85px); flex-shrink: 0;">
                                            <span class="dash-gauge-progress" style="width: <?php echo $pct; ?>%; background: <?php echo $bar_color; ?>; display: block;"></span>
                                        </span>
                                        <strong style="font-size: 0.78rem; color: var(--dash-text); white-space: nowrap;"><?php echo $pct; ?>%</strong>
                                        <small style="color: var(--dash-muted); font-size: 0.74rem; white-space: nowrap;">(sur <?php echo $tot; ?>)</small>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--dash-muted); padding: 2.5rem 1rem;">
                                Aucune catégorie de billet configurée pour vos événements.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==============================================================================
         5. TRAÇABILITÉ & HISTORIQUE DÉTAILLÉ DES ACHETEURS (SECTIONS CLIQUABLES)
         ============================================================================== -->
    <div class="dash-card" id="table-acheteurs">
        <div class="dash-card-head">
            <div>
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-receipt" style="color: #10b981;"></i>
                    Traçabilité & Historique des Billets Achetés
                </h3>
                <div class="dash-card-subtitle">Liste nominative de chaque billet vendu avec coordonnées et statut d'accès</div>
            </div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <span style="font-size: 0.85rem; font-weight: 700; color: var(--dash-muted);">
                    <?php echo count($sales); ?> billet(s)
                </span>
                <span style="background: #ecfdf5; color: #10b981; border: 1px solid #d1fae5; border-radius: 8px; padding: 4px 10px; font-weight: 800; font-size: 0.82rem;">
                    Recettes : <?php echo number_format($total_filtre, 0, ',', ' '); ?> F
                </span>
            </div>
        </div>

        <!-- Tableau des billets vendus -->
        <div class="dash-table-wrapper">
            <table class="dash-pro-table mv-stack">
                <thead>
                    <tr>
                        <th>Code Unique</th>
                        <th>Événement</th>
                        <th>Catégorie</th>
                        <th>Prix Payé</th>
                        <th>Acheteur & Contact</th>
                        <th>Date d'Achat</th>
                        <th>Statut aux Portes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($sales) > 0): ?>
                        <?php foreach ($sales as $s): ?>
                            <tr>
                                <td data-label="Code Unique">
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <strong style="font-family: monospace; font-size: 0.88rem; color: var(--dash-primary); background: #f5f3ff; border: 1px solid #ddd6fe; padding: 3px 7px; border-radius: 6px; letter-spacing: 0.5px;">
                                            <?php echo htmlspecialchars($s['code_unique']); ?>
                                        </strong>
                                        <button type="button" onclick="navigator.clipboard.writeText('<?php echo $s['code_unique']; ?>'); alert('Code copié : <?php echo $s['code_unique']; ?>');" style="background: none; border: none; color: var(--dash-muted); cursor: pointer; padding: 3px; font-size: 0.8rem; border-radius: 4px;" title="Copier le code billet">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </td>
                                <td data-label="Événement">
                                    <a href="mes-ventes.php?event_id=<?php echo $s['event_id']; ?>" style="color: var(--dash-text); text-decoration: none; font-weight: 700; display: block;" title="Filtrer cet événement">
                                        <?php echo htmlspecialchars($s['event_name']); ?>
                                    </a>
                                    <?php if (!empty($s['date_evenement'])): ?>
                                        <small style="display: block; color: var(--dash-muted); font-size: 0.72rem;">
                                            Le <?php echo date('d/m/Y', strtotime($s['date_evenement'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Catégorie">
                                    <a href="mes-ventes.php?type=<?php echo urlencode($s['type_ticket']); ?>" style="text-decoration: none;" title="Filtrer par catégorie <?php echo htmlspecialchars($s['type_ticket']); ?>">
                                        <span style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 5px; font-weight: 700; font-size: 0.78rem; display: inline-block;">
                                            <?php echo htmlspecialchars($s['type_ticket']); ?>
                                        </span>
                                    </a>
                                </td>
                                <td data-label="Prix Payé">
                                    <strong style="color: var(--dash-primary); font-size: 0.92rem;"><?php echo number_format($s['prix'], 0, ',', ' '); ?> F</strong>
                                </td>
                                <td data-label="Acheteur & Contact">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 28px; height: 28px; border-radius: 50%; background: #eeedfd; color: var(--dash-primary); display: grid; place-items: center; font-size: 0.72rem; font-weight: 800; flex-shrink: 0;">
                                            <?php echo strtoupper(mb_substr($s['buyer_name'], 0, 1)); ?>
                                        </div>
                                        <div style="line-height: 1.25;">
                                            <strong style="font-size: 0.84rem; color: var(--dash-text);"><?php echo htmlspecialchars($s['buyer_name']); ?></strong>
                                            <div style="display: flex; gap: 8px; font-size: 0.74rem; color: var(--dash-muted); margin-top: 1px;">
                                                <a href="mailto:<?php echo htmlspecialchars($s['buyer_email']); ?>" style="color: var(--dash-muted); text-decoration: none;" title="Envoyer un e-mail">
                                                    <i class="fa-regular fa-envelope" style="font-size: 0.68rem;"></i> <?php echo htmlspecialchars($s['buyer_email']); ?>
                                                </a>
                                                <?php if (!empty($s['buyer_phone'])): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($s['buyer_phone']); ?>" style="color: #0284c7; text-decoration: none; font-weight: 600;" title="Appeler">
                                                        <i class="fa-solid fa-phone" style="font-size: 0.68rem;"></i> <?php echo htmlspecialchars($s['buyer_phone']); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Date d'Achat" style="white-space: nowrap; font-size: 0.8rem; color: var(--dash-muted);">
                                    <?php echo date('d/m/Y H:i', strtotime($s['date_achat'])); ?>
                                </td>
                                <td data-label="Statut aux Portes">
                                    <?php if ($s['statut'] === 'vendu'): ?>
                                        <a href="mes-ventes.php?statut=vendu#table-acheteurs" style="text-decoration: none;" title="Filtrer tous les billets valides">
                                            <span style="display: inline-flex; align-items: center; gap: 5px; background: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; padding: 3px 8px; border-radius: 6px; font-size: 0.78rem; font-weight: 700;">
                                                <i class="fa-solid fa-circle-check" style="font-size: 0.7rem;"></i> Valide
                                            </span>
                                        </a>
                                    <?php elseif ($s['statut'] === 'utilise'): ?>
                                        <a href="mes-ventes.php?statut=utilise#table-acheteurs" style="text-decoration: none;" title="Filtrer tous les billets scannés">
                                            <span style="display: inline-flex; align-items: center; gap: 5px; background: #ecfdf5; color: #16a34a; border: 1px solid #bbf7d0; padding: 3px 8px; border-radius: 6px; font-size: 0.78rem; font-weight: 700;">
                                                <i class="fa-solid fa-check-double" style="font-size: 0.7rem;"></i> Entré
                                            </span>
                                        </a>
                                        <?php if (!empty($s['date_utilisation']) || !empty($s['agent_nom'])): ?>
                                            <small style="display: block; color: var(--dash-muted); font-size: 0.7rem; margin-top: 2px;">
                                                <?php echo !empty($s['date_utilisation']) ? date('d/m H:i', strtotime($s['date_utilisation'])) : ''; ?>
                                                <?php echo !empty($s['agent_nom']) ? 'par ' . htmlspecialchars($s['agent_nom']) : ''; ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="display: inline-flex; align-items: center; gap: 5px; background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; padding: 3px 8px; border-radius: 6px; font-size: 0.78rem; font-weight: 700;">
                                            <?php echo ucfirst($s['statut']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--dash-muted); padding: 3rem 1rem;">
                                <i class="fa-solid fa-inbox" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                                Aucun billet trouvé pour ces critères de recherche.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==============================================================================
         6. PIED DE PAGE & SYNCHRONISATION EN DIRECT
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 1.5rem; margin-top: 1.5rem; border-top: 1px solid var(--dash-border); font-size: 0.82rem; color: var(--dash-muted); flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <span><i class="fa-solid fa-shield-check" style="color: #10b981;"></i> Données de billetterie synchronisées en temps réel</span>
            <span style="margin: 0 8px;">•</span>
            <span>Dernière mise à jour : <strong><?php echo date('d/m/Y à H:i:s'); ?></strong></span>
        </div>
        <div>
            <a href="mes-ventes.php" style="color: var(--dash-primary); font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                <i class="fa-solid fa-rotate"></i> Actualiser instantanément
            </a>
        </div>
    </div>
</div>

<script>
// ── Chart.js Configurations avec dégradé et isolation ─────────────────────────
const trendLabels   = <?php echo json_encode($trend_labels); ?>;
const trendTickets  = <?php echo json_encode($trend_tickets); ?>;
const trendRevenue  = <?php echo json_encode($trend_montant); ?>;

const ctxTrend = document.getElementById('mvChartTrend');
let trendChartInstance = null;

if (ctxTrend) {
    const tCtx = ctxTrend.getContext('2d');
    const gradRev = tCtx.createLinearGradient(0, 0, 0, 250);
    gradRev.addColorStop(0, 'rgba(91, 80, 230, 0.32)');
    gradRev.addColorStop(1, 'rgba(91, 80, 230, 0.01)');

    trendChartInstance = new Chart(ctxTrend, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Recettes (FCFA)',
                data: trendRevenue,
                borderColor: '#5b50e6',
                borderWidth: 3,
                fill: true,
                backgroundColor: gradRev,
                tension: 0.38,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#5b50e6',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            resizeDelay: 200,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(ctx) {
                            return ctx.parsed.y.toLocaleString('fr-FR') + ' FCFA';
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 10, weight: '600' } } },
                y: {
                    grid: { color: '#f1f5f9', borderDash: [4, 4] },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 10 },
                        callback: function(v) {
                            if (v >= 1000000) return (v / 1000000).toFixed(0) + 'M';
                            if (v >= 1000) return (v / 1000).toFixed(0) + 'k';
                            return v;
                        }
                    }
                }
            }
        }
    });
}

function switchTrendMode(mode, btn) {
    document.querySelectorAll('.dash-chart-tabs .dash-chart-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    if (!trendChartInstance) return;

    if (mode === 'revenue') {
        trendChartInstance.data.datasets[0].label = 'Recettes (FCFA)';
        trendChartInstance.data.datasets[0].data = trendRevenue;
        trendChartInstance.data.datasets[0].borderColor = '#5b50e6';
        trendChartInstance.options.scales.y.ticks.callback = function(v) {
            if (v >= 1000000) return (v / 1000000).toFixed(0) + 'M';
            if (v >= 1000) return (v / 1000).toFixed(0) + 'k';
            return v;
        };
        trendChartInstance.options.plugins.tooltip.callbacks.label = function(ctx) {
            return ctx.parsed.y.toLocaleString('fr-FR') + ' FCFA';
        };
    } else {
        trendChartInstance.data.datasets[0].label = 'Billets vendus';
        trendChartInstance.data.datasets[0].data = trendTickets;
        trendChartInstance.data.datasets[0].borderColor = '#0ea5e9';
        trendChartInstance.options.scales.y.ticks.callback = function(v) { return v; };
        trendChartInstance.options.plugins.tooltip.callbacks.label = function(ctx) {
            return ctx.parsed.y + ' billet(s)';
        };
    }
    trendChartInstance.update();
}

// ── Donut Remplissage Événements ──────────────────────────────────────────────
const ctxDonut = document.getElementById('mvChartDonut');
if (ctxDonut) {
    new Chart(ctxDonut, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($donut_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($donut_pcts); ?>,
                backgroundColor: <?php echo json_encode(array_slice($donut_colors, 0, count($donut_labels))); ?>,
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            resizeDelay: 200,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(ctx) {
                            return ctx.label + ' : ' + ctx.parsed + '% de capacité';
                        }
                    }
                }
            }
        }
    });
}
</script>

<?php include 'footer.php'; ?>
