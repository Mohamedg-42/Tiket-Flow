<?php
// ==============================================================================
// TABLEAU DE BORD ADMINISTRATEUR ENTERPRISE 100% DYNAMIQUE (admin/dashboard.php)
// Connecté en temps réel à la base MySQL : filtres dynamiques (périodes & événements),
// calculs d'évolutions réelles vs période précédente, sparklines et flux live.
// ==============================================================================

$admin_page_title = "Tableau de Bord - Administration Enterprise";
include 'header.php';

// 1. FILTRES DYNAMIQUES (PÉRIODE ET ÉVÉNEMENT SPÉCIFIQUE)
$period = $_GET['period'] ?? '30d';
$selected_event_id = isset($_GET['event_id']) && $_GET['event_id'] !== '' ? (int)$_GET['event_id'] : null;

// Définition des conditions SQL pour la période actuelle et la période précédente
switch ($period) {
    case '7d':
        $sql_period_cur_pay = "p.date_paiement >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $sql_period_prev_pay = "p.date_paiement BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $sql_period_cur_tkt = "t.date_achat >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $sql_period_prev_tkt = "t.date_achat BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $sql_period_cur_ord = "o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $sql_period_prev_ord = "o.created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $points_count = 7;
        $step_days = 1;
        $period_label = "7 derniers jours";
        break;

    case 'this_month':
        $sql_period_cur_pay = "MONTH(p.date_paiement) = MONTH(CURDATE()) AND YEAR(p.date_paiement) = YEAR(CURDATE())";
        $sql_period_prev_pay = "MONTH(p.date_paiement) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(p.date_paiement) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
        $sql_period_cur_tkt = "MONTH(t.date_achat) = MONTH(CURDATE()) AND YEAR(t.date_achat) = YEAR(CURDATE())";
        $sql_period_prev_tkt = "MONTH(t.date_achat) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(t.date_achat) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
        $sql_period_cur_ord = "MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())";
        $sql_period_prev_ord = "MONTH(o.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(o.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
        $points_count = 6;
        $step_days = 5;
        $period_label = "Ce mois-ci (" . date('F Y') . ")";
        break;

    case 'this_year':
        $sql_period_cur_pay = "YEAR(p.date_paiement) = YEAR(CURDATE())";
        $sql_period_prev_pay = "YEAR(p.date_paiement) = YEAR(CURDATE()) - 1";
        $sql_period_cur_tkt = "YEAR(t.date_achat) = YEAR(CURDATE())";
        $sql_period_prev_tkt = "YEAR(t.date_achat) = YEAR(CURDATE()) - 1";
        $sql_period_cur_ord = "YEAR(o.created_at) = YEAR(CURDATE())";
        $sql_period_prev_ord = "YEAR(o.created_at) = YEAR(CURDATE()) - 1";
        $points_count = 7;
        $step_days = 50;
        $period_label = "Cette année (" . date('Y') . ")";
        break;

    case 'all':
        $sql_period_cur_pay = "1=1";
        $sql_period_prev_pay = "1=0";
        $sql_period_cur_tkt = "1=1";
        $sql_period_prev_tkt = "1=0";
        $sql_period_cur_ord = "1=1";
        $sql_period_prev_ord = "1=0";
        $points_count = 7;
        $step_days = 10;
        $period_label = "Toutes les périodes";
        break;

    case '30d':
    default:
        $period = '30d';
        $sql_period_cur_pay = "p.date_paiement >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $sql_period_prev_pay = "p.date_paiement BETWEEN DATE_SUB(NOW(), INTERVAL 60 DAY) AND DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $sql_period_cur_tkt = "t.date_achat >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $sql_period_prev_tkt = "t.date_achat BETWEEN DATE_SUB(NOW(), INTERVAL 60 DAY) AND DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $sql_period_cur_ord = "o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $sql_period_prev_ord = "o.created_at BETWEEN DATE_SUB(NOW(), INTERVAL 60 DAY) AND DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $points_count = 7;
        $step_days = 5;
        $period_label = "30 derniers jours";
        break;
}

// Filtre conditionnel sur événement
$sql_ev_filter_tkt = $selected_event_id ? "AND t.event_id = " . $selected_event_id : "";
$sql_ev_filter_cap = $selected_event_id ? "WHERE tt.event_id = " . $selected_event_id : "";

// Récupération de la liste des événements pour le sélecteur dynamique
$all_events_list = $pdo->query("SELECT id, nom FROM events ORDER BY nom ASC")->fetchAll();

// Fonction dynamique de calcul de pourcentage d'évolution vs période précédente
function get_growth($current, $previous) {
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
// 2. CALCUL DYNAMIQUE DES KPIS (PÉRIODE ACTUELLE VS PÉRIODE PRÉCÉDENTE)
// ==============================================================================

// A. Chiffre d'Affaires Brut (période courante vs précédente)
if ($selected_event_id) {
    $stmt_ca_cur = $pdo->query("SELECT COALESCE(SUM(t.prix), 0) FROM tickets t WHERE t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt $sql_ev_filter_tkt");
    $total_revenue = (float)$stmt_ca_cur->fetchColumn();

    $stmt_ca_prev = $pdo->query("SELECT COALESCE(SUM(t.prix), 0) FROM tickets t WHERE t.statut IN ('vendu', 'utilise') AND $sql_period_prev_tkt $sql_ev_filter_tkt");
    $prev_revenue = (float)$stmt_ca_prev->fetchColumn();
} else {
    $stmt_ca_cur = $pdo->query("SELECT COALESCE(SUM(p.montant), 0) FROM payments p WHERE p.statut = 'paye' AND $sql_period_cur_pay");
    $total_revenue = (float)$stmt_ca_cur->fetchColumn();

    $stmt_ca_prev = $pdo->query("SELECT COALESCE(SUM(p.montant), 0) FROM payments p WHERE p.statut = 'paye' AND $sql_period_prev_pay");
    $prev_revenue = (float)$stmt_ca_prev->fetchColumn();
}
$ca_growth = get_growth($total_revenue, $prev_revenue);

// B. Commissions Plateforme (5% par défaut ou commission_rate de l'événement)
$stmt_comm_cur = $pdo->query("
    SELECT COALESCE(SUM(t.prix * (COALESCE(e.commission_rate, 5.0) / 100)), 0) 
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt $sql_ev_filter_tkt
");
$total_commissions = (float)$stmt_comm_cur->fetchColumn();

$stmt_comm_prev = $pdo->query("
    SELECT COALESCE(SUM(t.prix * (COALESCE(e.commission_rate, 5.0) / 100)), 0) 
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    WHERE t.statut IN ('vendu', 'utilise') AND $sql_period_prev_tkt $sql_ev_filter_tkt
");
$prev_commissions = (float)$stmt_comm_prev->fetchColumn();
$comm_growth = get_growth($total_commissions, $prev_commissions);

// C. Billets vendus et Capacité globale
$stmt_tkt_cur = $pdo->query("SELECT COUNT(*) FROM tickets t WHERE t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt $sql_ev_filter_tkt");
$total_tickets_sold = (int)$stmt_tkt_cur->fetchColumn();

$stmt_tkt_prev = $pdo->query("SELECT COUNT(*) FROM tickets t WHERE t.statut IN ('vendu', 'utilise') AND $sql_period_prev_tkt $sql_ev_filter_tkt");
$prev_tickets_sold = (int)$stmt_tkt_prev->fetchColumn();
$tkt_growth = get_growth($total_tickets_sold, $prev_tickets_sold);

$capacite_totale = (int)$pdo->query("SELECT COALESCE(SUM(tt.quantite), 0) FROM ticket_types tt $sql_ev_filter_cap")->fetchColumn();
$taux_occupation = ($capacite_totale > 0) ? min(100, round(($total_tickets_sold / $capacite_totale) * 100)) : 0;

// D. Commandes et Panier Moyen
if ($selected_event_id) {
    $stmt_ord_cur = $pdo->query("
        SELECT COUNT(DISTINCT o.id) as nb, COALESCE(SUM(t.prix), 0) as tot
        FROM orders o
        JOIN tickets t ON t.order_id = o.id
        WHERE o.statut = 'payee' AND $sql_period_cur_ord $sql_ev_filter_tkt
    ");
    $ord_res = $stmt_ord_cur->fetch();
    $total_orders = (int)$ord_res['nb'];
    $panier_moyen = ($total_orders > 0) ? round($ord_res['tot'] / $total_orders) : 0;
} else {
    $stmt_ord_cur = $pdo->query("SELECT COUNT(*) as nb, COALESCE(SUM(montant_total), 0) as tot FROM orders o WHERE o.statut = 'payee' AND $sql_period_cur_ord");
    $ord_res = $stmt_ord_cur->fetch();
    $total_orders = (int)$ord_res['nb'];
    $panier_moyen = ($total_orders > 0) ? round($ord_res['tot'] / $total_orders) : 0;
}

$stmt_ord_prev = $pdo->query("SELECT COUNT(*) FROM orders o WHERE o.statut = 'payee' AND $sql_period_prev_ord");
$prev_orders = (int)$stmt_ord_prev->fetchColumn();
$ord_growth = get_growth($total_orders, $prev_orders);

// E. Check-in et Participants scannés aux portes
$stmt_used_cur = $pdo->query("SELECT COUNT(*) FROM tickets t WHERE t.statut = 'utilise' AND $sql_period_cur_tkt $sql_ev_filter_tkt");
$total_tickets_used = (int)$stmt_used_cur->fetchColumn();

$stmt_used_prev = $pdo->query("SELECT COUNT(*) FROM tickets t WHERE t.statut = 'utilise' AND $sql_period_prev_tkt $sql_ev_filter_tkt");
$prev_tickets_used = (int)$stmt_used_prev->fetchColumn();
$used_growth = get_growth($total_tickets_used, $prev_tickets_used);
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

    if ($selected_event_id) {
        $stmt_pt = $pdo->prepare("
            SELECT COALESCE(SUM(t.prix), 0) as ca, COUNT(*) as nb
            FROM tickets t
            WHERE t.event_id = ? AND t.statut IN ('vendu', 'utilise') 
              AND DATE(t.date_achat) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ?
        ");
        $stmt_pt->execute([$selected_event_id, $d, max(1, $step_days - 1), $d]);
    } else {
        $stmt_pt = $pdo->prepare("
            SELECT COALESCE(SUM(p.montant), 0) as ca, COUNT(*) as nb
            FROM payments p
            WHERE p.statut = 'paye' 
              AND DATE(p.date_paiement) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ?
        ");
        $stmt_pt->execute([$d, max(1, $step_days - 1), $d]);
    }
    $row_pt = $stmt_pt->fetch();
    $chart_revenue_vals[] = (float)($row_pt['ca'] ?? 0);
    $chart_tickets_vals[] = (int)($row_pt['nb'] ?? 0);
}

// Sparklines dynamiques générées à partir des points réels
$sparkline_data = array_map(function($v) { return max(1, (int)$v); }, $chart_revenue_vals);

// ==============================================================================
// 4. RÉPARTITION DYNAMIQUE PAR PASSERELLE MOBILE MONEY
// ==============================================================================
$stmt_methods = $pdo->query("
    SELECT LOWER(p.methode) as methode, COALESCE(SUM(p.montant), 0) as total_methode, COUNT(*) as nb
    FROM payments p
    WHERE p.statut = 'paye' AND $sql_period_cur_pay
    GROUP BY LOWER(p.methode)
")->fetchAll(PDO::FETCH_ASSOC);

$methods_map = ['wave' => 0, 'orange_money' => 0, 'mtn_money' => 0, 'moov_money' => 0];
foreach ($stmt_methods as $m) {
    $k = $m['methode'];
    if (isset($methods_map[$k])) {
        $methods_map[$k] = (float)$m['total_methode'];
    }
}
$total_methods = array_sum($methods_map);

// ==============================================================================
// 5. STATUT DYNAMIQUE DES COMMANDES
// ==============================================================================
$stmt_orders_st = $pdo->query("
    SELECT o.statut, COUNT(*) as nb 
    FROM orders o 
    WHERE $sql_period_cur_ord
    GROUP BY o.statut
")->fetchAll(PDO::FETCH_KEY_PAIR);

$orders_payees   = (int)($stmt_orders_st['payee'] ?? 0);
$orders_attente  = (int)($stmt_orders_st['en_attente'] ?? 0);
$orders_annulees = (int)($stmt_orders_st['annulee'] ?? 0) + (int)($stmt_orders_st['echouee'] ?? 0);
$orders_all = $orders_payees + $orders_attente + $orders_annulees;

// ==============================================================================
// 6. TOP ÉVÉNEMENTS DYNAMIQUES
// ==============================================================================
$stmt_top = $pdo->query("
    SELECT e.id, e.nom, e.image, e.date_evenement, e.heure, e.categorie,
           u.nom as promoteur_nom,
           COUNT(t.id) as tickets_vendus,
           COALESCE(SUM(t.prix), 0) as ca_total,
           (SELECT COALESCE(SUM(tt.quantite), 0) FROM ticket_types tt WHERE tt.event_id = e.id) as capacite
    FROM events e
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN tickets t ON t.event_id = e.id AND t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt
    WHERE e.statut = 'actif'
    GROUP BY e.id
    ORDER BY ca_total DESC, tickets_vendus DESC
    LIMIT 6
");
$top_events = $stmt_top->fetchAll();

// ==============================================================================
// 7. VENTES DYNAMIQUES PAR TYPE DE BILLET
// ==============================================================================
$stmt_tt = $pdo->query("
    SELECT tt.nom, COALESCE(SUM(t.prix), 0) as ca_type, COUNT(t.id) as total_vendus
    FROM ticket_types tt
    LEFT JOIN tickets t ON t.ticket_type_id = tt.id AND t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt
    $sql_ev_filter_cap
    GROUP BY tt.nom
    ORDER BY ca_type DESC
    LIMIT 5
");
$tt_stats = $stmt_tt->fetchAll();
$tt_labels = [];
$tt_values = [];
foreach ($tt_stats as $tts) {
    $tt_labels[] = $tts['nom'];
    $tt_values[] = (float)$tts['ca_type'];
}

// ==============================================================================
// 7 bis. TYPES DE BILLETS VENDUS PAR ÉVÉNEMENT
// ==============================================================================
$stmt_tt_ev = $pdo->query("
    SELECT e.id as event_id, e.nom as event_nom,
           COALESCE(tt.nom, 'Non catégorisé') as type_nom,
           COUNT(t.id) as nb_vendus,
           COALESCE(SUM(t.prix), 0) as ca_type
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    LEFT JOIN ticket_types tt ON t.ticket_type_id = tt.id
    WHERE t.statut IN ('vendu', 'utilise') AND $sql_period_cur_tkt $sql_ev_filter_tkt
    GROUP BY e.id, tt.nom
    ORDER BY e.nom ASC, nb_vendus DESC
    LIMIT 60
");
$tt_by_event = $stmt_tt_ev->fetchAll();

// ==============================================================================
// 8. FLUX OPÉRATIONNEL 100% EN DIRECT (DERNIÈRES ACTIONS RÉELLES DE LA BD)
// ==============================================================================
$live_activities = [];
try {
    $stmt_live = $pdo->query("
        SELECT t.id, t.prix, t.statut, t.date_achat, t.client_nom,
               e.nom as event_nom, tt.nom as ticket_type
        FROM tickets t
        JOIN events e ON t.event_id = e.id
        LEFT JOIN ticket_types tt ON t.ticket_type_id = tt.id
        ORDER BY t.date_achat DESC
        LIMIT 6
    ");
    $live_activities = $stmt_live->fetchAll();
} catch (PDOException $e) {
    $live_activities = [];
}
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">
<!-- Inclusion Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="dash-container">
    <!-- ==============================================================================
         1. BARRE DE FILTRES DYNAMIQUES & CONTRÔLES
         ============================================================================== -->
    <!-- ==============================================================================
         1. BARRE DE FILTRES DYNAMIQUES & CONTRÔLES (TOUT EN HAUT)
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-chart-line" style="color: var(--dash-primary); font-size: 1.6rem;"></i>
                Tableau de Bord Exécutif
            </h1>
            <p>Données temps réel consolidées de la plateforme · Période : <strong><?php echo htmlspecialchars($period_label); ?></strong></p>
        </div>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" class="dash-btn-action" onclick="window.print()" title="Imprimer le rapport ou générer un PDF">
                <i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i>
                <span>Rapport PDF</span>
            </button>
            <a href="evenements.php" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Gérer Événements</span>
            </a>
        </div>
    </div>

    <!-- BARRE DE FILTRES AVANCÉS EN HAUT SUR UNE SEULE LIGNE -->
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--dash-border); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : PILULES DE PÉRIODES TEMPORELLES -->
        <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
            <a href="?period=7d<?php echo $selected_event_id ? '&event_id=' . $selected_event_id : ''; ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.9rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $period === '7d' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-regular fa-clock" style="<?php echo $period === '7d' ? 'color: #2dd4bf;' : ''; ?>"></i> 7 jours
            </a>

            <a href="?period=30d<?php echo $selected_event_id ? '&event_id=' . $selected_event_id : ''; ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.9rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $period === '30d' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-calendar-days" style="<?php echo $period === '30d' ? 'color: #2dd4bf;' : ''; ?>"></i> 30 jours
            </a>

            <a href="?period=this_month<?php echo $selected_event_id ? '&event_id=' . $selected_event_id : ''; ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.9rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $period === 'this_month' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-regular fa-calendar" style="<?php echo $period === 'this_month' ? 'color: #2dd4bf;' : ''; ?>"></i> Ce mois
            </a>

            <a href="?period=this_year<?php echo $selected_event_id ? '&event_id=' . $selected_event_id : ''; ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.9rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $period === 'this_year' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-chart-pie" style="<?php echo $period === 'this_year' ? 'color: #2dd4bf;' : ''; ?>"></i> Cette année
            </a>

            <a href="?period=all<?php echo $selected_event_id ? '&event_id=' . $selected_event_id : ''; ?>" style="text-decoration: none; border-radius: 9px; padding: 0.45rem 0.9rem; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; <?php echo $period === 'all' ? 'background: #0f172a; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
                <i class="fa-solid fa-infinity" style="<?php echo $period === 'all' ? 'color: #2dd4bf;' : ''; ?>"></i> Tout
            </a>
        </div>

        <!-- À DROITE : SÉLECTEUR ÉVÉNEMENT & INDICATEUR DE ZONE -->
        <form method="GET" action="dashboard.php" id="filterForm" style="display: inline-flex; gap: 8px; align-items: center; margin: 0;">
            <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">

            <div style="display: flex; align-items: center; gap: 6px; padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); background: #f8fafc;">
                <i class="fa-solid fa-filter" style="color: var(--dash-primary); font-size: 0.8rem;"></i>
                <select name="event_id" onchange="document.getElementById('filterForm').submit();" style="border: none; background: transparent; font-weight: 700; color: var(--dash-text); outline: none; cursor: pointer; max-width: 220px; font-size: 0.82rem;">
                    <option value="">Tous les événements de la plateforme</option>
                    <?php foreach ($all_events_list as $ev_item): ?>
                        <option value="<?php echo $ev_item['id']; ?>" <?php echo $selected_event_id === (int)$ev_item['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(mb_strimwidth($ev_item['nom'], 0, 26, '...')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($selected_event_id): ?>
                <a href="?period=<?php echo $period; ?>" style="color: #ef4444; font-size: 0.78rem; text-decoration: underline;">Tous les événements</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         2. RACCOURCIS D'ACTIONS RAPIDES
         ============================================================================== -->
    <div class="dash-quick-shortcuts">
        <a href="demandes.php" class="dash-shortcut-card">
            <div class="dash-shortcut-icon" style="background: #fff7ed; color: #f97316;">
                <i class="fa-solid fa-inbox"></i>
            </div>
            <div class="dash-shortcut-text">
                <strong>Demandes en attente</strong>
                <small><?php echo (int)$badge_demandes_all; ?> à examiner</small>
            </div>
        </a>

        <a href="retraits.php" class="dash-shortcut-card">
            <div class="dash-shortcut-icon" style="background: #ecfdf5; color: #10b981;">
                <i class="fa-solid fa-money-bill-transfer"></i>
            </div>
            <div class="dash-shortcut-text">
                <strong>Demandes de Retraits</strong>
                <small><?php echo (int)$badge_withdrawals; ?> en attente</small>
            </div>
        </a>

        <a href="commandes.php" class="dash-shortcut-card">
            <div class="dash-shortcut-icon" style="background: #f0f9ff; color: #0ea5e9;">
                <i class="fa-solid fa-cart-shopping"></i>
            </div>
            <div class="dash-shortcut-text">
                <strong>Commandes Période</strong>
                <small><?php echo number_format($total_orders, 0, ',', ' '); ?> commandes payées</small>
            </div>
        </a>

        <a href="verification.php" class="dash-shortcut-card">
            <div class="dash-shortcut-icon" style="background: #f5f3ff; color: #5b50e6;">
                <i class="fa-solid fa-qrcode"></i>
            </div>
            <div class="dash-shortcut-text">
                <strong>Scanner & Contrôle</strong>
                <small>Validation aux accès</small>
            </div>
        </a>
    </div>

    <!-- ==============================================================================
         3. BANDEAU DE 6 KPIS FINANCIERS & OPÉRATIONNELS AVEC ÉVOLUTIONS DYNAMIQUES
         ============================================================================== -->
    <div class="dash-kpi-grid-6">
        <!-- 1. Chiffre d'Affaires Brut -->
        <a href="paiements.php" class="dash-kpi-card" title="Voir tous les paiements et transactions">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap purple">
                    <i class="fa-solid fa-coins"></i>
                </div>
                <span class="dash-kpi-badge-pill <?php echo $ca_growth['class']; ?>">
                    <i class="fa-solid fa-arrow-trend-<?php echo $ca_growth['dir'] === 'down' ? 'down' : 'up'; ?>"></i> <?php echo $ca_growth['text']; ?>
                </span>
            </div>
            <div class="dash-kpi-title">Chiffre d'Affaires Brut</div>
            <div class="dash-kpi-amount"><?php echo number_format($total_revenue, 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub">Total encaissé sur la période <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="kpiSpark1"></canvas>
            </div>
        </a>

        <!-- 2. Commissions plateforme -->
        <a href="retraits.php" class="dash-kpi-card" title="Gérer les retraits et commissions">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap green">
                    <i class="fa-solid fa-sack-dollar"></i>
                </div>
                <span class="dash-kpi-badge-pill <?php echo $comm_growth['class']; ?>">
                    <i class="fa-solid fa-arrow-trend-<?php echo $comm_growth['dir'] === 'down' ? 'down' : 'up'; ?>"></i> <?php echo $comm_growth['text']; ?>
                </span>
            </div>
            <div class="dash-kpi-title">Commissions Encaissées</div>
            <div class="dash-kpi-amount" style="color: #10b981;"><?php echo number_format($total_commissions, 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub">Revenu net plateforme (5%) <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="kpiSpark2"></canvas>
            </div>
        </a>

        <!-- 3. Billets vendus -->
        <a href="tickets.php" class="dash-kpi-card" title="Gérer les types de billets et tarifs">
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
                <canvas id="kpiSpark3"></canvas>
            </div>
        </a>

        <!-- 4. Panier Moyen -->
        <a href="commandes.php" class="dash-kpi-card" title="Voir toutes les commandes clients">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap amber">
                    <i class="fa-solid fa-basket-shopping"></i>
                </div>
                <span class="dash-kpi-badge-pill <?php echo $ord_growth['class']; ?>">
                    <i class="fa-solid fa-arrow-trend-<?php echo $ord_growth['dir'] === 'down' ? 'down' : 'up'; ?>"></i> <?php echo $ord_growth['text']; ?>
                </span>
            </div>
            <div class="dash-kpi-title">Panier Moyen</div>
            <div class="dash-kpi-amount"><?php echo number_format($panier_moyen, 0, ',', ' '); ?> <small style="font-size: 0.72rem; font-weight: 700;">F</small></div>
            <div class="dash-kpi-sub"><?php echo number_format($total_orders, 0, ',', ' '); ?> commandes payées <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="kpiSpark4"></canvas>
            </div>
        </a>

        <!-- 5. Check-in & Entrées -->
        <a href="verification.php" class="dash-kpi-card" title="Accéder au scanner et contrôle des accès">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap orange">
                    <i class="fa-solid fa-user-check"></i>
                </div>
                <span class="dash-kpi-badge-pill <?php echo $used_growth['class']; ?>">
                    <i class="fa-solid fa-arrow-trend-<?php echo $used_growth['dir'] === 'down' ? 'down' : 'up'; ?>"></i> <?php echo $used_growth['text']; ?>
                </span>
            </div>
            <div class="dash-kpi-title">Check-in (Entrées)</div>
            <div class="dash-kpi-amount"><?php echo number_format($total_tickets_used, 0, ',', ' '); ?></div>
            <div class="dash-kpi-sub"><?php echo $taux_presence; ?>% de présence réelle <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="kpiSpark5"></canvas>
            </div>
        </a>

        <!-- 6. Taux de Remplissage -->
        <a href="evenements.php" class="dash-kpi-card" title="Gérer tous les événements de la plateforme">
            <div class="dash-kpi-top">
                <div class="dash-kpi-icon-wrap pink">
                    <i class="fa-solid fa-percent"></i>
                </div>
                <span class="dash-kpi-badge-pill up">
                    <i class="fa-solid fa-chart-pie"></i> Actif
                </span>
            </div>
            <div class="dash-kpi-title">Taux de Remplissage</div>
            <div class="dash-kpi-amount" style="color: #ec4899;"><?php echo $taux_occupation; ?>%</div>
            <div class="dash-kpi-sub">Capacité globale utilisée <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem; margin-left: 2px;"></i></div>
            <div class="dash-sparkline-box">
                <canvas id="kpiSpark6"></canvas>
            </div>
        </a>
    </div>

    <!-- ==============================================================================
         4. LIGNE ANALYTIQUE EN 3 COLONNES ÉQUILIBRÉES : COURBE + MOYENS DE PAIEMENT + STATUT
         ============================================================================== -->
    <div class="dash-row-3cols">
        <!-- 1. Courbe d'évolution du Chiffre d'Affaires -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-chart-area" style="color: var(--dash-primary);"></i>
                        Évolution des Recettes
                    </h3>
                    <div class="dash-card-subtitle">
                        7 repères clés de la période sélectionnée
                    </div>
                </div>
                <div class="dash-chart-tabs">
                    <button type="button" class="dash-chart-tab active" onclick="switchChartMode('revenue', this)">Recettes (F)</button>
                    <button type="button" class="dash-chart-tab" onclick="switchChartMode('tickets', this)">Billets</button>
                </div>
            </div>
            <div style="height: 225px; max-height: 225px; position: relative; width: 100%; overflow: hidden;">
                <canvas id="mainEvolutionChart"></canvas>
            </div>
        </div>

        <!-- 2. Donut Passerelles Mobile Money -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-mobile-screen-button" style="color: var(--dash-secondary);"></i>
                        Passerelles Mobile Money
                    </h3>
                    <div class="dash-card-subtitle">Répartition réelle sur la période</div>
                </div>
            </div>
            <div class="dash-donut-box" style="height: 180px;">
                <canvas id="paymentMethodsChart"></canvas>
                <div class="dash-donut-info-center">
                    <strong style="font-size: 1.15rem;"><?php echo number_format($total_methods, 0, ',', ' '); ?> F</strong>
                    <span style="font-size: 0.7rem;">Total Mobile</span>
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
                    <a href="paiements.php" class="dash-legend-entry" title="Voir paiements <?php echo htmlspecialchars($cfg['label']); ?>">
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

        <!-- 3. Donut Statut des tickets -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-pie-chart" style="color: #10b981;"></i>
                        Statut des tickets
                    </h3>
                    <div class="dash-card-subtitle">Volume réel sur la période</div>
                </div>
            </div>
            <div class="dash-donut-box" style="height: 180px;">
                <canvas id="ordersStatusChart"></canvas>
                <div class="dash-donut-info-center">
                    <strong style="font-size: 1.3rem;"><?php echo number_format($orders_all, 0, ',', ' '); ?></strong>
                    <span style="font-size: 0.7rem;">Total</span>
                </div>
            </div>
            <div class="dash-donut-legend-list" style="margin-top: 0.75rem; gap: 0.45rem;">
                <a href="commandes.php" class="dash-legend-entry" title="Voir les commandes payées">
                    <div class="dash-legend-name">
                        <span class="dash-legend-bullet" style="background: #10b981;"></span>
                        <span>Payées</span>
                    </div>
                    <div class="dash-legend-details">
                        <span class="dash-legend-num"><?php echo number_format($orders_payees, 0, ',', ' '); ?></span>
                        <span class="dash-legend-percent">(<?php echo $orders_all > 0 ? round(($orders_payees / $orders_all) * 100) : 0; ?>%)</span>
                    </div>
                </a>
                <a href="commandes.php" class="dash-legend-entry" title="Voir les commandes en attente">
                    <div class="dash-legend-name">
                        <span class="dash-legend-bullet" style="background: #f59e0b;"></span>
                        <span>En attente</span>
                    </div>
                    <div class="dash-legend-details">
                        <span class="dash-legend-num"><?php echo number_format($orders_attente, 0, ',', ' '); ?></span>
                        <span class="dash-legend-percent">(<?php echo $orders_all > 0 ? round(($orders_attente / $orders_all) * 100) : 0; ?>%)</span>
                    </div>
                </a>
                <a href="commandes.php" class="dash-legend-entry" title="Voir les commandes annulées">
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
         5. LIGNE 3 : TOP ÉVÉNEMENTS + TYPES DE TICKETS + FLUX TEMPS RÉEL
         ============================================================================== -->
    <div class="dash-grid-3cols">
        <!-- 1. Top Événements avec Barres de Remplissage -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i>
                        Top Événements les Plus Rentables
                    </h3>
                    <div class="dash-card-subtitle">Recettes générées sur la période</div>
                </div>
            </div>
            <div class="dash-table-wrapper">
                <table class="dash-pro-table mv-stack">
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
                        <?php if (!empty($top_events)): ?>
                            <?php foreach ($top_events as $ev): ?>
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
                                <tr class="dash-clickable-row" onclick="window.location='modifier-evenement.php?id=<?php echo $ev['id']; ?>'" title="Cliquer pour gérer cet événement">
                                    <td data-label="Événement">
                                        <div class="dash-event-cell">
                                            <img src="<?php echo $ev_img; ?>" alt="" class="dash-event-poster">
                                            <div class="dash-event-meta">
                                                <strong><?php echo htmlspecialchars($ev['nom']); ?></strong>
                                                <small><i class="fa-solid fa-user-tie"></i> <?php echo htmlspecialchars($ev['promoteur_nom'] ?? 'Organisateur'); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Date" style="white-space: nowrap; font-size: 0.8rem;"><?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?></td>
                                    <td data-label="Vendus"><strong><?php echo number_format($vds, 0, ',', ' '); ?></strong></td>
                                    <td data-label="Recette" style="font-weight: 700; color: var(--dash-primary);"><?php echo number_format((float)$ev['ca_total'], 0, ',', ' '); ?> F</td>
                                    <td data-label="Occupation">
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
                                    Aucun événement n'a encore enregistré de ventes sur cette période.
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
                <canvas id="ticketTypeBarChart"></canvas>
            </div>
        </div>

        <!-- 3. Flux Opérationnel 100% en Direct (Live Feed) -->
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h3 class="dash-card-title">
                        <i class="fa-solid fa-bolt" style="color: #10b981;"></i>
                        Opérations & Activités Live
                    </h3>
                    <div class="dash-card-subtitle">Dernières transactions réelles enregistrées</div>
                </div>
                <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.75rem; font-weight: 700; color: #10b981; background: #ecfdf5; padding: 3px 8px; border-radius: 6px;">
                    <span style="width: 7px; height: 7px; border-radius: 50%; background: #10b981;"></span> Live
                </span>
            </div>
            <div class="dash-live-stream">
                <?php if (!empty($live_activities)): ?>
                    <?php foreach ($live_activities as $act): ?>
                        <a href="commandes.php" class="dash-stream-card" title="Voir les commandes correspondantes">
                            <div class="dash-stream-left">
                                <div class="dash-stream-icon" style="background: #eeedfd; color: var(--dash-primary);">
                                    <i class="fa-solid fa-ticket"></i>
                                </div>
                                <div class="dash-stream-texts">
                                    <span class="dash-stream-title"><?php echo htmlspecialchars($act['ticket_type'] ?: 'Billet'); ?> - <?php echo htmlspecialchars(mb_strimwidth($act['event_nom'], 0, 18, '...')); ?></span>
                                    <span class="dash-stream-desc">Acheteur : <?php echo htmlspecialchars($act['client_nom'] ?: 'Client Web'); ?></span>
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
         5 bis. TYPES DE BILLETS VENDUS PAR ÉVÉNEMENT
         ============================================================================== -->
    <div class="dash-card" style="margin-bottom: 1.75rem;">
        <div class="dash-card-head">
            <div>
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-layer-group" style="color: #0ea5e9;"></i>
                    Types de Billets Vendus par Événement
                </h3>
                <div class="dash-card-subtitle">Répartition détaillée des ventes par formule et par événement</div>
            </div>
            <span style="background: #f0f9ff; color: #0ea5e9; padding: 4px 10px; border-radius: 8px; font-size: 0.78rem; font-weight: 800;">
                <?php echo count($tt_by_event); ?> ligne(s)
            </span>
        </div>
        <div class="dash-table-wrapper">
            <table class="dash-pro-table mv-stack">
                <thead>
                    <tr>
                        <th>Événement</th>
                        <th>Type de Billet</th>
                        <th>Billets Vendus</th>
                        <th>Recette</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($tt_by_event)): ?>
                        <?php foreach ($tt_by_event as $tbe): ?>
                            <tr>
                                <td data-label="Événement">
                                    <a href="dashboard.php?period=<?php echo urlencode($period); ?>&event_id=<?php echo $tbe['event_id']; ?>" style="color: var(--dash-text); text-decoration: none; font-weight: 700;" title="Filtrer le dashboard sur cet événement">
                                        <?php echo htmlspecialchars($tbe['event_nom']); ?>
                                    </a>
                                </td>
                                <td data-label="Type de Billet">
                                    <span style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 5px; font-weight: 700; font-size: 0.78rem; display: inline-block;">
                                        <?php echo htmlspecialchars($tbe['type_nom']); ?>
                                    </span>
                                </td>
                                <td data-label="Billets Vendus">
                                    <strong style="font-size: 0.92rem;"><?php echo number_format($tbe['nb_vendus'], 0, ',', ' '); ?></strong>
                                </td>
                                <td data-label="Recette">
                                    <strong style="color: var(--dash-primary); font-size: 0.92rem;"><?php echo number_format((float)$tbe['ca_type'], 0, ',', ' '); ?> F</strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--dash-muted); padding: 2.5rem 1rem;">
                                <i class="fa-solid fa-inbox" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                                Aucune vente de billet enregistrée pour cette période.
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
            <span><i class="fa-solid fa-shield-check" style="color: #10b981;"></i> Données synchronisées avec la base MySQL</span>
            <span style="margin: 0 8px;">•</span>
            <span>Dernière mise à jour : <strong><?php echo date('d/m/Y à H:i:s'); ?></strong></span>
        </div>
        <div>
            <a href="dashboard.php?period=<?php echo urlencode($period); ?><?php echo $selected_event_id ? '&event_id=' . $selected_event_id : ''; ?>" style="color: var(--dash-primary); font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                <i class="fa-solid fa-rotate"></i> Actualiser instantanément
            </a>
        </div>
    </div>
</div>

<!-- ==============================================================================
     SCRIPTS CHART.JS HAUTE PRÉCISION
     ============================================================================== -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Sparklines pour les 6 cartes KPI
    function drawSparkline(canvasId, lineColor, fillColor, dataPoints) {
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

    const dynSpark = <?php echo json_encode(!empty($sparkline_data) ? $sparkline_data : [1, 2, 1, 3, 2, 4, 3]); ?>;
    drawSparkline('kpiSpark1', '#5b50e6', 'rgba(91, 80, 230, 0.28)', dynSpark);
    drawSparkline('kpiSpark2', '#10b981', 'rgba(16, 185, 129, 0.28)', dynSpark);
    drawSparkline('kpiSpark3', '#0ea5e9', 'rgba(14, 165, 233, 0.28)', dynSpark);
    drawSparkline('kpiSpark4', '#eab308', 'rgba(234, 179, 8, 0.28)',  dynSpark);
    drawSparkline('kpiSpark5', '#f97316', 'rgba(249, 115, 22, 0.28)', dynSpark);
    drawSparkline('kpiSpark6', '#ec4899', 'rgba(236, 72, 153, 0.28)', dynSpark);

    // 2. Courbe d'Évolution avec support double mode (Recettes / Billets)
    const ctxMain = document.getElementById('mainEvolutionChart');
    let mainChartInstance = null;

    if (ctxMain) {
        const mCtx = ctxMain.getContext('2d');
        const revGrad = mCtx.createLinearGradient(0, 0, 0, 225);
        revGrad.addColorStop(0, 'rgba(91, 80, 230, 0.32)');
        revGrad.addColorStop(1, 'rgba(91, 80, 230, 0.01)');

        mainChartInstance = new Chart(ctxMain, {
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
    window.switchChartMode = function(mode, btn) {
        document.querySelectorAll('.dash-chart-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        if (!mainChartInstance) return;

        if (mode === 'tickets') {
            mainChartInstance.data.datasets[0].label = "Billets vendus";
            mainChartInstance.data.datasets[0].data = <?php echo json_encode($chart_tickets_vals); ?>;
            mainChartInstance.data.datasets[0].borderColor = '#0ea5e9';
            mainChartInstance.options.plugins.tooltip.callbacks.label = function(ctx) {
                return ctx.parsed.y + ' billets vendus';
            };
            mainChartInstance.options.scales.y.ticks.callback = function(v) { return v; };
        } else {
            mainChartInstance.data.datasets[0].label = "Recettes (FCFA)";
            mainChartInstance.data.datasets[0].data = <?php echo json_encode($chart_revenue_vals); ?>;
            mainChartInstance.data.datasets[0].borderColor = '#5b50e6';
            mainChartInstance.options.plugins.tooltip.callbacks.label = function(ctx) {
                return ctx.parsed.y.toLocaleString('fr-FR') + ' FCFA';
            };
            mainChartInstance.options.scales.y.ticks.callback = function(v) {
                if (v >= 1000000) return (v / 1000000).toFixed(0) + 'M';
                if (v >= 1000) return (v / 1000).toFixed(0) + 'k';
                return v;
            };
        }
        mainChartInstance.update();
    };

    // 3. Donut Passerelles Mobile Money
    const ctxPayment = document.getElementById('paymentMethodsChart');
    if (ctxPayment) {
        new Chart(ctxPayment, {
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

    // 4. Donut Statut des tickets
    const ctxOrdersSt = document.getElementById('ordersStatusChart');
    if (ctxOrdersSt) {
        new Chart(ctxOrdersSt, {
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
    const ctxBar = document.getElementById('ticketTypeBarChart');
    if (ctxBar) {
        new Chart(ctxBar, {
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