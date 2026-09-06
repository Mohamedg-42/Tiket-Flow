<?php
// ==============================================================================
// GESTION & TRAÇABILITÉ DES TICKETS (admin/tickets.php)
// Design Dashboard Pro - Contrôle, filtrage et validation des billets émis
// ==============================================================================

$admin_page_title = "Gestion des Billets - Administration";
include 'header.php';

$filter_event = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$filter_status = trim($_GET['statut'] ?? '');
$filter_search = trim($_GET['q'] ?? '');

$sql_tickets = "
    SELECT t.*, e.nom AS event_name, u.nom AS client_nom, u.email AS client_email, ag.nom AS agent_nom
    FROM tickets t
    JOIN events e ON t.event_id = e.id
    JOIN users u ON t.user_id = u.id
    LEFT JOIN users ag ON t.validated_by = ag.id
    WHERE 1=1
";
$params = [];

if (!empty($filter_event)) {
    $sql_tickets .= " AND t.event_id = ?";
    $params[] = $filter_event;
}

if (!empty($filter_status) && in_array($filter_status, ['vendu', 'utilise', 'annule'], true)) {
    $sql_tickets .= " AND t.statut = ?";
    $params[] = $filter_status;
}

if (!empty($filter_search)) {
    $sql_tickets .= " AND (t.code_unique LIKE ? OR u.nom LIKE ? OR u.email LIKE ? OR e.nom LIKE ?)";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
}

$sql_tickets .= " ORDER BY t.date_achat DESC";
$stmt_tks = $pdo->prepare($sql_tickets);
$stmt_tks->execute($params);
$tickets_list = $stmt_tks->fetchAll();

// KPIs
$tot_emis = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE statut != 'annule'")->fetchColumn();
$tot_scannes = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE statut = 'utilise'")->fetchColumn();
$tot_valides = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE statut = 'vendu'")->fetchColumn();
$recette_tkt = (float) $pdo->query("SELECT COALESCE(SUM(prix), 0) FROM tickets WHERE statut != 'annule'")->fetchColumn();

// Liste des événements pour filtre
$events_list = $pdo->query("SELECT id, nom FROM events ORDER BY nom ASC")->fetchAll();
?>
<style>
/* ==============================================================================
   RESPONSIVE DESIGN : TRAÇABILITÉ & GESTION DES BILLETS
   ============================================================================== */
.dash-container {
    padding: clamp(0.85rem, 2.5vw, 1.75rem);
    max-width: 100%;
    box-sizing: border-box;
}

.tickets-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.tickets-title-box h1 {
    font-size: clamp(1.25rem, 3.2vw, 1.75rem);
    font-weight: 800;
    color: var(--dash-text, #000000);
    margin: 0 0 0.35rem;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

.tickets-title-box p {
    color: var(--dash-muted, #737373);
    font-size: clamp(0.82rem, 1.8vw, 0.92rem);
    margin: 0;
    line-height: 1.45;
}

/* 2. Barre de filtres responsive */
.tickets-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    background: #ffffff;
    padding: 0.65rem 0.85rem;
    border-radius: 12px;
    border: 1px solid var(--dash-border, #E5E5E5);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    flex-wrap: wrap;
}

.tickets-pills-wrap {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
    max-width: 100%;
}
.tickets-pills-wrap::-webkit-scrollbar {
    display: none;
}
.tickets-pill-item {
    text-decoration: none;
    border-radius: 9px;
    padding: 0.45rem 0.95rem;
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    flex-shrink: 0;
    transition: all 0.18s ease;
}

.tickets-search-form {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    margin: 0;
    flex-wrap: wrap;
}

/* 3. Grille des KPIs équilibrée */
.tickets-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: clamp(0.6rem, 1.8vw, 1rem);
    margin-bottom: 1.75rem;
}
.tickets-kpis .dash-kpi-card {
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
.tickets-kpis .kpi-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}
.tickets-kpis .kpi-lbl {
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.tickets-kpis .kpi-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.tickets-kpis .kpi-val {
    font-size: 1.65rem;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 0.25rem;
}
.tickets-kpis .kpi-sub {
    font-size: 0.75rem;
    line-height: 1.35;
}

/* 4. Table Wrapper & Cells anti-wrap */
.tickets-table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box;
}

@media (min-width: 821px) {
    .dash-table.tickets-table {
        min-width: 960px !important;
        width: 100% !important;
        border-collapse: separate;
        border-spacing: 0;
    }
}

.tickets-table th {
    white-space: nowrap !important;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    color: #737373;
    letter-spacing: 0.05em;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--dash-border, #E5E5E5);
}

.cell-code {
    font-family: 'Space Mono', monospace;
    font-size: 0.90rem;
    font-weight: 800;
    color: var(--dash-primary, #000000);
    white-space: nowrap !important;
}

/* PRIX/TARIF : STRICTEMENT SUR UNE SEULE LIGNE */
td[data-label="Tarif"],
.cell-tarif {
    color: #FF4A0D !important;
    font-size: 0.92rem !important;
    font-weight: 800 !important;
    white-space: nowrap !important;
    word-break: keep-all !important;
    font-variant-numeric: tabular-nums !important;
    letter-spacing: -0.01em;
}

/* BADGES DE STATUT : STRICTEMENT SUR UNE SEULE LIGNE */
td[data-label="Statut"],
.cell-status-badge {
    white-space: nowrap !important;
    word-break: keep-all !important;
}
.cell-status-badge {
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 800;
    font-size: 0.74rem;
    white-space: nowrap !important;
    display: inline-flex !important;
    align-items: center;
    gap: 5px;
    line-height: 1.2;
}

@media (min-width: 821px) {
    .card-top-only {
        display: none !important;
    }
}

@media (max-width: 1100px) {
    .tickets-kpis {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 820px) {
    .tickets-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.85rem !important;
    }
    .tickets-header .dash-btn-action {
        width: 100% !important;
        justify-content: center !important;
    }
    .tickets-filter-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.85rem !important;
    }
    .tickets-pills-wrap {
        width: 100% !important;
    }
    .tickets-search-form {
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.5rem !important;
    }
    .tickets-search-form select,
    .tickets-search-form input[type="text"],
    .tickets-search-form button,
    .tickets-search-form a.dash-btn-action {
        width: 100% !important;
        max-width: 100% !important;
        justify-content: center !important;
    }

    .tickets-kpis {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.55rem !important;
        margin-bottom: 1.25rem !important;
    }
    .tickets-kpis .dash-kpi-card {
        padding: 0.85rem 0.75rem !important;
    }
    .tickets-kpis .kpi-lbl {
        font-size: 0.7rem !important;
    }
    .tickets-kpis .kpi-icon {
        width: 28px !important;
        height: 28px !important;
        font-size: 0.78rem !important;
    }
    .tickets-kpis .kpi-val {
        font-size: 1.35rem !important;
    }
    .tickets-kpis .kpi-sub {
        font-size: 0.68rem !important;
        line-height: 1.25 !important;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .dash-card {
        padding: 0.65rem 0.5rem !important;
        border-radius: 12px !important;
        overflow: visible !important;
    }

    /* Transformation responsive du tableau en cartes structurées */
    .tickets-table-wrapper {
        overflow: visible !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .dash-table.tickets-table {
        display: block !important;
        min-width: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        border: none !important;
        box-sizing: border-box !important;
    }
    .dash-table.tickets-table thead {
        display: none !important;
    }
    .dash-table.tickets-table tbody {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.85rem !important;
        width: 100% !important;
    }
    .dash-table.tickets-table tr {
        display: block !important;
        background: #ffffff !important;
        border: 1px solid var(--dash-border, #E5E5E5) !important;
        border-radius: 12px !important;
        padding: 0.95rem 1rem !important;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04) !important;
        box-sizing: border-box !important;
        width: 100% !important;
    }
    .dash-table.tickets-table tr:hover td {
        background: transparent !important;
    }
    .dash-table.tickets-table td {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        gap: 0.65rem !important;
        padding: 0.52rem 0 !important;
        border-bottom: 1px dashed #E5E5E5 !important;
        max-width: 100% !important;
        text-align: right !important;
        font-size: 0.84rem !important;
        white-space: normal !important;
        box-sizing: border-box !important;
    }
    .dash-table.tickets-table td::before {
        content: attr(data-label);
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        color: #737373;
        letter-spacing: 0.04em;
        text-align: left;
        flex-shrink: 0;
    }
    .dash-table.tickets-table td.card-top {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding-top: 0 !important;
        padding-bottom: 0.65rem !important;
        border-bottom: 1px solid #E5E5E5 !important;
    }
    .dash-table.tickets-table td.card-top::before {
        display: none !important;
    }
    .dash-table.tickets-table td.hide-on-mobile-card {
        display: none !important;
    }
    .dash-table.tickets-table td:last-child {
        border-bottom: none !important;
        padding-bottom: 0 !important;
    }
}

@media (max-width: 380px) {
    .tickets-kpis {
        grid-template-columns: 1fr !important;
    }
    .tickets-pill-item {
        font-size: 0.76rem !important;
        padding: 0.4rem 0.75rem !important;
    }
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO RESPONSIVE
         ============================================================================== -->
    <div class="tickets-header">
        <div class="tickets-title-box">
            <h1>
                <i class="fa-solid fa-ticket" style="color: var(--dash-primary); font-size: 1.45rem;"></i>
                Traçabilité & Contrôle des Billets
            </h1>
            <p>Historique des billets vendus, validation aux accès et recherche par QR / Code unique.</p>
        </div>

        <div>
            <a href="verification.php" class="dash-btn-action btn-primary"
                style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-qrcode"></i> Ouvrir le Scanner Direct
            </a>
        </div>
    </div>

    <!-- ==============================================================================
         2. BARRE DE FILTRES MULTI-CRITÈRES EN HAUT (AU-DESSUS DES KPIS)
         ============================================================================== -->
    <div class="tickets-filter-bar">
        <!-- À GAUCHE : PILULES STATUT -->
        <div class="tickets-pills-wrap">
            <a href="?statut=&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($filter_search); ?>"
                class="tickets-pill-item"
                style="<?php echo $filter_status === '' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $filter_status === '' ? 'color: #FF4A0D;' : ''; ?>"></i>
                Tous (<?php echo $tot_emis; ?>)
            </a>

            <a href="?statut=vendu&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($filter_search); ?>"
                class="tickets-pill-item"
                style="<?php echo $filter_status === 'vendu' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-circle-check" style="color: #FF4A0D;"></i> Valides (<?php echo $tot_valides; ?>)
            </a>

            <a href="?statut=utilise&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($filter_search); ?>"
                class="tickets-pill-item"
                style="<?php echo $filter_status === 'utilise' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-barcode" style="color: #FF4A0D;"></i> Compostés / Utilisés
                (<?php echo $tot_scannes; ?>)
            </a>

            <a href="?statut=annule&event_id=<?php echo $filter_event; ?>&q=<?php echo urlencode($filter_search); ?>"
                class="tickets-pill-item"
                style="<?php echo $filter_status === 'annule' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-ban" style="color: #000000;"></i> Annulés
            </a>
        </div>

        <!-- À DROITE : SÉLECTEUR ÉVÉNEMENT & RECHERCHE -->
        <form method="GET" action="tickets.php" class="tickets-search-form">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($filter_status); ?>">

            <select name="event_id" onchange="this.form.submit()"
                style="padding: 0.42rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer; max-width: 100%; box-sizing: border-box; text-overflow: ellipsis;">
                <option value="">Tous les événements</option>
                <?php foreach ($events_list as $ev): ?>
                    <option value="<?php echo $ev['id']; ?>" <?php echo ($filter_event == $ev['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(mb_strimwidth($ev['nom'], 0, 25, '...')); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="q" value="<?php echo htmlspecialchars($filter_search); ?>"
                placeholder="Code, client, email..."
                style="padding: 0.42rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 170px; max-width: 100%; box-sizing: border-box; background: #ffffff;">

            <button type="submit" class="dash-btn-action"
                style="padding: 0.42rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <!-- Export Excel des billets -->
            <a href="export.php?type=tickets&event_id=<?php echo (int)$filter_event; ?>&statut=<?php echo urlencode($filter_status); ?>&q=<?php echo urlencode($filter_search); ?>" class="dash-btn-action"
                style="padding: 0.42rem 0.85rem; font-size: 0.82rem; text-decoration: none;" title="Exporter tous les billets sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i>
                <span>Exporter Excel</span>
            </a>

            <?php if ($filter_status !== '' || $filter_event || $filter_search !== ''): ?>
                <a href="tickets.php" style="color: #000000; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS RESPONSIVE
         ============================================================================== -->
    <div class="tickets-kpis">
        <div class="dash-kpi-card">
            <div class="kpi-top">
                <span class="kpi-lbl" style="color: #FF4A0D;">Billets Actifs (Valides)</span>
                <span class="kpi-icon" style="background: #FFF2ED; color: #FF4A0D;"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <div class="kpi-val" style="color: #FF4A0D;"><?php echo number_format($tot_valides, 0, ',', ' '); ?></div>
            <small class="kpi-sub" style="color: #FF4A0D;">En attente de passage aux portes</small>
        </div>

        <div class="dash-kpi-card">
            <div class="kpi-top">
                <span class="kpi-lbl" style="color: #FF4A0D;">Participants Scannés</span>
                <span class="kpi-icon" style="background: #FFF2ED; color: #FF4A0D;"><i class="fa-solid fa-qrcode"></i></span>
            </div>
            <div class="kpi-val" style="color: #FF4A0D;"><?php echo number_format($tot_scannes, 0, ',', ' '); ?></div>
            <small class="kpi-sub" style="color: #FF4A0D;">Entrées compostées par les agents</small>
        </div>

        <div class="dash-kpi-card">
            <div class="kpi-top">
                <span class="kpi-lbl" style="color: #FF4A0D;">Recette Billetterie</span>
                <span class="kpi-icon" style="background: #FFF2ED; color: #FF4A0D;"><i class="fa-solid fa-sack-dollar"></i></span>
            </div>
            <div class="kpi-val" style="color: #FF4A0D;"><?php echo number_format($recette_tkt, 0, ',', ' '); ?> F</div>
            <small class="kpi-sub" style="color: #FF4A0D;">Total des billets vendus</small>
        </div>

        <div class="dash-kpi-card">
            <div class="kpi-top">
                <span class="kpi-lbl" style="color: var(--dash-muted);">Total Billets Émis</span>
                <span class="kpi-icon" style="background: #F5F5F5; color: var(--dash-muted);"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div class="kpi-val" style="color: var(--dash-text);"><?php echo number_format($tot_emis, 0, ',', ' '); ?></div>
            <small class="kpi-sub" style="color: var(--dash-muted);">Volume global de billetterie</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES BILLETS
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Billets Filtrés
                (<?php echo count($tickets_list); ?>)
            </h3>
        </div>

        <?php if (empty($tickets_list)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-ticket"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucun billet ne correspond à vos critères de recherche.
            </div>
        <?php else: ?>
            <div class="tickets-table-wrapper">
                <table class="dash-table tickets-table">
                    <thead>
                        <tr>
                            <th>Code Unique</th>
                            <th>Événement</th>
                            <th>Formule</th>
                            <th>Tarif</th>
                            <th>Acheteur</th>
                            <th>Date d'Achat</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Contrôle Entrée</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets_list as $t): ?>
                            <tr>
                                <td class="card-top" data-label="Code Unique">
                                    <strong class="cell-code">
                                        <?php echo htmlspecialchars($t['code_unique']); ?>
                                    </strong>
                                    <div class="card-top-only" style="display: flex; align-items: center; gap: 6px;">
                                        <span style="background: #FFF2ED; color: #FF4A0D; padding: 2px 7px; border-radius: 6px; font-weight: 800; font-size: 0.72rem; white-space: nowrap;">
                                            <?php echo htmlspecialchars($t['type_ticket']); ?>
                                        </span>
                                        <?php if ($t['statut'] === 'vendu'): ?>
                                            <span class="cell-status-badge" style="background: #FFF2ED; color: #000000;">Valide</span>
                                        <?php elseif ($t['statut'] === 'utilise'): ?>
                                            <span class="cell-status-badge" style="background: #FFF2ED; color: #FF4A0D;">Composté</span>
                                        <?php else: ?>
                                            <span class="cell-status-badge" style="background: #F5F5F5; color: #000000;">Annulé</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Événement">
                                    <strong style="color: var(--dash-text); font-size: 0.88rem; display: block; max-width: 220px;">
                                        <?php echo htmlspecialchars($t['event_name']); ?>
                                    </strong>
                                </td>
                                <td data-label="Formule" class="hide-on-mobile-card" style="white-space: nowrap;">
                                    <span
                                        style="background: #FFF2ED; color: #FF4A0D; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.75rem; white-space: nowrap;">
                                        <?php echo htmlspecialchars($t['type_ticket']); ?>
                                    </span>
                                </td>
                                <td data-label="Tarif" style="white-space: nowrap;">
                                    <strong class="cell-tarif" style="white-space: nowrap;">
                                        <?php echo str_replace(' ', '&nbsp;', number_format($t['prix'], 0, ',', ' ')) . '&nbsp;F'; ?>
                                    </strong>
                                </td>
                                <td data-label="Acheteur">
                                    <div style="max-width: 200px;">
                                        <span
                                            style="color: var(--dash-text); font-weight: 700; font-size: 0.84rem; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($t['client_nom']); ?>
                                        </span>
                                        <small style="color: var(--dash-muted); font-size: 0.74rem; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($t['client_email']); ?>
                                        </small>
                                    </div>
                                </td>
                                <td data-label="Date d'Achat" style="white-space: nowrap;">
                                    <span style="font-size: 0.82rem; color: var(--dash-muted); white-space: nowrap;">
                                        <?php echo date('d/m/Y H:i', strtotime($t['date_achat'])); ?>
                                    </span>
                                </td>
                                <td data-label="Statut" class="hide-on-mobile-card" style="white-space: nowrap;">
                                    <?php if ($t['statut'] === 'vendu'): ?>
                                        <span class="cell-status-badge" style="background: #FFF2ED; color: #000000;">Valide</span>
                                    <?php elseif ($t['statut'] === 'utilise'): ?>
                                        <span class="cell-status-badge" style="background: #FFF2ED; color: #FF4A0D;">Composté</span>
                                    <?php else: ?>
                                        <span class="cell-status-badge" style="background: #F5F5F5; color: #000000;">Annulé</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Contrôle Entrée" style="text-align: right; white-space: nowrap;">
                                    <?php if ($t['statut'] === 'utilise'): ?>
                                        <small style="color: var(--dash-muted); font-size: 0.75rem; white-space: nowrap;">
                                            Scanné le <?php echo date('d/m H:i', strtotime($t['date_utilisation'])); ?>
                                            <?php if ($t['agent_nom']): ?>
                                                par <strong><?php echo htmlspecialchars($t['agent_nom']); ?></strong>
                                            <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <span style="color: var(--dash-muted); font-size: 0.76rem; font-style: italic; white-space: nowrap;">Non scanné</span>
                                    <?php endif; ?>
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