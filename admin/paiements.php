<?php
// ==============================================================================
// GESTION DES PAIEMENTS MOBILE MONEY (admin/paiements.php)
// Design Dashboard Pro Suisse - Traçabilité de tous les encaissements (Wave, Orange, MTN, Moov)
// ==============================================================================

$admin_page_title = "Paiements & Encaissements - Administration";
include 'header.php';

// Filtres
$methode_filter = trim($_GET['methode'] ?? '');
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT p.*, o.numero_commande, u.nom AS client_nom, u.email AS client_email 
    FROM payments p 
    LEFT JOIN orders o ON o.id = p.order_id 
    LEFT JOIN users u ON u.id = p.user_id 
    WHERE 1=1
";
$params = [];

if (!empty($methode_filter) && in_array($methode_filter, ['wave', 'orange_money', 'mtn_money', 'moov_money'], true)) {
    $sql .= " AND LOWER(p.methode) = ?";
    $params[] = $methode_filter;
}

if (!empty($search)) {
    $sql .= " AND (p.reference LIKE ? OR o.numero_commande LIKE ? OR u.nom LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// KPIs globaux
$tot_encaisse = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM payments WHERE statut = 'paye'")->fetchColumn();
$tot_transac = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE statut = 'paye'")->fetchColumn();

// Par méthode
$stmt_m = $pdo->query("
    SELECT LOWER(methode) as m, COALESCE(SUM(montant), 0) as tot 
    FROM payments WHERE statut = 'paye' 
    GROUP BY LOWER(methode)
")->fetchAll(PDO::FETCH_KEY_PAIR);

$tot_wave = (float) ($stmt_m['wave'] ?? 0);
$tot_orange = (float) ($stmt_m['orange_money'] ?? 0);
$tot_mtn = (float) ($stmt_m['mtn_money'] ?? 0);
$tot_moov = (float) ($stmt_m['moov_money'] ?? 0);
?>

<style>
/* ==============================================================================
   DESIGN SYSTEM SUISSE & CARTES RESPONSIVES (admin/paiements.php)
   ============================================================================== */
.paiements-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.paiements-header .dash-title-box h1 {
    font-size: clamp(1.25rem, 3.2vw, 1.75rem);
    font-weight: 800;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0 0 0.35rem 0;
}
.paiements-header .dash-title-box p {
    color: var(--dash-muted, #737373);
    font-size: 0.88rem;
    margin: 0;
}
.paiements-header-actions {
    display: flex;
    gap: 0.65rem;
    flex-wrap: wrap;
    align-items: center;
}

/* 2. Barre de filtres opérateurs avec défilement tactile */
.paiements-filter-bar {
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
.paiements-pills-nav {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 2px;
}
.paiements-pills-nav::-webkit-scrollbar {
    display: none;
}
.paiements-pill {
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
.paiements-search-form {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    margin: 0;
    flex-wrap: wrap;
}

/* 3. KPIs de trésorerie */
.paiements-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: clamp(0.6rem, 1.8vw, 1rem);
    margin-bottom: 1.5rem;
}
.paiements-kpis .dash-kpi-card {
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
.paiements-table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box;
}
.dash-table.paiements-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.85rem;
}
.dash-table.paiements-table th {
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
.dash-table.paiements-table td {
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
    .dash-table.paiements-table {
        min-width: 900px !important;
    }
}

@media (max-width: 1150px) {
    .paiements-kpis {
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
    .paiements-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
    }
    .paiements-header-actions {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 0.45rem !important;
    }
    .paiements-header-actions a {
        width: 100% !important;
        justify-content: center !important;
        text-align: center !important;
        box-sizing: border-box !important;
    }
    .paiements-filter-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
        padding: 0.65rem !important;
    }
    .paiements-pills-nav {
        width: 100% !important;
    }
    .paiements-search-form {
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.45rem !important;
    }
    .paiements-search-form input[type="text"],
    .paiements-search-form button,
    .paiements-search-form a {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        justify-content: center !important;
        text-align: center !important;
    }
    .paiements-kpis {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.50rem !important;
        margin-bottom: 1rem !important;
    }
    .paiements-kpis .dash-kpi-card {
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

    .paiements-table-wrapper {
        overflow: visible !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }
    .dash-table.paiements-table {
        display: block !important;
        min-width: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        border: none !important;
        box-sizing: border-box !important;
    }
    .dash-table.paiements-table thead {
        display: none !important;
    }
    .dash-table.paiements-table tbody {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.85rem !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    .dash-table.paiements-table tr {
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
    .dash-table.paiements-table tr:hover td {
        background: transparent !important;
    }
    .dash-table.paiements-table td {
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
    .dash-table.paiements-table td:last-child {
        border-bottom: none !important;
        padding-bottom: 0 !important;
    }
    .dash-table.paiements-table td::before {
        content: attr(data-label);
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        color: #737373;
        letter-spacing: 0.04em;
        text-align: left;
        flex-shrink: 0;
    }

    .dash-table.paiements-table td.card-top {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding-top: 0 !important;
        padding-bottom: 0.65rem !important;
        border-bottom: 1px solid #E5E5E5 !important;
    }
    .dash-table.paiements-table td.card-top::before {
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
}

@media (max-width: 420px) {
    .paiements-kpis {
        grid-template-columns: 1fr !important;
    }
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="paiements-header">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-credit-card" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Flux Financiers & Paiements Mobile Money
            </h1>
            <p>Supervisez tous les encaissements instantanés par opérateur Mobile Money (Wave, Orange, MTN, Moov).</p>
        </div>

        <div class="paiements-header-actions">
            <a href="export.php?type=commandes" class="dash-btn-action"
                style="padding: 0.6rem 1.15rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;" title="Exporter les paiements et commandes sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <a href="retraits.php" class="dash-btn-action btn-primary"
                style="padding: 0.6rem 1.2rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-money-bill-transfer"></i> Gérer les Retraits Promoteurs
            </a>
        </div>
    </div>

    <!-- ==============================================================================
         2. BARRE DE FILTRES EN HAUT (PILULES OPÉRATEURS ACTIVES)
         ============================================================================== -->
    <div class="paiements-filter-bar">
        <!-- À GAUCHE : PILULES OPÉRATEURS -->
        <div class="paiements-pills-nav">
            <a class="paiements-pill" href="?methode=&q=<?php echo urlencode($search); ?>"
                style="<?php echo $methode_filter === '' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-layer-group" style="<?php echo $methode_filter === '' ? 'color: #FF4A0D;' : ''; ?>"></i> Tous
            </a>

            <a class="paiements-pill" href="?methode=wave&q=<?php echo urlencode($search); ?>"
                style="<?php echo $methode_filter === 'wave' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-water" style="color: #FF4A0D;"></i> Wave
            </a>

            <a class="paiements-pill" href="?methode=orange_money&q=<?php echo urlencode($search); ?>"
                style="<?php echo $methode_filter === 'orange_money' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-mobile-screen" style="color: #FF4A0D;"></i> Orange Money
            </a>

            <a class="paiements-pill" href="?methode=mtn_money&q=<?php echo urlencode($search); ?>"
                style="<?php echo $methode_filter === 'mtn_money' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-mobile-screen" style="color: #FF4A0D;"></i> MTN Money
            </a>

            <a class="paiements-pill" href="?methode=moov_money&q=<?php echo urlencode($search); ?>"
                style="<?php echo $methode_filter === 'moov_money' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-mobile-screen" style="color: #FF4A0D;"></i> Moov Money
            </a>
        </div>

        <!-- À DROITE : RECHERCHE -->
        <form method="GET" action="paiements.php" class="paiements-search-form">
            <input type="hidden" name="methode" value="<?php echo htmlspecialchars($methode_filter); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Réf, n° commande, client..."
                style="padding: 0.45rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 190px; background: #ffffff;">
            <button type="submit" class="dash-btn-action"
                style="padding: 0.45rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>
            <?php if ($methode_filter !== '' || $search !== ''): ?>
                <a href="paiements.php" style="color: #000000; font-size: 0.78rem; text-decoration: underline; padding-left: 4px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS DE PAIEMENT
         ============================================================================== -->
    <div class="paiements-kpis">
        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Total Brut Encaissé</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-vault"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D; font-variant-numeric: tabular-nums;">
                <?php echo str_replace(' ', '&nbsp;', number_format($tot_encaisse, 0, ',', ' ')); ?>&nbsp;F
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem;"><?php echo number_format($tot_transac, 0, ',', ' '); ?> transactions réussies</small>
        </div>

        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Wave Mobile Money</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-water"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D; font-variant-numeric: tabular-nums;">
                <?php echo str_replace(' ', '&nbsp;', number_format($tot_wave, 0, ',', ' ')); ?>&nbsp;F
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Encaissés via QR & Wave</small>
        </div>

        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Orange Money</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-mobile-screen"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D; font-variant-numeric: tabular-nums;">
                <?php echo str_replace(' ', '&nbsp;', number_format($tot_orange, 0, ',', ' ')); ?>&nbsp;F
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Encaissés via Orange Money</small>
        </div>

        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">MTN & Moov</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-mobile-screen-button"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D; font-variant-numeric: tabular-nums;">
                <?php echo str_replace(' ', '&nbsp;', number_format($tot_mtn + $tot_moov, 0, ',', ' ')); ?>&nbsp;F
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">MTN (<?php echo number_format($tot_mtn, 0, ',', ' '); ?>) · Moov (<?php echo number_format($tot_moov, 0, ',', ' '); ?>)</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES TRANSACTIONS & CARTES MOBILES
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Journal des Transactions Mobile Money
                (<?php echo count($payments); ?>)
            </h3>
        </div>

        <?php if (empty($payments)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-credit-card"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucun paiement ne correspond aux filtres sélectionnés.
            </div>
        <?php else: ?>
            <div class="paiements-table-wrapper">
                <table class="dash-table paiements-table">
                    <thead>
                        <tr>
                            <th>Réf. Transaction</th>
                            <th>N° Commande</th>
                            <th>Acheteur</th>
                            <th>Moyen Mobile Money</th>
                            <th>Montant</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Date d'Encaissement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <?php
                            $m_label = ucfirst(str_replace('_', ' ', $p['methode']));
                            
                            // Badge statut sobre
                            $badge_statut_html = '';
                            if ($p['statut'] === 'paye') {
                                $badge_statut_html = '<span style="background: #FFF2ED; color: #000000; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">Payé</span>';
                            } elseif ($p['statut'] === 'echoue') {
                                $badge_statut_html = '<span style="background: #F5F5F5; color: #000000; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">Échoué</span>';
                            } else {
                                $badge_statut_html = '<span style="background: #FFF2ED; color: #FF4A0D; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem; display: inline-flex; align-items: center; white-space: nowrap;">En attente</span>';
                            }

                            // Icône opérateur
                            $op_icon = 'fa-mobile-screen-button';
                            $op_color = '#FF4A0D';
                            $m_lower = strtolower($p['methode'] ?? '');
                            if (strpos($m_lower, 'orange') !== false) {
                                $op_icon = 'fa-mobile-screen';
                                $op_color = '#FF4A0D';
                            } elseif (strpos($m_lower, 'mtn') !== false) {
                                $op_icon = 'fa-mobile-screen';
                                $op_color = '#FF4A0D';
                            } elseif (strpos($m_lower, 'moov') !== false) {
                                $op_icon = 'fa-mobile-screen';
                                $op_color = '#FF4A0D';
                            } elseif (strpos($m_lower, 'wave') !== false) {
                                $op_icon = 'fa-water';
                                $op_color = '#FF4A0D';
                            }
                            ?>
                            <tr>
                                <td class="card-top" data-label="Réf. Transaction">
                                    <strong style="font-family: 'Space Mono', monospace; font-size: 0.90rem; color: var(--dash-primary); word-break: break-all;">
                                        <?php echo htmlspecialchars($p['reference']); ?>
                                    </strong>
                                    <span class="mobile-badge"><?php echo $badge_statut_html; ?></span>
                                </td>
                                <td data-label="N° Commande">
                                    <span style="font-family: 'Space Mono', monospace; font-weight: 700; color: var(--dash-text); font-size: 0.85rem;">
                                        #<?php echo htmlspecialchars($p['numero_commande'] ?? '—'); ?>
                                    </span>
                                </td>
                                <td data-label="Acheteur">
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block;">
                                        <?php echo htmlspecialchars($p['client_nom'] ?? 'Client Anonyme'); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.74rem;">
                                        <?php echo htmlspecialchars($p['client_email'] ?? '—'); ?>
                                    </small>
                                </td>
                                <td data-label="Moyen">
                                    <span style="font-weight: 800; font-size: 0.82rem; color: var(--dash-text); display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;">
                                        <i class="fa-solid <?php echo $op_icon; ?>" style="color: <?php echo $op_color; ?>;"></i>
                                        <?php echo htmlspecialchars($m_label); ?>
                                    </span>
                                </td>
                                <td data-label="Montant">
                                    <strong class="cell-amount">
                                        <?php echo str_replace(' ', '&nbsp;', number_format($p['montant'], 0, ',', ' ')) . '&nbsp;F'; ?>
                                    </strong>
                                </td>
                                <td class="hide-on-mobile-card" data-label="Statut">
                                    <span class="desktop-badge"><?php echo $badge_statut_html; ?></span>
                                </td>
                                <td data-label="Date d'Encaissement" style="text-align: right;">
                                    <span style="font-size: 0.82rem; color: var(--dash-muted); font-variant-numeric: tabular-nums; white-space: nowrap;">
                                        <?php echo !empty($p['date_paiement']) ? date('d/m/Y H:i', strtotime($p['date_paiement'])) : date('d/m/Y H:i', strtotime($p['created_at'])); ?>
                                    </span>
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