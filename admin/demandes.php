<?php
// ==============================================================================
// CENTRE DES DEMANDES ADMINISTRATEUR (admin/demandes.php)
// Design Dashboard Pro - Validation d'événements, cotisations & plébiscites de vote
// ==============================================================================

$admin_page_title = "Centre des Demandes - Administration";
include 'header.php';
require_once '../includes/commission.php';

$message = "";
$msg_type = "";

$tab = $_GET['tab'] ?? 'evenements';
if (!in_array($tab, ['evenements', 'cotisations', 'votes'], true)) {
    $tab = 'evenements';
}

// ============ TRAITEMENT DES ACTIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ----- Événements : approuver / refuser -----
    if ($action === 'approuver_evenement' || $action === 'refuser_evenement') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM event_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req || $req['statut'] !== 'en_attente') {
            $message = "Cette demande n'existe pas ou a déjà été traitée.";
            $msg_type = "error";
        } elseif ($action === 'approuver_evenement') {
            $commission_rate = (float) ($_POST['commission_rate'] ?? 5.00);
            if ($commission_rate < 0)
                $commission_rate = 5.00;

            try {
                $pdo->beginTransaction();

                $salle_id = !empty($req['salle_id']) ? (int) $req['salle_id'] : null;
                $stmt_ev = $pdo->prepare("INSERT INTO events (user_id, nom, description, image, categorie, date_evenement, heure, lieu, prix_vote, type_vote, vote_question, commission_rate, salle_id, statut) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')");
                $stmt_ev->execute([$req['user_id'], $req['nom'], $req['description'], $req['image'], $req['categorie'], $req['date_evenement'], $req['heure'], $req['lieu'], (float) ($req['prix_vote'] ?? 0), $req['type_vote'] ?? 'aucun', $req['vote_question'] ?? null, $commission_rate, $salle_id]);
                $new_event_id = (int) $pdo->lastInsertId();

                $ticket_types = json_decode($req['ticket_types_data'] ?? '[]', true);
                if (!empty($ticket_types) && is_array($ticket_types)) {
                    require_once '../includes/places.php';
                    $stmt_tt = $pdo->prepare("INSERT INTO ticket_types (event_id, nom, prix, frais_place, quantite, places_choisies, quantite_vendue) VALUES (?, ?, ?, ?, ?, ?, 0)");
                    foreach ($ticket_types as $tt) {
                        $p_choisies = isset($tt['places_choisies']) ? max(0, (int) $tt['places_choisies']) : 0;
                        $stmt_tt->execute([
                            $new_event_id,
                            $tt['nom'],
                            (float) $tt['prix'],
                            (float) ($tt['frais_place'] ?? 0),
                            (int) $tt['quantite'],
                            $p_choisies
                        ]);
                        // Génération automatique des places pour ce tarif
                        generer_places_type($pdo, (int) $pdo->lastInsertId(), (int) $tt['quantite']);
                    }
                }

                // Création automatique des candidats / options de vote dans 'event_candidats'
                $candidats_data = json_decode($req['candidats_data'] ?? '[]', true);
                if (!empty($candidats_data) && is_array($candidats_data)) {
                    $stmt_c_ins = $pdo->prepare("INSERT INTO event_candidats (event_id, nom, description, photo) VALUES (?, ?, ?, ?)");
                    foreach ($candidats_data as $cd) {
                        $stmt_c_ins->execute([
                            $new_event_id,
                            $cd['nom'],
                            $cd['description'] ?? null,
                            $cd['photo'] ?? null
                        ]);
                    }
                }

                $pdo->prepare("UPDATE event_requests SET statut = 'approuve', reviewed_at = NOW() WHERE id = ?")->execute([$request_id]);
                $pdo->commit();

                $message = "L'événement « " . htmlspecialchars($req['nom']) . " » a été approuvé et publié sur la billetterie (commission : " . $commission_rate . "%).";
                $msg_type = "success";
            } catch (Exception $e) {
                if ($pdo->inTransaction())
                    $pdo->rollBack();
                $message = "Erreur lors de la validation : " . $e->getMessage();
                $msg_type = "error";
            }
        } else {
            $commentaire = trim($_POST['commentaire_admin'] ?? '') ?: 'Aucun motif fourni';
            $pdo->prepare("UPDATE event_requests SET statut = 'refuse', commentaire_admin = ?, reviewed_at = NOW() WHERE id = ?")->execute([$commentaire, $request_id]);
            $message = "La demande d'événement a été refusée. Le promoteur verra le motif dans son espace.";
            $msg_type = "success";
        }
        // ----- Cotisations : approuver / refuser une campagne -----
    } elseif ($action === 'approuver_campagne' || $action === 'refuser_campagne') {
        $campagne_id = (int) ($_POST['campagne_id'] ?? 0);
        $stmt_c = $pdo->prepare("SELECT titre FROM cotisation_campagnes WHERE id = ? AND statut = 'en_attente'");
        $stmt_c->execute([$campagne_id]);
        $camp = $stmt_c->fetch();

        if (!$camp) {
            $message = "Cette campagne n'existe pas ou a déjà été traitée.";
            $msg_type = "error";
        } elseif ($action === 'approuver_campagne') {
            $pdo->prepare("UPDATE cotisation_campagnes SET statut = 'active', commentaire_admin = NULL, reviewed_at = NOW() WHERE id = ?")->execute([$campagne_id]);
            $message = "La campagne « " . htmlspecialchars($camp['titre']) . " » est approuvée : elle est désormais visible dans l'onglet Cotisations du site.";
            $msg_type = "success";
        } else {
            $commentaire = trim($_POST['commentaire_admin'] ?? '') ?: 'Aucun motif fourni';
            $pdo->prepare("UPDATE cotisation_campagnes SET statut = 'refuse', commentaire_admin = ?, reviewed_at = NOW() WHERE id = ?")->execute([$commentaire, $campagne_id]);
            $message = "La campagne « " . htmlspecialchars($camp['titre']) . " » a été refusée. Le promoteur verra le motif dans son espace.";
            $msg_type = "success";
        }
    }
}

// ============ RÉCUPÉRATION DES DONNÉES SELON L'ONGLET ============
$event_requests = [];
$campagnes_list = [];
$classement_votes = [];

// Onglet 1 : Demandes d'événements
try {
    $event_requests = $pdo->query("
        SELECT r.*, u.nom AS promoteur_nom, u.email AS promoteur_email, p.commission_rate AS promoteur_commission_rate
        FROM event_requests r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN promoters p ON p.user_id = r.user_id
        ORDER BY (r.statut = 'en_attente') DESC, r.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $event_requests = [];
}

// Onglet 2 : Campagnes de cotisation
try {
    $campagnes_list = $pdo->query("
        SELECT c.*, u.nom AS promoteur_nom, u.email AS promoteur_email
        FROM cotisation_campagnes c
        LEFT JOIN users u ON c.user_id = u.id
        ORDER BY (c.statut = 'en_attente') DESC, c.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $campagnes_list = [];
}

// Onglet 3 : Votes & plébiscites
try {
    $classement_votes = $pdo->query("
        SELECT e.id, e.nom, e.categorie, e.date_evenement, e.lieu, e.prix_vote, e.type_vote,
               (SELECT COUNT(*) FROM event_votes v WHERE v.event_id = e.id) AS nb_votes,
               (SELECT COUNT(*) FROM event_likes l WHERE l.event_id = e.id) AS nb_likes,
               (SELECT COALESCE(SUM(vp.montant), 0) FROM vote_paiements vp WHERE vp.event_id = e.id AND vp.statut = 'paye') AS recettes_votes,
               u.nom AS promoteur_nom
        FROM events e
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.statut = 'actif'
        ORDER BY nb_votes DESC, nb_likes DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $classement_votes = [];
}

// Compteurs pour les badges
$nb_events_pending = count(array_filter($event_requests, fn($r) => $r['statut'] === 'en_attente'));
$nb_campagnes_pending = count(array_filter($campagnes_list, fn($c) => $c['statut'] === 'en_attente'));
?>
<style>
/* ==============================================================================
   RESPONSIVE DESIGN SYSTEM : CENTRE DES DEMANDES & VALIDATIONS
   Style Typographique International & Cartes Mobiles Fluides
   ============================================================================== */
.dash-container {
    padding: clamp(0.85rem, 2.5vw, 1.75rem);
    max-width: 100%;
    box-sizing: border-box;
}

.demandes-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.demandes-title-box h1 {
    font-size: clamp(1.25rem, 3.2vw, 1.75rem);
    font-weight: 800;
    color: var(--dash-text, #000000);
    margin: 0 0 0.35rem;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

.demandes-title-box p {
    color: var(--dash-muted, #737373);
    font-size: clamp(0.82rem, 1.8vw, 0.92rem);
    margin: 0;
    line-height: 1.45;
}

/* 2. Onglets à défilement tactile fluide sur mobile */
.demandes-tab-nav {
    display: flex;
    gap: 0.45rem;
    margin-bottom: 1.5rem;
    background: #ffffff;
    padding: 0.5rem 0.75rem;
    border-radius: 12px;
    border: 1px solid var(--dash-border, #E5E5E5);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.demandes-tab-nav::-webkit-scrollbar {
    display: none;
}
.demandes-tab-item {
    text-decoration: none;
    border-radius: 9px;
    padding: 0.55rem 1rem;
    font-size: 0.84rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    white-space: nowrap;
    flex-shrink: 0;
    transition: all 0.18s ease;
}

/* 3. Grille des KPIs équilibrée */
.demandes-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: clamp(0.6rem, 1.8vw, 1rem);
    margin-bottom: 1.75rem;
}
.demandes-kpis .dash-kpi-card {
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
.demandes-kpis .kpi-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}
.demandes-kpis .kpi-lbl {
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.demandes-kpis .kpi-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.demandes-kpis .kpi-val {
    font-size: 1.65rem;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 0.25rem;
}
.demandes-kpis .kpi-sub {
    font-size: 0.75rem;
    line-height: 1.35;
}

/* 4. Conteneur tableau standard pour grand écran */
.demandes-table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box;
}

/* ==============================================================================
   RESPONSIVE BREAKPOINTS (Tablette & Mobile)
   ============================================================================== */
@media (max-width: 1100px) {
    .demandes-kpis {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .demandes-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.85rem !important;
    }
    .demandes-header .dash-btn-action {
        width: 100% !important;
        justify-content: center !important;
    }
    .demandes-kpis {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.55rem !important;
        margin-bottom: 1.25rem !important;
    }
    .demandes-kpis .dash-kpi-card {
        padding: 0.85rem 0.75rem !important;
    }
    .demandes-kpis .kpi-lbl {
        font-size: 0.7rem !important;
    }
    .demandes-kpis .kpi-icon {
        width: 28px !important;
        height: 28px !important;
        font-size: 0.78rem !important;
    }
    .demandes-kpis .kpi-val {
        font-size: 1.35rem !important;
    }
    .demandes-kpis .kpi-sub {
        font-size: 0.68rem !important;
        line-height: 1.25 !important;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* Transformation responsive du tableau en cartes structurées */
    .demandes-table-wrapper {
        overflow-x: visible !important;
    }
    .dash-table.demandes-table {
        display: block !important;
        min-width: 0 !important;
        width: 100% !important;
        border: none !important;
    }
    .dash-table.demandes-table thead {
        display: none !important;
    }
    .dash-table.demandes-table tbody {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.85rem !important;
        width: 100% !important;
    }
    .dash-table.demandes-table tr {
        display: block !important;
        background: #ffffff !important;
        border: 1px solid var(--dash-border, #E5E5E5) !important;
        border-radius: 12px !important;
        padding: 0.95rem 1rem !important;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04) !important;
        box-sizing: border-box !important;
        width: 100% !important;
    }
    .dash-table.demandes-table tr:hover td {
        background: transparent !important;
    }
    .dash-table.demandes-table td {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        gap: 0.65rem !important;
        padding: 0.55rem 0 !important;
        border-bottom: 1px dashed #E5E5E5 !important;
        max-width: 100% !important;
        text-align: right !important;
        font-size: 0.84rem !important;
        white-space: normal !important;
        box-sizing: border-box !important;
    }
    .dash-table.demandes-table td::before {
        content: attr(data-label);
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        color: #737373;
        letter-spacing: 0.04em;
        text-align: left;
        flex-shrink: 0;
    }

    /* En-tête de la carte (titre événement/campagne) */
    .dash-table.demandes-table td.cell-primary {
        display: block !important;
        text-align: left !important;
        padding-top: 0 !important;
        padding-bottom: 0.75rem !important;
        border-bottom: 1px solid #E5E5E5 !important;
    }
    .dash-table.demandes-table td.cell-primary::before {
        display: none !important;
    }

    /* Ligne de rang (onglet votes) */
    .dash-table.demandes-table td.cell-rank {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding-bottom: 0.55rem !important;
        border-bottom: 1px solid #E5E5E5 !important;
    }

    /* Pied de la carte (actions administratives) */
    .dash-table.demandes-table td.cell-actions {
        display: block !important;
        text-align: left !important;
        padding-top: 0.8rem !important;
        padding-bottom: 0 !important;
        border-bottom: none !important;
    }
    .dash-table.demandes-table td.cell-actions::before {
        display: none !important;
    }
    .dash-table.demandes-table td.cell-actions .action-group {
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.5rem !important;
        width: 100% !important;
    }
    .dash-table.demandes-table td.cell-actions form {
        width: 100% !important;
        display: flex !important;
        align-items: center !important;
        gap: 0.5rem !important;
        margin: 0 !important;
    }
    .dash-table.demandes-table td.cell-actions form button {
        flex: 1 !important;
        justify-content: center !important;
        padding: 0.52rem 0.85rem !important;
    }
    .dash-table.demandes-table td.cell-actions a.dash-btn-action {
        display: flex !important;
        width: 100% !important;
        justify-content: center !important;
        padding: 0.52rem !important;
        box-sizing: border-box !important;
    }
}

@media (max-width: 380px) {
    .demandes-kpis {
        grid-template-columns: 1fr !important;
    }
    .demandes-tab-item {
        font-size: 0.76rem !important;
        padding: 0.45rem 0.75rem !important;
    }
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO RESPONSIVE
         ============================================================================== -->
    <div class="demandes-header">
        <div class="demandes-title-box">
            <h1>
                <i class="fa-solid fa-inbox" style="color: var(--dash-primary); font-size: 1.45rem;"></i>
                Centre des Demandes & Validations
            </h1>
            <p>Examinez les propositions d'événements, validez les campagnes de cotisation et suivez les votes du public.</p>
        </div>
        <div>
            <a href="export.php?type=demandes&tab=<?php echo urlencode($tab); ?>" class="dash-btn-action" style="padding: 0.6rem 1.15rem; text-decoration: none;" title="Exporter les demandes sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div
            style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. ONGLETS DE NAVIGATION DÉFILABLES FLUIDES
         ============================================================================== -->
    <div class="demandes-tab-nav">
        <a href="?tab=evenements" class="demandes-tab-item"
            style="<?php echo $tab === 'evenements' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
            <i class="fa-solid fa-calendar-plus" style="<?php echo $tab === 'evenements' ? 'color: #FF4A0D;' : ''; ?>"></i>
            <span>Demandes d'Événements</span>
            <?php if ($nb_events_pending > 0): ?>
                <span style="background: #000000; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_events_pending; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=cotisations" class="demandes-tab-item"
            style="<?php echo $tab === 'cotisations' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
            <i class="fa-solid fa-hand-holding-heart" style="color: #FF4A0D;"></i>
            <span>Campagnes de Cotisation</span>
            <?php if ($nb_campagnes_pending > 0): ?>
                <span style="background: #FF4A0D; color: #ffffff; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; font-weight: 800;"><?php echo $nb_campagnes_pending; ?></span>
            <?php endif; ?>
        </a>

        <a href="?tab=votes" class="demandes-tab-item"
            style="<?php echo $tab === 'votes' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
            <i class="fa-solid fa-chart-simple" style="color: #FF4A0D;"></i>
            <span>Votes & Plébiscites</span>
        </a>
    </div>

    <!-- ==============================================================================
         2bis. KPIS DE SYNTHÈSE (GRILLE RESPONSIVE PRO)
         ============================================================================== -->
    <?php
    $kpi_evt_attente = (int) $pdo->query("SELECT COUNT(*) FROM event_requests WHERE statut = 'en_attente'")->fetchColumn();
    $kpi_evt_approuves = (int) $pdo->query("SELECT COUNT(*) FROM event_requests WHERE statut = 'approuve'")->fetchColumn();
    $kpi_camp_attente = (int) $pdo->query("SELECT COUNT(*) FROM cotisation_campagnes WHERE statut = 'en_attente'")->fetchColumn();
    $kpi_votes_total = (int) $pdo->query("SELECT COUNT(*) FROM event_votes")->fetchColumn();
    ?>
    <div class="demandes-kpis">
        <div class="dash-kpi-card">
            <div class="kpi-top">
                <span class="kpi-lbl" style="color: #FF4A0D;">Événements en Attente</span>
                <span class="kpi-icon" style="background: #FFF2ED; color: #FF4A0D;"><i class="fa-solid fa-hourglass-half"></i></span>
            </div>
            <div class="kpi-val" style="color: #FF4A0D;"><?php echo $kpi_evt_attente; ?></div>
            <small class="kpi-sub" style="color: #FF4A0D;">Demandes d'événements à traiter</small>
        </div>

        <div class="dash-kpi-card">
            <div class="kpi-top">
                <span class="kpi-lbl" style="color: #FF4A0D;">Événements Validés</span>
                <span class="kpi-icon" style="background: #FFF2ED; color: #FF4A0D;"><i class="fa-solid fa-calendar-check"></i></span>
            </div>
            <div class="kpi-val" style="color: #FF4A0D;"><?php echo $kpi_evt_approuves; ?></div>
            <small class="kpi-sub" style="color: #FF4A0D;">Publiés sur la billetterie</small>
        </div>

        <div class="dash-kpi-card">
            <div class="kpi-top">
                <span class="kpi-lbl" style="color: #FF4A0D;">Campagnes en Attente</span>
                <span class="kpi-icon" style="background: #F5F5F5; color: #FF4A0D;"><i class="fa-solid fa-hand-holding-heart"></i></span>
            </div>
            <div class="kpi-val" style="color: #FF4A0D;"><?php echo $kpi_camp_attente; ?></div>
            <small class="kpi-sub" style="color: #FF4A0D;">Cotisations à examiner</small>
        </div>

        <div class="dash-kpi-card">
            <div class="kpi-top">
                <span class="kpi-lbl" style="color: #FF4A0D;">Votes du Public</span>
                <span class="kpi-icon" style="background: #FFF2ED; color: #FF4A0D;"><i class="fa-solid fa-chart-simple"></i></span>
            </div>
            <div class="kpi-val" style="color: #FF4A0D;"><?php echo $kpi_votes_total; ?></div>
            <small class="kpi-sub" style="color: #FF4A0D;">Suffrages exprimés sur la plateforme</small>
        </div>
    </div>

    <!-- ==============================================================================
         3. CONTENU SELON L'ONGLET SÉLECTIONNÉ
         ============================================================================== -->
    <?php if ($tab === 'evenements'): ?>
        <!-- A. DEMANDES D'ÉVÉNEMENTS -->
        <div class="dash-card">
            <div class="dash-card-head" style="margin-bottom: 1rem;">
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-calendar-plus" style="color: #FF4A0D;"></i> Demandes d'Événements Reçues
                </h3>
            </div>

            <?php if (empty($event_requests)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                    <i class="fa-solid fa-calendar-xmark"
                        style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                    Aucune proposition d'événement enregistrée pour l'instant.
                </div>
            <?php else: ?>
                <div class="demandes-table-wrapper">
                    <table class="dash-table demandes-table">
                        <thead>
                            <tr>
                                <th>Demande</th>
                                <th>Organisateur</th>
                                <th>Date & Lieu</th>
                                <th>Billetterie</th>
                                <th>Statut</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($event_requests as $r): ?>
                                <?php
                                $is_pending = ($r['statut'] === 'en_attente');
                                $t_data = json_decode($r['ticket_types_data'] ?? '[]', true) ?: [];
                                $r_candidats = json_decode($r['candidats_data'] ?? '[]', true) ?: [];
                                $has_vote = !empty($r['type_vote']) && $r['type_vote'] !== 'aucun' && $r['type_vote'] !== '';
                                $total_places_demandees = 0;
                                $total_recette_estimee = 0;
                                foreach ($t_data as $tt) {
                                    $t_qty = (int) ($tt['quantite'] ?? 0);
                                    $t_px  = (float) ($tt['prix'] ?? 0);
                                    $total_places_demandees += $t_qty;
                                    $total_recette_estimee += ($t_qty * $t_px);
                                }
                                $req_scale = get_event_scale_tier($total_places_demandees, $total_recette_estimee);
                                $promoter_custom_rate = isset($r['promoteur_commission_rate']) && $r['promoteur_commission_rate'] !== null ? (float)$r['promoteur_commission_rate'] : null;
                                $suggested_comm_rate = ($promoter_custom_rate !== null) ? $promoter_custom_rate : (!empty($r['commission_rate']) ? (float)$r['commission_rate'] : (float)$req_scale['rate']);
                                $badge_st = [
                                    'en_attente' => ['En attente', '#FFF2ED', '#FF4A0D'],
                                    'approuve' => ['Approuvée', '#FFF2ED', '#000000'],
                                    'refuse' => ['Refusée', '#F5F5F5', '#000000']
                                ];
                                [$st_text, $st_bg, $st_fg] = $badge_st[$r['statut']] ?? ['Inconnu', '#F5F5F5', '#737373'];
                                ?>
                                <tr>
                                    <td class="cell-primary" data-label="Demande" style="max-width: 340px;">
                                        <strong style="color: var(--dash-text); font-size: 0.92rem; display: block; line-height: 1.35;">
                                            <?php echo htmlspecialchars($r['nom']); ?>
                                        </strong>
                                        <span style="color: var(--dash-muted); font-size: 0.76rem; margin-top: 4px; display: block; line-height: 1.4;">
                                            <i class="fa-solid fa-tag" style="color: var(--dash-primary);"></i>
                                            <?php echo htmlspecialchars($r['categorie']); ?>
                                            <?php if ($has_vote): ?> &nbsp;•&nbsp;
                                                <?php echo ($r['type_vote'] === 'concours') ? 'Concours' : 'Réalisation'; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($r_candidats)): ?> &nbsp;•&nbsp; <i class="fa-solid fa-users"></i>
                                                <?php echo count($r_candidats); ?> candidat(s)<?php endif; ?>
                                            <br><i class="fa-regular fa-clock"></i> Soumise le
                                            <?php echo date('d/m/Y à H:i', strtotime($r['created_at'])); ?>
                                        </span>
                                        <details style="margin-top: 8px;">
                                            <summary style="font-size: 0.76rem; color: #FF4A0D; font-weight: 700; cursor: pointer; user-select: none;">
                                                <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i> Voir les détails complets
                                            </summary>
                                            <div
                                                style="margin-top: 8px; padding: 10px 12px; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.8rem; color: #737373; line-height: 1.5;">
                                                <?php echo nl2br(htmlspecialchars($r['description'])); ?>

                                                <!-- Système de vote demandé & candidats proposés (aide à la décision) -->
                                                <?php if ($has_vote || !empty($r_candidats)): ?>
                                                    <div
                                                        style="background: #FFF2ED; border: 1px solid #E5E5E5; border-radius: 8px; padding: 0.85rem 1rem; margin: 0.85rem 0;">
                                                        <?php if ($has_vote): ?>
                                                            <div
                                                                style="font-size: 0.82rem; color: #FF4A0D; margin-bottom: <?php echo !empty($r_candidats) ? '0.5rem' : '0'; ?>;">
                                                                <i class="fa-solid fa-chart-simple"></i> <strong>Système de vote demandé :</strong>
                                                                <?php echo ($r['type_vote'] === 'concours') ? 'Concours (avec candidats)' : 'Réalisation'; ?>
                                                                <?php if ((float) ($r['prix_vote'] ?? 0) > 0): ?>
                                                                    — <strong><?php echo number_format((float) $r['prix_vote'], 0, ',', ' '); ?> F</strong> / vote (payant)
                                                                <?php else: ?>
                                                                    — Gratuit
                                                                <?php endif; ?>
                                                                <?php if (!empty($r['vote_question'])): ?>
                                                                    <div style="margin-top: 0.25rem; font-style: italic;">« <?php echo htmlspecialchars($r['vote_question']); ?> »</div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($r_candidats)): ?>
                                                            <div style="font-size: 0.8rem; color: #000000;">
                                                                <strong><i class="fa-solid fa-users"></i> <?php echo count($r_candidats); ?> candidat(s) proposé(s) :</strong>
                                                                <ul style="margin: 0.35rem 0 0; padding-left: 1.2rem;">
                                                                    <?php foreach ($r_candidats as $rc): ?>
                                                                        <li><?php echo htmlspecialchars($rc['nom'] ?? 'Candidat'); ?><?php echo !empty($rc['description']) ? ' — ' . htmlspecialchars($rc['description']) : ''; ?></li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Billetterie proposée -->
                                                <?php if (!empty($t_data)): ?>
                                                    <div
                                                        style="background: #FFF2ED; border: 1px solid #FFF2ED; border-radius: 8px; padding: 0.7rem 1rem; margin: 0.85rem 0; font-size: 0.8rem; color: #FF4A0D;">
                                                        <strong><i class="fa-solid fa-ticket"></i> Billetterie proposée :</strong>
                                                        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;">
                                                            <?php foreach ($t_data as $i => $tt): ?>
                                                                <span
                                                                    style="display: inline-block; background: #ffffff; border: 1px solid #FFF2ED; border-radius: 6px; padding: 3px 9px; font-size: 0.78rem;">
                                                                    <?php echo htmlspecialchars($tt['nom'] ?? 'Tarif'); ?> —
                                                                    <strong><?php echo number_format((float) ($tt['prix'] ?? 0), 0, ',', ' '); ?> F</strong> ×
                                                                    <?php echo (int) ($tt['quantite'] ?? 0); ?> places
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Justificatifs & type de demandeur (dans les détails) -->
                                                <div
                                                    style="display: flex; gap: 1rem; font-size: 0.82rem; flex-wrap: wrap; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #E5E5E5;">
                                                    <span><i class="fa-solid fa-building-user"></i> Type :
                                                        <strong><?php echo $r['type_personne'] === 'morale' ? 'Personne morale' : 'Personne physique'; ?></strong></span>
                                                    <?php if ($r['document_justificatif']): ?>
                                                        <a href="../uploads/event_docs/<?php echo htmlspecialchars($r['document_justificatif']); ?>"
                                                            target="_blank"
                                                            style="color: #FF4A0D; font-weight: 700; text-decoration: underline;"><i
                                                                class="fa-solid fa-file-pdf"></i> Pièce justificative</a>
                                                    <?php endif; ?>
                                                    <?php if ($r['document_autorisation']): ?>
                                                        <a href="../uploads/event_docs/<?php echo htmlspecialchars($r['document_autorisation']); ?>"
                                                            target="_blank"
                                                            style="color: #FF4A0D; font-weight: 700; text-decoration: underline;"><i
                                                                class="fa-solid fa-file-shield"></i> Autorisation légale</a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </details>
                                    </td>
                                    <td data-label="Organisateur">
                                        <div>
                                            <span style="font-size: 0.84rem; font-weight: 700; color: var(--dash-text); display: block;">
                                                <?php echo htmlspecialchars($r['promoteur_nom'] ?? 'Promoteur inconnu'); ?>
                                            </span>
                                            <small style="color: var(--dash-muted); font-size: 0.76rem; word-break: break-all;">
                                                <?php echo htmlspecialchars($r['promoteur_email']); ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td data-label="Date & Lieu">
                                        <div>
                                            <span style="font-size: 0.84rem; color: var(--dash-text); display: block; font-weight: 600;">
                                                <i class="fa-regular fa-calendar"></i>
                                                <?php echo date('d/m/Y', strtotime($r['date_evenement'])); ?>
                                            </span>
                                            <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                                <?php echo date('H\hi', strtotime($r['heure'])); ?> — <i
                                                    class="fa-solid fa-location-dot" style="color: #000000;"></i>
                                                <?php echo htmlspecialchars(mb_strimwidth($r['lieu'] ?? '', 0, 22, '...')); ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td data-label="Billetterie">
                                        <div>
                                            <?php if (!empty($t_data)): ?>
                                                <strong style="font-size: 0.84rem; color: var(--dash-text); display: block;"><?php echo count($t_data); ?> tarif(s)</strong>
                                                <small style="color: var(--dash-muted); font-size: 0.76rem;"><?php echo number_format($total_places_demandees, 0, ',', ' '); ?> places</small>
                                                <div style="margin-top: 4px;">
                                                    <?php echo render_scale_badge_html($req_scale); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--dash-muted); font-size: 0.8rem;">Sans billetterie</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Statut">
                                        <div>
                                            <span
                                                style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; padding: 3px 9px; border-radius: 6px; font-weight: 800; font-size: 0.74rem; display: inline-block;">
                                                <?php echo $st_text; ?>
                                            </span>
                                            <?php if (!$is_pending && !empty($r['reviewed_at'])): ?>
                                                <small
                                                    style="color: var(--dash-muted); font-size: 0.72rem; display: block; margin-top: 3px;">Le
                                                    <?php echo date('d/m/Y', strtotime($r['reviewed_at'])); ?></small>
                                            <?php endif; ?>
                                            <?php if ($r['statut'] === 'refuse' && !empty($r['commentaire_admin'])): ?>
                                                <small style="color: #000000; font-size: 0.72rem; display: block; margin-top: 3px;"
                                                    title="<?php echo htmlspecialchars($r['commentaire_admin']); ?>">
                                                    <i class="fa-solid fa-comment-dots"></i>
                                                    <?php echo htmlspecialchars(mb_strimwidth($r['commentaire_admin'], 0, 26, '...')); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="cell-actions" data-label="Actions" style="text-align: right;">
                                        <?php if ($is_pending): ?>
                                            <div class="action-group">
                                                <form method="POST" style="display: inline-flex; align-items: center; gap: 0.5rem; margin: 0;">
                                                    <input type="hidden" name="action" value="approuver_evenement">
                                                    <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                                    <div
                                                        style="display: inline-flex; align-items: center; gap: 4px; background: #FFF2ED; border: 1px solid #FF4A0D; border-radius: 8px; padding: 3px 8px; flex-shrink: 0;"
                                                        title="Barème suggéré selon l'ampleur : <?php echo htmlspecialchars($req_scale['name']); ?>">
                                                        <span style="font-size: 0.74rem; color: #FF4A0D; font-weight: 700;">Com.</span>
                                                        <input type="number" name="commission_rate" value="<?php echo number_format($suggested_comm_rate, 1, '.', ''); ?>" min="0" max="30" step="0.5"
                                                            style="width: 44px; border: 0; background: transparent; font-weight: 800; font-size: 0.8rem; text-align: center; outline: none; color: #FF4A0D;">
                                                        <span style="font-size: 0.74rem; color: #FF4A0D; font-weight: 700;">%</span>
                                                    </div>
                                                    <button type="submit" class="dash-btn-action"
                                                        style="background: #FF4A0D; color: #ffffff; padding: 0.4rem 0.9rem; font-size: 0.8rem; font-weight: 800; border-color: #FF4A0D;">
                                                        <i class="fa-solid fa-check"></i> Approuver
                                                    </button>
                                                </form>
                                                <form method="POST"
                                                    onsubmit="var m = prompt('Motif du refus (visible par le promoteur) :', 'Dossier incomplet ou non conforme'); if (m === null || m.trim() === '') return false; this.querySelector('[name=commentaire_admin]').value = m.trim(); return true;"
                                                    style="margin: 0; width: 100%;">
                                                    <input type="hidden" name="action" value="refuser_evenement">
                                                    <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                                    <input type="hidden" name="commentaire_admin" value="Dossier incomplet ou non conforme">
                                                    <button type="submit" class="dash-btn-action"
                                                        style="background: #F5F5F5; color: #000000; padding: 0.38rem 0.85rem; font-size: 0.78rem; font-weight: 800; border-color: #E5E5E5; width: 100%; justify-content: center;">
                                                        <i class="fa-solid fa-xmark"></i> Refuser
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <a href="../client/accueil.php" target="_blank" class="dash-btn-action"
                                                style="padding: 0.38rem 0.75rem; font-size: 0.76rem;" title="Voir la vitrine publique">
                                                <i class="fa-solid fa-eye"></i> Voir vitrine
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($tab === 'cotisations'): ?>
        <!-- B. CAMPAGNES DE COTISATION -->
        <div class="dash-card">
            <div class="dash-card-head" style="margin-bottom: 1rem;">
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-hand-holding-heart" style="color: #FF4A0D;"></i> Campagnes de Cotisation Proposées
                </h3>
            </div>

            <?php if (empty($campagnes_list)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                    <i class="fa-solid fa-hand-holding-heart"
                        style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                    Aucune campagne de cotisation proposée pour l'instant.
                </div>
            <?php else: ?>
                <div class="demandes-table-wrapper">
                    <table class="dash-table demandes-table">
                        <thead>
                            <tr>
                                <th>Campagne</th>
                                <th>Organisateur</th>
                                <th>Objectif</th>
                                <th>Statut</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campagnes_list as $c): ?>
                                <?php
                                $is_pending = ($c['statut'] === 'en_attente');
                                $badge_st = [
                                    'en_attente' => ['En attente', '#FFF2ED', '#FF4A0D'],
                                    'active' => ['Active', '#FFF2ED', '#000000'],
                                    'terminee' => ['Terminée', '#F5F5F5', '#737373'],
                                    'refuse' => ['Refusée', '#F5F5F5', '#000000']
                                ];
                                [$st_text, $st_bg, $st_fg] = $badge_st[$c['statut']] ?? ['Inconnu', '#F5F5F5', '#737373'];
                                ?>
                                <tr>
                                    <td class="cell-primary" data-label="Campagne" style="max-width: 340px;">
                                        <strong style="color: var(--dash-text); font-size: 0.92rem; display: block; line-height: 1.35;">
                                            <?php echo htmlspecialchars($c['titre']); ?>
                                        </strong>
                                        <?php if (!empty($c['date_limite'])): ?>
                                            <small style="color: var(--dash-muted); font-size: 0.76rem; display: block; margin-top: 4px;">
                                                <i class="fa-regular fa-calendar"></i> Échéance :
                                                <?php echo date('d/m/Y', strtotime($c['date_limite'])); ?>
                                            </small>
                                        <?php endif; ?>
                                        <details style="margin-top: 8px;">
                                            <summary style="font-size: 0.76rem; color: #FF4A0D; font-weight: 700; cursor: pointer; user-select: none;">
                                                <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i> Voir la description
                                            </summary>
                                            <div
                                                style="margin-top: 8px; padding: 10px 12px; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 8px; font-size: 0.8rem; color: #737373; line-height: 1.5;">
                                                <?php echo nl2br(htmlspecialchars($c['description'] ?? '')); ?>
                                            </div>
                                        </details>
                                    </td>
                                    <td data-label="Organisateur">
                                        <div>
                                            <span style="font-size: 0.84rem; font-weight: 700; color: var(--dash-text); display: block;">
                                                <?php echo htmlspecialchars($c['promoteur_nom'] ?? 'Promoteur'); ?>
                                            </span>
                                            <small style="color: var(--dash-muted); font-size: 0.76rem; word-break: break-all;">
                                                <?php echo htmlspecialchars($c['promoteur_email'] ?? ''); ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td data-label="Objectif">
                                        <strong style="font-size: 0.92rem; color: #FF4A0D;">
                                            <?php echo number_format((float) $c['montant_objectif'], 0, ',', ' '); ?> F
                                        </strong>
                                    </td>
                                    <td data-label="Statut">
                                        <div>
                                            <span
                                                style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; padding: 3px 9px; border-radius: 6px; font-weight: 800; font-size: 0.74rem; display: inline-block;">
                                                <?php echo $st_text; ?>
                                            </span>
                                            <?php if ($c['statut'] === 'refuse' && !empty($c['commentaire_admin'])): ?>
                                                <small style="color: #000000; font-size: 0.72rem; display: block; margin-top: 3px;"
                                                    title="<?php echo htmlspecialchars($c['commentaire_admin']); ?>">
                                                    <i class="fa-solid fa-comment-dots"></i>
                                                    <?php echo htmlspecialchars(mb_strimwidth($c['commentaire_admin'], 0, 26, '...')); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="cell-actions" data-label="Actions" style="text-align: right;">
                                        <?php if ($is_pending): ?>
                                            <div class="action-group">
                                                <form method="POST" style="margin: 0; width: 100%;">
                                                    <input type="hidden" name="action" value="approuver_campagne">
                                                    <input type="hidden" name="campagne_id" value="<?php echo $c['id']; ?>">
                                                    <button type="submit" class="dash-btn-action"
                                                        style="background: #FF4A0D; color: #ffffff; padding: 0.42rem 0.9rem; font-size: 0.8rem; font-weight: 800; border-color: #FF4A0D; width: 100%; justify-content: center;">
                                                        <i class="fa-solid fa-check"></i> Valider la campagne
                                                    </button>
                                                </form>
                                                <form method="POST"
                                                    onsubmit="var m = prompt('Motif du refus (visible par le promoteur) :', 'Campagne non conforme aux règles de la plateforme'); if (m === null || m.trim() === '') return false; this.querySelector('[name=commentaire_admin]').value = m.trim(); return true;"
                                                    style="margin: 0; width: 100%;">
                                                    <input type="hidden" name="action" value="refuser_campagne">
                                                    <input type="hidden" name="campagne_id" value="<?php echo $c['id']; ?>">
                                                    <input type="hidden" name="commentaire_admin" value="Campagne non validée">
                                                    <button type="submit" class="dash-btn-action"
                                                        style="background: #F5F5F5; color: #000000; padding: 0.38rem 0.85rem; font-size: 0.78rem; font-weight: 800; border-color: #E5E5E5; width: 100%; justify-content: center;">
                                                        <i class="fa-solid fa-xmark"></i> Refuser
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($tab === 'votes'): ?>
        <!-- C. VOTES DU PUBLIC & ENGAGEMENT -->
        <div class="dash-card">
            <div class="dash-card-head" style="margin-bottom: 1rem;">
                <h3 class="dash-card-title">
                    <i class="fa-solid fa-ranking-star" style="color: #FF4A0D;"></i> Plébiscite et Votes du Public
                </h3>
            </div>

            <div class="demandes-table-wrapper">
                <table class="dash-table demandes-table">
                    <thead>
                        <tr>
                            <th>Rang</th>
                            <th>Événement</th>
                            <th>Organisateur</th>
                            <th>Type de Vote</th>
                            <th>Suffrages</th>
                            <th>Likes</th>
                            <th>Recettes Encaissées</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rg = 1;
                        foreach ($classement_votes as $v):
                            ?>
                            <tr>
                                <td class="cell-rank" data-label="Rang">
                                    <strong style="font-size: 0.95rem; color: var(--dash-text);">
                                        <?php echo ($rg === 1) ? '🥇 1er' : (($rg === 2) ? '🥈 2e' : (($rg === 3) ? '🥉 3e' : '#' . $rg)); ?>
                                    </strong>
                                </td>
                                <td class="cell-primary" data-label="Événement">
                                    <strong style="color: var(--dash-text); font-size: 0.92rem; display: block; line-height: 1.35;">
                                        <?php echo htmlspecialchars($v['nom']); ?>
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem; margin-top: 3px; display: block;">
                                        <i class="fa-regular fa-calendar"></i>
                                        <?php echo date('d/m/Y', strtotime($v['date_evenement'])); ?>
                                    </small>
                                </td>
                                <td data-label="Organisateur">
                                    <span style="font-weight: 700; color: var(--dash-text); font-size: 0.84rem;">
                                        <?php echo htmlspecialchars($v['promoteur_nom'] ?? 'Organisateur'); ?>
                                    </span>
                                </td>
                                <td data-label="Type de Vote">
                                    <span
                                        style="background: <?php echo ($v['type_vote'] === 'concours') ? '#FFF2ED' : '#FFF2ED'; ?>; color: <?php echo ($v['type_vote'] === 'concours') ? '#FF4A0D' : '#FF4A0D'; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem; display: inline-block;">
                                        <?php echo ($v['type_vote'] === 'concours') ? 'Concours' : 'Réalisation'; ?>
                                    </span>
                                </td>
                                <td data-label="Suffrages">
                                    <strong style="color: #FF4A0D; font-size: 0.95rem;">
                                        <?php echo number_format((int) $v['nb_votes'], 0, ',', ' '); ?> votes
                                    </strong>
                                </td>
                                <td data-label="Likes">
                                    <span style="color: #000000; font-weight: 700; font-size: 0.85rem;">
                                        <i class="fa-solid fa-heart"></i> <?php echo (int) $v['nb_likes']; ?>
                                    </span>
                                </td>
                                <td data-label="Recettes">
                                    <strong style="color: #FF4A0D; font-size: 0.92rem;">
                                        <?php echo number_format((float) $v['recettes_votes'], 0, ',', ' '); ?> F
                                    </strong>
                                </td>
                            </tr>
                            <?php
                            $rg++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>