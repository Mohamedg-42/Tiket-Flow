<?php
// ==============================================================================
// GESTION DES COMMANDES (admin/commandes.php)
// Design Dashboard Pro - Traçabilité des paniers, statuts et réservations
// ==============================================================================

$admin_page_title = "Gestion des Commandes - Administration";
include 'header.php';

// Filtres
$statut_f = $_GET['statut'] ?? 'tous';
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT o.*, u.nom AS client_nom, u.email AS client_email, u.telephone as client_tel,
           (SELECT COUNT(*) FROM tickets t WHERE t.order_id = o.id) as nb_billets
    FROM orders o 
    LEFT JOIN users u ON u.id = o.user_id 
    WHERE 1=1
";
$params = [];

if ($statut_f === 'payee') {
    $sql .= " AND o.statut = 'payee'";
} elseif ($statut_f === 'en_attente') {
    $sql .= " AND o.statut = 'en_attente'";
} elseif ($statut_f === 'annulee') {
    $sql .= " AND o.statut = 'annulee'";
}

if (!empty($search)) {
    $sql .= " AND (o.numero_commande LIKE ? OR u.nom LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY o.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// KPIs globaux
$tot_orders = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$tot_payees = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE statut = 'payee'")->fetchColumn();
$tot_ca = (float) $pdo->query("SELECT COALESCE(SUM(montant_total), 0) FROM orders WHERE statut = 'payee'")->fetchColumn();
$panier_moyen = ($tot_payees > 0) ? round($tot_ca / $tot_payees) : 0;

// Action : Renvoyer l'e-mail officiel avec les billets & QR Codes
$resend_msg = '';
$resend_type = '';
if (isset($_GET['resend_tickets'])) {
    $resend_order_id = (int) $_GET['resend_tickets'];

    $st_ord = $pdo->prepare("
        SELECT o.*, u.nom as user_nom, u.email as user_email
        FROM orders o 
        LEFT JOIN users u ON u.id = o.user_id 
        WHERE o.id = ? AND o.statut = 'payee'
    ");
    $st_ord->execute([$resend_order_id]);
    $o_data = $st_ord->fetch();

    if ($o_data) {
        $c_email = $o_data['client_email'] ?: $o_data['user_email'];
        $c_nom = $o_data['client_nom'] ?: $o_data['user_nom'] ?: 'Client';

        if (!empty($c_email) && filter_var($c_email, FILTER_VALIDATE_EMAIL)) {
            $st_tk = $pdo->prepare("
                SELECT t.*, e.nom as event_name, e.date_evenement as date_ev, e.heure, e.lieu, tt.nom as type_ticket
                FROM tickets t
                JOIN events e ON e.id = t.event_id
                JOIN ticket_types tt ON tt.id = t.ticket_type_id
                WHERE t.order_id = ?
            ");
            $st_tk->execute([$resend_order_id]);
            $raw_tickets = $st_tk->fetchAll();

            $tickets_for_mail = [];
            foreach ($raw_tickets as $rt) {
                $tickets_for_mail[] = [
                    'code_unique' => $rt['code_unique'],
                    'qr_code' => $rt['qr_code'],
                    'event_name' => $rt['event_name'],
                    'type_ticket' => $rt['type_ticket'],
                    'place' => $rt['place_numero'] ?? '',
                    'prix' => $rt['prix'],
                    'date_ev' => $rt['date_ev'],
                    'heure' => $rt['heure'],
                    'lieu' => $rt['lieu']
                ];
            }

            require_once __DIR__ . '/../includes/mailer.php';
            $sent = sendTicketEmail($c_email, $c_nom, $o_data['numero_commande'], $tickets_for_mail, $resend_order_id);
            if ($sent) {
                $resend_msg = "Les billets de la commande #" . htmlspecialchars($o_data['numero_commande']) . " ont été renvoyés avec succès à " . htmlspecialchars($c_email) . " !";
                $resend_type = "success";
            } else {
                $resend_msg = "Échec de l'envoi de l'e-mail vers " . htmlspecialchars($c_email) . ". Consultez logs/mail.log.";
                $resend_type = "error";
            }
        } else {
            $resend_msg = "Cette commande n'a pas d'adresse email valide associée.";
            $resend_type = "error";
        }
    } else {
        $resend_msg = "Commande introuvable ou non payée.";
        $resend_type = "error";
    }
}
?>

<style>
/* ==============================================================================
   RESPONSIVE DESIGN & CADRAGE SUISSE : GESTION DES COMMANDES (admin/commandes.php)
   ============================================================================== */
.dash-container {
    padding: clamp(0.85rem, 2.5vw, 1.75rem);
    max-width: 100%;
    box-sizing: border-box;
}

.commandes-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.commandes-header .dash-title-box h1 {
    font-size: clamp(1.25rem, 3.2vw, 1.75rem);
    font-weight: 800;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0 0 0.35rem 0;
}
.commandes-header .dash-title-box p {
    color: var(--dash-muted, #737373);
    font-size: 0.88rem;
    margin: 0;
}
.commandes-header-actions {
    display: flex;
    gap: 0.65rem;
    flex-wrap: wrap;
    align-items: center;
}

/* 2. Barre de filtres avec onglets défilables */
.commandes-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    background: #ffffff;
    padding: 0.65rem 0.85rem;
    border-radius: 12px;
    border: 1px solid var(--dash-border, #E5E5E5);
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    flex-wrap: wrap;
}
.commandes-pills-nav {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 2px;
}
.commandes-pills-nav::-webkit-scrollbar {
    display: none;
}
.commandes-pill {
    text-decoration: none;
    border-radius: 9px;
    padding: 0.45rem 0.95rem;
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.commandes-search-form {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    margin: 0;
    flex-wrap: wrap;
}

/* 3. KPIs de supervision */
.commandes-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: clamp(0.6rem, 1.8vw, 1rem);
    margin-bottom: 1.5rem;
}
.commandes-kpis .dash-kpi-card {
    padding: 1.15rem;
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid var(--dash-border, #E5E5E5);
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

/* 4. Tableau & Protections desktop */
.commandes-table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box;
}
.dash-table.commandes-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.85rem;
}
.dash-table.commandes-table th {
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    color: #737373;
    letter-spacing: 0.05em;
    padding: 0.75rem 0.85rem;
    border-bottom: 1px solid var(--dash-border, #E5E5E5);
    text-align: left;
    background: #ffffff;
    white-space: nowrap !important;
}
.dash-table.commandes-table td {
    padding: 0.85rem 0.85rem;
    border-bottom: 1px solid #F5F5F5;
    vertical-align: middle;
}

.cell-amount {
    color: #FF4A0D;
    font-size: 0.95rem;
    font-weight: 800;
    white-space: nowrap !important;
    word-break: keep-all !important;
    font-variant-numeric: tabular-nums !important;
}

.desktop-badge {
    display: inline-flex;
}
.mobile-badge {
    display: none;
}

@media (min-width: 861px) {
    .dash-table.commandes-table {
        min-width: 900px !important;
    }
}

@media (max-width: 1150px) {
    .commandes-kpis {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}

/* ==============================================================================
   5. TRANSFORMATION EN CARTES MOBILES (≤ 860px)
   ============================================================================== */
@media (max-width: 860px) {
    .dash-container {
        padding: 0.75rem 0.5rem !important;
    }
    .commandes-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
    }
    .commandes-header-actions {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 0.45rem !important;
    }
    .commandes-header-actions a {
        width: 100% !important;
        justify-content: center !important;
        text-align: center !important;
        box-sizing: border-box !important;
    }
    .commandes-filter-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
        padding: 0.65rem !important;
    }
    .commandes-pills-nav {
        width: 100% !important;
    }
    .commandes-search-form {
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.45rem !important;
    }
    .commandes-search-form input[type="text"],
    .commandes-search-form button,
    .commandes-search-form a {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        justify-content: center !important;
        text-align: center !important;
    }
    .commandes-kpis {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.50rem !important;
        margin-bottom: 1rem !important;
    }
    .commandes-kpis .dash-kpi-card {
        padding: 0.75rem !important;
    }

    .dash-card {
        padding: 0.65rem 0.5rem !important;
        border-radius: 12px !important;
        overflow: visible !important;
        border: 1px solid var(--dash-border, #E5E5E5) !important;
    }
    .dash-card-head {
        margin-bottom: 0.75rem !important;
        padding: 0 0.25rem !important;
    }
    .dash-card-title {
        font-size: 0.92rem !important;
    }

    .commandes-table-wrapper {
        overflow: visible !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }
    .dash-table.commandes-table {
        display: block !important;
        min-width: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        border: none !important;
        box-sizing: border-box !important;
    }
    .dash-table.commandes-table thead {
        display: none !important;
    }
    .dash-table.commandes-table tbody {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.85rem !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    .dash-table.commandes-table tr {
        display: block !important;
        background: #ffffff !important;
        border: 1px solid var(--dash-border, #E5E5E5) !important;
        border-radius: 12px !important;
        padding: 0.85rem !important;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04) !important;
        box-sizing: border-box !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .dash-table.commandes-table tr:hover td {
        background: transparent !important;
    }
    .dash-table.commandes-table td {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        gap: 0.5rem !important;
        padding: 0.48rem 0 !important;
        border-bottom: 1px dashed #E5E5E5 !important;
        width: 100% !important;
        max-width: 100% !important;
        text-align: right !important;
        font-size: 0.82rem !important;
        box-sizing: border-box !important;
    }
    .dash-table.commandes-table td::before {
        content: attr(data-label);
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        color: #737373;
        letter-spacing: 0.04em;
        text-align: left;
        flex-shrink: 0;
    }

    .dash-table.commandes-table td.card-top {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding-top: 0 !important;
        padding-bottom: 0.65rem !important;
        border-bottom: 1px solid #E5E5E5 !important;
    }
    .dash-table.commandes-table td.card-top::before {
        display: none !important;
    }

    .desktop-badge {
        display: none !important;
    }
    .mobile-badge {
        display: inline-flex !important;
    }
    .hide-on-mobile-card {
        display: none !important;
    }

    .dash-table.commandes-table td.card-actions {
        border-bottom: none !important;
        padding-bottom: 0 !important;
        padding-top: 0.65rem !important;
        display: block !important;
    }
    .dash-table.commandes-table td.card-actions::before {
        display: none !important;
    }
    .dash-table.commandes-table td.card-actions .cell-actions-group {
        display: flex !important;
        width: 100% !important;
        gap: 0.45rem !important;
        align-items: center !important;
    }
    .dash-table.commandes-table td.card-actions .cell-actions-group a {
        flex: 1 1 0 !important;
        text-align: center !important;
        justify-content: center !important;
        padding: 0.55rem 0.4rem !important;
        font-size: 0.78rem !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 5px !important;
        border-radius: 8px !important;
        text-decoration: none !important;
        box-sizing: border-box !important;
        border: 1px solid var(--dash-border, #E5E5E5) !important;
        background: #F5F5F5 !important;
    }
}

@media (max-width: 420px) {
    .commandes-kpis {
        grid-template-columns: 1fr !important;
    }
}
</style>

<div class="dash-container">
    <?php if ($resend_msg): ?>
        <div
            style="padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; font-weight: 600; font-size: 0.9rem; <?php echo $resend_type === 'success' ? 'background: #FFF2ED; color: #000000; border: 1px solid #FFF2ED;' : 'background: #F5F5F5; color: #000000; border: 1px solid #E5E5E5;'; ?>">
            <i
                class="fa-solid <?php echo $resend_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <?php echo $resend_msg; ?>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="commandes-header">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-cart-shopping" style="color: var(--dash-primary);"></i>
                Gestion des Commandes Clients
            </h1>
            <p>Supervisez les transactions d'achat de billets et l'état des paniers clients.</p>
        </div>

        <div class="commandes-header-actions">
            <a href="export.php?type=commandes&statut=<?php echo urlencode($statut_f); ?>&q=<?php echo urlencode($search); ?>" class="dash-btn-action"
                style="padding: 0.6rem 1.15rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;" title="Exporter les commandes sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <a href="paiements.php" class="dash-btn-action btn-primary"
                style="padding: 0.6rem 1.2rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-credit-card"></i> Voir les Flux Mobile Money
            </a>
        </div>
    </div>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES ACTIVES)
         ============================================================================== -->
    <div class="commandes-filter-bar">
        <!-- À GAUCHE : PILULES STATUT -->
        <div class="commandes-pills-nav">
            <a class="commandes-pill" href="?statut=tous&q=<?php echo urlencode($search); ?>"
                style="<?php echo $statut_f === 'tous' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $statut_f === 'tous' ? 'color: #FF4A0D;' : ''; ?>"></i>
                Toutes (<?php echo $tot_orders; ?>)
            </a>

            <a class="commandes-pill" href="?statut=payee&q=<?php echo urlencode($search); ?>"
                style="<?php echo $statut_f === 'payee' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-circle-check" style="color: #FF4A0D;"></i> Payées (<?php echo $tot_payees; ?>)
            </a>

            <a class="commandes-pill" href="?statut=en_attente&q=<?php echo urlencode($search); ?>"
                style="<?php echo $statut_f === 'en_attente' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-clock" style="color: #FF4A0D;"></i> En attente
            </a>

            <a class="commandes-pill" href="?statut=annulee&q=<?php echo urlencode($search); ?>"
                style="<?php echo $statut_f === 'annulee' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-ban" style="color: #000000;"></i> Annulées
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form class="commandes-search-form" method="GET" action="commandes.php">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($statut_f); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="N° commande, client..."
                style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 180px; background: #ffffff;">
            <button type="submit" class="dash-btn-action"
                style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($statut_f !== 'tous' || $search !== ''): ?>
                <a href="commandes.php" style="color: #000000; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS
         ============================================================================== -->
    <div class="commandes-kpis">
        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Commandes Payées</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D; font-variant-numeric: tabular-nums;">
                <?php echo str_replace(' ', '&nbsp;', number_format($tot_payees, 0, ',', ' ')); ?>
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Transactions finalisées avec succès</small>
        </div>

        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Volume Financier</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-sack-dollar"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D; font-variant-numeric: tabular-nums;">
                <?php echo str_replace(' ', '&nbsp;', number_format($tot_ca, 0, ',', ' ')); ?>&nbsp;F
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Total encaissé sur les commandes</small>
        </div>

        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Panier Moyen</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-receipt"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D; font-variant-numeric: tabular-nums;">
                <?php echo str_replace(' ', '&nbsp;', number_format($panier_moyen, 0, ',', ' ')); ?>&nbsp;F
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Dépense moyenne par commande</small>
        </div>

        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Total Commandes</span>
                <span style="background: #F5F5F5; color: var(--dash-muted); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-cart-shopping"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text); font-variant-numeric: tabular-nums;">
                <?php echo str_replace(' ', '&nbsp;', number_format($tot_orders, 0, ',', ' ')); ?>
            </div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Tous statuts confondus</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES COMMANDES & CARTES MOBILES SUISSES
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Commandes
                (<?php echo count($orders); ?>)
            </h3>
        </div>

        <?php if (empty($orders)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-cart-shopping"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucune commande ne correspond aux critères de recherche.
            </div>
        <?php else: ?>
            <div class="commandes-table-wrapper">
                <table class="dash-table commandes-table">
                    <thead>
                        <tr>
                            <th>N° Commande</th>
                            <th>Client / Acheteur</th>
                            <th>Billets</th>
                            <th>Montant Total</th>
                            <th>Statut</th>
                            <th>Date de Commande</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <?php
                            // Badges statut (style suisse épuré sans émojis)
                            $badge_statut_html = '';
                            if ($o['statut'] === 'payee') {
                                $badge_statut_html = '<span style="background: #FFF2ED; color: #000000; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">Payée</span>';
                            } elseif ($o['statut'] === 'annulee') {
                                $badge_statut_html = '<span style="background: #F5F5F5; color: #000000; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">Annulée</span>';
                            } else {
                                $badge_statut_html = '<span style="background: #FFF2ED; color: #FF4A0D; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">En attente</span>';
                            }
                            ?>
                            <tr>
                                <td class="card-top" data-label="N° Commande">
                                    <strong style="font-family: 'Space Mono', monospace; font-size: 0.90rem; color: var(--dash-primary);">
                                        #<?php echo htmlspecialchars($o['numero_commande']); ?>
                                    </strong>
                                    <span class="mobile-badge"><?php echo $badge_statut_html; ?></span>
                                </td>
                                <td data-label="Client">
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($o['client_nom'] ?? 'Client Anonyme'); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($o['client_email'] ?? '—'); ?>
                                    </small>
                                </td>
                                <td data-label="Billets">
                                    <span style="font-weight: 700; font-size: 0.85rem; color: var(--dash-text); font-variant-numeric: tabular-nums;">
                                        <i class="fa-solid fa-ticket" style="color: var(--dash-muted); margin-right: 3px;"></i>
                                        <?php echo (int) $o['nb_billets']; ?> billet(s)
                                    </span>
                                </td>
                                <td data-label="Montant Total">
                                    <strong class="cell-amount">
                                        <?php echo str_replace(' ', '&nbsp;', number_format($o['montant_total'], 0, ',', ' ')) . '&nbsp;F'; ?>
                                    </strong>
                                </td>
                                <td class="hide-on-mobile-card" data-label="Statut">
                                    <span class="desktop-badge"><?php echo $badge_statut_html; ?></span>
                                </td>
                                <td data-label="Date">
                                    <span style="font-size: 0.82rem; color: var(--dash-muted); font-variant-numeric: tabular-nums;">
                                        <?php echo !empty($o['created_at']) ? date('d/m/Y H:i', strtotime($o['created_at'])) : '—'; ?>
                                    </span>
                                </td>
                                <td class="card-actions" style="text-align: right;">
                                    <div class="cell-actions-group">
                                        <?php if ($o['statut'] === 'payee'): ?>
                                            <a href="../client/telecharger-ticket.php?order_id=<?php echo (int) $o['id']; ?>"
                                                target="_blank" class="dash-btn-action"
                                                style="padding: 0.35rem 0.65rem; font-size: 0.76rem; background: #F5F5F5; color: var(--dash-text); text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"
                                                title="Télécharger le PDF officiel des billets">
                                                <i class="fa-solid fa-file-pdf" style="color: #000000;"></i> <span>PDF</span>
                                            </a>

                                            <a href="?resend_tickets=<?php echo (int) $o['id']; ?>&statut=<?php echo urlencode($statut_f); ?>&q=<?php echo urlencode($search); ?>"
                                                onclick="return confirm('Renvoyer immédiatement les billets officiels par e-mail au client ?');"
                                                class="dash-btn-action"
                                                style="padding: 0.35rem 0.65rem; font-size: 0.76rem; background: #F5F5F5; color: var(--dash-primary); text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"
                                                title="Renvoyer l'e-mail des billets avec QR codes">
                                                <i class="fa-solid fa-paper-plane" style="color: var(--dash-primary);"></i> <span>Renvoyer</span>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #737373; font-size: 0.76rem;">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>