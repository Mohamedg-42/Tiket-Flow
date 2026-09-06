<?php
// ==============================================================================
// GESTION & FILTRAGE DES ÉVÉNEMENTS (admin/evenements.php)
// Design Dashboard Pro - Filtres en haut, KPIs dynamiques et supervision globale
// ==============================================================================

$admin_page_title = "Gestion des Événements - Administration";
include 'header.php';

$message = "";
$msg_type = "";

// 1. Action rapide : Changement direct de statut par l'admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $ev_id = (int) $_POST['event_id'];
    $new_status = $_POST['new_status'] ?? 'actif';

    if (in_array($new_status, ['actif', 'termine', 'annule', 'en_attente'], true)) {
        $stmt_status = $pdo->prepare("UPDATE events SET statut = ? WHERE id = ?");
        $stmt_status->execute([$new_status, $ev_id]);
        $message = "Le statut de l'événement a été mis à jour avec succès.";
        $msg_type = "success";
    }
}

// 1.b Action complète : Mise à jour de l'événement via la modale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event'])) {
    $ev_id = (int) ($_POST['event_id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categorie = trim($_POST['categorie'] ?? 'Concert');
    if ($categorie === 'Autre' && !empty($_POST['categorie_custom'])) {
        $categorie = trim($_POST['categorie_custom']);
    }
    $date = $_POST['date_evenement'] ?? '';
    $heure = $_POST['heure'] ?? '';
    $lieu = trim($_POST['lieu'] ?? '');
    $statut = $_POST['statut'] ?? 'actif';
    $type_vote = $_POST['type_vote'] ?? 'aucun';
    $prix_vote = (float) ($_POST['prix_vote'] ?? 0);
    $commission = (float) ($_POST['commission_rate'] ?? 5.0);

    if ($ev_id > 0 && !empty($nom)) {
        $image_sql = "";
        $params = [$nom, $description, $categorie, $date, $heure, $lieu, $type_vote, $prix_vote, $commission, $statut];

        // Gestion de l'upload d'affiche si fournie
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $upload_dir = '../uploads/events/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $new_img = 'event_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $new_img)) {
                    $image_sql = ", image = ?";
                    $params[] = $new_img;
                }
            }
        }

        $params[] = $ev_id;
        $sql_upd = "UPDATE events SET nom = ?, description = ?, categorie = ?, date_evenement = ?, heure = ?, lieu = ?, type_vote = ?, prix_vote = ?, commission_rate = ?, statut = ? $image_sql WHERE id = ?";
        $stmt_upd = $pdo->prepare($sql_upd);
        $stmt_upd->execute($params);

        $message = "L'événement « " . htmlspecialchars($nom) . " » a été modifié avec succès !";
        $msg_type = "success";
    }
}

// 2. Filtres et recherche
$search = trim($_GET['q'] ?? '');
$status_f = trim($_GET['statut'] ?? '');
$category = trim($_GET['categorie'] ?? '');
$period = trim($_GET['periode'] ?? '');

$sql = "
    SELECT e.*, 
           COALESCE(p.nom_commercial, u.nom, 'Administrateur') AS promoter_name,
           (SELECT COALESCE(SUM(tt.quantite_vendue), 0) FROM ticket_types tt WHERE tt.event_id = e.id) AS total_vendus,
           (SELECT COALESCE(SUM(tt.quantite), 0) FROM ticket_types tt WHERE tt.event_id = e.id) AS total_places,
           (SELECT COALESCE(SUM(t.prix), 0) FROM tickets t WHERE t.event_id = e.id AND t.statut != 'annule') AS total_recette
    FROM events e
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN promoters p ON u.id = p.user_id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (e.nom LIKE ? OR e.lieu LIKE ? OR p.nom_commercial LIKE ? OR u.nom LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_f) && in_array($status_f, ['actif', 'termine', 'annule', 'en_attente'], true)) {
    $sql .= " AND e.statut = ?";
    $params[] = $status_f;
}

if (!empty($category)) {
    $sql .= " AND e.categorie = ?";
    $params[] = $category;
}

if ($period === 'a_venir') {
    $sql .= " AND (e.date_evenement > CURDATE() OR (e.date_evenement = CURDATE() AND e.heure >= CURTIME()))";
} elseif ($period === 'passe') {
    $sql .= " AND (e.date_evenement < CURDATE() OR (e.date_evenement = CURDATE() AND e.heure < CURTIME()))";
} elseif ($period === 'aujourdhui') {
    $sql .= " AND e.date_evenement = CURDATE()";
}

$sql .= " ORDER BY e.date_evenement DESC, e.heure DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

// 3. Calcul des compteurs globaux
$total_events = (int) $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
$active_events = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE statut = 'actif'")->fetchColumn();
$ended_events = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE statut = 'termine'")->fetchColumn();
$total_tickets = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE statut != 'annule'")->fetchColumn();

// Catégories disponibles pour le filtre
$categories_list = $pdo->query("SELECT DISTINCT categorie FROM events WHERE categorie IS NOT NULL AND categorie != '' ORDER BY categorie ASC")->fetchAll(PDO::FETCH_COLUMN);
?>

<style>
/* ==============================================================================
   RESPONSIVE DESIGN & CADRAGE SUISSE : GESTION DES ÉVÉNEMENTS (admin/evenements.php)
   ============================================================================== */
.dash-container {
    padding: clamp(0.85rem, 2.5vw, 1.75rem);
    max-width: 100%;
    box-sizing: border-box;
}

.events-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.events-header .dash-title-box h1 {
    font-size: clamp(1.25rem, 3.2vw, 1.75rem);
    font-weight: 800;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0 0 0.35rem 0;
}
.events-header .dash-title-box p {
    color: var(--dash-muted, #737373);
    font-size: 0.88rem;
    margin: 0;
}
.events-header-actions {
    display: flex;
    gap: 0.65rem;
    flex-wrap: wrap;
    align-items: center;
}

/* 2. Barre de filtres unifiée avec onglets défilables */
.events-filter-bar {
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
.events-pills-nav {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 2px;
}
.events-pills-nav::-webkit-scrollbar {
    display: none;
}
.events-pill {
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
.events-search-form {
    display: inline-flex;
    gap: 8px;
    align-items: center;
    margin: 0;
    flex-wrap: wrap;
}

/* 3. KPIs de supervision */
.events-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: clamp(0.6rem, 1.8vw, 1rem);
    margin-bottom: 1.5rem;
}
.events-kpis .dash-kpi-card {
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
.events-table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box;
}
.dash-table.events-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.85rem;
}
.dash-table.events-table th {
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
.dash-table.events-table td {
    padding: 0.85rem 0.85rem;
    border-bottom: 1px solid #F5F5F5;
    vertical-align: middle;
}

.cell-revenue {
    color: #FF4A0D;
    font-size: 0.92rem;
    font-weight: 800;
    white-space: nowrap !important;
    word-break: keep-all !important;
    font-variant-numeric: tabular-nums !important;
}

.mobile-thumb-img {
    display: none;
}
.card-top-flex {
    display: block;
}
.cell-actions-group {
    display: inline-flex;
    gap: 5px;
}
.cell-actions-group a span {
    display: none;
}

@media (min-width: 861px) {
    .dash-table.events-table {
        min-width: 960px !important;
    }
}

@media (max-width: 1150px) {
    .events-kpis {
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
    .events-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
    }
    .events-header-actions {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 0.45rem !important;
    }
    .events-header-actions a,
    .events-header-actions button {
        width: 100% !important;
        justify-content: center !important;
        text-align: center !important;
        box-sizing: border-box !important;
    }
    .events-filter-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
        padding: 0.65rem !important;
    }
    .events-pills-nav {
        width: 100% !important;
    }
    .events-search-form {
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.45rem !important;
    }
    .events-search-form select,
    .events-search-form div,
    .events-search-form input[type="text"],
    .events-search-form button,
    .events-search-form a {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        justify-content: center !important;
        text-align: center !important;
    }
    .events-kpis {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.50rem !important;
        margin-bottom: 1rem !important;
    }
    .events-kpis .dash-kpi-card {
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

    .events-table-wrapper {
        overflow: visible !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }
    .dash-table.events-table {
        display: block !important;
        min-width: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        border: none !important;
        box-sizing: border-box !important;
    }
    .dash-table.events-table thead {
        display: none !important;
    }
    .dash-table.events-table tbody {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.85rem !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    .dash-table.events-table tr {
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
    .dash-table.events-table tr:hover td {
        background: transparent !important;
    }
    .dash-table.events-table td {
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
    .dash-table.events-table td::before {
        content: attr(data-label);
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        color: #737373;
        letter-spacing: 0.04em;
        text-align: left;
        flex-shrink: 0;
    }

    .dash-table.events-table td.hide-on-mobile-card {
        display: none !important;
    }
    .dash-table.events-table td.card-top {
        display: block !important;
        padding-top: 0 !important;
        padding-bottom: 0.65rem !important;
        border-bottom: 1px solid #E5E5E5 !important;
        text-align: left !important;
    }
    .dash-table.events-table td.card-top::before {
        display: none !important;
    }

    .card-top-flex {
        display: flex !important;
        align-items: center !important;
        gap: 0.75rem !important;
    }
    .mobile-thumb-img {
        display: block !important;
        width: 52px !important;
        height: 52px !important;
        border-radius: 8px !important;
        object-fit: cover !important;
        border: 1px solid #E5E5E5 !important;
        flex-shrink: 0 !important;
    }
    .card-top-content {
        flex: 1 1 auto;
        min-width: 0;
    }
    .event-title {
        font-size: 0.95rem !important;
        font-weight: 800 !important;
        color: var(--dash-text, #000000) !important;
        line-height: 1.3 !important;
        display: block !important;
        margin-bottom: 4px !important;
    }
    .event-cat-badge {
        font-size: 0.72rem !important;
        font-weight: 700 !important;
        color: #FF4A0D !important;
        background: #FFF2ED !important;
        padding: 2px 7px !important;
        border-radius: 6px !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 4px !important;
    }

    .dash-table.events-table td.card-actions {
        border-bottom: none !important;
        padding-bottom: 0 !important;
        padding-top: 0.65rem !important;
        display: block !important;
    }
    .dash-table.events-table td.card-actions::before {
        display: none !important;
    }
    .dash-table.events-table td.card-actions .cell-actions-group {
        display: flex !important;
        width: 100% !important;
        gap: 0.45rem !important;
        align-items: center !important;
    }
    .dash-table.events-table td.card-actions .cell-actions-group a,
    .dash-table.events-table td.card-actions .cell-actions-group button {
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
        background: #ffffff !important;
        cursor: pointer !important;
    }
    .dash-table.events-table td.card-actions .cell-actions-group a span,
    .dash-table.events-table td.card-actions .cell-actions-group button span {
        display: inline !important;
        font-weight: 700 !important;
    }
}

@media (max-width: 420px) {
    .events-kpis {
        grid-template-columns: 1fr !important;
    }
}

/* ==============================================================================
   STYLES DE LA MODALE D'ÉDITION D'ÉVÉNEMENT (CONFORME AUX STANDARDS DU SITE)
   ============================================================================== */
.dash-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
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
    max-width: 720px;
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

.dash-modal-body::-webkit-scrollbar {
    width: 6px;
}
.dash-modal-body::-webkit-scrollbar-track {
    background: #F5F5F5;
}
.dash-modal-body::-webkit-scrollbar-thumb {
    background: #E5E5E5;
    border-radius: 999px;
}
.dash-modal-body::-webkit-scrollbar-thumb:hover {
    background: #737373;
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
    position: sticky;
    bottom: 0;
    z-index: 10;
}

.form-field-label {
    display: block;
    font-size: 0.82rem;
    font-weight: 700;
    margin-bottom: 4px;
    color: var(--dash-text, #000000);
}

.form-field-input,
.form-field-select,
.form-field-textarea {
    width: 100%;
    padding: 0.58rem 0.85rem;
    border: 1px solid var(--dash-border, #E5E5E5);
    border-radius: 8px;
    font-size: 0.85rem;
    font-family: inherit;
    box-sizing: border-box;
    background: #ffffff;
    color: var(--dash-text, #000000);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.form-field-input:focus,
.form-field-select:focus,
.form-field-textarea:focus {
    outline: none;
    border-color: var(--dash-primary, #FF4A0D);
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="events-header">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-calendar-days" style="color: var(--dash-primary);"></i>
                Gestion des Événements
            </h1>
            <p>Supervisez, filtrez et gérez l'ensemble des événements et spectacles de la plateforme.</p>
        </div>

        <div class="events-header-actions">
            <a href="export.php?type=evenements" class="dash-btn-action"
                style="padding: 0.6rem 1.15rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;" title="Exporter tous les événements sur Excel">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <a href="creer-evenement.php" class="dash-btn-action btn-primary"
                style="padding: 0.6rem 1.2rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <i class="fa-solid fa-plus"></i> Créer un Événement
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
         2. BARRE DE FILTRES MULTI-CRITÈRES EN HAUT
         ============================================================================== -->
    <div class="events-filter-bar">
        <!-- À GAUCHE : PILULES STATUTS -->
        <div class="events-pills-nav">
            <a class="events-pill" href="?statut=&categorie=<?php echo urlencode($category); ?>&periode=<?php echo $period; ?>&q=<?php echo urlencode($search); ?>"
                style="<?php echo $status_f === '' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-list" style="<?php echo $status_f === '' ? 'color: #FF4A0D;' : ''; ?>"></i> Tous
                (<?php echo $total_events; ?>)
            </a>

            <a class="events-pill" href="?statut=actif&categorie=<?php echo urlencode($category); ?>&periode=<?php echo $period; ?>&q=<?php echo urlencode($search); ?>"
                style="<?php echo $status_f === 'actif' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-bolt" style="color: #FF4A0D;"></i> Actifs (<?php echo $active_events; ?>)
            </a>

            <a class="events-pill" href="?statut=termine&categorie=<?php echo urlencode($category); ?>&periode=<?php echo $period; ?>&q=<?php echo urlencode($search); ?>"
                style="<?php echo $status_f === 'termine' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-flag-checkered" style="color: #737373;"></i> Terminés
                (<?php echo $ended_events; ?>)
            </a>

            <a class="events-pill" href="?statut=en_attente&categorie=<?php echo urlencode($category); ?>&periode=<?php echo $period; ?>&q=<?php echo urlencode($search); ?>"
                style="<?php echo $status_f === 'en_attente' ? 'background: #000000; color: #ffffff; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);' : 'background: #F5F5F5; color: #737373; border: 1px solid #E5E5E5;'; ?>">
                <i class="fa-solid fa-clock" style="color: #FF4A0D;"></i> En attente
            </a>
        </div>

        <!-- À DROITE : SÉLECTEURS CATÉGORIE, PÉRIODE & RECHERCHE -->
        <form class="events-search-form" method="GET" action="evenements.php">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($status_f); ?>">

            <!-- Sélecteur Catégorie -->
            <select name="categorie" onchange="this.form.submit()"
                style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer; max-width: 160px;">
                <option value="">Toutes catégories</option>
                <?php foreach ($categories_list as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($category === $cat) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Sélecteur Période -->
            <select name="periode" onchange="this.form.submit()"
                style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; font-weight: 700; background: #ffffff; color: var(--dash-text); cursor: pointer;">
                <option value="" <?php echo ($period === '') ? 'selected' : ''; ?>>Toutes les dates</option>
                <option value="a_venir" <?php echo ($period === 'a_venir') ? 'selected' : ''; ?>>À venir</option>
                <option value="aujourdhui" <?php echo ($period === 'aujourdhui') ? 'selected' : ''; ?>>Aujourd'hui</option>
                <option value="passe" <?php echo ($period === 'passe') ? 'selected' : ''; ?>>Passés</option>
            </select>

            <!-- Champ Recherche -->
            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass"
                    style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="Nom, salle, promoteur..."
                    style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 170px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action"
                style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($status_f !== '' || $category !== '' || $period !== '' || $search !== ''): ?>
                <a href="evenements.php"
                    style="color: #000000; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. KPIS DE SYNTHÈSE (AU-DESSOUS DES FILTRES)
         ============================================================================== -->
    <div class="events-kpis">
        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--dash-muted); text-transform: uppercase;">Événements Filtrés</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-calendar-check"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text);"><?php echo count($events); ?></div>
            <small style="color: var(--dash-muted); font-size: 0.75rem;">Sur un total de <?php echo $total_events; ?> créés</small>
        </div>

        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Événements Actifs</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-bolt"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #FF4A0D;"><?php echo $active_events; ?></div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Visibles en billetterie publique</small>
        </div>

        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #737373; text-transform: uppercase;">Événements Clos</span>
                <span style="background: #F5F5F5; color: #737373; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-flag-checkered"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: #737373;"><?php echo $ended_events; ?></div>
            <small style="color: #737373; font-size: 0.75rem;">Éditions archivées ou terminées</small>
        </div>

        <div class="dash-kpi-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #FF4A0D; text-transform: uppercase;">Billets Émis</span>
                <span style="background: #FFF2ED; color: #FF4A0D; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div style="font-size: 1.65rem; font-weight: 800; color: var(--dash-text); font-variant-numeric: tabular-nums;">
                <?php echo str_replace(' ', '&nbsp;', number_format($total_tickets, 0, ',', ' ')); ?>
            </div>
            <small style="color: #FF4A0D; font-size: 0.75rem;">Total des billets vendus</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. TABLEAU DES ÉVÉNEMENTS & CARTES MOBILES SUISSES
         ============================================================================== -->
    <div class="dash-card">
        <div class="dash-card-head" style="margin-bottom: 1rem;">
            <h3 class="dash-card-title">
                <i class="fa-solid fa-list-check" style="color: var(--dash-primary);"></i> Liste des Événements
                (<?php echo count($events); ?>)
            </h3>
        </div>

        <?php if (empty($events)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-calendar-xmark"
                    style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                Aucun événement ne correspond à vos critères de recherche.<br>
                <a href="evenements.php"
                    style="color: var(--dash-primary); font-weight: 700; text-decoration: underline; margin-top: 0.5rem; display: inline-block;">Réinitialiser les filtres</a>
            </div>
        <?php else: ?>
            <div class="events-table-wrapper">
                <table class="dash-table events-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Affiche</th>
                            <th>Événement</th>
                            <th>Organisateur</th>
                            <th>Date & Heure</th>
                            <th>Lieu</th>
                            <th>Ventes / Capacité</th>
                            <th>Recette</th>
                            <th>Statut</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $ev): ?>
                            <?php
                            $taux = ($ev['total_places'] > 0) ? round(($ev['total_vendus'] / $ev['total_places']) * 100) : 0;

                            // Image
                            $img_src = 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&w=150&q=80';
                            if (!empty($ev['image'])) {
                                if (strpos($ev['image'], 'http') === 0) {
                                    $img_src = htmlspecialchars($ev['image']);
                                } elseif (file_exists('../uploads/events/' . $ev['image'])) {
                                    $img_src = '../uploads/events/' . htmlspecialchars($ev['image']);
                                }
                            }

                            // Statut badge
                            $statut_badge = [
                                'actif' => ['Actif', '#FFF2ED', '#000000'],
                                'termine' => ['Terminé', '#F5F5F5', '#737373'],
                                'annule' => ['Annulé', '#F5F5F5', '#000000'],
                                'en_attente' => ['En attente', '#FFF2ED', '#000000']
                            ];
                            [$st_label, $st_bg, $st_fg] = $statut_badge[$ev['statut']] ?? ['Inconnu', '#F5F5F5', '#737373'];
                            ?>
                            <tr>
                                <td class="hide-on-mobile-card" style="width: 60px;">
                                    <img src="<?php echo $img_src; ?>" alt="Affiche"
                                        style="width: 48px; height: 48px; border-radius: 8px; object-fit: cover; border: 1px solid var(--dash-border);">
                                </td>
                                <td class="card-top" data-label="Événement">
                                    <div class="card-top-flex">
                                        <img class="mobile-thumb-img" src="<?php echo $img_src; ?>" alt="Affiche">
                                        <div class="card-top-content">
                                            <strong class="event-title">
                                                <?php echo htmlspecialchars($ev['nom']); ?>
                                            </strong>
                                            <div class="event-subtags">
                                                <span class="event-cat-badge">
                                                    <i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($ev['categorie']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Organisateur">
                                    <span style="font-size: 0.84rem; font-weight: 700; color: var(--dash-text);">
                                        <?php echo htmlspecialchars($ev['promoter_name']); ?>
                                    </span>
                                </td>
                                <td data-label="Date & Heure">
                                    <span style="font-size: 0.84rem; color: var(--dash-text); display: block; font-weight: 600;">
                                        <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                                    </span>
                                    <small style="color: var(--dash-muted); font-size: 0.76rem;">
                                        <?php echo date('H\hi', strtotime($ev['heure'])); ?>
                                    </small>
                                </td>
                                <td data-label="Lieu">
                                    <span style="font-size: 0.82rem; color: var(--dash-muted);">
                                        <i class="fa-solid fa-location-dot" style="color: #000000;"></i>
                                        <?php echo htmlspecialchars(mb_strimwidth($ev['lieu'], 0, 30, '...')); ?>
                                    </span>
                                </td>
                                <td data-label="Ventes / Jauge">
                                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 3px;">
                                        <div style="font-size: 0.82rem; font-weight: 700; color: var(--dash-text); font-variant-numeric: tabular-nums;">
                                            <?php echo $ev['total_vendus']; ?> / <?php echo $ev['total_places']; ?> <small style="color: var(--dash-muted);">(<?php echo $taux; ?>%)</small>
                                        </div>
                                        <div style="background: #E5E5E5; height: 5px; border-radius: 999px; overflow: hidden; width: 85px;">
                                            <div style="height: 100%; width: <?php echo min(100, $taux); ?>%; background: #FF4A0D; border-radius: 999px;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Recette">
                                    <strong class="cell-revenue">
                                        <?php echo str_replace(' ', '&nbsp;', number_format((float) $ev['total_recette'], 0, ',', ' ')); ?>&nbsp;F
                                    </strong>
                                </td>
                                <td data-label="Statut">
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <select name="new_status" onchange="this.form.submit()"
                                            style="background: <?php echo $st_bg; ?>; color: <?php echo $st_fg; ?>; border: 1px solid rgba(0,0,0,0.06); padding: 4px 8px; border-radius: 6px; font-size: 0.76rem; font-weight: 800; cursor: pointer; outline: none;">
                                            <option value="actif" <?php echo $ev['statut'] === 'actif' ? 'selected' : ''; ?>>Actif</option>
                                            <option value="termine" <?php echo $ev['statut'] === 'termine' ? 'selected' : ''; ?>>Terminé</option>
                                            <option value="annule" <?php echo $ev['statut'] === 'annule' ? 'selected' : ''; ?>>Annulé</option>
                                            <option value="en_attente" <?php echo $ev['statut'] === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                        </select>
                                    </form>
                                </td>
                                <td class="card-actions" style="text-align: right;">
                                    <div class="cell-actions-group">
                                        <a href="../client/accueil.php" target="_blank" class="dash-btn-action"
                                            style="padding: 0.35rem 0.6rem; font-size: 0.74rem;"
                                            title="Voir la vitrine publique">
                                            <i class="fa-solid fa-eye"></i> <span>Vitrine</span>
                                        </a>
                                        <button type="button" class="dash-btn-action btn-edit"
                                            onclick="openEditEventModal(<?php echo (int) $ev['id']; ?>)"
                                            style="padding: 0.35rem 0.6rem; font-size: 0.74rem; cursor: pointer;" title="Modifier cet événement (Modale)">
                                            <i class="fa-solid fa-pen"></i> <span>Modifier</span>
                                        </button>
                                        <a href="supprimer-evenement.php?id=<?php echo $ev['id']; ?>" class="dash-btn-action"
                                            style="padding: 0.35rem 0.6rem; font-size: 0.74rem; color: #000000;"
                                            onclick="return confirm('Confirmez-vous la suppression définitive de cet événement ?');"
                                            title="Supprimer">
                                            <i class="fa-solid fa-trash"></i> <span>Supprimer</span>
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
     MODALE DE MODIFICATION D'ÉVÉNEMENT (CONFORME AUX STANDARDS DU SITE)
     ============================================================================== -->
<div id="editEventModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeEditEventModal()"></div>
    <form method="POST" action="evenements.php" enctype="multipart/form-data" class="dash-modal-dialog">
        <input type="hidden" name="update_event" value="1">
        <input type="hidden" name="event_id" id="edit_event_id">

        <div class="dash-modal-header">
            <h3><i class="fa-solid fa-pen-to-square" style="color: var(--dash-primary);"></i> Modifier l'Événement</h3>
            <button type="button" class="dash-modal-close" onclick="closeEditEventModal()">&times;</button>
        </div>

        <div class="dash-modal-body">
                <!-- Nom de l'événement -->
                <div>
                    <label class="form-field-label">Nom de l'événement *</label>
                    <input type="text" name="nom" id="edit_nom" class="form-field-input" required placeholder="Ex: Concert Live Abidjan 2026">
                </div>

                <!-- Catégorie -->
                <div>
                    <label class="form-field-label">Catégorie *</label>
                    <select name="categorie" id="edit_categorie" class="form-field-select" onchange="onEditCatChange(this.value)">
                        <option value="Concert">Concert</option>
                        <option value="Festival">Festival</option>
                        <option value="Théâtre">Théâtre</option>
                        <option value="Humour">Humour</option>
                        <option value="Sport">Sport</option>
                        <option value="Conférence">Conférence</option>
                        <option value="Soirée">Soirée</option>
                        <option value="Autre">✏️ Autre catégorie personnalisée...</option>
                    </select>
                    <div id="edit_custom_cat_box" style="display: none; margin-top: 6px;">
                        <input type="text" name="categorie_custom" id="edit_categorie_custom" class="form-field-input" placeholder="Précisez la catégorie...">
                    </div>
                </div>

                <!-- Date & Heure -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                    <div>
                        <label class="form-field-label">Date de l'événement *</label>
                        <input type="date" name="date_evenement" id="edit_date_evenement" class="form-field-input" required>
                    </div>
                    <div>
                        <label class="form-field-label">Heure *</label>
                        <input type="time" name="heure" id="edit_heure" class="form-field-input" required>
                    </div>
                </div>

                <!-- Lieu / Salle -->
                <div>
                    <label class="form-field-label">Lieu & Ville *</label>
                    <input type="text" name="lieu" id="edit_lieu" class="form-field-input" required placeholder="Ex: Palais de la Culture, Treichville, Abidjan">
                </div>

                <!-- Statut & Commission -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                    <div>
                        <label class="form-field-label">Statut de diffusion *</label>
                        <select name="statut" id="edit_statut" class="form-field-select">
                            <option value="actif">Actif (En vente)</option>
                            <option value="termine">Terminé (Archivé)</option>
                            <option value="annule">Annulé</option>
                            <option value="en_attente">En attente de validation</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-field-label">Commission plateforme (%)</label>
                        <input type="number" step="0.1" min="0" max="100" name="commission_rate" id="edit_commission_rate" class="form-field-input">
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label class="form-field-label">Description détaillée</label>
                    <textarea name="description" id="edit_description" class="form-field-textarea" rows="3" placeholder="Présentation et programme de l'événement..."></textarea>
                </div>

                <!-- Type de vote & Prix du vote -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                    <div>
                        <label class="form-field-label">Système de vote associé</label>
                        <select name="type_vote" id="edit_type_vote" class="form-field-select">
                            <option value="aucun">Aucun vote</option>
                            <option value="concours">Concours / Compétition</option>
                            <option value="realisation">Réalisation / Vote du public</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-field-label">Prix du vote (FCFA)</label>
                        <input type="number" min="0" step="50" name="prix_vote" id="edit_prix_vote" class="form-field-input">
                    </div>
                </div>

                <!-- Affiche de l'événement -->
                <div style="background: #F5F5F5; border: 1px solid var(--dash-border, #E5E5E5); border-radius: 10px; padding: 0.85rem;">
                    <label class="form-field-label" style="margin-bottom: 6px;">Affiche promotionnelle</label>
                    <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <img id="edit_preview_img" src="" alt="Affiche actuelle"
                            style="width: 56px; height: 56px; border-radius: 8px; object-fit: cover; border: 1px solid var(--dash-border, #E5E5E5);">
                        <div style="flex: 1 1 auto; min-width: 200px;">
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-field-input" style="padding: 0.45rem 0.65rem; font-size: 0.8rem;">
                            <small style="color: var(--dash-muted, #737373); font-size: 0.74rem; display: block; margin-top: 3px;">Laisser vide pour conserver l'affiche actuelle.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dash-modal-footer">
                <button type="button" class="dash-btn-action" onclick="closeEditEventModal()" style="cursor: pointer;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" style="cursor: pointer; padding: 0.6rem 1.3rem;">
                    <i class="fa-solid fa-check"></i> Enregistrer les Modifications
                </button>
            </div>
        </form>
</div>

<script>
// Dictionnaire sécurisé des événements pour la modale
const PLATFORM_EVENTS = <?php 
    $ev_map = [];
    foreach ($events as $item) {
        $ev_map[(int)$item['id']] = $item;
    }
    echo json_encode($ev_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); 
?>;

function openEditEventModal(evData) {
    let ev = null;
    if (typeof evData === 'object' && evData !== null) {
        ev = evData;
    } else {
        const evId = parseInt(evData, 10);
        if (PLATFORM_EVENTS && PLATFORM_EVENTS[evId]) {
            ev = PLATFORM_EVENTS[evId];
        }
    }

    if (!ev) {
        console.error("Événement introuvable:", evData);
        alert("Impossible de charger les données de cet événement.");
        return;
    }
    
    document.getElementById('edit_event_id').value = ev.id || '';
    document.getElementById('edit_nom').value = ev.nom || '';
    document.getElementById('edit_description').value = ev.description || '';
    
    // Catégorie
    const cats = ['Concert', 'Festival', 'Théâtre', 'Humour', 'Sport', 'Conférence', 'Soirée'];
    const selectCat = document.getElementById('edit_categorie');
    const customCatBox = document.getElementById('edit_custom_cat_box');
    const customCatInput = document.getElementById('edit_categorie_custom');
    
    if (cats.includes(ev.categorie)) {
        selectCat.value = ev.categorie;
        customCatBox.style.display = 'none';
        customCatInput.value = '';
    } else if (ev.categorie) {
        selectCat.value = 'Autre';
        customCatBox.style.display = 'block';
        customCatInput.value = ev.categorie;
    } else {
        selectCat.value = 'Concert';
        customCatBox.style.display = 'none';
        customCatInput.value = '';
    }

    document.getElementById('edit_date_evenement').value = ev.date_evenement || '';
    document.getElementById('edit_heure').value = ev.heure ? ev.heure.substring(0, 5) : '';
    document.getElementById('edit_lieu').value = ev.lieu || '';
    document.getElementById('edit_statut').value = ev.statut || 'actif';
    document.getElementById('edit_type_vote').value = ev.type_vote || 'aucun';
    document.getElementById('edit_prix_vote').value = parseFloat(ev.prix_vote || 0);
    document.getElementById('edit_commission_rate').value = parseFloat(ev.commission_rate || 5);

    // Image preview
    const previewImg = document.getElementById('edit_preview_img');
    if (ev.image) {
        if (ev.image.indexOf('http') === 0) {
            previewImg.src = ev.image;
        } else {
            previewImg.src = '../uploads/events/' + ev.image;
        }
    } else {
        previewImg.src = 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&w=150&q=80';
    }

    const modal = document.getElementById('editEventModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeEditEventModal() {
    const modal = document.getElementById('editEventModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function onEditCatChange(val) {
    const box = document.getElementById('edit_custom_cat_box');
    if (val === 'Autre') {
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}

// Fermeture avec la touche Échap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditEventModal();
    }
});
</script>

<?php include 'footer.php'; ?>