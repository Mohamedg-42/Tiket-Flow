<?php
// ==============================================================================
// GESTION DES CONCOURS & VOTES (promoteur/votes.php)
// Design Dashboard Pro - Filtres avancés en haut, classement des candidats en direct & recettes
// ==============================================================================

$page_title = "Gestion des Concours & Votes - Espace Promoteur";
include 'header.php';

$user_id = (int) $_SESSION['user_id'];
$message = "";
$msg_type = "";

// ------------------------------------------------------------------------------
// 1. Traitement : Ajout d'un candidat à un concours
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_candidat'])) {
    $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $nom = trim($_POST['nom'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    // Vérifier que l'événement appartient bien au promoteur
    $stmt_chk = $pdo->prepare("SELECT id FROM events WHERE id = ? AND user_id = ?");
    $stmt_chk->execute([$event_id, $user_id]);
    $ev_ok = $stmt_chk->fetch();

    if (!$ev_ok) {
        $message = "Événement ou concours non valide ou non autorisé.";
        $msg_type = "error";
    } elseif (empty($nom)) {
        $message = "Le nom du candidat ou participant est obligatoire.";
        $msg_type = "error";
    } else {
        // Upload photo
        $photo_name = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $upload_dir = '../uploads/candidats/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $photo_name = 'candidat_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $photo_name);
            }
        }

        $stmt_ins = $pdo->prepare("
            INSERT INTO event_candidats (event_id, nom, description, photo, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt_ins->execute([$event_id, $nom, $desc ?: null, $photo_name]);

        $message = "Le candidat « " . htmlspecialchars($nom) . " » a été ajouté avec succès au concours !";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 2. Traitement : Modification d'un candidat
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_candidat'])) {
    $candidat_id = filter_input(INPUT_POST, 'candidat_id', FILTER_VALIDATE_INT);
    $nom = trim($_POST['nom'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    // Vérifier autorisation
    $stmt_chk = $pdo->prepare("
        SELECT c.id, c.photo, c.event_id 
        FROM event_candidats c 
        JOIN events e ON c.event_id = e.id 
        WHERE c.id = ? AND e.user_id = ?
    ");
    $stmt_chk->execute([$candidat_id, $user_id]);
    $c_row = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    if (!$c_row) {
        $message = "Candidat introuvable ou non autorisé.";
        $msg_type = "error";
    } elseif (empty($nom)) {
        $message = "Le nom du candidat ne peut pas être vide.";
        $msg_type = "error";
    } else {
        $photo_name = $c_row['photo'];
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $upload_dir = '../uploads/candidats/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $photo_name = 'candidat_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $photo_name);
            }
        }

        $stmt_upd = $pdo->prepare("
            UPDATE event_candidats 
            SET nom = ?, description = ?, photo = ? 
            WHERE id = ?
        ");
        $stmt_upd->execute([$nom, $desc ?: null, $photo_name, $candidat_id]);

        $message = "Les informations de « " . htmlspecialchars($nom) . " » ont été mises à jour.";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 3. Traitement : Suppression d'un candidat
// ------------------------------------------------------------------------------
if (isset($_GET['delete_candidat'])) {
    $del_id = filter_input(INPUT_GET, 'delete_candidat', FILTER_VALIDATE_INT);
    $stmt_chk = $pdo->prepare("
        SELECT c.id, c.nom 
        FROM event_candidats c 
        JOIN events e ON c.event_id = e.id 
        WHERE c.id = ? AND e.user_id = ?
    ");
    $stmt_chk->execute([$del_id, $user_id]);
    $c_del = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    if ($c_del) {
        $pdo->prepare("DELETE FROM event_candidats WHERE id = ?")->execute([$del_id]);
        $message = "Le candidat « " . htmlspecialchars($c_del['nom']) . " » a été retiré de la compétition.";
        $msg_type = "success";
    }
}

// ------------------------------------------------------------------------------
// 4. Filtres Avancés (Typologie, Concours, Statut, Période, Recherche)
// ------------------------------------------------------------------------------
$type_filter = $_GET['type'] ?? 'tous';
if (!in_array($type_filter, ['tous', 'concours', 'realisation', 'payant', 'gratuit'], true)) {
    $type_filter = 'tous';
}

$statut_filter = $_GET['statut'] ?? 'tous';
if (!in_array($statut_filter, ['tous', 'actif', 'termine'], true)) {
    $statut_filter = 'tous';
}

$periode = $_GET['periode'] ?? 'toutes';
if (!in_array($periode, ['toutes', '7_jours', '30_jours', 'ce_mois', 'cette_annee'], true)) {
    $periode = 'toutes';
}

$filter_event = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$search_q = trim($_GET['q'] ?? '');

// Liste complète de tous les concours/événements pour le sélecteur
$stmt_all_evs = $pdo->prepare("
    SELECT id, nom, type_vote 
    FROM events 
    WHERE user_id = ? 
      AND (type_vote IS NOT NULL OR categorie IN ('concours', 'vote') OR prix_vote > 0)
    ORDER BY nom ASC
");
$stmt_all_evs->execute([$user_id]);
$all_promoter_events = $stmt_all_evs->fetchAll(PDO::FETCH_ASSOC);

// Construction de la requête avec tous les filtres
$sql_events = "
    SELECT e.*,
           (SELECT COUNT(*) FROM event_votes ev WHERE ev.event_id = e.id) AS total_votes,
           (SELECT COUNT(*) FROM event_candidats ec WHERE ec.event_id = e.id) AS total_candidats,
           (SELECT COALESCE(SUM(vp.montant), 0) FROM vote_paiements vp WHERE vp.event_id = e.id AND vp.statut = 'paye') AS recettes_votes
    FROM events e
    WHERE e.user_id = ? 
      AND (e.type_vote IS NOT NULL OR e.categorie IN ('concours', 'vote') OR e.prix_vote > 0)
";
$params_events = [$user_id];

if ($filter_event) {
    $sql_events .= " AND e.id = ?";
    $params_events[] = $filter_event;
}

if ($type_filter === 'concours') {
    $sql_events .= " AND e.type_vote = 'concours'";
} elseif ($type_filter === 'realisation') {
    $sql_events .= " AND e.type_vote = 'realisation_evenement'";
} elseif ($type_filter === 'payant') {
    $sql_events .= " AND e.prix_vote > 0";
} elseif ($type_filter === 'gratuit') {
    $sql_events .= " AND (e.prix_vote = 0 OR e.prix_vote IS NULL)";
}

if ($statut_filter === 'actif') {
    $sql_events .= " AND e.statut IN ('actif', 'approuve')";
} elseif ($statut_filter === 'termine') {
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
    $sql_events .= " AND (e.nom LIKE ? OR e.vote_question LIKE ? OR EXISTS (SELECT 1 FROM event_candidats ec2 WHERE ec2.event_id = e.id AND (ec2.nom LIKE ? OR ec2.description LIKE ?)))";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
    $params_events[] = "%$search_q%";
}

$sql_events .= " ORDER BY (e.statut = 'actif' OR e.statut = 'approuve') DESC, e.date_evenement DESC";

$stmt_evs = $pdo->prepare($sql_events);
$stmt_evs->execute($params_events);
$vote_events = $stmt_evs->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------------------------
// 5. Calculs KPI Globaux (selon filtrage courant)
// ------------------------------------------------------------------------------
$kpi_nb_concours = count($vote_events);
$kpi_total_votes = array_sum(array_column($vote_events, 'total_votes'));
$kpi_total_candidats = array_sum(array_column($vote_events, 'total_candidats'));
$kpi_recettes_votes = array_sum(array_column($vote_events, 'recettes_votes'));
?>

<link rel="stylesheet" href="../Css/dashboard-pro.css">

<style>
    .candidat-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.9rem 1.1rem;
        background: #F5F5F5;
        border: 1px solid var(--dash-border);
        border-radius: 12px;
        margin-bottom: 0.75rem;
        transition: all 0.2s ease;
    }

    .candidat-card:hover {
        background: #ffffff;
        border-color: var(--dash-primary);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }

    .candidat-card-left {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        flex: 1;
        min-width: 0;
    }

    .candidat-card-right {
        display: flex;
        align-items: center;
        gap: 1.1rem;
        flex-shrink: 0;
    }

    .candidat-photo {
        width: 52px;
        height: 52px;
        border-radius: 10px;
        object-fit: cover;
        border: 1px solid var(--dash-border);
        background: #E5E5E5;
        flex-shrink: 0;
    }

    .rank-badge {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.85rem;
        flex-shrink: 0;
    }

    .rank-1 {
        background: #FFF2ED;
        color: #FF4A0D;
        border: 1px solid #E5E5E5;
    }

    .rank-2 {
        background: #E5E5E5;
        color: #737373;
        border: 1px solid #E5E5E5;
    }

    .rank-3 {
        background: #FFF2ED;
        color: #FF4A0D;
        border: 1px solid #E5E5E5;
    }

    .rank-other {
        background: #F5F5F5;
        color: var(--dash-muted);
    }

    @media (max-width: 820px) {
        .candidat-card {
            flex-direction: column;
            align-items: stretch;
            padding: 0.85rem;
            gap: 0.75rem;
        }
        .candidat-card-left {
            width: 100%;
        }
        .candidat-card-right {
            width: 100%;
            justify-content: space-between;
            border-top: 1px solid var(--dash-border, #E5E5E5);
            padding-top: 0.65rem;
            flex-wrap: wrap;
            gap: 0.6rem;
        }
    }
</style>

<div class="dash-container">
    <!-- ==============================================================================
         1. EN-TÊTE DASHBOARD PRO
         ============================================================================== -->
    <div class="dash-header-section" style="margin-bottom: 1.25rem;">
        <div class="dash-title-box">
            <h1>
                <i class="fa-solid fa-trophy" style="color: #FF4A0D; font-size: 1.55rem;"></i>
                Gestion des Concours & Votes
            </h1>
            <p>Supervisez vos compétitions, suivez le classement des candidats en direct et encaissez les recettes des
                votes en temps réel.</p>
        </div>

        <div style="display: flex; gap: 0.65rem; flex-wrap: wrap; align-items: center;">
            <a href="export.php?type=votes&event_id=<?php echo $filter_event ? (int)$filter_event : ''; ?>&type_filter=<?php echo urlencode($type_filter); ?>&statut=<?php echo urlencode($statut_filter); ?>&periode=<?php echo urlencode($periode); ?>&q=<?php echo urlencode($search_q); ?>" class="dash-btn-action" style="padding: 0.6rem 1.15rem; text-decoration: none;" title="Exporter les concours, candidats et votes sur Excel (CSV)">
                <i class="fa-solid fa-file-excel" style="color: #FF4A0D;"></i> Exporter Excel
            </a>
            <button type="button" onclick="openAddCandidatModal()" class="eventia-btn-primary"
                style="padding: 0.6rem 1.15rem; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                <i class="fa-solid fa-user-plus"></i> Inscrire un Candidat
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="eventia-alert eventia-alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>" style="margin-bottom: 1.25rem;">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
         2. BARRE DE FILTRES AVANCÉS SUR LA MÊME LIGNE (POSITIONNÉE TOUT EN HAUT)
         ============================================================================== -->
    <div
        style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; background: #ffffff; padding: 0.65rem 0.85rem; border-radius: 12px; border: 1px solid var(--eventia-border, #E5E5E5); box-shadow: 0 1px 3px rgba(0,0,0,0.02); flex-wrap: wrap;">
        <!-- À GAUCHE : ONGLETS DE TYPOLOGIE -->
        <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
            <a href="?type=tous&statut=<?php echo urlencode($statut_filter); ?>&periode=<?php echo urlencode($periode); ?>&event_id=<?php echo $filter_event ? (int) $filter_event : ''; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'tous' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-list"></i> Tous
            </a>

            <a href="?type=concours&statut=<?php echo urlencode($statut_filter); ?>&periode=<?php echo urlencode($periode); ?>&event_id=<?php echo $filter_event ? (int) $filter_event : ''; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'concours' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-trophy" style="color: var(--eventia-amber-dark, #FF4A0D);"></i> Concours
            </a>

            <a href="?type=realisation&statut=<?php echo urlencode($statut_filter); ?>&periode=<?php echo urlencode($periode); ?>&event_id=<?php echo $filter_event ? (int) $filter_event : ''; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'realisation' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-check-to-slot" style="color: var(--eventia-navy-light, #000000);"></i> Réalisation
            </a>

            <a href="?type=payant&statut=<?php echo urlencode($statut_filter); ?>&periode=<?php echo urlencode($periode); ?>&event_id=<?php echo $filter_event ? (int) $filter_event : ''; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'payant' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-coins" style="color: var(--eventia-amber-dark, #FF4A0D);"></i> Payants
            </a>

            <a href="?type=gratuit&statut=<?php echo urlencode($statut_filter); ?>&periode=<?php echo urlencode($periode); ?>&event_id=<?php echo $filter_event ? (int) $filter_event : ''; ?>&q=<?php echo urlencode($search_q); ?>"
                class="dash-chart-tab <?php echo $type_filter === 'gratuit' ? 'active' : ''; ?>"
                style="text-decoration: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-gift" style="color: var(--tikeli-orange, #FF4A0D);"></i> Gratuits
            </a>
        </div>

        <!-- À DROITE : SÉLECTEURS CONCOURS, STATUT, PÉRIODE & RECHERCHE -->
        <form method="GET" style="display: inline-flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter ?? ''); ?>">
            <input type="hidden" name="statut" value="<?php echo htmlspecialchars($statut_filter ?? ''); ?>">
            <input type="hidden" name="periode" value="<?php echo htmlspecialchars($periode ?? ''); ?>">
            <input type="hidden" name="event_id" value="<?php echo htmlspecialchars((string) ($filter_event ?? '')); ?>">

            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--eventia-muted, #737373); font-size: 0.8rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q ?? ''); ?>" placeholder="Rechercher..." style="padding: 0.4rem 0.75rem 0.4rem 2rem; border-radius: 8px; border: 1px solid var(--eventia-border, #E5E5E5); font-size: 0.82rem; width: 140px; background: #ffffff;">
            </div>

            <button type="submit" class="eventia-btn-secondary"
                style="padding: 0.4rem 0.85rem; font-size: 0.82rem;">
                Filtrer
            </button>

            <?php if ($type_filter !== 'tous' || $statut_filter !== 'tous' || $periode !== 'toutes' || $filter_event || $search_q !== ''): ?>
                <a href="votes.php"
                    style="color: var(--eventia-danger, #000000); font-size: 0.78rem; text-decoration: underline; margin-left: 2px;">Effacer</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================================
         3. KPI CARDS : SYNTHÈSE CALCULÉE EN DIRECT (AU-DESSOUS DU FILTRE)
         ============================================================================== -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <div class="eventia-kpi-card" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; width: 100%;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--tikeli-orange, #FF4A0D); text-transform: uppercase; letter-spacing: 0.3px;">Concours Filtrés</span>
                    <span style="background: rgba(255, 74, 13, 0.15); color: var(--tikeli-orange, #FF4A0D); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-trophy"></i></span>
                </div>
                <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--eventia-navy, #000000); line-height: 1.1; margin-bottom: 0.25rem;"><?php echo $kpi_nb_concours; ?></div>
            </div>
            <small style="color: var(--eventia-muted, #737373); font-size: 0.75rem; margin-top: 2px;">Compétitions correspondantes</small>
        </div>

        <div class="eventia-kpi-card" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; width: 100%;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--eventia-muted, #737373); text-transform: uppercase; letter-spacing: 0.3px;">Suffrages Exprimés</span>
                    <span style="background: rgba(11, 29, 58, 0.08); color: var(--eventia-navy, #000000); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-check-to-slot"></i></span>
                </div>
                <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--eventia-navy, #000000); line-height: 1.1; margin-bottom: 0.25rem;"><?php echo number_format($kpi_total_votes, 0, ',', ' '); ?></div>
            </div>
            <small style="color: var(--eventia-muted, #737373); font-size: 0.75rem; margin-top: 2px;">Votes enregistrés au total</small>
        </div>

        <div class="eventia-kpi-card" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; width: 100%;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--eventia-muted, #737373); text-transform: uppercase; letter-spacing: 0.3px;">Candidats Inscrits</span>
                    <span style="background: rgba(11, 29, 58, 0.08); color: var(--eventia-navy, #000000); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-users"></i></span>
                </div>
                <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--eventia-navy, #000000); line-height: 1.1; margin-bottom: 0.25rem;"><?php echo $kpi_total_candidats; ?></div>
            </div>
            <small style="color: var(--eventia-muted, #737373); font-size: 0.75rem; margin-top: 2px;">Participants en lice</small>
        </div>

        <div class="eventia-kpi-card" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; width: 100%;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--tikeli-orange, #FF4A0D); text-transform: uppercase; letter-spacing: 0.3px;">Recettes des Votes</span>
                    <span style="background: rgba(255, 74, 13, 0.12); color: var(--tikeli-orange, #FF4A0D); width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center; font-size: 0.85rem;"><i class="fa-solid fa-coins"></i></span>
                </div>
                <div style="font-size: 1.75rem; font-family: var(--font-heading, 'Outfit', sans-serif); font-weight: 800; color: var(--tikeli-orange, #FF4A0D); line-height: 1.1; margin-bottom: 0.25rem; white-space: nowrap;"><?php echo number_format($kpi_recettes_votes, 0, ',', ' '); ?> <span style="font-size: 0.95rem; font-weight: 700;">FCFA</span></div>
            </div>
            <small style="color: var(--tikeli-orange, #FF4A0D); font-size: 0.75rem; font-weight: 600; margin-top: 2px;">Total encaissé Mobile Money</small>
        </div>
    </div>

    <!-- ==============================================================================
         4. CLASSEMENT & PARTICIPANTS PAR ÉVÉNEMENT
         ============================================================================== -->
    <?php if (count($vote_events) > 0): ?>
        <div style="display: flex; flex-direction: column; gap: 1.75rem;">
            <?php foreach ($vote_events as $ev): ?>
                <?php
                // Récupérer les candidats de cet événement avec leur nombre de votes
                $stmt_cand = $pdo->prepare("
                    SELECT c.*,
                           (SELECT COUNT(*) FROM event_votes ev WHERE ev.candidat_id = c.id) AS nb_votes,
                           (SELECT COALESCE(SUM(vp.montant), 0) FROM vote_paiements vp WHERE vp.candidat_id = c.id AND vp.statut = 'paye') AS recettes_candidat
                    FROM event_candidats c
                    WHERE c.event_id = ?
                    ORDER BY nb_votes DESC, c.id ASC
                ");
                $stmt_cand->execute([$ev['id']]);
                $candidats = $stmt_cand->fetchAll(PDO::FETCH_ASSOC);

                $total_votes_event = (int) $ev['total_votes'];
                $prix_vote = (float) $ev['prix_vote'];
                $type_vote = $ev['type_vote'] ?? 'concours';
                ?>
                <div class="dash-card" style="padding: 1.5rem;">
                    <!-- En-tête de l'événement -->
                    <div
                        style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem; border-bottom: 1px solid var(--dash-border); padding-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span
                                    style="background: <?php echo $type_vote === 'concours' ? '#fef9c3' : '#e0f2fe'; ?>; color: <?php echo $type_vote === 'concours' ? '#ca8a04' : '#0284c7'; ?>; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 0.72rem; text-transform: uppercase;">
                                    <?php echo $type_vote === 'concours' ? 'Concours de Talents' : 'Vote Réalisation'; ?>
                                </span>
                                <span style="color: var(--dash-muted); font-size: 0.78rem;">
                                    <i class="fa-regular fa-calendar"></i>
                                    <?php echo date('d/m/Y', strtotime($ev['date_evenement'])); ?>
                                </span>
                            </div>

                            <h2
                                style="margin: 0.35rem 0 0.25rem; color: var(--dash-text); font-size: 1.25rem; font-weight: 800;">
                                <?php echo htmlspecialchars($ev['nom']); ?>
                            </h2>

                            <?php if (!empty($ev['vote_question'])): ?>
                                <p style="margin: 0; color: var(--dash-muted); font-size: 0.84rem;">
                                    <i class="fa-solid fa-circle-question" style="color: var(--dash-primary);"></i>
                                    <strong>Question :</strong> « <?php echo htmlspecialchars($ev['vote_question']); ?> »
                                </p>
                            <?php endif; ?>
                        </div>

                        <div style="display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap;">
                            <div style="text-align: right;">
                                <span style="font-size: 0.75rem; color: var(--dash-muted); display: block;">Prix du Vote</span>
                                <strong style="font-size: 1.05rem; color: var(--dash-text);">
                                    <?php echo $prix_vote > 0 ? number_format($prix_vote, 0, ',', ' ') . ' FCFA' : '<span style="color: #10b981;">Gratuit</span>'; ?>
                                </strong>
                            </div>

                            <div style="text-align: right; border-left: 1px solid var(--dash-border); padding-left: 1.25rem;">
                                <span style="font-size: 0.75rem; color: var(--dash-muted); display: block;">Total
                                    Recettes</span>
                                <strong style="font-size: 1.2rem; color: #059669; font-weight: 800;">
                                    <?php echo number_format((float) $ev['recettes_votes'], 0, ',', ' '); ?> F
                                </strong>
                            </div>

                            <button type="button" onclick="openPromoterShare(<?php echo (int) $ev['id']; ?>, '<?php echo htmlspecialchars(addslashes($ev['nom'])); ?>')"
                                class="dash-btn-action"
                                style="padding: 0.45rem 0.85rem; font-size: 0.8rem; background: #FFF2ED; color: #EA580C; border: 1px solid #FFEDD5; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-share-nodes"></i> Partager le lien public
                            </button>

                            <button type="button" onclick="openAddCandidatModal(<?php echo $ev['id']; ?>)"
                                class="dash-btn-action"
                                style="padding: 0.45rem 0.85rem; font-size: 0.8rem; background: var(--dash-primary); color: #ffffff;">
                                <i class="fa-solid fa-plus"></i> Ajouter Candidat
                            </button>
                        </div>
                    </div>

                    <!-- Classement des Candidats -->
                    <h4
                        style="margin: 0 0 0.85rem; font-size: 0.92rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-ranking-star" style="color: #FF4A0D;"></i>
                        Classement en direct (<?php echo count($candidats); ?>
                        candidat<?php echo count($candidats) > 1 ? 's' : ''; ?>)
                    </h4>

                    <?php if (count($candidats) > 0): ?>
                        <div style="display: flex; flex-direction: column;">
                            <?php
                            $rank = 1;
                            foreach ($candidats as $c):
                                $nb_v = (int) $c['nb_votes'];
                                $pct = $total_votes_event > 0 ? round(($nb_v / $total_votes_event) * 100, 1) : 0;
                                $rank_class = ($rank === 1) ? 'rank-1' : (($rank === 2) ? 'rank-2' : (($rank === 3) ? 'rank-3' : 'rank-other'));
                                $photo_url = !empty($c['photo']) 
                                    ? ((strpos($c['photo'], 'http://') === 0 || strpos($c['photo'], 'https://') === 0) ? htmlspecialchars($c['photo']) : '../uploads/candidats/' . htmlspecialchars($c['photo'])) 
                                    : '../images/default-avatar.png';
                                ?>
                                <div class="candidat-card">
                                    <div class="candidat-card-left">
                                        <!-- Rang -->
                                        <div class="rank-badge <?php echo $rank_class; ?>">
                                            <?php echo ($rank === 1) ? '🥇' : (($rank === 2) ? '🥈' : (($rank === 3) ? '🥉' : '#' . $rank)); ?>
                                        </div>

                                        <!-- Photo -->
                                        <img src="<?php echo $photo_url; ?>" alt="Photo" class="candidat-photo"
                                            onerror="this.src='../images/default-avatar.png';">

                                        <!-- Info Candidat -->
                                        <div style="flex: 1; min-width: 0;">
                                            <strong style="color: var(--dash-text); font-size: 0.95rem; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?php echo htmlspecialchars($c['nom']); ?>
                                            </strong>
                                            <?php if (!empty($c['description'])): ?>
                                                <small
                                                    style="color: var(--dash-muted); font-size: 0.76rem; display: block; margin-top: 1px; line-height: 1.35;">
                                                    <?php echo htmlspecialchars(mb_strimwidth($c['description'], 0, 75, '...')); ?>
                                                </small>
                                            <?php endif; ?>

                                            <!-- Jauge de vote -->
                                            <div
                                                style="background: #E5E5E5; height: 6px; border-radius: 999px; overflow: hidden; margin-top: 6px; width: 100%; max-width: 320px;">
                                                <div
                                                    style="height: 100%; width: <?php echo $pct; ?>%; background: linear-gradient(90deg, #ca8a04, #eab308); border-radius: 999px;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Score & Actions -->
                                    <div class="candidat-card-right">
                                        <div style="text-align: right; min-width: 65px;">
                                            <strong
                                                style="color: var(--dash-text); font-size: 1.05rem; font-weight: 800; display: block;">
                                                <?php echo number_format($nb_v, 0, ',', ' '); ?> <span
                                                    style="font-size: 0.78rem; font-weight: 600; color: var(--dash-muted);">votes</span>
                                            </strong>
                                            <small style="color: #ca8a04; font-weight: 800; font-size: 0.78rem; white-space: nowrap;"><?php echo $pct; ?>%
                                                des voix</small>
                                        </div>

                                        <?php if ($prix_vote > 0): ?>
                                            <div style="text-align: right; border-left: 1px solid var(--dash-border); padding-left: 0.75rem;">
                                                <span style="font-size: 0.72rem; color: var(--dash-muted); display: block;">Recette</span>
                                                <strong style="color: #059669; font-size: 0.92rem; font-weight: 700; white-space: nowrap;">
                                                    <?php echo number_format((float) $c['recettes_candidat'], 0, ',', ' '); ?> F
                                                </strong>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Boutons Partager / Modifier / Supprimer -->
                                        <div style="display: flex; gap: 5px; margin-left: auto;">
                                            <button type="button" class="dash-btn-action"
                                                style="padding: 0.35rem 0.65rem; font-size: 0.74rem; color: #EA580C; background: #FFF2ED; border: 1px solid #FFEDD5; display: inline-flex; align-items: center; gap: 4px; font-weight: 700;"
                                                onclick="openPromoterShareCandidate(<?php echo (int) $ev['id']; ?>, '<?php echo htmlspecialchars(addslashes($ev['nom'])); ?>', <?php echo (int) $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['nom'])); ?>')"
                                                title="Partager le lien direct de ce candidat">
                                                <i class="fa-solid fa-share-nodes"></i> Partager
                                            </button>
                                            <button type="button" class="dash-btn-action"
                                                style="padding: 0.35rem 0.65rem; font-size: 0.74rem;"
                                                onclick="openEditCandidatModal(<?php echo htmlspecialchars(json_encode($c)); ?>)"
                                                title="Modifier ce candidat">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <a href="votes.php?delete_candidat=<?php echo $c['id']; ?>" class="dash-btn-action"
                                                style="padding: 0.35rem 0.65rem; font-size: 0.74rem; color: #000000;"
                                                onclick="return confirm('Confirmez-vous le retrait de « <?php echo htmlspecialchars(addslashes($c['nom'])); ?> » du concours ?');"
                                                title="Supprimer">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                $rank++;
                            endforeach;
                            ?>
                        </div>
                    <?php else: ?>
                        <div
                            style="text-align: center; padding: 2rem 1rem; color: var(--dash-muted); background: #F5F5F5; border-radius: 10px; border: 1px dashed var(--dash-border);">
                            <i class="fa-solid fa-user-group"
                                style="font-size: 2rem; color: #E5E5E5; margin-bottom: 0.5rem; display: block;"></i>
                            Aucun candidat n'a encore été inscrit pour ce concours.<br>
                            <button type="button" onclick="openAddCandidatModal(<?php echo $ev['id']; ?>)"
                                class="dash-btn-action btn-primary"
                                style="margin-top: 0.75rem; padding: 0.4rem 0.85rem; font-size: 0.78rem;">
                                + Inscrire le premier candidat
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="dash-card" style="text-align: center; padding: 3.5rem 1rem; color: var(--dash-muted);">
            <i class="fa-solid fa-trophy"
                style="font-size: 2.75rem; color: #E5E5E5; margin-bottom: 0.75rem; display: block;"></i>
            <strong style="display: block; font-size: 1.05rem; color: var(--dash-text); margin-bottom: 0.25rem;">Aucun
                événement de vote ou concours ne correspond à vos filtres</strong>
            <p style="font-size: 0.84rem; margin: 0 0 1.25rem;">Modifiez vos critères de recherche ou réinitialisez les
                filtres pour afficher l'ensemble de vos compétitions.</p>
            <a href="votes.php" class="dash-btn-action btn-primary" style="display: inline-flex; text-decoration: none;">
                <i class="fa-solid fa-rotate-left"></i> Réinitialiser les Filtres
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- ==============================================================================
     MODAL : AJOUTER UN CANDIDAT
     ============================================================================== -->
<div id="modalAddCandidat"
    style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div
        style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden;">
        <div
            style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; background: #F5F5F5;">
            <h3
                style="margin: 0; font-size: 1.05rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-user-plus" style="color: var(--dash-primary);"></i> Inscrire un Candidat au
                Concours
            </h3>
            <button type="button" onclick="closeAddCandidatModal()"
                style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="votes.php" enctype="multipart/form-data" style="padding: 1.5rem;">
            <input type="hidden" name="action_add_candidat" value="1">

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Concours / Événement concerné *
                </label>
                <select name="event_id" id="add_candidat_event_id" required
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem; font-weight: 700;">
                    <?php foreach ($all_promoter_events as $ev): ?>
                        <option value="<?php echo $ev['id']; ?>">
                            <?php echo htmlspecialchars($ev['nom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Nom et Prénom du Candidat *
                </label>
                <input type="text" name="nom" required placeholder="Ex: Aïcha Koné, Numéro 04..."
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Description / Bio / Numéro de dossard
                </label>
                <textarea name="description" rows="2"
                    placeholder="Ex: Candidate #04 représentant la région des Lagunes..."
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;"></textarea>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Photo officielle du candidat
                </label>
                <input type="file" name="photo" accept="image/*" style="width: 100%; font-size: 0.82rem;">
            </div>

            <div
                style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--dash-border); padding-top: 1rem;">
                <button type="button" onclick="closeAddCandidatModal()" class="dash-btn-action"
                    style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary"
                    style="padding: 0.55rem 1.25rem; font-weight: 800;">
                    <i class="fa-solid fa-check"></i> Enregistrer le Candidat
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODAL : MODIFIER UN CANDIDAT
     ============================================================================== -->
<div id="modalEditCandidat"
    style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div
        style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden;">
        <div
            style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--dash-border); display: flex; justify-content: space-between; align-items: center; background: #F5F5F5;">
            <h3
                style="margin: 0; font-size: 1.05rem; color: var(--dash-text); font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-pen-to-square" style="color: var(--dash-primary);"></i> Modifier le Candidat
            </h3>
            <button type="button" onclick="closeEditCandidatModal()"
                style="border: 0; background: transparent; font-size: 1.2rem; color: var(--dash-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="votes.php" enctype="multipart/form-data" style="padding: 1.5rem;">
            <input type="hidden" name="action_edit_candidat" value="1">
            <input type="hidden" name="candidat_id" id="edit_candidat_id" value="">

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Nom et Prénom du Candidat *
                </label>
                <input type="text" name="nom" id="edit_candidat_nom" required
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Description / Bio / Détails
                </label>
                <textarea name="description" id="edit_candidat_desc" rows="2"
                    style="width: 100%; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--dash-border); font-size: 0.85rem;"></textarea>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label
                    style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--dash-text);">
                    Nouvelle photo (laisser vide pour conserver)
                </label>
                <input type="file" name="photo" accept="image/*" style="width: 100%; font-size: 0.82rem;">
            </div>

            <div
                style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--dash-border); padding-top: 1rem;">
                <button type="button" onclick="closeEditCandidatModal()" class="dash-btn-action"
                    style="padding: 0.55rem 1rem;">Annuler</button>
                <button type="submit" class="dash-btn-action btn-primary"
                    style="padding: 0.55rem 1.25rem; font-weight: 800;">
                    <i class="fa-solid fa-check"></i> Enregistrer les Modifications
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Partage Public Promoteur -->
<div id="modalPromoterShare" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closePromoterShareModal()"></div>
    <div class="dash-modal-dialog" style="max-width: 500px; z-index: 10001;">
        <div class="dash-modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--dash-border); padding: 1rem 1.25rem;">
            <h3 style="margin: 0; font-size: 1.15rem; color: var(--dash-text); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-share-nodes" style="color: #FF4A0D;"></i> Partager le lien du vote
            </h3>
            <button type="button" onclick="closePromoterShareModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--dash-muted);">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="dash-modal-body" style="padding: 1.25rem;">
            <p id="promoterShareTitle" style="font-weight: 700; color: var(--dash-text); margin: 0 0 0.5rem; font-size: 0.95rem;"></p>
            <p style="color: var(--dash-muted); font-size: 0.84rem; margin: 0 0 1rem; line-height: 1.45;">
                Diffusez ce lien public sur vos réseaux sociaux, affiches, ou envoyez-le directement par WhatsApp pour récolter un maximum de votes.
            </p>

            <div style="background: #F8FAFC; border: 1px solid var(--dash-border); border-radius: 8px; padding: 0.5rem; display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1.25rem;">
                <input type="text" id="promoterShareUrl" readonly style="flex: 1; border: none; background: transparent; font-family: 'Space Mono', monospace; font-size: 0.84rem; color: var(--dash-text); outline: none; padding: 0.35rem 0.5rem;">
                <button type="button" id="btnCopyPromoterLink" onclick="copyPromoterShareUrl()" class="dash-btn-action" style="background: #0F172A; color: #ffffff; padding: 0.45rem 0.85rem; font-size: 0.8rem; border-radius: 6px; white-space: nowrap;">
                    <i class="fa-regular fa-copy"></i> Copier
                </button>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <a id="btnPromoterWa" href="#" target="_blank" rel="noopener" class="dash-btn-action" style="justify-content: center; background: #25D366; color: #ffffff; text-decoration: none; padding: 0.65rem 1rem; font-size: 0.85rem; border-radius: 8px; font-weight: 700; border: none;">
                    <i class="fa-brands fa-whatsapp"></i> WhatsApp
                </a>
                <a id="btnPromoterPreview" href="#" target="_blank" class="dash-btn-action" style="justify-content: center; background: #F1F5F9; color: var(--dash-text); text-decoration: none; padding: 0.65rem 1rem; font-size: 0.85rem; border-radius: 8px; font-weight: 700; border: 1px solid var(--dash-border);">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Tester la page
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    function openAddCandidatModal(eventId) {
        if (eventId) {
            document.getElementById('add_candidat_event_id').value = eventId;
        }
        document.getElementById('modalAddCandidat').style.display = 'flex';
    }
    function closeAddCandidatModal() {
        document.getElementById('modalAddCandidat').style.display = 'none';
    }

    function openEditCandidatModal(cand) {
        document.getElementById('edit_candidat_id').value = cand.id;
        document.getElementById('edit_candidat_nom').value = cand.nom;
        document.getElementById('edit_candidat_desc').value = cand.description || '';
        document.getElementById('modalEditCandidat').style.display = 'flex';
    }
    function closeEditCandidatModal() {
        document.getElementById('modalEditCandidat').style.display = 'none';
    }

    // Gestion du Partage Promoteur
    let currentPromoterShareUrl = '';
    let currentPromoterShareMsg = '';

    function openPromoterShare(eventId, eventTitle) {
        const loc = window.location;
        const base = loc.protocol + '//' + loc.host + loc.pathname.replace('/promoteur/votes.php', '/client/accueil.php');
        const url = base + '?onglet=voter&vote_id=' + eventId;
        const msg = "🗳️ Votez dès maintenant pour « " + eventTitle + " » sur Tikéli ! Cliquez ici : " + url;
        
        currentPromoterShareUrl = url;
        currentPromoterShareMsg = msg;

        document.getElementById('promoterShareTitle').textContent = "Concours : " + eventTitle;
        document.getElementById('promoterShareUrl').value = url;
        document.getElementById('btnPromoterWa').href = "https://api.whatsapp.com/send?text=" + encodeURIComponent(msg);
        document.getElementById('btnPromoterPreview').href = url;

        document.getElementById('modalPromoterShare').style.display = 'flex';
    }

    function openPromoterShareCandidate(eventId, eventTitle, candId, candNom) {
        const loc = window.location;
        const base = loc.protocol + '//' + loc.host + loc.pathname.replace('/promoteur/votes.php', '/client/accueil.php');
        const url = base + '?onglet=voter&vote_id=' + eventId + '&candidat_id=' + candId;
        const msg = "🗳️ Soutenez et votez pour " + candNom + " dans « " + eventTitle + " » sur Tikéli ! Cliquez ici : " + url;
        
        currentPromoterShareUrl = url;
        currentPromoterShareMsg = msg;

        document.getElementById('promoterShareTitle').textContent = "Candidat : " + candNom + " (" + eventTitle + ")";
        document.getElementById('promoterShareUrl').value = url;
        document.getElementById('btnPromoterWa').href = "https://api.whatsapp.com/send?text=" + encodeURIComponent(msg);
        document.getElementById('btnPromoterPreview').href = url;

        document.getElementById('modalPromoterShare').style.display = 'flex';
    }

    function closePromoterShareModal() {
        document.getElementById('modalPromoterShare').style.display = 'none';
    }

    function copyPromoterShareUrl() {
        const input = document.getElementById('promoterShareUrl');
        const btn = document.getElementById('btnCopyPromoterLink');
        if (!input) return;

        navigator.clipboard.writeText(input.value).then(() => {
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copié !';
            btn.style.background = '#10B981';
            setTimeout(() => {
                btn.innerHTML = '<i class="fa-regular fa-copy"></i> Copier';
                btn.style.background = '#0F172A';
            }, 2500);
        }).catch(() => {
            input.select();
            document.execCommand('copy');
        });
    }

    window.addEventListener('click', function (e) {
        const m1 = document.getElementById('modalAddCandidat');
        const m2 = document.getElementById('modalEditCandidat');
        const m3 = document.getElementById('modalPromoterShare');
        if (e.target === m1) closeAddCandidatModal();
        if (e.target === m2) closeEditCandidatModal();
        if (e.target === m3) closePromoterShareModal();
    });
</script>

<?php include 'footer.php'; ?>