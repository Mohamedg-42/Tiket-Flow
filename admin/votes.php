<?php
// ==============================================================================
// GESTION & SUPERVISION DES VOTES ET CONCOURS (admin/votes.php)
// Design Dashboard Pro - Contrôle centralisé de tous les votes et concours de la plateforme
// ==============================================================================

$admin_page_title = "Gestion des Votes & Concours - Administration";
include 'header.php';

$message = "";
$msg_type = "";
$active_modal_event_id = 0;
$active_modal_tab = 'config';

// 1. Traitement des actions Administrateur (Modération du vote, activation/clôture, modification & gestion des candidats)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_vote_admin'])) {
    $ev_id = (int)($_POST['event_id'] ?? 0);
    $action_vote = $_POST['action_vote_admin'];

    if ($action_vote === 'cloturer') {
        $stmt = $pdo->prepare("UPDATE events SET type_vote = 'ferme' WHERE id = ?");
        $stmt->execute([$ev_id]);
        $message = "Le vote pour cet événement a été clôturé avec succès.";
        $msg_type = "success";
    } elseif ($action_vote === 'reactiver') {
        $nv_type = $_POST['nouveau_type_vote'] ?? 'concours';
        $stmt = $pdo->prepare("UPDATE events SET type_vote = ? WHERE id = ?");
        $stmt->execute([$nv_type, $ev_id]);
        $message = "Le vote pour cet événement a été réactivé.";
        $msg_type = "success";
    } elseif ($action_vote === 'modifier') {
        $nom = trim($_POST['nom'] ?? '');
        $vote_question = trim($_POST['vote_question'] ?? '');
        $type_vote = trim($_POST['type_vote'] ?? 'concours');
        $prix_vote = max(0, (float)($_POST['prix_vote'] ?? 0));
        $date_evenement = $_POST['date_evenement'] ?? '';
        $lieu = trim($_POST['lieu'] ?? '');
        $statut = trim($_POST['statut'] ?? 'actif');

        $stmt = $pdo->prepare("
            UPDATE events 
            SET nom = ?, vote_question = ?, type_vote = ?, prix_vote = ?, date_evenement = ?, lieu = ?, statut = ?
            WHERE id = ?
        ");
        $stmt->execute([$nom, $vote_question ?: null, $type_vote, $prix_vote, $date_evenement, $lieu, $statut, $ev_id]);
        $message = "La configuration du concours et du système de vote a été mise à jour avec succès.";
        $msg_type = "success";
        $active_modal_event_id = $ev_id;
        $active_modal_tab = 'config';
    } elseif ($action_vote === 'ajouter_candidat') {
        $c_nom = trim($_POST['candidat_nom'] ?? '');
        $c_desc = trim($_POST['candidat_desc'] ?? '');
        $photo_name = null;

        if (isset($_FILES['candidat_photo']) && $_FILES['candidat_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['candidat_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowed, true)) {
                $upload_dir = '../uploads/candidats/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $photo_name = 'candidat_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['candidat_photo']['tmp_name'], $upload_dir . $photo_name);
            }
        } elseif (!empty($_POST['candidat_photo_url'])) {
            $photo_name = trim($_POST['candidat_photo_url']);
        }

        if (!empty($c_nom) && $ev_id > 0) {
            $stmt_ins = $pdo->prepare("
                INSERT INTO event_candidats (event_id, nom, description, photo, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt_ins->execute([$ev_id, $c_nom, $c_desc ?: null, $photo_name]);
            $message = "Le candidat « " . htmlspecialchars($c_nom) . " » a été ajouté avec succès au concours !";
            $msg_type = "success";
            $active_modal_event_id = $ev_id;
            $active_modal_tab = 'candidats';
        } else {
            $message = "Veuillez indiquer au minimum le nom du candidat.";
            $msg_type = "error";
            $active_modal_event_id = $ev_id;
            $active_modal_tab = 'candidats';
        }
    } elseif ($action_vote === 'modifier_candidat') {
        $cand_id = (int)($_POST['candidat_id'] ?? 0);
        $c_nom = trim($_POST['candidat_nom'] ?? '');
        $c_desc = trim($_POST['candidat_desc'] ?? '');

        $stmt_cur = $pdo->prepare("SELECT photo FROM event_candidats WHERE id = ?");
        $stmt_cur->execute([$cand_id]);
        $photo_name = $stmt_cur->fetchColumn();

        if (isset($_FILES['candidat_photo']) && $_FILES['candidat_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['candidat_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowed, true)) {
                $upload_dir = '../uploads/candidats/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $photo_name = 'candidat_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['candidat_photo']['tmp_name'], $upload_dir . $photo_name);
            }
        } elseif (!empty($_POST['candidat_photo_url'])) {
            $photo_name = trim($_POST['candidat_photo_url']);
        }

        if ($cand_id > 0 && !empty($c_nom)) {
            $stmt_upd = $pdo->prepare("
                UPDATE event_candidats 
                SET nom = ?, description = ?, photo = ? 
                WHERE id = ?
            ");
            $stmt_upd->execute([$c_nom, $c_desc ?: null, $photo_name, $cand_id]);
            $message = "Le candidat « " . htmlspecialchars($c_nom) . " » a été mis à jour avec succès.";
            $msg_type = "success";
            $active_modal_event_id = $ev_id;
            $active_modal_tab = 'candidats';
        }
    } elseif ($action_vote === 'supprimer_candidat') {
        $cand_id = (int)($_POST['candidat_id'] ?? 0);
        if ($cand_id > 0) {
            $stmt_n = $pdo->prepare("SELECT nom FROM event_candidats WHERE id = ?");
            $stmt_n->execute([$cand_id]);
            $c_nom = $stmt_n->fetchColumn();

            $stmt_del = $pdo->prepare("DELETE FROM event_candidats WHERE id = ?");
            $stmt_del->execute([$cand_id]);
            $message = "Le candidat « " . htmlspecialchars($c_nom ?: 'sélectionné') . " » a été retiré du concours.";
            $msg_type = "success";
            $active_modal_event_id = $ev_id;
            $active_modal_tab = 'candidats';
        }
    }
}

// 2. Filtres avancés
$type_filter   = $_GET['type'] ?? 'tous';
$statut_filter = $_GET['statut'] ?? 'tous';
$periode       = $_GET['periode'] ?? 'tous';
$search        = trim($_GET['q'] ?? '');

$sql = "
    SELECT e.id, e.nom, e.categorie, e.image, e.date_evenement, e.lieu, e.prix_vote, e.type_vote, e.vote_question, e.statut as event_statut,
           u.nom as promoteur_nom, u.email as promoteur_email, p.nom_commercial,
           (SELECT COUNT(*) FROM event_votes v WHERE v.event_id = e.id) as total_votes,
           (SELECT COUNT(*) FROM event_likes l WHERE l.event_id = e.id) as total_likes,
           (SELECT COALESCE(SUM(vp.montant), 0) FROM vote_paiements vp WHERE vp.event_id = e.id AND vp.statut = 'paye') as total_recette_votes,
           (SELECT COUNT(*) FROM event_candidats c WHERE c.event_id = e.id) as nb_candidats
    FROM events e
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN promoters p ON u.id = p.user_id
    WHERE (e.type_vote IS NOT NULL AND e.type_vote != 'aucun' AND e.type_vote != '')
";
$params = [];

if ($type_filter === 'concours') {
    $sql .= " AND e.type_vote = 'concours'";
} elseif ($type_filter === 'realisation') {
    $sql .= " AND e.type_vote = 'realisation'";
} elseif ($type_filter === 'payant') {
    $sql .= " AND e.prix_vote > 0";
} elseif ($type_filter === 'gratuit') {
    $sql .= " AND (e.prix_vote = 0 OR e.prix_vote IS NULL)";
}

if ($statut_filter === 'actif') {
    $sql .= " AND e.type_vote != 'ferme' AND e.statut = 'actif'";
} elseif ($statut_filter === 'termine') {
    $sql .= " AND (e.type_vote = 'ferme' OR e.statut = 'termine')";
}

if ($periode === '7j') {
    $sql .= " AND e.date_evenement >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($periode === '30j') {
    $sql .= " AND e.date_evenement >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($periode === 'futur') {
    $sql .= " AND e.date_evenement >= CURDATE()";
}

if (!empty($search)) {
    $sql .= " AND (e.nom LIKE ? OR u.nom LIKE ? OR p.nom_commercial LIKE ? OR e.vote_question LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY total_votes DESC, total_likes DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$votes_events = $stmt->fetchAll();

// Pré-chargement optimisé de tous les candidats pour les événements listés
$candidats_by_event = [];
if (!empty($votes_events)) {
    $eids = array_map(function($ev) { return (int)$ev['id']; }, $votes_events);
    $in_q = implode(',', array_fill(0, count($eids), '?'));
    $stmt_c = $pdo->prepare("
        SELECT c.id, c.event_id, c.nom, c.description, c.photo, c.created_at,
               (SELECT COUNT(*) FROM event_votes ev WHERE ev.candidat_id = c.id) as nb_votes,
               (SELECT COALESCE(SUM(vp.montant), 0) FROM vote_paiements vp WHERE vp.candidat_id = c.id AND vp.statut = 'paye') as recettes_candidat
        FROM event_candidats c
        WHERE c.event_id IN ($in_q)
        ORDER BY nb_votes DESC, c.id ASC
    ");
    $stmt_c->execute($eids);
    $all_cands = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_cands as $cand) {
        $candidats_by_event[(int)$cand['event_id']][] = $cand;
    }
}

foreach ($votes_events as &$ve_item) {
    $ve_id = (int)$ve_item['id'];
    $ve_item['candidats'] = $candidats_by_event[$ve_id] ?? [];
}
unset($ve_item);

// KPIs globaux
$global_votes = (int)$pdo->query("SELECT COUNT(*) FROM event_votes")->fetchColumn();
$global_likes = (int)$pdo->query("SELECT COUNT(*) FROM event_likes")->fetchColumn();
$global_ca_votes = (float)$pdo->query("SELECT COALESCE(SUM(montant), 0) FROM vote_paiements WHERE statut = 'paye'")->fetchColumn();
$nb_concours_actifs = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE type_vote IN ('concours', 'realisation') AND statut = 'actif'")->fetchColumn();
?>
<style>
/* ==============================================================================
   RESPONSIVE DESIGN & CADRAGE SUISSE : SUPERVISION DES VOTES & CONCOURS
   ============================================================================== */
.dash-container {
    padding: clamp(0.85rem, 2.5vw, 1.75rem);
    max-width: 100%;
    box-sizing: border-box;
}

.votes-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.votes-header .dash-title-box h1 {
    font-size: clamp(1.25rem, 3.2vw, 1.75rem);
    font-weight: 800;
}
.votes-header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}

/* 2. Barre de filtres unifiée avec onglets défilables */
.votes-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
    background: #ffffff;
    padding: 0.65rem 0.85rem;
    border-radius: 12px;
    border: 1px solid var(--dash-border, #E5E5E5);
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    flex-wrap: wrap;
}
.votes-pills-nav {
    display: flex;
    gap: 0.35rem;
    align-items: center;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 2px;
}
.votes-pills-nav::-webkit-scrollbar {
    display: none;
}
.votes-pill {
    text-decoration: none;
    border-radius: 9px;
    padding: 0.42rem 0.85rem;
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.votes-search-form {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    margin: 0;
    flex-wrap: wrap;
}

/* 3. KPIs de supervision */
.votes-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: clamp(0.6rem, 1.8vw, 1rem);
    margin-bottom: 1.5rem;
}
.votes-kpis .eventia-kpi-card {
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

/* 4. Tableau & Protections anti-saut de ligne */
.votes-table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box;
}
.dash-table.votes-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.85rem;
}
.dash-table.votes-table th {
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
.dash-table.votes-table td {
    padding: 0.85rem 0.85rem;
    border-bottom: 1px solid #F5F5F5;
    vertical-align: middle;
}

.cell-tarif,
.cell-vote-price {
    font-size: 0.85rem !important;
    font-weight: 800 !important;
    white-space: nowrap !important;
    word-break: keep-all !important;
    font-variant-numeric: tabular-nums !important;
    display: inline-flex !important;
    align-items: center;
    gap: 4px;
}
.cell-amount {
    white-space: nowrap !important;
    word-break: keep-all !important;
    font-variant-numeric: tabular-nums !important;
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

@media (min-width: 861px) {
    .dash-table.votes-table {
        min-width: 960px !important;
    }
    .card-top-only {
        display: none !important;
    }
}

@media (max-width: 1150px) {
    .votes-kpis {
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
    .votes-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
    }
    .votes-header-actions {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 0.45rem !important;
    }
    .votes-header-actions .dash-btn-action,
    .votes-header-actions a,
    .votes-header-actions button {
        width: 100% !important;
        justify-content: center !important;
        text-align: center !important;
        box-sizing: border-box !important;
    }
    .votes-filter-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
        padding: 0.65rem !important;
    }
    .votes-pills-nav {
        width: 100% !important;
    }
    .votes-search-form {
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.45rem !important;
    }
    .votes-search-form select,
    .votes-search-form input[type="text"],
    .votes-search-form button,
    .votes-search-form a {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        justify-content: center !important;
    }
    .votes-kpis {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.50rem !important;
        margin-bottom: 1rem !important;
    }
    .votes-kpis .eventia-kpi-card {
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

    .votes-table-wrapper {
        overflow: visible !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }
    .dash-table.votes-table {
        display: block !important;
        min-width: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        border: none !important;
        box-sizing: border-box !important;
    }
    .dash-table.votes-table thead {
        display: none !important;
    }
    .dash-table.votes-table tbody {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.85rem !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    .dash-table.votes-table tr {
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
    .dash-table.votes-table tr:hover td {
        background: transparent !important;
    }
    .dash-table.votes-table td {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        gap: 0.5rem !important;
        padding: 0.50rem 0 !important;
        border-bottom: 1px dashed #E5E5E5 !important;
        width: 100% !important;
        max-width: 100% !important;
        text-align: right !important;
        font-size: 0.82rem !important;
        box-sizing: border-box !important;
    }
    .dash-table.votes-table td::before {
        content: attr(data-label);
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        color: #737373;
        letter-spacing: 0.04em;
        text-align: left;
        flex-shrink: 0;
    }
    .dash-table.votes-table td.card-top {
        display: block !important;
        padding-top: 0 !important;
        padding-bottom: 0.65rem !important;
        border-bottom: 1px solid #E5E5E5 !important;
        text-align: left !important;
    }
    .dash-table.votes-table td.card-top::before {
        display: none !important;
    }
    .dash-table.votes-table td.hide-on-mobile-card {
        display: none !important;
    }
    .dash-table.votes-table td.card-actions {
        border-bottom: none !important;
        padding-bottom: 0 !important;
        padding-top: 0.65rem !important;
        display: block !important;
    }
    .dash-table.votes-table td.card-actions::before {
        display: none !important;
    }
    .dash-table.votes-table td.card-actions .actions-group {
        display: flex !important;
        width: 100% !important;
        gap: 0.45rem !important;
        align-items: center !important;
    }
    .dash-table.votes-table td.card-actions .actions-group form {
        flex: 1 1 0 !important;
    }
    .dash-table.votes-table td.card-actions .actions-group .btn-edit {
        flex: 1 1 0 !important;
        justify-content: center !important;
    }
    .dash-table.votes-table td.card-actions .actions-group button,
    .dash-table.votes-table td.card-actions .actions-group a {
        justify-content: center !important;
        padding: 0.52rem 0.65rem !important;
        box-sizing: border-box !important;
    }
}

@media (max-width: 420px) {
    .votes-kpis {
        grid-template-columns: 1fr !important;
    }
}

.actions-group {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    justify-content: flex-end;
}
.actions-group .btn-edit {
    cursor: pointer;
    border: 1px solid var(--dash-border, #E5E5E5);
    background: #F5F5F5;
    color: var(--dash-text, #000000);
    font-weight: 700;
    font-size: 0.78rem;
    padding: 0.38rem 0.75rem;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s ease;
}
.actions-group .btn-edit:hover {
    background: #E5E5E5;
}

/* ==============================================================================
   MODALE DE MODIFICATION SUISSE
   ============================================================================== */
.dash-modal {
    position: fixed;
    inset: 0;
    z-index: 99999 !important;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: clamp(0.5rem, 2vw, 1.25rem);
    box-sizing: border-box;
}
.dash-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
}
.dash-modal-dialog {
    position: relative;
    background: #ffffff;
    border-radius: 14px;
    width: 100%;
    max-width: 680px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    overflow: hidden;
    animation: modalIn 0.22s ease-out;
    z-index: 1;
    max-height: 90vh;
    max-height: 90dvh;
    display: flex;
    flex-direction: column;
    margin: auto;
}
@keyframes modalIn {
    from {
        opacity: 0;
        transform: translateY(-16px) scale(0.97);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
.dash-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.35rem;
    border-bottom: 1px solid var(--dash-border, #E5E5E5);
    background: #ffffff;
    flex-shrink: 0;
}
.dash-modal-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--dash-text, #000000);
    display: flex;
    align-items: center;
    gap: 8px;
}
.dash-modal-close {
    background: transparent;
    border: none;
    font-size: 1.5rem;
    line-height: 1;
    color: var(--dash-muted, #737373);
    cursor: pointer;
    padding: 0.2rem;
    border-radius: 6px;
    transition: color 0.15s ease;
}
.dash-modal-close:hover {
    color: #000000;
}
.dash-modal-body {
    padding: 1.25rem 1.35rem;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.dash-modal-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.75rem;
    padding: 0.9rem 1.35rem;
    background: #F5F5F5;
    border-top: 1px solid var(--dash-border, #E5E5E5);
    flex-shrink: 0;
}
.form-field-label {
    display: block;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--dash-text, #000000);
    margin-bottom: 0.35rem;
}
.form-field-input, .form-field-select, .form-field-textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 0.6rem 0.85rem;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 8px;
    font-size: 0.85rem;
    background: #ffffff;
    color: var(--dash-text, #000000);
    outline: none;
    transition: border-color 0.15s ease;
}
.form-field-input:focus, .form-field-select:focus, .form-field-textarea:focus {
    border-color: var(--dash-primary, #FF4A0D);
}

/* Onglets de la modale de vote et concours */
.vote-modal-tabs {
    display: flex;
    background: #F5F5F5;
    border-bottom: 1px solid var(--dash-border, #E5E5E5);
    padding: 0 1.25rem;
    gap: 0.35rem;
    overflow-x: auto;
    flex-shrink: 0;
}
.vote-modal-tab-btn {
    padding: 0.75rem 0.95rem;
    border: none;
    background: transparent;
    font-size: 0.84rem;
    font-weight: 700;
    color: var(--dash-muted, #737373);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
    white-space: nowrap;
}
.vote-modal-tab-btn:hover {
    color: var(--dash-text, #000000);
}
.vote-modal-tab-btn.active {
    color: #FF4A0D;
    border-bottom-color: #FF4A0D;
    background: #ffffff;
}

/* Gestion des candidats dans la modale */
.cand-list {
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}
.cand-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.65rem 0.85rem;
    background: #ffffff;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 10px;
    transition: all 0.15s ease;
}
.cand-card:hover {
    border-color: #E5E5E5;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.03);
}
.cand-avatar {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    object-fit: cover;
    background: #F5F5F5;
    flex-shrink: 0;
    border: 1px solid #E5E5E5;
}
.cand-meta {
    flex: 1 1 auto;
    min-width: 0;
}
.cand-name {
    font-size: 0.88rem;
    font-weight: 800;
    color: var(--dash-text, #000000);
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.cand-desc {
    font-size: 0.75rem;
    color: var(--dash-muted, #737373);
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    margin-top: 2px;
}
.cand-score {
    text-align: right;
    flex-shrink: 0;
}
.cand-score-count {
    font-size: 0.88rem;
    font-weight: 800;
    color: #FF4A0D;
    display: block;
}
.cand-actions {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-shrink: 0;
}
.cand-form-box {
    background: #F5F5F5;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 0.75rem;
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO RESPONSIVE
         ============================================================================== -->
    <div class="votes-header">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-ranking-star" style="color: #FF4A0D; font-size: 1.55rem;"></i>
                Supervision des Votes & Concours
            </h1>
            <p>Contrôle global des suffrages, des compétitions de candidats et des recettes de votes payants.</p>
        </div>

        <div class="votes-header-actions">
            <a href="export.php?type=votes&type_filter=<?php echo urlencode($type_filter); ?>&statut=<?php echo urlencode($statut_filter); ?>&periode=<?php echo urlencode($periode); ?>&q=<?php echo urlencode($search); ?>" class="dash-btn-action" style="padding: 0.6rem 1.15rem; text-decoration: none;" title="Exporter les concours, votes et recettes sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <button type="button" class="dash-btn-action" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Imprimer Rapport
            </button>
            <a href="evenements.php" class="dash-btn-action btn-primary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-calendar-days"></i> Voir les Événements
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES MULTI-CRITÈRES EN HAUT (AU-DESSUS DES KPIS)
         ============================================================================== -->
    <div class="votes-filter-bar">
        <!-- À GAUCHE : ONGLETS TYPOLOGIE DÉFILABLES -->
        <div class="votes-pills-nav">
            <a href="?type=tous&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" class="votes-pill" style="<?php echo $type_filter === 'tous' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-layer-group" style="<?php echo $type_filter === 'tous' ? 'color: #FF4A0D;' : ''; ?>"></i> Tous
            </a>

            <a href="?type=concours&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" class="votes-pill" style="<?php echo $type_filter === 'concours' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-trophy" style="color: #FF4A0D;"></i> Concours
            </a>

            <a href="?type=realisation&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" class="votes-pill" style="<?php echo $type_filter === 'realisation' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-star" style="color: #FF4A0D;"></i> Réalisations
            </a>

            <a href="?type=payant&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" class="votes-pill" style="<?php echo $type_filter === 'payant' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-coins" style="color: #FF4A0D;"></i> Votes Payants
            </a>

            <a href="?type=gratuit&statut=<?php echo $statut_filter; ?>&periode=<?php echo $periode; ?>&q=<?php echo urlencode($search); ?>" class="votes-pill" style="<?php echo $type_filter === 'gratuit' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-gift" style="color: var(--eventia-turquoise, #FF4A0D);"></i> Gratuits
            </a>
        </div>

        <!-- À DROITE : SÉLECTEURS & RECHERCHE -->
        <form method="GET" action="votes.php" class="votes-search-form">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">

            <select name="statut" onchange="this.form.submit()" style="padding: 0.42rem 0.65rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer;">
                <option value="tous" <?php echo $statut_filter === 'tous' ? 'selected' : ''; ?>>Tous statuts</option>
                <option value="actif" <?php echo $statut_filter === 'actif' ? 'selected' : ''; ?>>En cours</option>
                <option value="termine" <?php echo $statut_filter === 'termine' ? 'selected' : ''; ?>>Clôturés</option>
            </select>

            <select name="periode" onchange="this.form.submit()" style="padding: 0.42rem 0.65rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer;">
                <option value="tous" <?php echo $periode === 'tous' ? 'selected' : ''; ?>>Toute période</option>
                <option value="7j" <?php echo $periode === '7j' ? 'selected' : ''; ?>>7 derniers jours</option>
                <option value="30j" <?php echo $periode === '30j' ? 'selected' : ''; ?>>30 derniers jours</option>
                <option value="futur" <?php echo $periode === 'futur' ? 'selected' : ''; ?>>À venir</option>
            </select>

            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Titre ou promoteur..." style="padding: 0.42rem 0.65rem; border-radius: 8px; border: 1px solid var(--dash-border, #E5E5E5); font-size: 0.82rem; width: 160px; background: #ffffff;">

            <button type="submit" class="dash-btn-action" style="padding: 0.42rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($type_filter !== 'tous' || $statut_filter !== 'tous' || $periode !== 'tous' || $search !== ''): ?>
                <a href="votes.php" style="color: #000000; font-size: 0.78rem; text-decoration: underline;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- BANDEAU ZONE ACTIVE EXPLICITE -->
    <div style="background: rgba(11, 29, 58, 0.03); border: 1px solid var(--dash-border, #E5E5E5); border-radius: 10px; padding: 0.6rem 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; font-size: 0.82rem; color: var(--dash-muted); flex-wrap: wrap; gap: 0.5rem;">
        <div>
            <i class="fa-solid fa-crosshairs" style="color: var(--dash-primary); margin-right: 6px;"></i>
            <strong>Zone Active :</strong>
            <span>Typologie : <strong style="color: var(--dash-text);"><?php echo ucfirst($type_filter); ?></strong></span> ·
            <span>Statut : <strong style="color: var(--dash-text);"><?php echo ucfirst($statut_filter); ?></strong></span> ·
            <span>Période : <strong style="color: var(--dash-text);"><?php echo ucfirst($periode); ?></strong></span>
        </div>
        <span style="background: rgba(11, 29, 58, 0.08); color: var(--dash-primary); font-weight: 700; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem;">
            <?php echo count($votes_events); ?> événement(s) listé(s)
        </span>
    </div>

    <!-- ==============================================================================
         3. CARTES KPIS DE SUPERVISION RESPONSIVES
         ============================================================================== -->
    <div class="votes-kpis">
        <div class="eventia-kpi-card" style="border-left: 4px solid #FF4A0D;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 800; color: #FF4A0D; text-transform: uppercase; letter-spacing: 0.03em;">Compétitions Actives</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-trophy"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-weight: 800; color: var(--dash-text); line-height: 1.1; margin-bottom: 0.25rem;"><?php echo $nb_concours_actifs; ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Événements ouverts aux votes</small>
        </div>

        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 800; color: var(--dash-muted); text-transform: uppercase; letter-spacing: 0.03em;">Suffrages Exprimés</span>
                <span style="background: #F5F5F5; color: var(--dash-text); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-check-to-slot"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-weight: 800; color: var(--dash-text); line-height: 1.1; margin-bottom: 0.25rem;"><?php echo str_replace(' ', '&nbsp;', number_format($global_votes, 0, ',', ' ')); ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Total des votes enregistrés</small>
        </div>

        <div class="eventia-kpi-card" style="border-left: 4px solid #FF4A0D;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 800; color: #FF4A0D; text-transform: uppercase; letter-spacing: 0.03em;">Recettes Votes Payants</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-coins"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-weight: 800; color: #FF4A0D; line-height: 1.1; margin-bottom: 0.25rem;"><?php echo str_replace(' ', '&nbsp;', number_format($global_ca_votes, 0, ',', ' ')); ?>&nbsp;F</div>
            <small style="color: #FF4A0D; font-size: 0.75rem; font-weight: 600;">Chiffre d'affaires Mobile Money</small>
        </div>

        <div class="eventia-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 800; color: var(--dash-muted); text-transform: uppercase; letter-spacing: 0.03em;">Coups de Cœur (Likes)</span>
                <span style="background: #F5F5F5; color: #000000; width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-heart"></i></span>
            </div>
            <div style="font-size: 1.75rem; font-weight: 800; color: var(--dash-text); line-height: 1.1; margin-bottom: 0.25rem;"><?php echo str_replace(' ', '&nbsp;', number_format($global_likes, 0, ',', ' ')); ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Engagement direct des visiteurs</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DE CONTRÔLE ET D'ARBITRAGE DES VOTES
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Événements avec Système de Vote (<?php echo count($votes_events); ?>)
            </h3>
        </div>

        <?php if (empty($votes_events)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-trophy" style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucun concours ou vote ne correspond aux filtres sélectionnés.
            </div>
        <?php else: ?>
            <div class="dash-table-wrapper votes-table-wrapper">
                <table class="dash-table votes-table">
                    <thead>
                        <tr>
                            <th>Événement</th>
                            <th>Organisateur</th>
                            <th>Typologie</th>
                            <th>Tarification</th>
                            <th>Candidats</th>
                            <th>Suffrages & Likes</th>
                            <th>Recettes</th>
                            <th>Statut du Vote</th>
                            <th style="text-align: right;">Arbitrage Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($votes_events as $ve): ?>
                            <?php
                            $is_closed = ($ve['type_vote'] === 'ferme' || $ve['event_statut'] === 'termine');
                            $type_badge = ($ve['type_vote'] === 'concours') ? ['Concours', '#FFF2ED', '#FF4A0D'] : ['Réalisation', '#FFF2ED', '#FF4A0D'];
                            ?>
                            <tr>
                                <td class="card-top" data-label="Événement">
                                    <div class="card-top-only" style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 0.45rem;">
                                        <span style="background: <?php echo $type_badge[1]; ?>; color: <?php echo $type_badge[2]; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.72rem; white-space: nowrap;">
                                            <?php echo $type_badge[0]; ?>
                                        </span>
                                        <?php if ($is_closed): ?>
                                            <span class="cell-status-badge" style="background: #F5F5F5; color: #737373; padding: 2px 7px; font-size: 0.70rem;">Clos</span>
                                        <?php else: ?>
                                            <span class="cell-status-badge" style="background: #FFF2ED; color: #000000; padding: 2px 7px; font-size: 0.70rem;">En cours</span>
                                        <?php endif; ?>
                                    </div>
                                    <strong style="color: var(--dash-text); font-size: 0.92rem; display: block; line-height: 1.35; margin-bottom: 3px; word-break: break-word;">
                                        <?php echo htmlspecialchars($ve['nom']); ?>
                                    </strong>
                                    <?php if (!empty($ve['vote_question'])): ?>
                                        <div style="font-size: 0.76rem; color: #FF4A0D; margin-bottom: 3px; line-height: 1.3; font-weight: 600;">
                                            <i class="fa-solid fa-circle-question" style="color: #FF4A0D;"></i> « <?php echo htmlspecialchars($ve['vote_question']); ?> »
                                        </div>
                                    <?php endif; ?>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem; display: block; line-height: 1.3;">
                                        <i class="fa-regular fa-calendar" style="color: #737373;"></i> <?php echo date('d/m/Y', strtotime($ve['date_evenement'])); ?> · <?php echo htmlspecialchars($ve['lieu']); ?>
                                    </small>
                                </td>
                                <td data-label="Organisateur">
                                    <div style="text-align: right; max-width: 65%;">
                                        <span style="font-weight: 700; color: var(--dash-text); font-size: 0.84rem; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($ve['nom_commercial'] ?: $ve['promoteur_nom']); ?>
                                        </span>
                                        <small style="color: var(--dash-muted); font-size: 0.74rem; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($ve['promoteur_email']); ?>
                                        </small>
                                    </div>
                                </td>
                                <td data-label="Typologie" class="hide-on-mobile-card" style="white-space: nowrap;">
                                    <span style="background: <?php echo $type_badge[1]; ?>; color: <?php echo $type_badge[2]; ?>; padding: 3px 8px; border-radius: 6px; font-weight: 800; font-size: 0.74rem; white-space: nowrap;">
                                        <?php echo $type_badge[0]; ?>
                                    </span>
                                </td>
                                <td data-label="Tarification" style="white-space: nowrap;">
                                    <?php if ($ve['prix_vote'] > 0): ?>
                                        <span class="cell-tarif" style="color: #FF4A0D; font-weight: 800; font-size: 0.85rem; white-space: nowrap;">
                                            <i class="fa-solid fa-coins"></i>&nbsp;<?php echo str_replace(' ', '&nbsp;', number_format((float)$ve['prix_vote'], 0, ',', ' ')); ?>&nbsp;F<small style="font-weight: 700; color: #FF4A0D;">/vote</small>
                                        </span>
                                    <?php else: ?>
                                        <span class="cell-tarif" style="color: #737373; font-size: 0.80rem; font-weight: 700; white-space: nowrap;">
                                            <i class="fa-solid fa-gift"></i>&nbsp;Gratuit
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Candidats" style="white-space: nowrap;">
                                    <button type="button" onclick="openEditVoteModal(<?php echo (int) $ve['id']; ?>, 'candidats')"
                                        style="background: #F5F5F5; border: 1px solid var(--dash-border, #E5E5E5); border-radius: 6px; padding: 3px 8px; font-weight: 700; font-size: 0.80rem; color: var(--dash-text); cursor: pointer; display: inline-flex; align-items: center; gap: 5px;"
                                        title="Gérer les candidats du concours">
                                        <i class="fa-solid fa-users" style="color: #FF4A0D;"></i> <?php echo (int)$ve['nb_candidats']; ?> candidat(s)
                                    </button>
                                </td>
                                <td data-label="Suffrages & Likes" style="white-space: nowrap;">
                                    <div style="text-align: right; display: flex; align-items: center; gap: 8px; justify-content: flex-end;">
                                        <span style="font-weight: 800; color: #FF4A0D; font-size: 0.88rem; white-space: nowrap;">
                                            <?php echo str_replace(' ', '&nbsp;', number_format((int)$ve['total_votes'], 0, ',', ' ')); ?>&nbsp;votes
                                        </span>
                                        <span style="color: #000000; font-size: 0.76rem; font-weight: 700; white-space: nowrap;">
                                            <i class="fa-solid fa-heart"></i>&nbsp;<?php echo (int)$ve['total_likes']; ?>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Recettes" style="white-space: nowrap;">
                                    <strong class="cell-amount" style="color: #FF4A0D; font-size: 0.92rem; font-weight: 800; white-space: nowrap;">
                                        <?php echo str_replace(' ', '&nbsp;', number_format((float)$ve['total_recette_votes'], 0, ',', ' ')); ?>&nbsp;F
                                    </strong>
                                </td>
                                <td data-label="Statut" class="hide-on-mobile-card" style="white-space: nowrap;">
                                    <?php if ($is_closed): ?>
                                        <span class="cell-status-badge" style="background: #F5F5F5; color: #737373; white-space: nowrap;">
                                            Clos
                                        </span>
                                    <?php else: ?>
                                        <span class="cell-status-badge" style="background: #FFF2ED; color: #000000; white-space: nowrap;">
                                            En cours
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="card-actions" data-label="Arbitrage">
                                    <div class="actions-group">
                                        <button type="button" class="dash-btn-action btn-edit"
                                            onclick="openEditVoteModal(<?php echo (int) $ve['id']; ?>, 'config')"
                                            title="Modifier la configuration du vote ou du concours">
                                            <i class="fa-solid fa-pen"></i> <span>Modifier</span>
                                        </button>

                                        <?php if (!$is_closed): ?>
                                            <form method="POST" onsubmit="return confirm('Voulez-vous clôturer immédiatement ce vote ? Les utilisateurs ne pourront plus voter.');" style="margin: 0; flex: 1 1 auto;">
                                                <input type="hidden" name="event_id" value="<?php echo $ve['id']; ?>">
                                                <input type="hidden" name="action_vote_admin" value="cloturer">
                                                <button type="submit" class="dash-btn-action" style="width: 100%; justify-content: center; background: #F5F5F5; color: #000000; border: 1px solid #E5E5E5; border-radius: 8px; font-weight: 700; font-size: 0.78rem;" title="Clôturer le vote">
                                                    <i class="fa-solid fa-lock"></i> <span>Clôturer</span>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="margin: 0; flex: 1 1 auto;">
                                                <input type="hidden" name="event_id" value="<?php echo $ve['id']; ?>">
                                                <input type="hidden" name="action_vote_admin" value="reactiver">
                                                <input type="hidden" name="nouveau_type_vote" value="concours">
                                                <button type="submit" class="dash-btn-action" style="width: 100%; justify-content: center; background: #FFF2ED; color: #000000; border: 1px solid #FFF2ED; border-radius: 8px; font-weight: 700; font-size: 0.78rem;" title="Réactiver le vote">
                                                    <i class="fa-solid fa-unlock"></i> <span>Réactiver</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <a href="../client/vote-event.php?id=<?php echo (int)$ve['id']; ?>" target="_blank" class="dash-btn-action" style="border-radius: 8px; justify-content: center; background: #F5F5F5; border: 1px solid #E5E5E5; color: var(--dash-text); flex-shrink: 0;" title="Voir la page de vote publique">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                        </a>
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

<!-- ==============================================================================
     MODALE DE MODIFICATION DE VOTE & CONCOURS (STANDARDS SUISSE & GESTION CANDIDATS)
     ============================================================================== -->
<div id="editVoteModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeEditVoteModal()"></div>
    <div class="dash-modal-dialog">
        <div class="dash-modal-header">
            <h3>
                <i class="fa-solid fa-ranking-star" style="color: #FF4A0D;"></i>
                <span id="modal_event_title">Modifier le Concours & Vote</span>
            </h3>
            <button type="button" class="dash-modal-close" onclick="closeEditVoteModal()">&times;</button>
        </div>

        <!-- Onglets Suisses -->
        <div class="vote-modal-tabs">
            <button type="button" class="vote-modal-tab-btn active" id="tabBtnConfig" onclick="switchVoteModalTab('config')">
                <i class="fa-solid fa-sliders"></i> Configuration du Concours
            </button>
            <button type="button" class="vote-modal-tab-btn" id="tabBtnCandidats" onclick="switchVoteModalTab('candidats')">
                <i class="fa-solid fa-users"></i> Candidats (<span id="tabCountCands">0</span>)
            </button>
        </div>

        <div class="dash-modal-body">
            <!-- ONGLET 1 : CONFIGURATION DU VOTE & CONCOURS -->
            <div id="paneVoteConfig" style="display: flex; flex-direction: column; gap: 0.9rem;">
                <form method="POST" action="votes.php" id="formVoteConfig">
                    <input type="hidden" name="action_vote_admin" value="modifier">
                    <input type="hidden" name="event_id" id="edit_vote_event_id">

                    <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                        <!-- Nom de l'événement -->
                        <div>
                            <label class="form-field-label">Nom de l'événement / Concours *</label>
                            <input type="text" name="nom" id="edit_vote_nom" class="form-field-input" required placeholder="Ex: Festival des Voix d'Or 2026">
                        </div>

                        <!-- Question ou Thème du Vote -->
                        <div>
                            <label class="form-field-label">
                                <i class="fa-solid fa-circle-question" style="color: #FF4A0D;"></i> Question ou Intitulé du Vote
                            </label>
                            <input type="text" name="vote_question" id="edit_vote_question" class="form-field-input" placeholder="Ex: Qui mérite le trophée de la Révélation de l'Année ?">
                            <small style="color: var(--dash-muted); font-size: 0.74rem;">Cette question s'affiche sur la page de vote publique pour orienter les votants.</small>
                        </div>

                        <!-- Système de vote & Tarif du vote -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem;">
                            <div>
                                <label class="form-field-label">Système de vote *</label>
                                <select name="type_vote" id="edit_vote_type" class="form-field-select" required onchange="onTypeVoteChange(this.value)">
                                    <option value="concours">Concours (avec candidats en compétition)</option>
                                    <option value="realisation">Réalisation directe (appréciation globale)</option>
                                    <option value="ferme">Vote clôturé (fermé aux votes)</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-field-label">Prix du vote (FCFA) *</label>
                                <input type="number" name="prix_vote" id="edit_vote_prix" min="0" step="50" class="form-field-input" required placeholder="0 = Gratuit">
                                <small style="color: var(--dash-muted); font-size: 0.74rem;">Indiquez 0 pour un vote gratuit.</small>
                            </div>
                        </div>

                        <!-- Date & Lieu -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem;">
                            <div>
                                <label class="form-field-label">Date de l'événement *</label>
                                <input type="date" name="date_evenement" id="edit_vote_date" class="form-field-input" required>
                            </div>
                            <div>
                                <label class="form-field-label">Lieu & Salle *</label>
                                <input type="text" name="lieu" id="edit_vote_lieu" class="form-field-input" required placeholder="Ex: Palais de la Culture, Treichville, Abidjan">
                            </div>
                        </div>

                        <!-- Statut de l'événement -->
                        <div>
                            <label class="form-field-label">Statut de diffusion de l'événement *</label>
                            <select name="statut" id="edit_vote_statut" class="form-field-select" required>
                                <option value="actif">Actif (En ligne & visible)</option>
                                <option value="termine">Terminé (Clos)</option>
                                <option value="annule">Annulé</option>
                            </select>
                        </div>

                        <!-- Lien direct vers la fiche complète -->
                        <div style="background: #F5F5F5; border: 1px solid var(--dash-border, #E5E5E5); border-radius: 10px; padding: 0.85rem; font-size: 0.82rem; color: var(--dash-muted, #737373); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                            <span>Pour modifier l'affiche générale ou la billetterie :</span>
                            <a id="edit_vote_full_link" href="#" class="dash-btn-action" style="font-size: 0.78rem; text-decoration: none; padding: 0.4rem 0.8rem;">
                                <i class="fa-solid fa-sliders"></i> Modification complète
                            </a>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem; border-top: 1px solid var(--dash-border, #E5E5E5); padding-top: 0.95rem;">
                        <button type="button" class="dash-btn-action" onclick="closeEditVoteModal()" style="cursor: pointer;">Annuler</button>
                        <button type="submit" class="dash-btn-action btn-primary" style="cursor: pointer; padding: 0.6rem 1.3rem;">
                            <i class="fa-solid fa-check"></i> Enregistrer les Paramètres
                        </button>
                    </div>
                </form>
            </div>

            <!-- ONGLET 2 : GESTION DES CANDIDATS -->
            <div id="paneVoteCandidats" style="display: none; flex-direction: column; gap: 0.85rem;">
                <!-- En-tête candidats -->
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; border-bottom: 1px solid var(--dash-border, #E5E5E5); padding-bottom: 0.75rem;">
                    <div>
                        <strong style="font-size: 0.95rem; color: var(--dash-text); display: block;">Candidats Inscrits</strong>
                        <span id="pane_cands_sub" style="font-size: 0.76rem; color: var(--dash-muted);">Liste des participants en compétition</span>
                    </div>
                    <button type="button" class="dash-btn-action btn-primary" onclick="toggleAddCandidatForm()" id="btnToggleAddCand" style="font-size: 0.78rem; padding: 0.42rem 0.85rem;">
                        <i class="fa-solid fa-plus"></i> <span id="btnToggleAddCandText">Nouveau Candidat</span>
                    </button>
                </div>

                <!-- Formulaire Dépliable d'Ajout / Modification de Candidat -->
                <div id="boxCandidatForm" class="cand-form-box" style="display: none;">
                    <form method="POST" action="votes.php" enctype="multipart/form-data" id="formCandidat">
                        <input type="hidden" name="action_vote_admin" id="candidat_form_action" value="ajouter_candidat">
                        <input type="hidden" name="event_id" id="candidat_form_event_id">
                        <input type="hidden" name="candidat_id" id="candidat_form_candidat_id">

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                            <strong id="candFormTitle" style="font-size: 0.86rem; color: var(--dash-text);">
                                <i class="fa-solid fa-user-plus" style="color: #FF4A0D;"></i> Ajouter un candidat
                            </strong>
                            <button type="button" onclick="closeCandidatForm()" class="dash-modal-close" style="font-size: 1.1rem;">&times;</button>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <div>
                                <label class="form-field-label">Nom complet ou Nom de scène *</label>
                                <input type="text" name="candidat_nom" id="cand_nom_input" class="form-field-input" required placeholder="Ex: Aïcha Traoré - Voix d'Or">
                            </div>

                            <div>
                                <label class="form-field-label">Biographie / Description</label>
                                <textarea name="candidat_desc" id="cand_desc_input" class="form-field-textarea" rows="2" placeholder="Ex: Chanteuse afro-soul et révélation vocale de l'année."></textarea>
                            </div>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                                <div>
                                    <label class="form-field-label">Photo du candidat (Fichier)</label>
                                    <input type="file" name="candidat_photo" id="cand_photo_file" class="form-field-input" accept="image/*">
                                </div>
                                <div>
                                    <label class="form-field-label">Ou URL de l'image</label>
                                    <input type="url" name="candidat_photo_url" id="cand_photo_url" class="form-field-input" placeholder="https://...">
                                </div>
                            </div>

                            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.25rem;">
                                <button type="button" class="dash-btn-action" onclick="closeCandidatForm()" style="font-size: 0.78rem;">Annuler</button>
                                <button type="submit" class="dash-btn-action btn-primary" id="candSubmitBtn" style="font-size: 0.78rem;">
                                    <i class="fa-solid fa-check"></i> Enregistrer le Candidat
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Alerte si le type de vote n'est pas un concours -->
                <div id="noticeNotConcours" style="display: none; background: #FFF2ED; border: 1px solid #FF4A0D; border-radius: 8px; padding: 0.85rem; font-size: 0.82rem; color: #000000;">
                    <i class="fa-solid fa-circle-info"></i> Ce vote est actuellement en mode <strong>« Réalisation directe »</strong> (sans candidats). Pour activer le vote par candidats nominatifs, basculez le système de vote sur « Concours » dans le premier onglet.
                </div>

                <!-- Liste des candidats -->
                <div id="modalCandidatesList" class="cand-list">
                    <!-- Généré dynamiquement en JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Dictionnaire des votes et de leurs candidats pour la modale
const PLATFORM_VOTES = <?php 
    $v_map = [];
    foreach ($votes_events as $item) {
        $v_map[(int)$item['id']] = $item;
    }
    echo json_encode($v_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); 
?>;

let currentActiveEventId = null;

function getCandidatPhotoUrl(photo) {
    if (!photo || photo === '') {
        return '../images/default-avatar.png';
    }
    if (photo.startsWith('http://') || photo.startsWith('https://')) {
        return photo;
    }
    return '../uploads/candidats/' + encodeURIComponent(photo);
}

function switchVoteModalTab(tab) {
    const btnConfig = document.getElementById('tabBtnConfig');
    const btnCands = document.getElementById('tabBtnCandidats');
    const paneConfig = document.getElementById('paneVoteConfig');
    const paneCands = document.getElementById('paneVoteCandidats');

    if (tab === 'candidats') {
        btnConfig.classList.remove('active');
        btnCands.classList.add('active');
        paneConfig.style.display = 'none';
        paneCands.style.display = 'flex';
    } else {
        btnCands.classList.remove('active');
        btnConfig.classList.add('active');
        paneCands.style.display = 'none';
        paneConfig.style.display = 'flex';
    }
}

function onTypeVoteChange(val) {
    const notice = document.getElementById('noticeNotConcours');
    if (notice) {
        notice.style.display = (val === 'concours') ? 'none' : 'block';
    }
}

function toggleAddCandidatForm() {
    const box = document.getElementById('boxCandidatForm');
    const isHidden = (box.style.display === 'none' || box.style.display === '');
    if (isHidden) {
        openAddCandidatForm();
    } else {
        closeCandidatForm();
    }
}

function openAddCandidatForm() {
    const box = document.getElementById('boxCandidatForm');
    const title = document.getElementById('candFormTitle');
    const actionInput = document.getElementById('candidat_form_action');
    const candIdInput = document.getElementById('candidat_form_candidat_id');
    const nomInput = document.getElementById('cand_nom_input');
    const descInput = document.getElementById('cand_desc_input');
    const photoUrlInput = document.getElementById('cand_photo_url');
    const submitBtn = document.getElementById('candSubmitBtn');
    const toggleText = document.getElementById('btnToggleAddCandText');

    actionInput.value = 'ajouter_candidat';
    candIdInput.value = '';
    nomInput.value = '';
    descInput.value = '';
    photoUrlInput.value = '';
    title.innerHTML = '<i class="fa-solid fa-user-plus" style="color: #FF4A0D;"></i> Ajouter un candidat au concours';
    submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> Ajouter au Concours';
    if (toggleText) toggleText.textContent = 'Fermer le formulaire';

    box.style.display = 'block';
    nomInput.focus();
}

function closeCandidatForm() {
    const box = document.getElementById('boxCandidatForm');
    const toggleText = document.getElementById('btnToggleAddCandText');
    box.style.display = 'none';
    if (toggleText) toggleText.textContent = 'Nouveau Candidat';
}

function editCandidatInline(candId) {
    const ev = PLATFORM_VOTES[currentActiveEventId];
    if (!ev || !ev.candidats) return;

    const cand = ev.candidats.find(c => parseInt(c.id, 10) === parseInt(candId, 10));
    if (!cand) return;

    const box = document.getElementById('boxCandidatForm');
    const title = document.getElementById('candFormTitle');
    const actionInput = document.getElementById('candidat_form_action');
    const candIdInput = document.getElementById('candidat_form_candidat_id');
    const nomInput = document.getElementById('cand_nom_input');
    const descInput = document.getElementById('cand_desc_input');
    const photoUrlInput = document.getElementById('cand_photo_url');
    const submitBtn = document.getElementById('candSubmitBtn');
    const toggleText = document.getElementById('btnToggleAddCandText');

    actionInput.value = 'modifier_candidat';
    candIdInput.value = cand.id;
    nomInput.value = cand.nom || '';
    descInput.value = cand.description || '';
    photoUrlInput.value = (cand.photo && (cand.photo.startsWith('http://') || cand.photo.startsWith('https://'))) ? cand.photo : '';
    title.innerHTML = '<i class="fa-solid fa-pen" style="color: #FF4A0D;"></i> Modifier le candidat « ' + escapeHtml(cand.nom) + ' »';
    submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> Mettre à jour le Candidat';
    if (toggleText) toggleText.textContent = 'Fermer le formulaire';

    box.style.display = 'block';
    nomInput.focus();
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderCandidatesList(cands, totalVotesEvent) {
    const container = document.getElementById('modalCandidatesList');
    if (!cands || cands.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 1.5rem 1rem; background: #F5F5F5; border: 1px dashed var(--dash-border, #E5E5E5); border-radius: 10px; color: var(--dash-muted);">
                <i class="fa-solid fa-user-slash" style="font-size: 1.75rem; color: #E5E5E5; margin-bottom: 0.5rem; display: block;"></i>
                Aucun candidat n'est encore inscrit à ce concours.<br>
                <button type="button" onclick="openAddCandidatForm()" class="dash-btn-action" style="margin-top: 0.65rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-plus"></i> Inscrire le premier candidat
                </button>
            </div>
        `;
        return;
    }

    let html = '';
    const totalVotes = parseInt(totalVotesEvent, 10) || 0;

    cands.forEach((c, idx) => {
        const nbVotes = parseInt(c.nb_votes, 10) || 0;
        const pct = totalVotes > 0 ? ((nbVotes / totalVotes) * 100).toFixed(1) : '0.0';
        const photoUrl = getCandidatPhotoUrl(c.photo);
        const rankNum = idx + 1;

        html += `
            <div class="cand-card">
                <span style="font-size: 0.74rem; font-weight: 800; color: #737373; width: 22px; text-align: center;">#${rankNum}</span>
                <img src="${photoUrl}" alt="Photo de ${escapeHtml(c.nom)}" class="cand-avatar" onerror="this.src='../images/default-avatar.png';">
                
                <div class="cand-meta">
                    <strong class="cand-name">${escapeHtml(c.nom)}</strong>
                    ${c.description ? `<small class="cand-desc">${escapeHtml(c.description)}</small>` : ''}
                    <div style="background: #E5E5E5; height: 4px; border-radius: 999px; overflow: hidden; margin-top: 5px; max-width: 220px;">
                        <div style="height: 100%; width: ${pct}%; background: #FF4A0D; border-radius: 999px;"></div>
                    </div>
                </div>

                <div class="cand-score">
                    <span class="cand-score-count">${nbVotes} vote${nbVotes > 1 ? 's' : ''}</span>
                    <small style="font-size: 0.72rem; font-weight: 700; color: #FF4A0D;">${pct}%</small>
                </div>

                <div class="cand-actions">
                    <button type="button" class="dash-btn-action" onclick="editCandidatInline(${c.id})" style="padding: 0.35rem 0.55rem; font-size: 0.75rem;" title="Modifier les infos de ce candidat">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <form method="POST" action="votes.php" onsubmit="return confirm('Voulez-vous vraiment retirer « ${escapeHtml(c.nom)} » de ce concours ?');" style="margin: 0;">
                        <input type="hidden" name="action_vote_admin" value="supprimer_candidat">
                        <input type="hidden" name="event_id" value="${c.event_id}">
                        <input type="hidden" name="candidat_id" value="${c.id}">
                        <button type="submit" class="dash-btn-action" style="padding: 0.35rem 0.55rem; font-size: 0.75rem; color: #000000; background: #F5F5F5; border-color: #E5E5E5;" title="Supprimer ce candidat">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function openEditVoteModal(evId, targetTab = 'config') {
    const ev = PLATFORM_VOTES[parseInt(evId, 10)];
    if (!ev) {
        console.error("Événement de vote introuvable:", evId);
        alert("Impossible de charger les données de cet événement.");
        return;
    }

    currentActiveEventId = parseInt(evId, 10);

    // Titre & ID
    document.getElementById('modal_event_title').textContent = ev.nom || 'Modifier le Concours & Vote';
    document.getElementById('edit_vote_event_id').value = ev.id || '';
    document.getElementById('candidat_form_event_id').value = ev.id || '';

    // Champs de configuration
    document.getElementById('edit_vote_nom').value = ev.nom || '';
    document.getElementById('edit_vote_question').value = ev.vote_question || '';
    document.getElementById('edit_vote_type').value = ev.type_vote || 'concours';
    document.getElementById('edit_vote_prix').value = ev.prix_vote !== null ? ev.prix_vote : 0;
    document.getElementById('edit_vote_date').value = ev.date_evenement ? ev.date_evenement.split(' ')[0] : '';
    document.getElementById('edit_vote_lieu').value = ev.lieu || '';
    document.getElementById('edit_vote_statut').value = ev.event_statut || 'actif';
    
    // Lien vers la modification avancée de l'événement
    document.getElementById('edit_vote_full_link').href = 'modifier-evenement.php?id=' + encodeURIComponent(ev.id);

    // Compteur & Liste des Candidats
    const cands = ev.candidats || [];
    document.getElementById('tabCountCands').textContent = cands.length;
    document.getElementById('pane_cands_sub').textContent = `${cands.length} candidat(s) participant au concours « ${ev.nom} »`;

    // Alerte mode non-concours
    onTypeVoteChange(ev.type_vote);

    // Rendu de la liste des candidats
    closeCandidatForm();
    renderCandidatesList(cands, ev.total_votes);

    // Affichage de la modale
    const modal = document.getElementById('editVoteModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Basculer sur l'onglet demandé
    switchVoteModalTab(targetTab);
}

function closeEditVoteModal() {
    const modal = document.getElementById('editVoteModal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.overflow = '';
    closeCandidatForm();
}

// Fermeture par touche Échap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditVoteModal();
    }
});

<?php if (!empty($active_modal_event_id)): ?>
// Ré-ouverture automatique de la modale après soumission
document.addEventListener('DOMContentLoaded', function() {
    openEditVoteModal(<?php echo (int)$active_modal_event_id; ?>, '<?php echo htmlspecialchars($active_modal_tab); ?>');
});
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>
