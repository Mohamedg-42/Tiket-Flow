<?php
// ==============================================================================
// TABLEAU DE BORD PROMOTEUR 100% DYNAMIQUE (promoteur/dashboard.php)
// Connecté en direct à la base MySQL : filtres dynamiques (périodes & événements),
// calculs d'évolutions réelles, suivi des entrées et billetterie de l'organisateur.
// ==============================================================================

$page_title = "Tableau de Bord - Espace Organisateur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];

// 1. FILTRES DYNAMIQUES (PÉRIODE ET ÉVÉNEMENT DU PROMOTEUR)
$period = $_GET['period'] ?? '30d';
$selected_event_id = isset($_GET['event_id']) && $_GET['event_id'] !== '' ? (int)$_GET['event_id'] : null;

switch ($period) {
    case '7d':
        $sql_period_cur_tkt = "t.date_achat >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $sql_period_prev_tkt = "t.date_achat BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $points_count = 7;
        $step_days = 1;
        $period_label = "7 derniers jours";
        break;

    case 'this_month':
        $sql_period_cur_tkt = "MONTH(t.date_achat) = MONTH(CURDATE()) AND YEAR(t.date_achat) = YEAR(CURDATE())";
        $sql_period_prev_tkt = "MONTH(t.date_achat) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(t.date_achat) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
        $points_count = 6;
        $step_days = 5;
        $period_label = "Ce mois-ci (" . date('F Y') . ")";
        break;

    case 'this_year':
        $sql_period_cur_tkt = "YEAR(t.date_achat) = YEAR(CURDATE())";
        $sql_period_prev_tkt = "YEAR(t.date_achat) = YEAR(CURDATE()) - 1";
        $points_count = 7;
        $step_days = 50;
        $period_label = "Cette année (" . date('Y') . ")";
        break;

    case 'all':
        $sql_period_cur_tkt = "1=1";
        $sql_period_prev_tkt = "1=0";
        $points_count = 7;
        $step_days = 10;
        $period_label = "Toutes les périodes";
        break;

    case '30d':
    default:
        $period = '30d';
        $sql_period_cur_tkt = "t.date_achat >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $sql_period_prev_tkt = "t.date_achat BETWEEN DATE_SUB(NOW(), INTERVAL 60 DAY) AND DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $points_count = 7;
        $step_days = 5;
        $period_label = "30 derniers jours";
        break;
}

// Filtre conditionnel sur événement
$sql_ev_filter_tkt = $selected_event_id ? "AND t.event_id = " . $selected_event_id : "";
$sql_ev_filter_cap = $selected_event_id ? "AND tt.event_id = " . $selected_event_id : "";

// Liste des événements du promoteur pour le sélecteur dynamique
$stmt_my_ev_list = $pdo->prepare("SELECT id, nom FROM events WHERE user_id = ? ORDER BY nom ASC");
$stmt_my_ev_list->execute([$user_id]);
$my_events_list = $stmt_my_ev_list->fetchAll();

// Fonction dynamique de calcul d'évolution
function get_prom_growth($current, $previous) {
    if ($previous <= 0) {
        if ($current > 0) return ['dir' => 'up', 'text' => '+100%', 'class' => 'up'];
        return ['dir' => 'neutral', 'text' => '0%', 'class' => 'neutral'];
    }
    $diff = (($current - $previous) / $previous) * 100;
    $diff_round = round($diff, 1);
    if ($diff_round > 0) {
        return ['dir' => 'up', 'text' => '+' . $diff_round . '%', 'class' => 'up'];
    } elseif ($diff_round < 0) {
        return ['dir' => 'down', 'text' => $diff_round . '%', 'class' => 'down'];
    }
    return ['dir' => 'neutral', 'text' => '0%', 'class' => 'neutral'];
}

// ==============================================================================
// 2. CALCUL DYNAMIQUE DES KPIS DU PROMOTEUR
// ==============================================================================

// A. Recettes Brutes (période actuelle vs précédente)
$stmt_ca_cur = $pdo->prepare("
    SELECT COALESCE(SUM(t.prix), 0)
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt $sql_ev_filter_tkt
");
$stmt_ca_cur->execute([$user_id]);
$total_ventes = (float)$stmt_ca_cur->fetchColumn();

$stmt_ca_prev = $pdo->prepare("
    SELECT COALESCE(SUM(t.prix), 0)
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu', 'utilise') AND $sql_period_prev_tkt $sql_ev_filter_tkt
");
$stmt_ca_prev->execute([$user_id]);
$prev_ventes = (float)$stmt_ca_prev->fetchColumn();
$ca_growth = get_prom_growth($total_ventes, $prev_ventes);

// B. Revenu Net Promoteur (après déduction commission plateforme)
$stmt_comm = $pdo->prepare("
    SELECT COALESCE(SUM(t.prix * (COALESCE(e.commission_rate, 5.0) / 100)), 0)
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt $sql_ev_filter_tkt
");
$stmt_comm->execute([$user_id]);
$comm_prelevee = (float)$stmt_comm->fetchColumn();
$total_revenu_net = max(0, $total_ventes - $comm_prelevee);

// C. Billets vendus & Capacité
$stmt_tkt_cur = $pdo->prepare("
    SELECT COUNT(t.id)
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt $sql_ev_filter_tkt
");
$stmt_tkt_cur->execute([$user_id]);
$total_tickets_sold = (int)$stmt_tkt_cur->fetchColumn();

$stmt_tkt_prev = $pdo->prepare("
    SELECT COUNT(t.id)
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut IN ('vendu', 'utilise') AND $sql_period_prev_tkt $sql_ev_filter_tkt
");
$stmt_tkt_prev->execute([$user_id]);
$prev_tickets_sold = (int)$stmt_tkt_prev->fetchColumn();
$tkt_growth = get_prom_growth($total_tickets_sold, $prev_tickets_sold);

$stmt_prom_cap = $pdo->prepare("
    SELECT COALESCE(SUM(tt.quantite), 0) 
    FROM ticket_types tt
    JOIN events e ON tt.event_id = e.id
    WHERE e.user_id = ? $sql_ev_filter_cap
");
$stmt_prom_cap->execute([$user_id]);
$capacite_totale = (int)$stmt_prom_cap->fetchColumn();
$taux_occupation = ($capacite_totale > 0) ? min(100, round(($total_tickets_sold / $capacite_totale) * 100)) : 0;

// D. Commandes et Panier Moyen
$stmt_prom_orders = $pdo->prepare("
    SELECT COUNT(DISTINCT o.id) as nb_orders, COALESCE(SUM(t.prix), 0) as total_ca
    FROM orders o
    JOIN tickets t ON t.order_id = o.id
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND o.statut = 'payee' AND $sql_period_cur_tkt $sql_ev_filter_tkt
");
$stmt_prom_orders->execute([$user_id]);
$prom_ord_res = $stmt_prom_orders->fetch();
$total_orders = (int)$prom_ord_res['nb_orders'];
$panier_moyen = ($total_orders > 0) ? round($prom_ord_res['total_ca'] / $total_orders) : 0;

// E. Check-in et Entrées aux portes
$stmt_prom_used = $pdo->prepare("
    SELECT COUNT(t.id)
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND t.statut = 'utilise' AND $sql_period_cur_tkt $sql_ev_filter_tkt
");
$stmt_prom_used->execute([$user_id]);
$total_tickets_used = (int)$stmt_prom_used->fetchColumn();
$taux_presence = ($total_tickets_sold > 0) ? min(100, round(($total_tickets_used / $total_tickets_sold) * 100)) : 0;

// ==============================================================================
// 3. DONNÉES TEMPORELLES COMPACTES POUR LE GRAPHIQUE (7 PALIERS DYNAMIQUES)
// ==============================================================================
$chart_labels = [];
$chart_revenue_vals = [];
$chart_tickets_vals = [];

for ($i = $points_count - 1; $i >= 0; $i--) {
    $offset = $i * $step_days;
    $d = date('Y-m-d', strtotime("-$offset days"));
    $chart_labels[] = date('d M', strtotime($d));

    $sql_pt_ev = $selected_event_id ? "AND t.event_id = $selected_event_id" : "";
    $stmt_pt = $pdo->prepare("
        SELECT COALESCE(SUM(t.prix), 0) as ca, COUNT(*) as nb
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        WHERE e.user_id = ? AND t.statut IN ('vendu', 'utilise')
          AND DATE(t.date_achat) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ?
          $sql_pt_ev
    ");
    $stmt_pt->execute([$user_id, $d, max(1, $step_days - 1), $d]);
    $row_pt = $stmt_pt->fetch();
    $chart_revenue_vals[] = (float)($row_pt['ca'] ?? 0);
    $chart_tickets_vals[] = (int)($row_pt['nb'] ?? 0);
}

$sparkline_data = array_map(function($v) { return max(1, (int)$v); }, $chart_revenue_vals);

// ==============================================================================
// 4. RÉPARTITION MOBILE MONEY DYNAMIQUE DU PROMOTEUR
// ==============================================================================
$stmt_prom_methods = $pdo->prepare("
    SELECT LOWER(p.methode) as methode, COALESCE(SUM(t.prix), 0) as total_methode
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    JOIN tickets t ON t.order_id = o.id
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND p.statut = 'paye' AND $sql_period_cur_tkt $sql_ev_filter_tkt
    GROUP BY LOWER(p.methode)
");
$stmt_prom_methods->execute([$user_id]);
$prom_methods_db = $stmt_prom_methods->fetchAll(PDO::FETCH_ASSOC);

$methods_map = ['wave' => 0, 'orange_money' => 0, 'mtn_money' => 0, 'moov_money' => 0];
foreach ($prom_methods_db as $m) {
    $k = $m['methode'];
    if (isset($methods_map[$k])) {
        $methods_map[$k] = (float)$m['total_methode'];
    }
}
$total_methods = array_sum($methods_map);

// ==============================================================================
// 5. STATUT DYNAMIQUE DES COMMANDES DU PROMOTEUR
// ==============================================================================
$stmt_orders_st = $pdo->prepare("
    SELECT o.statut, COUNT(DISTINCT o.id) as nb 
    FROM orders o
    JOIN tickets t ON t.order_id = o.id
    JOIN events e ON t.event_id = e.id
    WHERE e.user_id = ? AND $sql_period_cur_tkt $sql_ev_filter_tkt
    GROUP BY o.statut
");
$stmt_orders_st->execute([$user_id]);
$prom_ord_st_map = $stmt_orders_st->fetchAll(PDO::FETCH_KEY_PAIR);

$orders_payees   = (int)($prom_ord_st_map['payee'] ?? 0);
$orders_attente  = (int)($prom_ord_st_map['en_attente'] ?? 0);
$orders_annulees = (int)($prom_ord_st_map['annulee'] ?? 0) + (int)($prom_ord_st_map['echouee'] ?? 0);
$orders_all = $orders_payees + $orders_attente + $orders_annulees;

// ==============================================================================
// 6. TOP ÉVÉNEMENTS DYNAMIQUE DU PROMOTEUR
// ==============================================================================
$stmt_my_top = $pdo->prepare("
    SELECT e.id, e.nom, e.image, e.date_evenement, e.heure, e.categorie,
           COUNT(t.id) as tickets_vendus,
           COALESCE(SUM(t.prix), 0) as ca_total,
           (SELECT COALESCE(SUM(tt.quantite), 0) FROM ticket_types tt WHERE tt.event_id = e.id) as capacite
    FROM events e
    LEFT JOIN tickets t ON t.event_id = e.id AND t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt
    WHERE e.user_id = ?
    GROUP BY e.id
    ORDER BY ca_total DESC, tickets_vendus DESC
    LIMIT 6
");
$stmt_my_top->execute([$user_id]);
$my_top_events = $stmt_my_top->fetchAll();

// ==============================================================================
// 7. VENTES PAR TYPE DE TICKET DYNAMIQUES
// ==============================================================================
$stmt_tt_prom = $pdo->prepare("
    SELECT tt.nom, COALESCE(SUM(t.prix), 0) as ca_type, COUNT(t.id) as total_vendus
    FROM ticket_types tt
    JOIN events e ON tt.event_id = e.id
    LEFT JOIN tickets t ON t.ticket_type_id = tt.id AND t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt
    WHERE e.user_id = ? $sql_ev_filter_cap
    GROUP BY tt.nom
    ORDER BY ca_type DESC
    LIMIT 5
");
$stmt_tt_prom->execute([$user_id]);
$tt_stats_prom = $stmt_tt_prom->fetchAll();

$tt_labels = [];
$tt_values = [];
foreach ($tt_stats_prom as $tts) {
    $tt_labels[] = $tts['nom'];
    $tt_values[] = (float)$tts['ca_type'];
}

// ==============================================================================
// 8. FLUX OPÉRATIONNEL EN DIRECT DE L'ORGANISATEUR (100% REQUÊTE BD)
// ==============================================================================
$live_activities = [];
try {
    $stmt_live_prom = $pdo->prepare("
        SELECT t.id, t.prix, t.statut, t.date_achat, t.client_nom,
               e.nom as event_nom, tt.nom as ticket_type
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN ticket_types tt ON t.ticket_type_id = tt.id
        WHERE e.user_id = ?
        ORDER BY t.date_achat DESC
        LIMIT 6
    ");
    $stmt_live_prom->execute([$user_id]);
    $live_activities = $stmt_live_prom->fetchAll();
} catch (PDOException $e) {
    $live_activities = [];
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">
<!-- Inclusion Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="dash-container">
    <!-- ==============================================================================
         1. BARRE DE FILTRES DYNAMIQUES DU PROMOTEUR
         ============================================================================== -->
    <div class="dash-header-section">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-chart-pie" style="color: var(--dash-primary); font-size: 1.6rem;"></i>
                Tableau de Bord Organisateur
            </h1>
            <p>Données de billetterie en temps réel pour la période : <strong><?php echo htmlspecialchars($period_label); ?></strong></p>
        </div>

        <div class="dash-filter-bar">
            <!-- Formulaire de filtrage dynamique -->
            <form method="GET" action="dashboard.php" id="promFilterForm" style="display: flex; align-items: center; gap: 0.5rem; margin: 0; flex-wrap: wrap;">
                <!-- Filtre Période -->
                <div class="dash-control-select" style="padding: 0.4rem 0.8rem;">
                    <i class="fa-regular fa-calendar" style="color: var(--dash-primary);"></i>
                    <select name="period" onchange="document.getElementById('promFilterForm').submit();" style="border: none; background: transparent; font-weight: 700; color: var(--dash-text); outline: none; cursor: pointer;">
                        <option value="7d" <?php echo $period === '7d' ? 'selected' : ''; ?>>7 derniers jours</option>
                        <option value="30d" <?php echo $period === '30d' ? 'selected' : ''; ?>>30 derniers jours</option>
                        <option value="this_month" <?php echo $period === 'this_month' ? 'selected' : ''; ?>>Ce mois-ci</option>
                        <option value="this_year" <?php echo $period === 'this_year' ? 'selected' : ''; ?>>Cette année</option>
                        <option value="all" <?php echo $period === 'all' ? 'selected' : ''; ?>>Toutes les périodes</option>
                    </select>
                </div>

                <!-- Filtre Événement spécifique -->
                <div class="dash-control-select" style="padding: 0.4rem 0.8rem;">
                    <i class="fa-solid fa-filter" style="color: var(--dash-secondary);"></i>
                    <select name="event_id" onchange="document.getElementById('promFilterForm').submit();" style="border: none; background: transparent; font-weight: 700; color: var(--dash-text); outline: none; cursor: pointer; max-width: 200px;">
                        <option value="">Tous mes événements</option>
                        <?php foreach ($my_events_list as $ev_item): ?>
                            <option value="<?php echo $ev_item['id']; ?>" <?php echo $selected_event_id === (int)$ev_item['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(mb_strimwidth($ev_item['nom'], 0, 25, '...')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <button type="button" class="dash-btn-action" onclick="window.print()" title="Imprimer le bilan des ventes">
                <i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i>
                <span>Imprimer Bilan</span>
            </button>

            <a href="demande-evenement.php" class="dash-btn-action btn-primary">
                <i class="fa-solid fa-plus"></i>
                <span>Créer un Événement</span>
            </a>
        </div>
    </div>

    <!-- ==============================================================================
         2. RACCOURCIS D'ACTIONS RAPIDES
         ============================================================================== -->
    <div class="dash-quick-shortcuts">
        <a href="solde.php" class="dash-shortcut-card" style="border-left: 4px solid #10b981;">
            <div class="dash-shortcut-icon" style="background: #ecfdf5; color: #10b981;">
                <i class="fa-solid fa-wallet"></i>
            </div>
            <div class="dash-shortcut-text">
                <strong style="color: #10b981; font-size: 0.95rem;"><?php echo number_format($solde_actuel, 0, ',', ' '); ?> F</strong>
                <small>Solde Disponible (Retrait)</small>
            </div>
        </a>

        <a href="mes-ventes.php" class="dash-shortcut-card">
            <div class="dash-shortcut-icon" style="background: #eeedfd; color: var(--dash-primary);">
                <i class="fa-solid fa-ticket"></i>
            </div>
            <div class="dash-shortcut-text">
                <strong>Disponibilité Billets</strong>
                <small>Tarifs, Quotas & Réductions</small>
            </div>
        </a>

        <a href="mes-evenements.php" class="dash-shortcut-card">
            <div class="dash-shortcut-icon" style="background: #fff7ed; color: #f97316;">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
            <div class="dash-shortcut-text">
                <strong>Mes Événements</strong>
                <small>Gestion des programmes</small>
            </div>
        </a>

        <a href="agents.php" class="dash-shortcut-card">
            <div class="dash-shortcut-icon" style="background: #f0f9ff; color: #0ea5e9;">
                <i class="fa-solid fa-users-gear"></i>
            </div>
            <div class="dash-shortcut-text">
                <strong>Agents de Contrôle</strong>
                <small>Scans & Accès aux portes</small>
            </div>
        </a>
    </div>

    <!-- ==============================================================================
         3. BANDEAU DE 6 KPIS FINANCIERS AVEC ÉVOLUTIONS DYNAMIQUES
         ============================================================================== -->
    <div class="dash-kpi-grid-6">
        <!-- 1. Ventes Brutes -->
        <a href="mes-ventes.php" class="dash-kpi-card" title="Consulter mes ventes détaillées">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap purple">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </div>
                <span class="dash-kpi-badge-pill <?php echo $ca_growth['class']; ?>">
                    <i class="fa-solid fa-arrow-trend-<?php echo $ca_growth['dir'] === 'down' ? 'down' : 'up'; ?>"></i> <?php echo $ca_growth['text']; ?>
                </span>
            </div>
            <div class="dash-kpi-title">Recettes Brutes</div>
            <div class="dash-kpi-amount"><?php echo number_format($total_ventes, 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub">Total billets vendus sur la période <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="promSpark1"></canvas>
            </div>
        </a>

        <!-- 2. Revenu Net Promoteur -->
        <a href="solde.php" class="dash-kpi-card" title="Voir mon solde disponible et demander un retrait">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap green">
                    <i class="fa-solid fa-piggy-bank"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-check"></i> Net
                </span>
            </div>
            <div class="dash-kpi-title">Revenu Net (95%)</div>
            <div class="dash-kpi-amount" style="color: #10b981;"><?php echo number_format($total_revenu_net, 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub">Après déduction des commissions <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="promSpark2"></canvas>
            </div>
        </a>

        <!-- 3. Billets vendus -->
        <a href="mes-ventes.php" class="dash-kpi-card" title="Gérer mes quotas et types de billets">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap blue">
                    <i class="fa-solid fa-ticket"></i>
                </div>
                <span class="dash-kpi-badge-pill <?php echo $tkt_growth['class']; ?>">
                    <i class="fa-solid fa-arrow-trend-<?php echo $tkt_growth['dir'] === 'down' ? 'down' : 'up'; ?>"></i> <?php echo $tkt_growth['text']; ?>
                </span>
            </div>
            <div class="dash-kpi-title">Billets Écoulés</div>
            <div class="dash-kpi-amount"><?php echo number_format($total_tickets_sold, 0, ',', ' '); ?></div>
            <div class="dash-kpi-sub">Sur <?php echo number_format($capacite_totale, 0, ',', ' '); ?> places créées <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="promSpark3"></canvas>
            </div>
        </a>

        <!-- 4. Panier Moyen -->
        <a href="mes-ventes.php" class="dash-kpi-card" title="Voir les détails de mes ventes">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap amber">
                    <i class="fa-solid fa-basket-shopping"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-coins"></i> Moyen
                </span>
            </div>
            <div class="dash-kpi-title">Panier Moyen</div>
            <div class="dash-kpi-amount"><?php echo number_format($panier_moyen, 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub"><?php echo number_format($total_orders, 0, ',', ' '); ?> commandes passées <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="promSpark4"></canvas>
            </div>
        </a>

        <!-- 5. Check-in & Entrées -->
        <a href="agents.php" class="dash-kpi-card" title="Gérer mes agents de contrôle aux entrées">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap orange">
                    <i class="fa-solid fa-user-check"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-qrcode"></i> Scan
                </span>
            </div>
            <div class="dash-kpi-title">Check-in Effectués</div>
            <div class="dash-kpi-amount"><?php echo number_format($total_tickets_used, 0, ',', ' '); ?></div>
            <div class="dash-kpi-sub"><?php echo $taux_presence; ?>% de présence réelle <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="promSpark5"></canvas>
            </div>
        </a>

        <!-- 6. Taux de Remplissage -->
        <a href="mes-evenements.php" class="dash-kpi-card" title="Consulter et gérer mes événements">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap pink">
                    <i class="fa-solid fa-percent"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-gauge"></i> Jauge
                </span>
            </div>
            <div class="dash-kpi-title">Taux de Remplissage</div>
            <div class="dash-kpi-amount" style="color: #ec4899;"><?php echo $taux_occupation; ?>%</div>
            <div class="dash-kpi-sub">Capacité globale utilisée <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="promSpark6"></canvas>
            </div>
        </a>
    </div>

    <!-- ==============================================================================
         4. LIGNE ANALYTIQUE EN 3 COLONNES ÉQUILIBRÉES : COURBE + CANAUX + STATUT
         ============================================================================== -->
    <div class="dash-row-3cols">
        <!-- 1. Courbe d'évolution Recettes / Billets -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-chart-area" style="color: var(--dash-primary);"></i>
                        Évolution des Recettes
                    </h3>
                    <div class="dash-card-subtitle">7 repères clés de la période sélectionnée</div>
                </div>
                <div class="dash-chart-tabs">
                    <button type="button" class="dash-chart-tab active" onclick="switchPromChartMode('revenue', this)">Recettes (F)</button>
                    <button type="button" class="dash-chart-tab" onclick="switchPromChartMode('tickets', this)">Billets</button>
                </div>
            </div>
            <div style="height: 225px; max-height: 225px; position: relative; width: 100%; overflow: hidden;">
                <canvas id="promEvolutionChart"></canvas>
            </div>
        </div>

        <!-- 2. Donut Moyens de Paiement Mobile Money -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-mobile-screen-button" style="color: var(--dash-secondary);"></i>
                        Canaux de Paiement
                    </h3>
                    <div class="dash-card-subtitle">Répartition réelle sur la période</div>
                </div>
            </div>
            <div class="dash-donut-box" style="height: 180px;">
                <canvas id="promPaymentChart"></canvas>
                <div class="dash-donut-info-center">
                    <strong style="font-size: 1.15rem;"><?php echo number_format($total_methods, 0, ',', ' '); ?> F</strong>
                    <span style="font-size: 0.7rem;">Total Encaissé</span>
                </div>
            </div>
            <div class="dash-donut-legend-list" style="margin-top: 0.75rem; gap: 0.45rem;">
                <?php
                $methods_cfg = [
                    'wave' => ['label' => 'Wave', 'color' => '#1dc4e9'],
                    'orange_money' => ['label' => 'Orange Money', 'color' => '#ff7900'],
                    'mtn_money' => ['label' => 'MTN MoMo', 'color' => '#ffcc00'],
                    'moov_money' => ['label' => 'Moov Money', 'color' => '#0066b3']
                ];
                foreach ($methods_map as $key => $amount):
                    $cfg = $methods_cfg[$key] ?? ['label' => ucfirst($key), 'color' => '#64748b'];
                    $pct = ($total_methods > 0) ? round(($amount / $total_methods) * 100) : 0;
                ?>
                    <a href="mes-ventes.php" class="dash-legend-entry" title="Voir mes ventes <?php echo htmlspecialchars($cfg['label']); ?>">
                        <div class="dash-legend-name">
                            <span class="dash-legend-bullet" style="background: <?php echo $cfg['color']; ?>;"></span>
                            <span><?php echo $cfg['label']; ?></span>
                        </div>
                        <div class="dash-legend-details">
                            <span class="dash-legend-num"><?php echo number_format($amount, 0, ',', ' '); ?> F</span>
                            <span class="dash-legend-percent"><?php echo $pct; ?>%</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 3. Donut Statut des Commandes -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-pie-chart" style="color: #10b981;"></i>
                        Statut des Commandes
                    </h3>
                    <div class="dash-card-subtitle">Volume réel de vos événements</div>
                </div>
            </div>
            <div class="dash-donut-box" style="height: 180px;">
                <canvas id="promOrdersStatusChart"></canvas>
                <div class="dash-donut-info-center">
                    <strong style="font-size: 1.3rem;"><?php echo number_format($orders_all, 0, ',', ' '); ?></strong>
                    <span style="font-size: 0.7rem;">Total</span>
                </div>
            </div>
            <div class="dash-donut-legend-list" style="margin-top: 0.75rem; gap: 0.45rem;">
                <a href="mes-ventes.php" class="dash-legend-entry" title="Voir mes commandes payées">
                    <div class="dash-legend-name">
                        <span class="dash-legend-bullet" style="background: #10b981;"></span>
                        <span>Payées</span>
                    </div>
                    <div class="dash-legend-details">
                        <span class="dash-legend-num"><?php echo number_format($orders_payees, 0, ',', ' '); ?></span>
                        <span class="dash-legend-percent">(<?php echo $orders_all > 0 ? round(($orders_payees / $orders_all) * 100) : 0; ?>%)</span>
                    </div>
                </a>
                <a href="mes-ventes.php" class="dash-legend-entry" title="Voir mes commandes en attente">
                    <div class="dash-legend-name">
                        <span class="dash-legend-bullet" style="background: #f59e0b;"></span>
                        <span>En attente</span>
                    </div>
                    <div class="dash-legend-details">
                        <span class="dash-legend-num"><?php echo number_format($orders_attente, 0, ',', ' '); ?></span>
                        <span class="dash-legend-percent">(<?php echo $orders_all > 0 ? round(($orders_attente / $orders_all) * 100) : 0; ?>%)</span>
                    </div>
                </a>
                <a href="mes-ventes.php" class="dash-legend-entry" title="Voir mes commandes annulées">
                    <div class="dash-legend-name">
                        <span class="dash-legend-bullet" style="background: #ef4444;"></span>
                        <span>Annulées</span>
                    </div>
                    <div class="dash-legend-details">
                        <span class="dash-legend-num"><?php echo number_format($orders_annulees, 0, ',', ' '); ?></span>
                        <span class="dash-legend-percent">(<?php echo $orders_all > 0 ? round(($orders_annulees / $orders_all) * 100) : 0; ?>%)</span>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- ==============================================================================
         5. LIGNE 3 : VOS ÉVÉNEMENTS + TYPES DE TICKETS + FLUX DIRECT
         ============================================================================== -->
    <div class="dash-grid-3cols">
        <!-- 1. Vos Événements & Remplissage -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-calendar-check" style="color: #f59e0b;"></i>
                        Performances de Vos Événements
                    </h3>
                    <div class="dash-card-subtitle">Recettes générées sur la période</div>
                </div>
            </div>
            <div class="dash-table-wrapper">
                <table class="dash-pro-table">
                    <thead>
                        <tr>
                            <th>Événement</th>
                            <th>Date</th>
                            <th>Vendus</th>
                            <th>Recette</th>
                            <th>Occupation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($my_top_events)): ?>
                            <?php foreach ($my_top_events as $ev): ?>
                                <?php
                                $cap = (int)$ev['capacite'];
                                $vds = (int)$ev['tickets_vendus'];
                                $pct = ($cap > 0) ? min(100, round(($vds / $cap) * 100)) : 0;
                                $color = ($pct >= 75) ? '#10b981' : (($pct >= 50) ? '#f59e0b' : '#64748b');
                                
                                $ev_img = 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=150&q=80';
                                if (!empty($ev['image'])) {
                                    if (strpos($ev['image'], 'http') === 0) {
                                        $ev_img = htmlspecialchars($ev['image']);
                                    } elseif (file_exists('../uploads/events/' . $ev['image'])) {
                                        $ev_img = '../uploads/events/' . htmlspecialchars($ev['image']);
                                    }
                                }
                                ?>
                                <tr class="dash-clickable-row" onclick="window.location='mes-ventes.php?event_id=<?php echo $ev['id']; ?>'" title="Cliquer pour gérer les ventes de cet événement">
                                    <td>
                                        <div class="dash-event-cell">
                                            <img src="<?php echo $ev_img; ?>" alt="" class="dash-event-poster">
                                            <div class="dash-event-meta">
                                                <strong><?php echo htmlspecialchars($ev['nom']); ?></strong>
                                                <small><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($ev['heure'] ?: '20:00'); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="white-space: nowrap; font-size: 0.8rem;"><?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?></td>
                                    <td><strong><?php echo number_format($vds, 0, ',', ' '); ?></strong></td>
                                    <td style="font-weight: 700; color: var(--dash-primary);"><?php echo number_format((float)$ev['ca_total'], 0, ',', ' '); ?> F</td>
                                    <td>
                                        <div style="display: flex; align-items: center;">
                                            <span class="dash-gauge-track">
                                                <span class="dash-gauge-progress" style="width: <?php echo $pct; ?>%; background: <?php echo $color; ?>; display: block;"></span>
                                            </span>
                                            <strong style="color: <?php echo $color; ?>; font-size: 0.76rem;"><?php echo $pct; ?>%</strong>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--dash-muted); padding: 2rem;">
                                    Vous n'avez pas encore d'événements créés. <a href="demande-evenement.php" style="color: var(--dash-primary); font-weight: 700;">Proposer un événement</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. Ventes par Type de Billet -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-tags" style="color: var(--dash-primary);"></i>
                        Recettes par Type de Billet
                    </h3>
                    <div class="dash-card-subtitle">Volume financier par catégorie</div>
                </div>
            </div>
            <div style="height: 250px; max-height: 250px; position: relative; width: 100%; overflow: hidden;">
                <canvas id="promTicketTypeChart"></canvas>
            </div>
        </div>

        <!-- 3. Flux d'Activité Récent 100% en Direct -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-bolt" style="color: #10b981;"></i>
                        Activités Récentes en Direct
                    </h3>
                    <div class="dash-card-subtitle">Ventes et scans de vos événements</div>
                </div>
                <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.75rem; font-weight: 700; color: #10b981; background: #ecfdf5; padding: 3px 8px; border-radius: 6px;">
                    <span style="width: 7px; height: 7px; border-radius: 50%; background: #10b981;"></span> Live
                </span>
            </div>
            <div class="dash-live-stream">
                <?php if (!empty($live_activities)): ?>
                    <?php foreach ($live_activities as $act): ?>
                        <a href="mes-ventes.php" class="dash-stream-card" title="Voir mes ventes">
                            <div class="dash-stream-left">
                                <div class="dash-stream-icon" style="background: #eeedfd; color: var(--dash-primary);">
                                    <i class="fa-solid fa-ticket"></i>
                                </div>
                                <div class="dash-stream-texts">
                                    <span class="dash-stream-title"><?php echo htmlspecialchars($act['ticket_type'] ?: 'Billet'); ?> - <?php echo htmlspecialchars(mb_strimwidth($act['event_nom'], 0, 18, '...')); ?></span>
                                    <span class="dash-stream-desc">Client : <?php echo htmlspecialchars($act['client_nom'] ?: 'Acheteur Web'); ?></span>
                                </div>
                            </div>
                            <div class="dash-stream-right">
                                <span class="dash-stream-val">+<?php echo number_format($act['prix'], 0, ',', ' '); ?> F</span>
                                <span class="dash-stream-time"><?php echo date('d/m H:i', strtotime($act['date_achat'])); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; color: var(--dash-muted); padding: 2.5rem 1rem;">
                        <i class="fa-solid fa-inbox" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                        <span style="font-size: 0.85rem;">Aucune transaction enregistrée récemment.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==============================================================================
         6. PIED DE PAGE DE SYNCHRONISATION
         ============================================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 1.5rem; margin-top: 1.5rem; border-top: 1px solid var(--dash-border); font-size: 0.82rem; color: var(--dash-muted); flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <span><i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Données financières synchronisées</span>
            <span style="margin: 0 8px;">•</span>
            <span>Dernière synchronisation : <strong><?php echo date('d/m/Y à H:i:s'); ?></strong></span>
        </div>
        <div>
            <a href="dashboard.php?period=<?php echo urlencode($period); ?><?php echo $selected_event_id ? '&event_id=' . $selected_event_id : ''; ?>" style="color: var(--dash-primary); font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                <i class="fa-solid fa-rotate"></i> Actualiser instantanément
            </a>
        </div>
    </div>
</div>

<!-- ==============================================================================
     SCRIPTS CHART.JS ORGANISATEUR
     ============================================================================== -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Sparklines pour les 6 KPIs
    function drawPromSparkline(canvasId, lineColor, fillColor, dataPoints) {
        const el = document.getElementById(canvasId);
        if (!el) return;
        const ctx = el.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 42);
        grad.addColorStop(0, fillColor);
        grad.addColorStop(1, 'rgba(255, 255, 255, 0)');

        new Chart(el, {
            type: 'line',
            data: {
                labels: dataPoints.map((_, i) => i),
                datasets: [{
                    data: dataPoints,
                    borderColor: lineColor,
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: true,
                    backgroundColor: grad,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 200,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: {
                    x: { display: false },
                    y: { display: false, min: 0 }
                }
            }
        });
    }

    const dynSparkProm = <?php echo json_encode(!empty($sparkline_data) ? $sparkline_data : [1, 2, 1, 3, 2, 4, 3]); ?>;
    drawPromSparkline('promSpark1', '#5b50e6', 'rgba(91, 80, 230, 0.28)', dynSparkProm);
    drawPromSparkline('promSpark2', '#10b981', 'rgba(16, 185, 129, 0.28)', dynSparkProm);
    drawPromSparkline('promSpark3', '#0ea5e9', 'rgba(14, 165, 233, 0.28)', dynSparkProm);
    drawPromSparkline('promSpark4', '#eab308', 'rgba(234, 179, 8, 0.28)',  dynSparkProm);
    drawPromSparkline('promSpark5', '#f97316', 'rgba(249, 115, 22, 0.28)', dynSparkProm);
    drawPromSparkline('promSpark6', '#ec4899', 'rgba(236, 72, 153, 0.28)', dynSparkProm);

    // 2. Courbe Recettes & Billets du promoteur
    const ctxMainProm = document.getElementById('promEvolutionChart');
    let promChartInstance = null;

    if (ctxMainProm) {
        const mCtx = ctxMainProm.getContext('2d');
        const revGrad = mCtx.createLinearGradient(0, 0, 0, 225);
        revGrad.addColorStop(0, 'rgba(91, 80, 230, 0.32)');
        revGrad.addColorStop(1, 'rgba(91, 80, 230, 0.01)');

        promChartInstance = new Chart(ctxMainProm, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: "Recettes (FCFA)",
                    data: <?php echo json_encode($chart_revenue_vals); ?>,
                    borderColor: '#5b50e6',
                    borderWidth: 3,
                    fill: true,
                    backgroundColor: revGrad,
                    tension: 0.38,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#5b50e6',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 7
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
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 12 },
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
                    x: {
                        grid: { display: false },
                        ticks: { color: '#94a3b8', font: { size: 11, weight: '600' } }
                    },
                    y: {
                        grid: { color: '#f1f5f9', borderDash: [4, 4] },
                        ticks: {
                            color: '#94a3b8',
                            font: { size: 11 },
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

    // Basculement Recettes / Billets
    window.switchPromChartMode = function(mode, btn) {
        document.querySelectorAll('.dash-chart-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        if (!promChartInstance) return;

        if (mode === 'tickets') {
            promChartInstance.data.datasets[0].label = "Billets vendus";
            promChartInstance.data.datasets[0].data = <?php echo json_encode($chart_tickets_vals); ?>;
            promChartInstance.data.datasets[0].borderColor = '#0ea5e9';
            promChartInstance.options.plugins.tooltip.callbacks.label = function(ctx) {
                return ctx.parsed.y + ' billets vendus';
            };
            promChartInstance.options.scales.y.ticks.callback = function(v) { return v; };
        } else {
            promChartInstance.data.datasets[0].label = "Recettes (FCFA)";
            promChartInstance.data.datasets[0].data = <?php echo json_encode($chart_revenue_vals); ?>;
            promChartInstance.data.datasets[0].borderColor = '#5b50e6';
            promChartInstance.options.plugins.tooltip.callbacks.label = function(ctx) {
                return ctx.parsed.y.toLocaleString('fr-FR') + ' FCFA';
            };
            promChartInstance.options.scales.y.ticks.callback = function(v) {
                if (v >= 1000000) return (v / 1000000).toFixed(0) + 'M';
                if (v >= 1000) return (v / 1000).toFixed(0) + 'k';
                return v;
            };
        }
        promChartInstance.update();
    };

    // 3. Donut Moyens de Paiement
    const ctxPaymentProm = document.getElementById('promPaymentChart');
    if (ctxPaymentProm) {
        new Chart(ctxPaymentProm, {
            type: 'doughnut',
            data: {
                labels: ['Wave', 'Orange Money', 'MTN MoMo', 'Moov Money'],
                datasets: [{
                    data: [
                        <?php echo (float)$methods_map['wave']; ?>,
                        <?php echo (float)$methods_map['orange_money']; ?>,
                        <?php echo (float)$methods_map['mtn_money']; ?>,
                        <?php echo (float)$methods_map['moov_money']; ?>
                    ],
                    backgroundColor: ['#1dc4e9', '#ff7900', '#ffcc00', '#0066b3'],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '74%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 10,
                        callbacks: {
                            label: function(ctx) {
                                return ctx.label + ' : ' + ctx.parsed.toLocaleString('fr-FR') + ' FCFA';
                            }
                        }
                    }
                }
            }
        });
    }

    // 4. Donut Statut des Commandes
    const ctxOrdersProm = document.getElementById('promOrdersStatusChart');
    if (ctxOrdersProm) {
        new Chart(ctxOrdersProm, {
            type: 'doughnut',
            data: {
                labels: ['Payées', 'En attente', 'Annulées'],
                datasets: [{
                    data: [<?php echo $orders_payees; ?>, <?php echo $orders_attente; ?>, <?php echo $orders_annulees; ?>],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '74%',
                plugins: { legend: { display: false } }
            }
        });
    }

    // 5. Histogramme Bar Chart des Types de Billets
    const ctxBarProm = document.getElementById('promTicketTypeChart');
    if (ctxBarProm) {
        new Chart(ctxBarProm, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(!empty($tt_labels) ? $tt_labels : ['Aucun billet']); ?>,
                datasets: [{
                    label: "Chiffre d'affaires",
                    data: <?php echo json_encode(!empty($tt_values) ? $tt_values : [0]); ?>,
                    backgroundColor: '#5b50e6',
                    borderRadius: 8,
                    borderSkipped: false,
                    barThickness: 32
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#94a3b8', font: { size: 11, weight: '700' } }
                    },
                    y: {
                        grid: { color: '#f1f5f9', borderDash: [4, 4] },
                        ticks: {
                            color: '#94a3b8',
                            font: { size: 11 },
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
});
</script>