<?php
// ==============================================================================
// GESTION DES ÉVÉNEMENTS DU PROMOTEUR (promoteur/mes-evenements.php)
// Design Dashboard Pro - Pilotage des événements, billetteries, quotas et statuts
// ==============================================================================

$page_title = "Gestion de mes Événements - Espace Promoteur";
include 'header.php';

$user_id = (int)$_SESSION['user_id'];
$message = "";
$msg_type = "";

/**
 * Résolution robuste et dynamique de l'affiche de l'événement
 */
function get_event_display_image($ev) {
    $img = trim($ev['image'] ?? '');
    if (!empty($img) && $img !== 'default.jpg') {
        if (strpos($img, 'http://') === 0 || strpos($img, 'https://') === 0) {
            return $img;
        }
        if (file_exists(__DIR__ . '/../uploads/events/' . $img)) {
            return '../uploads/events/' . htmlspecialchars($img);
        }
        if (file_exists(__DIR__ . '/../uploads/' . $img)) {
            return '../uploads/' . htmlspecialchars($img);
        }
    }
    
    // Visuels thématiques haute fidélité selon l'événement et la catégorie
    $cat = mb_strtolower(trim($ev['categorie'] ?? ''));
    $id = (int)($ev['id'] ?? 0);

    $curated = [
        1 => 'https://images.unsplash.com/photo-1514525253161-7a46d19cd819?auto=format&fit=crop&w=800&q=80', // Concert Live Abidjan
        4 => 'https://images.unsplash.com/photo-1509198397868-475647b2a1e5?auto=format&fit=crop&w=800&q=80', // Doctor Doom
        9 => 'https://images.unsplash.com/photo-1470225620780-dba8ba36b745?auto=format&fit=crop&w=800&q=80', // La Pona Concert
    ];
    if (isset($curated[$id])) {
        return $curated[$id];
    }

    $fallbacks = [
        'concert'   => 'https://images.unsplash.com/photo-1514525253161-7a46d19cd819?auto=format&fit=crop&w=800&q=80',
        'festival'  => 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=800&q=80',
        'spectacle' => 'https://images.unsplash.com/photo-1469488865564-c2de10f69f96?auto=format&fit=crop&w=800&q=80',
        'sport'     => 'https://images.unsplash.com/photo-1508098682722-e99c43a406b2?auto=format&fit=crop&w=800&q=80',
        'theatre'   => 'https://images.unsplash.com/photo-1507676184212-d03ab07a01bf?auto=format&fit=crop&w=800&q=80',
        'soiree'    => 'https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?auto=format&fit=crop&w=800&q=80',
        'conference'=> 'https://images.unsplash.com/photo-1475721027785-f74eccf877e2?auto=format&fit=crop&w=800&q=80',
        'formation' => 'https://images.unsplash.com/photo-1524178232363-1fb2b075b655?auto=format&fit=crop&w=800&q=80',
    ];
    if (isset($fallbacks[$cat])) {
        return $fallbacks[$cat];
    }

    if (file_exists(__DIR__ . '/../uploads/events/default.jpg')) {
        return '../uploads/events/default.jpg';
    }
    return 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&w=800&q=80';
}

// ------------------------------------------------------------------------------
// 1. Actions de gestion (Clôturer, Réactiver, Modifier)
// ------------------------------------------------------------------------------

// Clôturer / Marquer comme Terminé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cloturer'])) {
    $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $stmt_close = $pdo->prepare("UPDATE events SET statut = 'termine' WHERE id = ? AND user_id = ?");
    $stmt_close->execute([$event_id, $user_id]);
    $message = "L'événement a été marqué comme terminé et la billetterie est désormais clôturée.";
    $msg_type = "success";
}

// Réactiver l'événement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reactiver'])) {
    $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $stmt_act = $pdo->prepare("UPDATE events SET statut = 'actif' WHERE id = ? AND user_id = ?");
    $stmt_act->execute([$event_id, $user_id]);
    $message = "L'événement a été réactivé avec succès.";
    $msg_type = "success";
}

// Modification des informations de base de l'événement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_modifier'])) {
    $event_id    = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $nom         = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $lieu        = trim($_POST['lieu'] ?? '');
    $date_ev     = trim($_POST['date_evenement'] ?? '');
    $heure       = trim($_POST['heure'] ?? '');

    if (empty($nom) || empty($lieu) || empty($date_ev) || empty($heure)) {
        $message = "Veuillez remplir tous les champs obligatoires (Titre, Date, Heure, Lieu).";
        $msg_type = "error";
    } else {
        // Traitement de l'upload de nouvelle image si présente
        $new_image_name = null;
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $new_image_name = 'event_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest_dir = __DIR__ . '/../uploads/events/';
                if (!is_dir($dest_dir)) {
                    @mkdir($dest_dir, 0777, true);
                }
                $dest = $dest_dir . $new_image_name;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                    $new_image_name = null;
                }
            }
        }

        if ($new_image_name) {
            $stmt_upd = $pdo->prepare("
                UPDATE events 
                SET nom = ?, description = ?, lieu = ?, date_evenement = ?, heure = ?, image = ? 
                WHERE id = ? AND user_id = ?
            ");
            $stmt_upd->execute([$nom, $description, $lieu, $date_ev, $heure, $new_image_name, $event_id, $user_id]);
        } else {
            $stmt_upd = $pdo->prepare("
                UPDATE events 
                SET nom = ?, description = ?, lieu = ?, date_evenement = ?, heure = ? 
                WHERE id = ? AND user_id = ?
            ");
            $stmt_upd->execute([$nom, $description, $lieu, $date_ev, $heure, $event_id, $user_id]);
        }
        $message = "Les modifications ont été enregistrées avec succès.";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 2. Filtres & Recherche
// ------------------------------------------------------------------------------
$filter_statut = $_GET['statut'] ?? 'tous';
if (!in_array($filter_statut, ['tous', 'actif', 'termine', 'complet'], true)) {
    $filter_statut = 'tous';
}

$periode = $_GET['periode'] ?? 'toutes';
if (!in_array($periode, ['toutes', '7_jours', '30_jours', 'ce_mois', 'cette_annee'], true)) {
    $periode = 'toutes';
}

$search_q = trim($_GET['q'] ?? '');

// ------------------------------------------------------------------------------
// 3. Récupération des événements avec statistiques de ventes et scans
// ------------------------------------------------------------------------------
$sql_events = "
    SELECT e.*, 
           COALESCE((SELECT SUM(tt.quantite_vendue) FROM ticket_types tt WHERE tt.event_id = e.id), 0) AS tickets_vendus,
           COALESCE((SELECT SUM(tt.quantite) FROM ticket_types tt WHERE tt.event_id = e.id), 0) AS total_places,
           COALESCE((SELECT SUM(t.prix) FROM tickets t WHERE t.event_id = e.id AND t.statut IN ('vendu', 'utilise')), 0) AS total_recette,
           COALESCE((SELECT COUNT(*) FROM tickets t_sc WHERE t_sc.event_id = e.id AND t_sc.statut = 'utilise'), 0) AS total_scans,
           COALESCE((SELECT COUNT(*) FROM agent_assignments aa WHERE aa.event_id = e.id), 0) AS nb_agents,
           COALESCE((SELECT COUNT(*) FROM event_votes ev WHERE ev.event_id = e.id), 0) AS nb_votes
    FROM events e 
    WHERE e.user_id = ?
";
$params_events = [$user_id];

if ($filter_statut === 'actif') {
    $sql_events .= " AND (e.statut = 'actif' OR e.statut = 'approuve')";
} elseif ($filter_statut === 'termine') {
    $sql_events .= " AND e.statut = 'termine'";
}

if ($periode === 'ce_mois') {
    $sql_events .= " AND e.date_evenement >= DATE_FORMAT(NOW(), '%Y-%m-01')";
} elseif ($periode === 'cette_annee') {
    $sql_events .= " AND e.date_evenement >= DATE_FORMAT(NOW(), '%Y-01-01')";
} elseif ($periode === '30_jours') {
    $sql_events .= " AND e.date_evenement >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($periode === '7_jours') {
    $sql_events .= " AND e.date_evenement >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
}

if ($search_q !== '') {
    $sql_events .= " AND (e.nom LIKE ? OR e.lieu LIKE ? OR e.categorie LIKE ?)";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
}

$sql_events .= " ORDER BY (e.statut = 'actif' OR e.statut = 'approuve') DESC, e.date_evenement DESC";

$stmt_events = $pdo->prepare($sql_events);
$stmt_events->execute($params_events);
$all_events = $stmt_events->fetchAll(PDO::FETCH_ASSOC);

// Filtrage si 'complet'
if ($filter_statut === 'complet') {
    $all_events = array_filter($all_events, function($ev) {
        return (int)$ev['total_places'] > 0 && (int)$ev['tickets_vendus'] >= (int)$ev['total_places'];
    });
}

// ------------------------------------------------------------------------------
// 4. Calcul des KPI Cards Globales
// ------------------------------------------------------------------------------
$total_events_count = count($all_events);
$total_billets_vendus_sum = array_sum(array_column($all_events, 'tickets_vendus'));
$total_places_sum = array_sum(array_column($all_events, 'total_places'));
$total_recettes_sum = array_sum(array_column($all_events, 'total_recette'));
$taux_remplissage_global = $total_places_sum > 0 ? round(($total_billets_vendus_sum / $total_places_sum) * 100, 1) : 0;
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
.event-thumb {
    width: 60px;
    height: 60px;
    border-radius: 10px;
    object-fit: cover;
    background: #000000;
    border: 1px solid var(--dash-border);
    flex-shrink: 0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.06);
}
.event-action-btn {
    padding: 0.35rem 0.65rem;
    border-radius: 8px;
    border: 1px solid var(--dash-border);
    background: #ffffff;
    color: var(--dash-text);
    font-size: 0.78rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.event-action-btn:hover {
    background: #F5F5F5;
    border-color: var(--dash-primary);
    color: var(--dash-primary);
}
.event-action-btn.btn-danger:hover {
    background: #F5F5F5;
    border-color: #000000;
    color: #000000;
}
.event-progress-bar {
    background: #E5E5E5;
    border-radius: 999px;
    height: 7px;
    overflow: hidden;
    margin-top: 5px;
}
.event-progress-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease;
}

/* Responsivité Mobile & Tablette */
.events-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.events-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    background: #ffffff;
    padding: 0.65rem 0.85rem;
    border-radius: 12px;
    border: 1px solid var(--dash-border);
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    flex-wrap: wrap;
}

.events-tabs-scroll {
    display: flex;
    gap: 0.35rem;
    align-items: center;
    flex-wrap: wrap;
}

.events-filter-form {
    display: inline-flex;
    gap: 8px;
    align-items: center;
    margin: 0;
    flex-wrap: wrap;
}

/* Actions Compactes & Stylisées pour le Tableau Desktop */
.table-actions-group {
    display: inline-flex;
    gap: 4px;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: nowrap;
}

.table-action-icon {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    border: 1px solid var(--dash-border, #E5E5E5);
    color: var(--dash-text, #000000);
    font-size: 0.78rem;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
    padding: 0;
    flex-shrink: 0;
}

.table-action-icon:hover {
    background: #000000;
    color: #ffffff;
    border-color: #000000;
    transform: translateY(-1px);
    box-shadow: 0 3px 6px rgba(0, 0, 0, 0.12);
}

.table-action-icon.action-primary:hover {
    background: var(--tikeli-orange, #FF4A0D);
    border-color: var(--tikeli-orange, #FF4A0D);
    color: #ffffff;
}

.table-action-icon.action-danger {
    color: #dc2626;
    border-color: #fee2e2;
    background: #fff5f5;
}

.table-action-icon.action-danger:hover {
    background: #dc2626;
    border-color: #dc2626;
    color: #ffffff;
}

.table-action-icon.action-success {
    color: #16a34a;
    border-color: #dcfce7;
    background: #f0fdf4;
}

.table-action-icon.action-success:hover {
    background: #16a34a;
    border-color: #16a34a;
    color: #ffffff;
}

/* Grille d'actions tactiles pour les Cartes Mobile */
.card-actions-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 6px;
    padding-top: 6px;
    border-top: 1px dashed var(--dash-border);
}

.card-action-btn {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 7px 4px;
    border-radius: 8px;
    background: #ffffff;
    border: 1px solid var(--dash-border);
    color: var(--dash-text);
    font-size: 0.68rem;
    font-weight: 700;
    text-decoration: none;
    gap: 3px;
    transition: all 0.15s ease;
    cursor: pointer;
    width: 100%;
    box-sizing: border-box;
}

.card-action-btn i {
    font-size: 0.85rem;
}

.card-action-btn:hover {
    background: #000000;
    color: #ffffff;
    border-color: #000000;
}

.card-action-btn.action-primary {
    color: var(--tikeli-orange, #FF4A0D);
    border-color: #ffd8c7;
    background: #fff8f5;
}

.card-action-btn.action-primary:hover {
    background: var(--tikeli-orange, #FF4A0D);
    color: #ffffff;
    border-color: var(--tikeli-orange, #FF4A0D);
}

.card-action-btn.action-danger {
    color: #dc2626;
    border-color: #fee2e2;
    background: #fff5f5;
}

.card-action-btn.action-danger:hover {
    background: #dc2626;
    color: #ffffff;
    border-color: #dc2626;
}

.card-action-btn.action-success {
    color: #16a34a;
    border-color: #dcfce7;
    background: #f0fdf4;
}

.card-action-btn.action-success:hover {
    background: #16a34a;
    color: #ffffff;
    border-color: #16a34a;
}

.events-desktop-table {
    display: block;
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #E5E5E5 transparent;
}
.events-desktop-table::-webkit-scrollbar {
    height: 4px;
}
.events-desktop-table::-webkit-scrollbar-thumb {
    background: #E5E5E5;
    border-radius: 4px;
}

.events-mobile-cards {
    display: none;
}

@media (max-width: 1200px) {
    .events-kpi-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.75rem !important;
    }
    body:not(.sidebar-collapsed) .events-desktop-table {
        display: none !important;
    }
    body:not(.sidebar-collapsed) .events-mobile-cards {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.85rem !important;
        padding: 0.85rem !important;
    }
}

/* Bascule responsive optimisée à 991px pour tous les modes */
@media (max-width: 991px) {
    .dash-container {
        padding: 0.75rem !important;
    }
    .dash-header-section {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
    }
    .dash-header-section .dash-title-box h1 {
        font-size: 1.25rem !important;
    }
    .dash-header-section .dash-title-box p {
        font-size: 0.8rem !important;
    }
    .dash-header-section > div:last-child {
        width: 100% !important;
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 0.5rem !important;
    }
    .dash-header-section .dash-btn-action {
        justify-content: center !important;
        padding: 0.55rem 0.5rem !important;
        font-size: 0.8rem !important;
        text-align: center !important;
    }

    /* Barre de filtres adaptative */
    .events-filter-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        padding: 0.75rem !important;
        gap: 0.65rem !important;
    }
    .events-tabs-scroll {
        overflow-x: auto !important;
        white-space: nowrap !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: none !important;
        gap: 0.35rem !important;
        padding-bottom: 3px !important;
    }
    .events-tabs-scroll::-webkit-scrollbar {
        display: none !important;
    }
    .events-filter-form {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.5rem !important;
        width: 100% !important;
    }
    .events-filter-form > div {
        width: 100% !important;
    }
    .events-filter-form select,
    .events-filter-form input[name="q"] {
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .events-filter-form button[type="submit"] {
        width: 100% !important;
        justify-content: center !important;
    }

    /* Masquer le tableau et afficher les cartes fluides */
    .events-desktop-table {
        display: none !important;
    }
    .events-mobile-cards {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.85rem !important;
        padding: 0.85rem !important;
    }
}

@media (max-width: 580px) {
    .events-kpi-grid {
        grid-template-columns: 1fr !important;
        gap: 0.6rem !important;
    }
    .dash-header-section > div:last-child {
        grid-template-columns: 1fr !important;
    }
}
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.5rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-calendar-days" style="color: var(--dash-primary); font-size: 1.55rem;"></i>
                Gestion de mes Événements
            </h1>
            <p>Pilotez vos billetteries, suivez le remplissage des salles, gérez vos tarifs et encadrez vos équipes de contrôle.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; flex-wrap: wrap;">
            <a href="export.php?type=evenements" class="dash-btn-action" style="padding: 0.6rem 1.15rem; text-decoration: none;" title="Exporter le bilan de tous les événements sur Excel">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <a href="demande-evenement.php" class="dash-btn-action btn-primary" style="padding: 0.6rem 1.15rem; text-decoration: none;">
                <i class="fa-solid fa-plus"></i> Proposer un Événement
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msg_type === 'success' ? '#FFF2ED' : '#F5F5F5'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#FFF2ED' : '#E5E5E5'; ?>; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: <?php echo $msg_type === 'success' ? '#000000' : '#000000'; ?>; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. KPI CARDS : SYNTHÈSE DE GESTION DES ÉVÉNEMENTS
         ============================================================================== -->
    <div class="events-kpi-grid">
        <!-- 1. Total Événements -->
        <div class="dash-kpi-card" style="padding: 1.15rem 1.25rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border, #E2E8F0); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                <span style="font-size: 0.76rem; font-weight: 700; color: var(--dash-muted, #64748B); text-transform: uppercase; letter-spacing: 0.04em;">Total Événements</span>
                <span style="background: #F8FAFC; border: 1px solid #E2E8F0; color: var(--dash-text, #0F172A); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-calendar-check"></i></span>
            </div>
            <div style="font-size: 1.7rem; font-weight: 800; color: var(--dash-text, #0F172A); letter-spacing: -0.02em; line-height: 1.2;"><?php echo $total_events_count; ?></div>
            <small style="color: var(--dash-muted, #64748B); font-size: 0.75rem; margin-top: 4px; display: block;">En ligne & enregistrés</small>
        </div>

        <!-- 2. Billets Écoulés -->
        <div class="dash-kpi-card" style="padding: 1.15rem 1.25rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border, #E2E8F0); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                <span style="font-size: 0.76rem; font-weight: 700; color: var(--dash-muted, #64748B); text-transform: uppercase; letter-spacing: 0.04em;">Billets Écoulés</span>
                <span style="background: #F8FAFC; border: 1px solid #E2E8F0; color: var(--dash-text, #0F172A); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-ticket"></i></span>
            </div>
            <div style="font-size: 1.7rem; font-weight: 800; color: var(--dash-text, #0F172A); letter-spacing: -0.02em; line-height: 1.2;"><?php echo number_format($total_billets_vendus_sum, 0, ',', ' '); ?></div>
            <small style="color: var(--dash-muted, #64748B); font-size: 0.75rem; margin-top: 4px; display: block;">sur <?php echo number_format($total_places_sum, 0, ',', ' '); ?> places disponibles</small>
        </div>

        <!-- 3. Recettes Cumulées -->
        <div class="dash-kpi-card" style="padding: 1.15rem 1.25rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border, #E2E8F0); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                <span style="font-size: 0.76rem; font-weight: 700; color: var(--dash-muted, #64748B); text-transform: uppercase; letter-spacing: 0.04em;">Recettes Cumulées</span>
                <span style="background: #F8FAFC; border: 1px solid #E2E8F0; color: #16A34A; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-coins"></i></span>
            </div>
            <div style="font-size: 1.7rem; font-weight: 800; color: #16A34A; letter-spacing: -0.02em; line-height: 1.2;"><?php echo number_format($total_recettes_sum, 0, ',', ' '); ?> <span style="font-size: 1rem; font-weight: 700;">FCFA</span></div>
            <small style="color: var(--dash-muted, #64748B); font-size: 0.75rem; margin-top: 4px; display: block;">Chiffre d'affaires brut généré</small>
        </div>

        <!-- 4. Remplissage Moyen -->
        <div class="dash-kpi-card" style="padding: 1.15rem 1.25rem; border-radius: 12px; background: #ffffff; border: 1px solid var(--dash-border, #E2E8F0); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                <span style="font-size: 0.76rem; font-weight: 700; color: var(--dash-muted, #64748B); text-transform: uppercase; letter-spacing: 0.04em;">Remplissage Moyen</span>
                <span style="background: #F8FAFC; border: 1px solid #E2E8F0; color: var(--dash-text, #0F172A); width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-chart-pie"></i></span>
            </div>
            <div style="font-size: 1.7rem; font-weight: 800; color: var(--dash-text, #0F172A); letter-spacing: -0.02em; line-height: 1.2;"><?php echo $taux_remplissage_global; ?>%</div>
            <small style="color: var(--dash-muted, #64748B); font-size: 0.75rem; margin-top: 4px; display: block;">Capacité globale occupée</small>
        </div>
    </div>

    <!-- ==============================================================================
         3. BARRE DE FILTRES : STATUTS, PÉRIODE & RECHERCHE
         ============================================================================== -->
    <div class="events-filter-bar">
        <!-- Onglets Statuts avec défilement tactile fluide -->
        <div class="events-tabs-scroll">
            <a href="?statut=tous&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'tous' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                <i class="fa-solid fa-list"></i> Tous (<?php echo $total_events_count; ?>)
            </a>

            <a href="?statut=actif&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'actif' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                <i class="fa-solid fa-circle-dot" style="color: #FF4A0D;"></i> En cours / Actifs
            </a>

            <a href="?statut=complet&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'complet' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                <i class="fa-solid fa-ban" style="color: #000000;"></i> Complets / Sold Out
            </a>

            <a href="?statut=termine&periode=<?php echo $periode; ?>" class="dash-chart-tab <?php echo $filter_statut === 'termine' ? 'active' : ''; ?>" style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.95rem; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                <i class="fa-solid fa-flag-checkered" style="color: #737373;"></i> Terminés
            </a>
        </div>

        <!-- Filtre Période & Recherche -->
        <form method="GET" class="events-filter-form">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($filter_statut); ?>">

            <!-- Sélecteur PÉRIODE -->
            <div style="display: inline-flex; align-items: center; gap: 6px; background: #F5F5F5; border: 1px solid var(--dash-border); border-radius: 8px; padding: 3px 10px;">
                <i class="fa-regular fa-calendar-days" style="color: var(--dash-primary); font-size: 0.85rem;"></i>
                <select name="periode" onchange="this.form.submit()" style="border: 0; background: transparent; font-size: 0.82rem; font-weight: 700; color: var(--dash-text); cursor: pointer; padding: 0.3rem 0.2rem; outline: none;">
                    <option value="toutes" <?php echo $periode === 'toutes' ? 'selected' : ''; ?>>Toutes les dates</option>
                    <option value="7_jours" <?php echo $periode === '7_jours' ? 'selected' : ''; ?>>7 derniers jours</option>
                    <option value="30_jours" <?php echo $periode === '30_jours' ? 'selected' : ''; ?>>30 derniers jours</option>
                    <option value="ce_mois" <?php echo $periode === 'ce_mois' ? 'selected' : ''; ?>>Ce mois-ci</option>
                    <option value="cette_annee" <?php echo $periode === 'cette_annee' ? 'selected' : ''; ?>>Cette année</option>
                </select>
            </div>

            <!-- Champ Recherche rapide -->
            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--dash-muted); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Nom, lieu..." style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.82rem; width: 170px; background: #ffffff;">
            </div>

            <button type="submit" class="dash-btn-action" style="padding: 0.4rem 0.85rem; font-size: 0.82rem; background: var(--dash-primary); color: #ffffff; border-radius: 8px;">
                Filtrer
            </button>

            <?php if ($periode !== 'toutes' || $search_q !== '' || $filter_statut !== 'tous'): ?>
                <a href="mes-evenements.php" style="color: #000000; font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         4. LISTE DES ÉVÉNEMENTS (TABLEAU DESKTOP & CARTES MOBILE)
         ============================================================================== -->
    <div class="dash-card" style="padding: 0; overflow: hidden;">
        <div style="padding: 1.15rem 1.35rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <h3 style="margin: 0; font-size: 1rem; color: var(--dash-text); font-weight: 700;">
                    <i class="fa-solid fa-bullhorn" style="color: var(--dash-primary); margin-right: 6px;"></i>
                    Événements Publiés sur Tikéli (<?php echo count($all_events); ?>)
                </h3>
                <small style="color: var(--dash-muted); font-size: 0.78rem;">Suivi en direct des ventes, entrées scannées et pilotage de la billetterie.</small>
            </div>
            <a href="demandes.php" style="font-size: 0.82rem; color: var(--dash-primary); font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                <i class="fa-solid fa-folder-open"></i> Voir mes propositions en attente
            </a>
        </div>

        <?php if (count($all_events) > 0): ?>
            <!-- A. VUE TABLEAU POUR GRAND ÉCRAN (DESKTOP / TABLETTE HORIZONTALE) -->
            <div class="events-desktop-table">
                <table class="dash-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr style="background: #F5F5F5; border-bottom: 1px solid var(--dash-border); font-size: 0.74rem; text-transform: uppercase; color: var(--dash-muted); letter-spacing: 0.04em;">
                            <th style="padding: 0.75rem 0.85rem;">Événement</th>
                            <th style="padding: 0.75rem 0.65rem;">Date & Lieu</th>
                            <th style="padding: 0.75rem 0.65rem; width: 160px;">Billetterie & Remplissage</th>
                            <th style="padding: 0.75rem 0.65rem; text-align: right;">Recettes</th>
                            <th style="padding: 0.75rem 0.65rem; text-align: center;">Statut</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: right; width: 170px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.85rem;">
                        <?php foreach ($all_events as $ev): ?>
                            <?php 
                                $vendus   = (int)$ev['tickets_vendus'];
                                $total    = (int)$ev['total_places'];
                                $restants = max(0, $total - $vendus);
                                $pct      = ($total > 0) ? round(($vendus / $total) * 100) : 0;
                                $is_actif = in_array($ev['statut'], ['actif', 'approuve'], true);
                                $is_soldout = ($total > 0 && $restants === 0);

                                $img_src = get_event_display_image($ev);
                                $type_label = '';
                                if ($ev['type_vote'] === 'concours') $type_label = 'Concours';
                                elseif ($ev['type_vote'] === 'realisation_evenement') $type_label = 'Vote Réalisation';
                                else $type_label = 'Billetterie';
                            ?>
                            <tr style="border-bottom: 1px solid var(--dash-border); transition: background 0.15s ease;">
                                <!-- Événement & Affiche -->
                                <td style="padding: 0.75rem 0.85rem;">
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($ev['nom']); ?>" class="event-thumb" style="width: 44px; height: 44px; border-radius: 8px;" onerror="this.onerror=null; this.src='../uploads/events/default.jpg';">
                                        <div style="min-width: 0;">
                                            <strong style="color: var(--dash-text); font-weight: 700; display: block; font-size: 0.88rem; line-height: 1.25; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?php echo htmlspecialchars($ev['nom']); ?>
                                            </strong>
                                            <div style="color: var(--dash-muted); font-size: 0.74rem; display: flex; align-items: center; gap: 4px; margin-top: 3px; flex-wrap: wrap;">
                                                <span style="background: #F5F5F5; color: var(--dash-text); padding: 1px 6px; border-radius: 4px; font-weight: 600; font-size: 0.7rem;">
                                                    <?php echo htmlspecialchars($ev['categorie']); ?>
                                                </span>
                                                <span style="background: #FFF2ED; color: #FF4A0D; padding: 1px 6px; border-radius: 4px; font-weight: 700; font-size: 0.7rem;">
                                                    <?php echo $type_label; ?>
                                                </span>
                                                <?php if ($ev['nb_agents'] > 0): ?>
                                                    <span style="color: #FF4A0D; font-size: 0.72rem; font-weight: 600;">
                                                        <i class="fa-solid fa-shield-halved"></i> <?php echo $ev['nb_agents']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Date & Lieu -->
                                <td style="padding: 0.75rem 0.65rem; white-space: nowrap;">
                                    <div style="color: var(--dash-text); font-weight: 700; font-size: 0.82rem;">
                                        <i class="fa-regular fa-calendar" style="color: var(--dash-primary); margin-right: 3px;"></i>
                                        <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                                    </div>
                                    <small style="color: var(--dash-muted); font-size: 0.75rem; display: block; margin-top: 2px; max-width: 150px; overflow: hidden; text-overflow: ellipsis;">
                                        <i class="fa-regular fa-clock"></i> <?php echo substr($ev['heure'], 0, 5); ?>
                                        • <?php echo htmlspecialchars($ev['lieu']); ?>
                                    </small>
                                </td>

                                <!-- Billetterie & Remplissage -->
                                <td style="padding: 0.75rem 0.65rem; width: 160px;">
                                    <div style="display: flex; justify-content: space-between; align-items: baseline; font-size: 0.8rem;">
                                        <strong style="color: #FF4A0D; font-size: 0.86rem;">
                                            <?php echo $vendus; ?> vendu(s)
                                        </strong>
                                        <span style="color: var(--dash-muted); font-size: 0.72rem;">
                                            / <?php echo $total; ?>
                                        </span>
                                    </div>
                                    <div class="event-progress-bar" style="height: 6px; margin-top: 4px;">
                                        <div class="event-progress-fill" style="width: <?php echo min(100, $pct); ?>%; background: <?php echo $is_soldout ? '#ef4444' : ($pct > 80 ? '#f59e0b' : '#10b981'); ?>;"></div>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 3px;">
                                        <small style="color: var(--dash-muted); font-size: 0.7rem;">Rempli à <?php echo $pct; ?>%</small>
                                        <?php if ($ev['total_scans'] > 0): ?>
                                            <small style="color: #10b981; font-size: 0.7rem; font-weight: 700;">
                                                <i class="fa-solid fa-qrcode"></i> <?php echo $ev['total_scans']; ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Recettes -->
                                <td style="padding: 0.75rem 0.65rem; text-align: right; white-space: nowrap;">
                                    <strong style="color: #10b981; font-size: 0.92rem; display: block;">
                                        <?php echo number_format($ev['total_recette'], 0, ',', ' '); ?> F
                                    </strong>
                                    <small style="color: var(--dash-muted); font-size: 0.7rem;">Com: <?php echo (float)$ev['commission_rate']; ?>%</small>
                                </td>

                                <!-- Statut -->
                                <td style="padding: 0.75rem 0.65rem; text-align: center; white-space: nowrap;">
                                    <?php if ($ev['statut'] === 'termine'): ?>
                                        <span style="background: #E5E5E5; color: #737373; padding: 3px 7px; border-radius: 6px; font-weight: 700; font-size: 0.72rem; display: inline-flex; align-items: center; gap: 3px;">
                                            <i class="fa-solid fa-flag-checkered"></i> Terminé
                                        </span>
                                    <?php elseif ($is_soldout): ?>
                                        <span style="background: #fee2e2; color: #dc2626; padding: 3px 7px; border-radius: 6px; font-weight: 700; font-size: 0.72rem; display: inline-flex; align-items: center; gap: 3px;">
                                            <i class="fa-solid fa-ban"></i> Complet
                                        </span>
                                    <?php elseif ($is_actif): ?>
                                        <span style="background: #ecfdf5; color: #166534; padding: 3px 7px; border-radius: 6px; font-weight: 700; font-size: 0.72rem; display: inline-flex; align-items: center; gap: 3px;">
                                            <i class="fa-solid fa-circle-dot" style="color: #10b981;"></i> En vente
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #F5F5F5; color: var(--dash-muted); padding: 3px 7px; border-radius: 6px; font-weight: 700; font-size: 0.72rem;">
                                            <?php echo htmlspecialchars(ucfirst($ev['statut'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions de Gestion -->
                                <td style="padding: 0.75rem 0.85rem; text-align: right; white-space: nowrap; width: 170px;">
                                    <div class="table-actions-group">
                                        <!-- Voir côté public -->
                                        <a href="../client/accueil.php" target="_blank" class="table-action-icon" title="Voir côté public" aria-label="Voir côté public">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                        </a>

                                        <!-- Modifier -->
                                        <button type="button" class="table-action-icon" title="Modifier l'événement" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($ev)); ?>)" aria-label="Modifier">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>

                                        <!-- Agents -->
                                        <a href="agents.php?event_id=<?php echo $ev['id']; ?>" class="table-action-icon" title="Gérer les agents de scan" aria-label="Agents">
                                            <i class="fa-solid fa-shield-halved"></i>
                                        </a>

                                        <!-- Ventes -->
                                        <a href="mes-ventes.php?event_id=<?php echo $ev['id']; ?>" class="table-action-icon action-primary" title="Consulter les ventes & recettes" aria-label="Ventes">
                                            <i class="fa-solid fa-chart-pie"></i>
                                        </a>

                                        <!-- Clôturer / Réactiver -->
                                        <?php if ($is_actif): ?>
                                            <form method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Voulez-vous clôturer la billetterie de « <?php echo htmlspecialchars(addslashes($ev['nom'])); ?> » ?');">
                                                <input type="hidden" name="action_cloturer" value="1">
                                                <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                                <button type="submit" class="table-action-icon action-danger" title="Clôturer la billetterie" aria-label="Clôturer">
                                                    <i class="fa-solid fa-power-off"></i>
                                                </button>
                                            </form>
                                        <?php elseif ($ev['statut'] === 'termine'): ?>
                                            <form method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Réactiver cet événement et rouvrir sa billetterie ?');">
                                                <input type="hidden" name="action_reactiver" value="1">
                                                <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                                <button type="submit" class="table-action-icon action-success" title="Réactiver la billetterie" aria-label="Réactiver">
                                                    <i class="fa-solid fa-play"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- B. VUE CARTES MOBILES TACTILES (ÉCRANS SMARTPHONES & PETITES TABLETTES) -->
            <div class="events-mobile-cards">
                <?php foreach ($all_events as $ev): ?>
                    <?php 
                        $vendus   = (int)$ev['tickets_vendus'];
                        $total    = (int)$ev['total_places'];
                        $restants = max(0, $total - $vendus);
                        $pct      = ($total > 0) ? round(($vendus / $total) * 100) : 0;
                        $is_actif = in_array($ev['statut'], ['actif', 'approuve'], true);
                        $is_soldout = ($total > 0 && $restants === 0);

                        $img_src = get_event_display_image($ev);
                        $type_label = '';
                        if ($ev['type_vote'] === 'concours') $type_label = 'Concours';
                        elseif ($ev['type_vote'] === 'realisation_evenement') $type_label = 'Vote Réalisation';
                        else $type_label = 'Billetterie';
                    ?>
                    <div style="background: #ffffff; border: 1px solid var(--dash-border); border-radius: 12px; padding: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 0.75rem;">
                        <!-- Top Row : Affiche + Titre + Badges + Statut -->
                        <div style="display: flex; gap: 0.85rem; align-items: flex-start;">
                            <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($ev['nom']); ?>" 
                                 class="event-thumb" 
                                 style="width: 72px; height: 72px; border-radius: 10px; object-fit: cover;"
                                 onerror="this.onerror=null; this.src='../uploads/events/default.jpg';">
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; margin-bottom: 4px;">
                                    <strong style="color: var(--dash-text); font-size: 0.96rem; font-weight: 800; line-height: 1.25; word-break: break-word;">
                                        <?php echo htmlspecialchars($ev['nom']); ?>
                                    </strong>
                                    <!-- Badge Statut -->
                                    <?php if ($ev['statut'] === 'termine'): ?>
                                        <span style="background: #E5E5E5; color: #737373; padding: 2px 7px; border-radius: 6px; font-weight: 700; font-size: 0.7rem; white-space: nowrap; flex-shrink: 0;">
                                            Terminé
                                        </span>
                                    <?php elseif ($is_soldout): ?>
                                        <span style="background: #F5F5F5; color: #000000; padding: 2px 7px; border-radius: 6px; font-weight: 700; font-size: 0.7rem; white-space: nowrap; flex-shrink: 0;">
                                            Complet
                                        </span>
                                    <?php elseif ($is_actif): ?>
                                        <span style="background: #FFF2ED; color: #000000; padding: 2px 7px; border-radius: 6px; font-weight: 700; font-size: 0.7rem; white-space: nowrap; flex-shrink: 0;">
                                            En vente
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #F5F5F5; color: var(--dash-muted); padding: 2px 7px; border-radius: 6px; font-weight: 700; font-size: 0.7rem; white-space: nowrap; flex-shrink: 0;">
                                            <?php echo htmlspecialchars(ucfirst($ev['statut'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Badges catégorie / type / agents -->
                                <div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap; margin-top: 4px;">
                                    <span style="background: #F5F5F5; color: var(--dash-text); padding: 1px 6px; border-radius: 4px; font-weight: 600; font-size: 0.7rem;">
                                        <?php echo htmlspecialchars($ev['categorie']); ?>
                                    </span>
                                    <span style="background: #FFF2ED; color: #FF4A0D; padding: 1px 6px; border-radius: 4px; font-weight: 700; font-size: 0.7rem;">
                                        <?php echo $type_label; ?>
                                    </span>
                                    <?php if ($ev['nb_agents'] > 0): ?>
                                        <span style="color: #FF4A0D; font-size: 0.72rem; font-weight: 700;">
                                            <i class="fa-solid fa-shield-halved"></i> <?php echo $ev['nb_agents']; ?> agent(s)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Date, Heure & Lieu -->
                        <div style="background: #F5F5F5; border-radius: 8px; padding: 8px 10px; font-size: 0.8rem; display: flex; flex-direction: column; gap: 4px;">
                            <div style="color: var(--dash-text); font-weight: 700; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-regular fa-calendar" style="color: var(--dash-primary);"></i>
                                <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?> à <?php echo substr($ev['heure'], 0, 5); ?>
                            </div>
                            <div style="color: var(--dash-muted); font-size: 0.76rem; display: flex; align-items: center; gap: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <i class="fa-solid fa-location-dot" style="color: #737373;"></i>
                                <?php echo htmlspecialchars($ev['lieu']); ?>
                            </div>
                        </div>

                        <!-- Jauge Billetterie & Remplissage -->
                        <div style="padding: 2px 2px;">
                            <div style="display: flex; justify-content: space-between; align-items: baseline; font-size: 0.8rem; margin-bottom: 3px;">
                                <span style="font-weight: 700; color: #FF4A0D;">
                                    <?php echo $vendus; ?> vendu(s)
                                </span>
                                <span style="color: var(--dash-muted); font-size: 0.74rem;">
                                    <?php echo $restants; ?> restant(s) / <?php echo $total; ?>
                                </span>
                            </div>
                            <div class="event-progress-bar" style="height: 6px;">
                                <div class="event-progress-fill" style="width: <?php echo min(100, $pct); ?>%; background: <?php echo $is_soldout ? '#ef4444' : ($pct > 80 ? '#f59e0b' : '#10b981'); ?>;"></div>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 3px;">
                                <small style="color: var(--dash-muted); font-size: 0.72rem;">Rempli à <?php echo $pct; ?>%</small>
                                <?php if ($ev['total_scans'] > 0): ?>
                                    <small style="color: #10b981; font-size: 0.72rem; font-weight: 700;">
                                        <i class="fa-solid fa-qrcode"></i> <?php echo $ev['total_scans']; ?> scanné(s)
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Recettes & Commission -->
                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--dash-border); padding-top: 8px;">
                            <span style="font-size: 0.76rem; color: var(--dash-muted); font-weight: 600;">Recettes Brutes :</span>
                            <strong style="font-size: 1.05rem; color: #10b981; font-weight: 800;">
                                <?php echo number_format($ev['total_recette'], 0, ',', ' '); ?> F
                            </strong>
                        </div>

                        <!-- Barre d'actions tactiles -->
                        <div class="card-actions-grid">
                            <a href="../client/accueil.php" target="_blank" class="card-action-btn" title="Voir côté public" aria-label="Voir côté public">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                <span>Voir</span>
                            </a>
                            <button type="button" class="card-action-btn" title="Modifier l'événement" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($ev)); ?>)" aria-label="Modifier">
                                <i class="fa-solid fa-pen-to-square"></i>
                                <span>Modifier</span>
                            </button>
                            <a href="agents.php?event_id=<?php echo $ev['id']; ?>" class="card-action-btn" title="Gérer les agents" aria-label="Agents">
                                <i class="fa-solid fa-shield-halved"></i>
                                <span>Agents</span>
                            </a>
                            <a href="mes-ventes.php?event_id=<?php echo $ev['id']; ?>" class="card-action-btn action-primary" title="Consulter les ventes" aria-label="Ventes">
                                <i class="fa-solid fa-chart-pie"></i>
                                <span>Ventes</span>
                            </a>
                            <?php if ($is_actif): ?>
                                <form method="POST" style="margin: 0; width: 100%; display: flex;" onsubmit="return confirm('Voulez-vous clôturer la billetterie de « <?php echo htmlspecialchars(addslashes($ev['nom'])); ?> » ?');">
                                    <input type="hidden" name="action_cloturer" value="1">
                                    <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                    <button type="submit" class="card-action-btn action-danger" title="Clôturer la billetterie" aria-label="Clôturer">
                                        <i class="fa-solid fa-power-off"></i>
                                        <span>Clôturer</span>
                                    </button>
                                </form>
                            <?php elseif ($ev['statut'] === 'termine'): ?>
                                <form method="POST" style="margin: 0; width: 100%; display: flex;" onsubmit="return confirm('Réactiver cet événement et rouvrir sa billetterie ?');">
                                    <input type="hidden" name="action_reactiver" value="1">
                                    <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                    <button type="submit" class="card-action-btn action-success" title="Réactiver la billetterie" aria-label="Réactiver">
                                        <i class="fa-solid fa-play"></i>
                                        <span>Rouvrir</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="card-action-btn" style="opacity: 0.4; cursor: default;">
                                    <i class="fa-solid fa-lock"></i>
                                    <span>Fermé</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
                <strong style="display: block; font-size: 1rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucun événement trouvé</strong>
                <p style="font-size: 0.82rem; margin: 0 0 1rem;">Vous n'avez aucun événement correspondant aux filtres sélectionnés.</p>
                <a href="demande-evenement.php" class="dash-btn-action btn-primary" style="display: inline-flex; text-decoration: none;">
                    <i class="fa-solid fa-plus"></i> Proposer un Événement
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==============================================================================
     MODAL DE MODIFICATION D'UN ÉVÉNEMENT
     ============================================================================== -->
<div id="modalEditEvent" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 540px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">
        <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.1rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-pen-to-square" style="color: var(--dash-primary);"></i> Modifier l'Événement
            </h3>
            <button type="button" onclick="closeEditModal()" style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="mes-evenements.php" enctype="multipart/form-data" style="padding: 1.5rem; overflow-y: auto;">
            <input type="hidden" name="action_modifier" value="1">
            <input type="hidden" name="event_id" id="edit_event_id" value="">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Nom / Titre de l'événement *
                </label>
                <input type="text" name="nom" id="edit_nom" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        Date de l'événement *
                    </label>
                    <input type="date" name="date_evenement" id="edit_date" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                        Heure de début *
                    </label>
                    <input type="time" name="heure" id="edit_heure" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
                </div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Lieu / Salle *
                </label>
                <input type="text" name="lieu" id="edit_lieu" required style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Description du programme
                </label>
                <textarea name="description" id="edit_desc" rows="4" style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;"></textarea>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Changer l'affiche (Optionnel)
                </label>
                <input type="file" name="image" accept="image/*" style="width: 100%; font-size: 0.82rem;">
                <small style="color: var(--dash-muted); font-size: 0.72rem; display: block; margin-top: 3px;">Format JPG, PNG ou WEBP conseillé.</small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--dash-border);">
                <button type="button" onclick="closeEditModal()" class="dash-btn-action" style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary" style="padding: 0.55rem 1.25rem;">
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(eventData) {
    document.getElementById('edit_event_id').value = eventData.id;
    document.getElementById('edit_nom').value = eventData.nom;
    document.getElementById('edit_date').value = eventData.date_evenement;
    document.getElementById('edit_heure').value = (eventData.heure || '').substring(0, 5);
    document.getElementById('edit_lieu').value = eventData.lieu;
    document.getElementById('edit_desc').value = eventData.description || '';
    document.getElementById('modalEditEvent').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('modalEditEvent').style.display = 'none';
}

window.addEventListener('click', function(e) {
    const m = document.getElementById('modalEditEvent');
    if (e.target === m) closeEditModal();
});
</script>

<?php include 'footer.php'; ?>
